<?php

declare(strict_types=1);

if (!function_exists('xlsxReadRows')) {
    /**
     * Read a worksheet from an XLSX file and return the cell values as row arrays.
     *
     * @return list<list<string>>
     */
    function xlsxReadRows(string $filePath, ?string $sheetName = null): array
    {
        $archive = xlsxOpenArchive($filePath);

        try {
            $sharedStrings = xlsxReadSharedStrings($archive);
            $sheetPath = xlsxResolveSheetPath($archive, $sheetName);
            $sheetXml = $archive->getFromName($sheetPath);

            if ($sheetXml === false) {
                throw new \RuntimeException(sprintf('Unable to read worksheet "%s".', $sheetName ?? 'Sheet1'));
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
                    [$column] = xlsxSplitCellReference($reference);
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
     * Extract a range of cells from a named worksheet.
     *
     * @return list<list<string>>
     */
    function xlsxReadRange(string $filePath, string $sheetName, string $startCell, string $endCell): array
    {
        $archive = xlsxOpenArchive($filePath);

        try {
            $sharedStrings = xlsxReadSharedStrings($archive);
            $sheetPath = xlsxResolveSheetPath($archive, $sheetName);
            $sheetXml = $archive->getFromName($sheetPath);

            if ($sheetXml === false) {
                throw new \RuntimeException(sprintf('Unable to read worksheet "%s".', $sheetName));
            }

            $sheet = simplexml_load_string($sheetXml);
            if ($sheet === false || !isset($sheet->sheetData)) {
                return [];
            }

            [$startColumn, $startRow] = xlsxSplitCellReference($startCell);
            [$endColumn, $endRow] = xlsxSplitCellReference($endCell);

            $startIndex = xlsxColumnToIndex($startColumn);
            $endIndex = xlsxColumnToIndex($endColumn);

            if ($startIndex === null || $endIndex === null) {
                throw new \RuntimeException('Invalid column reference provided for range extraction.');
            }

            if ($startRow === 0 || $endRow === 0) {
                throw new \RuntimeException('Invalid row reference provided for range extraction.');
            }

            if ($endRow < $startRow || $endIndex < $startIndex) {
                return [];
            }

            $width = ($endIndex - $startIndex) + 1;
            $rows = [];

            foreach ($sheet->sheetData->row as $row) {
                $rowNumber = isset($row['r']) ? (int) $row['r'] : null;

                if ($rowNumber === null || $rowNumber < $startRow || $rowNumber > $endRow) {
                    continue;
                }

                $columns = [];

                foreach ($row->c as $cell) {
                    $reference = (string) ($cell['r'] ?? '');
                    [$column] = xlsxSplitCellReference($reference);
                    $index = xlsxColumnToIndex($column);

                    if ($index === null || $index < $startIndex || $index > $endIndex) {
                        continue;
                    }

                    $columns[$index] = xlsxReadCell($cell, $sharedStrings);
                }

                $rowCells = [];
                for ($i = $startIndex; $i <= $endIndex; $i++) {
                    $rowCells[] = $columns[$i] ?? '';
                }

                $rows[$rowNumber] = $rowCells;
            }

            $output = [];
            for ($rowNumber = $startRow; $rowNumber <= $endRow; $rowNumber++) {
                $output[] = $rows[$rowNumber] ?? array_fill(0, $width, '');
            }

            return $output;
        } finally {
            $archive->close();
        }
    }

    function xlsxOpenArchive(string $filePath): \ZipArchive
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException('Spreadsheet not found.');
        }

        $archive = new \ZipArchive();
        if ($archive->open($filePath) !== true) {
            throw new \RuntimeException('Unable to open XLSX archive.');
        }

        return $archive;
    }

    function xlsxResolveSheetPath(\ZipArchive $archive, ?string $sheetName): string
    {
        if ($sheetName === null) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbookIndex = $archive->locateName('xl/workbook.xml');

        if ($workbookIndex === false) {
            throw new \RuntimeException('Workbook manifest not found.');
        }

        $workbookXml = $archive->getFromIndex($workbookIndex);
        if ($workbookXml === false) {
            throw new \RuntimeException('Unable to read workbook manifest.');
        }

        $document = simplexml_load_string($workbookXml);
        if ($document === false || !isset($document->sheets)) {
            throw new \RuntimeException('Workbook manifest is malformed.');
        }

        $relationships = [];
        $relsIndex = $archive->locateName('xl/_rels/workbook.xml.rels');
        if ($relsIndex !== false) {
            $relsXml = $archive->getFromIndex($relsIndex);
            if ($relsXml !== false) {
                $relsDocument = simplexml_load_string($relsXml);
                if ($relsDocument !== false) {
                    foreach ($relsDocument->Relationship as $relationship) {
                        $id = (string) ($relationship['Id'] ?? '');
                        $target = (string) ($relationship['Target'] ?? '');
                        $mode = strtolower((string) ($relationship['TargetMode'] ?? 'internal'));

                        if ($id !== '' && $target !== '' && $mode !== 'external') {
                            $relationships[$id] = $target;
                        }
                    }
                }
            }
        }

        foreach ($document->sheets->sheet as $sheet) {
            $name = (string) ($sheet['name'] ?? '');

            $relId = '';

            // Namespaced attributes (r:id) are not exposed directly by SimpleXML when the
            // workbook declares the relationship namespace as the default. Fetch the
            // attribute via the namespace helper first, then fall back to direct access
            // for legacy documents.
            $relationshipAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            if ($relationshipAttrs !== null && isset($relationshipAttrs['id'])) {
                $relId = (string) $relationshipAttrs['id'];
            }

            if ($relId === '') {
                $relationshipAttrs = $sheet->attributes('r', true);
                if ($relationshipAttrs !== null && isset($relationshipAttrs['id'])) {
                    $relId = (string) $relationshipAttrs['id'];
                }
            }

            if ($relId === '' && isset($sheet['r:id'])) {
                $relId = (string) $sheet['r:id'];
            }

            if (strcasecmp($name, $sheetName) !== 0) {
                continue;
            }

            if ($relId === '' || !isset($relationships[$relId])) {
                throw new \RuntimeException(sprintf('Worksheet "%s" is missing a relationship target.', $sheetName));
            }

            $target = $relationships[$relId];
            $normalized = xlsxNormalizeRelationshipTarget('xl/workbook.xml', $target);

            $candidates = xlsxCandidatePaths($normalized);
            foreach ($candidates as $candidate) {
                if ($archive->locateName($candidate) !== false) {
                    return $candidate;
                }
            }

            return $normalized;
        }

        throw new \RuntimeException(sprintf('Worksheet "%s" was not found in the workbook.', $sheetName));
    }

    function xlsxNormalizeRelationshipTarget(string $basePart, string $target): string
    {
        $target = str_replace('\\', '/', trim($target));
        if ($target === '') {
            return '';
        }

        if (preg_match('/^[a-z]+:/i', $target) === 1) {
            return $target;
        }

        if ($target[0] === '/') {
            $path = ltrim($target, '/');
        } else {
            $baseDir = str_replace('\\', '/', dirname($basePart));
            $baseDir = $baseDir === '.' ? '' : $baseDir;
            $path = $baseDir !== '' ? $baseDir . '/' . $target : $target;
        }

        $segments = explode('/', $path);
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }

            $resolved[] = $segment;
        }

        return implode('/', $resolved);
    }

    /**
     * @return list<string>
     */
    function xlsxCandidatePaths(string $path): array
    {
        $candidates = [];

        $normalized = ltrim($path, '/');
        if ($normalized !== '') {
            $candidates[] = $normalized;
        }

        if ($normalized !== '' && !str_starts_with($normalized, 'xl/')) {
            $candidates[] = 'xl/' . $normalized;
        }

        if (str_starts_with($normalized, 'xl/')) {
            $candidates[] = substr($normalized, 3);
        }

        $candidates[] = $path;

        return array_values(array_unique(array_filter($candidates, static fn ($candidate) => $candidate !== '')));
    }

    /**
     * @return array{0:string,1:int}
     */
    function xlsxSplitCellReference(string $reference): array
    {
        $reference = strtoupper(trim($reference));

        if ($reference === '') {
            return ['', 0];
        }

        if (!preg_match('/^([A-Z]+)(\d+)$/', $reference, $matches)) {
            return ['', 0];
        }

        return [$matches[1], (int) $matches[2]];
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
