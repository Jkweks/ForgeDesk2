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

fputcsv($output, ['Part Number', 'SKU', 'Finish', 'Description', 'On Hand', 'Committed', 'On Order', 'Cost']);

foreach ($inventory as $row) {
    // Calculate cost for Tubelite parts
    $cost = null;
    try {
        $cost = ezEstimateCalculatePartCost(
            $row['part_number'],
            $row['finish'] ?? '',
            $row['supplier_name'] ?? null
        );
    } catch (\Throwable $e) {
        // If cost calculation fails, leave it empty
        $cost = null;
    }

    // Format cost for CSV (empty if null, otherwise formatted to 2 decimal places)
    $costFormatted = $cost !== null ? number_format($cost, 2, '.', '') : '';

    fputcsv($output, [
        $row['part_number'],
        $row['sku'],
        $row['finish'] ?? '',
        $row['item'],
        $row['stock'],
        $row['committed_qty'],
        $row['on_order_qty'],
        $costFormatted,
    ]);
}

fclose($output);

exit;
