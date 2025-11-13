<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/inventory.php';
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

$formData = [
    'item' => '',
    'part_number' => '',
    'finish' => '',
    'sku' => '',
    'location' => '',
    'stock' => '0',
    'supplier' => '',
    'supplier_contact' => '',
    'reorder_point' => '0',
    'lead_time_days' => '0',
    'category' => '',
    'subcategories' => [],
    'discontinued' => false,
];

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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'create';

        $isDiscontinued = isset($_POST['discontinued']) && (string) $_POST['discontinued'] === '1';

        $payload = [
            'item' => trim((string) ($_POST['item'] ?? '')),
            'part_number' => trim((string) ($_POST['part_number'] ?? '')),
            'finish' => trim((string) ($_POST['finish'] ?? '')),
            'location' => trim((string) ($_POST['location'] ?? '')),
            'supplier' => trim((string) ($_POST['supplier'] ?? '')),
            'supplier_contact' => trim((string) ($_POST['supplier_contact'] ?? '')),
        ];

        $stockRaw = trim((string) ($_POST['stock'] ?? '0'));
        $reorderRaw = trim((string) ($_POST['reorder_point'] ?? '0'));
        $leadTimeRaw = trim((string) ($_POST['lead_time_days'] ?? '0'));
        $formData = [
            'item' => $payload['item'],
            'part_number' => $payload['part_number'],
            'finish' => $payload['finish'],
            'sku' => '',
            'location' => $payload['location'],
            'stock' => $stockRaw,
            'supplier' => $payload['supplier'],
            'supplier_contact' => $payload['supplier_contact'],
            'reorder_point' => $reorderRaw,
            'lead_time_days' => $leadTimeRaw,
            'category' => trim((string) ($_POST['category'] ?? '')),
            'subcategories' => [],
            'discontinued' => $isDiscontinued,
        ];

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

        if ($payload['item'] === '') {
            $errors['item'] = 'Item name is required.';
        }

        if ($payload['part_number'] === '') {
            $errors['part_number'] = 'Part number is required.';
        }

        if ($payload['location'] === '') {
            $errors['location'] = 'Storage location is required.';
        }

        if ($payload['supplier'] === '') {
            $errors['supplier'] = 'Supplier name is required.';
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

        $payload['stock'] = $stock === false ? 0 : $stock;
        $payload['reorder_point'] = $reorderPoint === false ? 0 : $reorderPoint;
        $payload['lead_time_days'] = $leadTimeDays === false ? 0 : $leadTimeDays;
        $payload['supplier_contact'] = $payload['supplier_contact'] !== '' ? $payload['supplier_contact'] : null;

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

        if ($errors === []) {
            $committedQty = $existingItem['committed_qty'] ?? 0;
            $availableQty = $payload['stock'] - $committedQty;
            $payload['status'] = $isDiscontinued
                ? 'Discontinued'
                : inventoryStatusFromAvailable($availableQty, $payload['reorder_point']);

            try {
                if ($action === 'update' && $editingId !== null) {
                    updateInventoryItem($db, $editingId, $payload);
                    header('Location: inventory.php?success=updated');
                } else {
                    createInventoryItem($db, $payload);
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
                $formData = [
                    'item' => $existing['item'],
                    'sku' => $existing['sku'],
                    'part_number' => $existing['part_number'],
                    'finish' => $existing['finish'] ?? '',
                    'location' => $existing['location'],
                    'stock' => (string) $existing['stock'],
                    'supplier' => $existing['supplier'],
                    'supplier_contact' => $existing['supplier_contact'] ?? '',
                    'reorder_point' => (string) $existing['reorder_point'],
                    'lead_time_days' => (string) $existing['lead_time_days'],
                    'category' => '',
                    'subcategories' => [],
                    'discontinued' => $existing['discontinued'],
                ];
                $formData['sku'] = inventoryComposeSku(
                    $formData['part_number'],
                    $existing['finish'] ?? null
                );
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

$modalRequested = isset($_GET['modal']) && $_GET['modal'] === 'open';
$modalOpen = $modalRequested || $editingId !== null || ($errors !== [] && $_SERVER['REQUEST_METHOD'] === 'POST');
$bodyAttributes = $modalOpen ? ' class="modal-open"' : '';
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
        ?>
        <div class="modal-tabs" role="tablist">
          <button type="button" role="tab" id="inventory-tab-details" aria-controls="inventory-panel-details" aria-selected="<?= $detailsActive ? 'true' : 'false' ?>" tabindex="<?= $detailsActive ? '0' : '-1' ?>">Part Details</button>
          <button type="button" role="tab" id="inventory-tab-categories" aria-controls="inventory-panel-categories" aria-selected="<?= $categoriesActive ? 'true' : 'false' ?>" tabindex="<?= $categoriesActive ? '0' : '-1' ?>">Categories</button>
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

          <div class="field">
            <label for="location">Location<span aria-hidden="true">*</span></label>
            <input type="text" id="location" name="location" value="<?= e($formData['location']) ?>" required />
            <?php if (!empty($errors['location'])): ?>
              <p class="field-error"><?= e($errors['location']) ?></p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label for="supplier">Supplier<span aria-hidden="true">*</span></label>
            <input type="text" id="supplier" name="supplier" value="<?= e($formData['supplier']) ?>" required />
            <?php if (!empty($errors['supplier'])): ?>
              <p class="field-error"><?= e($errors['supplier']) ?></p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label for="supplier_contact">Supplier Contact</label>
            <input type="email" id="supplier_contact" name="supplier_contact" value="<?= e($formData['supplier_contact']) ?>" />
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

          <div class="field">
            <label for="lead_time_days">Lead Time (days)<span aria-hidden="true">*</span></label>
            <input type="number" id="lead_time_days" name="lead_time_days" min="0" value="<?= e($formData['lead_time_days']) ?>" required />
            <?php if (!empty($errors['lead_time_days'])): ?>
              <p class="field-error"><?= e($errors['lead_time_days']) ?></p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label>Average Daily Use</label>
            <div class="read-only-value">
              <span class="quantity-pill"><?= e(inventoryFormatDailyUse($currentAverageDailyUse)) ?></span>
              <span class="muted">per day</span>
            </div>
            <p class="field-help">Calculated automatically from the last <?= e((string) inventoryAverageDailyUseWindowDays()) ?> days of usage adjustments.</p>
          </div>
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

        <footer>
          <a class="button secondary" href="inventory.php">Cancel</a>
          <button type="submit" class="button primary"><?= $editingId === null ? 'Create Item' : 'Update Item' ?></button>
        </footer>
      </form>
    </div>
  </div>

  <script src="js/inventory-table.js"></script>
  <script>
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

  </script>
</body>
</html>
