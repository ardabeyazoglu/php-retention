<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PhpRetention\FileInfo;
use PhpRetention\Retention;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @covers \PhpRetention\Retention
 */
class RetentionTest extends TestCase
{
    private function getStartDate(): DateTimeImmutable
    {
        // let's assume backup files are created at 01:00 and retention check starts right after
        return new DateTimeImmutable('2024-01-22 01:00:00');
    }

    public function testFinder()
    {
        $retention = new Retention([]);

        $baseDir = self::$tmpDir . '/findFiles';
        if (!file_exists($baseDir)) {
            mkdir($baseDir, 0o770, true);
        }

        $testData = [
            'file1',
            'file2',
            'file3',
        ];
        $filesExpected = [];
        foreach ($testData as $k => $d) {
            if ($k > 0) {
                sleep(1);
            }
            $filepath = $baseDir . '/' . $d;
            file_put_contents($filepath, time());

            $stats = stat($filepath);
            $timeCreated = $stats['mtime'] ?: $stats['ctime'];

            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $date = $date->setTimestamp($timeCreated);

            $filesExpected[] = new FileInfo(
                date: $date,
                path: $filepath,
                isDirectory: false
            );
        }

        $filesFound = $retention->findFiles($baseDir);

        usort($filesExpected, function ($a, $b) {
            return $a->timestamp > $b->timestamp ? 1 : -1;
        });
        usort($filesFound, function ($a, $b) {
            return $a->timestamp > $b->timestamp ? 1 : -1;
        });

        // assertEqualsCanonicalizing cannot parse FileInfo objects
        self::assertEquals($filesExpected, $filesFound);
    }

    public function testTimeHandling()
    {
        $ret = new Retention([]);
        $ret->setTimeHandler(function (string $filepath, bool $isDirectory) {
            $name = basename($filepath);
            if (preg_match('/db\-([0-9]{4})([0-9]{2})([0-9]{2})/', $name, $matches)) {
                $year = (int) $matches[1];
                $month = (int) $matches[2];
                $day = (int) $matches[3];

                $date = new DateTimeImmutable('now', new DateTimeZone("UTC"));
                $date = $date->setDate($year, $month, $day);
                $date = $date->setTime(0, 0, 0);

                return new FileInfo(
                    date: $date,
                    path: $filepath,
                    isDirectory: $isDirectory
                );
            }

            return null;
        });

        // test valid
        $testPath = '/backup/db/schema/2024/db-20240106.sql.bz2';
        $expectedFileInfo = $this->invokePrivateMethod($ret, 'checkTime', [
            $testPath
        ]);

        $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $date = $date->setDate(2024, 01, 06)->setTime(0, 0, 0);
        $actualFileInfo = new FileInfo($date, $testPath, false);
        self::assertEquals($expectedFileInfo, $actualFileInfo);

        // test invalid
        $invalid = $this->invokePrivateMethod($ret, 'checkTime', [
            '/backup/db/schema/2024/db.sql.bz2',
        ]);
        self::assertNull($invalid);
    }

    #[DataProvider('prunePolicyProvider')]
    /** @throws Exception */
    public function testPrunePolicy(array $policy, array $expectedKeepList)
    {
        $files = $this->getDummyFileData();

        $retention = $this->getMockBuilder(Retention::class)
            ->onlyMethods(['findFiles', 'pruneFile'])
            ->getMock()
        ;

        $retention->expects($this->once())
            ->method('findFiles')
            ->willReturn($files)
        ;

        $fileCount = count($files);
        $keepCount = count($expectedKeepList);
        $pruneCount = $fileCount - $keepCount;

        $retention
            ->expects($this->exactly($pruneCount))
            ->method('pruneFile')
            ->willReturn(true)
        ;

        /** @var Retention $retention */
        $retention->setPolicyConfig($policy);
        $result = $retention->apply('');
        $actualKeepList = $result->keepList;

        self::assertSameSize($expectedKeepList, $actualKeepList);

        usort($actualKeepList, function($a, $b){
            return $a["fileInfo"]->timestamp > $b["fileInfo"]->timestamp ? -1 : 1;
        });

        foreach ($expectedKeepList as $i => $expected) {
            $actualKeep = $actualKeepList[$i];
            $actual = [
                "path" => $actualKeep["fileInfo"]->path,
                "reasons" => $actualKeep["reasons"]
            ];
            self::assertEquals($expected, $actual);
        }
    }

