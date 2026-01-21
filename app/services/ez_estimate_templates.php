<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/xlsx.php';

if (!function_exists('ezEstimateTemplateStorageDir')) {
    function ezEstimateTemplateStorageDir(): string
    {
        $dir = __DIR__ . '/../../storage/ez-estimate';

        if (!is_dir($dir)) {
            $previousUmask = umask(0);
            if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
                umask($previousUmask);
                throw new \RuntimeException(
                    'Unable to create the EZ Estimate storage directory at ' . $dir . '. '
                    . 'Ensure the parent directory is writable by the web server.'
                );
            }
            umask($previousUmask);
        }

        return $dir;
    }
}

if (!function_exists('ezEstimateEnsureWritableStorage')) {
    function ezEstimateEnsureWritableStorage(): string
    {
        $storageDir = ezEstimateTemplateStorageDir();

        if (!is_dir($storageDir)) {
            throw new \RuntimeException(
                'Unable to prepare the EZ Estimate storage directory at ' . $storageDir . '. '
                . 'Ensure the parent directory is writable by the web server.'
            );
        }

        if (!is_writable($storageDir)) {
            $previousUmask = umask(0);
            @chmod($storageDir, 0770);
            umask($previousUmask);
        }

        if (!is_writable($storageDir)) {
            $previousUmask = umask(0);
            @chmod($storageDir, 0775);
            @chmod($storageDir, 0777);
            umask($previousUmask);
        }

        $testFile = $storageDir . '/.write_test_' . uniqid('', true);
        $handle = @fopen($testFile, 'wb');

        if ($handle === false) {
            throw new \RuntimeException(
                'Unable to save the uploaded template. Verify that the web server can write to ' . $storageDir . '.'
            );
        }

        fclose($handle);
        @unlink($testFile);

        return $storageDir;
    }
}

if (!function_exists('ezEstimateDefaultTemplatePath')) {
    function ezEstimateDefaultTemplatePath(): string
    {
        return __DIR__ . '/../helpers/EZ_Estimate.xlsm';
    }
}

if (!function_exists('ezEstimateUploadedTemplatePath')) {
    function ezEstimateUploadedTemplatePath(): string
    {
        return ezEstimateTemplateStorageDir() . '/EZ_Estimate.xlsm';
    }
}

if (!function_exists('ezEstimateActiveTemplatePath')) {
    function ezEstimateActiveTemplatePath(): string
    {
        $uploaded = ezEstimateUploadedTemplatePath();

        if (is_file($uploaded)) {
            return $uploaded;
        }

        return ezEstimateDefaultTemplatePath();
    }
}

if (!function_exists('ezEstimateStoreTemplateUpload')) {
    /**
     * Persist a user-uploaded EZ Estimate workbook for future use.
     *
     * @param array{tmp_name?:string,error?:int,name?:string,type?:string} $file
     */
    function ezEstimateStoreTemplateUpload(array $file): string
    {
        $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($errorCode !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file is larger than the form allows.',
                UPLOAD_ERR_PARTIAL => 'The file upload did not complete. Try again.',
                UPLOAD_ERR_NO_FILE => 'Select a template to upload.',
            ];
            throw new \RuntimeException($messages[$errorCode] ?? 'Failed to upload the template.');
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException('The uploaded template could not be verified. Please try again.');
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension !== 'xlsm' && $extension !== 'xlsx') {
            throw new \RuntimeException('Upload an Excel macro-enabled workbook (.xlsm) or .xlsx file.');
        }

        $storageDir = ezEstimateEnsureWritableStorage();

        $targetPath = ezEstimateUploadedTemplatePath();

        if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
            if (!@copy($file['tmp_name'], $targetPath)) {
                throw new \RuntimeException('Unable to save the uploaded template.');
            }

            @unlink($file['tmp_name']);
        }

        return $targetPath;
    }
}

