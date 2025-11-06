<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/data/inventory.php';
require_once __DIR__ . '/../../app/helpers/estimate_uploads.php';
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
$analysisLog = [];
$uploadedName = null;

$logUpload = static function (string $message) use (&$analysisLog): void {
    $analysisLog[] = [
        'message' => $message,
        'at' => 0.0,
    ];
};

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawUploadId = isset($_POST['upload_id']) ? (string) $_POST['upload_id'] : null;
    $normalizedUploadId = null;

    if ($rawUploadId !== null && trim($rawUploadId) !== '') {
        $normalizedUploadId = estimate_upload_sanitize_id($rawUploadId);

        if ($normalizedUploadId === null) {
            $errors[] = 'The uploaded workbook reference was invalid. Please try again.';
            $logUpload(sprintf('Received invalid upload identifier "%s".', $rawUploadId));
        }
    }

    $runAnalyzer = static function (string $path) use (&$analysis, &$analysisLog, &$errors, &$uploadedName, $db, $logUpload): void {
        try {
            $analysis = analyzeEstimateRequirements($db, $path);
            $analysisLog = $analysis['log'] ?? [];
        } catch (\Throwable $exception) {
            if ($exception instanceof EstimateAnalysisException) {
                $analysisLog = $exception->getLog();
            }

            $errors[] = 'Unable to process the workbook: ' . $exception->getMessage();
            $logUpload('Analyzer exception: ' . $exception->getMessage());
        }
    };

    if ($normalizedUploadId !== null && $errors === []) {
        $paths = estimate_upload_paths($normalizedUploadId);
        $metadata = estimate_upload_load_metadata($normalizedUploadId) ?? [];
        $assembledPath = $paths['file'];

        if (!is_file($assembledPath)) {
            $errors[] = 'The uploaded workbook could not be assembled. Please try again.';
            $logUpload(sprintf('Chunked upload "%s" is missing the assembled workbook.', $normalizedUploadId));
        } else {
            $uploadedName = isset($metadata['name']) && is_string($metadata['name'])
                ? $metadata['name']
                : ($uploadedName ?? basename($assembledPath));

            if (isset($metadata['size'], $metadata['chunks'])) {
                $logUpload(sprintf(
                    'Chunked upload %s assembled at %s bytes across %s chunk(s).',
                    $normalizedUploadId,
                    number_format((int) $metadata['size']),
                    (string) $metadata['chunks']
                ));
            }

            $logUpload(sprintf('Executing analyzer against chunked upload %s (%s).', $normalizedUploadId, $uploadedName));
            $runAnalyzer($assembledPath);
        }

        estimate_upload_cleanup($normalizedUploadId);
    } elseif ($errors === []) {
        if (!isset($_FILES['estimate'])) {
            $errors[] = 'Select an EZ Estimate workbook to review.';
            $logUpload('Upload payload did not include an "estimate" file field.');
        } else {
            /** @var array{tmp_name?:string,name?:string,error?:int} $file */
            $file = $_FILES['estimate'];
            $uploadedName = $file['name'] ?? null;
            $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;

            if ($errorCode !== UPLOAD_ERR_OK) {
                $logUpload(sprintf('Upload rejected with PHP error code %d.', $errorCode));

                $limit = ini_get('upload_max_filesize');
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => sprintf('The uploaded file exceeds the server upload limit (%s).', $limit ?: 'unknown'),
                    UPLOAD_ERR_FORM_SIZE => 'The uploaded file is larger than the form allows.',
                    UPLOAD_ERR_PARTIAL => 'The file upload did not complete. Try again.',
                    UPLOAD_ERR_NO_FILE => 'Select an EZ Estimate workbook to review.',
                ];

                $errors[] = $uploadErrors[$errorCode] ?? 'Failed to upload the workbook. Please try again.';
            } elseif (empty($file['tmp_name']) || !is_string($file['tmp_name'])) {
                $errors[] = 'The uploaded file could not be accessed. Please try again.';
                $logUpload('Upload metadata did not include a valid tmp_name for the workbook.');
            } elseif (!is_uploaded_file($file['tmp_name'])) {
                $errors[] = 'The uploaded file could not be verified. Please try again.';
                $logUpload(sprintf('tmp_name "%s" failed the is_uploaded_file check.', $file['tmp_name']));
            } else {
                $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

                if ($extension !== 'xlsx') {
                    $errors[] = 'Please upload an .xlsx workbook exported from Excel.';
                    $logUpload(sprintf('Rejected "%s" because the extension is not .xlsx.', $file['name'] ?? 'unknown file'));
                } else {
                    $runAnalyzer($file['tmp_name']);
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
          <input type="hidden" name="upload_id" id="upload_id" value="" />
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
  <script>
    (function () {
      const form = document.querySelector('form.form');
      if (!form) {
        return;
      }

      if (typeof window.fetch !== 'function' || typeof FormData === 'undefined') {
        return;
      }

      const fileInput = form.querySelector('#estimate');
      const uploadIdInput = form.querySelector('#upload_id');
      const submitButton = form.querySelector('button[type="submit"]');
      let bypassSubmit = false;

      const generateId = function () {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
          return crypto.randomUUID();
        }

        const bytes = new Uint8Array(16);

        if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
          crypto.getRandomValues(bytes);
        } else {
          for (let i = 0; i < bytes.length; i += 1) {
            bytes[i] = Math.floor(Math.random() * 256);
          }
        }

        return Array.from(bytes).map(function (value) {
          return value.toString(16).padStart(2, '0');
        }).join('');
      };

      form.addEventListener('submit', async function (event) {
        if (bypassSubmit) {
          bypassSubmit = false;
          return;
        }

        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
          return;
        }

        event.preventDefault();

        const file = fileInput.files[0];
        const chunkSize = 1024 * 1024; // 1 MiB chunks stay under the 2 MB limit per request
        const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
        const uploadId = generateId();

        if (submitButton) {
          submitButton.disabled = true;
        }

        form.classList.add('is-uploading');

        try {
          for (let index = 0; index < totalChunks; index += 1) {
            const start = index * chunkSize;
            const end = Math.min(file.size, start + chunkSize);
            const chunk = file.slice(start, end);

            const payload = new FormData();
            payload.append('upload_id', uploadId);
            payload.append('chunk_index', String(index));
            payload.append('total_chunks', String(totalChunks));
            payload.append('file_name', file.name);
            payload.append('chunk', chunk, file.name);

            const response = await fetch('estimate-upload.php', {
              method: 'POST',
              body: payload,
            });

            if (!response.ok) {
              throw new Error('Upload failed with status ' + response.status + '.');
            }

            const json = await response.json().catch(function () {
              return null;
            });

            if (!json || json.status !== 'ok') {
              const message = json && typeof json.error === 'string'
                ? json.error
                : 'Upload failed. Please try again.';
              throw new Error(message);
            }
          }

          if (uploadIdInput) {
            uploadIdInput.value = uploadId;
          }

          fileInput.value = '';
          bypassSubmit = true;
          form.submit();
        } catch (error) {
          console.error('Chunked upload failed:', error);

          if (uploadIdInput) {
            uploadIdInput.value = '';
          }

          const message = error instanceof Error && error.message
            ? error.message
            : 'Upload failed. Please try again.';

          window.alert(message);
        } finally {
          if (submitButton) {
            submitButton.disabled = false;
          }

          form.classList.remove('is-uploading');
        }
      });
    }());
  </script>
  <?php if ($analysisLog !== []): ?>
    <script>
      (function (logEntries, label) {
        if (!window.console || !Array.isArray(logEntries)) {
          return;
        }

        var groupLabel = 'Estimate analysis · ' + label;
        var openedGroup = false;
        if (console.groupCollapsed) {
          console.groupCollapsed(groupLabel);
          openedGroup = true;
        } else if (console.group) {
          console.group(groupLabel);
          openedGroup = true;
        } else {
          console.log(groupLabel);
        }

        for (var i = 0; i < logEntries.length; i++) {
          var entry = logEntries[i] || {};
          var message = typeof entry.message === 'string' ? entry.message : '';
          var at = typeof entry.at === 'number' ? entry.at : null;
          var suffix = at !== null ? (' +' + at.toFixed(3) + 's') : '';
          console.log(message + suffix);
        }

        if (openedGroup && console.groupEnd) {
          console.groupEnd();
        }
      })(<?= json_encode($analysisLog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($uploadedName ?? 'Workbook') ?>);
    </script>
  <?php endif; ?>
</body>
</html>
