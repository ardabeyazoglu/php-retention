<?php

namespace PhpRetention;

use DateTimeImmutable;
use JsonSerializable;
use ReflectionClass;

class FileInfo implements JsonSerializable
{
    public readonly int $timestamp;
    public readonly int $year;
    public readonly int $month;
    public readonly int $week;
    public readonly int $day;
    public readonly int $hour;

    /**
     * Fixed-width, collision-safe period bucket keys used by the retention algorithm.
     * Building them here (from the DateTimeImmutable directly) avoids ambiguous
     * concatenation of unpadded integers (e.g. 2024-01-15 vs 2024-11-05).
     * The week key uses the ISO-8601 year ("o"), not the calendar year, so that
     * dates belonging to week 01/53 of a neighbouring year are bucketed correctly.
     */
    public readonly string $hourIndex;
    public readonly string $dayIndex;
    public readonly string $weekIndex;
    public readonly string $monthIndex;
    public readonly string $yearIndex;

    public function __construct(
        public DateTimeImmutable $date,
        public string $path,
        public ?bool $isDirectory = null
    )
    {
        [$year, $month, $week, $day, $hour] = explode('.', $date->format('Y.m.W.d.H'));
        $this->year = (int) $year;
        $this->month = (int) $month;
        $this->week = (int) $week;
        $this->day = (int) $day;
        $this->hour = (int) $hour;
        $this->timestamp = $date->getTimestamp();

        $this->yearIndex = $date->format('Y');
        $this->monthIndex = $date->format('Ym');
        $this->weekIndex = $date->format('oW');
        $this->dayIndex = $date->format('Ymd');
        $this->hourIndex = $date->format('YmdH');

        if (is_null($this->isDirectory) && file_exists($this->path)) {
            $this->isDirectory = is_dir($this->path);
        }
    }

    public function __toString()
    {
        return $this->path;
    }

    public function jsonSerialize(): array
    {
        $reflection = new ReflectionClass($this);
        $props = $reflection->getProperties();
        $data = [];
        foreach ($props as $prop) {
            if ($prop->getName() === 'date') {
                continue;
            }
            if ($prop->isPublic()) {
                $data[$prop->getName()] = $prop->getValue($this);
            }
        }
        return $data;
    }
}