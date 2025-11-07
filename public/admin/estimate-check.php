<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/data/inventory.php';
require_once __DIR__ . '/../../app/helpers/estimate_uploads.php';
require_once __DIR__ . '/../../app/services/estimate_check.php';
require_once __DIR__ . '/../../app/services/reservation_service.php';

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
$flashMessages = [
    'success' => [],
    'error' => [],
];
$commitSummary = null;
$formValues = [
    'job_number' => '',
    'job_name' => '',
    'requested_by' => '',
    'needed_by' => '',
    'notes' => '',
];
$action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['action'] ?? 'review')
    : 'review';

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

$supportsReservations = false;
if ($dbError === null && isset($db)) {
    try {
        $supportsReservations = inventorySupportsReservations($db);
    } catch (\Throwable $exception) {
        $supportsReservations = false;
    }
}

if ($dbError === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'commit') {
        $formValues = [
            'job_number' => trim((string) ($_POST['job_number'] ?? '')),
            'job_name' => trim((string) ($_POST['job_name'] ?? '')),
            'requested_by' => trim((string) ($_POST['requested_by'] ?? '')),
            'needed_by' => trim((string) ($_POST['needed_by'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];

        $postedUploadedName = isset($_POST['uploaded_name']) ? trim((string) $_POST['uploaded_name']) : '';
        if ($postedUploadedName !== '') {
            $uploadedName = $postedUploadedName;
        }

        $rawMessages = isset($_POST['analysis_messages']) ? (string) $_POST['analysis_messages'] : '';
        $analysisMessages = [];
        if ($rawMessages !== '') {
            $decodedMessages = json_decode($rawMessages, true);
            if (is_array($decodedMessages)) {
                foreach ($decodedMessages as $message) {
                    if (is_array($message) && isset($message['type'], $message['text'])) {
                        $analysisMessages[] = [
                            'type' => (string) $message['type'],
                            'text' => (string) $message['text'],
                        ];
                    }
                }
            }
        }

        $rawLines = isset($_POST['lines']) && is_array($_POST['lines']) ? $_POST['lines'] : [];
        $analysisItems = [];

        foreach ($rawLines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $partNumber = isset($line['part_number']) ? (string) $line['part_number'] : '';
            $finish = isset($line['finish']) && $line['finish'] !== '' ? (string) $line['finish'] : null;
            $required = isset($line['required']) ? (int) $line['required'] : 0;
            $available = isset($line['available']) && $line['available'] !== '' ? (int) $line['available'] : null;
            $shortfall = isset($line['shortfall']) ? (int) $line['shortfall'] : null;
            $status = isset($line['status']) ? (string) $line['status'] : 'missing';
            if (!in_array($status, ['available', 'short', 'missing'], true)) {
                $status = 'missing';
            }
            $inventoryId = isset($line['inventory_item_id']) ? (int) $line['inventory_item_id'] : null;
            if ($inventoryId !== null && $inventoryId <= 0) {
                $inventoryId = null;
            }
            $committedQty = isset($line['committed_qty']) && $line['committed_qty'] !== ''
                ? (int) $line['committed_qty']
                : null;
            $sku = isset($line['sku']) && $line['sku'] !== '' ? (string) $line['sku'] : null;

            $required = max(0, $required);
            $available = $available !== null ? max(0, $available) : null;
            $shortfall = $shortfall !== null ? max(0, $shortfall) : max(0, $required - ($available ?? 0));

            $analysisItems[] = [
                'part_number' => $partNumber,
                'finish' => $finish,
                'required' => $required,
                'available' => $available,
                'shortfall' => $shortfall,
                'status' => $status,
                'sku' => $sku,
                'inventory_item_id' => $inventoryId,
                'committed_qty' => $committedQty,
            ];
        }

        $recount = static function (array $items): array {
            $counts = ['total' => 0, 'available' => 0, 'short' => 0, 'missing' => 0];
            foreach ($items as $item) {
                $counts['total']++;
                $status = $item['status'] ?? 'missing';
                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
            }

            return $counts;
        };

        $analysis = [
            'items' => $analysisItems,
            'messages' => $analysisMessages,
            'counts' => $recount($analysisItems),
        ];

        if (!$supportsReservations) {
            $errors[] = 'Job reservations are not available in this environment.';
        }

        if ($formValues['job_number'] === '') {
            $errors[] = 'Enter a job number before committing inventory.';
        }

        if ($formValues['job_name'] === '') {
            $errors[] = 'Enter a job name before committing inventory.';
        }

        if ($formValues['requested_by'] === '') {
            $errors[] = 'Enter who requested the reservation.';
        }

        $neededByNormalized = null;
        if ($formValues['needed_by'] !== '') {
            $neededDate = \DateTimeImmutable::createFromFormat('Y-m-d', $formValues['needed_by']);
            if ($neededDate === false) {
                $errors[] = 'Provide a valid "Needed by" date (YYYY-MM-DD).';
            } else {
                $neededByNormalized = $neededDate->format('Y-m-d');
            }
        }

        $eligibleIndices = [];
        foreach ($analysisItems as $index => $item) {
            $inventoryId = $item['inventory_item_id'];
            $available = $item['available'];
            $requiredQty = $item['required'];
            if (
                $inventoryId !== null
                && $inventoryId > 0
                && $requiredQty > 0
                && $available !== null
                && $available >= $requiredQty
                && $item['status'] === 'available'
            ) {
                $eligibleIndices[] = $index;
            }
        }

        $commitAction = isset($_POST['commit_action']) ? (string) $_POST['commit_action'] : 'selected';
        $selectedIndices = [];

        if ($commitAction === 'all') {
            $selectedIndices = $eligibleIndices;
        } else {
            $selectedRaw = isset($_POST['selected']) ? (array) $_POST['selected'] : [];
            foreach ($selectedRaw as $value) {
                $index = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($index === false) {
                    continue;
                }

                if (in_array($index, $eligibleIndices, true) && !in_array($index, $selectedIndices, true)) {
                    $selectedIndices[] = $index;
                }
            }
        }

        if ($eligibleIndices === []) {
            $errors[] = 'None of the reviewed lines are ready to reserve. Re-run the analysis to refresh availability.';
        } elseif ($selectedIndices === []) {
            $errors[] = 'Select at least one line item that is ready to commit.';
        }

        if ($errors === []) {
            $lineItemsForReservation = [];
            foreach ($selectedIndices as $selectedIndex) {
                $item = $analysisItems[$selectedIndex];
                $lineItemsForReservation[] = [
                    'inventory_item_id' => (int) $item['inventory_item_id'],
                    'requested_qty' => (int) $item['required'],
                    'commit_qty' => (int) $item['required'],
                    'part_number' => $item['part_number'],
                    'finish' => $item['finish'],
                    'sku' => $item['sku'],
                ];
            }

            try {
                $result = reservationCommitItems($db, [
                    'job_number' => $formValues['job_number'],
                    'job_name' => $formValues['job_name'],
                    'requested_by' => $formValues['requested_by'],
                    'needed_by' => $neededByNormalized,
                    'notes' => $formValues['notes'],
                ], $lineItemsForReservation);

                $commitSummary = $result;

                $totalCommitted = 0;
                foreach ($result['items'] as $reservedItem) {
                    $totalCommitted += (int) $reservedItem['committed_qty'];
                    foreach ($analysisItems as &$analysisItem) {
                        if ((int) ($analysisItem['inventory_item_id'] ?? 0) === (int) $reservedItem['inventory_item_id']) {
                            $analysisItem['available'] = $reservedItem['available_after'];
                            $analysisItem['shortfall'] = max(0, $analysisItem['required'] - $analysisItem['available']);
                            $analysisItem['committed_qty'] = ($analysisItem['committed_qty'] ?? 0) + (int) $reservedItem['committed_qty'];
                            $analysisItem['just_committed'] = true;
                        }
                    }
                    unset($analysisItem);
                }

                $analysis['items'] = $analysisItems;
                $analysis['counts'] = $recount($analysisItems);

                $flashMessages['success'][] = sprintf(
                    'Committed %d unit%s to job %s.',
                    $totalCommitted,
                    $totalCommitted === 1 ? '' : 's',
                    $result['job_number']
                );
            } catch (\Throwable $exception) {
                $errors[] = 'Unable to commit the selected items: ' . $exception->getMessage();
            }
        }
    } else {
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

        <?php foreach ($flashMessages['success'] as $message): ?>
          <div class="alert success" role="status">
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
          <?php
          $messagesJson = json_encode($analysis['messages'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          if ($messagesJson === false) {
              $messagesJson = '[]';
          }
          ?>
          <form method="post" class="form commit-form" id="commit-form" novalidate>
            <input type="hidden" name="action" value="commit" />
            <input type="hidden" name="uploaded_name" value="<?= e((string) ($uploadedName ?? '')) ?>" />
            <input type="hidden" name="analysis_messages" value='<?= e($messagesJson) ?>' />
            <?php foreach ($analysis['items'] as $index => $item): ?>
              <input type="hidden" name="lines[<?= e((string) $index) ?>][part_number]" value="<?= e($item['part_number']) ?>" />
              <input type="hidden" name="lines[<?= e((string) $index) ?>][finish]" value="<?= e((string) ($item['finish'] ?? '')) ?>" />
              <input type="hidden" name="lines[<?= e((string) $index) ?>][required]" value="<?= e((string) $item['required']) ?>" />
              <input type="hidden" name="lines[<?= e((string) $index) ?>][available]" value="<?= $item['available'] !== null ? e((string) $item['available']) : '' ?>" />
              <input type="hidden" name="lines[<?= e((string) $index) ?>][shortfall]" value="<?= e((string) $item['shortfall']) ?>" />
              <input type="hidden" name="lines[<?= e((string) $index) ?>][status]" value="<?= e($item['status']) ?>" />
              <input type="hidden" name="lines[<?= e((string) $index) ?>][sku]" value="<?= e((string) ($item['sku'] ?? '')) ?>" />
              <input type="hidden" name="lines[<?= e((string) $index) ?>][inventory_item_id]" value="<?= e((string) ($item['inventory_item_id'] ?? '')) ?>" />
              <input type="hidden" name="lines[<?= e((string) $index) ?>][committed_qty]" value="<?= isset($item['committed_qty']) ? e((string) $item['committed_qty']) : '' ?>" />
            <?php endforeach; ?>

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

            <fieldset class="reservation-metadata">
              <legend>Reservation Details</legend>
              <div class="field-grid">
                <div class="field">
                  <label for="job_number">Job Number<span aria-hidden="true">*</span></label>
                  <input type="text" id="job_number" name="job_number" value="<?= e($formValues['job_number']) ?>" required <?= !$supportsReservations ? 'disabled' : '' ?> />
                </div>
                <div class="field">
                  <label for="job_name">Job Name<span aria-hidden="true">*</span></label>
                  <input type="text" id="job_name" name="job_name" value="<?= e($formValues['job_name']) ?>" required <?= !$supportsReservations ? 'disabled' : '' ?> />
                </div>
                <div class="field">
                  <label for="requested_by">Requested By<span aria-hidden="true">*</span></label>
                  <input type="text" id="requested_by" name="requested_by" value="<?= e($formValues['requested_by']) ?>" required <?= !$supportsReservations ? 'disabled' : '' ?> />
                </div>
                <div class="field">
                  <label for="needed_by">Needed By</label>
                  <input type="date" id="needed_by" name="needed_by" value="<?= e($formValues['needed_by']) ?>" <?= !$supportsReservations ? 'disabled' : '' ?> />
                </div>
              </div>
              <div class="field">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="2" <?= !$supportsReservations ? 'disabled' : '' ?>><?= e($formValues['notes']) ?></textarea>
              </div>
            </fieldset>

            <?php if (!$supportsReservations): ?>
              <p class="small muted" role="note">Reservation commits are currently disabled because job reservation tables are unavailable.</p>
            <?php endif; ?>

            <div class="table-wrapper">
              <table class="table" aria-label="EZ Estimate comparison results">
                <thead>
                  <tr>
                    <th scope="col" class="select-col">
                      <input type="checkbox" id="select-all" <?= !$supportsReservations ? 'disabled' : '' ?> />
                      <label class="sr-only" for="select-all">Select all available lines</label>
                    </th>
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
                      <td colspan="8" class="small">No material requests were found in the provided ranges.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($analysis['items'] as $index => $item): ?>
                      <?php
                      $finishLabel = $item['finish'] !== null ? $item['finish'] : '—';
                      $available = $item['available'];
                      $statusLabel = ucfirst($item['status']);
                      $isEligible = $supportsReservations
                        && $item['inventory_item_id'] !== null
                        && $item['status'] === 'available'
                        && $item['available'] !== null
                        && $item['available'] >= $item['required']
                        && $item['required'] > 0;
                      $rowClasses = [];
                      if (!empty($item['just_committed'])) {
                          $rowClasses[] = 'just-committed';
                      }
                      ?>
                      <tr<?= $rowClasses !== [] ? ' class="' . e(implode(' ', $rowClasses)) . '"' : '' ?>>
                        <td class="select-cell">
                          <input type="checkbox" class="line-select" name="selected[]" value="<?= e((string) $index) ?>" id="select-line-<?= e((string) $index) ?>" <?= $isEligible ? '' : 'disabled' ?> />
                          <label class="sr-only" for="select-line-<?= e((string) $index) ?>">Select <?= e($item['part_number']) ?> <?= e($finishLabel) ?></label>
                        </td>
                        <td><?= e($item['part_number']) ?></td>
                        <td><?= e($finishLabel) ?></td>
                        <td><?= e($item['sku'] ?? '—') ?></td>
                        <td><?= e((string) $item['required']) ?></td>
                        <td><?= e($available !== null ? (string) $available : '—') ?></td>
                        <td><?= e((string) $item['shortfall']) ?></td>
                        <td>
                          <span class="status" data-level="<?= e($statusLabel) ?>"><?= e($statusLabel) ?></span>
                          <?php if (!empty($item['just_committed'])): ?>
                            <span class="badge success">Committed</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="reservation-actions">
              <button type="submit" class="button secondary" name="commit_action" value="selected" <?= !$supportsReservations ? 'disabled' : '' ?>>Commit Selected</button>
              <button type="submit" class="button primary" name="commit_action" value="all" <?= !$supportsReservations ? 'disabled' : '' ?>>Commit All Ready Lines</button>
            </div>
          </form>

          <?php if ($commitSummary !== null): ?>
            <div class="reservation-summary" role="status" aria-live="polite">
              <h2>Reservation committed</h2>
              <p><strong><?= e($commitSummary['job_number']) ?></strong> · <?= e($commitSummary['job_name']) ?></p>
              <ul>
                <?php foreach ($commitSummary['items'] as $item): ?>
                  <li>
                    <span class="sku"><?= e($item['sku']) ?></span>
                    <span>— <?= e((string) $item['committed_qty']) ?> committed (<?= e((string) $item['available_after']) ?> remaining)</span>
                  </li>
                <?php endforeach; ?>
              </ul>
              <a class="badge link" href="job-tracker.php?job=<?= urlencode($commitSummary['job_number']) ?>">View reservation details</a>
            </div>
          <?php endif; ?>
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
  <script>
    (function () {
      const commitForm = document.getElementById('commit-form');
      if (!commitForm) {
        return;
      }

      const selectAll = commitForm.querySelector('#select-all');
      const lineCheckboxes = Array.prototype.slice.call(commitForm.querySelectorAll('.line-select'));

      if (selectAll) {
        selectAll.addEventListener('change', function () {
          lineCheckboxes.forEach(function (checkbox) {
            if (checkbox.disabled) {
              return;
            }

            checkbox.checked = selectAll.checked;
          });
          selectAll.indeterminate = false;
        });
      }

      const syncSelectAll = function () {
        if (!selectAll) {
          return;
        }

        const enabled = lineCheckboxes.filter(function (checkbox) {
          return !checkbox.disabled;
        });

        if (enabled.length === 0) {
          selectAll.checked = false;
          selectAll.indeterminate = false;
          return;
        }

        const checkedCount = enabled.filter(function (checkbox) {
          return checkbox.checked;
        }).length;

        if (checkedCount === 0) {
          selectAll.checked = false;
          selectAll.indeterminate = false;
        } else if (checkedCount === enabled.length) {
          selectAll.checked = true;
          selectAll.indeterminate = false;
        } else {
          selectAll.indeterminate = true;
        }
      };

      lineCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', syncSelectAll);
      });

      syncSelectAll();
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
