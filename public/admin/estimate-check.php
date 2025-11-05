<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/data/inventory.php';
require_once __DIR__ . '/../../app/services/estimate_check.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
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

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'EZ Estimate Check');
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$analysis = null;
$uploadedName = null;

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['estimate']) || !is_uploaded_file($_FILES['estimate']['tmp_name'])) {
        $errors[] = 'Select an EZ Estimate workbook to review.';
    } else {
        /** @var array{tmp_name:string,name:string,error:int} $file */
        $file = $_FILES['estimate'];
        $uploadedName = $file['name'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file is larger than the form allows.',
                UPLOAD_ERR_PARTIAL => 'The file upload did not complete. Try again.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            ];

            $errors[] = $uploadErrors[$file['error']] ?? 'Failed to upload the workbook. Please try again.';
        } else {
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($extension !== 'xlsx') {
                $errors[] = 'Please upload an .xlsx workbook exported from Excel.';
            } else {
                try {
                    $analysis = analyzeEstimateRequirements($db, $file['tmp_name']);
                } catch (\Throwable $exception) {
                    $errors[] = 'Unable to process the workbook: ' . $exception->getMessage();
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
  <title><?= e($app['name']) ?> · EZ Estimate Check</title>
  <link rel="stylesheet" href="../css/dashboard.css" />
</head>
<body>
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
      <section class="panel" aria-labelledby="estimate-title">
        <header class="panel-header">
          <div>
            <h1 id="estimate-title">EZ Estimate Review</h1>
            <p class="small">Upload a job takeoff to see what inventory can ship today.</p>
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

        <?php if ($analysis !== null && $errors === []): ?>
          <div class="alert success" role="status">
            <?= e(($uploadedName ?? 'Workbook') . ' analysed successfully.') ?>
          </div>
        <?php endif; ?>

        <p>Upload the EZ Estimate spreadsheet generated for a project. The tool reads the Accessories and Stock Lengths tabs and compares the requested quantities and finishes with current on-hand stock.</p>

        <form method="post" enctype="multipart/form-data" class="form" novalidate>
          <div class="field">
            <label for="estimate">EZ Estimate Workbook<span aria-hidden="true">*</span></label>
            <input type="file" id="estimate" name="estimate" accept=".xlsx" required <?= $dbError !== null ? 'disabled' : '' ?> />
            <p class="field-help">Accepted format: .xlsx. Pages checked: Accessories (1-3) and Stock Lengths (1-3).</p>
          </div>

          <div class="field submit">
            <button type="submit" class="button primary" <?= $dbError !== null ? 'disabled' : '' ?>>Analyze Requirements</button>
          </div>
        </form>

        <?php if ($analysis !== null): ?>
          <div class="import-summary">
            <div class="summary-card">
              <h3>Lines Reviewed</h3>
              <strong><?= e((string) $analysis['counts']['total']) ?></strong>
            </div>
            <div class="summary-card">
              <h3>Ready to Fulfill</h3>
              <strong><?= e((string) $analysis['counts']['available']) ?></strong>
            </div>
            <div class="summary-card">
              <h3>Need Attention</h3>
              <strong><?= e((string) $analysis['counts']['short']) ?></strong>
            </div>
            <div class="summary-card">
              <h3>Missing Items</h3>
              <strong><?= e((string) $analysis['counts']['missing']) ?></strong>
            </div>
          </div>

          <?php if ($analysis['messages'] !== []): ?>
            <ul class="message-list">
              <?php foreach ($analysis['messages'] as $message): ?>
                <li data-type="<?= e($message['type']) ?>"><?= e($message['text']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <div class="table-wrapper">
            <table class="table" aria-label="EZ Estimate comparison results">
              <thead>
                <tr>
                  <th scope="col">Part Number</th>
                  <th scope="col">Finish</th>
                  <th scope="col">SKU</th>
                  <th scope="col">Required Qty</th>
                  <th scope="col">Available Qty</th>
                  <th scope="col">Shortfall</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($analysis['items'] === []): ?>
                  <tr>
                    <td colspan="7" class="small">No material requests were found in the provided ranges.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($analysis['items'] as $item): ?>
                    <?php
                    $finishLabel = $item['finish'] !== null ? $item['finish'] : '—';
                    $available = $item['available'];
                    $statusLabel = ucfirst($item['status']);
                    ?>
                    <tr>
                      <td><?= e($item['part_number']) ?></td>
                      <td><?= e($finishLabel) ?></td>
                      <td><?= e($item['sku'] ?? '—') ?></td>
                      <td><?= e((string) $item['required']) ?></td>
                      <td><?= e($available !== null ? (string) $available : '—') ?></td>
                      <td><?= e((string) $item['shortfall']) ?></td>
                      <td>
                        <span class="status" data-level="<?= e($statusLabel) ?>"><?= e($statusLabel) ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>
</body>
</html>
