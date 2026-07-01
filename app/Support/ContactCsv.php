<?php

namespace App\Support;

/**
 * One robust CSV reader for every contact import (contacts + groups), so the two
 * never drift apart again. Handles:
 *  - a UTF-8 BOM (Excel adds one)
 *  - comma / semicolon / tab delimiters (Excel uses ; in many regions)
 *  - a phone column named number / phone / mobile / whatsapp / contact
 *  - detecting a real .xlsx/.xls binary uploaded by mistake
 */
class ContactCsv
{
    /**
     * Read the CSV into header-keyed rows.
     *
     * @return array<int, array<string, ?string>>
     */
    public static function rows(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        // First line → strip BOM, detect delimiter, build the header.
        $first = rtrim((string) fgets($handle), "\r\n");
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);

        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delim = array_key_first($counts);
        if ($counts[$delim] === 0) {
            $delim = ',';
        }

        $header = array_map(
            fn ($h) => str_replace(' ', '_', strtolower(trim((string) $h))),
            str_getcsv($first, $delim)
        );

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delim)) !== false) {
            if ($data === [null] || (count($data) === 1 && trim((string) $data[0]) === '')) {
                continue; // blank line
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($data[$i]) ? trim((string) $data[$i]) : null;
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Resolve the phone number from whatever the column is called.
     */
    public static function phone(array $row): string
    {
        foreach (['number', 'phone', 'mobile', 'whatsapp', 'mobile_number', 'phone_number', 'contact', 'contact_number'] as $key) {
            if (! empty($row[$key])) {
                return preg_replace('/\D+/', '', (string) $row[$key]);
            }
        }

        return '';
    }

    /**
     * Is this actually a binary Excel workbook (.xlsx / .xls) rather than a CSV?
     */
    public static function looksBinary(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $sig = (string) fread($handle, 4);
        fclose($handle);

        return str_starts_with($sig, "PK\x03\x04")          // .xlsx (zip)
            || str_starts_with($sig, "\xD0\xCF\x11\xE0");    // .xls (OLE)
    }
}
