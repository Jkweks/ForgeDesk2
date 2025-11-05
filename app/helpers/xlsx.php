<?php

declare(strict_types=1);

if (!function_exists('xlsxReadRows')) {
    /**
     * Read the first worksheet of an XLSX file and return the cell values as row arrays.
     *
     * @return list<list<string>>
     */
    function xlsxReadRows(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException('Spreadsheet not found.');
        }

        $archive = new \ZipArchive();
        if ($archive->open($filePath) !== true) {
            throw new \RuntimeException('Unable to open XLSX archive.');
        }

        try {
            $sharedStrings = xlsxReadSharedStrings($archive);
            $sheetIndex = $archive->locateName('xl/worksheets/sheet1.xml');

            if ($sheetIndex === false) {
                throw new \RuntimeException('The workbook is missing Sheet1.');
            }

            $sheetXml = $archive->getFromIndex($sheetIndex);
            if ($sheetXml === false) {
                throw new \RuntimeException('Unable to read worksheet data.');
            }

            $sheet = simplexml_load_string($sheetXml);
            if ($sheet === false) {
                throw new \RuntimeException('Worksheet XML is malformed.');
            }

            $rows = [];
            if (!isset($sheet->sheetData)) {
                return $rows;
            }

            foreach ($sheet->sheetData->row as $row) {
                /** @var list<string> $cells */
                $cells = [];
                $columns = [];

                foreach ($row->c as $cell) {
                    $reference = (string) ($cell['r'] ?? '');
                    $column = preg_replace('/\d+/', '', $reference);
                    $index = xlsxColumnToIndex($column);

                    if ($index === null) {
                        continue;
                    }

                    $value = xlsxReadCell($cell, $sharedStrings);
                    $columns[$index] = $value;
                }

                if ($columns !== []) {
                    ksort($columns);
                    $cells = array_values($columns);
                }

                $rows[] = $cells;
            }

            return $rows;
        } finally {
            $archive->close();
        }
    }

    /**
     * @return array<int, string>
     */
    function xlsxReadSharedStrings(\ZipArchive $archive): array
    {
        $index = $archive->locateName('xl/sharedStrings.xml');

        if ($index === false) {
            return [];
        }

        $xml = $archive->getFromIndex($index);
        if ($xml === false) {
            return [];
        }

        $document = simplexml_load_string($xml);
        if ($document === false) {
            return [];
        }

        $strings = [];
        foreach ($document->si as $si) {
            $text = '';

            if (isset($si->t)) {
                $text .= (string) $si->t;
            }

            if (isset($si->r)) {
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
            }

            $strings[] = $text;
        }

        return $strings;
    }

    function xlsxColumnToIndex(string $column): ?int
    {
        $column = strtoupper(trim($column));

        if ($column === '') {
            return null;
        }

        $index = 0;
        $length = strlen($column);

        for ($i = 0; $i < $length; $i++) {
            $char = ord($column[$i]);
            if ($char < 65 || $char > 90) {
                return null;
            }

            $index = ($index * 26) + ($char - 64);
        }

        return $index - 1;
    }

    /**
     * @param array<int, string> $sharedStrings
     */
    function xlsxReadCell(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr' && isset($cell->is->t)) {
            return trim((string) $cell->is->t);
        }

        if (!isset($cell->v)) {
            return '';
        }

        $value = (string) $cell->v;

        if ($type === 's') {
            $index = (int) $value;

            return trim($sharedStrings[$index] ?? '');
        }

        if ($type === 'b') {
            return $value === '1' ? 'TRUE' : 'FALSE';
        }

        return trim($value);
    }
}
