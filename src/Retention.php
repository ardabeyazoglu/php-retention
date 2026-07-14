<?php

declare(strict_types=1);

namespace PhpRetention;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class Retention implements LoggerAwareInterface
{

    /**
     * @var int keep last n (most recent) files
     */
    private int $keepLast;

    /**
     * @var int for the last n hours in which a file was made, keep only the last snapshot for each hour
     */
    private int $keepHourly;

    /**
     * @var int for the last n days which have one or more files, only keep the last one for that day
     */
    private int $keepDaily;

    /**
     * @var int for the last n weeks which have one or more files, only keep the last one for that week
     */
    private int $keepWeekly;

    /**
     * @var int for the last n months which have one or more files, only keep the last one for that month
     */
    private int $keepMonthly;

    /**
     * @var int for the last n years which have one or more files, only keep the last one for that year
     */
    private int $keepYearly;

    /**
     * @var bool dry run to test before executing
     */
    private bool $dryRun = false;

    /**
     * function to execute when pruning the file
     *
     * @var callable
     */
    private $pruneHandler;

    /**
     * function to read or parse time of the file
     *
     * @var callable
     */
    private $timeHandler;

    /**
     * function to find files
     *
     * @var callable
     */
    private $findHandler;

    /**
     * @var callable group files so that retention will be applied based on groups instead of single files
     */
    private $groupHandler;

    /**
     * @var string|null regex to exclude files (applies to full file path)
     */
    private ?string $excludePattern = null;

    private LoggerInterface $logger;

    /**
     * Retention constructor.
     */
    public function __construct(array $policyConfig = [])
    {
        $this->setPolicyConfig($policyConfig);
        $this->logger = new NullLogger();
    }

    /**
     * Example: Full backup on each sunday, no backups between
     * KeepLast=2 + KeepDaily=2 : keep file1 and file2
     * KeepDaily=1 + KeepMonthly=2 : keep file1 + file2
     *  ID        Time
     * -----------------------------
     * file7  2019-09-01 11:00:00
     * file6  2019-09-08 11:00:00
     * file5  2019-09-15 11:00:00
     * file4  2019-09-22 11:00:00
     * file3  2019-09-29 11:00:00
     * file2  2019-10-06 11:00:00
     * file1  2019-10-13 11:00:00.
     *
     * @return self
     */
    public function setPolicyConfig(array $policyConfig)
    {
        $this->keepLast = isset($policyConfig['keep-last']) ? intval($policyConfig['keep-last']) : 0;
        $this->keepHourly = isset($policyConfig['keep-hourly']) ? intval($policyConfig['keep-hourly']) : 0;
        $this->keepDaily = isset($policyConfig['keep-daily']) ? intval($policyConfig['keep-daily']) : 0;
        $this->keepWeekly = isset($policyConfig['keep-weekly']) ? intval($policyConfig['keep-weekly']) : 0;
        $this->keepMonthly = isset($policyConfig['keep-monthly']) ? intval($policyConfig['keep-monthly']) : 0;
        $this->keepYearly = isset($policyConfig['keep-yearly']) ? intval($policyConfig['keep-yearly']) : 0;

        $this->keepLast = max($this->keepLast, 0);
        $this->keepHourly = max($this->keepHourly, 0);
        $this->keepDaily = max($this->keepDaily, 0);
        $this->keepWeekly = max($this->keepWeekly, 0);
        $this->keepMonthly = max($this->keepMonthly, 0);
        $this->keepYearly = max($this->keepYearly, 0);

        if ($this->keepLast === 0) {
            // never delete all files
            $this->keepLast = 1;
        }

        return $this;
    }

    /**
     * enable/disable dry-run.
     * @param bool $dryRun
     * @return $this
     */
    public function setDryRun(bool $dryRun)
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    /**
     * exclude files using regex
     * @param string $pattern
     * @return void
     */
    public function setExcludePattern(string $pattern)
    {
        $this->excludePattern = $pattern;
    }

