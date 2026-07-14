<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionException;
use ReflectionMethod;

/**
 * @internal
 *
 * @coversNothing
 */
class TestCase extends PHPUnitTestCase
{
    /**
     * @var string temporary directory for test files
     */
    protected static string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $className = explode('\\', get_class($this));
        self::$tmpDir = sys_get_temp_dir() . '/' . array_pop($className) . '_' . bin2hex(\random_bytes(8));

        date_default_timezone_set("UTC");
    }

    public function tearDown(): void
    {
        parent::tearDown();

        if (file_exists(self::$tmpDir)) {
            if (PATH_SEPARATOR === ';') {
                // for windows
                shell_exec('rmdir /s /q ' . escapeshellarg(self::$tmpDir));
            }
            else {
                shell_exec('rm -R ' . escapeshellarg(self::$tmpDir) . " || echo 'could not delete tmp files'");
            }
        }
    }

    final public function getSystemDateTime(): DateTimeImmutable
    {
        $date = new DateTimeImmutable(shell_exec('date -Is'));

        return $date->setTimezone(new DateTimeZone(shell_exec('date +"%z"')));
    }

    /**
     * @return mixed
     *
     * @throws ReflectionException
     */
    final public function invokePrivateMethod(object $object, string $methodName, array $args)
    {
        $method = new ReflectionMethod(get_class($object), $methodName);
        return $method->invokeArgs($object, $args);
    }

}