    public static function prunePolicyProvider(): array
    {
        // first one is policy config, second is list of dates/files to keep
        return [
            [
                [
                    'keep-last' => 3,
                ],
                [
                    [
                        'path' => '/backup/path/file-20240122_01',
                        'reasons' => ['last'],
                    ],
                    [
                        'path' => '/backup/path/file-20240121_01',
                        'reasons' => ['last'],
                    ],
                    [
                        'path' => '/backup/path/file-20240120_01',
                        'reasons' => ['last'],
                    ],
                ],
            ],
            [
                [
                    'keep-daily' => 3,
                    'keep-weekly' => 5,
                    'keep-monthly' => 4,
                    'keep-yearly' => 4
                ],
                [
                    [
                        'path' => '/backup/path/file-20240122_01',
                        'reasons' => ['last', 'daily', 'weekly', 'monthly', 'yearly'],
                    ],
                    [
                        'path' => '/backup/path/file-20240121_01',
                        'reasons' => ['daily', 'weekly'],
                    ],
                    [
                        'path' => '/backup/path/file-20240120_01',
                        'reasons' => ['daily'],
                    ],
                    [
                        'path' => '/backup/path/file-20240114_01',
                        'reasons' => ['weekly'],
                    ],
                    [
                        'path' => '/backup/path/file-20240107_01',
                        'reasons' => ['weekly'],
                    ],
                    [
                        'path' => '/backup/path/file-20231231_01',
                        'reasons' => ['weekly', 'monthly', 'yearly'],
                    ],
                    [
                        'path' => '/backup/path/file-20231130_01',
                        'reasons' => ['monthly'],
                    ],
                    [
                        'path' => '/backup/path/file-20231031_01',
                        'reasons' => ['monthly'],
                    ],
                    [
                        'path' => '/backup/path/file-20221231_01',
                        'reasons' => ['yearly'],
                    ],
                    [
                        'path' => '/backup/path/file-20210122_01',
                        'reasons' => ['yearly'],
                    ],
                ],
            ],
        ];
    }

    private function getDummyFileData(): array
    {
        $startDate = $this->getStartDate();
        $timeData = [];
        for ($i = 0; $i <= 366 * 2; ++$i) {
            $dt2 = $startDate->modify("-{$i} day")->setTime(1, 1, 0);

            $timeData[] = new FileInfo(
                date: $dt2,
                path: "/backup/path/file-" . $dt2->format('Ymd_H')
            );
        }
        for ($i = 1; $i < 5; ++$i) {
            $dt2 = $startDate->modify("-{$i} year")->setTime(1, 1, 0);

            $timeData[] = new FileInfo(
                date: $dt2,
                path: "/backup/path/file-" . $dt2->format('Ymd_H')
            );
        }

        usort($timeData, function ($a, $b) {
            return $a->timestamp > $b->timestamp ? -1 : 1;
        });

        return $timeData;
    }