if (!function_exists('ezEstimateLoadMultipliers')) {
    /**
     * @return list<array{row:int,label:string,value:?float}>
     */
    function ezEstimateLoadMultipliers(?string $templatePath = null): array
    {
        $path = $templatePath ?? ezEstimateActiveTemplatePath();

        if (!is_file($path)) {
            throw new \RuntimeException('No EZ Estimate template is available.');
        }

        $rows = xlsxReadRange($path, 'MULTIPLIERS', 'C4', 'D12');
        $multipliers = [];
        $rowNumber = 4;

        foreach ($rows as $row) {
            $label = isset($row[0]) ? trim((string) $row[0]) : '';
            $rawValue = $row[1] ?? '';
            $numeric = is_numeric($rawValue) ? (float) $rawValue : null;

            $multipliers[] = [
                'row' => $rowNumber,
                'label' => $label !== '' ? $label : sprintf('Row %d', $rowNumber),
                'value' => $numeric,
            ];
            $rowNumber++;
        }

        return $multipliers;
    }
}

if (!function_exists('ezEstimateEnsureWritableTemplate')) {
    function ezEstimateEnsureWritableTemplate(): string
    {
        $target = ezEstimateUploadedTemplatePath();

        if (is_file($target)) {
            return $target;
        }

        $source = ezEstimateDefaultTemplatePath();
        if (!is_file($source)) {
            throw new \RuntimeException('The default EZ Estimate template is missing.');
        }

        if (!@copy($source, $target)) {
            throw new \RuntimeException('Unable to prepare a writable EZ Estimate template.');
        }

        return $target;
    }
}

if (!function_exists('ezEstimateUpdateMultipliers')) {
    /**
     * @param array<int,float> $updates Row number => multiplier
     */
    function ezEstimateUpdateMultipliers(array $updates): void
    {
        $templatePath = ezEstimateEnsureWritableTemplate();

        $archive = new \ZipArchive();
        if ($archive->open($templatePath) !== true) {
            throw new \RuntimeException('Unable to open the EZ Estimate workbook for editing.');
        }

        try {
            $sheetPath = xlsxResolveSheetPath($archive, 'MULTIPLIERS');
            $sheetXml = $archive->getFromName($sheetPath);

            if ($sheetXml === false) {
                throw new \RuntimeException('The MULTIPLIERS worksheet could not be read.');
            }

            $document = new \DOMDocument();
            $document->preserveWhiteSpace = false;
            $document->formatOutput = false;

            if (@$document->loadXML($sheetXml) === false) {
                throw new \RuntimeException('The MULTIPLIERS worksheet is malformed.');
            }

            $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $sheetDataList = $document->getElementsByTagNameNS($namespace, 'sheetData');
            if ($sheetDataList->length === 0) {
                throw new \RuntimeException('The MULTIPLIERS worksheet is missing data nodes.');
            }

            /** @var \DOMElement $sheetData */
            $sheetData = $sheetDataList->item(0);
            $rowCache = [];
            for ($node = $sheetData->firstChild; $node !== null; $node = $node->nextSibling) {
                if ($node instanceof \DOMElement && $node->namespaceURI === $namespace && $node->localName === 'row') {
                    $rowNumber = (int) $node->getAttribute('r');
                    if ($rowNumber > 0) {
                        $rowCache[$rowNumber] = $node;
                    }
                }
            }

            foreach ($updates as $rowNumber => $value) {
                $rowElement = ezEstimateGetOrCreateRow($document, $sheetData, $rowCache, $rowNumber, $namespace);
                ezEstimateSetNumericCell($document, $rowElement, 'D', $rowNumber, $value, $namespace, 6);
            }

            $archive->addFromString($sheetPath, $document->saveXML());
            ezEstimateResetCalcChain($archive);
        } finally {
            $archive->close();
        }
    }
}

