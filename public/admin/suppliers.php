<?php
declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/data/suppliers.php';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Suppliers');
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$successMessage = null;
$suppliers = [];
$editingSupplier = null;

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null) {
    try {
        $suppliers = suppliersList($db);
    } catch (\Throwable $exception) {
        $dbError = $exception->getMessage();
        $suppliers = [];
    }

    if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
        $supplierId = (int) $_GET['edit'];
        $editingSupplier = suppliersFind($db, $supplierId);
        if ($editingSupplier === null) {
            $errors['general'] = 'The requested supplier could not be found.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbError === null) {
        $action = $_POST['action'] ?? 'create';
        $name = trim((string) ($_POST['name'] ?? ''));
        $contactName = trim((string) ($_POST['contact_name'] ?? ''));
        $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
        $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
        $leadTimeRaw = trim((string) ($_POST['default_lead_time_days'] ?? '0'));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Supplier name is required.';
        }

        $leadTimeDays = filter_var($leadTimeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($leadTimeDays === false) {
            $errors['default_lead_time_days'] = 'Lead time must be a non-negative integer.';
        }

        if ($errors === []) {
            $payload = [
                'name' => $name,
                'contact_name' => $contactName !== '' ? $contactName : null,
                'contact_email' => $contactEmail !== '' ? $contactEmail : null,
                'contact_phone' => $contactPhone !== '' ? $contactPhone : null,
                'default_lead_time_days' => $leadTimeDays !== false ? $leadTimeDays : 0,
                'notes' => $notes !== '' ? $notes : null,
            ];

            try {
                if ($action === 'update' && isset($_POST['id']) && ctype_digit((string) $_POST['id'])) {
                    $supplierId = (int) $_POST['id'];
                    suppliersUpdate($db, $supplierId, $payload);
                    $successMessage = 'Supplier updated successfully.';
                    $editingSupplier = suppliersFind($db, $supplierId);
                } else {
                    $newId = suppliersCreate($db, $payload);
                    $successMessage = 'Supplier added successfully.';
                    $editingSupplier = suppliersFind($db, $newId);
                }

                $suppliers = suppliersList($db);
            } catch (\Throwable $exception) {
                $errors['general'] = 'Unable to save supplier: ' . $exception->getMessage();
            }
        }
    }
}

$bodyAttributes = ' class="has-sidebar-toggle"';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> · Suppliers</title>
  <link rel="stylesheet" href="/css/dashboard.css" />