    public function testGrouping()
    {
        $expectedKeptFiles = [
            '/backup/tenant/mysql-20240107.tar.gz',
            '/backup/tenant/files-20240107.tar.gz'
        ];

        $retention = new Retention();
        $retention->setPolicyConfig(['keep-last' => 1]);
        $retention->setPruneHandler(function () {
            // simulate pruning
            return true;
        });
        $retention->setFindHandler(function () {
            $files = [];
            $testFiles = [
                '/backup/tenant/mysql-20240106.tar.gz',
                '/backup/tenant/files-20240106.tar.gz',
                '/backup/tenant/mysql-20240107.tar.gz',
                '/backup/tenant/files-20240107.tar.gz'
            ];
            foreach ($testFiles as $filepath) {
                if (preg_match('/\-([0-9]{4})([0-9]{2})([0-9]{2})/', $filepath, $matches)) {
                    $year = intval($matches[1]);
                    $month = intval($matches[2]);
                    $day = intval($matches[3]);

                    $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                    $date = $date->setDate($year, $month, $day)->setTime(0, 0, 0, 0);

                    $files[] = new FileInfo(
                        date: $date,
                        path: $filepath,
                        isDirectory: false
                    );
                }
            }
            return $files;
        });
        $retention->setGroupHandler(function (string $filepath) {
            // group different types of backups based on tenant and date and prune them together
            if (preg_match('/\-([0-9]{8})\.tar\.gz$/', $filepath, $matches)) {
                // group by date str
                return $matches[1];
            }

            return null;
        });
        $result = $retention->apply('/backup/tenant');
        $kept = $result->keepList;

        $actualKeptFiles = [];
        foreach ($kept as $f) {
            $actualKeptFiles[] = $f['fileInfo']->path;
        }

        self::assertEqualsCanonicalizing($expectedKeptFiles, $actualKeptFiles);
    }

    public function testPruneByDirectory()
    {
        $baseDir = self::$tmpDir . '/' . __FUNCTION__;
        if (!file_exists($baseDir)) {
            mkdir($baseDir, 0o770, true);
        }

        $testFiles = [
            'backup-20240129/file1.txt',
            'backup-20240129/file2.txt',
            'backup-20240128/file1.txt',
            'backup-20240128/file2.txt',
            'backup-20240127/file1.txt',
            'backup-20240127/file2.txt',
        ];
        foreach ($testFiles as $filepath) {
            $path = $baseDir . '/' . $filepath;
            $dir = dirname($path);
            if (!file_exists($dir)) {
                mkdir($dir, 0o770, true);
            }
            file_put_contents($path, "...");
        }

        $retention = new Retention(['keep-last' => 1]);
        $retention->setFindHandler(function (string $targetDir) {
            $files = [];
            foreach (scandir($targetDir) as $dir) {
                if (in_array($dir, ['.', '..'])) {
                    continue;
                }
                $filepath = "$targetDir/$dir";

                if (preg_match('/backup\-([0-9]{4})([0-9]{2})([0-9]{2})/', $filepath, $matches)) {
                    $year = intval($matches[1]);
                    $month = intval($matches[2]);
                    $day = intval($matches[3]);

                    $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                    $date = $date->setDate($year, $month, $day)->setTime(0, 0, 0, 0);

                    $files[] = new FileInfo(
                        date: $date,
                        path: $filepath,
                        isDirectory: true
                    );
                }
            }
            return $files;
        });
        $retention->apply($baseDir);

        self::assertFalse(file_exists($baseDir . '/backup-20240128'));
        self::assertFalse(file_exists($baseDir . '/backup-20240127'));
        self::assertTrue(file_exists($baseDir . '/' . $testFiles[0]));
        self::assertTrue(file_exists($baseDir . '/' . $testFiles[1]));
    }

    /* ------------------------------------------------------------------ *
     *  Edge-case tests                                                    *
     * ------------------------------------------------------------------ */

    /**
     * @var string[] paths passed to the prune handler in a virtual-file test
     */
    private array $prunedPaths = [];

    private function utc(string $datetime): DateTimeImmutable
    {
        return new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
    }

    /**
     * Build a Retention that operates on a virtual list of files (no disk I/O),
     * recording pruned paths in $this->prunedPaths instead of deleting anything.
     *
     * @param array $policy
     * @param array $fileSpecs list of [string $path, DateTimeImmutable $date, bool $isDir=false]
     */
    private function buildRetention(array $policy, array $fileSpecs): Retention
    {
        $this->prunedPaths = [];

        $retention = new Retention($policy);
        $retention->setFindHandler(function () use ($fileSpecs) {
            $files = [];
            foreach ($fileSpecs as $spec) {
                $files[] = new FileInfo(
                    date: $spec[1],
                    path: $spec[0],
                    isDirectory: $spec[2] ?? false
                );
            }

            return $files;
        });
        $retention->setPruneHandler(function (FileInfo $fileInfo) {
            $this->prunedPaths[] = $fileInfo->path;

            return true;
        });

        return $retention;
    }

