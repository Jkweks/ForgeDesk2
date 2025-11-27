<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/inventory.php';
require_once __DIR__ . '/../app/data/suppliers.php';
require_once __DIR__ . '/../app/data/storage_locations.php';
require_once __DIR__ . '/../app/data/configurator.php';
require_once __DIR__ . '/../app/views/components/inventory_table.php';

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$successMessage = null;
$inventory = [];
$inventorySummary = [
    'total_stock' => 0,
    'total_committed' => 0,
    'total_available' => 0,
    'active_reservations' => 0,
];
$editingId = null;
$finishOptions = inventoryFinishOptions();
$categoryOptions = inventoryCategoryOptions();
$currentAverageDailyUse = null;
$locationHierarchy = [];
$itemActivity = [];

$formData = [
    'item' => '',
    'part_number' => '',
    'finish' => '',
    'sku' => '',
    'stock' => '0',
    'supplier_id' => '',
    'supplier' => '',
    'supplier_contact' => '',
    'reorder_point' => '0',
    'lead_time_days' => '0',
    'pack_size' => '0',
    'purchase_uom' => '',
    'stock_uom' => '',
    'category' => '',
    'subcategories' => [],
    'discontinued' => false,
    'locations' => [],
    'configurator_enabled' => false,
    'configurator_part_type' => '',
    'configurator_uses' => [],
    'configurator_requires' => [],
];
$storageLocations = [];
$storageLocationMap = [];
$locationAssignmentsList = [];
$suppliers = [];
$supplierMap = [];
$configuratorUseOptions = [];
$configuratorUseMap = [];
$configuratorRequirementOptions = [];
$configuratorRequirementMap = [];

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Manage Inventory');
    }
}
unset($groupItems, $item);

