<?php

declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';

require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/data/inventory.php';

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

fputcsv($output, ['SKU', 'On Hand', 'Committed', 'Available']);

foreach ($inventory as $row) {
    fputcsv($output, [
        $row['sku'],
        $row['stock'],
        $row['committed_qty'],
        $row['available_qty'],
    ]);
}

fclose($output);

exit;