    private function keptPaths($result): array
    {
        $paths = [];
        foreach ($result->keepList as $keep) {
            $paths[] = $keep['fileInfo']->path;
        }

        return $paths;
    }

    /**
     * Different days must never share a bucket. Previously the index was built
     * from unpadded integers, so 2024-01-15 and 2024-11-05 both mapped to
     * "2024115" and one of them was silently dropped by the daily policy.
     */
    public function testDailyPolicyKeepsDistinctDaysThatUsedToCollide()
    {
        $files = [
            ['/backup/day/file-20241105', $this->utc('2024-11-05 05:00:00')],
            ['/backup/day/file-20240610', $this->utc('2024-06-10 05:00:00')],
            ['/backup/day/file-20240115', $this->utc('2024-01-15 05:00:00')],
        ];

        $retention = $this->buildRetention(['keep-daily' => 3], $files);
        $result = $retention->apply('/backup/day');

        $kept = $this->keptPaths($result);
        sort($kept);

        self::assertSame([
            '/backup/day/file-20240115',
            '/backup/day/file-20240610',
            '/backup/day/file-20241105',
        ], $kept);
        self::assertEmpty($this->prunedPaths);
    }

    /**
     * Files created exactly at midnight (hour 0) must still be eligible for the
     * hourly policy. The old `$hour > 0` guard excluded them while still letting
     * them consume an hourly bucket slot.
     */
    public function testHourlyPolicyIncludesMidnightHour()
    {
        // newest -> oldest; the midnight file is the oldest so only keep-hourly
        // (not keep-last) can retain it.
        $files = [
            ['/backup/hour/file-0230', $this->utc('2024-03-01 02:30:00')],
            ['/backup/hour/file-0130', $this->utc('2024-03-01 01:30:00')],
            ['/backup/hour/file-0030', $this->utc('2024-03-01 00:30:00')],
        ];

        $retention = $this->buildRetention(['keep-hourly' => 3], $files);
        $result = $retention->apply('/backup/hour');

        $kept = $this->keptPaths($result);

        self::assertContains(
            '/backup/hour/file-0030',
            $kept,
            'a midnight-hour file must be retained by keep-hourly'
        );
        self::assertCount(3, $kept);
        self::assertEmpty($this->prunedPaths);
    }

    /**
     * The weekly bucket must use the ISO year, not the calendar year. 2019-12-30
     * belongs to ISO week 01 of 2020, while 2019-01-02 is ISO week 01 of 2019;
     * with calendar-year indexing they both mapped to "20191".
     */
    public function testWeeklyPolicyUsesIsoYearAtBoundary()
    {
        $files = [
            ['/backup/week/file-20191230', $this->utc('2019-12-30 05:00:00')],
            ['/backup/week/file-20190102', $this->utc('2019-01-02 05:00:00')],
        ];

        $retention = $this->buildRetention(['keep-weekly' => 2], $files);
        $result = $retention->apply('/backup/week');

        $kept = $this->keptPaths($result);
        sort($kept);

        self::assertSame([
            '/backup/week/file-20190102',
            '/backup/week/file-20191230',
        ], $kept);
        self::assertEmpty($this->prunedPaths);
    }

    /**
     * Weekly counting must remain correct across a year boundary: keep the N
     * most recent distinct ISO weeks and prune the rest.
     */
    public function testWeeklyPolicyCountAcrossNewYear()
    {
        $files = [
            ['/backup/wk/2021-01-13', $this->utc('2021-01-13 05:00:00')], // ISO 2021-W02
            ['/backup/wk/2021-01-06', $this->utc('2021-01-06 05:00:00')], // ISO 2021-W01
            ['/backup/wk/2020-12-30', $this->utc('2020-12-30 05:00:00')], // ISO 2020-W53
            ['/backup/wk/2020-12-23', $this->utc('2020-12-23 05:00:00')], // ISO 2020-W52
            ['/backup/wk/2020-12-16', $this->utc('2020-12-16 05:00:00')], // ISO 2020-W51
        ];

        $retention = $this->buildRetention(['keep-weekly' => 3], $files);
        $result = $retention->apply('/backup/wk');

        $kept = $this->keptPaths($result);
        sort($kept);
        self::assertSame([
            '/backup/wk/2020-12-30',
            '/backup/wk/2021-01-06',
            '/backup/wk/2021-01-13',
        ], $kept);

        sort($this->prunedPaths);
        self::assertSame([
            '/backup/wk/2020-12-16',
            '/backup/wk/2020-12-23',
        ], $this->prunedPaths);
    }

