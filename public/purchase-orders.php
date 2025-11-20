<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/purchase_orders.php';
require_once __DIR__ . '/../app/services/purchase_order_documents.php';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] ?? '') === 'Purchase Orders';
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$generalErrors = [];
$successMessage = null;
$filter = isset($_GET['filter']) ? trim((string) $_GET['filter']) : 'open';
if ($filter === '') {
    $filter = 'open';
}
$selectedPurchaseOrderId = null;
$selectedPurchaseOrder = null;

if (isset($_GET['po_id']) && ctype_digit((string) $_GET['po_id'])) {
    $selectedPurchaseOrderId = (int) $_GET['po_id'];
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $successMessage = 'Purchase order status updated successfully.';
}

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $db = null;
    $generalErrors[] = 'Unable to connect to the database: ' . $exception->getMessage();
}

function purchaseOrderIsTubeliteSupplier(?array $supplier): bool
{
    if ($supplier === null || !isset($supplier['name'])) {
        return false;
    }

    return stripos((string) $supplier['name'], 'tubelite') !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($db)) {
    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
    $postedFilter = isset($_POST['filter']) ? trim((string) $_POST['filter']) : '';
    if ($postedFilter !== '') {
        $filter = $postedFilter;
    }

    $postedId = isset($_POST['purchase_order_id']) && ctype_digit((string) $_POST['purchase_order_id'])
        ? (int) $_POST['purchase_order_id']
        : null;

    if ($postedId !== null) {
        $selectedPurchaseOrderId = $postedId;
    }

    $redirectQuery = http_build_query([
        'po_id' => $selectedPurchaseOrderId,
        'filter' => $filter,
    ]);

    if ($action === 'update_status' && $postedId !== null) {
        $status = isset($_POST['status']) ? trim((string) $_POST['status']) : '';
        if ($status === '' || !in_array($status, purchaseOrderStatusList(), true)) {
            $generalErrors[] = 'Choose a valid purchase order status.';
        } else {
            try {
                updatePurchaseOrder($db, $postedId, ['status' => $status]);
                header('Location: /purchase-orders.php?' . $redirectQuery . '&updated=1');
                exit;
            } catch (\Throwable $exception) {
                $generalErrors[] = 'Unable to update purchase order: ' . $exception->getMessage();
            }
        }
    } elseif ($action === 'download_pdf' && $postedId !== null) {
        try {
            $purchaseOrder = loadPurchaseOrder($db, $postedId);
            if ($purchaseOrder === null) {
                throw new RuntimeException('Purchase order not found.');
            }

            $pdf = generatePurchaseOrderPdfContent($db, $postedId);
            $orderNumber = $purchaseOrder['order_number'] ?? sprintf('PO-%d', $purchaseOrder['id']);
            $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $orderNumber) . '.pdf';
            if ($filename === '.pdf') {
                $filename = 'purchase-order.pdf';
            }

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        } catch (\Throwable $exception) {
            $generalErrors[] = 'Unable to generate purchase order PDF: ' . $exception->getMessage();
        }
    } elseif ($action === 'download_tubelite' && $postedId !== null) {
        try {
            $purchaseOrder = loadPurchaseOrder($db, $postedId);
            if ($purchaseOrder === null) {
                throw new RuntimeException('Purchase order not found.');
            }

            if (!purchaseOrderIsTubeliteSupplier($purchaseOrder['supplier'] ?? null)) {
                throw new RuntimeException('Tubelite exports are only available for Tubelite suppliers.');
            }

            $templatePath = __DIR__ . '/../app/helpers/EZ_Estimate.xlsm';
            $tempBase = tempnam(sys_get_temp_dir(), 'fd_po_');
            if ($tempBase === false) {
                throw new RuntimeException('Unable to prepare a temporary workbook.');
            }

            $workbookPath = $tempBase . '.xlsm';
            if (!rename($tempBase, $workbookPath)) {
                unlink($tempBase);
                throw new RuntimeException('Unable to initialize workbook for download.');
            }

            generateTubeliteEzEstimateOrder($db, $postedId, $templatePath, $workbookPath);

            $orderNumber = $purchaseOrder['order_number'] ?? sprintf('PO-%d', $purchaseOrder['id']);
            $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $orderNumber) . '-tubelite.xlsm';
            if ($filename === '-tubelite.xlsm') {
                $filename = 'tubelite-ez-estimate.xlsm';
            }

            header('Content-Type: application/vnd.ms-excel.sheet.macroEnabled.12');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($workbookPath));
            readfile($workbookPath);
            unlink($workbookPath);
            exit;
        } catch (\Throwable $exception) {
            $generalErrors[] = 'Unable to generate Tubelite EZ Estimate: ' . $exception->getMessage();
        }
    }
}

