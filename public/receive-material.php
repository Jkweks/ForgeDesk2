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
                    'receive' => isset($values['receive']) ? trim((string) $values['receive']) : '',
                    'cancel' => isset($values['cancel']) ? trim((string) $values['cancel']) : '',
                ];
            }
        }

        $changes = [];
        $hasChange = false;

        foreach ($selectedPurchaseOrder['lines'] as $line) {
            $lineId = $line['id'];
            $key = (string) $lineId;
            $values = $formValues['lines'][$key] ?? ['receive' => '', 'cancel' => ''];
            $receiveRaw = $values['receive'];
            $cancelRaw = $values['cancel'];
            $errors = [];

            $receive = $receiveRaw !== '' ? filter_var($receiveRaw, FILTER_VALIDATE_FLOAT) : 0.0;
            $cancel = $cancelRaw !== '' ? filter_var($cancelRaw, FILTER_VALIDATE_FLOAT) : 0.0;

            if ($receive === false || $receive < 0) {
                $errors['receive'] = 'Enter a non-negative quantity to receive.';
            }
            if ($cancel === false || $cancel < 0) {
                $errors['cancel'] = 'Enter a non-negative quantity to cancel.';
            }

            if ($errors !== []) {
                $lineErrors[$lineId] = $errors;
                continue;
            }

            $receive = $receive !== false ? (float) $receive : 0.0;
            $cancel = $cancel !== false ? (float) $cancel : 0.0;

            if ($receive <= 0 && $cancel <= 0) {
                continue;
            }

            $hasChange = true;
            $changes[$lineId] = ['receive' => $receive, 'cancel' => $cancel];
        }

        if (!$hasChange) {
            $generalErrors[] = 'Enter a quantity to receive or cancel before submitting.';
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
                    $generalErrors[] = 'No receipt or cancellation quantities were processed.';
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
                $formValues['lines'][$key] = ['receive' => '', 'cancel' => ''];
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
        <input type="search" name="q" placeholder="Search POs or suppliers" data-receiving-search />
      </form>
      <button class="user" type="button">
        <span class="user-avatar" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
        <span class="user-email"><?= e($app['user']['email']) ?></span>
        <span aria-hidden="true"><?= icon('chev') ?></span>
      </button>
    </header>

    <main class="content">
      <section class="panel" aria-labelledby="receive-material-title">
        <header class="panel-header">
          <div>
            <h1 id="receive-material-title">Receive Material</h1>
            <p class="small">Match supplier deliveries to purchase orders, update stock, and keep audit trails synced.</p>
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
                  ?>
                  <a
                    class="receiving-order <?= $isActive ? 'active' : '' ?>"
                    href="/receive-material.php?po_id=<?= $orderId ?>"
                    data-order
                    data-order-title="<?= e($label) ?>"
                    data-order-supplier="<?= e($order['supplier_name'] ?? 'Unknown supplier') ?>"
                  >
                    <span class="order-title"><?= e($label) ?></span>
                    <span class="order-meta">
                      <?= e($order['supplier_name'] ?? 'Unknown supplier') ?> ·
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
                    <table class="data-table" data-receiving-lines>
                      <thead>
                        <tr>
                          <th scope="col">SKU</th>
                          <th scope="col">Description</th>
                          <th scope="col">Ordered</th>
                          <th scope="col">Received</th>
                          <th scope="col">Cancelled</th>
                          <th scope="col">Outstanding</th>
                          <th scope="col">Receive now</th>
                          <th scope="col">Cancel</th>
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
                        ?>
                          <tr data-line-id="<?= $lineId ?>" data-outstanding-value="<?= e($outstandingAttribute) ?>">
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
                            <td><?= e(inventoryFormatQuantity($line['quantity_ordered'])) ?></td>
                            <td><?= e(inventoryFormatQuantity($line['quantity_received'])) ?></td>
                            <td><?= e(inventoryFormatQuantity($line['quantity_cancelled'])) ?></td>
                            <td>
                              <span data-outstanding><?= e(inventoryFormatQuantity($outstanding)) ?></span>
                            </td>
                            <td class="input-cell">
                              <label class="sr-only" for="receive-<?= $lineId ?>">Receive quantity</label>
                              <input
                                type="number"
                                step="0.001"
                                min="0"
                                max="<?= e($outstandingAttribute) ?>"
                                name="lines[<?= $lineId ?>][receive]"
                                id="receive-<?= $lineId ?>"
                                value="<?= e($values['receive']) ?>"
                                data-receive
                                <?= $outstanding <= 0.00001 ? 'disabled' : '' ?>
                              />
                              <?php if (isset($lineError['receive'])): ?>
                                <p class="field-error"><?= e($lineError['receive']) ?></p>
                              <?php endif; ?>
                            </td>
                            <td class="input-cell">
                              <label class="sr-only" for="cancel-<?= $lineId ?>">Cancel quantity</label>
                              <input
                                type="number"
                                step="0.001"
                                min="0"
                                max="<?= e($outstandingAttribute) ?>"
                                name="lines[<?= $lineId ?>][cancel]"
                                id="cancel-<?= $lineId ?>"
                                value="<?= e($values['cancel']) ?>"
                                data-cancel
                                <?= $outstanding <= 0.00001 ? 'disabled' : '' ?>
                              />
                              <?php if (isset($lineError['cancel'])): ?>
                                <p class="field-error"><?= e($lineError['cancel']) ?></p>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>

                  <?php if (!$hasOutstanding): ?>
                    <p class="muted">All lines on this purchase order have been received or cancelled.</p>
                  <?php endif; ?>

                  <div class="form-actions">
                    <button class="button primary" type="submit" <?= $hasOutstanding ? '' : 'disabled' ?>>Record receipt</button>
                    <a class="button secondary" href="/purchase-orders.php">View purchase orders</a>
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
                                  <td><?= e(inventoryFormatQuantity($line['quantity_received'])) ?></td>
                                  <td><?= e(inventoryFormatQuantity($line['quantity_cancelled'])) ?></td>
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

  <script src="js/receive-material.js" defer></script>
</body>
</html>