    /**
     * An empty policy (and keep-last=0) must keep exactly the most recent file.
     */
    public function testEmptyPolicyKeepsOnlyNewest()
    {
        $files = [
            ['/backup/cfg/new', $this->utc('2024-05-03 05:00:00')],
            ['/backup/cfg/mid', $this->utc('2024-05-02 05:00:00')],
            ['/backup/cfg/old', $this->utc('2024-05-01 05:00:00')],
        ];

        foreach ([[], ['keep-last' => 0]] as $policy) {
            $retention = $this->buildRetention($policy, $files);
            $result = $retention->apply('/backup/cfg');

            self::assertSame(['/backup/cfg/new'], $this->keptPaths($result));
            sort($this->prunedPaths);
            self::assertSame(['/backup/cfg/mid', '/backup/cfg/old'], $this->prunedPaths);
        }
    }

    /**
     * Negative policy values are clamped to 0, so a negative keep-daily is a
     * no-op and only the keep-last>=1 safety net applies.
     */
    public function testNegativePolicyValuesAreClamped()
    {
        $files = [
            ['/backup/neg/d3', $this->utc('2024-05-03 05:00:00')],
            ['/backup/neg/d2', $this->utc('2024-05-02 05:00:00')],
            ['/backup/neg/d1', $this->utc('2024-05-01 05:00:00')],
        ];

        $retention = $this->buildRetention(['keep-daily' => -5, 'keep-last' => -1], $files);
        $result = $retention->apply('/backup/neg');

        self::assertSame(['/backup/neg/d3'], $this->keptPaths($result));
        self::assertCount(2, $this->prunedPaths);
    }

    /**
     * Files that share the exact same timestamp must produce a deterministic,
     * warning-free result (the comparator now returns 0 for equal timestamps).
     */
    public function testSortStabilityWithEqualTimestamps()
    {
        $date = $this->utc('2024-05-05 05:00:00');
        $files = [
            ['/backup/eq/a', $date],
            ['/backup/eq/b', $date],
            ['/backup/eq/c', $date],
        ];

        $retention = $this->buildRetention(['keep-last' => 1], $files);
        $result = $retention->apply('/backup/eq');

        self::assertCount(1, $result->keepList);
        self::assertCount(2, $this->prunedPaths);
    }

    /**
     * Grouping must work with period policies, not just keep-last: the group is
     * kept or pruned as a unit based on its newest member.
     */
    public function testGroupingWithDailyPolicy()
    {
        $this->prunedPaths = [];

        $retention = new Retention(['keep-daily' => 1]);
        $retention->setPruneHandler(function (FileInfo $fileInfo) {
            $this->prunedPaths[] = $fileInfo->path;

            return true;
        });
        $retention->setFindHandler(function () {
            $files = [];
            $specs = [
                '/backup/t/mysql-20240107.tar.gz',
                '/backup/t/files-20240107.tar.gz',
                '/backup/t/mysql-20240106.tar.gz',
                '/backup/t/files-20240106.tar.gz',
            ];
            foreach ($specs as $filepath) {
                preg_match('/\-([0-9]{4})([0-9]{2})([0-9]{2})/', $filepath, $m);
                $date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->setDate((int) $m[1], (int) $m[2], (int) $m[3])
                    ->setTime(0, 0, 0);
                $files[] = new FileInfo(date: $date, path: $filepath, isDirectory: false);
            }

            return $files;
        });
        $retention->setGroupHandler(function (string $filepath) {
            return preg_match('/\-([0-9]{8})\.tar\.gz$/', $filepath, $m) ? $m[1] : null;
        });

        $result = $retention->apply('/backup/t');

        $kept = $this->keptPaths($result);
        sort($kept);
        self::assertSame([
            '/backup/t/files-20240107.tar.gz',
            '/backup/t/mysql-20240107.tar.gz',
        ], $kept);

        sort($this->prunedPaths);
        self::assertSame([
            '/backup/t/files-20240106.tar.gz',
            '/backup/t/mysql-20240106.tar.gz',
        ], $this->prunedPaths);
    }