if (!function_exists('ezEstimateGetOrCreateRow')) {
    function ezEstimateGetOrCreateRow(\DOMDocument $document, \DOMElement $sheetData, array &$rowCache, int $rowNumber, string $namespace): \DOMElement
    {
        if (isset($rowCache[$rowNumber])) {
            return $rowCache[$rowNumber];
        }

        $row = $document->createElementNS($namespace, 'row');
        $row->setAttribute('r', (string) $rowNumber);

        $inserted = false;
        for ($node = $sheetData->firstChild; $node !== null; $node = $node->nextSibling) {
            if (!$node instanceof \DOMElement || $node->namespaceURI !== $namespace || $node->localName !== 'row') {
                continue;
            }

            $existingNumber = (int) $node->getAttribute('r');
            if ($existingNumber > $rowNumber) {
                $sheetData->insertBefore($row, $node);
                $inserted = true;
                break;
            }
        }

        if (!$inserted) {
            $sheetData->appendChild($row);
        }

        $rowCache[$rowNumber] = $row;
        return $row;
    }
}

if (!function_exists('ezEstimateGetOrCreateCell')) {
    function ezEstimateGetOrCreateCell(\DOMDocument $document, \DOMElement $rowElement, string $column, int $rowNumber, string $namespace): \DOMElement
    {
        $cellReference = $column . $rowNumber;

        for ($node = $rowElement->firstChild; $node !== null; $node = $node->nextSibling) {
            if (!$node instanceof \DOMElement || $node->namespaceURI !== $namespace || $node->localName !== 'c') {
                continue;
            }

            if ($node->getAttribute('r') === $cellReference) {
                return $node;
            }
        }

        $cell = $document->createElementNS($namespace, 'c');
        $cell->setAttribute('r', $cellReference);

        $targetIndex = xlsxColumnToIndex($column);
        $inserted = false;

        for ($node = $rowElement->firstChild; $node !== null; $node = $node->nextSibling) {
            if (!$node instanceof \DOMElement || $node->namespaceURI !== $namespace || $node->localName !== 'c') {
                continue;
            }

            $existingRef = $node->getAttribute('r');
            $existingColumn = preg_replace('/\d+$/', '', $existingRef);
            $existingIndex = $existingColumn !== null ? xlsxColumnToIndex((string) $existingColumn) : null;

            if ($existingIndex !== null && $targetIndex !== null && $existingIndex > $targetIndex) {
                $rowElement->insertBefore($cell, $node);
                $inserted = true;
                break;
            }
        }

        if (!$inserted) {
            $rowElement->appendChild($cell);
        }

        return $cell;
    }
}

if (!function_exists('ezEstimateClearCell')) {
    function ezEstimateClearCell(\DOMElement $cell): void
    {
        while ($cell->firstChild !== null) {
            $cell->removeChild($cell->firstChild);
        }

        if ($cell->hasAttribute('t')) {
            $cell->removeAttribute('t');
        }
    }
}

if (!function_exists('ezEstimateSetNumericCell')) {
    function ezEstimateSetNumericCell(\DOMDocument $document, \DOMElement $rowElement, string $column, int $rowNumber, ?float $value, string $namespace, ?int $precision = null): void
    {
        $cell = ezEstimateGetOrCreateCell($document, $rowElement, $column, $rowNumber, $namespace);
        ezEstimateClearCell($cell);

        if ($value === null) {
            return;
        }

        if ($precision !== null) {
            $formatted = number_format($value, $precision, '.', '');
            $formatted = rtrim(rtrim($formatted, '0'), '.');
            if ($formatted === '') {
                $formatted = '0';
            }
        } else {
            $formatted = (string) $value;
        }

        $cell->appendChild($document->createElementNS($namespace, 'v', $formatted));
    }
}

if (!function_exists('ezEstimateResetCalcChain')) {
    function ezEstimateResetCalcChain(\ZipArchive $archive): void
    {
        $calcChainPath = 'xl/calcChain.xml';
        if ($archive->locateName($calcChainPath) !== false) {
            $archive->deleteName($calcChainPath);
        }
    }
}

