<?php
declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/data/metrics.php';
require_once __DIR__ . '/../../app/data/inventory.php';
require_once __DIR__ . '/../../app/data/storage_locations.php';
require_once __DIR__ . '/../../app/views/components/inventory_table.php';

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
$locationHierarchy = [];

try {
    $db = db($databaseConfig);
    $metrics = loadMetrics($db);
    $inventory = loadInventory($db);
    $locationHierarchy = storageLocationsHierarchy($db);
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
} catch (Throwable $exception) {
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

function renderNavBadge(array $item): string
{
    if (empty($item['badge'])) {
        return '';
    }

    $class = 'badge text-uppercase ms-2';
    if (!empty($item['badge_class'])) {
        $class .= ' bg-' . htmlspecialchars((string) $item['badge_class'], ENT_QUOTES, 'UTF-8');
    } else {
        $class .= ' bg-azure';
    }

    return sprintf('<span class="%s">%s</span>', $class, htmlspecialchars((string) $item['badge'], ENT_QUOTES, 'UTF-8'));
}

function renderNavLink(array $item, bool $isDropdown = false): string
{
    $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
    $href = htmlspecialchars((string) ($item['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
    $iconName = (string) ($item['icon'] ?? 'grid');
    $iconSvg = icon($iconName);
    $badge = renderNavBadge($item);

    if ($isDropdown) {
        return sprintf(
            '<a class="dropdown-item" href="%s">%s<span class="dropdown-item-icon">%s</span>%s</a>',
            $href,
            $label,
            $iconSvg,
            $badge
        );
    }

    return sprintf(
        '<a class="nav-link" href="%s">%s<span class="nav-link-title">%s</span>%s</a>',
        $href,
        $iconSvg,
        $label,
        $badge
    );
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark" data-bs-theme-primary="red">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta http-equiv="X-UA-Compatible" content="ie=edge" />
    <title><?= e($app['name']) ?> Inventory Dashboard (Tabler)</title>
    <link href="./tabler_files/jsvectormap.css" rel="stylesheet" />
    <link href="./tabler_files/tabler.css" rel="stylesheet" />
    <link href="./tabler_files/tabler-flags.css" rel="stylesheet" />
    <link href="./tabler_files/tabler-socials.css" rel="stylesheet" />
    <link href="./tabler_files/tabler-payments.css" rel="stylesheet" />
    <link href="./tabler_files/tabler-vendors.css" rel="stylesheet" />
    <link href="./tabler_files/tabler-marketing.css" rel="stylesheet" />
    <link href="./tabler_files/tabler-themes.css" rel="stylesheet" />
    <link href="./tabler_files/demo.css" rel="stylesheet" />
    <style>
      @import url('https://rsms.me/inter/inter.css');
    </style>
  </head>
  <body class="layout-fluid">
    <div class="page">
      <header class="navbar navbar-expand-md d-print-none" aria-label="Primary navigation">
        <div class="container-xl">
          <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu"
            aria-controls="navbar-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
          </button>
          <a class="navbar-brand" href="/">
            <span class="navbar-brand-text">ForgeDesk</span>
          </a>
          <div class="navbar-nav flex-row order-md-last">
            <div class="nav-item d-none d-md-flex me-3 align-items-center">
              <?php if ($dbError === null): ?>
                <span class="status status-green"></span>
                <span class="ms-2 text-muted">Database live</span>
              <?php else: ?>
                <span class="status status-red"></span>
                <span class="ms-2 text-danger">Database error</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="collapse navbar-collapse" id="navbar-menu">
            <div class="d-flex flex-column flex-md-row flex-fill align-items-stretch align-items-md-center">
              <ul class="navbar-nav">
                <?php foreach ($nav as $groupLabel => $items): ?>
                  <?php if (count($items) === 1): ?>
                    <li class="nav-item">
                      <?= renderNavLink($items[0]) ?>
                    </li>
                  <?php else: ?>
                    <li class="nav-item dropdown">
                      <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false">
                        <span class="nav-link-title"><?= e((string) $groupLabel) ?></span>
                      </a>
                      <div class="dropdown-menu">
                        <?php foreach ($items as $item): ?>
                          <?= renderNavLink($item, true) ?>
                        <?php endforeach; ?>
                      </div>
                    </li>
                  <?php endif; ?>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        </div>
      </header>

      <div class="page-wrapper">
        <div class="page-header d-print-none">
          <div class="container-xl d-flex justify-content-between align-items-center">
            <div>
              <div class="page-pretitle">Inventory</div>
              <h2 class="page-title">Dashboard (Tabler Preview)</h2>
            </div>
            <div class="d-flex gap-2">
              <a href="/index.php" class="btn btn-secondary">
                Legacy dashboard
              </a>
            </div>
          </div>
        </div>

        <div class="page-body">
          <div class="container-xl">
            <?php if ($dbError !== null): ?>
              <div class="alert alert-danger" role="alert">
                <div class="d-flex">
                  <div>
                    <i class="ti ti-alert-triangle"></i>
                  </div>
                  <div class="ms-2">
                    <h4 class="alert-title">Database error</h4>
                    <div class="text-muted"><?= e($dbError) ?></div>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <div class="row row-deck row-cards">
              <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                  <div class="card-body">
                    <div class="d-flex align-items-center">
                      <div class="subheader">SKUs tracked</div>
                      <div class="ms-auto lh-1">
                        <span class="badge bg-success text-uppercase">Live</span>
                      </div>
                    </div>
                    <div class="h1 mb-3"><?= e(inventoryFormatQuantity($inventoryStats['sku_count'])) ?></div>
                    <div class="d-flex align-items-center">
                      <div class="text-muted">Live parts currently in the system.</div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                  <div class="card-body">
                    <div class="d-flex align-items-center">
                      <div class="subheader">Units on hand</div>
                    </div>
                    <div class="h1 mb-3"><?= e(inventoryFormatQuantity($inventoryStats['units_on_hand'])) ?></div>
                    <div class="d-flex align-items-center">
                      <div class="text-muted">Quantities available across all SKUs.</div>
                    </div>
                  </div>
                </div>
              </div>
              <?php foreach ($metrics as $metric): ?>
                <div class="col-sm-6 col-lg-3">
                  <div class="card card-sm<?= !empty($metric['accent']) ? ' card-stacked' : '' ?>">
                    <div class="card-body">
                      <div class="d-flex align-items-center">
                        <div class="subheader"><?= e($metric['label']) ?></div>
                        <?php if (!empty($metric['time'])): ?>
                          <div class="ms-auto lh-1 text-muted small"><?= e($metric['time']) ?></div>
                        <?php endif; ?>
                      </div>
                      <div class="h1 mb-3"><?= e((string) $metric['value']) ?></div>
                      <?php if (!empty($metric['delta'])): ?>
                        <div class="d-flex align-items-center">
                          <div class="text-muted"><?= e($metric['delta']) ?></div>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="row row-cards mt-3">
              <div class="col-12">
                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title">Inventory Snapshot</h3>
                    <div class="ms-auto text-muted">Updated <?= date('M j, Y') ?></div>
                  </div>
                  <div class="card-tabs">
                    <ul class="nav nav-tabs" data-bs-toggle="tabs">
                      <li class="nav-item">
                        <a href="#tab-all" class="nav-link active" data-bs-toggle="tab">
                          All Inventory
                          <span class="badge bg-secondary ms-2"><?= e((string) $allInventoryCount) ?></span>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="#tab-low" class="nav-link" data-bs-toggle="tab">
                          Low &amp; Critical
                          <span class="badge bg-orange ms-2"><?= e((string) $lowCriticalCount) ?></span>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="#tab-committed" class="nav-link" data-bs-toggle="tab">
                          Committed Parts
                          <span class="badge bg-purple ms-2"><?= e((string) $committedCount) ?></span>
                        </a>
                      </li>
                    </ul>
                  </div>
                  <div class="card-body tab-content">
                    <div class="tab-pane active show" id="tab-all">
                      <div class="table-responsive">
                        <?php renderInventoryTable($inventory, [
                            'includeFilters' => true,
                            'emptyMessage' => 'No inventory items found. Add rows to the inventory system to populate this dashboard.',
                            'id' => 'tabler-inventory-all',
                            'pageSize' => 15,
                            'showActions' => false,
                            'locationHierarchy' => $locationHierarchy,
                        ]); ?>
                      </div>
                    </div>
                    <div class="tab-pane" id="tab-low">
                      <div class="table-responsive">
                        <?php renderInventoryTable($lowCriticalInventory, [
                            'includeFilters' => false,
                            'emptyMessage' => 'No low or critical parts right now.',
                            'id' => 'tabler-inventory-low',
                            'pageSize' => 15,
                            'showActions' => false,
                        ]); ?>
                      </div>
                    </div>
                    <div class="tab-pane" id="tab-committed">
                      <div class="table-responsive">
                        <?php renderInventoryTable($committedInventory, [
                            'includeFilters' => false,
                            'emptyMessage' => 'No parts are currently committed to jobs.',
                            'id' => 'tabler-inventory-committed',
                            'pageSize' => 15,
                            'showActions' => false,
                        ]); ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="row row-cards mt-3">
              <div class="col-12">
                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title">Next Modules</h3>
                    <div class="ms-auto text-muted">Work orders &amp; assemblies on the horizon</div>
                  </div>
                  <div class="card-body">
                    <div class="row row-cards">
                      <div class="col-md-4">
                        <div class="card">
                          <div class="card-body">
                            <h3 class="card-title">Work Order Management</h3>
                            <p class="text-muted">Track fabrication tasks with schedules, dependencies, and quality checkpoints.</p>
                          </div>
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="card">
                          <div class="card-body">
                            <h3 class="card-title">Assembly Builder</h3>
                            <p class="text-muted">Generate bills of materials for standard storefront packages and custom cuts.</p>
                          </div>
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="card">
                          <div class="card-body">
                            <h3 class="card-title">Live Purchasing Queue</h3>
                            <p class="text-muted">Surface buying actions tied to shortages, jobs, and supplier lead times.</p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="./tabler_files/tabler.min.js.download" defer></script>
    <script src="./tabler_files/demo.min.js.download" defer></script>
  </body>
</html>