    /**
     * The default recursive pruner must remove directory trees deeper than one
     * level (the previous LEAVES_ONLY iteration left nested directories behind).
     */
    public function testPruneByNestedDirectory()
    {
        $baseDir = self::$tmpDir . '/' . __FUNCTION__;

        $dates = ['20240129', '20240128', '20240127'];
        foreach ($dates as $d) {
            $deep = "$baseDir/backup-$d/sub/deep";
            mkdir($deep, 0o770, true);
            file_put_contents("$deep/file.txt", '...');
            file_put_contents("$baseDir/backup-$d/root.txt", '...');
        }

        $retention = new Retention(['keep-last' => 1]);
        $retention->setFindHandler($this->datedDirectoryFinder());
        $retention->apply($baseDir);

        self::assertDirectoryDoesNotExist("$baseDir/backup-20240128");
        self::assertDirectoryDoesNotExist("$baseDir/backup-20240127");
        self::assertDirectoryExists("$baseDir/backup-20240129/sub/deep");
        self::assertFileExists("$baseDir/backup-20240129/sub/deep/file.txt");
        self::assertFileExists("$baseDir/backup-20240129/root.txt");
    }

    /**
     * The default recursive pruner must not follow symlinks out of the tree: the
     * link itself is removed, but its target (outside the pruned dir) survives.
     */
    public function testPruneDoesNotFollowSymlinks()
    {
        if (PATH_SEPARATOR === ';') {
            self::markTestSkipped('symlink semantics differ on Windows');
        }

        $baseDir = self::$tmpDir . '/' . __FUNCTION__;

        $outside = $baseDir . '/outside';
        mkdir($outside, 0o770, true);
        file_put_contents("$outside/secret.txt", 'do not delete');

        // older dir contains a symlink pointing outside the pruned tree
        mkdir("$baseDir/backup-20240128", 0o770, true);
        file_put_contents("$baseDir/backup-20240128/data.txt", '...');
        symlink($outside, "$baseDir/backup-20240128/link-to-outside");

        mkdir("$baseDir/backup-20240129", 0o770, true);
        file_put_contents("$baseDir/backup-20240129/data.txt", '...');

        $retention = new Retention(['keep-last' => 1]);
        $retention->setFindHandler($this->datedDirectoryFinder());
        $retention->apply($baseDir);

        // the older backup dir (and its symlink) is removed...
        self::assertDirectoryDoesNotExist("$baseDir/backup-20240128");
        // ...but the target outside the tree is untouched
        self::assertDirectoryExists($outside);
        self::assertFileExists("$outside/secret.txt");
        self::assertStringEqualsFile("$outside/secret.txt", 'do not delete');
        self::assertDirectoryExists("$baseDir/backup-20240129");
    }

    /**
     * Shared finder for the on-disk directory tests: treats each "backup-YYYYMMDD"
     * entry in the target dir as a dated directory and ignores everything else.
     */
    private function datedDirectoryFinder(): callable
    {
        return function (string $targetDir) {
            $files = [];
            foreach (scandir($targetDir) as $dir) {
                if (in_array($dir, ['.', '..'])) {
                    continue;
                }

                if (!preg_match('/^backup\-([0-9]{4})([0-9]{2})([0-9]{2})$/', $dir, $matches)) {
                    continue;
                }

                $date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->setDate((int) $matches[1], (int) $matches[2], (int) $matches[3])
                    ->setTime(0, 0, 0);

                $files[] = new FileInfo(
                    date: $date,
                    path: "$targetDir/$dir",
                    isDirectory: true
                );
            }

            return $files;
        };
    }

}