if (!function_exists('ezEstimateLoadPricingData')) {
    /**
     * Load pricing data from SL Formulas and P Formulas sheets.
     * Returns an associative array: [part_number => ['pricing_group' => string, 'price' => float, 'sheet' => string]]
     *
     * @return array<string, array{pricing_group:string,price:float,sheet:string}>
     */
    function ezEstimateLoadPricingData(?string $templatePath = null): array
    {
        $path = $templatePath ?? ezEstimateActiveTemplatePath();

        if (!is_file($path)) {
            throw new \RuntimeException('No EZ Estimate template is available.');
        }

        $pricingData = [];

        // Load SL Formulas sheet (for parts starting with E, T, A, TU)
        // Pricing group in A, part number in C, list price in I
        try {
            $slRows = xlsxReadRows($path, 'SL Formulas');

            foreach ($slRows as $index => $row) {
                // Skip header row (assuming first row is header)
                if ($index === 0) {
                    continue;
                }

                $pricingGroup = isset($row[0]) ? trim((string) $row[0]) : '';
                $partNumber = isset($row[2]) ? trim((string) $row[2]) : '';
                $price = isset($row[8]) ? $row[8] : '';

                if ($partNumber === '' || $pricingGroup === '') {
                    continue;
                }

                // Convert price to float
                $priceFloat = is_numeric($price) ? (float) $price : 0.0;

                $pricingData[$partNumber] = [
                    'pricing_group' => $pricingGroup,
                    'price' => $priceFloat,
                    'sheet' => 'SL Formulas',
                ];
            }
        } catch (\Throwable $e) {
            // Sheet might not exist, continue
        }

        // Load P Formulas sheet (for parts starting with P, PTB, S)
        // Pricing group in A, part number in C, cost in I
        try {
            $pRows = xlsxReadRows($path, 'P Formulas');

            foreach ($pRows as $index => $row) {
                // Skip header row
                if ($index === 0) {
                    continue;
                }

                $pricingGroup = isset($row[0]) ? trim((string) $row[0]) : '';
                $partNumber = isset($row[2]) ? trim((string) $row[2]) : '';
                $cost = isset($row[8]) ? $row[8] : '';

                if ($partNumber === '' || $pricingGroup === '') {
                    continue;
                }

                // Convert cost to float
                $costFloat = is_numeric($cost) ? (float) $cost : 0.0;

                $pricingData[$partNumber] = [
                    'pricing_group' => $pricingGroup,
                    'price' => $costFloat,
                    'sheet' => 'P Formulas',
                ];
            }
        } catch (\Throwable $e) {
            // Sheet might not exist, continue
        }

        return $pricingData;
    }
}

if (!function_exists('ezEstimateLoadFinishMultipliers')) {
    /**
     * Load finish multipliers from Finish Codes sheet.
     * Returns an associative array: [finish_code => multiplier]
     *
     * @return array<string, float>
     */
    function ezEstimateLoadFinishMultipliers(?string $templatePath = null): array
    {
        $path = $templatePath ?? ezEstimateActiveTemplatePath();

        if (!is_file($path)) {
            throw new \RuntimeException('No EZ Estimate template is available.');
        }

        $finishMultipliers = [];

        try {
            // Read specific cells from Finish Codes sheet
            // C2 finish -> H3, BL -> H8, DB -> H6, 0R -> 1.0

            $finishCodes = xlsxReadRange($path, 'Finish Codes', 'H1', 'H10');

            // C2 finish is in H3 (row 3)
            if (isset($finishCodes[2][0]) && is_numeric($finishCodes[2][0])) {
                $finishMultipliers['C2'] = (float) $finishCodes[2][0];
            }

            // BL finish is in H8 (row 8)
            if (isset($finishCodes[7][0]) && is_numeric($finishCodes[7][0])) {
                $finishMultipliers['BL'] = (float) $finishCodes[7][0];
            }

            // DB finish is in H6 (row 6)
            if (isset($finishCodes[5][0]) && is_numeric($finishCodes[5][0])) {
                $finishMultipliers['DB'] = (float) $finishCodes[5][0];
            }

            // 0R finish has a multiplier of 1
            $finishMultipliers['0R'] = 1.0;
        } catch (\Throwable $e) {
            // Sheet might not exist or structure is different
            // Return defaults
            $finishMultipliers = [
                'C2' => 1.0,
                'BL' => 1.0,
                'DB' => 1.0,
                '0R' => 1.0,
            ];
        }

        return $finishMultipliers;
    }
}