    /**
     * apply retention policy under specified root directory.
     * @param string $baseDir
     * @return Result
     */
    public function apply(string $baseDir): Result
    {
        $startTime = time();

        $files = $this->findFiles($baseDir);
        $result = $this->checkPolicy($files);
        $keepList = $result['keep'];
        $pruneList = $result['prune'];

        foreach ($keepList as $keep) {
            /** @var FileInfo $fileInfo */
            $fileInfo = $keep['fileInfo'];
            $this->logger->debug("{$fileInfo->path} will be kept for " . implode(', ', $keep['reasons']) . ' policies.');
        }

        foreach ($pruneList as $fileInfo) {
            /** @var FileInfo $fileInfo */
            $this->logger->debug("{$fileInfo->path} will be removed.");
        }

        if (empty($keepList)) {
            $this->logger->notice('There must be at least one file to keep.', [
                'baseDir' => $baseDir,
            ]);
        }
        else {
            if ($this->dryRun) {
                $this->logger->debug('No policy applied because of dry-run.');
            }
            else {
                foreach ($pruneList as $fileInfo) {
                    /** @var FileInfo $fileInfo */
                    if (!$this->pruneFile($fileInfo)) {
                        throw new RetentionException("Pruning {$fileInfo->path} failed unexpectedly.");
                    }
                }
            }
        }

        $endTime = time();

        return new Result(
            keepList: $keepList,
            pruneList: $pruneList,
            startTime: $startTime,
            endTime: $endTime
        );
    }