try {
    $db = db($databaseConfig);
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

if ($dbError === null) {
    try {
        $storageLocations = storageLocationsList($db, true);
        foreach ($storageLocations as $location) {
            $storageLocationMap[$location['id']] = $location;
        }
        $locationHierarchy = storageLocationsHierarchy($db);
    } catch (\Throwable $exception) {
        $errors[] = 'Unable to load storage locations: ' . $exception->getMessage();
        $storageLocations = [];
        $storageLocationMap = [];
        $locationHierarchy = [];
    }

    try {
        $suppliers = suppliersList($db);
        foreach ($suppliers as $supplier) {
            $supplierMap[$supplier['id']] = $supplier;
        }
    } catch (\Throwable $exception) {
        $errors[] = 'Unable to load suppliers: ' . $exception->getMessage();
        $suppliers = [];
        $supplierMap = [];
    }

    try {
        $configuratorUseOptions = configuratorListUseOptions($db);
        foreach ($configuratorUseOptions as $option) {
            $configuratorUseMap[$option['id']] = $option;
        }

        $configuratorRequirementOptions = configuratorInventoryOptions($db);
        foreach ($configuratorRequirementOptions as $option) {
            $configuratorRequirementMap[$option['id']] = $option;
        }
    } catch (\Throwable $exception) {
        $errors[] = 'Unable to load configurator options: ' . $exception->getMessage();
        $configuratorUseOptions = [];
        $configuratorUseMap = [];
        $configuratorRequirementOptions = [];
        $configuratorRequirementMap = [];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'create';

        $isDiscontinued = isset($_POST['discontinued']) && (string) $_POST['discontinued'] === '1';

        $payload = [
            'item' => trim((string) ($_POST['item'] ?? '')),
            'part_number' => trim((string) ($_POST['part_number'] ?? '')),
            'finish' => trim((string) ($_POST['finish'] ?? '')),
            'supplier' => trim((string) ($_POST['supplier_custom'] ?? ($_POST['supplier'] ?? ''))),
            'supplier_contact' => trim((string) ($_POST['supplier_contact'] ?? '')),
            'supplier_id' => null,
            'supplier_sku' => null,
        ];

        $supplierChoice = trim((string) ($_POST['supplier_id'] ?? ''));

        $stockRaw = trim((string) ($_POST['stock'] ?? '0'));
        $reorderRaw = trim((string) ($_POST['reorder_point'] ?? '0'));
        $leadTimeRaw = trim((string) ($_POST['lead_time_days'] ?? '0'));
        $packSizeRaw = trim((string) ($_POST['pack_size'] ?? ''));
        $purchaseUomRaw = strtolower(trim((string) ($_POST['purchase_uom'] ?? '')));
        $stockUomRaw = trim((string) ($_POST['stock_uom'] ?? ''));
        $configuratorEnabled = isset($_POST['configurator_enabled']) && (string) $_POST['configurator_enabled'] === '1';
        $configuratorPartTypeRaw = strtolower(trim((string) ($_POST['configurator_part_type'] ?? '')));
        $formData = [
            'item' => $payload['item'],
            'part_number' => $payload['part_number'],
            'finish' => $payload['finish'],
            'sku' => '',
            'location' => '',
            'stock' => $stockRaw,
            'supplier_id' => $supplierChoice,
            'supplier' => $payload['supplier'],
            'supplier_contact' => $payload['supplier_contact'],
            'reorder_point' => $reorderRaw,
            'lead_time_days' => $leadTimeRaw,
            'pack_size' => $packSizeRaw !== '' ? $packSizeRaw : '0',
            'purchase_uom' => $purchaseUomRaw,
            'stock_uom' => $stockUomRaw,
            'category' => trim((string) ($_POST['category'] ?? '')),
            'subcategories' => [],
            'discontinued' => $isDiscontinued,
            'configurator_enabled' => $configuratorEnabled,
            'configurator_part_type' => $configuratorPartTypeRaw,
            'configurator_uses' => [],
            'configurator_requires' => [],
        ];

        $submittedConfiguratorUses = isset($_POST['configurator_uses']) && is_array($_POST['configurator_uses'])
            ? array_values(array_unique(array_map('intval', $_POST['configurator_uses'])))
            : [];
        $submittedConfiguratorRequires = isset($_POST['configurator_requires']) && is_array($_POST['configurator_requires'])
            ? array_values(array_unique(array_map('intval', $_POST['configurator_requires'])))
            : [];

        $validConfiguratorUses = array_values(array_filter(
            $submittedConfiguratorUses,
            static fn (int $id): bool => isset($configuratorUseMap[$id])
        ));
        $validConfiguratorRequires = array_values(array_filter(
            $submittedConfiguratorRequires,
            static fn (int $id): bool => isset($configuratorRequirementMap[$id])
        ));

        $formData['configurator_uses'] = $validConfiguratorUses;
        $formData['configurator_requires'] = $validConfiguratorRequires;

        $submittedSubcategories = isset($_POST['subcategories']) && is_array($_POST['subcategories'])
            ? array_values(array_filter(
                array_map(
                    static fn ($value) => trim((string) $value),
                    $_POST['subcategories']
                ),
                static fn (string $value): bool => $value !== ''
            ))
            : [];
        $formData['subcategories'] = array_values(array_unique($submittedSubcategories));

        $submittedLocations = isset($_POST['locations']) && is_array($_POST['locations']) ? $_POST['locations'] : [];
        $formLocationRows = [];
        $locationAssignmentsList = [];

        foreach ($submittedLocations as $row) {
            if (!is_array($row)) {
                continue;
            }

            $locationIdRaw = trim((string) ($row['location_id'] ?? ''));
            $quantityRaw = trim((string) ($row['quantity'] ?? ''));
            $locationId = $locationIdRaw !== '' && ctype_digit($locationIdRaw) ? (int) $locationIdRaw : null;
            $locationRecord = $locationId !== null && isset($storageLocationMap[$locationId]) ? $storageLocationMap[$locationId] : null;

            $formLocationRows[] = [
                'location_id' => $locationId !== null ? $locationId : '',
                'label' => $locationRecord['name'] ?? '',
                'quantity' => $quantityRaw,
            ];

            if ($locationRecord === null) {
                continue;
            }

            if ($quantityRaw === '') {
                $quantity = 0;
            } else {
                $quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($quantity === false) {
                    $errors['locations'] = 'Quantities must be non-negative integers.';
                    continue;
                }
            }

            if (!isset($locationAssignmentsList[$locationRecord['id']])) {
                $locationAssignmentsList[$locationRecord['id']] = [
                    'storage_location_id' => $locationRecord['id'],
                    'name' => $locationRecord['name'],
                    'quantity' => $quantity,
                ];
            } else {
                $locationAssignmentsList[$locationRecord['id']]['quantity'] += $quantity;
            }
        }

        if ($formLocationRows === []) {
            $formLocationRows[] = ['location_id' => '', 'label' => '', 'quantity' => ''];
        }
        $formData['locations'] = $formLocationRows;

        if (empty($errors['locations'])) {
            if ($storageLocations === []) {
                $errors['locations'] = 'Add storage locations before creating inventory.';
            } elseif ($locationAssignmentsList === []) {
                $errors['locations'] = 'Select at least one storage location.';
            }
        }

        $locationAssignmentsList = array_values($locationAssignmentsList);
        $payload['location'] = inventoryFormatLocationSummary($locationAssignmentsList);

        if ($formData['category'] !== '' && !isset($categoryOptions[$formData['category']])) {
            $errors['category'] = 'Select a valid category.';
            $formData['category'] = '';
            $formData['subcategories'] = [];
        } elseif ($formData['category'] === '') {
            $formData['subcategories'] = [];
        } else {
            $validSubcategories = array_values(array_intersect($categoryOptions[$formData['category']], $formData['subcategories']));
            $formData['subcategories'] = $validSubcategories;
        }

        if (!$configuratorEnabled) {
            $configuratorPartTypeRaw = '';
            $validConfiguratorUses = [];
            $validConfiguratorRequires = [];
            $formData['configurator_uses'] = [];
            $formData['configurator_requires'] = [];
        } else {
            if (!in_array($configuratorPartTypeRaw, configuratorAllowedPartTypes(), true)) {
                $errors['configurator_part_type'] = 'Choose door, frame, hardware, or accessory.';
                $formData['configurator_part_type'] = '';
            }

            $invalidRequirementSelections = array_diff($submittedConfiguratorRequires, $validConfiguratorRequires);
            if ($invalidRequirementSelections !== []) {
                $errors['configurator_requires'] = 'Select valid required parts from the list.';
            }
        }

        if ($payload['item'] === '') {
            $errors['item'] = 'Item name is required.';
        }

        if ($payload['part_number'] === '') {
            $errors['part_number'] = 'Part number is required.';
        }

        $stock = filter_var($stockRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($stock === false) {
            $errors['stock'] = 'Stock must be a non-negative integer.';
        }

        $reorderPoint = filter_var($reorderRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($reorderPoint === false) {
            $errors['reorder_point'] = 'Reorder point must be a non-negative integer.';
        }

        $leadTimeDays = filter_var($leadTimeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($leadTimeDays === false) {
            $errors['lead_time_days'] = 'Lead time must be a non-negative integer.';
        }

        $selectedSupplier = null;
        if ($supplierChoice === 'custom') {
            if ($payload['supplier'] === '') {
                $errors['supplier'] = 'Supplier name is required.';
            }
            $formData['supplier_id'] = 'custom';
        } elseif ($supplierChoice !== '') {
            if (!ctype_digit($supplierChoice) || !isset($supplierMap[(int) $supplierChoice])) {
                $errors['supplier'] = 'Select a valid supplier.';
            } else {
                $selectedSupplier = $supplierMap[(int) $supplierChoice];
                $payload['supplier_id'] = (int) $supplierChoice;
                $payload['supplier'] = $selectedSupplier['name'];
                $formData['supplier_id'] = (string) $supplierChoice;

                if ($payload['supplier_contact'] === '' && !empty($selectedSupplier['contact_email'])) {
                    $payload['supplier_contact'] = (string) $selectedSupplier['contact_email'];
                    $formData['supplier_contact'] = $payload['supplier_contact'];
                }

                if (($leadTimeDays === false || $leadTimeDays === 0) && ($selectedSupplier['default_lead_time_days'] ?? 0) > 0) {
                    $leadTimeDays = (int) $selectedSupplier['default_lead_time_days'];
                    $formData['lead_time_days'] = (string) $leadTimeDays;
                }
            }
        } elseif ($payload['supplier'] === '') {
            $errors['supplier'] = 'Supplier name is required.';
        }

        $payload['stock'] = $stock === false ? 0 : $stock;
        $payload['reorder_point'] = $reorderPoint === false ? 0 : $reorderPoint;
        $payload['lead_time_days'] = $leadTimeDays === false ? 0 : $leadTimeDays;
        $payload['supplier_contact'] = $payload['supplier_contact'] !== '' ? $payload['supplier_contact'] : null;

        $packSize = 0.0;
        if ($packSizeRaw === '') {
            $formData['pack_size'] = '0';
        } elseif (!is_numeric($packSizeRaw)) {
            $errors['pack_size'] = 'Pack size must be a number.';
        } else {
            $packSize = (float) $packSizeRaw;
            if ($packSize < 0) {
                $errors['pack_size'] = 'Pack size cannot be negative.';
            } else {
                $formData['pack_size'] = number_format($packSize, 3, '.', '');
            }
        }

        $purchaseUom = null;
        if ($purchaseUomRaw !== '') {
            if (!in_array($purchaseUomRaw, ['pack', 'each'], true)) {
                $errors['purchase_uom'] = 'Select a valid purchase unit.';
            } else {
                $purchaseUom = $purchaseUomRaw;
                $formData['purchase_uom'] = $purchaseUomRaw;
            }
        } else {
            $formData['purchase_uom'] = '';
        }

        $stockUomNormalized = $stockUomRaw !== ''
            ? trim((string) (function_exists('mb_substr') ? mb_substr($stockUomRaw, 0, 16) : substr($stockUomRaw, 0, 16)))
            : '';
        $formData['stock_uom'] = $stockUomNormalized;

        $payload['pack_size'] = $packSize;
        $payload['purchase_uom'] = $purchaseUom;
        $payload['stock_uom'] = $stockUomNormalized !== '' ? $stockUomNormalized : null;

        $finishRaw = $payload['finish'];
        $finish = $finishRaw !== '' ? inventoryNormalizeFinish($finishRaw) : null;
        if ($finishRaw !== '' && $finish === null) {
            $errors['finish'] = 'Choose a valid finish option.';
        }

        $payload['finish'] = $finish;
        $formData['finish'] = $finishRaw;
        $payload['sku'] = inventoryComposeSku($payload['part_number'], $finish);
        $formData['sku'] = $payload['sku'];

        if ($action === 'update') {
            $idRaw = $_POST['id'] ?? '';
            if (ctype_digit($idRaw)) {
                $editingId = (int) $idRaw;
            } else {
                $errors['general'] = 'Invalid item selected for update.';
            }

            $existingItem = null;

            if ($editingId !== null) {
                $existingItem = findInventoryItem($db, $editingId);
                $currentAverageDailyUse = $existingItem['average_daily_use'] ?? null;
            }

            if ($editingId !== null && $existingItem === null) {
                $errors['general'] = 'The selected inventory item no longer exists.';
            }
        } else {
            $existingItem = null;
        }

        $configuratorPartType = $configuratorPartTypeRaw !== '' ? $configuratorPartTypeRaw : null;

        if ($errors === []) {
            $committedQty = $existingItem['committed_qty'] ?? 0;
            $availableQty = $payload['stock'] - $committedQty;
            $payload['status'] = $isDiscontinued
                ? 'Discontinued'
                : inventoryStatusFromAvailable($availableQty, $payload['reorder_point']);

            try {
                if ($action === 'update' && $editingId !== null) {
                    updateInventoryItem($db, $editingId, $payload);
                    inventorySyncLocationAssignments($db, $editingId, $locationAssignmentsList);
                    configuratorSyncPartProfile(
                        $db,
                        $editingId,
                        $configuratorEnabled,
                        $configuratorPartType,
                        $validConfiguratorUses,
                        $validConfiguratorRequires
                    );
                    header('Location: inventory.php?success=updated');
                } else {
                    $newItemId = createInventoryItem($db, $payload);
                    inventorySyncLocationAssignments($db, $newItemId, $locationAssignmentsList);
                    configuratorSyncPartProfile(
                        $db,
                        $newItemId,
                        $configuratorEnabled,
                        $configuratorPartType,
                        $validConfiguratorUses,
                        $validConfiguratorRequires
                    );
                    header('Location: inventory.php?success=created');
                }

                exit;
            } catch (\PDOException $exception) {
                if ($exception->getCode() === '23505') {
                    $errors['sku'] = 'SKU must be unique.';
                } else {
                    $errors['general'] = 'Unable to save inventory item: ' . $exception->getMessage();
                }
            }
        }
    } else {
        if (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) {
            $editingId = (int) $_GET['id'];
            $existing = findInventoryItem($db, $editingId);

            if ($existing !== null) {
                $currentAverageDailyUse = $existing['average_daily_use'] ?? null;
                $existingPackSize = isset($existing['pack_size']) ? (float) $existing['pack_size'] : 0.0;
                $existingPurchaseUom = $existing['purchase_uom'] ?? '';
                $existingStockUom = $existing['stock_uom'] ?? '';
                $formData = [
                    'item' => $existing['item'],
                    'sku' => $existing['sku'],
                    'part_number' => $existing['part_number'],
                    'finish' => $existing['finish'] ?? '',
                    'stock' => (string) $existing['stock'],
                    'supplier_id' => $existing['supplier_id'] !== null ? (string) $existing['supplier_id'] : '',
                    'supplier' => $existing['supplier'],
                    'supplier_contact' => $existing['supplier_contact'] ?? '',
                    'reorder_point' => (string) $existing['reorder_point'],
                    'lead_time_days' => (string) $existing['lead_time_days'],
                    'pack_size' => number_format($existingPackSize, 3, '.', ''),
                    'purchase_uom' => $existingPurchaseUom !== '' ? strtolower((string) $existingPurchaseUom) : '',
                    'stock_uom' => $existingStockUom ?? '',
                    'category' => '',
                    'subcategories' => [],
                    'discontinued' => $existing['discontinued'],
                ];
                if ($formData['supplier_id'] === '' && $existing['supplier'] !== '') {
                    foreach ($suppliers as $supplier) {
                        if (strcasecmp($supplier['name'], (string) $existing['supplier']) === 0) {
                            $formData['supplier_id'] = (string) $supplier['id'];
                            break;
                        }
                    }
                }
                $formData['sku'] = inventoryComposeSku(
                    $formData['part_number'],
                    $existing['finish'] ?? null
                );

                $existingLocations = inventoryLoadItemLocations($db, $editingId);
                if ($existingLocations !== []) {
                    $formData['locations'] = array_map(
                        static fn (array $location): array => [
                            'location_id' => $location['storage_location_id'],
                            'label' => $location['name'],
                            'quantity' => (string) $location['quantity'],
                        ],
                        $existingLocations
                    );
                }

                $configuratorProfile = configuratorLoadPartProfile($db, $editingId);
                $formData['configurator_enabled'] = $configuratorProfile['enabled'];
                $formData['configurator_part_type'] = $configuratorProfile['part_type'] ?? '';
                $formData['configurator_uses'] = array_values(array_filter(
                    $configuratorProfile['use_ids'],
                    static fn (int $id): bool => isset($configuratorUseMap[$id])
                ));
                $formData['configurator_requires'] = array_values(array_filter(
                    $configuratorProfile['required_ids'],
                    static fn (int $id): bool => isset($configuratorRequirementMap[$id])
                ));

                $itemActivity = inventoryLoadItemActivity($db, $editingId, 50);
            } else {
                $successMessage = null;
                $errors['general'] = 'The requested inventory item could not be found.';
                $editingId = null;
            }
        }
    }

    $inventory = loadInventory($db);
    $inventorySummary = inventoryReservationSummary($db);
}

if ($formData['locations'] === []) {
    $formData['locations'][] = ['location_id' => '', 'label' => '', 'quantity' => ''];
}

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'created') {
        $successMessage = 'Inventory item created successfully.';
    } elseif ($_GET['success'] === 'updated') {
        $successMessage = 'Inventory item updated successfully.';
    }
}

if ($formData['sku'] === '' && $formData['part_number'] !== '') {
    $formData['sku'] = inventoryComposeSku(
        $formData['part_number'],
        $formData['finish'] !== '' ? $formData['finish'] : null
    );
}

$activeModalTab = !empty($errors['category']) ? 'categories' : 'details';
if (!empty($errors['configurator_part_type']) || !empty($errors['configurator_requires'])) {
    $activeModalTab = 'configurator';
}
if (isset($_GET['tab']) && in_array($_GET['tab'], ['details', 'categories', 'configurator', 'activity'], true)) {
    $activeModalTab = $_GET['tab'];
}

$modalRequested = isset($_GET['modal']) && $_GET['modal'] === 'open';
$modalOpen = $modalRequested || $editingId !== null || ($errors !== [] && $_SERVER['REQUEST_METHOD'] === 'POST');
$bodyClasses = ['has-sidebar-toggle'];
if ($modalOpen) {
    $bodyClasses[] = 'modal-open';
}
$bodyAttributes = ' class="' . implode(' ', $bodyClasses) . '"';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> Inventory Manager</title>
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
      <section class="panel" aria-labelledby="inventory-manager-title">
        <header class="panel-header">
          <div>
            <h1 id="inventory-manager-title">Inventory Manager</h1>
            <p class="small">Review stock levels, update item details, and manage supplier information.</p>
          </div>
          <div class="header-actions">
            <a class="button secondary" href="inventory_export.php">Download CSV</a>
            <a class="button secondary" href="cycle-count.php">Start cycle count</a>
            <a class="button secondary" href="/admin/estimate-check.php">Analyze EZ Estimate</a>
            <a class="button primary" href="inventory.php?modal=open">Add Inventory Item</a>
            <?php if ($editingId !== null): ?>
              <a class="button secondary" href="inventory.php">Exit edit</a>
            <?php endif; ?>
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

        <?php if (!empty($errors['general'])): ?>
          <div class="alert error" role="alert">
            <?= e($errors['general']) ?>
          </div>
        <?php endif; ?>

        <div class="table-wrapper">
          <?php if ($dbError === null): ?>
            <div class="inventory-metrics" role="list">
              <div class="metric" role="listitem">
                <span class="metric-label">Units on hand</span>
                <span class="metric-value"><?= e(inventoryFormatQuantity($inventorySummary['total_stock'])) ?></span>
              </div>
              <div class="metric" role="listitem">
                <span class="metric-label">Committed to jobs</span>
                <span class="metric-value"><?= e(inventoryFormatQuantity($inventorySummary['total_committed'])) ?></span>
              </div>
              <div class="metric" role="listitem">
                <span class="metric-label">Available to promise</span>
                <span class="metric-value"><?= e(inventoryFormatQuantity($inventorySummary['total_available'])) ?></span>
              </div>
              <div class="metric" role="listitem">
                <span class="metric-label">Active reservations</span>
                <span class="metric-value">
                  <a class="metric-link" href="/admin/job-reservations.php">
                    <?= e((string) $inventorySummary['active_reservations']) ?>
                  </a>
                </span>
              </div>
            </div>
            <?php renderInventoryTable($inventory, [
                'includeFilters' => true,
                'emptyMessage' => 'No inventory items found. Use the button above to add your first part.',
                'id' => 'inventory-table-all',
                'pageSize' => 15,
                'locationHierarchy' => $locationHierarchy,
            ]); ?>
          <?php endif; ?>
        </div>
      </section>
    </main>
  </div>

  <?php
  $modalClasses = 'modal' . ($modalOpen ? ' open' : '');
  $modalTitle = $editingId === null ? 'Add Inventory Item' : 'Edit Inventory Item';
  $modalDescription = $editingId === null
      ? 'Define the base part, finish, and stocking targets before onboarding data.'
      : 'Update the part details, finish, and stocking targets for this item.';
  ?>
  <div id="inventory-modal" class="<?= e($modalClasses) ?>" role="dialog" aria-modal="true" aria-labelledby="inventory-modal-title" aria-hidden="<?= $modalOpen ? 'false' : 'true' ?>" data-close-url="inventory.php">
    <div class="modal-dialog">
      <header>
        <div>
          <h2 id="inventory-modal-title"><?= e($modalTitle) ?></h2>
          <p><?= e($modalDescription) ?></p>
        </div>
        <a class="modal-close" href="inventory.php" aria-label="Close inventory form">&times;</a>
      </header>

      <form method="post" class="form" novalidate>
        <input type="hidden" name="action" value="<?= $editingId === null ? 'create' : 'update' ?>" />
        <?php if ($editingId !== null): ?>
          <input type="hidden" name="id" value="<?= e((string) $editingId) ?>" />
        <?php endif; ?>

        <?php
        $detailsActive = $activeModalTab === 'details';
        $categoriesActive = $activeModalTab === 'categories';
        $configuratorActive = $activeModalTab === 'configurator';
        $activityActive = $activeModalTab === 'activity';
        ?>
        <div class="modal-tabs" role="tablist">
          <button type="button" role="tab" id="inventory-tab-details" aria-controls="inventory-panel-details" aria-selected="<?= $detailsActive ? 'true' : 'false' ?>" tabindex="<?= $detailsActive ? '0' : '-1' ?>">Part Details</button>
          <button type="button" role="tab" id="inventory-tab-categories" aria-controls="inventory-panel-categories" aria-selected="<?= $categoriesActive ? 'true' : 'false' ?>" tabindex="<?= $categoriesActive ? '0' : '-1' ?>">Categories</button>
          <button type="button" role="tab" id="inventory-tab-configurator" aria-controls="inventory-panel-configurator" aria-selected="<?= $configuratorActive ? 'true' : 'false' ?>" tabindex="<?= $configuratorActive ? '0' : '-1' ?>">Configurator</button>
          <button type="button" role="tab" id="inventory-tab-activity" aria-controls="inventory-panel-activity" aria-selected="<?= $activityActive ? 'true' : 'false' ?>" tabindex="<?= $activityActive ? '0' : '-1' ?>">Activity</button>
        </div>

        <div id="inventory-panel-details" class="tab-panel" role="tabpanel" aria-labelledby="inventory-tab-details"<?= $detailsActive ? '' : ' hidden' ?>>
          <div class="field">
            <label for="item">Item<span aria-hidden="true">*</span></label>
            <input type="text" id="item" name="item" value="<?= e($formData['item']) ?>" required data-modal-focus="true" />
            <?php if (!empty($errors['item'])): ?>
              <p class="field-error"><?= e($errors['item']) ?></p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label for="part_number">Part Number<span aria-hidden="true">*</span></label>
            <input type="text" id="part_number" name="part_number" value="<?= e($formData['part_number']) ?>" required />
            <?php if (!empty($errors['part_number'])): ?>
              <p class="field-error"><?= e($errors['part_number']) ?></p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label for="finish">Finish</label>
            <select id="finish" name="finish">
              <option value="">No finish specified</option>
              <?php foreach ($finishOptions as $option): ?>
                <option value="<?= e($option) ?>"<?= strtoupper($formData['finish']) === $option ? ' selected' : '' ?>><?= e($option) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="field-help">Choose the finish code used when composing SKUs.</p>
            <?php if (!empty($errors['finish'])): ?>
              <p class="field-error"><?= e($errors['finish']) ?></p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label for="sku">Generated SKU</label>
            <input type="text" id="sku" name="sku" value="<?= e($formData['sku']) ?>" readonly />
            <p class="field-help">The SKU is automatically built from the part number and finish.</p>
            <?php if (!empty($errors['sku'])): ?>
              <p class="field-error"><?= e($errors['sku']) ?></p>
            <?php endif; ?>
          </div>

          <section class="location-manager">
            <div class="location-manager__header">
              <span>Storage locations<span aria-hidden="true">*</span></span>
              <p class="small">Assign one or more storage locations along with the quantity typically staged there.</p>
            </div>
            <div class="location-rows" data-location-rows>
              <?php
              $renderLocations = $formData['locations'] !== []
                  ? $formData['locations']
                  : [['location_id' => '', 'label' => '', 'quantity' => '']];
              ?>
              <?php foreach ($renderLocations as $index => $row): ?>
                <div class="location-row" data-location-row>
                  <div class="field">
                    <label for="location-select-<?= e((string) $index) ?>">Location</label>
                    <select
                      id="location-select-<?= e((string) $index) ?>"
                      data-location-input="location_id"
                      name="locations[<?= e((string) $index) ?>][location_id]"
                      required
                    >
                      <option value="">Select location</option>
                      <?php foreach ($storageLocations as $option): ?>
                        <?php $selected = (string) ($row['location_id'] ?? '') === (string) $option['id']; ?>
                        <option
                          value="<?= e((string) $option['id']) ?>"
                          <?= $selected ? 'selected' : '' ?>
                        >
                          <?= e($option['name']) ?><?= $option['is_active'] ? '' : ' (inactive)' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="location-qty-<?= e((string) $index) ?>">Qty in slot</label>
                    <input
                      type="number"
                      id="location-qty-<?= e((string) $index) ?>"
                      data-location-input="quantity"
                      name="locations[<?= e((string) $index) ?>][quantity]"
                      min="0"
                      step="1"
                      value="<?= e((string) ($row['quantity'] ?? '')) ?>"
                    />
                  </div>
                  <button type="button" class="button ghost icon-only" data-remove-location aria-label="Remove location">&times;</button>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="location-actions">
              <button type="button" class="button secondary" data-add-location>Add another location</button>
              <p class="field-help">Manage the available locations from the <a href="/admin/storage-locations.php">Storage Locations dashboard</a>.</p>
            </div>
            <?php if (!empty($errors['locations'])): ?>
              <p class="field-error"><?= e($errors['locations']) ?></p>
            <?php endif; ?>
          </section>

          <div class="field-grid">
            <div class="field">
              <label for="supplier_id">Supplier<span aria-hidden="true">*</span></label>
              <select id="supplier_id" name="supplier_id" data-supplier-select>
                <option value="">Select a supplier</option>
                <?php foreach ($suppliers as $supplier): ?>
                  <?php
                    $isSelected = $formData['supplier_id'] !== '' && (int) $formData['supplier_id'] === (int) $supplier['id'];
                    $contactEmail = $supplier['contact_email'] ?? '';
                    $contactPhone = $supplier['contact_phone'] ?? '';
                  ?>
                  <option
                    value="<?= e((string) $supplier['id']) ?>"
                    data-contact-email="<?= e((string) $contactEmail) ?>"
                    data-contact-phone="<?= e((string) $contactPhone) ?>"
                    data-lead-time="<?= e((string) ($supplier['default_lead_time_days'] ?? 0)) ?>"
                    <?= $isSelected ? 'selected' : '' ?>
                  >
                    <?= e($supplier['name']) ?>
                  </option>
                <?php endforeach; ?>
                <option value="custom"<?= $formData['supplier_id'] === 'custom' ? ' selected' : '' ?>>Custom supplier</option>
              </select>
              <p class="field-help">Maintain supplier records from the Suppliers admin dashboard.</p>
              <?php if (!empty($errors['supplier'])): ?>
                <p class="field-error"><?= e($errors['supplier']) ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label for="supplier">Custom supplier name<span aria-hidden="true">*</span></label>
              <input
                type="text"
                id="supplier"
                name="supplier_custom"
                value="<?= e($formData['supplier']) ?>"
                placeholder="Other vendor name"
              />
              <p class="field-help">Use when the vendor is not yet in the directory.</p>
            </div>
          </div>

          <div class="field">
            <label for="supplier_contact">Supplier Contact</label>
            <input type="text" id="supplier_contact" name="supplier_contact" value="<?= e($formData['supplier_contact']) ?>" />
          </div>

          <div class="field-grid">
            <div class="field">
              <label for="stock">Stock<span aria-hidden="true">*</span></label>
              <input type="number" id="stock" name="stock" min="0" value="<?= e($formData['stock']) ?>" required />
              <?php if (!empty($errors['stock'])): ?>
                <p class="field-error"><?= e($errors['stock']) ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label for="reorder_point">Reorder Point<span aria-hidden="true">*</span></label>
              <input type="number" id="reorder_point" name="reorder_point" min="0" value="<?= e($formData['reorder_point']) ?>" required />
              <?php if (!empty($errors['reorder_point'])): ?>
                <p class="field-error"><?= e($errors['reorder_point']) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <div class="field-grid">
            <div class="field">
              <label for="lead_time_days">Lead Time (days)<span aria-hidden="true">*</span></label>
              <input type="number" id="lead_time_days" name="lead_time_days" min="0" value="<?= e($formData['lead_time_days']) ?>" required />
              <?php if (!empty($errors['lead_time_days'])): ?>
                <p class="field-error"><?= e($errors['lead_time_days']) ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label for="pack_size">Pack Size</label>
              <input
                type="number"
                id="pack_size"
                name="pack_size"
                min="0"
                step="0.01"
                value="<?= e($formData['pack_size']) ?>"
              />
              <p class="field-help">Leave at 0 to order in eaches. Enter the number of eaches per pack when vendors require packs.</p>
              <?php if (!empty($errors['pack_size'])): ?>
                <p class="field-error"><?= e($errors['pack_size']) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <div class="field-grid">
            <div class="field">
              <label for="purchase_uom">Purchase Unit</label>
              <select id="purchase_uom" name="purchase_uom">
                <option value="">Match packs/eaches automatically</option>
                <option value="pack"<?= $formData['purchase_uom'] === 'pack' ? ' selected' : '' ?>>Packs</option>
                <option value="each"<?= $formData['purchase_uom'] === 'each' ? ' selected' : '' ?>>Each</option>
              </select>
              <p class="field-help">Controls the default unit shown on Material Replenishment.</p>
              <?php if (!empty($errors['purchase_uom'])): ?>
                <p class="field-error"><?= e($errors['purchase_uom']) ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label for="stock_uom">Stock Unit</label>
              <input
                type="text"
                id="stock_uom"
                name="stock_uom"
                value="<?= e($formData['stock_uom']) ?>"
                maxlength="16"
                placeholder="ea"
              />
              <p class="field-help">Displayed in replenishment tables and purchase orders (ex: ea, ft, kit).</p>
            </div>
          </div>

          <div class="field">
            <label>Average Daily Use</label>
            <div class="read-only-value">
              <span class="quantity-pill"><?= e(inventoryFormatDailyUse($currentAverageDailyUse)) ?></span>
              <span class="muted">per day</span>
            </div>
            <p class="field-help">Calculated automatically from the last <?= e((string) inventoryAverageDailyUseWindowDays()) ?> days of usage adjustments.</p>
          </div>

          <div class="field">
            <div class="checkbox-field">
              <input type="checkbox" id="discontinued" name="discontinued" value="1"<?= $formData['discontinued'] ? ' checked' : '' ?>>
              <label for="discontinued">Mark item as discontinued</label>
            </div>
            <p class="field-help">Statuses are automatically calculated from available stock. Discontinued items keep their label regardless of stock levels.</p>
          </div>
        </div>

        <div id="inventory-panel-categories" class="tab-panel" role="tabpanel" aria-labelledby="inventory-tab-categories"<?= $categoriesActive ? '' : ' hidden' ?>>
          <div class="field">
            <label for="category">Category</label>
            <select id="category" name="category">
              <option value="">Select category</option>
              <?php foreach ($categoryOptions as $category => $subcategories): ?>
                <option value="<?= e($category) ?>"<?= $formData['category'] === $category ? ' selected' : '' ?>><?= e($category) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="field-help">Choose the primary grouping for this part. Categories will be saved in a future update.</p>
            <?php if (!empty($errors['category'])): ?>
              <p class="field-error"><?= e($errors['category']) ?></p>
            <?php endif; ?>
          </div>

          <div class="field">
            <span class="field-label">Subcategories</span>
            <div class="subcategory-grid" data-subcategory-container>
              <?php foreach ($categoryOptions as $category => $subcategories): ?>
                <?php $isActive = $formData['category'] === $category; ?>
                <fieldset class="subcategory-group" data-category-group="<?= e($category) ?>"<?= $isActive ? '' : ' hidden' ?>>
                  <legend class="sr-only"><?= e($category) ?> subcategories</legend>
                  <?php foreach ($subcategories as $subcategory): ?>
                    <?php $checkboxId = 'subcategory-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($category . '-' . $subcategory)); ?>
                    <label class="subcategory-option" for="<?= e($checkboxId) ?>">
                      <input
                        type="checkbox"
                        id="<?= e($checkboxId) ?>"
                        name="subcategories[]"
                        value="<?= e($subcategory) ?>"
                        <?= $isActive && in_array($subcategory, $formData['subcategories'], true) ? 'checked' : '' ?>
                        <?= $isActive ? '' : 'disabled' ?>
                      >
                      <span><?= e($subcategory) ?></span>
                    </label>
                  <?php endforeach; ?>
                </fieldset>
              <?php endforeach; ?>
            </div>
            <p class="field-help">Select all applicable specialty groupings. These selections are informational today and will be stored in a future project.</p>
          </div>
        </div>

        <div id="inventory-panel-configurator" class="tab-panel" role="tabpanel" aria-labelledby="inventory-tab-configurator"<?= $configuratorActive ? '' : ' hidden' ?>>
          <div class="field">
            <div class="checkbox-field">
              <input type="checkbox" id="configurator_enabled" name="configurator_enabled" value="1"<?= $formData['configurator_enabled'] ? ' checked' : '' ?>>
              <label for="configurator_enabled">Available in configurator</label>
            </div>
            <p class="field-help">Include this part when building door and frame assemblies.</p>
          </div>

          <fieldset class="field-group" data-configurator-fields>
            <legend class="sr-only">Configurator details</legend>
            <div class="field-grid">
              <div class="field">
                <label for="configurator_part_type">Configurator part type<span aria-hidden="true">*</span></label>
                <select id="configurator_part_type" name="configurator_part_type" data-configurator-toggle>
                  <option value="">Select type</option>
                  <?php foreach (configuratorAllowedPartTypes() as $type): ?>
                    <?php $typeLabel = ucfirst($type); ?>
                    <option value="<?= e($type) ?>"<?= $formData['configurator_part_type'] === $type ? ' selected' : '' ?>><?= e($typeLabel) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="field-help">Categorize the component for configurator filtering.</p>
                <?php if (!empty($errors['configurator_part_type'])): ?>
                  <p class="field-error"><?= e($errors['configurator_part_type']) ?></p>
                <?php endif; ?>
              </div>

              <div class="field">
                <label for="configurator_uses">Part uses</label>
                <select id="configurator_uses" name="configurator_uses[]" multiple data-configurator-toggle>
                  <?php foreach ($configuratorUseOptions as $option): ?>
                    <option value="<?= e((string) $option['id']) ?>"<?= in_array((int) $option['id'], $formData['configurator_uses'], true) ? ' selected' : '' ?>><?= e($option['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="field-help">Select all applicable opening types or prep scenarios.</p>
              </div>
            </div>

            <div class="field">
              <label for="configurator_requires">Requires</label>
              <select id="configurator_requires" name="configurator_requires[]" multiple data-configurator-toggle>
                <?php foreach ($configuratorRequirementOptions as $option): ?>
                  <?php if ($editingId !== null && (int) $option['id'] === $editingId) { continue; } ?>
                  <option value="<?= e((string) $option['id']) ?>"<?= in_array((int) $option['id'], $formData['configurator_requires'], true) ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="field-help">Add hardware, frames, or accessories automatically whenever this part is used. Required parts cascade into the final bill of material.</p>
              <?php if (!empty($errors['configurator_requires'])): ?>
                <p class="field-error"><?= e($errors['configurator_requires']) ?></p>
              <?php endif; ?>
            </div>
          </fieldset>
        </div>

        <div id="inventory-panel-activity" class="tab-panel" role="tabpanel" aria-labelledby="inventory-tab-activity"<?= $activityActive ? '' : ' hidden' ?>>
          <?php if ($editingId === null): ?>
            <p class="small">Save the item to review receipts, cycle counts, and inventory adjustments.</p>
          <?php elseif ($itemActivity === []): ?>
            <p class="small">No transactions recorded for this part yet.</p>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="table activity-table">
                <thead>
                  <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Type</th>
                    <th scope="col">Reference</th>
                    <th scope="col" class="numeric">Change</th>
                    <th scope="col">Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($itemActivity as $entry): ?>
                    <?php
                      $timestamp = strtotime((string) $entry['occurred_at']);
                      $displayDate = $timestamp !== false ? date('Y-m-d H:i', $timestamp) : (string) $entry['occurred_at'];
                      $kind = $entry['kind'];
                      $typeLabel = $kind === 'cycle_count'
                        ? 'Cycle count'
                        : ($kind === 'receipt' ? 'PO receipt' : 'Inventory transaction');
                      $change = (float) $entry['quantity_change'];
                      $pillClass = $change < 0 ? 'danger' : ($change > 0 ? 'success' : 'brand');
                      $changeLabel = ($change > 0 ? '+' : '') . inventoryFormatQuantity($change);
                      $details = $entry['details'] ?? [];
                      $context = '';

                      if ($kind === 'cycle_count') {
                          $expected = isset($details['expected_qty']) ? inventoryFormatQuantity((int) $details['expected_qty']) : '—';
                          $counted = isset($details['counted_qty']) ? inventoryFormatQuantity((int) $details['counted_qty']) : '—';
                          $context = 'Counted ' . $counted . ' vs expected ' . $expected;
                      } elseif ($kind === 'receipt') {
                          $received = isset($details['received_qty']) ? inventoryFormatQuantity($details['received_qty']) : '0';
                          $cancelled = isset($details['cancelled_qty']) ? (float) $details['cancelled_qty'] : 0.0;
                          $context = 'Received ' . $received;
                          if ($cancelled > 0.0001) {
                              $context .= ' · Cancelled ' . inventoryFormatQuantity($cancelled);
                          }
                      } else {
                          $before = $details['stock_before'] ?? null;
                          $after = $details['stock_after'] ?? null;
                          if ($before !== null && $after !== null) {
                              $context = 'Stock ' . inventoryFormatQuantity($before) . ' → ' . inventoryFormatQuantity($after);
                          }
                      }

                      $note = $entry['note'] ?? null;
                    ?>
                    <tr>
                      <td><?= e($displayDate) ?></td>
                      <td><?= e($typeLabel) ?></td>
                      <td><?= e((string) $entry['reference']) ?></td>
                      <td class="numeric"><span class="quantity-pill <?= e($pillClass) ?>"><?= e($changeLabel) ?></span></td>
                      <td>
                        <?= $context !== '' ? e($context) : '<span class="muted">No extra details</span>' ?>
                        <?php if ($note !== null): ?>
                          <p class="small muted">Note: <?= e($note) ?></p>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <footer>
          <a class="button secondary" href="inventory.php">Cancel</a>
          <button type="submit" class="button primary"><?= $editingId === null ? 'Create Item' : 'Update Item' ?></button>
        </footer>
      </form>
      <template id="location-row-template">
        <div class="location-row" data-location-row>
          <div class="field">
            <label>Location</label>
            <select data-location-input="location_id" required>
              <option value="">Select location</option>
              <?php foreach ($storageLocations as $option): ?>
                <option value="<?= e((string) $option['id']) ?>">
                  <?= e($option['name']) ?><?= $option['is_active'] ? '' : ' (inactive)' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Qty in slot</label>
            <input type="number" min="0" step="1" data-location-input="quantity" />
          </div>
          <button type="button" class="button ghost icon-only" data-remove-location aria-label="Remove location">&times;</button>
        </div>
      </template>
    </div>
  </div>

  <script src="js/dashboard.js"></script>
  <script src="js/inventory-table.js"></script>
  <script>
  (function () {
    const modal = document.getElementById('inventory-modal');
    if (!modal) {
      return;
    }

    const configuratorCheckbox = modal.querySelector('#configurator_enabled');
    const configuratorFieldset = modal.querySelector('[data-configurator-fields]');
    const configuratorInputs = Array.from(modal.querySelectorAll('[data-configurator-toggle]'));

    function syncConfiguratorControls() {
      const isEnabled = configuratorCheckbox instanceof HTMLInputElement && configuratorCheckbox.checked;

      configuratorInputs.forEach((input) => {
        if (input instanceof HTMLInputElement || input instanceof HTMLSelectElement) {
          input.disabled = !isEnabled;
        }
      });

      if (configuratorFieldset instanceof HTMLElement) {
        if (isEnabled) {
          configuratorFieldset.removeAttribute('aria-disabled');
        } else {
          configuratorFieldset.setAttribute('aria-disabled', 'true');
        }
      }
    }

    if (configuratorCheckbox instanceof HTMLInputElement) {
      configuratorCheckbox.addEventListener('change', syncConfiguratorControls);
      syncConfiguratorControls();
    }
  })();

  (function () {
    const modal = document.getElementById('inventory-modal');
    if (!modal) {
      return;
    }

    const body = document.body;
    const closeUrl = modal.getAttribute('data-close-url') || 'inventory.php';
    const closeModal = () => {
      window.location.href = closeUrl;
    };

    if (modal.classList.contains('open')) {
      modal.setAttribute('aria-hidden', 'false');
      if (!body.classList.contains('modal-open')) {
        body.classList.add('modal-open');
      }
      const focusTarget = modal.querySelector('[data-modal-focus]');
      if (focusTarget instanceof HTMLElement) {
        window.requestAnimationFrame(() => focusTarget.focus());
      }
    } else {
      modal.setAttribute('aria-hidden', 'true');
    }

    const closeButton = modal.querySelector('.modal-close');
    if (closeButton) {
      closeButton.addEventListener('click', function (event) {
        event.preventDefault();
        closeModal();
      });
    }

    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        closeModal();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && modal.classList.contains('open')) {
        closeModal();
      }
    });
  })();

  (function () {
    const modal = document.getElementById('inventory-modal');
    if (!modal) {
      return;
    }

    const tabs = Array.from(modal.querySelectorAll('.modal-tabs [role="tab"]'));
    if (tabs.length === 0) {
      return;
    }

    const tabMap = new Map();
    tabs.forEach((tab) => {
      const panelId = tab.getAttribute('aria-controls');
      if (panelId) {
        const panel = modal.querySelector('#' + panelId);
        if (panel instanceof HTMLElement) {
          tabMap.set(tab, panel);
        }
      }
    });

    function activateTab(targetTab) {
      tabs.forEach((tab) => {
        const isActive = tab === targetTab;
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        tab.setAttribute('tabindex', isActive ? '0' : '-1');
        const panel = tabMap.get(tab);
        if (panel) {
          if (isActive) {
            panel.removeAttribute('hidden');
          } else {
            panel.setAttribute('hidden', 'hidden');
          }
        }
      });
    }

    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => activateTab(tab));
      tab.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          activateTab(tab);
        }

        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
          event.preventDefault();
          const offset = event.key === 'ArrowRight' ? 1 : -1;
          const nextIndex = (index + offset + tabs.length) % tabs.length;
          tabs[nextIndex].focus();
        }
      });
    });
  })();

  (function () {
    const modal = document.getElementById('inventory-modal');
    if (!modal) {
      return;
    }

    const categorySelect = modal.querySelector('#category');
    if (!(categorySelect instanceof HTMLSelectElement)) {
      return;
    }

    const groups = Array.from(modal.querySelectorAll('[data-category-group]'));

    function syncGroups() {
      const selected = categorySelect.value;
      groups.forEach((group) => {
        if (!(group instanceof HTMLElement)) {
          return;
        }

        const isActive = group.getAttribute('data-category-group') === selected && selected !== '';
        if (isActive) {
          group.removeAttribute('hidden');
        } else {
          group.setAttribute('hidden', 'hidden');
        }

        const inputs = Array.from(group.querySelectorAll('input[type="checkbox"]'));
        inputs.forEach((input) => {
          input.disabled = !isActive;
          if (!isActive) {
            input.checked = false;
          }
        });
      });
    }

    categorySelect.addEventListener('change', syncGroups);
    syncGroups();
  })();

  (function () {
    const modal = document.getElementById('inventory-modal');
    if (!modal) {
      return;
    }

    const supplierSelect = modal.querySelector('[data-supplier-select]');
    const contactInput = modal.querySelector('#supplier_contact');
    const leadInput = modal.querySelector('#lead_time_days');
    const customInput = modal.querySelector('input[name="supplier_custom"]');

    if (!(supplierSelect instanceof HTMLSelectElement)) {
      return;
    }

    function applySupplierDefaults() {
      const option = supplierSelect.options[supplierSelect.selectedIndex];
      const isCustom = supplierSelect.value === 'custom' || supplierSelect.value === '';

      if (customInput instanceof HTMLInputElement) {
        customInput.disabled = !isCustom;
        if (!isCustom && customInput.value.trim() === '') {
          customInput.placeholder = option?.textContent || 'Other vendor name';
        }
      }

      if (!(option instanceof HTMLOptionElement)) {
        return;
      }

      const contactEmail = option.dataset.contactEmail || '';
      const contactPhone = option.dataset.contactPhone || '';
      const leadTime = parseInt(option.dataset.leadTime || '0', 10);

      if (contactInput instanceof HTMLInputElement && contactInput.value.trim() === '') {
        contactInput.value = contactEmail || contactPhone;
      }

      if (leadInput instanceof HTMLInputElement) {
        const current = parseInt(leadInput.value || '0', 10);
        if ((Number.isNaN(current) || current === 0) && Number.isFinite(leadTime) && leadTime > 0) {
          leadInput.value = String(leadTime);
        }
      }
    }

    supplierSelect.addEventListener('change', applySupplierDefaults);
    applySupplierDefaults();
  })();

  (function () {
    const modal = document.getElementById('inventory-modal');
    if (!modal) {
      return;
    }

    const rowsContainer = modal.querySelector('[data-location-rows]');
    const template = document.getElementById('location-row-template');
    const addButton = modal.querySelector('[data-add-location]');

    if (!(rowsContainer instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function getRows() {
      return Array.from(rowsContainer.querySelectorAll('[data-location-row]'));
    }

    function syncNames() {
      getRows().forEach((row, index) => {
        const inputs = Array.from(row.querySelectorAll('[data-location-input]'));
        inputs.forEach((input) => {
          const key = input.getAttribute('data-location-input');
          if (!key) {
            return;
          }

          input.setAttribute('name', 'locations[' + index + '][' + key + ']');

          if (input instanceof HTMLInputElement || input instanceof HTMLSelectElement) {
            const baseId = 'location-' + key + '-' + index;
            input.id = baseId;
            const label = input.closest('.field')?.querySelector('label');
            if (label instanceof HTMLLabelElement) {
              label.setAttribute('for', baseId);
            }
          }
        });
      });
    }

    function attachRow(row) {
      const removeButton = row.querySelector('[data-remove-location]');
      if (removeButton instanceof HTMLButtonElement) {
        removeButton.addEventListener('click', function (event) {
          event.preventDefault();
          const rows = getRows();
          if (rows.length <= 1) {
            const select = row.querySelector('[data-location-input="location_id"]');
            const qty = row.querySelector('[data-location-input="quantity"]');
            if (select instanceof HTMLSelectElement) {
              select.value = '';
            }
            if (qty instanceof HTMLInputElement) {
              qty.value = '';
            }
            return;
          }

          row.remove();
          syncNames();
        });
      }
    }

    function addRow(defaults = {}) {
      const fragment = template.content.cloneNode(true);
      rowsContainer.appendChild(fragment);
      const rows = getRows();
      const newRow = rows[rows.length - 1];
      if (!newRow) {
        return;
      }

      const locationSelect = newRow.querySelector('[data-location-input="location_id"]');
      if (locationSelect instanceof HTMLSelectElement && defaults.location_id) {
        locationSelect.value = String(defaults.location_id);
      }

      const qtyInput = newRow.querySelector('[data-location-input="quantity"]');
      if (qtyInput instanceof HTMLInputElement && Object.prototype.hasOwnProperty.call(defaults, 'quantity')) {
        qtyInput.value = defaults.quantity;
      }

      attachRow(newRow);
      syncNames();
    }

    getRows().forEach(attachRow);
    syncNames();

    if (addButton instanceof HTMLButtonElement) {
      addButton.addEventListener('click', function (event) {
        event.preventDefault();
        addRow();
      });
    }
  })();

  </script>
</body>
</html>
