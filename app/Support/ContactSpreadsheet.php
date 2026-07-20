<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class ContactSpreadsheet
{
    /** @return array<int, array<string, ?string>> */
    public static function rows(string $path): array
    {
        if (! ContactCsv::looksBinary($path)) {
            return ContactCsv::rows($path);
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Excel .xlsx files are unavailable on this server. Save the file as CSV and try again.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This file could not be read. Upload a CSV or .xlsx file.');
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            $zip->close();
            throw new RuntimeException('The Excel file has no first worksheet.');
        }

        $shared = [];
        if ($strings = $zip->getFromName('xl/sharedStrings.xml')) {
            $xml = simplexml_load_string($strings);
            foreach ($xml?->si ?? [] as $item) {
                $shared[] = trim(implode('', array_map('strval', $item->xpath('.//t') ?: [])));
            }
        }

        $xml = simplexml_load_string($sheet);
        $rows = [];
        foreach ($xml?->sheetData?->row ?? [] as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                $reference = (string) $cell['r'];
                preg_match('/([A-Z]+)/', $reference, $match);
                $column = self::columnIndex($match[1] ?? 'A');
                $value = (string) $cell->v;
                if ((string) $cell['t'] === 's') {
                    $value = $shared[(int) $value] ?? '';
                } elseif ((string) $cell['t'] === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                }
                $values[$column] = trim($value);
            }
            if ($values) {
                $rows[] = $values;
            }
        }
        $zip->close();

        if (count($rows) < 2) {
            return [];
        }
        $header = array_map(fn ($value) => str_replace(' ', '_', strtolower(trim((string) $value))), $rows[0]);
        $result = [];
        foreach (array_slice($rows, 1) as $row) {
            $item = [];
            foreach ($header as $column => $key) {
                $item[$key] = $row[$column] ?? null;
            }
            $result[] = $item;
        }

        return $result;
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }
        return $index - 1;
    }
}
