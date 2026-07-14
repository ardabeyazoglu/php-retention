<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use PhpRetention\FileInfo;
use PhpRetention\Retention;
use PhpRetention\RetentionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;

#[CoversClass(Retention::class)]
class RetentionCoverageTest extends TestCase
{
    public function testDryRunDoesNotPruneFiles(): void
    {
        $pruneCalls = 0;
        $retention = $this->retentionWithTwoFiles();
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
        $retention = $this->retentionWithTwoFiles();
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
        $retention = $this->retentionWithTwoFiles();
        $retention->setPruneHandler(fn () => throw new \RuntimeException('storage unavailable'));

        $this->expectException(RetentionException::class);
        $this->expectExceptionMessage('storage unavailable');

        $retention->apply('/backup');
    }

    public function testPruneHandlerRetentionExceptionIsRethrown(): void
    {
        $expected = new RetentionException('retention failure');
        $retention = $this->retentionWithTwoFiles();
        $retention->setPruneHandler(fn () => throw $expected);

        try {
            $retention->apply('/backup');
            self::fail('Expected the prune handler exception to be rethrown.');
        }
        catch (RetentionException $actual) {
            self::assertSame($expected, $actual);
        }
    }

    public function testUnnamedFilesAreGroupedTogether(): void
    {
        $retention = $this->retentionWithTwoFiles();
        $retention->setGroupHandler(fn () => null);
        $retention->setPruneHandler(fn () => self::fail('Grouped files must not be pruned.'));

        $result = $retention->apply('/backup');

        self::assertCount(2, $result->keepList);
        self::assertSame([], $result->pruneList);
    }

    public function testPolicySafetyNetKeepsFirstGroup(): void
    {
        $retention = new Retention();
        $retention->setGroupHandler(fn () => 'group');
        $keepLast = new \ReflectionProperty($retention, 'keepLast');
        $keepLast->setValue($retention, 0);
        $files = [
            new FileInfo(new DateTimeImmutable('2024-01-02'), '/backup/new'),
            new FileInfo(new DateTimeImmutable('2024-01-01'), '/backup/old'),
        ];

        $result = $this->invokePrivateMethod($retention, 'checkPolicy', [$files]);

        self::assertCount(2, $result['keep']);
        self::assertSame(['last'], $result['keep'][0]['reasons']);

        $ungroupedRetention = new Retention();
        $keepLast = new \ReflectionProperty($ungroupedRetention, 'keepLast');
        $keepLast->setValue($ungroupedRetention, 0);
        $result = $this->invokePrivateMethod($ungroupedRetention, 'checkPolicy', [[$files[0]]]);

        self::assertSame($files[0], $result['keep'][0]['fileInfo']);
    }

    public function testDefaultPrunerDeletesFileUri(): void
    {
        $baseDir = self::$tmpDir . '/' . __FUNCTION__;
        mkdir($baseDir, 0o770, true);
        $newPath = $baseDir . '/new';
        $oldPath = $baseDir . '/old';
        file_put_contents($newPath, 'new');
        file_put_contents($oldPath, 'old');

        $retention = new Retention(['keep-last' => 1]);
        $retention->setFindHandler(fn () => [
            new FileInfo(new DateTimeImmutable('2024-01-02'), $newPath, false),
            new FileInfo(new DateTimeImmutable('2024-01-01'), 'file://' . $oldPath, false),
        ]);

        $retention->apply($baseDir);

        self::assertFileExists($newPath);
        self::assertFileDoesNotExist($oldPath);
    }

    public function testDefaultPrunerRejectsRiskyShallowPath(): void
    {
        $retention = new Retention();

        $this->expectException(RetentionException::class);
        $this->expectExceptionMessage("'/tmp' could not be pruned: Pruning is not allowed in '/tmp'");

        $this->invokePrivateMethod(
            $retention,
            'pruneFile',
            [new FileInfo(new DateTimeImmutable(), '/tmp', true)]
        );
    }

    private function retentionWithTwoFiles(): Retention
    {
        $retention = new Retention(['keep-last' => 1]);
        $retention->setFindHandler(fn () => [
            new FileInfo(new DateTimeImmutable('2024-01-02'), '/backup/new'),
            new FileInfo(new DateTimeImmutable('2024-01-01'), '/backup/old'),
        ]);

        return $retention;
    }
}
