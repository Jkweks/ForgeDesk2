<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/helpers/xlsx.php';
require_once __DIR__ . '/../../app/services/ez_estimate_templates.php';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] ?? '') === 'EZ Estimate Template';
    }
}
unset($groupItems, $item);

$errors = [];
$successMessage = null;
$templatePath = null;
$templateModified = null;
$usingUploaded = false;
$multipliers = [];
$multipliersError = null;

$templatePath = ezEstimateActiveTemplatePath();
$usingUploaded = is_file(ezEstimateUploadedTemplatePath());
if (is_file($templatePath)) {
    $templateModified = filemtime($templatePath) ?: null;
}

try {
    $multipliers = ezEstimateLoadMultipliers($templatePath);
} catch (\Throwable $exception) {
    $multipliersError = $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_template') {
        if (!isset($_FILES['template'])) {
            $errors['upload'] = 'Select a template workbook to upload.';
        } else {
            try {
                ezEstimateStoreTemplateUpload($_FILES['template']);
                $successMessage = 'EZ Estimate template uploaded successfully.';
                $templatePath = ezEstimateActiveTemplatePath();
                $usingUploaded = is_file(ezEstimateUploadedTemplatePath());
                $templateModified = is_file($templatePath) ? (filemtime($templatePath) ?: null) : null;
                $multipliers = ezEstimateLoadMultipliers($templatePath);
                $multipliersError = null;
            } catch (\Throwable $exception) {
                $errors['upload'] = $exception->getMessage();
            }
        }
    } elseif ($action === 'update_multipliers') {
        if ($multipliersError !== null) {
            $errors['multipliers'] = 'Unable to load multipliers from the template.';
        } else {
            $updates = [];
            foreach ($multipliers as $multiplier) {
                $rowNumber = $multiplier['row'];
                $input = isset($_POST['multipliers'][$rowNumber]) ? trim((string) $_POST['multipliers'][$rowNumber]) : '';
                if ($input === '') {
                    $errors['multipliers'] = 'All multiplier values are required.';
                    break;
                }

                $normalized = str_replace(',', '', $input);
                $value = filter_var($normalized, FILTER_VALIDATE_FLOAT);
                if ($value === false) {
                    $errors['multipliers'] = sprintf('Enter a numeric value for %s.', $multiplier['label']);
                    break;
                }

                $updates[$rowNumber] = (float) $value;
            }

            if ($errors === []) {
                try {
                    ezEstimateUpdateMultipliers($updates);
                    $successMessage = 'Multipliers updated successfully.';
                    $multipliers = ezEstimateLoadMultipliers(ezEstimateActiveTemplatePath());
                    $multipliersError = null;
                } catch (\Throwable $exception) {
                    $errors['general'] = $exception->getMessage();
                }
            }
        }
    }
}

render('components/header', ['title' => 'EZ Estimate Template · ' . $app['name'], 'nav' => $nav]);
?>
<body>
  <?php render('components/topbar', ['title' => 'EZ Estimate Template']); ?>
  <div class="page">
    <?php render('components/sidebar', ['nav' => $nav]); ?>

    <main class="content">
      <?php if (!empty($errors['general'])): ?>
        <div class="callout danger"><?= e($errors['general']) ?></div>
      <?php endif; ?>
      <?php if (!empty($successMessage)): ?>
        <div class="callout success"><?= e($successMessage) ?></div>
      <?php endif; ?>

      <header class="page-title">
        <div>
          <p class="eyebrow">Admin</p>
          <h1>EZ Estimate template</h1>
          <p class="muted">Upload a replacement workbook for Tubelite EZ Estimate exports and adjust the MULTIPLIERS sheet.</p>
        </div>
      </header>

      <section class="card-grid two-column">
        <div class="card">
          <header class="card-header">
            <div>
              <p class="eyebrow">Template</p>
              <h2>Active workbook</h2>
            </div>
          </header>
          <div class="card-body">
            <dl class="definition-list">
              <div>
                <dt>Status</dt>
                <dd>
                  <?php if ($usingUploaded): ?>
                    <span class="badge success">Custom upload</span>
                  <?php else: ?>
                    <span class="badge">Default template</span>
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <dt>Location</dt>
                <dd class="mono small"><?= e((string) $templatePath) ?></dd>
              </div>
              <div>
                <dt>Last updated</dt>
                <dd>
                  <?php if ($templateModified !== null): ?>
                    <?= e(date('Y-m-d H:i', $templateModified)) ?>
                  <?php else: ?>
                    <span class="muted">Unknown</span>
                  <?php endif; ?>
                </dd>
              </div>
            </dl>
            <?php if (!empty($errors['upload'])): ?>
              <div class="callout danger"><?= e($errors['upload']) ?></div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="form" novalidate>
              <input type="hidden" name="action" value="upload_template" />
              <div class="field">
                <label for="template">Upload new template</label>
                <input type="file" name="template" id="template" accept=".xlsm,.xlsx" required />
                <p class="small muted">Accepted formats: .xlsm or .xlsx. The file will replace the current template used for Tubelite exports.</p>
              </div>
              <button type="submit" class="button secondary">Upload template</button>
            </form>
          </div>
        </div>

        <div class="card">
          <header class="card-header">
            <div>
              <p class="eyebrow">Multipliers</p>
              <h2>Update MULTIPLIERS sheet</h2>
            </div>
          </header>
          <div class="card-body">
            <?php if ($multipliersError !== null): ?>
              <div class="callout danger"><?= e($multipliersError) ?></div>
            <?php endif; ?>
            <?php if (!empty($errors['multipliers'])): ?>
              <div class="callout danger"><?= e($errors['multipliers']) ?></div>
            <?php endif; ?>
            <form method="post" class="form" novalidate>
              <input type="hidden" name="action" value="update_multipliers" />
              <div class="table" role="grid" aria-label="Multipliers">
                <div class="table-row table-head">
                  <div>Label</div>
                  <div class="numeric">Value (D column)</div>
                </div>
                <?php if ($multipliers === []): ?>
                  <div class="table-row">
                    <div colspan="2" class="muted">No multipliers were found in the template.</div>
                  </div>
                <?php else: ?>
                  <?php foreach ($multipliers as $multiplier): ?>
                    <div class="table-row">
                      <div>
                        <strong><?= e($multiplier['label']) ?></strong>
                        <p class="muted small">Row <?= e((string) $multiplier['row']) ?> · Cell D<?= e((string) $multiplier['row']) ?></p>
                      </div>
                      <div class="numeric">
                        <input type="number" step="0.000001" name="multipliers[<?= e((string) $multiplier['row']) ?>]" value="<?= $multiplier['value'] !== null ? e((string) $multiplier['value']) : '' ?>" required />
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <p class="small muted">Updates are written directly to the MULTIPLIERS sheet (cells D4:D12). Labels come from column C.</p>
              <button type="submit" class="button primary" <?= $multipliersError !== null ? 'disabled' : '' ?>>Save multipliers</button>
            </form>
          </div>
        </div>
      </section>
    </main>
  </div>
  <script src="/js/dashboard.js"></script>
</body>
</html>
