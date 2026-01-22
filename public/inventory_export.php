<?php

declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';

require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/data/inventory.php';
require_once __DIR__ . '/../app/services/ez_estimate_templates.php';

$databaseConfig = $app['database'];

try {
    $db = db($databaseConfig);
    $inventory = loadInventory($db);
} catch (\Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to generate inventory export: ' . $exception->getMessage();

    return;
}

// Load pricing data once for efficiency
$pricingData = [];
$finishMultipliers = [];
$pricingGroupMultipliers = [];
$abhPricingData = [];

try {
    $pricingData = ezEstimateLoadPricingData();
    $finishMultipliers = ezEstimateLoadFinishMultipliers();
    $pricingGroupMultipliers = ezEstimateLoadPricingGroupMultipliers();
} catch (\Throwable $e) {
    // If EZ Estimate template is not available, costs will be empty
}

// Load ABH Hardware pricing data
try {
    $abhPricingData = abhLoadPricingData();
} catch (\Throwable $e) {
    // If ABH pricing file is not available, costs will be empty
}

$output = fopen('php://output', 'w');

if ($output === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to open output stream for CSV export.';

    return;
}

$timestamp = (new \DateTimeImmutable('now'))->format('Y-m-d_H-i-s');
$filename = sprintf('inventory-report-%s.csv', $timestamp);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

fputcsv($output, ['Part Number', 'SKU', 'Finish', 'Description', 'On Hand', 'Committed', 'On Order', 'Qty in Packs', 'Cost']);

foreach ($inventory as $row) {
    // Skip discontinued parts
    if (!empty($row['discontinued'])) {
        continue;
    }

    $cost = null;
    $supplierName = $row['supplier_name'] ?? null;

    // Check supplier and calculate cost accordingly
    if ($supplierName !== null && strcasecmp($supplierName, 'ABH Hardware') === 0) {
        // ABH Hardware - lookup by SKU
        $sku = $row['sku'];
        $cost = $abhPricingData[$sku] ?? null;
    } elseif ($supplierName !== null && strcasecmp($supplierName, 'Tubelite') === 0) {
        // Tubelite - calculate using part number, finish, and multipliers
        $cost = ezEstimateCalculatePartCostFromCache(
            $row['part_number'],
            $row['finish'] ?? '',
            $supplierName,
            $pricingData,
            $finishMultipliers,
            $pricingGroupMultipliers
        );
    }

    // Format cost for CSV (empty if null, otherwise formatted to 2 decimal places)
    $costFormatted = $cost !== null ? number_format($cost, 2, '.', '') : '';

    // Calculate quantity in packs
    $qtyInPacks = '';
    $packSize = $row['pack_size'] ?? 0.0;
    $stock = $row['stock'];

    if ($packSize > 0) {
        $qtyInPacks = inventoryEachToUnit((float) $stock, $packSize, 'pack');
        $qtyInPacks = number_format($qtyInPacks, 2, '.', '');
    }

    fputcsv($output, [
        $row['part_number'],
        $row['sku'],
        $row['finish'] ?? '',
        $row['item'],
        $row['stock'],
        $row['committed_qty'],
        $row['on_order_qty'],
        $qtyInPacks,
        $costFormatted,
    ]);
}

fclose($output);

exit;