    /**
     * check each file for retention
     * only the newest backups for each period will be kept (for daily, the latest backup of the day if there are multiple).
     * @param FileInfo[] $files array of FileInfo objects
     * @return array[]
     */
    private function checkPolicy(array $files)
    {
        $keepList = [];
        $pruneList = [];
        $lastList = [];
        $hourlyList = [];
        $dailyList = [];
        $weeklyList = [];
        $monthlyList = [];
        $yearlyList = [];

        if (!empty($this->groupHandler)) {
            $fn = $this->groupHandler;
            $fileGroups = [];
            foreach ($files as $fileInfo) {
                $groupName = $fn($fileInfo->path);
                if ($groupName === "" || $groupName === null) {
                    $groupName = "_";
                }
                $fileGroups[$groupName] ??= [];
                $fileGroups[$groupName][] = $fileInfo;
            }
            $files = $fileGroups;
        }

        // from newest to oldest
        foreach ($files as $fileInfos) {
            $filepaths = [];
            if (!is_array($fileInfos)) {
                $fileInfos = [$fileInfos];
            }
            foreach ($fileInfos as $finfo) {
                $filepaths[] = $finfo->path;
            }

            $fileInfo = $fileInfos[0];
            /** @var FileInfo $fileInfo */

            // fixed-width, collision-safe bucket keys (computed once in FileInfo)
            $hourIndex = $fileInfo->hourIndex;
            $dayIndex = $fileInfo->dayIndex;
            $weekIndex = $fileInfo->weekIndex;
            $monthIndex = $fileInfo->monthIndex;
            $yearIndex = $fileInfo->yearIndex;

            $keep = false;
            $reasons = [];

            if ($this->keepLast) {
                $keepCount = count($lastList);
                if ($this->keepLast > $keepCount) {
                    $keep = true;
                    $lastList[] = $filepaths;
                    $reasons[] = 'last';
                }
            }

            if ($this->keepHourly) {
                $keepCount = count($hourlyList);
                if ($this->keepHourly > $keepCount) {
                    if (!isset($hourlyList[$hourIndex])) {
                        $keep = true;
                        $reasons[] = 'hourly';
                    }
                }
            }

            if ($this->keepDaily) {
                $keepCount = count($dailyList);
                if ($this->keepDaily > $keepCount) {
                    if (!isset($dailyList[$dayIndex])) {
                        $keep = true;
                        $reasons[] = 'daily';
                    }
                }
            }

            if ($this->keepWeekly) {
                $keepCount = count($weeklyList);
                if ($this->keepWeekly > $keepCount) {
                    if (!isset($weeklyList[$weekIndex])) {
                        $keep = true;
                        $reasons[] = 'weekly';
                    }
                }
            }

            if ($this->keepMonthly) {
                $keepCount = count($monthlyList);
                if ($this->keepMonthly > $keepCount) {
                    if (!isset($monthlyList[$monthIndex])) {
                        $keep = true;
                        $reasons[] = 'monthly';
                    }
                }
            }

            if ($this->keepYearly) {
                $keepCount = count($yearlyList);
                if ($this->keepYearly > $keepCount) {
                    if (!isset($yearlyList[$yearIndex])) {
                        $keep = true;
                        $reasons[] = 'yearly';
                    }
                }
            }

            // mark this file processed for all "keep" periods configured

            if ($this->keepHourly) {
                if (!isset($hourlyList[$hourIndex])) {
                    $hourlyList[$hourIndex] = $filepaths;
                }
            }

            if ($this->keepDaily) {
                if (!isset($dailyList[$dayIndex])) {
                    $dailyList[$dayIndex] = $filepaths;
                }
            }

            if ($this->keepWeekly) {
                if (!isset($weeklyList[$weekIndex])) {
                    $weeklyList[$weekIndex] = $filepaths;
                }
            }

            if ($this->keepMonthly) {
                if (!isset($monthlyList[$monthIndex])) {
                    $monthlyList[$monthIndex] = $filepaths;
                }
            }

            if ($this->keepYearly) {
                if (!isset($yearlyList[$yearIndex])) {
                    $yearlyList[$yearIndex] = $filepaths;
                }
            }

            if ($keep) {
                foreach ($fileInfos as $finfo) {
                    $keepList[] = [
                        'fileInfo' => $finfo,
                        'reasons' => $reasons,
                    ];
                }
            }
            else {
                foreach ($fileInfos as $finfo) {
                    $pruneList[] = $finfo;
                }
            }
        }

        if (empty($keepList)) {
            // always keep at least 1 (safety net; note $files may be an
            // associative array of groups when a groupHandler is used)
            if (count($files) > 0) {
                $first = $files[array_key_first($files)];
                if (!is_array($first)) {
                    $first = [$first];
                }
                foreach ($first as $finfo) {
                    $keepList[] = [
                        'fileInfo' => $finfo,
                        'reasons' => ['last']
                    ];
                }
            }
        }

        return [
            'keep' => $keepList,
            'prune' => $pruneList,
        ];
    }

    /**
     * find list of files to apply retention policy
     * @param string $targetDir
     * @return array
     */
    public function findFiles(string $targetDir)
    {
        if (is_callable($this->findHandler)) {
            $fn = $this->findHandler;

            $files = $fn($targetDir);
            if (!is_array($files)) {
                throw new RetentionException("Find handler must return an array of FileInfo objects.");
            }

            foreach ($files as $file) {
                if (!($file instanceof FileInfo)) {
                    throw new RetentionException("Find handler must return an array of FileInfo objects.");
                }
            }
        }
        else {
            $files = [];

            $excludePattern = $this->excludePattern;

            foreach (scandir($targetDir) as $file) {
                if (in_array($file, ['.', '..'])) {
                    continue;
                }

                $filepath = "{$targetDir}/{$file}";

                if (!empty($excludePattern)) {
                    if (preg_match($excludePattern, $filepath)) {
                        continue;
                    }
                }

                // trailing slash is for cloud storage folders
                if (str_ends_with($filepath, '/') || is_dir($filepath)) {
                    $isDirectory = true;
                }
                else {
                    $isDirectory = false;
                }

                $fileInfo = $this->checkTime($filepath, $isDirectory);
                if (is_null($fileInfo)) {
                    continue;
                }
                $files[] = $fileInfo;
            }
        }

        // sort files by descending order (from newest to oldest)
        usort($files, function (FileInfo $a, FileInfo $b) {
            return $b->timestamp <=> $a->timestamp;
        });

        return $files;
    }