if (!function_exists('ezEstimateLoadPricingGroupMultipliers')) {
    /**
     * Load pricing group multipliers from MULTIPLIERS sheet.
     * Returns an associative array: [pricing_group => multiplier]
     *
     * @return array<string, float>
     */
    function ezEstimateLoadPricingGroupMultipliers(?string $templatePath = null): array
    {
        $path = $templatePath ?? ezEstimateActiveTemplatePath();

        if (!is_file($path)) {
            throw new \RuntimeException('No EZ Estimate template is available.');
        }

        $pricingGroupMultipliers = [];

        try {
            // Read pricing groups from column B rows 4-12 and multipliers from column E
            $rows = xlsxReadRange($path, 'MULTIPLIERS', 'B4', 'E12');

            foreach ($rows as $row) {
                $pricingGroups = isset($row[0]) ? trim((string) $row[0]) : '';
                $multiplier = isset($row[3]) ? $row[3] : '';

                if ($pricingGroups === '') {
                    continue;
                }

                // Convert multiplier to float
                $multiplierFloat = is_numeric($multiplier) ? (float) $multiplier : 1.0;

                // Column B can contain multiple values per cell, split by comma or other delimiters
                $groupList = preg_split('/[,;\s]+/', $pricingGroups, -1, PREG_SPLIT_NO_EMPTY);

                foreach ($groupList as $group) {
                    $group = trim($group);
                    if ($group !== '') {
                        $pricingGroupMultipliers[$group] = $multiplierFloat;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Sheet might not exist, return empty array
        }

        return $pricingGroupMultipliers;
    }
}

if (!function_exists('ezEstimateCalculatePartCost')) {
    /**
     * Calculate the cost for a given part number and finish.
     *
     * @param string $partNumber The part number
     * @param string $finish The finish code
     * @param string|null $supplier The supplier name (only calculates for 'Tubelite' parts)
     * @param string|null $templatePath Optional template path
     * @return float|null The calculated cost, or null if not applicable/not found
     */
    function ezEstimateCalculatePartCost(string $partNumber, string $finish, ?string $supplier = null, ?string $templatePath = null): ?float
    {
        // Only calculate for Tubelite supplier parts
        if ($supplier !== null && strcasecmp($supplier, 'Tubelite') !== 0) {
            return null;
        }

        $partNumber = trim($partNumber);
        $finish = strtoupper(trim($finish));

        if ($partNumber === '') {
            return null;
        }

        // Check if part number starts with the correct prefixes
        $prefix = '';
        foreach (['PTB', 'TU', 'E', 'T', 'A', 'P', 'S'] as $testPrefix) {
            if (stripos($partNumber, $testPrefix) === 0) {
                $prefix = strtoupper($testPrefix);
                break;
            }
        }

        if ($prefix === '') {
            return null;
        }

        // Determine which sheet to use based on prefix
        $validSheet = null;
        if (in_array($prefix, ['E', 'T', 'A', 'TU'], true)) {
            $validSheet = 'SL Formulas';
        } elseif (in_array($prefix, ['P', 'PTB', 'S'], true)) {
            $validSheet = 'P Formulas';
        }

        if ($validSheet === null) {
            return null;
        }

        try {
            // Load pricing data
            $pricingData = ezEstimateLoadPricingData($templatePath);

            if (!isset($pricingData[$partNumber])) {
                return null;
            }

            $partData = $pricingData[$partNumber];

            // Verify the part is from the correct sheet
            if ($partData['sheet'] !== $validSheet) {
                return null;
            }

            $basePrice = $partData['price'];
            $pricingGroup = $partData['pricing_group'];

            // Load finish multipliers
            $finishMultipliers = ezEstimateLoadFinishMultipliers($templatePath);
            $finishMultiplier = $finishMultipliers[$finish] ?? 1.0;

            // Load pricing group multipliers
            $pricingGroupMultipliers = ezEstimateLoadPricingGroupMultipliers($templatePath);
            $groupMultiplier = $pricingGroupMultipliers[$pricingGroup] ?? 1.0;

            // Calculate final cost: base price * finish multiplier * pricing group multiplier
            $cost = $basePrice * $finishMultiplier * $groupMultiplier;

            return $cost;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
