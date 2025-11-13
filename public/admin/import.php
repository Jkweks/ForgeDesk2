<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/data/inventory.php';
require_once __DIR__ . '/../../app/services/inventory_seed.php';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Data Seeding');
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$importResult = null;
$uploadedName = null;

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['spreadsheet']) || !is_uploaded_file($_FILES['spreadsheet']['tmp_name'])) {
        $errors[] = 'Select an Excel workbook to import.';
    } else {
        /** @var array{tmp_name:string,name:string,error:int} $file */
        $file = $_FILES['spreadsheet'];
        $uploadedName = $file['name'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file is larger than the form allows.',
                UPLOAD_ERR_PARTIAL => 'The file upload did not complete. Try again.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            ];

            $errors[] = $uploadErrors[$file['error']] ?? 'Failed to upload the spreadsheet. Please try again.';
        } else {
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($extension !== 'xlsx') {
                $errors[] = 'Please upload an .xlsx workbook exported from Microsoft Excel.';
            } else {
                try {
                    $importResult = seedInventoryFromXlsx($db, $file['tmp_name']);
                } catch (\Throwable $exception) {
                    $errors[] = 'Import failed: ' . $exception->getMessage();
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> · Data Seeding</title>
  <link rel="stylesheet" href="../css/dashboard.css" />
</head>
<body>
  <div class="layout">
    <?php require __DIR__ . '/../../app/views/partials/sidebar.php'; ?>

    <main class="content">
      <section class="panel" aria-labelledby="import-title">
        <header class="panel-header">
          <div>
            <h1 id="import-title">Seed Inventory from Excel</h1>
            <p class="small">Load part masters, finish codes, and stocking targets in bulk.</p>
          </div>
        </header>

        <?php if ($dbError !== null): ?>
          <div class="alert error" role="alert">
            <strong>Database connection issue:</strong> <?= e($dbError) ?>
          </div>
        <?php endif; ?>

        <?php foreach ($errors as $message): ?>
          <div class="alert error" role="alert">
            <?= e($message) ?>
          </div>
        <?php endforeach; ?>

        <?php if ($importResult !== null && $errors === []): ?>
          <div class="alert success" role="status">
            <?= e(($uploadedName ?? 'Your workbook') . ' processed successfully.') ?>
          </div>
        <?php endif; ?>

        <p>Use the importer to load existing catalogs without manual data entry. The spreadsheet should include headers for:</p>
        <ul>
          <li>Item, Part Number, Finish</li>
          <li>Location, Stock, Reorder Point, Lead Time (days)</li>
          <li>Supplier, Supplier Contact (Status optional; set to "Discontinued" to keep manual)</li>
        </ul>

        <form method="post" enctype="multipart/form-data" class="form" novalidate>
          <div class="field">
            <label for="spreadsheet">Excel Workbook<span aria-hidden="true">*</span></label>
            <input type="file" id="spreadsheet" name="spreadsheet" accept=".xlsx" required <?= $dbError !== null ? 'disabled' : '' ?> />
            <p class="field-help">Accepted format: .xlsx (Excel 2007+). The first worksheet will be imported.</p>
          </div>

          <div class="field submit">
            <button type="submit" class="button primary" <?= $dbError !== null ? 'disabled' : '' ?>>Import Inventory</button>
          </div>
        </form>

        <?php if ($importResult !== null): ?>
          <div class="import-summary">
            <div class="summary-card">
              <h3>Processed Rows</h3>
              <strong><?= e((string) $importResult['processed']) ?></strong>
            </div>
            <div class="summary-card">
              <h3>Inserted</h3>
              <strong><?= e((string) $importResult['inserted']) ?></strong>
            </div>
            <div class="summary-card">
              <h3>Updated</h3>
              <strong><?= e((string) $importResult['updated']) ?></strong>
            </div>
            <div class="summary-card">
              <h3>Skipped</h3>
              <strong><?= e((string) $importResult['skipped']) ?></strong>
            </div>
          </div>

          <?php if ($importResult['messages'] !== []): ?>
            <ul class="message-list">
              <?php foreach ($importResult['messages'] as $message): ?>
                <li data-type="<?= e($message['type']) ?>"><?= e($message['text']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if ($importResult['preview'] !== []): ?>
            <table class="preview-table" aria-label="Imported rows preview">
              <thead>
                <tr>
                  <th scope="col">Row</th>
                  <th scope="col">Item</th>
                  <th scope="col">SKU</th>
                  <th scope="col">Status</th>
                  <th scope="col">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($importResult['preview'] as $entry): ?>
                  <tr>
                    <td><?= e((string) $entry['row']) ?></td>
                    <td><?= e($entry['item']) ?></td>
                    <td><?= e($entry['sku']) ?></td>
                    <td><?= e($entry['status']) ?></td>
                    <td><?= e($entry['action']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </main>
  </div>
</body>
</html>
