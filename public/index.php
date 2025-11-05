<?php
$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';
$metrics = require __DIR__ . '/../app/data/metrics.php';
$inventory = require __DIR__ . '/../app/data/inventory.php';
require_once __DIR__ . '/../app/helpers/icons.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
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
    <aside class="sidebar">
      <div class="brand">
        <span class="brand-badge"><?= e($app['user']['avatar']) ?></span>
        <div>
          <strong><?= e($app['name']) ?></strong>
          <div class="small"><?= e($app['branding']['tagline']) ?></div>
        </div>
        <span class="brand-version"><?= e($app['version']) ?></span>
      </div>
      <?php foreach ($nav as $group => $items): ?>
        <nav class="nav-group">
          <h6><?= e($group) ?></h6>
          <?php foreach ($items as $item): ?>
            <?php $isActive = $item['active'] ?? false; ?>
            <a class="nav-item<?= $isActive ? ' active' : '' ?>" href="#<?= strtolower(str_replace(' ', '-', $item['label'])) ?>">
              <span aria-hidden="true"><?= icon($item['icon']) ?></span>
              <span><?= e($item['label']) ?></span>
              <?php if (!empty($item['badge'])): ?>
                <span class="badge"><?= e($item['badge']) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>
      <?php endforeach; ?>
    </aside>

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
                <th scope="col">SKU</th>
                <th scope="col">Location</th>
                <th scope="col">Stock</th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inventory as $row): ?>
                <tr>
                  <td><?= e($row['item']) ?></td>
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