    /**
     * check file and get time info (UTC time!)
     * @param string $filepath
     * @param bool $isDirectory
     * @return FileInfo|null
     */
    protected function checkTime(string $filepath, bool $isDirectory = false): ?FileInfo
    {
        if (is_callable($this->timeHandler)) {
            $fn = $this->timeHandler;
            $fileInfo = $fn($filepath, $isDirectory);

            return $fileInfo;
        }
        else {
            $stats = stat($filepath);
            $timeCreated = $stats['mtime'] ?: $stats['ctime'];

            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $date = $date->setTimestamp($timeCreated);

            return new FileInfo(
                date: $date,
                path: $filepath,
                isDirectory: $isDirectory
            );
        }
    }

    /**
     * run prune action (by default try to delete local file)
     *
     * @param FileInfo $fileInfo
     * @return bool
     */
    protected function pruneFile(FileInfo $fileInfo)
    {
        if (is_callable($this->pruneHandler)) {
            try {
                $fn = $this->pruneHandler;
                $rs = $fn($fileInfo);
            }
            catch (Throwable $ex) {
                if (!($ex instanceof RetentionException)) {
                    throw new RetentionException($ex->getMessage());
                }
                throw $ex;
            }
        }
        else {
            try {
                $pathToDelete = $fileInfo->path;

                $scheme = "file";
                if (preg_match('/^([0-9a-zA-Z_]+):\/\//i', strtolower($pathToDelete), $matches)) {
                    $scheme = $matches[1];
                }

                if ($scheme === 'file') {
                    // a very basic safeguard against deleting unix root/system folders by mistake
                    // most of the time backup dir will be at least /path/to/foo
                    // realpath() returns false when the path does not exist; fall back to the
                    // raw path so the safeguard can never be silently bypassed.
                    $realPathToDelete = realpath($pathToDelete) ?: $pathToDelete;
                    if (str_starts_with($realPathToDelete, '/') && substr_count($realPathToDelete, '/') < 3) {
                        throw new RetentionException("Pruning is not allowed in '{$pathToDelete}', because it is marked as risky. The directory depth must be bigger than 2.");
                    }
                }

                if ($fileInfo->isDirectory) {
                    // CHILD_FIRST so that nested files/directories are removed before their
                    // parents. Symlinks are never followed (PHP does not recurse into them by
                    // default); a symlinked entry is removed with unlink(), which drops the
                    // link itself and never touches the target outside the tree.
                    $directory = new RecursiveDirectoryIterator($pathToDelete, FilesystemIterator::SKIP_DOTS);
                    $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($iterator as $info) {
                        /** @var SplFileInfo $info */
                        $childPath = $info->getPathName();
                        if ($info->isLink() || !$info->isDir()) {
                            unlink($childPath);
                        }
                        else {
                            rmdir($childPath);
                        }
                    }

                    $rs = rmdir($pathToDelete);
                }
                else {
                    $rs = unlink($pathToDelete);
                }
            }
            catch (Throwable $ex) {
                throw new RetentionException("'{$pathToDelete}' could not be pruned: " . $ex->getMessage());
            }
        }

        return (bool) $rs;
    }

    /**
     * change default prune action
     * @param callable $callback
     */
    public function setPruneHandler(callable $callback)
    {
        $this->pruneHandler = $callback;
    }

    /**
     * change default time detection (only used with default finder)
     * @param callable $callback
     */
    public function setTimeHandler(callable $callback)
    {
        $this->timeHandler = $callback;
    }

    /**
     * set finder
     * @param callable $callback
     */
    public function setFindHandler(callable $callback)
    {
        $this->findHandler = $callback;
    }

    /**
     * set grouping function for directory name to group files so that retention will be applied based on groups
     * @param callable $groupHandler
     */
    public function setGroupHandler(callable $groupHandler)
    {
        $this->groupHandler = $groupHandler;
    }

    /**
     * @param LoggerInterface $logger
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
