<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/services/purchase_order_documents.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ' but received ' . var_export($actual, true) . '.');
    }
}

// 1. Tubelite category mapping covers accessories, stock lengths, and unknown values
$categoryCases = [
    ['P100', 'accessories'],
    ['PTB-22', 'accessories'],
    ['S9', 'accessories'],
    ['T500', 'stock_lengths'],
    ['tu55', 'stock_lengths'],
    ['E10', 'stock_lengths'],
    ['A01', 'stock_lengths'],
    ['X123', null],
    ['', null],
];

foreach ($categoryCases as [$sku, $expectedCategory]) {
    assertSameValue($expectedCategory, purchaseOrderTubeliteCategory($sku), 'Unexpected Tubelite category for ' . var_export($sku, true) . '.');
}

// 2. Worksheet helpers create and reuse rows/cells while formatting values
$namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$document = new DOMDocument();
$document->loadXML('<worksheet xmlns="' . $namespace . '"><sheetData/></worksheet>');
$sheetDataList = $document->getElementsByTagNameNS($namespace, 'sheetData');
if ($sheetDataList->length === 0) {
    throw new RuntimeException('Worksheet seed failed to parse.');
}

/** @var DOMElement $sheetData */
$sheetData = $sheetDataList->item(0);
$rowCache = [];

$row = purchaseOrderGetOrCreateRow($document, $sheetData, $rowCache, 5, $namespace);
assertSameValue(5, (int) $row->getAttribute('r'), 'Row number was not set.');
assertSameValue($row, purchaseOrderGetOrCreateRow($document, $sheetData, $rowCache, 5, $namespace), 'Row cache did not return the original row.');

purchaseOrderSetNumericCell($document, $row, 'C', 5, 3.14159, $namespace, 2);
$cellElements = $row->getElementsByTagNameNS($namespace, 'c');
if ($cellElements->length === 0) {
    throw new RuntimeException('Numeric cell was not created.');
}

$numericCell = null;
foreach ($cellElements as $cell) {
    if ($cell->getAttribute('r') === 'C5') {
        $numericCell = $cell;
        break;
    }
}

if (!$numericCell instanceof DOMElement) {
    throw new RuntimeException('Numeric cell could not be located.');
}

$values = $numericCell->getElementsByTagNameNS($namespace, 'v');
assertSameValue('3.14', $values->length > 0 ? $values->item(0)->nodeValue : null, 'Numeric value was not formatted to two decimals.');

purchaseOrderSetTextCell($document, $row, 'A', 5, 'Sample Item', $namespace);
$textCell = null;
foreach ($row->getElementsByTagNameNS($namespace, 'c') as $cell) {
    if ($cell->getAttribute('r') === 'A5') {
        $textCell = $cell;
        break;
    }
}

if (!$textCell instanceof DOMElement) {
    throw new RuntimeException('Text cell was not created.');
}

assertSameValue('inlineStr', $textCell->getAttribute('t'), 'Text cell type was not set.');
$isList = $textCell->getElementsByTagNameNS($namespace, 'is');
assertSameValue(true, $isList->length > 0, 'Text cell is missing inline string content.');

purchaseOrderSetTextCell($document, $row, 'A', 5, '', $namespace);
assertSameValue('', $textCell->getAttribute('t'), 'Cleared text cell should not have a type.');
assertSameValue(0, $textCell->childNodes->length, 'Cleared text cell still has children.');

purchaseOrderSetNumericCell($document, $row, 'D', 5, null, $namespace);
$cleared = null;
foreach ($row->getElementsByTagNameNS($namespace, 'c') as $cell) {
    if ($cell->getAttribute('r') === 'D5') {
        $cleared = $cell;
        break;
    }
}

if (!$cleared instanceof DOMElement) {
    throw new RuntimeException('Empty numeric cell was not created.');
}

assertSameValue(0, $cleared->childNodes->length, 'Empty numeric cell should not contain any value nodes.');

echo "All purchase order document tests passed\n";
