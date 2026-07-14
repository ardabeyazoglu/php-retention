<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use PhpRetention\FileInfo;
use PhpRetention\Result;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FileInfo::class)]
#[CoversClass(Result::class)]
class ValueObjectsTest extends TestCase
{
    public function testFileInfoDerivesDatePartsAndDirectoryState(): void
    {
        $path = self::$tmpDir . '/backup';
        mkdir($path, 0o770, true);
        $date = new DateTimeImmutable('2024-01-07 13:14:15 UTC');

        $fileInfo = new FileInfo($date, $path);

        self::assertSame($date->getTimestamp(), $fileInfo->timestamp);
        self::assertSame(2024, $fileInfo->year);
        self::assertSame(1, $fileInfo->month);
        self::assertSame(1, $fileInfo->week);
        self::assertSame(7, $fileInfo->day);
        self::assertSame(13, $fileInfo->hour);
        self::assertTrue($fileInfo->isDirectory);
        self::assertSame($path, (string) $fileInfo);
        self::assertSame([
            'timestamp' => $date->getTimestamp(),
            'year' => 2024,
            'month' => 1,
            'week' => 1,
            'day' => 7,
            'hour' => 13,
            'path' => $path,
            'isDirectory' => true,
        ], $fileInfo->jsonSerialize());
    }

    public function testResultSerializesAllPublicState(): void
    {
        $keepList = [['path' => '/backup/new']];
        $pruneList = ['/backup/old'];
        $result = new Result($keepList, $pruneList, 100, 101);

        self::assertSame([
            'keepList' => $keepList,
            'pruneList' => $pruneList,
            'startTime' => 100,
            'endTime' => 101,
        ], $result->jsonSerialize());
    }
}
