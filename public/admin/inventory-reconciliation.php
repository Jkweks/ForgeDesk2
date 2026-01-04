<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/data/inventory.php';
require_once __DIR__ . '/../../app/data/storage_locations.php';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Location Reconciliation');
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$successMessage = null;
$locationDiscrepancies = [];
$realignResult = null;
$policy = inventoryLocationStockPolicy();

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'realign') {
        try {
            $realignResult = inventoryRealignStockFromLocations($db);
            $successMessage = 'Stock totals realigned to location quantities.';
        } catch (\Throwable $exception) {
            $errors['general'] = 'Unable to realign stock totals: ' . $exception->getMessage();
        }
    }

    try {
        $locationDiscrepancies = inventoryLocationDiscrepancies($db);
    } catch (\Throwable $exception) {
        $errors['general'] = 'Unable to load reconciliation results: ' . $exception->getMessage();
    }
}

$bodyClasses = ['has-sidebar-toggle'];
$bodyAttributes = ' class="' . implode(' ', $bodyClasses) . '"';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> · Location Reconciliation</title>
  <script src="/js/tabler-theme.js"></script>
  <link rel="stylesheet" href="/css/tabler.min.css" />
  <link rel="stylesheet" href="/css/admin-bridge.css" />
  <script src="/js/tabler.min.js" defer></script>
  <script src="/js/tabler-init.js" defer></script>
</head>
<body<?= $bodyAttributes ?>>
  <div class="layout">
    <?php require __DIR__ . '/../../app/views/partials/sidebar.php'; ?>

    <?php
    $topbarTitle = 'Location Reconciliation';
    $topbarSubhead = 'Compare inventory stock values to summed storage location quantities.';
    require __DIR__ . '/../../app/views/partials/topbar.php';
    unset($topbarTitle, $topbarSubhead, $topbarExtras);
    ?>

    <main class="content">
      <section class="panel" aria-labelledby="location-reconcile-title">
        <header class="panel-header">
          <div>
            <p class="eyebrow">Inventory Controls</p>
            <h1 id="location-reconcile-title">Location Reconciliation</h1>
            <p class="small">Ensure on-hand stock mirrors detailed storage location quantities for every item.</p>
          </div>
          <div class="header-actions">
            <a class="button secondary" href="/inventory.php">Back to inventory</a>
          </div>
        </header>

        <?php if ($dbError !== null): ?>
          <div class="alert error" role="alert">Database connection failed: <?= e($dbError) ?></div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
          <div class="alert error" role="alert"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <?php if ($successMessage !== null): ?>
          <div class="alert success" role="status"><?= e($successMessage) ?></div>
        <?php endif; ?>

        <?php if ($realignResult !== null): ?>
          <div class="callout neutral">
            <p class="strong">Realignment complete</p>
            <p class="small">Checked <?= e((string) $realignResult['checked']) ?> items and updated <?= e((string) $realignResult['updated']) ?> stock totals.</p>
          </div>
        <?php endif; ?>

        <div class="callout neutral">
          <p class="strong">Location policy: <?= e($policy === 'block_on_mismatch' ? 'Block on mismatch' : 'Sync stock to locations') ?></p>
          <p class="small">
            <?= $policy === 'block_on_mismatch'
                ? 'Saves fail when location totals differ from item stock.'
                : 'Item stock is automatically aligned to summed location quantities when assignments change.' ?>
          </p>
        </div>

        <div class="panel-grid">
          <div class="panel-block">
            <div class="panel-block-header">
              <div>
                <h2>Discrepancies</h2>
                <p class="small">Items where stock and location totals differ.</p>
              </div>
              <form method="post" class="inline-form">
                <input type="hidden" name="action" value="realign" />
                <button type="submit" class="button primary">Realign stock to locations</button>
              </form>
            </div>

            <?php if ($locationDiscrepancies === []): ?>
              <p class="small">All inventory items match their location quantities.</p>
            <?php else: ?>
              <div class="table-wrapper">
                <table>
                  <thead>
                    <tr>
                      <th scope="col">Item</th>
                      <th scope="col">SKU</th>
                      <th scope="col" class="numeric">Item stock</th>
                      <th scope="col" class="numeric">Location total</th>
                      <th scope="col" class="numeric">Difference</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($locationDiscrepancies as $row): ?>
                      <tr>
                        <th scope="row">
                          <a href="/inventory.php?id=<?= e((string) $row['id']) ?>" class="table-link"><?= e($row['item']) ?></a>
                        </th>
                        <td><?= e($row['sku']) ?></td>
                        <td class="numeric"><?= e(inventoryFormatQuantity($row['stock'])) ?></td>
                        <td class="numeric"><?= e(inventoryFormatQuantity($row['location_stock'])) ?></td>
                        <td class="numeric<?= $row['difference'] !== 0 ? ' danger' : '' ?>">
                          <?= e(inventoryFormatQuantity($row['difference'])) ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </main>
  </div>
  <script src="/js/dashboard.js"></script>
</body>
</html>
