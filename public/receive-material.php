<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/inventory.php';
require_once __DIR__ . '/../app/data/purchase_orders.php';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] ?? '') === 'Receive Material';
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$generalErrors = [];
$lineErrors = [];
$successMessage = null;
$selectedPurchaseOrderId = null;
$selectedPurchaseOrder = null;
$receiptHistory = [];
$openOrders = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['purchase_order_id']) && ctype_digit((string) $_POST['purchase_order_id'])) {
        $selectedPurchaseOrderId = (int) $_POST['purchase_order_id'];
    }
} elseif (isset($_GET['po_id']) && ctype_digit((string) $_GET['po_id'])) {
    $selectedPurchaseOrderId = (int) $_GET['po_id'];
}

$formValues = [
    'reference' => '',
    'notes' => '',
    'lines' => [],
];

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if (isset($db) && $db instanceof \PDO) {
    if ($selectedPurchaseOrderId !== null) {
        try {
            $selectedPurchaseOrder = loadPurchaseOrder($db, $selectedPurchaseOrderId);
        } catch (\Throwable $exception) {
            $generalErrors[] = 'Unable to load purchase order: ' . $exception->getMessage();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedPurchaseOrder !== null) {
        $reference = isset($_POST['reference']) ? trim((string) $_POST['reference']) : '';
        $notes = isset($_POST['notes']) ? trim((string) $_POST['notes']) : '';
        $formValues['reference'] = $reference;
        $formValues['notes'] = $notes;

        $submittedLines = $_POST['lines'] ?? [];
        if (is_array($submittedLines)) {
            foreach ($submittedLines as $lineId => $values) {
                $formValues['lines'][(string) $lineId] = [
                    'backorder' => isset($values['backorder']) ? trim((string) $values['backorder']) : '',
                ];
            }
        }

        $changes = [];
        $hasChange = false;

        foreach ($selectedPurchaseOrder['lines'] as $line) {
            $lineId = $line['id'];
            $key = (string) $lineId;
            $values = $formValues['lines'][$key] ?? ['backorder' => ''];
            $backorderRaw = $values['backorder'];
            $errors = [];
            $outstanding = $line['outstanding_quantity'];

            $backorder = $backorderRaw !== '' ? filter_var($backorderRaw, FILTER_VALIDATE_FLOAT) : 0.0;

            if ($backorder === false || $backorder < 0) {
                $errors['backorder'] = 'Enter a non-negative backordered quantity.';
            }

            if ($backorder !== false && $backorder > $outstanding + 0.0005) {
                $errors['backorder'] = 'Backordered quantity cannot exceed the outstanding amount.';
            }

            if ($errors !== []) {
                $lineErrors[$lineId] = $errors;
                continue;
            }

            $backorder = $backorder !== false ? (float) $backorder : 0.0;
            $receive = max($outstanding - $backorder, 0.0);

            if ($receive <= 0.0) {
                continue;
            }

            $hasChange = true;
            $changes[$lineId] = ['receive' => $receive];
        }

        if (!$hasChange) {
            $generalErrors[] = 'Enter backordered quantities that leave received material before submitting.';
        }

        if ($generalErrors === [] && $lineErrors === []) {
            $defaultReference = $selectedPurchaseOrder['order_number'] !== null
                ? 'Receipt for PO ' . $selectedPurchaseOrder['order_number']
                : 'Receipt for PO #' . $selectedPurchaseOrder['id'];
            $referenceValue = $reference !== '' ? $reference : $defaultReference;

            try {
                $result = recordPurchaseOrderReceipt(
                    $db,
                    $selectedPurchaseOrder['id'],
                    $changes,
                    $referenceValue,
                    $notes !== '' ? $notes : null
                );

                if ($result['lines'] === []) {
                    $generalErrors[] = 'No receipt quantities were processed.';
                } else {
                    $redirectUrl = '/receive-material.php?po_id=' . $selectedPurchaseOrder['id']
                        . '&success=recorded';
                    if (!empty($result['receipt_id'])) {
                        $redirectUrl .= '&receipt_id=' . (int) $result['receipt_id'];
                    }
                    header('Location: ' . $redirectUrl);
                    exit;
                }
            } catch (\Throwable $exception) {
                $generalErrors[] = 'Unable to record receipt: ' . $exception->getMessage();
            }
        }
    }

    try {
        $openOrders = purchaseOrderListOpen($db);
    } catch (\Throwable $exception) {
        $generalErrors[] = 'Unable to load open purchase orders: ' . $exception->getMessage();
        $openOrders = [];
    }

    if ($selectedPurchaseOrder === null) {
        if ($selectedPurchaseOrderId === null && $openOrders !== []) {
            $selectedPurchaseOrderId = $openOrders[0]['id'];
        }

        if ($selectedPurchaseOrderId !== null) {
            try {
                $selectedPurchaseOrder = loadPurchaseOrder($db, $selectedPurchaseOrderId);
            } catch (\Throwable $exception) {
                $generalErrors[] = 'Unable to load purchase order: ' . $exception->getMessage();
            }
        }
    } else {
        try {
            $selectedPurchaseOrder = loadPurchaseOrder($db, $selectedPurchaseOrder['id']);
        } catch (\Throwable $exception) {
            $generalErrors[] = 'Unable to refresh purchase order: ' . $exception->getMessage();
        }
    }

    if ($selectedPurchaseOrder !== null) {
        if ($formValues['reference'] === '') {
            $formValues['reference'] = $selectedPurchaseOrder['order_number'] !== null
                ? 'Receipt for PO ' . $selectedPurchaseOrder['order_number']
                : 'Receipt for PO #' . $selectedPurchaseOrder['id'];
        }

        foreach ($selectedPurchaseOrder['lines'] as $line) {
            $lineId = $line['id'];
            $key = (string) $lineId;
            if (!isset($formValues['lines'][$key])) {
                $formValues['lines'][$key] = ['backorder' => ''];
            }
        }

        try {
            $receiptHistory = purchaseOrderLoadReceiptHistory($db, $selectedPurchaseOrder['id']);
        } catch (\Throwable $exception) {
            $generalErrors[] = 'Unable to load receipt history: ' . $exception->getMessage();
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] === 'recorded') {
    $successMessage = 'Receipt recorded successfully.';
}

$bodyAttributes = ' class="has-sidebar-toggle"';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> Receive Material</title>
  <link rel="stylesheet" href="css/dashboard.css" />
</head>
<body<?= $bodyAttributes ?>>
  <div class="layout">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <?php
    $topbarTitle = 'Receive Material';
    require __DIR__ . '/../app/views/partials/topbar.php';
    unset($topbarTitle, $topbarSubhead, $topbarExtras);
    ?>

    <main class="content">
      <section class="panel" aria-labelledby="receive-material-title">
        <header class="panel-header">
          <div>
            <h1 id="receive-material-title">Receive Material</h1>
            <p class="small">Log supplier deliveries and note backordered quantities to keep inventory in sync.</p>
          </div>
        </header>

        <?php if ($dbError !== null): ?>
          <div class="alert error" role="alert">
            <strong>Database connection issue:</strong> <?= e($dbError) ?>
          </div>
        <?php endif; ?>

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

        <div class="receiving-grid" data-receiving>
          <aside class="receiving-sidebar" aria-label="Open purchase orders">
            <h2>Open purchase orders</h2>
            <div class="receiving-order-list" data-receiving-orders>
              <?php if ($openOrders === []): ?>
                <p class="muted">No purchase orders are awaiting receipt.</p>
              <?php else: ?>
                <?php foreach ($openOrders as $order): ?>
                  <?php
                  $orderId = (int) $order['id'];
                  $isActive = $selectedPurchaseOrder !== null && $selectedPurchaseOrder['id'] === $orderId;
                  $label = $order['order_number'] !== null
                      ? $order['order_number']
                      : 'PO #' . $orderId;
                  $supplierName = trim((string) ($order['supplier_name'] ?? ''));
                  if ($supplierName === '') {
                      $supplierName = 'Unassigned supplier';
                  }
                  ?>
                  <a
                    class="receiving-order <?= $isActive ? 'active' : '' ?>"
                    href="/receive-material.php?po_id=<?= $orderId ?>"
                    data-order
                    data-order-title="<?= e($label) ?>"
                    data-order-supplier="<?= e($supplierName) ?>"
                  >
                    <span class="order-title"><?= e($label) ?></span>
                    <span class="order-meta">
                      <?= e($supplierName) ?> ·
                      <?= e(inventoryFormatQuantity($order['outstanding_quantity'])) ?> open units
                    </span>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </aside>

          <div class="receiving-main">
            <?php if ($selectedPurchaseOrder === null): ?>
              <div class="card">
                <div class="card-body">
                  <p class="muted">Select a purchase order to begin receiving material.</p>
                </div>
              </div>
            <?php else: ?>
              <article class="card" aria-labelledby="po-header">
                <header class="card-header" id="po-header">
                  <div>
                    <h2>
                      <?php if ($selectedPurchaseOrder['order_number'] !== null): ?>
                        <?= e($selectedPurchaseOrder['order_number']) ?>
                      <?php else: ?>
                        PO #<?= e((string) $selectedPurchaseOrder['id']) ?>
                      <?php endif; ?>
                    </h2>
                    <p class="small">
                      Status: <?= e(ucwords(str_replace('_', ' ', $selectedPurchaseOrder['status']))) ?>
                      <?php if ($selectedPurchaseOrder['supplier'] !== null): ?>
                        · Supplier: <?= e($selectedPurchaseOrder['supplier']['name']) ?>
                      <?php endif; ?>
                    </p>
                  </div>
                </header>

                <form method="post" class="receiving-form" novalidate data-receiving-form>
                  <input type="hidden" name="purchase_order_id" value="<?= e((string) $selectedPurchaseOrder['id']) ?>" />

                  <div class="form-grid">
                    <div>
                      <label for="reference">Receipt reference</label>
                      <input
                        type="text"
                        id="reference"
                        name="reference"
                        value="<?= e($formValues['reference']) ?>"
                        required
                      />
                    </div>
                    <div>
                      <label for="notes">Notes</label>
                      <textarea id="notes" name="notes" rows="2" placeholder="Optional notes for this receipt"><?= e($formValues['notes']) ?></textarea>
                    </div>
                  </div>

                  <div class="table-responsive">
                    <table class="data-table table" data-receiving-lines data-sortable-table>
                      <thead>
                        <tr>
                          <th scope="col" class="select-col">
                            <label class="sr-only" for="select-all-lines">Select all lines</label>
                            <input type="checkbox" id="select-all-lines" data-table-select-all checked />
                          </th>
                          <th scope="col" class="sortable" data-sort-key="sku" aria-sort="none">SKU</th>
                          <th scope="col" class="sortable" data-sort-key="description" aria-sort="none">Description</th>
                          <th scope="col" class="sortable" data-sort-key="ordered" data-sort-type="number" aria-sort="none">Ordered</th>
                          <th scope="col" class="sortable" data-sort-key="received" data-sort-type="number" aria-sort="none">Received</th>
                          <th scope="col" class="sortable" data-sort-key="outstanding" data-sort-type="number" aria-sort="none">Outstanding</th>
                          <th scope="col" class="sortable" data-sort-key="receive_now" data-sort-type="number" aria-sort="none">Receive now</th>
                          <th scope="col">Backordered</th>
                        </tr>
                        <tr class="filter-row">
                          <th aria-hidden="true"></th>
                          <th><input type="search" class="column-filter" data-key="sku" placeholder="Search SKU" aria-label="Filter by SKU"></th>
                          <th><input type="search" class="column-filter" data-key="description" placeholder="Search description" aria-label="Filter by description"></th>
                          <th><input type="search" class="column-filter" data-key="ordered" placeholder="Search ordered" aria-label="Filter by ordered" inputmode="decimal"></th>
                          <th><input type="search" class="column-filter" data-key="received" placeholder="Search received" aria-label="Filter by received" inputmode="decimal"></th>
                          <th><input type="search" class="column-filter" data-key="outstanding" placeholder="Search outstanding" aria-label="Filter by outstanding" inputmode="decimal"></th>
                          <th aria-hidden="true"></th>
                          <th aria-hidden="true"></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php
                        $hasOutstanding = false;
                        foreach ($selectedPurchaseOrder['lines'] as $line):
                            $lineId = $line['id'];
                            $outstanding = $line['outstanding_quantity'];
                            if ($outstanding > 0.00001) {
                                $hasOutstanding = true;
                            }
                            $key = (string) $lineId;
                            $values = $formValues['lines'][$key];
                            $lineError = $lineErrors[$lineId] ?? [];
                            $outstandingAttribute = number_format($outstanding, 3, '.', '');
                            $orderedLabel = purchaseOrderFormatLineQuantity($line);
                            $receivedLabel = purchaseOrderFormatQuantityForLine($line, (float) $line['quantity_received']);
                            $outstandingLabel = purchaseOrderFormatQuantityForLine($line, (float) $outstanding);
                            $backorderRaw = $values['backorder'] ?? '';
                            $backorderValue = $backorderRaw !== '' ? (float) $backorderRaw : 0.0;
                            $receiveNow = max($outstanding - $backorderValue, 0.0);
                            $receiveNowLabel = purchaseOrderFormatQuantityForLine($line, $receiveNow);
                        ?>
                          <tr
                            data-line-id="<?= $lineId ?>"
                            data-row
                            data-outstanding-value="<?= e($outstandingAttribute) ?>"
                            data-has-outstanding="<?= $outstanding > 0.00001 ? '1' : '0' ?>"
                            data-sku="<?= e($line['sku'] ?? $line['supplier_sku'] ?? '—') ?>"
                            data-description="<?= e($line['description'] ?? ($line['item'] ?? '')) ?>"
                            data-ordered="<?= e((string) $line['quantity_ordered']) ?>"
                            data-received="<?= e((string) $line['quantity_received']) ?>"
                            data-outstanding="<?= e((string) $outstanding) ?>"
                            data-receive-now="<?= e((string) $receiveNow) ?>"
                          >
                            <td class="select-col">
                              <label class="sr-only" for="select-line-<?= $lineId ?>">Select line <?= $lineId ?></label>
                              <input type="checkbox" id="select-line-<?= $lineId ?>" class="line-checkbox" data-row-checkbox data-line-checkbox="<?= $lineId ?>" checked />
                            </td>
                            <th scope="row">
                              <?php if ($line['sku'] !== null): ?>
                                <span class="sku"><?= e($line['sku']) ?></span>
                              <?php else: ?>
                                <span class="muted">—</span>
                              <?php endif; ?>
                            </th>
                            <td>
                              <?= e($line['description'] ?? ($line['item'] ?? '')) ?>
                            </td>
                            <td><?= e($orderedLabel) ?></td>
                            <td><?= e($receivedLabel) ?></td>
                            <td>
                              <span data-outstanding><?= e($outstandingLabel) ?></span>
                            </td>
                            <td class="input-cell">
                              <span data-receive-now><?= e($receiveNowLabel) ?></span>
                            </td>
                            <td class="input-cell">
                              <label class="sr-only" for="backorder-<?= $lineId ?>">Backordered quantity</label>
                              <input
                                type="number"
                                step="0.001"
                                min="0"
                                max="<?= e($outstandingAttribute) ?>"
                                name="lines[<?= $lineId ?>][backorder]"
                                id="backorder-<?= $lineId ?>"
                                value="<?= e($values['backorder']) ?>"
                                data-backorder
                                data-base-disabled="<?= $outstanding <= 0.00001 ? '1' : '0' ?>"
                                <?= $outstanding <= 0.00001 ? 'disabled' : '' ?>
                              />
                              <?php if (isset($lineError['backorder'])): ?>
                                <p class="field-error"><?= e($lineError['backorder']) ?></p>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>

                  <?php if (!$hasOutstanding): ?>
                    <p class="muted">All lines on this purchase order have been received or fully accounted for.</p>
                  <?php endif; ?>

                  <div class="form-actions">
                    <button class="button primary" type="submit" <?= $hasOutstanding ? '' : 'disabled' ?>>Record receipt</button>
                    <?php
                      $purchaseOrderLink = '/purchase-orders.php';
                      if ($selectedPurchaseOrder !== null) {
                          $purchaseOrderLink .= '?po_id=' . urlencode((string) $selectedPurchaseOrder['id']);
                      }
                    ?>
                    <a class="button secondary" href="<?= e($purchaseOrderLink) ?>">View purchase orders</a>
                  </div>
                </form>
              </article>

              <section class="card" aria-labelledby="receipt-history-title">
                <header class="card-header">
                  <h2 id="receipt-history-title">Receipt history</h2>
                </header>
                <div class="card-body">
                  <?php if ($receiptHistory === []): ?>
                    <p class="muted">No receipt transactions have been recorded for this purchase order.</p>
                  <?php else: ?>
                    <ul class="receipt-history">
                      <?php foreach ($receiptHistory as $receipt): ?>
                        <li>
                          <div class="receipt-header">
                            <strong><?= e($receipt['reference']) ?></strong>
                            <span><?= e(date('M j, Y g:i A', strtotime($receipt['created_at']))) ?></span>
                          </div>
                          <?php if ($receipt['notes'] !== null && $receipt['notes'] !== ''): ?>
                            <p class="small muted">Notes: <?= e($receipt['notes']) ?></p>
                          <?php endif; ?>
                          <table>
                            <thead>
                              <tr>
                                <th scope="col">Line</th>
                                <th scope="col">Received</th>
                                <th scope="col">Cancelled</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($receipt['lines'] as $line): ?>
                                <tr>
                                  <th scope="row">
                                    <?= e($line['description'] ?? ($line['item'] ?? 'Line #' . $line['purchase_order_line_id'])) ?>
                                  </th>
                                  <td><?= e(purchaseOrderFormatQuantityForLine($line, (float) $line['quantity_received'])) ?></td>
                                  <td><?= e(purchaseOrderFormatQuantityForLine($line, (float) $line['quantity_cancelled'])) ?></td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
              </section>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="js/dashboard.js"></script>
  <script src="js/sortable-table.js" defer></script>
  <script src="js/receive-material.js" defer></script>
</body>
</html>
