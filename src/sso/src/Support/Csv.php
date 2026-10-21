<?php

declare(strict_types=1);

namespace App\Support;

/** Penulis CSV yang tahan formula injection (sel berawalan = + - @ tab CR diberi awalan apostrof). */
final class Csv
{
    /** Nilai sel aman untuk spreadsheet. */
    public static function cell(string|int|float|null $value): string
    {
        $s = (string) ($value ?? '');
        if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) {
            return "'" . $s;
        }

        return $s;
    }

    /** @param list<list<string|int|float|null>> $rows */
    public static function encode(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        if ($h === false) {
            throw new \RuntimeException('Tidak bisa membuka buffer CSV');
        }
        foreach ($rows as $row) {
            fputcsv($h, array_map(static fn (string|int|float|null $v): string => self::cell($v), $row), ',', '"', '', "\r\n");
        }
        rewind($h);
        $out = (string) stream_get_contents($h);
        fclose($h);

        return $out;
    }
}