$orders = [];
if (isset($db)) {
    try {
        $orders = purchaseOrderListRecent($db, $filter, 100);
    } catch (\Throwable $exception) {
        $generalErrors[] = 'Unable to load purchase orders: ' . $exception->getMessage();
        $orders = [];
    }

    if ($selectedPurchaseOrderId === null && $orders !== []) {
        $selectedPurchaseOrderId = $orders[0]['id'];
    }

    if ($selectedPurchaseOrderId !== null) {
        try {
            $selectedPurchaseOrder = loadPurchaseOrder($db, $selectedPurchaseOrderId);
        } catch (\Throwable $exception) {
            $generalErrors[] = 'Unable to load purchase order details: ' . $exception->getMessage();
        }
    }
}

$orderStatuses = purchaseOrderStatusList();
$filterOptions = array_merge(['open', 'all'], $orderStatuses);

$bodyAttributes = ' class="has-sidebar-toggle"';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> Purchase Orders</title>
  <link rel="stylesheet" href="css/dashboard.css" />
</head>
<body<?= $bodyAttributes ?>>
  <div class="layout">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <header class="topbar">
      <button
        class="topbar-toggle"
        type="button"
        data-sidebar-toggle
        aria-controls="app-sidebar"
        aria-expanded="false"
        aria-label="Toggle navigation"
      >
        <span aria-hidden="true"><?= icon('menu') ?></span>
      </button>
      <form class="search" role="search" aria-label="Purchase order search">
        <span aria-hidden="true"><?= icon('search') ?></span>
        <input type="search" name="q" placeholder="Search purchase orders" disabled />
      </form>
      <button class="user" type="button">
        <span class="user-avatar" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
        <span class="user-email"><?= e($app['user']['email']) ?></span>
        <span aria-hidden="true"><?= icon('chev') ?></span>
      </button>
    </header>

    <main class="content">
      <section class="panel" aria-labelledby="purchase-orders-title">
        <header class="panel-header">
          <div>
            <h1 id="purchase-orders-title">Purchase Orders</h1>
            <p class="small">Review, reprint, and update purchasing activity across all suppliers.</p>
          </div>
        </header>

        <?php if ($successMessage !== null): ?>
          <div class="alert success" role="status">
            <?= e($successMessage) ?>
          </div>
        <?php endif; ?>

        <?php if ($generalErrors !== []): ?>
          <div class="alert error" role="alert">
            <ul class="plain-list">
              <?php foreach ($generalErrors as $error): ?>
                <li><?= e($error) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="receiving-grid" data-purchase-orders>
          <aside class="receiving-sidebar" aria-label="Purchase order filters">
            <h2>Filters</h2>
            <form method="get" class="filter-form">
              <label for="filter-select" class="sr-only">Filter purchase orders</label>
              <select id="filter-select" name="filter" onchange="this.form.submit()">
                <?php foreach ($filterOptions as $option): ?>
                  <?php
                    $label = $option === 'all' ? 'All' : ($option === 'open' ? 'Open' : ucwords(str_replace('_', ' ', $option)));
                  ?>
                  <option value="<?= e($option) ?>"<?= $filter === $option ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <h3>Recent orders</h3>
            <div class="receiving-order-list">
              <?php if ($orders === []): ?>
                <p class="muted">No purchase orders match this filter.</p>
              <?php else: ?>
                <?php foreach ($orders as $order): ?>
                  <?php
                    $orderId = (int) $order['id'];
                    $isActive = $selectedPurchaseOrder !== null && $selectedPurchaseOrder['id'] === $orderId;
                    $label = $order['order_number'] ?? ('PO #' . $orderId);
                    $supplierName = $order['supplier_name'] ?? 'Unassigned supplier';
                    $statusLabel = ucwords(str_replace('_', ' ', $order['status']));
                    $linkQuery = http_build_query([
                        'po_id' => $orderId,
                        'filter' => $filter,
                    ]);
                  ?>
                  <a
                    class="receiving-order <?= $isActive ? 'active' : '' ?>"
                    href="/purchase-orders.php?<?= e($linkQuery) ?>"
                    data-order
                    data-order-title="<?= e($label) ?>"
                    data-order-supplier="<?= e($supplierName) ?>"
                  >
                    <span class="order-title"><?= e($label) ?></span>
                    <span class="order-meta"><?= e($supplierName) ?> · <?= e($statusLabel) ?></span>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </aside>

          <div class="receiving-main">
            <?php if ($selectedPurchaseOrder === null): ?>
              <div class="card">
                <div class="card-body">
                  <p class="muted">Select a purchase order to view details.</p>
                </div>
              </div>
            <?php else: ?>
              <?php
                $isTubelite = purchaseOrderIsTubeliteSupplier($selectedPurchaseOrder['supplier'] ?? null);
                $orderNumber = $selectedPurchaseOrder['order_number'] ?? ('PO #' . $selectedPurchaseOrder['id']);
                $statusLabel = ucwords(str_replace('_', ' ', $selectedPurchaseOrder['status']));
                $orderLinkQuery = http_build_query([
                    'po_id' => $selectedPurchaseOrder['id'],
                    'filter' => $filter,
                ]);
              ?>
              <article class="card" aria-labelledby="po-detail-title">
                <header class="card-header">
                  <div>
                    <h2 id="po-detail-title"><?= e($orderNumber) ?></h2>
                    <p class="small">
                      Status: <?= e($statusLabel) ?>
                      <?php if ($selectedPurchaseOrder['supplier'] !== null): ?>
                        · Supplier: <?= e($selectedPurchaseOrder['supplier']['name']) ?>
                      <?php endif; ?>
                    </p>
                  </div>
                  <div class="button-group">
                    <a class="button secondary" href="/receive-material.php?po_id=<?= e((string) $selectedPurchaseOrder['id']) ?>">Receive material</a>
                  </div>
                </header>
                <div class="card-body">
                  <dl class="data-grid">
                    <div>
                      <dt>Order date</dt>
                      <dd><?= e($selectedPurchaseOrder['order_date'] ?? '—') ?></dd>
                    </div>
                    <div>
                      <dt>Expected date</dt>
                      <dd><?= e($selectedPurchaseOrder['expected_date'] ?? '—') ?></dd>
                    </div>
                    <div>
                      <dt>Total cost</dt>
                      <dd>$<?= e(number_format((float) $selectedPurchaseOrder['total_cost'], 2)) ?></dd>
                    </div>
                  </dl>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="purchase_order_id" value="<?= e((string) $selectedPurchaseOrder['id']) ?>" />
                    <input type="hidden" name="filter" value="<?= e($filter) ?>" />
                    <label for="status-select">Status</label>
                    <select id="status-select" name="status">
                      <?php foreach ($orderStatuses as $status): ?>
                        <option value="<?= e($status) ?>"<?= $selectedPurchaseOrder['status'] === $status ? ' selected' : '' ?>>
                          <?= e(ucwords(str_replace('_', ' ', $status))) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" name="action" value="update_status" class="button secondary">Update status</button>
                  </form>
                  <div class="button-group">
                    <form method="post" class="inline" style="display:inline-block">
                      <input type="hidden" name="purchase_order_id" value="<?= e((string) $selectedPurchaseOrder['id']) ?>" />
                      <input type="hidden" name="filter" value="<?= e($filter) ?>" />
                      <button class="button primary" type="submit" name="action" value="download_pdf">Download PO PDF</button>
                    </form>
                    <?php if ($isTubelite): ?>
                      <form method="post" class="inline" style="display:inline-block">
                        <input type="hidden" name="purchase_order_id" value="<?= e((string) $selectedPurchaseOrder['id']) ?>" />
                        <input type="hidden" name="filter" value="<?= e($filter) ?>" />
                        <button class="button secondary" type="submit" name="action" value="download_tubelite">Download Tubelite EZ Estimate</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="table-responsive">
                  <table class="data-table table" data-sortable-table>
                    <thead>
                      <tr>
                        <th scope="col" class="sortable" data-sort-key="sku" aria-sort="none">SKU</th>
                        <th scope="col" class="sortable" data-sort-key="description" aria-sort="none">Description</th>
                        <th scope="col" class="sortable" data-sort-key="ordered" data-sort-type="number" aria-sort="none">Ordered</th>
                        <th scope="col" class="sortable" data-sort-key="received" data-sort-type="number" aria-sort="none">Received</th>
                        <th scope="col" class="sortable" data-sort-key="cancelled" data-sort-type="number" aria-sort="none">Cancelled</th>
                        <th scope="col" class="sortable" data-sort-key="outstanding" data-sort-type="number" aria-sort="none">Outstanding</th>
                        <th scope="col" class="sortable" data-sort-key="unitCost" data-sort-type="number" aria-sort="none">Unit Cost</th>
                        <th scope="col" class="sortable" data-sort-key="lineTotal" data-sort-type="number" aria-sort="none">Line Total</th>
                      </tr>
                      <tr class="filter-row">
                        <th><input type="search" class="column-filter" data-key="sku" placeholder="Search SKU" aria-label="Filter by SKU"></th>
                        <th><input type="search" class="column-filter" data-key="description" placeholder="Search description" aria-label="Filter by description"></th>
                        <th><input type="search" class="column-filter" data-key="ordered" placeholder="Search ordered" aria-label="Filter by ordered" inputmode="decimal"></th>
                        <th><input type="search" class="column-filter" data-key="received" placeholder="Search received" aria-label="Filter by received" inputmode="decimal"></th>
                        <th><input type="search" class="column-filter" data-key="cancelled" placeholder="Search cancelled" aria-label="Filter by cancelled" inputmode="decimal"></th>
                        <th><input type="search" class="column-filter" data-key="outstanding" placeholder="Search outstanding" aria-label="Filter by outstanding" inputmode="decimal"></th>
                        <th><input type="search" class="column-filter" data-key="unitCost" placeholder="Search cost" aria-label="Filter by unit cost" inputmode="decimal"></th>
                        <th><input type="search" class="column-filter" data-key="lineTotal" placeholder="Search total" aria-label="Filter by line total" inputmode="decimal"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($selectedPurchaseOrder['lines'] as $line): ?>
                        <?php
                          $orderedLabel = purchaseOrderFormatLineQuantity($line);
                          $receivedLabel = purchaseOrderFormatQuantityForLine($line, (float) $line['quantity_received']);
                          $cancelledLabel = purchaseOrderFormatQuantityForLine($line, (float) $line['quantity_cancelled']);
                          $outstandingLabel = purchaseOrderFormatQuantityForLine($line, (float) $line['outstanding_quantity']);
                          $unitCost = (float) $line['unit_cost'];
                          $lineTotal = (float) $line['quantity_ordered'] * $unitCost;
                        ?>
                        <tr
                          data-row
                          data-sku="<?= e($line['sku'] ?? $line['supplier_sku'] ?? '—') ?>"
                          data-description="<?= e($line['description'] ?? ($line['item'] ?? '')) ?>"
                          data-ordered="<?= e((string) $line['quantity_ordered']) ?>"
                          data-received="<?= e((string) $line['quantity_received']) ?>"
                          data-cancelled="<?= e((string) $line['quantity_cancelled']) ?>"
                          data-outstanding="<?= e((string) $line['outstanding_quantity']) ?>"
                          data-unit-cost="<?= e((string) $unitCost) ?>"
                          data-line-total="<?= e((string) $lineTotal) ?>"
                        >
                          <th scope="row"><?= e($line['sku'] ?? $line['supplier_sku'] ?? '—') ?></th>
                          <td><?= e($line['description'] ?? ($line['item'] ?? '')) ?></td>
                          <td><?= e($orderedLabel) ?></td>
                          <td><?= e($receivedLabel) ?></td>
                          <td><?= e($cancelledLabel) ?></td>
                          <td><?= e($outstandingLabel) ?></td>
                          <td>$<?= e(number_format($unitCost, 2)) ?></td>
                          <td>$<?= e(number_format($lineTotal, 2)) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </article>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </main>
  </div>
  <script src="js/sortable-table.js" defer></script>
  <script src="js/dashboard.js"></script>
</body>
</html>
