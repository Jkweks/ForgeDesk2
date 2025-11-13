<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/metrics.php';
require_once __DIR__ . '/../app/data/inventory.php';
require_once __DIR__ . '/../app/views/components/inventory_table.php';

$databaseConfig = $app['database'];
$metrics = [];
$inventory = [];
$inventoryStats = [
    'sku_count' => 0,
    'units_on_hand' => 0,
];
$dbError = null;
$lowCriticalInventory = [];
$committedInventory = [];
$allInventoryCount = 0;
$lowCriticalCount = 0;
$committedCount = 0;

try {
    $db = db($databaseConfig);
    $metrics = loadMetrics($db);
    $inventory = loadInventory($db);
    $totals = inventoryReservationSummary($db);

    $inventoryStats = [
        'sku_count' => count($inventory),
        'units_on_hand' => $totals['total_stock'] ?? 0,
    ];

    $lowCriticalInventory = array_values(array_filter(
        $inventory,
        static function (array $row): bool {
            if (!empty($row['discontinued'])) {
                return false;
            }

            $status = strtolower((string) $row['status']);

            return $status === 'low' || $status === 'critical';
        }
    ));

    $committedInventory = array_values(array_filter(
        $inventory,
        static fn (array $row): bool => (int) $row['committed_qty'] > 0
    ));

    $allInventoryCount = count($inventory);
    $lowCriticalCount = count($lowCriticalInventory);
    $committedCount = count($committedInventory);
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
          <div class="report-tabs" data-report-tabs>
            <div class="report-tabs__list" role="tablist">
              <button type="button" role="tab" id="report-tab-all" aria-controls="report-panel-all" aria-selected="true" tabindex="0" data-report-tab="all">
                All Inventory <span class="report-tabs__count"><?= e((string) $allInventoryCount) ?></span>
              </button>
              <button type="button" role="tab" id="report-tab-low" aria-controls="report-panel-low" aria-selected="false" tabindex="-1" data-report-tab="low">
                Low &amp; Critical <span class="report-tabs__count"><?= e((string) $lowCriticalCount) ?></span>
              </button>
              <button type="button" role="tab" id="report-tab-committed" aria-controls="report-panel-committed" aria-selected="false" tabindex="-1" data-report-tab="committed">
                Committed Parts <span class="report-tabs__count"><?= e((string) $committedCount) ?></span>
              </button>
            </div>
            <div class="report-tabs__panels">
              <section id="report-panel-all" class="report-tabs__panel" role="tabpanel" aria-labelledby="report-tab-all" data-report-panel="all">
                <?php renderInventoryTable($inventory, [
                    'includeFilters' => true,
                    'emptyMessage' => 'No inventory items found. Add rows to the inventory system to populate this dashboard.',
                    'id' => 'dashboard-inventory-all',
                    'pageSize' => 15,
                    'showActions' => false,
                ]); ?>
              </section>
              <section id="report-panel-low" class="report-tabs__panel" role="tabpanel" aria-labelledby="report-tab-low" data-report-panel="low" hidden>
                <?php renderInventoryTable($lowCriticalInventory, [
                    'includeFilters' => false,
                    'emptyMessage' => 'No low or critical parts right now.',
                    'id' => 'dashboard-inventory-low',
                    'pageSize' => 15,
                    'showActions' => false,
                ]); ?>
              </section>
              <section id="report-panel-committed" class="report-tabs__panel" role="tabpanel" aria-labelledby="report-tab-committed" data-report-panel="committed" hidden>
                <?php renderInventoryTable($committedInventory, [
                    'includeFilters' => false,
                    'emptyMessage' => 'No parts are currently committed to jobs.',
                    'id' => 'dashboard-inventory-committed',
                    'pageSize' => 15,
                    'showActions' => false,
                ]); ?>
              </section>
            </div>
          </div>
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
  <script src="js/inventory-table.js"></script>
  <script>
  (function () {
    const container = document.querySelector('[data-report-tabs]');
    if (!container) {
      return;
    }

    const tabs = Array.from(container.querySelectorAll('[data-report-tab]'));
    const panels = Array.from(container.querySelectorAll('[data-report-panel]'));

    function showTab(targetId) {
      if (!targetId) {
        return;
      }

      tabs.forEach((tab) => {
        const isActive = tab.dataset.reportTab === targetId;
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        tab.setAttribute('tabindex', isActive ? '0' : '-1');
      });

      panels.forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
          return;
        }

        const isActive = panel.dataset.reportPanel === targetId;
        if (isActive) {
          panel.removeAttribute('hidden');
        } else {
          panel.setAttribute('hidden', 'hidden');
        }
      });
    }

    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => {
        showTab(tab.dataset.reportTab || '');
      });

      tab.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          showTab(tab.dataset.reportTab || '');
        }

        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
          event.preventDefault();
          const offset = event.key === 'ArrowRight' ? 1 : -1;
          const nextIndex = (index + offset + tabs.length) % tabs.length;
          const nextTab = tabs[nextIndex];
          nextTab.focus();
          showTab(nextTab.dataset.reportTab || '');
        }
      });
    });

    const initiallySelected = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true');
    if (initiallySelected) {
      showTab(initiallySelected.dataset.reportTab || '');
    } else if (tabs[0]) {
      showTab(tabs[0].dataset.reportTab || '');
    }
  })();
  </script>
</body>
</html>
