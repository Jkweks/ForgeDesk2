<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/inventory.php';
require_once __DIR__ . '/../app/data/purchase_orders.php';
require_once __DIR__ . '/../app/services/purchase_order_documents.php';

/**
 * @param list<array<string,mixed>> $items
 * @return array<string,array{
 *   key:string,
 *   supplier_id:?int,
 *   name:string,
 *   contact_name:?string,
 *   contact_email:?string,
 *   contact_phone:?string,
 *   legacy_contact:?string,
 *   legacy_supplier:?string,
 *   items:list<array<string,mixed>>,
 *   is_tubelite:bool,
 *   recommended_total:float,
 *   stock_total:int,
 *   committed_total:int,
 *   on_order_total:float
 * }>
 */
function materialReplenishmentGroupItems(array $items): array
{
    $groups = [];

    foreach ($items as $item) {
        $supplierId = $item['supplier_id'] ?? null;
        $display = trim((string) ($item['supplier_display'] ?? ''));
        if ($display === '') {
            $display = trim((string) ($item['legacy_supplier'] ?? ''));
        }
        if ($display === '') {
            $display = 'Unassigned Supplier';
        }

        if ($supplierId !== null) {
            $key = 'supplier-' . $supplierId;
        } else {
            $slugSource = preg_replace('/[^a-z0-9]+/i', '-', strtolower($display));
            $slugSource = $slugSource !== null ? trim($slugSource, '-') : '';
            if ($slugSource === '') {
                $slugSource = 'unassigned';
            }
            $key = 'legacy-' . $slugSource;
        }

        if (!isset($groups[$key])) {
            $contactName = $item['supplier_contact_name'] ?? null;
            $contactEmail = $item['supplier_contact_email'] ?? null;
            $contactPhone = $item['supplier_contact_phone'] ?? null;

            if ($contactName === null && isset($item['legacy_supplier_contact'])) {
                $contactName = (string) $item['legacy_supplier_contact'];
            }

            $groups[$key] = [
                'key' => $key,
                'supplier_id' => $supplierId !== null ? (int) $supplierId : null,
                'name' => $display,
                'contact_name' => $contactName !== null ? (string) $contactName : null,
                'contact_email' => $contactEmail !== null ? (string) $contactEmail : null,
                'contact_phone' => $contactPhone !== null ? (string) $contactPhone : null,
                'legacy_contact' => $item['legacy_supplier_contact'] ?? null,
                'legacy_supplier' => $item['legacy_supplier'] ?? null,
                'items' => [],
                'is_tubelite' => stripos($display, 'tubelite') !== false,
                'recommended_total' => 0.0,
                'stock_total' => 0,
                'committed_total' => 0,
                'on_order_total' => 0.0,
            ];
        }

        $groups[$key]['items'][] = $item;
        $groups[$key]['recommended_total'] += (float) $item['recommended_order_qty'];
        $groups[$key]['stock_total'] += (int) $item['stock'];
        $groups[$key]['committed_total'] += (int) $item['committed_qty'];
        $groups[$key]['on_order_total'] += (float) $item['on_order_qty'];
    }

    foreach ($groups as &$group) {
        usort(
            $group['items'],
            static function (array $a, array $b): int {
                return strcasecmp((string) $a['item'], (string) $b['item']);
            }
        );
    }
    unset($group);

    uasort(
        $groups,
        static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        }
    );

    return $groups;
}

/**
 * @param list<array<string,mixed>> $items
 * @return array{item_count:int,needs_order:int,recommended_total:float,on_hand_total:int,committed_total:int,on_order_total:float}
 */
function materialReplenishmentTotals(array $items): array
{
    $totals = [
        'item_count' => count($items),
        'needs_order' => 0,
        'recommended_total' => 0.0,
        'on_hand_total' => 0,
        'committed_total' => 0,
        'on_order_total' => 0.0,
    ];

    foreach ($items as $item) {
        $recommended = (float) $item['recommended_order_qty'];
        if ($recommended > 0.0001) {
            $totals['needs_order'] += 1;
        }

        $totals['recommended_total'] += $recommended;
        $totals['on_hand_total'] += (int) $item['stock'];
        $totals['committed_total'] += (int) $item['committed_qty'];
        $totals['on_order_total'] += (float) $item['on_order_qty'];
    }

    return $totals;
}

$databaseConfig = $app['database'];
$dbError = null;
$replenishment = [];
$supplierGroups = [];
$totals = [
    'item_count' => 0,
    'needs_order' => 0,
    'recommended_total' => 0.0,
    'on_hand_total' => 0,
    'committed_total' => 0,
    'on_order_total' => 0.0,
];
$pageErrors = [];
$formErrors = [];
$invalidLineIds = [];
$activeSupplierKey = null;
$postedSelected = [];
$postedQuantities = [];
$postedUnitCosts = [];
$postedUnits = [];
$postedNotes = '';
$postedOrderNumber = '';
$shouldReloadSnapshot = false;

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] ?? '') === 'Material Replenishment';
    }
}
unset($groupItems, $item);

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if (!isset($db)) {
    $db = null;
}

