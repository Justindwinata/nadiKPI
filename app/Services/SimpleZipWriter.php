<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Dependency-free ZIP writer using the "store" method (no compression).
 *
 * A fixed timestamp can be supplied to make an archive byte-reproducible. This is
 * used by immutable evidence-pack exports so re-exporting the same report snapshot
 * produces the same ZIP bytes on every server/timezone.
 */
class SimpleZipWriter
{
    /** @var array<int, array{name:string,data:string,crc:int,size:int,offset:int,time:int,date:int}> */
    private array $files = [];
    private string $body = '';
    private readonly DateTimeImmutable $timestamp;

    public function __construct(?DateTimeInterface $timestamp = null)
    {
        $this->timestamp = ($timestamp
            ? DateTimeImmutable::createFromInterface($timestamp)
            : new DateTimeImmutable('@315532800')) // 1980-01-01T00:00:00Z
            ->setTimezone(new DateTimeZone('UTC'));
    }

    public function add(string $name, string $data): void
    {
        $name = str_replace('\\', '/', ltrim($name, '/'));
        if ($name === '' || str_contains($name, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $name)) {
            throw new InvalidArgumentException('Invalid ZIP entry name.');
        }
        foreach ($this->files as $file) {
            if ($file['name'] === $name) {
                throw new InvalidArgumentException('Duplicate ZIP entry name: '.$name);
            }
        }

        $crc = crc32($data);
        $size = strlen($data);
        $offset = strlen($this->body);
        [$time, $date] = $this->dosDateTime();

        $this->body .= pack('VvvvvvVVVvv',
            0x04034b50, 20, 0, 0, $time, $date, $crc, $size, $size, strlen($name), 0
        ).$name.$data;

        $this->files[] = compact('name', 'data', 'crc', 'size', 'offset', 'time', 'date');
    }

    public function finish(): string
    {
        $central = '';
        foreach ($this->files as $file) {
            $central .= pack('VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                $file['time'],
                $file['date'],
                $file['crc'],
                $file['size'],
                $file['size'],
                strlen($file['name']),
                0,
                0,
                0,
                0,
                0,
                $file['offset']
            ).$file['name'];
        }

        $centralOffset = strlen($this->body);
        $centralSize = strlen($central);
        $count = count($this->files);

        return $this->body.$central.pack('VvvvvVVv',
            0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0
        );
    }

    /** @return array{int,int} */
    private function dosDateTime(): array
    {
        $year = min(2107, max(1980, (int) $this->timestamp->format('Y')));
        $month = (int) $this->timestamp->format('n');
        $day = (int) $this->timestamp->format('j');
        $hour = (int) $this->timestamp->format('G');
        $minute = (int) $this->timestamp->format('i');
        $second = (int) $this->timestamp->format('s');

        $time = ($hour << 11) | ($minute << 5) | ($second >> 1);
        $date = (($year - 1980) << 9) | ($month << 5) | $day;

        return [$time, $date];
    }
}
