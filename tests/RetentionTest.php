<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PhpRetention\FileInfo;
use PhpRetention\Retention;
use PhpRetention\RetentionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;

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

    public function testDryRunDoesNotPruneFiles(): void
    {
        $pruneCalls = 0;
        $retention = new Retention(['keep-last' => 1]);
        $retention->setFindHandler(fn () => [
            new FileInfo(new DateTimeImmutable('2024-01-02'), '/backup/new'),
            new FileInfo(new DateTimeImmutable('2024-01-01'), '/backup/old'),
        ]);
        $retention->setPruneHandler(function () use (&$pruneCalls): bool {
            ++$pruneCalls;
            return true;
        });
        $retention->setDryRun(true);

        $result = $retention->apply('/backup');

        self::assertSame(0, $pruneCalls);
        self::assertSame('/backup/new', $result->keepList[0]['fileInfo']->path);
        self::assertSame('/backup/old', $result->pruneList[0]->path);
    }

    #[DataProvider('invalidFindHandlerProvider')]
    public function testFindHandlerMustReturnFileInfoArray(mixed $result): void
    {
        $retention = new Retention();
        $retention->setFindHandler(fn () => $result);

        $this->expectException(RetentionException::class);
        $this->expectExceptionMessage('Find handler must return an array of FileInfo objects.');

        $retention->findFiles('/backup');
    }

    public static function invalidFindHandlerProvider(): array
    {
        return [
            'not an array' => ['invalid'],
            'array with invalid item' => [[new \stdClass()]],
        ];
    }

    public function testFailedPruneHandlerThrowsRetentionException(): void
    {
        $retention = new Retention(['keep-last' => 1]);
        $retention->setFindHandler(fn () => [
            new FileInfo(new DateTimeImmutable('2024-01-02'), '/backup/new'),
            new FileInfo(new DateTimeImmutable('2024-01-01'), '/backup/old'),
        ]);
        $retention->setPruneHandler(fn () => false);

        $this->expectException(RetentionException::class);
        $this->expectExceptionMessage('Pruning /backup/old failed unexpectedly.');

        $retention->apply('/backup');
    }

    public function testDefaultFinderExcludesAndSkipsFiles(): void
    {
        $baseDir = self::$tmpDir . '/' . __FUNCTION__;
        mkdir($baseDir, 0o770, true);
        file_put_contents($baseDir . '/included', '');
        file_put_contents($baseDir . '/excluded', '');
        file_put_contents($baseDir . '/skipped', '');
        mkdir($baseDir . '/directory');

        $retention = new Retention();
        $retention->setExcludePattern('/excluded$/');
        $retention->setTimeHandler(function (string $path, bool $isDirectory): ?FileInfo {
            if (str_ends_with($path, '/skipped')) {
                return null;
            }

            return new FileInfo(new DateTimeImmutable('2024-01-01'), $path, $isDirectory);
        });

        $files = $retention->findFiles($baseDir);

        self::assertCount(2, $files);
        self::assertSame(['directory', 'included'], array_map(
            fn (FileInfo $fileInfo) => basename($fileInfo->path),
            $files
        ));
        self::assertTrue($files[0]->isDirectory);
        self::assertFalse($files[1]->isDirectory);
    }

    public function testEmptyFinderResultIsLogged(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = compact('level', 'message', 'context');
            }
        };
        $retention = new Retention();
        $retention->setFindHandler(fn () => []);
        $retention->setLogger($logger);

        $result = $retention->apply('/empty');

        self::assertSame([], $result->keepList);
        self::assertSame('notice', $logger->records[0]['level']);
        self::assertSame('There must be at least one file to keep.', $logger->records[0]['message']);
        self::assertSame(['baseDir' => '/empty'], $logger->records[0]['context']);
    }

    public function testPruneHandlerExceptionIsWrapped(): void
    {
        $retention = new Retention(['keep-last' => 1]);
        $retention->setFindHandler(fn () => [
            new FileInfo(new DateTimeImmutable('2024-01-02'), '/backup/new'),
            new FileInfo(new DateTimeImmutable('2024-01-01'), '/backup/old'),
        ]);
        $retention->setPruneHandler(fn () => throw new \RuntimeException('storage unavailable'));

        $this->expectException(RetentionException::class);
        $this->expectExceptionMessage('storage unavailable');

        $retention->apply('/backup');
    }

}
