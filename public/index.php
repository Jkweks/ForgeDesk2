<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/metrics.php';
require_once __DIR__ . '/../app/data/inventory.php';

$databaseConfig = $app['database'];
$metrics = [];
$inventory = [];
$inventoryStats = [
    'sku_count' => 0,
    'units_on_hand' => 0,
];
$dbError = null;

try {
    $db = db($databaseConfig);
    $metrics = loadMetrics($db);
    $inventory = loadInventory($db);
    $totals = inventoryReservationSummary($db);

    $inventoryStats = [
        'sku_count' => count($inventory),
        'units_on_hand' => $totals['total_stock'] ?? 0,
    ];
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        if (($item['label'] ?? '') === 'Database Health') {
            $item['badge'] = $dbError === null ? 'Live' : 'Error';
            $item['badge_class'] = $dbError === null ? 'success' : 'danger';
        }
    }
}
unset($groupItems, $item);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> Inventory Dashboard</title>
  <link rel="stylesheet" href="css/dashboard.css" />
</head>
<body>
  <div class="layout">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <header class="topbar">
      <form class="search" role="search" aria-label="Inventory search">
        <span aria-hidden="true"><?= icon('search') ?></span>
        <input type="search" name="q" placeholder="Search SKUs, bins, or components" />
      </form>
      <button class="user" type="button">
        <span class="user-avatar" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
        <span class="user-email"><?= e($app['user']['email']) ?></span>
        <span aria-hidden="true"><?= icon('chev') ?></span>
      </button>
    </header>

    <main class="content">
      <section class="metrics" aria-label="Inventory health metrics">
        <article class="metric">
          <div class="metric-header">
            <span>SKUs tracked</span>
          </div>
          <p class="metric-value"><?= e(inventoryFormatQuantity($inventoryStats['sku_count'])) ?></p>
          <p class="metric-delta small">Live parts currently in the system.</p>
        </article>
        <article class="metric">
          <div class="metric-header">
            <span>Units on hand</span>
          </div>
          <p class="metric-value"><?= e(inventoryFormatQuantity($inventoryStats['units_on_hand'])) ?></p>
          <p class="metric-delta small">Quantities available across all SKUs.</p>
        </article>
        <?php foreach ($metrics as $metric): ?>
          <article class="metric<?= !empty($metric['accent']) ? ' accent' : '' ?>">
            <div class="metric-header">
              <span><?= e($metric['label']) ?></span>
              <?php if (!empty($metric['time'])): ?>
                <span class="metric-time"><?= e($metric['time']) ?></span>
              <?php endif; ?>
            </div>
            <p class="metric-value"><?= e((string) $metric['value']) ?></p>
            <?php if (!empty($metric['delta'])): ?>
              <p class="metric-delta"><?= e($metric['delta']) ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </section>

      <section class="panel" id="stock-levels" aria-labelledby="inventory-title">
        <header>
          <h2 id="inventory-title">Inventory Snapshot</h2>
          <span class="small">Updated <?= date('M j, Y') ?></span>
        </header>
        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th scope="col">Item</th>
                <th scope="col">Part Number</th>
                <th scope="col">Finish</th>
                <th scope="col">SKU</th>
                <th scope="col">Location</th>
                <th scope="col">Stock</th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($inventory === []): ?>
                <tr>
                  <td colspan="7" class="small">No inventory items found. Add rows to the <code>inventory_items</code> table.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($inventory as $row): ?>
                  <tr>
                    <td><?= e($row['item']) ?></td>
                    <td><?= e($row['part_number']) ?></td>
                    <td><?= e(inventoryFormatFinish($row['finish'])) ?></td>
                    <td><?= e($row['sku']) ?></td>
                    <td><?= e($row['location']) ?></td>
                    <td><?= e((string) $row['stock']) ?></td>
                    <td>
                      <span class="status" data-level="<?= e($row['status']) ?>">
                        <?= e($row['status']) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel" id="roadmap" aria-labelledby="roadmap-title">
        <header>
          <h2 id="roadmap-title">Next Modules</h2>
          <span class="small">Work orders &amp; assemblies on the horizon</span>
        </header>
        <div class="roadmap">
          <article class="roadmap-card">
            <h3>Work Order Management</h3>
            <p>Plan, assign, and track fabrication orders from intake to install. Includes scheduling, capacity, and document handoff.</p>
          </article>
          <article class="roadmap-card">
            <h3>Aluminum Door Assembly</h3>
            <p>Configure stile dimensions, hardware packages, and finish schedules with automatic BOM roll-ups.</p>
          </article>
          <article class="roadmap-card">
            <h3>Supplier Collaboration</h3>
            <p>Share forecasts, confirm lead times, and log delivery variances to keep inventory responsive.</p>
          </article>
        </div>
      </section>
    </main>
  </div>
</body>
</html>
