<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/data/storage_locations.php';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Storage Locations');
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$successMessage = null;
$locations = [];
$locationPreview = '';

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $aisle = trim((string) ($_POST['aisle'] ?? ''));
            $rack = trim((string) ($_POST['rack'] ?? ''));
            $shelf = trim((string) ($_POST['shelf'] ?? ''));
            $bin = trim((string) ($_POST['bin'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($aisle === '') {
                $errors['aisle'] = 'Aisle is required.';
            }

            $locationPreview = storageLocationFormatName([
                'aisle' => $aisle,
                'rack' => $rack,
                'shelf' => $shelf,
                'bin' => $bin,
            ], 'Unspecified location');

            if ($errors === []) {
                try {
                    storageLocationsCreate($db, [
                        'name' => $locationPreview,
                        'description' => $description !== '' ? $description : null,
                        'aisle' => $aisle !== '' ? $aisle : null,
                        'rack' => $rack !== '' ? $rack : null,
                        'shelf' => $shelf !== '' ? $shelf : null,
                        'bin' => $bin !== '' ? $bin : null,
                    ]);
                    $successMessage = 'Storage location added successfully.';
                    $locationPreview = '';
                } catch (\Throwable $exception) {
                    $errors['general'] = 'Unable to create location: ' . $exception->getMessage();
                }
            }
        } elseif ($action === 'toggle') {
            $idRaw = $_POST['id'] ?? '';
            $state = $_POST['state'] ?? 'activate';

            if (!ctype_digit((string) $idRaw)) {
                $errors['general'] = 'Invalid location reference.';
            } else {
                $locationId = (int) $idRaw;
                $activate = $state === 'activate';

                try {
                    storageLocationsSetActive($db, $locationId, $activate);
                    $successMessage = $activate ? 'Location activated.' : 'Location deactivated.';
                } catch (\Throwable $exception) {
                    $errors['general'] = 'Unable to update location: ' . $exception->getMessage();
                }
            }
        }
    }

    try {
        $locations = storageLocationsList($db, true);
    } catch (\Throwable $exception) {
        $errors['general'] = 'Unable to load locations: ' . $exception->getMessage();
        $locations = [];
    }
}

$bodyClasses = ['has-sidebar-toggle'];
$bodyAttributes = ' class="' . implode(' ', $bodyClasses) . '"';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> · Storage Locations</title>
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
        <input type="search" placeholder="Search locations" aria-label="Search locations" />
      </div>
    </header>

    <main class="content">
      <section class="panel" aria-labelledby="location-admin-title">
        <header class="panel-header">
          <div>
            <p class="eyebrow">Inventory Settings</p>
            <h1 id="location-admin-title">Storage Locations</h1>
            <p class="small">Define the zones, aisles, or racks that inventory items can be assigned to when managing stock.</p>
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

          <div class="location-admin-grid">
          <div class="location-list">
            <div class="location-table">
              <div class="location-table__header">
                <div>Location</div>
                <div>Description</div>
                <div class="numeric">Items</div>
                <div>Status</div>
                <div>Actions</div>
              </div>
              <?php if ($locations === []): ?>
                <p class="small">No storage locations defined yet. Use the form to add your first location.</p>
              <?php else: ?>
                <?php foreach ($locations as $location): ?>
                  <div class="location-table__row">
                    <div>
                      <strong><?= e($location['display_name']) ?></strong>
                      <p class="small muted">
                        <?= e($location['aisle'] !== null ? 'Aisle ' . $location['aisle'] : 'No aisle') ?>
                        <?php if ($location['rack'] !== null): ?> · <?= e('Rack ' . $location['rack']) ?><?php endif; ?>
                        <?php if ($location['shelf'] !== null): ?> · <?= e('Shelf ' . $location['shelf']) ?><?php endif; ?>
                        <?php if ($location['bin'] !== null): ?> · <?= e('Bin ' . $location['bin']) ?><?php endif; ?>
                      </p>
                    </div>
                    <div>
                      <?= $location['description'] !== null ? e($location['description']) : '<span class="muted">No description</span>' ?>
                    </div>
                    <div class="numeric"><?= e((string) $location['assigned_items']) ?></div>
                    <div>
                      <span class="badge <?= $location['is_active'] ? 'success' : 'danger' ?>">
                        <?= $location['is_active'] ? 'Active' : 'Inactive' ?>
                      </span>
                    </div>
                    <div>
                      <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="toggle" />
                        <input type="hidden" name="id" value="<?= e((string) $location['id']) ?>" />
                        <input type="hidden" name="state" value="<?= $location['is_active'] ? 'deactivate' : 'activate' ?>" />
                        <button type="submit" class="button ghost">
                          <?= $location['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="location-form">
            <h2>Add storage location</h2>
            <form method="post" class="form" novalidate>
              <input type="hidden" name="action" value="create" />
              <div class="location-component-grid">
                <div class="field">
                  <label for="location-aisle">Aisle</label>
                  <input type="text" id="location-aisle" name="aisle" value="<?= e((string) ($_POST['aisle'] ?? '')) ?>" required />
                  <?php if (!empty($errors['aisle'])): ?>
                    <p class="field-error"><?= e($errors['aisle']) ?></p>
                  <?php endif; ?>
                </div>
                <div class="field">
                  <label for="location-rack">Rack <span class="optional">Optional</span></label>
                  <input type="text" id="location-rack" name="rack" value="<?= e((string) ($_POST['rack'] ?? '')) ?>" />
                </div>
                <div class="field">
                  <label for="location-shelf">Shelf <span class="optional">Optional</span></label>
                  <input type="text" id="location-shelf" name="shelf" value="<?= e((string) ($_POST['shelf'] ?? '')) ?>" />
                </div>
                <div class="field">
                  <label for="location-bin">Bin <span class="optional">Optional</span></label>
                  <input type="text" id="location-bin" name="bin" value="<?= e((string) ($_POST['bin'] ?? '')) ?>" />
                </div>
              </div>
              <p class="small muted">Names are generated automatically, e.g., Aisle A · Rack 1 · Shelf 3 · Bin 4 → <strong><?= e($locationPreview !== '' ? $locationPreview : 'A.1.3.4') ?></strong></p>
              <div class="field">
                <label for="location-description">Description <span class="optional">Optional</span></label>
                <textarea id="location-description" name="description" rows="3" placeholder="Aisle or bay notes"><?= e((string) ($_POST['description'] ?? '')) ?></textarea>
              </div>
              <button type="submit" class="button primary">Add location</button>
            </form>
          </div>
        </div>
      </section>
    </main>
  </div>
  <script src="/js/dashboard.js"></script>
</body>
</html>