if ($dbError === null && $db instanceof \PDO) {
    try {
        $replenishment = inventoryLoadReplenishmentSnapshot($db);
        $supplierGroups = materialReplenishmentGroupItems($replenishment);
        $totals = materialReplenishmentTotals($replenishment);
        $activeSupplierKey = $supplierGroups !== [] ? (string) array_key_first($supplierGroups) : null;
    } catch (\Throwable $exception) {
        $pageErrors[] = 'Unable to load replenishment data: ' . $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbError === null && $db instanceof \PDO) {
    $activeSupplierKey = isset($_POST['supplier_key']) ? (string) $_POST['supplier_key'] : $activeSupplierKey;
    $postedNotes = isset($_POST['notes']) ? trim((string) $_POST['notes']) : '';
    $postedOrderNumber = isset($_POST['order_number']) ? trim((string) $_POST['order_number']) : '';
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    $selectedRaw = $_POST['selected'] ?? [];
    if (is_array($selectedRaw)) {
        $postedSelected = array_values(array_unique(array_map('intval', $selectedRaw)));
    } elseif ($selectedRaw !== null && $selectedRaw !== '') {
        $postedSelected = [(int) $selectedRaw];
    }

    $quantityRaw = $_POST['quantity'] ?? [];
    if (is_array($quantityRaw)) {
        foreach ($quantityRaw as $key => $value) {
            $itemId = is_string($key) ? (int) $key : (int) $key;
            $postedQuantities[$itemId] = is_string($value) ? trim($value) : (string) $value;
        }
    }

    $unitCostRaw = $_POST['unit_cost'] ?? [];
    if (is_array($unitCostRaw)) {
        foreach ($unitCostRaw as $key => $value) {
            $itemId = is_string($key) ? (int) $key : (int) $key;
            $postedUnitCosts[$itemId] = is_string($value) ? trim($value) : (string) $value;
        }
    }

    $unitSelections = $_POST['quantity_unit'] ?? [];
    if (is_array($unitSelections)) {
        foreach ($unitSelections as $key => $value) {
            $itemId = is_string($key) ? (int) $key : (int) $key;
            $unitValue = is_string($value) ? strtolower(trim($value)) : '';
            $postedUnits[$itemId] = $unitValue === 'pack' ? 'pack' : 'each';
        }
    }

    if (!isset($supplierGroups[$activeSupplierKey])) {
        $formErrors[] = 'The selected supplier view is no longer available.';
    } else {
        $group = $supplierGroups[$activeSupplierKey];
        $itemsById = [];
        foreach ($group['items'] as $item) {
            $itemsById[(int) $item['id']] = $item;
        }

        if ($action === '') {
            $formErrors[] = 'Choose an action to generate the replenishment output.';
        } elseif ($action === 'generate_tubelite' && !$group['is_tubelite']) {
            $formErrors[] = 'Tubelite workbooks are only available for Tubelite suppliers.';
        } elseif ($action !== 'generate_tubelite' && $action !== 'generate_pdf') {
            $formErrors[] = 'Unknown action requested.';
        }

        if ($postedSelected === []) {
            $formErrors[] = 'Select at least one inventory item to include in the order.';
        }

        $lineInputs = [];

        foreach ($postedSelected as $itemId) {
            if (!isset($itemsById[$itemId])) {
                $formErrors[] = 'One or more selected items are no longer available.';
                $invalidLineIds[$itemId] = true;
                continue;
            }

            $item = $itemsById[$itemId];
            $quantityString = $postedQuantities[$itemId] ?? '';
            $quantityNormalized = str_replace(',', '', (string) $quantityString);
            $quantityValue = $quantityNormalized !== '' ? filter_var($quantityNormalized, FILTER_VALIDATE_FLOAT) : false;

            if ($quantityValue === false || $quantityValue <= 0) {
                $formErrors[] = 'Enter a positive quantity for ' . $itemsById[$itemId]['item'] . '.';
                $invalidLineIds[$itemId] = true;
                continue;
            }

            $unitCostString = $postedUnitCosts[$itemId] ?? '';
            $unitCostNormalized = str_replace(',', '', (string) $unitCostString);
            $unitCostValue = $unitCostNormalized !== '' ? filter_var($unitCostNormalized, FILTER_VALIDATE_FLOAT) : 0.0;

            if ($unitCostValue === false || $unitCostValue < 0) {
                $formErrors[] = 'Unit cost must be zero or greater for ' . $itemsById[$itemId]['item'] . '.';
                $invalidLineIds[$itemId] = true;
                continue;
            }

            $packSize = isset($item['pack_size']) ? max(0.0, (float) $item['pack_size']) : 0.0;
            $unitChoice = $postedUnits[$itemId] ?? ($packSize > 1.0 ? 'pack' : 'each');
            if ($unitChoice === 'pack' && $packSize <= 0.0) {
                $unitChoice = 'each';
            }

            $purchaseUom = $item['purchase_uom'] ?? ($packSize > 1.0 ? 'pack' : null);
            $purchaseUom = $purchaseUom !== null && $purchaseUom !== '' ? $purchaseUom : null;
            $stockUom = $item['stock_uom'] ?? 'ea';
            if ($stockUom === '') {
                $stockUom = 'ea';
            }

            $quantityEach = inventoryQuantityToEach((float) $quantityValue, $packSize, $unitChoice);
            $packsOrdered = $packSize > 0.0 ? ($quantityEach / $packSize) : 0.0;

            $lineInputs[] = [
                'item' => $item,
                'quantity' => (float) $quantityValue,
                'quantity_each' => $quantityEach,
                'quantity_unit' => $unitChoice,
                'packs_ordered' => $packsOrdered,
                'pack_size' => $packSize,
                'purchase_uom' => $purchaseUom,
                'stock_uom' => $stockUom,
                'unit_cost' => (float) $unitCostValue,
            ];
        }

        if ($formErrors === [] && $lineInputs !== []) {
            $notes = $postedNotes !== ''
                ? $postedNotes
                : sprintf('Material replenishment order generated on %s', date('Y-m-d H:i'));

            $expectedDate = null;
            $orderLines = [];
            $today = new DateTimeImmutable('today');

            foreach ($lineInputs as $line) {
                $item = $line['item'];
                $leadDays = isset($item['effective_lead_time_days']) ? max(0, (int) $item['effective_lead_time_days']) : 0;
                $lineExpected = null;
                if ($leadDays > 0) {
                    $lineExpected = $today->modify(sprintf('+%d days', $leadDays))->format('Y-m-d');
                    if ($expectedDate === null || $lineExpected > $expectedDate) {
                        $expectedDate = $lineExpected;
                    }
                }

                $description = (string) ($item['item'] ?? '');
                if ($description === '' && isset($item['sku'])) {
                    $description = (string) $item['sku'];
                }

                $orderLines[] = [
                    'inventory_item_id' => (int) $item['id'],
                    'supplier_sku' => $item['supplier_sku'] ?? null,
                    'description' => $description,
                    'quantity_ordered' => $line['quantity_each'],
                    'packs_ordered' => $line['packs_ordered'],
                    'pack_size' => $line['pack_size'],
                    'purchase_uom' => $line['purchase_uom'],
                    'stock_uom' => $line['stock_uom'],
                    'unit_cost' => $line['unit_cost'],
                    'expected_date' => $lineExpected,
                ];
            }

            try {
                $orderId = createPurchaseOrder($db, [
                    'order_number' => $postedOrderNumber !== '' ? $postedOrderNumber : null,
                    'supplier_id' => $group['supplier_id'],
                    'status' => 'draft',
                    'order_date' => date('Y-m-d'),
                    'expected_date' => $expectedDate,
                    'notes' => $notes,
                    'lines' => $orderLines,
                ]);
                $shouldReloadSnapshot = true;
                $purchaseOrder = loadPurchaseOrder($db, $orderId);
            } catch (\Throwable $exception) {
                $formErrors[] = 'Unable to create the purchase order: ' . $exception->getMessage();
                $purchaseOrder = null;
            }

            if ($formErrors === [] && isset($purchaseOrder) && $purchaseOrder !== null) {
                $orderNumber = $purchaseOrder['order_number'] ?? sprintf('purchase-order-%d', $purchaseOrder['id']);
                $filenameBase = preg_replace('/[^A-Za-z0-9._-]/', '-', $orderNumber);
                if ($filenameBase === '' || $filenameBase === null) {
                    $filenameBase = 'purchase-order';
                }

                if ($action === 'generate_tubelite') {
                    $templatePath = __DIR__ . '/../app/helpers/EZ_Estimate.xlsm';
                    $tempBase = tempnam(sys_get_temp_dir(), 'fd_tubelite_');
                    if ($tempBase === false) {
                        $formErrors[] = 'Unable to create a temporary file for the workbook.';
                    } else {
                        $workbookPath = $tempBase . '.xlsm';
                        if (!rename($tempBase, $workbookPath)) {
                            unlink($tempBase);
                            $formErrors[] = 'Unable to prepare the workbook for download.';
                        } else {
                            try {
                                generateTubeliteEzEstimateOrder($db, (int) $purchaseOrder['id'], $templatePath, $workbookPath);
                                header('Content-Type: application/vnd.ms-excel.sheet.macroEnabled.12');
                                header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsm"');
                                header('Content-Length: ' . (string) filesize($workbookPath));
                                header('Cache-Control: no-store, no-cache, must-revalidate');
                                header('Pragma: no-cache');
                                readfile($workbookPath);
                                unlink($workbookPath);
                                exit;
                            } catch (\Throwable $exception) {
                                $formErrors[] = 'Unable to build the Tubelite EZ Estimate workbook: ' . $exception->getMessage();
                                unlink($workbookPath);
                            }
                        }
                    }
                } elseif ($action === 'generate_pdf') {
                    try {
                        $lines = purchaseOrderBuildPdfLines($purchaseOrder);
                        $pdfContent = purchaseOrderGenerateSimplePdf($lines);
                        header('Content-Type: application/pdf');
                        header('Content-Disposition: attachment; filename="' . $filenameBase . '.pdf"');
                        header('Content-Length: ' . strlen($pdfContent));
                        header('Cache-Control: no-store, no-cache, must-revalidate');
                        header('Pragma: no-cache');
                        echo $pdfContent;
                        exit;
                    } catch (\Throwable $exception) {
                        $formErrors[] = 'Unable to generate the purchase order PDF: ' . $exception->getMessage();
                    }
                }
            }
        }
    }

    if ($shouldReloadSnapshot) {
        try {
            $replenishment = inventoryLoadReplenishmentSnapshot($db);
            $supplierGroups = materialReplenishmentGroupItems($replenishment);
            $totals = materialReplenishmentTotals($replenishment);
        } catch (\Throwable $exception) {
            $pageErrors[] = 'Unable to refresh replenishment data: ' . $exception->getMessage();
        }
        $postedSelected = [];
        $postedQuantities = [];
        $postedUnitCosts = [];
        $postedUnits = [];
        $postedNotes = '';
        $postedOrderNumber = '';
        $invalidLineIds = [];
    }
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

$supplierGroups = $supplierGroups ?? [];
if ($supplierGroups !== [] && ($activeSupplierKey === null || !isset($supplierGroups[$activeSupplierKey]))) {
    $activeSupplierKey = (string) array_key_first($supplierGroups);
}

function materialReplenishmentFormatDecimal(float $value, int $precision = 2): string
{
    return number_format($value, $precision, '.', ',');
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> · Material Replenishment</title>
  <link rel="stylesheet" href="css/dashboard.css" />
</head>
<body class="has-sidebar-toggle">
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
      <header class="content-header">
        <div>
          <h1>Material Replenishment</h1>
          <p class="small">Track projected availability, recommended order quantities, and generate purchase orders by supplier.</p>
        </div>
      </header>

      <section class="process-callout" role="region" aria-labelledby="replenishment-process-heading">
        <h2 id="replenishment-process-heading">How purchase orders are created from this page</h2>
        <p>This workspace produces a draft purchase order for the supplier whenever you generate a PDF or Tubelite workbook.</p>
        <ol>
          <li><strong>Select line items</strong> for a supplier tab and confirm the order quantities and unit costs you want to place.</li>
          <li><strong>Submit using one of the Generate buttons</strong>. ForgeDesk saves the selected lines on a draft purchase order and then streams the requested document.</li>
          <li><strong>Review the new draft</strong> from the Receive Material page to monitor confirmations, receipts, and close out the order when stock arrives.</li>
        </ol>
      </section>

      <?php if ($dbError !== null): ?>
        <div class="alert error" role="alert">
          <strong>Database connection issue:</strong> <?= e($dbError) ?>
        </div>
      <?php endif; ?>

      <?php foreach ($pageErrors as $error): ?>
        <div class="alert error" role="alert">
          <?= e($error) ?>
        </div>
      <?php endforeach; ?>

      <section class="metrics" aria-label="Replenishment summary metrics">
        <article class="metric">
          <div class="metric-header">
            <span>Total SKUs</span>
          </div>
          <p class="metric-value"><?= e(inventoryFormatQuantity($totals['item_count'])) ?></p>
          <p class="metric-delta small">Inventory items evaluated for replenishment.</p>
        </article>
        <article class="metric">
          <div class="metric-header">
            <span>Needs Order</span>
          </div>
          <p class="metric-value"><?= e(inventoryFormatQuantity($totals['needs_order'])) ?></p>
          <p class="metric-delta small">Items below target stock based on demand and safety buffers.</p>
        </article>
        <article class="metric">
          <div class="metric-header">
            <span>On Hand</span>
          </div>
          <p class="metric-value"><?= e(inventoryFormatQuantity($totals['on_hand_total'])) ?></p>
          <p class="metric-delta small">Current physical stock across the evaluated items.</p>
        </article>
        <article class="metric">
          <div class="metric-header">
            <span>On Order</span>
          </div>
          <p class="metric-value"><?= e(materialReplenishmentFormatDecimal($totals['on_order_total'], 2)) ?></p>
          <p class="metric-delta small">Open purchase order balances still inbound.</p>
        </article>
        <article class="metric accent">
          <div class="metric-header">
            <span>Order Shortfall</span>
          </div>
          <p class="metric-value"><?= e(materialReplenishmentFormatDecimal($totals['recommended_total'], 2)) ?></p>
          <p class="metric-delta small">Sum of reorder point gaps currently prefilled for ordering.</p>
        </article>
      </section>

      <?php if ($supplierGroups === []): ?>
        <p class="panel">No replenishment data is currently available. Add inventory items and supplier assignments to populate this report.</p>
      <?php else: ?>
        <div class="panel" aria-live="polite">
          <div class="report-tabs" data-replenishment-tabs>
            <div class="report-tabs__list" role="tablist" aria-label="Suppliers">
              <?php $tabIndex = 0; ?>
              <?php foreach ($supplierGroups as $key => $group): ?>
                <?php
                  $isActive = $activeSupplierKey === $key;
                  $tabId = 'supplier-tab-' . $tabIndex;
                  $panelId = 'supplier-panel-' . $tabIndex;
                ?>
                <button
                  type="button"
                  role="tab"
                  id="<?= e($tabId) ?>"
                  aria-controls="<?= e($panelId) ?>"
                  aria-selected="<?= $isActive ? 'true' : 'false' ?>"
                  tabindex="<?= $isActive ? '0' : '-1' ?>"
                  data-replenishment-tab="<?= e($key) ?>"
                  data-replenishment-target="<?= e($panelId) ?>"
                >
                  <?= e($group['name']) ?>
                  <span class="report-tabs__count"><?= e(inventoryFormatQuantity(count($group['items']))) ?></span>
                </button>
                <?php $tabIndex++; ?>
              <?php endforeach; ?>
            </div>
            <div class="report-tabs__panels">
              <?php $tabIndex = 0; ?>
              <?php foreach ($supplierGroups as $key => $group): ?>
                <?php
                  $panelId = 'supplier-panel-' . $tabIndex;
                  $tabId = 'supplier-tab-' . $tabIndex;
                  $isActive = $activeSupplierKey === $key;
                  $selected = $isActive ? $postedSelected : [];
                  $quantities = $isActive ? $postedQuantities : [];
                  $unitCosts = $isActive ? $postedUnitCosts : [];
                  $units = $isActive ? $postedUnits : [];
                  $notesValue = $isActive && $postedNotes !== '' ? $postedNotes : '';
                  $orderNumberValue = $isActive ? $postedOrderNumber : '';
                ?>
                <section
                  id="<?= e($panelId) ?>"
                  class="report-tabs__panel"
                  role="tabpanel"
                  aria-labelledby="<?= e($tabId) ?>"
                  data-replenishment-panel="<?= e($key) ?>"
                  <?= $isActive ? '' : ' hidden' ?>
                >
                  <form class="panel js-replenishment-form" method="post">
                    <header>
                      <h2><?= e($group['name']) ?></h2>
                      <p class="small">
                        <?php if (!empty($group['contact_name'])): ?>
                          <strong>Contact:</strong> <?= e($group['contact_name']) ?>
                        <?php elseif (!empty($group['legacy_contact'])): ?>
                          <strong>Contact:</strong> <?= e($group['legacy_contact']) ?>
                        <?php endif; ?>
                        <?php if (!empty($group['contact_email'])): ?>
                          · <a href="mailto:<?= e($group['contact_email']) ?>"><?= e($group['contact_email']) ?></a>
                        <?php endif; ?>
                        <?php if (!empty($group['contact_phone'])): ?>
                          · <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $group['contact_phone'])) ?>"><?= e($group['contact_phone']) ?></a>
                        <?php endif; ?>
                      </p>
                      <p class="small">Projected availability already reflects on-order balances and active reservations.</p>
                    </header>

                    <?php if ($isActive && $formErrors !== []): ?>
                      <div class="alert error" role="alert">
                        <ul>
                          <?php foreach ($formErrors as $error): ?>
                            <li><?= e($error) ?></li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                    <?php endif; ?>

                    <input type="hidden" name="supplier_key" value="<?= e($key) ?>" />

                    <div class="replenishment-toolbar">
                      <label class="sr-only" for="filter-<?= e($key) ?>">Filter items</label>
                      <input
                        type="search"
                        id="filter-<?= e($key) ?>"
                        class="replenishment-filter"
                        placeholder="Search items or SKUs"
                        data-replenishment-filter
                      />
                    </div>

                    <div class="table-wrapper">
                      <table class="table replenishment-table" data-replenishment-table>
                        <thead>
                          <tr>
                            <th scope="col">
                              <label class="sr-only" for="select-all-<?= e($key) ?>">Select all lines</label>
                              <input type="checkbox" id="select-all-<?= e($key) ?>" data-select-all />
                            </th>
                            <th scope="col" class="sortable" data-sort-key="item" aria-sort="none">Item</th>
                            <th scope="col" class="sortable" data-sort-key="sku" aria-sort="none">SKU</th>
                            <th scope="col" class="sortable" data-sort-key="status" aria-sort="none">Status</th>
                            <th scope="col" class="sortable numeric" data-sort-key="on-hand" data-sort-type="number" aria-sort="none">On Hand</th>
                            <th scope="col" class="sortable numeric" data-sort-key="committed" data-sort-type="number" aria-sort="none">Committed</th>
                            <th scope="col" class="sortable numeric" data-sort-key="on-order" data-sort-type="number" aria-sort="none">On Order</th>
                            <th scope="col" class="sortable numeric" data-sort-key="available" data-sort-type="number" aria-sort="none">Available</th>
                            <th scope="col" class="sortable numeric" data-sort-key="projected" data-sort-type="number" aria-sort="none">Projected</th>
                            <th scope="col" class="sortable numeric" data-sort-key="adu" data-sort-type="number" aria-sort="none">ADU</th>
                            <th scope="col" class="sortable numeric" data-sort-key="days-of-supply" data-sort-type="number" aria-sort="none">Days of Supply</th>
                            <th scope="col" class="sortable numeric" data-sort-key="order-qty" data-sort-type="number" aria-sort="none">Order Qty</th>
                            <th scope="col" class="sortable numeric" data-sort-key="unit-cost" data-sort-type="number" aria-sort="none">Unit Cost</th>
                            <th scope="col" class="sortable" data-sort-key="uom" aria-sort="none">UOM</th>
                          </tr>
                          <tr class="filter-row">
                            <th></th>
                            <th><input type="search" class="column-filter" data-key="item" placeholder="Search items" aria-label="Filter by item"></th>
                            <th><input type="search" class="column-filter" data-key="sku" placeholder="Search SKU" aria-label="Filter by SKU"></th>
                            <th><input type="search" class="column-filter" data-key="status" placeholder="Filter status" aria-label="Filter by status"></th>
                            <th><input type="search" class="column-filter" data-key="on-hand" placeholder="Search on hand" aria-label="Filter by on hand" inputmode="decimal"></th>
                            <th><input type="search" class="column-filter" data-key="committed" placeholder="Search committed" aria-label="Filter by committed" inputmode="decimal"></th>
                            <th><input type="search" class="column-filter" data-key="on-order" placeholder="Search on order" aria-label="Filter by on order" inputmode="decimal"></th>
                            <th><input type="search" class="column-filter" data-key="available" placeholder="Search available" aria-label="Filter by available" inputmode="decimal"></th>
                            <th><input type="search" class="column-filter" data-key="projected" placeholder="Search projected" aria-label="Filter by projected" inputmode="decimal"></th>
                            <th><input type="search" class="column-filter" data-key="adu" placeholder="Search ADU" aria-label="Filter by average daily use" inputmode="decimal"></th>
                            <th><input type="search" class="column-filter" data-key="days-of-supply" placeholder="Search days" aria-label="Filter by days of supply" inputmode="decimal"></th>
                            <th><input type="search" class="column-filter" data-key="order-qty" placeholder="Search qty" aria-label="Filter by order quantity" inputmode="decimal"></th>
                            <th><input type="search" class="column-filter" data-key="unit-cost" placeholder="Search cost" aria-label="Filter by unit cost" inputmode="decimal"></th>
                            <th><input type="search" class="column-filter" data-key="uom" placeholder="Search UOM" aria-label="Filter by unit of measure"></th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($group['items'] as $item): ?>
                            <?php
                              $itemId = (int) $item['id'];
                              $recommended = (float) $item['recommended_order_qty'];
                              $isSelected = $selected !== [] ? in_array($itemId, $selected, true) : false;
                              $packSize = isset($item['pack_size']) ? (float) $item['pack_size'] : 0.0;
                              $defaultUnitChoice = $packSize > 1.0 ? 'pack' : 'each';
                              $unitChoice = $units[$itemId] ?? $defaultUnitChoice;
                              $unitChoice = $unitChoice === 'pack' ? 'pack' : 'each';
                              if ($unitChoice === 'pack' && $packSize <= 0.0) {
                                  $unitChoice = 'each';
                              }
                              $displayValue = $quantities[$itemId] ?? '';
                              $recommendedDisplay = '';
                              if ($recommended > 0.0001) {
                                  $convertedRecommended = inventoryEachToUnit($recommended, $packSize, $unitChoice);
                                  if ($convertedRecommended > 0) {
                                      $recommendedDisplay = materialReplenishmentFormatDecimal($convertedRecommended, 3);
                                  }
                              }
                              if ($displayValue === '' && $recommendedDisplay !== '') {
                                  $displayValue = $recommendedDisplay;
                              }
                              $orderQuantity = $displayValue;
                              $orderQtyEach = 0.0;
                              if ($orderQuantity !== '') {
                                  $orderQtyEach = inventoryQuantityToEach((float) str_replace(',', '', $orderQuantity), $packSize, $unitChoice);
                              } elseif ($recommended > 0.0001) {
                                  $orderQtyEach = $recommended;
                              }
                              $recommendedEachValue = $recommended > 0.0001 ? number_format($recommended, 3, '.', '') : '';
                              $unitCost = $unitCosts[$itemId] ?? '';
                              $rowClasses = [];
                              if (isset($invalidLineIds[$itemId])) {
                                  $rowClasses[] = 'is-invalid';
                              }
                              $status = isset($item['status']) ? (string) $item['status'] : 'In Stock';
                              $statusClass = 'muted';
                              if (strcasecmp($status, 'Critical') === 0) {
                                  $statusClass = 'danger';
                              } elseif (strcasecmp($status, 'Low') === 0) {
                                  $statusClass = 'warning';
                              } elseif (strcasecmp($status, 'In Stock') === 0) {
                                  $statusClass = 'success';
                              }
                              $averageDailyUse = $item['average_daily_use'];
                              $daysOfSupply = $item['days_of_supply'];
                              $dataAttributes = [
                                  'item' => $item['item'],
                                  'sku' => $item['sku'],
                                  'status' => $status,
                                  'on-hand' => number_format((float) $item['stock'], 3, '.', ''),
                                  'committed' => number_format((float) $item['committed_qty'], 3, '.', ''),
                                  'on-order' => number_format((float) $item['on_order_qty'], 3, '.', ''),
                                  'available' => number_format((float) $item['available_now'], 3, '.', ''),
                                  'projected' => number_format((float) $item['projected_available'], 3, '.', ''),
                                  'adu' => $averageDailyUse !== null ? number_format((float) $averageDailyUse, 6, '.', '') : '',
                                  'days-of-supply' => $daysOfSupply !== null ? number_format((float) $daysOfSupply, 6, '.', '') : '',
                                  'order-qty' => $orderQtyEach > 0 ? number_format($orderQtyEach, 3, '.', '') : '',
                                  'pack-size' => number_format($packSize, 3, '.', ''),
                                  'unit-cost' => $unitCost !== '' ? $unitCost : '',
                                  'uom' => ($item['purchase_uom'] ?? ($packSize > 1 ? 'pack' : ($item['stock_uom'] ?? 'ea'))) . '|' . ($item['stock_uom'] ?? 'ea'),
                                  'order-unit' => $unitChoice,
                              ];
                              $attributeParts = [];
                              foreach ($dataAttributes as $attrKey => $attrValue) {
                                  $attributeParts[] = 'data-' . $attrKey . '="' . e((string) $attrValue) . '"';
                              }
                              $attributeString = $attributeParts !== [] ? ' ' . implode(' ', $attributeParts) : '';
                            ?>
                            <tr<?= $rowClasses !== [] ? ' class="' . e(implode(' ', $rowClasses)) . '"' : '' ?><?= $attributeString ?>>
                              <td data-title="Include">
                                <label class="sr-only" for="include-<?= e((string) $itemId) ?>">Include <?= e($item['item']) ?></label>
                                <input
                                  type="checkbox"
                                  id="include-<?= e((string) $itemId) ?>"
                                  name="selected[]"
                                  value="<?= e((string) $itemId) ?>"
                                  class="js-line-select"
                                  data-line-id="<?= e((string) $itemId) ?>"
                                  <?= $isSelected ? 'checked' : '' ?>
                                />
                              </td>
                              <td data-title="Item">
                                <strong><?= e($item['item']) ?></strong>
                                <?php if (!empty($item['supplier_sku'])): ?>
                                  <div class="small">Supplier SKU: <?= e($item['supplier_sku']) ?></div>
                                <?php endif; ?>
                              </td>
                              <td data-title="SKU"><?= e($item['sku'] !== '' ? $item['sku'] : '—') ?></td>
                              <td data-title="Status">
                                <span class="badge <?= e($statusClass) ?>"><?= e($status) ?></span>
                              </td>
                              <td data-title="On Hand" class="numeric"><?= e(inventoryFormatQuantity((int) $item['stock'])) ?></td>
                              <td data-title="Committed" class="numeric"><?= e(inventoryFormatQuantity((int) $item['committed_qty'])) ?></td>
                              <td data-title="On Order" class="numeric"><?= e(materialReplenishmentFormatDecimal((float) $item['on_order_qty'], 2)) ?></td>
                              <td data-title="Available" class="numeric"><?= e(inventoryFormatQuantity((int) $item['available_now'])) ?></td>
                              <td data-title="Projected" class="numeric"><?= e(materialReplenishmentFormatDecimal((float) $item['projected_available'], 2)) ?></td>
                              <td data-title="ADU" class="numeric"><?= $item['average_daily_use'] !== null ? e(materialReplenishmentFormatDecimal((float) $item['average_daily_use'], 3)) : '—' ?></td>
                              <td data-title="Days of Supply" class="numeric"><?= $item['days_of_supply'] !== null ? e(materialReplenishmentFormatDecimal((float) $item['days_of_supply'], 1)) : '—' ?></td>
                              <td data-title="Order Qty" class="numeric">
                                <div class="order-qty-control" data-quantity-control data-pack-size="<?= e((string) $packSize) ?>">
                                  <label class="sr-only" for="qty-<?= e((string) $itemId) ?>">Order quantity for <?= e($item['item']) ?></label>
                                  <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="qty-<?= e((string) $itemId) ?>"
                                    name="quantity[<?= e((string) $itemId) ?>]"
                                    value="<?= e($orderQuantity) ?>"
                                    class="js-quantity-input"
                                    data-line-id="<?= e((string) $itemId) ?>"
                                    data-recommended-each="<?= e($recommendedEachValue) ?>"
                                    data-order-input
                                    data-order-unit="<?= e($unitChoice) ?>"
                                    data-pack-size="<?= e((string) $packSize) ?>"
                                  />
                                  <?php if ($packSize > 1): ?>
                                    <select name="quantity_unit[<?= e((string) $itemId) ?>]" class="order-qty-unit" data-quantity-unit>
                                      <option value="pack"<?= $unitChoice === 'pack' ? ' selected' : '' ?>>Packs (<?= e(materialReplenishmentFormatDecimal($packSize, 0)) ?> <?= e($item['stock_uom'] ?? 'ea') ?>)</option>
                                      <option value="each"<?= $unitChoice === 'each' ? ' selected' : '' ?>>Each</option>
                                    </select>
                                  <?php else: ?>
                                    <input type="hidden" name="quantity_unit[<?= e((string) $itemId) ?>]" value="each" data-quantity-unit />
                                    <span class="order-qty-unit-label"><?= e($item['stock_uom'] ?? 'ea') ?></span>
                                  <?php endif; ?>
                                </div>
                              </td>
                              <td data-title="Unit Cost" class="numeric">
                                <label class="sr-only" for="cost-<?= e((string) $itemId) ?>">Unit cost for <?= e($item['item']) ?></label>
                                <input
                                  type="number"
                                  step="0.01"
                                  min="0"
                                  id="cost-<?= e((string) $itemId) ?>"
                                  name="unit_cost[<?= e((string) $itemId) ?>]"
                                  value="<?= e($unitCost) ?>"
                                  data-unit-cost-input
                                />
                              </td>
                              <td data-title="UOM">
                                <?php if ($packSize > 1): ?>
                                  <div class="uom-label"><strong><?= e($item['purchase_uom'] ?? 'pack') ?></strong> · <?= e(materialReplenishmentFormatDecimal($packSize, 0)) ?> <?= e($item['stock_uom'] ?? 'ea') ?></div>
                                  <div class="muted small">Stocked in <?= e($item['stock_uom'] ?? 'ea') ?></div>
                                <?php else: ?>
                                  <?= e($item['stock_uom'] ?? 'ea') ?>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>

                    <div class="replenishment-summary" data-summary>
                      <div>
                        <strong>Selected Lines</strong>
                        <span data-selected-count>0</span>
                      </div>
                      <div>
                        <strong>Total Order Qty (ea)</strong>
                        <span data-selected-quantity>0</span>
                      </div>
                    </div>

                    <div class="form-grid">
                      <div class="field">
                        <label for="order-number-<?= e($key) ?>">Order Number</label>
                        <input
                          type="text"
                          id="order-number-<?= e($key) ?>"
                          name="order_number"
                          value="<?= e($orderNumberValue) ?>"
                          placeholder="Auto-generate"
                        />
                        <p class="field-help">Optional reference. Leave blank to let the system assign one.</p>
                      </div>
                      <div class="field">
                        <label for="order-notes-<?= e($key) ?>">Notes</label>
                        <textarea
                          id="order-notes-<?= e($key) ?>"
                          name="notes"
                          rows="3"
                          placeholder="Instructions for the supplier"
                        ><?= e($notesValue) ?></textarea>
                      </div>
                    </div>

                    <footer>
                      <div class="button-group">
                        <button type="submit" name="action" value="generate_pdf" class="button primary">
                          Save Draft &amp; Generate Purchase Order PDF
                        </button>
                        <?php if ($group['is_tubelite']): ?>
                          <button type="submit" name="action" value="generate_tubelite" class="button secondary">
                            Save Draft &amp; Generate Tubelite EZ Estimate
                          </button>
                        <?php endif; ?>
                      </div>
                      <p class="small">The selected action saves these lines as a draft purchase order (visible in Receive Material) before downloading the supplier-ready file.</p>
                    </footer>
                  </form>
                </section>
                <?php $tabIndex++; ?>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </main>
  </div>

  <script src="js/dashboard.js"></script>
  <script src="js/material-replenishment.js" defer></script>
</body>
</html>
