<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/xlsx.php';
require_once __DIR__ . '/../data/purchase_orders.php';
require_once __DIR__ . '/../data/inventory.php';

if (!function_exists('purchaseOrderTubeliteCategory')) {
    function purchaseOrderTubeliteCategory(string $sku): ?string
    {
        $normalized = strtoupper(trim($sku));

        if ($normalized === '') {
            return null;
        }

        $accessoryPrefixes = ['P', 'S', 'PTB'];
        foreach ($accessoryPrefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return 'accessories';
            }
        }

        $stockPrefixes = ['T', 'TU', 'E', 'A'];
        foreach ($stockPrefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return 'stock_lengths';
            }
        }

        return null;
    }

    function purchaseOrderGetOrCreateRow(\DOMDocument $document, \DOMElement $sheetData, array &$rowCache, int $rowNumber, string $namespace): \DOMElement
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

    function purchaseOrderGetOrCreateCell(\DOMDocument $document, \DOMElement $rowElement, string $column, int $rowNumber, string $namespace): \DOMElement
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

    function purchaseOrderClearCell(\DOMElement $cell): void
    {
        while ($cell->firstChild !== null) {
            $cell->removeChild($cell->firstChild);
        }

        if ($cell->hasAttribute('t')) {
            $cell->removeAttribute('t');
        }
    }

    function purchaseOrderSetNumericCell(\DOMDocument $document, \DOMElement $rowElement, string $column, int $rowNumber, ?float $value, string $namespace, ?int $precision = null): void
    {
        $cell = purchaseOrderGetOrCreateCell($document, $rowElement, $column, $rowNumber, $namespace);
        purchaseOrderClearCell($cell);

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

    function purchaseOrderSetTextCell(\DOMDocument $document, \DOMElement $rowElement, string $column, int $rowNumber, string $value, string $namespace): void
    {
        $cell = purchaseOrderGetOrCreateCell($document, $rowElement, $column, $rowNumber, $namespace);

        if ($value === '') {
            purchaseOrderClearCell($cell);
            return;
        }

        purchaseOrderClearCell($cell);
        $cell->setAttribute('t', 'inlineStr');

        $is = $document->createElementNS($namespace, 'is');
        $textNode = $document->createElementNS($namespace, 't');
        $textNode->appendChild($document->createTextNode($value));
        $is->appendChild($textNode);
        $cell->appendChild($is);
    }

    /**
     * Populate a Tubelite EZ Estimate worksheet without disturbing the rest of the template.
     * Only the quantity, part number, and finish columns are touched so existing formulas remain intact.
     *
     * @param list<array{quantity:float,part_number:string,finish:?string}> $rows
     */
    function purchaseOrderPopulateEzEstimateSheet(\ZipArchive $archive, string $sheetName, array $rows, int $startRow = 11, int $maxRows = 35): void
    {
        $sheetPath = xlsxResolveSheetPath($archive, $sheetName);
        $original = $archive->getFromName($sheetPath);

        if ($original === false) {
            throw new \RuntimeException(sprintf('Worksheet "%s" could not be read.', $sheetName));
        }

        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        if (@$document->loadXML($original) === false) {
            throw new \RuntimeException(sprintf('Worksheet "%s" is malformed.', $sheetName));
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheetDataList = $document->getElementsByTagNameNS($namespace, 'sheetData');

        if ($sheetDataList->length === 0) {
            throw new \RuntimeException(sprintf('Worksheet "%s" is missing data nodes.', $sheetName));
        }

        /** @var \DOMElement $sheetData */
        $sheetData = $sheetDataList->item(0);

        $rowCache = [];
        for ($node = $sheetData->firstChild; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof \DOMElement && $node->namespaceURI === $namespace && $node->localName === 'row') {
                $number = (int) $node->getAttribute('r');
                if ($number > 0) {
                    $rowCache[$number] = $node;
                }
            }
        }

        $rowCount = count($rows);

        if ($rowCount > $maxRows) {
            throw new \InvalidArgumentException(sprintf(
                'Worksheet "%s" cannot accept more than %d rows of data.',
                $sheetName,
                $maxRows
            ));
        }

        for ($i = 0; $i < $maxRows; $i++) {
            $rowNumber = $startRow + $i;
            $rowElement = purchaseOrderGetOrCreateRow($document, $sheetData, $rowCache, $rowNumber, $namespace);
            if (isset($rows[$i])) {
                $data = $rows[$i];
                $quantity = isset($data['quantity']) ? (float) $data['quantity'] : 0.0;
                $partNumber = trim((string) ($data['part_number'] ?? ''));
                $finish = $data['finish'] ?? '';
                $finish = $finish !== null ? strtoupper(trim((string) $finish)) : '';

                purchaseOrderSetNumericCell($document, $rowElement, 'A', $rowNumber, $quantity, $namespace, 3);
                purchaseOrderSetTextCell($document, $rowElement, 'B', $rowNumber, $partNumber, $namespace);
                purchaseOrderSetTextCell($document, $rowElement, 'C', $rowNumber, $finish, $namespace);
            } else {
                purchaseOrderSetNumericCell($document, $rowElement, 'A', $rowNumber, null, $namespace, 3);
                purchaseOrderSetTextCell($document, $rowElement, 'B', $rowNumber, '', $namespace);
                purchaseOrderSetTextCell($document, $rowElement, 'C', $rowNumber, '', $namespace);
            }
        }

        $archive->addFromString($sheetPath, $document->saveXML());
    }

    function purchaseOrderResetCalcChain(\ZipArchive $archive): void
    {
        $calcChainPath = 'xl/calcChain.xml';
        if ($archive->locateName($calcChainPath) !== false) {
            $archive->deleteName($calcChainPath);
        }

        $relsPath = 'xl/_rels/workbook.xml.rels';
        $relsXml = $archive->getFromName($relsPath);

        if ($relsXml === false) {
            return;
        }

        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        if (@$document->loadXML($relsXml) === false) {
            return;
        }

        $namespace = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $relationships = $document->getElementsByTagNameNS($namespace, 'Relationship');
        $removed = false;

        foreach (iterator_to_array($relationships) as $relationship) {
            if (!$relationship instanceof \DOMElement) {
                continue;
            }

            if ($relationship->getAttribute('Target') === 'calcChain.xml') {
                $relationship->parentNode?->removeChild($relationship);
                $removed = true;
            }
        }

        if ($removed) {
            $archive->addFromString($relsPath, $document->saveXML());
        }
    }

    /**
     * Build an EZ Estimate workbook for Tubelite purchase orders.
     *
     * @return array{path:string,sheets:array<string,int>,unmapped:list<array{sku:?string,description:?string,quantity:float}>}
     */
    function generateTubeliteEzEstimateOrder(\PDO $db, int $purchaseOrderId, string $templatePath, string $outputPath): array
    {
        $purchaseOrder = loadPurchaseOrder($db, $purchaseOrderId);

        if ($purchaseOrder === null) {
            throw new \RuntimeException('Purchase order not found.');
        }

        if (!is_file($templatePath)) {
            throw new \RuntimeException('EZ Estimate template not found at ' . $templatePath);
        }

        if (!copy($templatePath, $outputPath)) {
            throw new \RuntimeException('Unable to copy EZ Estimate template.');
        }

        $sheetRows = [
            'Accessories' => [],
            'Stock Lengths' => [],
        ];

        $unmapped = [];

        foreach ($purchaseOrder['lines'] as $line) {
            $sku = $line['supplier_sku'] ?? $line['sku'] ?? '';
            $category = purchaseOrderTubeliteCategory($sku);

            if ($category === null) {
                $unmapped[] = [
                    'sku' => $sku !== '' ? $sku : null,
                    'description' => $line['description'] ?? $line['item'] ?? null,
                    'quantity' => $line['quantity_ordered'],
                ];
                continue;
            }

            $sheetName = $category === 'accessories' ? 'Accessories' : 'Stock Lengths';
            $parsed = inventoryParseSku($line['sku'] ?? $sku);
            $partNumber = $parsed['part_number'] !== '' ? $parsed['part_number'] : ($line['supplier_sku'] ?? $line['sku'] ?? '');
            $finish = $parsed['finish'] ?? '';
            $quantity = (float) $line['quantity_ordered'];

            $sheetRows[$sheetName][] = [
                'quantity' => $quantity,
                'part_number' => $partNumber,
                'finish' => $finish,
            ];
        }

        $archive = new \ZipArchive();
        if ($archive->open($outputPath) !== true) {
            throw new \RuntimeException('Unable to open Tubelite workbook for writing.');
        }

        $maxRowsPerSheet = 35;
        $sheetGroups = [
            'Accessories' => ['Accessories', 'Accessories (2)', 'Accessories (3)'],
            'Stock Lengths' => ['Stock Lengths', 'Stock Lengths (2)', 'Stock Lengths (3)'],
        ];

        try {
            foreach ($sheetGroups as $groupKey => $sheetNames) {
                $rows = $sheetRows[$groupKey];
                $rowCount = count($rows);
                $offset = 0;

                foreach ($sheetNames as $index => $sheetName) {
                    if ($offset >= $rowCount) {
                        if ($rowCount === 0 && $index === 0) {
                            break;
                        }

                        purchaseOrderPopulateEzEstimateSheet($archive, $sheetName, [], 11, $maxRowsPerSheet);
                        continue;
                    }

                    $slice = array_slice($rows, $offset, $maxRowsPerSheet);
                    purchaseOrderPopulateEzEstimateSheet($archive, $sheetName, $slice, 11, $maxRowsPerSheet);
                    $offset += count($slice);
                }

                if ($offset < $rowCount) {
                    throw new \RuntimeException(sprintf(
                        'Tubelite EZ Estimate template does not contain enough "%s" worksheets to fit %d rows.',
                        strtolower($groupKey),
                        $rowCount
                    ));
                }
            }
            purchaseOrderResetCalcChain($archive);
        } finally {
            $archive->close();
        }

        return [
            'path' => $outputPath,
            'sheets' => [
                'Accessories' => count($sheetRows['Accessories']),
                'Stock Lengths' => count($sheetRows['Stock Lengths']),
            ],
            'unmapped' => $unmapped,
        ];
    }

    function generatePurchaseOrderHtml(\PDO $db, int $purchaseOrderId): string
    {
        $purchaseOrder = loadPurchaseOrder($db, $purchaseOrderId);

        if ($purchaseOrder === null) {
            throw new \RuntimeException('Purchase order not found.');
        }

        $orderNumber = $purchaseOrder['order_number'] ?? sprintf('PO-%d', $purchaseOrder['id']);
        $orderDate = $purchaseOrder['order_date'] ?? date('Y-m-d');
        $expectedDate = $purchaseOrder['expected_date'] ?? 'TBD';
        $supplierName = $purchaseOrder['supplier']['name'] ?? 'Unknown Supplier';
        $supplierContact = $purchaseOrder['supplier']['contact_name'] ?? '';
        $supplierEmail = $purchaseOrder['supplier']['contact_email'] ?? '';
        $supplierPhone = $purchaseOrder['supplier']['contact_phone'] ?? '';

        $rowsHtml = '';
        $lineTotal = 0.0;
        foreach ($purchaseOrder['lines'] as $line) {
            $quantity = (float) $line['quantity_ordered'];
            $unitCost = (float) $line['unit_cost'];
            $total = $quantity * $unitCost;
            $lineTotal += $total;

            $rowsHtml .= '<tr>'
                . '<td>' . htmlspecialchars($line['sku'] ?? $line['supplier_sku'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($line['description'] ?? $line['item'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="text-end">' . number_format($quantity, 2, '.', ',') . '</td>'
                . '<td class="text-end">' . number_format($unitCost, 2, '.', ',') . '</td>'
                . '<td class="text-end">' . number_format($total, 2, '.', ',') . '</td>'
                . '</tr>';
        }

        $notes = $purchaseOrder['notes'] ?? '';

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" />'
            . '<title>Purchase Order ' . htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<style>'
            . 'body { font-family: Arial, sans-serif; margin: 40px; }'
            . 'h1 { font-size: 24px; margin-bottom: 10px; }'
            . 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }'
            . 'th, td { border: 1px solid #ccc; padding: 8px; }'
            . 'th { background: #f5f5f5; text-align: left; }'
            . '.text-end { text-align: right; }'
            . '</style></head><body>'
            . '<h1>Purchase Order ' . htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p><strong>Supplier:</strong> ' . htmlspecialchars($supplierName, ENT_QUOTES, 'UTF-8') . '<br />'
            . ($supplierContact !== '' ? htmlspecialchars($supplierContact, ENT_QUOTES, 'UTF-8') . '<br />' : '')
            . ($supplierEmail !== '' ? htmlspecialchars($supplierEmail, ENT_QUOTES, 'UTF-8') . '<br />' : '')
            . ($supplierPhone !== '' ? htmlspecialchars($supplierPhone, ENT_QUOTES, 'UTF-8') . '<br />' : '')
            . '<strong>Order Date:</strong> ' . htmlspecialchars($orderDate, ENT_QUOTES, 'UTF-8') . '<br />'
            . '<strong>Expected Date:</strong> ' . htmlspecialchars($expectedDate, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<table><thead><tr>'
            . '<th>SKU</th><th>Description</th><th class="text-end">Quantity</th><th class="text-end">Unit Cost</th><th class="text-end">Line Total</th>'
            . '</tr></thead><tbody>'
            . $rowsHtml
            . '</tbody><tfoot><tr>'
            . '<th colspan="4" class="text-end">Total</th><th class="text-end">' . number_format($lineTotal, 2, '.', ',') . '</th>'
            . '</tr></tfoot></table>';

        if ($notes !== '') {
            $html .= '<h2>Notes</h2><p>' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        $html .= '</body></html>';

        return $html;
    }

    /**
     * @return list<string>
     */
    function purchaseOrderBuildPdfLines(array $purchaseOrder): array
    {
        $orderNumber = $purchaseOrder['order_number'] ?? sprintf('PO-%d', $purchaseOrder['id']);
        $orderDate = $purchaseOrder['order_date'] ?? date('Y-m-d');
        $expectedDate = $purchaseOrder['expected_date'] ?? 'TBD';
        $supplierName = $purchaseOrder['supplier']['name'] ?? 'Unknown Supplier';
        $supplierContact = $purchaseOrder['supplier']['contact_name'] ?? '';
        $supplierEmail = $purchaseOrder['supplier']['contact_email'] ?? '';
        $supplierPhone = $purchaseOrder['supplier']['contact_phone'] ?? '';

        $lines = [];
        $lines[] = 'Purchase Order ' . $orderNumber;
        $lines[] = 'Supplier: ' . $supplierName;
        if ($supplierContact !== '') {
            $lines[] = 'Contact: ' . $supplierContact;
        }
        if ($supplierEmail !== '') {
            $lines[] = 'Email: ' . $supplierEmail;
        }
        if ($supplierPhone !== '') {
            $lines[] = 'Phone: ' . $supplierPhone;
        }
        $lines[] = 'Order Date: ' . $orderDate;
        $lines[] = 'Expected Date: ' . $expectedDate;
        $lines[] = '';
        $lines[] = sprintf('%-18s %-44s %8s %10s %10s', 'SKU', 'Description', 'Qty', 'Unit', 'Total');

        $total = 0.0;
        foreach ($purchaseOrder['lines'] as $line) {
            $quantity = (float) $line['quantity_ordered'];
            $unitCost = (float) $line['unit_cost'];
            $lineTotal = $quantity * $unitCost;
            $total += $lineTotal;

            $sku = $line['sku'] ?? $line['supplier_sku'] ?? '';
            $description = $line['description'] ?? $line['item'] ?? '';

            $lines[] = sprintf(
                '%-18s %-44s %8s %10s %10s',
                substr($sku, 0, 18),
                substr($description, 0, 44),
                number_format($quantity, 2, '.', ''),
                number_format($unitCost, 2, '.', ''),
                number_format($lineTotal, 2, '.', '')
            );
        }

        $lines[] = '';
        $lines[] = 'Total: ' . number_format($total, 2, '.', '');

        if (!empty($purchaseOrder['notes'])) {
            $lines[] = '';
            $lines[] = 'Notes: ' . preg_replace('/\s+/', ' ', (string) $purchaseOrder['notes']);
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    function purchaseOrderGenerateSimplePdf(array $lines): string
    {
        $content = "BT\n/F1 12 Tf\n";
        $y = 780.0;

        foreach ($lines as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $content .= sprintf("1 0 0 1 72 %.2f Tm (%s) Tj\n", $y, $escaped);
            $y -= 14.0;
        }

        $content .= "ET\n";
        $length = strlen($content);

        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n",
            "4 0 obj << /Length $length >> stream\n$content\nendstream endobj\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $position = strlen($pdf);

        foreach ($objects as $object) {
            $offsets[] = $position;
            $pdf .= $object;
            $position += strlen($object);
        }

        $xrefPosition = $position;
        $pdf .= 'xref\n0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        }

        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>\n';
        $pdf .= 'startxref\n' . $xrefPosition . "\n%%EOF";

        return $pdf;
    }

    function generatePurchaseOrderPdfContent(\PDO $db, int $purchaseOrderId): string
    {
        $purchaseOrder = loadPurchaseOrder($db, $purchaseOrderId);

        if ($purchaseOrder === null) {
            throw new \RuntimeException('Purchase order not found.');
        }

        $lines = purchaseOrderBuildPdfLines($purchaseOrder);

        return purchaseOrderGenerateSimplePdf($lines);
    }

    function writePurchaseOrderPdf(\PDO $db, int $purchaseOrderId, string $outputPath): void
    {
        $pdf = generatePurchaseOrderPdfContent($db, $purchaseOrderId);

        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create directory for PDF output.');
        }

        file_put_contents($outputPath, $pdf);
    }
}
