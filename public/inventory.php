<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/data/inventory.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Resolve the URL for a navigation item, defaulting to an in-page anchor when no explicit href is provided.
 *
 * @param array{label:string,href?:string} $item
 */
function nav_href(array $item): string
{
    if (!empty($item['href'])) {
        return $item['href'];
    }

    $anchor = strtolower(str_replace(' ', '-', $item['label']));

    return '#' . $anchor;
}

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
$statuses = ['In Stock', 'Low', 'Reorder', 'Critical', 'Discontinued'];

$finishOptions = inventoryFinishOptions();

$formData = [
    'item' => '',
    'part_number' => '',
    'finish' => '',
    'sku' => '',
    'location' => '',
    'stock' => '0',
    'status' => $statuses[0],
    'supplier' => '',
    'supplier_contact' => '',
    'reorder_point' => '0',
    'lead_time_days' => '0',
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

if ($dbError === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'create';

        $payload = [
            'item' => trim((string) ($_POST['item'] ?? '')),
            'part_number' => trim((string) ($_POST['part_number'] ?? '')),
            'finish' => trim((string) ($_POST['finish'] ?? '')),
            'location' => trim((string) ($_POST['location'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? '')),
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
            'status' => $payload['status'],
            'supplier' => $payload['supplier'],
            'supplier_contact' => $payload['supplier_contact'],
            'reorder_point' => $reorderRaw,
            'lead_time_days' => $leadTimeRaw,
        ];

        if ($payload['item'] === '') {
            $errors['item'] = 'Item name is required.';
        }

        if ($payload['part_number'] === '') {
            $errors['part_number'] = 'Part number is required.';
        }

        if ($payload['location'] === '') {
            $errors['location'] = 'Storage location is required.';
        }

        if (!in_array($payload['status'], $statuses, true)) {
            $errors['status'] = 'Select a valid status.';
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

            if ($editingId !== null && findInventoryItem($db, $editingId) === null) {
                $errors['general'] = 'The selected inventory item no longer exists.';
            }
        }

        if ($errors === []) {
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
                $formData = [
                    'item' => $existing['item'],
                    'sku' => $existing['sku'],
                    'part_number' => $existing['part_number'],
                    'finish' => $existing['finish'] ?? '',
                    'location' => $existing['location'],
                    'stock' => (string) $existing['stock'],
                    'status' => $existing['status'],
                    'supplier' => $existing['supplier'],
                    'supplier_contact' => $existing['supplier_contact'] ?? '',
                    'reorder_point' => (string) $existing['reorder_point'],
                    'lead_time_days' => (string) $existing['lead_time_days'],
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
            <a class="nav-item<?= $isActive ? ' active' : '' ?>" href="<?= e(nav_href($item)) ?>">
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

    <main class="content">
      <section class="panel" aria-labelledby="inventory-manager-title">
        <header class="panel-header">
          <div>
            <h1 id="inventory-manager-title">Inventory Items</h1>
            <p class="small">Track suppliers, lead times, and stock levels in one place.</p>
          </div>
          <div class="header-actions">
            <a class="button secondary" href="cycle-count.php">Start cycle count</a>
            <a class="button secondary" href="admin/estimate-check.php">Analyze EZ Estimate</a>
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
                  <a class="metric-link" href="jobs/reservations.php">
                    <?= e((string) $inventorySummary['active_reservations']) ?>
                  </a>
                </span>
              </div>
            </div>
          <?php endif; ?>
          <table class="table">
            <thead>
              <tr>
                <th scope="col">Item</th>
                <th scope="col">Part Number</th>
                <th scope="col">Finish</th>
                <th scope="col">SKU</th>
                <th scope="col">Location</th>
                <th scope="col">Stock</th>
                <th scope="col">Committed</th>
                <th scope="col">Available</th>
                <th scope="col">Reorder Point</th>
                <th scope="col">Supplier</th>
                <th scope="col">Contact</th>
                <th scope="col">Lead Time (days)</th>
                <th scope="col">Status</th>
                <th scope="col">Reservations</th>
                <th scope="col" class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($inventory === []): ?>
                <tr>
                  <td colspan="15" class="small">No inventory items found. Use the button above to add your first part.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($inventory as $row): ?>
                  <tr>
                    <td><?= e($row['item']) ?></td>
                    <td><?= e($row['part_number']) ?></td>
                    <td><?= e(inventoryFormatFinish($row['finish'])) ?></td>
                    <td><?= e($row['sku']) ?></td>
                    <td><?= e($row['location']) ?></td>
                    <td><span class="quantity-pill"><?= e(inventoryFormatQuantity($row['stock'])) ?></span></td>
                    <td><span class="quantity-pill brand"><?= e(inventoryFormatQuantity($row['committed_qty'])) ?></span></td>
                    <td>
                      <span class="quantity-pill <?= $row['available_qty'] <= 0 ? 'danger' : 'success' ?>">
                        <?= e(inventoryFormatQuantity($row['available_qty'])) ?>
                      </span>
                    </td>
                    <td><?= e(inventoryFormatQuantity($row['reorder_point'])) ?></td>
                    <td><?= e($row['supplier']) ?></td>
                    <td><?= e($row['supplier_contact'] ?? '—') ?></td>
                    <td><?= e((string) $row['lead_time_days']) ?></td>
                    <td>
                      <span class="status" data-level="<?= e($row['status']) ?>">
                        <?= e($row['status']) ?>
                      </span>
                    </td>
                    <td class="reservations">
                      <?php if ($row['active_reservations'] > 0): ?>
                        <a class="reservation-link" href="jobs/reservations.php?inventory_id=<?= e((string) $row['id']) ?>">
                          <?= e($row['active_reservations'] === 1 ? '1 active job' : $row['active_reservations'] . ' active jobs') ?>
                        </a>
                      <?php else: ?>
                        <span class="reservation-link muted">None</span>
                      <?php endif; ?>
                    </td>
                    <td class="actions">
                      <a class="button ghost" href="inventory.php?id=<?= e((string) $row['id']) ?>">Edit</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
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
        </div>

        <div class="field">
          <label for="status">Status<span aria-hidden="true">*</span></label>
          <select id="status" name="status" required>
            <?php foreach ($statuses as $status): ?>
              <option value="<?= e($status) ?>"<?= $formData['status'] === $status ? ' selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['status'])): ?>
            <p class="field-error"><?= e($errors['status']) ?></p>
          <?php endif; ?>
        </div>

        <footer>
          <a class="button secondary" href="inventory.php">Cancel</a>
          <button type="submit" class="button primary"><?= $editingId === null ? 'Create Item' : 'Update Item' ?></button>
        </footer>
      </form>
    </div>
  </div>

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
  </script>
</body>
</html>