</head>
<body<?= $bodyAttributes ?>>
  <div class="layout">
    <?php require __DIR__ . '/../../app/views/partials/sidebar.php'; ?>

    <header class="topbar">
      <button class="topbar-toggle" type="button" data-sidebar-toggle aria-controls="app-sidebar" aria-label="Toggle navigation">
        <?= icon('menu') ?>
      </button>
      <div class="search">
        <?= icon('search') ?>
        <input type="search" placeholder="Search suppliers" aria-label="Search suppliers" />
      </div>
    </header>

    <main class="content">
      <section class="panel" aria-labelledby="supplier-admin-title">
        <header class="panel-header">
          <div>
            <p class="eyebrow">Purchasing</p>
            <h1 id="supplier-admin-title">Suppliers</h1>
            <p class="small">Manage vendor contacts, lead times, and notes used across purchase orders.</p>
          </div>
        </header>

        <?php if ($dbError !== null): ?>
          <div class="alert error" role="alert">Database connection failed: <?= e($dbError) ?></div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
          <div class="alert error" role="alert"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <?php if ($successMessage !== null): ?>
          <div class="alert success" role="status"><?= e($successMessage) ?></div>
        <?php endif; ?>

        <div class="admin-grid">
          <div>
            <div class="table-wrapper">
              <table class="table" data-sortable-table>
                <thead>
                  <tr>
                    <th scope="col" class="sortable" data-sort-key="name">Name</th>
                    <th scope="col">Contact</th>
                    <th scope="col">Email</th>
                    <th scope="col">Phone</th>
                    <th scope="col" class="sortable" data-sort-key="lead" data-sort-type="number">Lead Time (days)</th>
                    <th scope="col">Notes</th>
                    <th scope="col" class="actions">Actions</th>
                  </tr>
                  <tr class="filter-row">
                    <th><input type="search" class="column-filter" data-key="name" placeholder="Filter name" aria-label="Filter by name"></th>
                    <th><input type="search" class="column-filter" data-key="contact" placeholder="Filter contact" aria-label="Filter by contact"></th>
                    <th><input type="search" class="column-filter" data-key="email" placeholder="Filter email" aria-label="Filter by email"></th>
                    <th><input type="search" class="column-filter" data-key="phone" placeholder="Filter phone" aria-label="Filter by phone"></th>
                    <th><input type="search" class="column-filter" data-key="lead" placeholder="Lead days" aria-label="Filter by lead time" inputmode="numeric"></th>
                    <th><input type="search" class="column-filter" data-key="notes" placeholder="Filter notes" aria-label="Filter by notes"></th>
                    <th aria-hidden="true"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($suppliers === []): ?>
                    <tr><td colspan="7" class="small">No suppliers defined yet. Use the form to add your first supplier.</td></tr>
                  <?php else: ?>
                    <?php foreach ($suppliers as $supplier): ?>
                      <tr
                        data-row
                        data-name="<?= e($supplier['name']) ?>"
                        data-contact="<?= e($supplier['contact_name'] ?? '') ?>"
                        data-email="<?= e($supplier['contact_email'] ?? '') ?>"
                        data-phone="<?= e($supplier['contact_phone'] ?? '') ?>"
                        data-lead="<?= e((string) ($supplier['default_lead_time_days'] ?? 0)) ?>"
                        data-notes="<?= e($supplier['notes'] ?? '') ?>"
                      >
                        <td><strong><?= e($supplier['name']) ?></strong></td>
                        <td><?= $supplier['contact_name'] !== null ? e($supplier['contact_name']) : '<span class="muted">—</span>' ?></td>
                        <td><?= $supplier['contact_email'] !== null ? e($supplier['contact_email']) : '<span class="muted">—</span>' ?></td>
                        <td><?= $supplier['contact_phone'] !== null ? e($supplier['contact_phone']) : '<span class="muted">—</span>' ?></td>
                        <td class="numeric"><span class="quantity-pill"><?= e((string) ($supplier['default_lead_time_days'] ?? 0)) ?></span></td>
                        <td><?= $supplier['notes'] !== null ? e($supplier['notes']) : '<span class="muted">—</span>' ?></td>
                        <td class="actions">
                          <a class="button ghost" href="/admin/suppliers.php?edit=<?= e((string) $supplier['id']) ?>">Edit</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <h2><?= $editingSupplier !== null ? 'Update supplier' : 'Add supplier' ?></h2>
              <?php if ($editingSupplier !== null): ?>
                <a class="button ghost" href="/admin/suppliers.php">Clear</a>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="<?= $editingSupplier !== null ? 'update' : 'create' ?>" />
                <?php if ($editingSupplier !== null): ?>
                  <input type="hidden" name="id" value="<?= e((string) $editingSupplier['id']) ?>" />
                <?php endif; ?>

                <div class="field">
                  <label for="name">Supplier name<span aria-hidden="true">*</span></label>
                  <input type="text" id="name" name="name" value="<?= e($editingSupplier['name'] ?? '') ?>" required />
                  <?php if (!empty($errors['name'])): ?>
                    <p class="field-error"><?= e($errors['name']) ?></p>
                  <?php endif; ?>
                </div>

                <div class="field-grid">
                  <div class="field">
                    <label for="contact_name">Contact name</label>
                    <input type="text" id="contact_name" name="contact_name" value="<?= e($editingSupplier['contact_name'] ?? '') ?>" />
                  </div>
                  <div class="field">
                    <label for="contact_email">Contact email</label>
                    <input type="email" id="contact_email" name="contact_email" value="<?= e($editingSupplier['contact_email'] ?? '') ?>" />
                  </div>
                </div>

                <div class="field-grid">
                  <div class="field">
                    <label for="contact_phone">Contact phone</label>
                    <input type="text" id="contact_phone" name="contact_phone" value="<?= e($editingSupplier['contact_phone'] ?? '') ?>" />
                  </div>
                  <div class="field">
                    <label for="default_lead_time_days">Default lead time (days)</label>
                    <input type="number" id="default_lead_time_days" name="default_lead_time_days" min="0" value="<?= e((string) ($editingSupplier['default_lead_time_days'] ?? 0)) ?>" />
                    <?php if (!empty($errors['default_lead_time_days'])): ?>
                      <p class="field-error"><?= e($errors['default_lead_time_days']) ?></p>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="field">
                  <label for="notes">Notes</label>
                  <textarea id="notes" name="notes" rows="3" placeholder="Key terms, shipping preferences, or portals."><?= e($editingSupplier['notes'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="button primary">Save supplier</button>
              </form>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
  <script src="/js/dashboard.js"></script>
  <script src="/js/sortable-table.js"></script>
</body>
</html>
