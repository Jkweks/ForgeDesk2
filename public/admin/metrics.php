<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/data/inventory_metrics.php';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Dashboard Metrics');
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$successMessage = null;
$metrics = [];
$editingMetric = null;
$formValues = [
    'label' => '',
    'value' => '',
    'delta' => null,
    'timeframe' => null,
    'accent' => false,
    'sort_order' => 100,
];

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null) {
    if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
        $editingMetric = inventoryMetricsFind($db, (int) $_GET['edit']);
        if ($editingMetric === null) {
            $errors['general'] = 'The requested metric could not be found.';
        } else {
            $formValues = $editingMetric;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'create';

        if ($action === 'delete') {
            $metricIdRaw = $_POST['id'] ?? '';
            if (!ctype_digit((string) $metricIdRaw)) {
                $errors['general'] = 'Invalid metric reference.';
            } else {
                $metricId = (int) $metricIdRaw;
                try {
                    inventoryMetricsDelete($db, $metricId);
                    $successMessage = 'Metric deleted successfully.';
                    if ($editingMetric !== null && $editingMetric['id'] === $metricId) {
                        $editingMetric = null;
                        $formValues = [
                            'label' => '',
                            'value' => '',
                            'delta' => null,
                            'timeframe' => null,
                            'accent' => false,
                            'sort_order' => 100,
                        ];
                    }
                } catch (\Throwable $exception) {
                    $errors['general'] = 'Unable to delete metric: ' . $exception->getMessage();
                }
            }
        } else {
            $label = trim((string) ($_POST['label'] ?? ''));
            $value = trim((string) ($_POST['value'] ?? ''));
            $delta = trim((string) ($_POST['delta'] ?? ''));
            $timeframe = trim((string) ($_POST['timeframe'] ?? ''));
            $accent = isset($_POST['accent']) && in_array((string) $_POST['accent'], ['1', 'on', 'true'], true);
            $sortOrderRaw = trim((string) ($_POST['sort_order'] ?? '100'));
            $sortOrder = filter_var($sortOrderRaw, FILTER_VALIDATE_INT);

            $formValues = [
                'label' => $label,
                'value' => $value,
                'delta' => $delta !== '' ? $delta : null,
                'timeframe' => $timeframe !== '' ? $timeframe : null,
                'accent' => $accent,
                'sort_order' => $sortOrder !== false ? $sortOrder : 100,
            ];

            if ($label === '') {
                $errors['label'] = 'Label is required.';
            }

            if ($value === '') {
                $errors['value'] = 'Value is required.';
            }

            if ($sortOrder === false) {
                $errors['sort_order'] = 'Sort order must be an integer.';
            }

            $metricId = null;
            if ($action === 'update') {
                $metricIdRaw = $_POST['id'] ?? '';
                if (!ctype_digit((string) $metricIdRaw)) {
                    $errors['general'] = 'Invalid metric reference.';
                } else {
                    $metricId = (int) $metricIdRaw;
                }
            }

            if ($errors === []) {
                $duplicateExists = inventoryMetricsLabelExists($db, $label, $metricId);
                if ($duplicateExists) {
                    $errors['label'] = 'A metric with this label already exists.';
                }
            }

            if ($errors === []) {
                $payload = [
                    'label' => $label,
                    'value' => $value,
                    'delta' => $delta !== '' ? $delta : null,
                    'timeframe' => $timeframe !== '' ? $timeframe : null,
                    'accent' => $accent,
                    'sort_order' => $sortOrder !== false ? $sortOrder : 100,
                ];

                try {
                    if ($action === 'update' && $metricId !== null) {
                        inventoryMetricsUpdate($db, $metricId, $payload);
                        $successMessage = 'Metric updated successfully.';
                        $editingMetric = inventoryMetricsFind($db, $metricId);
                        if ($editingMetric !== null) {
                            $formValues = $editingMetric;
                        }
                    } else {
                        $newId = inventoryMetricsCreate($db, $payload);
                        $successMessage = 'Metric added successfully.';
                        $editingMetric = inventoryMetricsFind($db, $newId);
                        if ($editingMetric !== null) {
                            $formValues = $editingMetric;
                        }
                    }
                } catch (\Throwable $exception) {
                    $errors['general'] = 'Unable to save metric: ' . $exception->getMessage();
                }
            }
        }
    }

    try {
        $metrics = inventoryMetricsList($db);
    } catch (\Throwable $exception) {
        $errors['general'] = 'Unable to load metrics: ' . $exception->getMessage();
        $metrics = [];
    }
}

$bodyAttributes = ' class="has-sidebar-toggle"';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> · Dashboard Metrics</title>
  <link rel="stylesheet" href="/css/dashboard.css" />
</head>
<body<?= $bodyAttributes ?>>
  <div class="layout">
    <?php require __DIR__ . '/../../app/views/partials/sidebar.php'; ?>

    <?php
    $topbarTitle = 'Dashboard Metrics';
    require __DIR__ . '/../../app/views/partials/topbar.php';
    unset($topbarTitle, $topbarSubhead, $topbarExtras);
    ?>

    <main class="content">
      <section class="panel" aria-labelledby="metrics-admin-title">
        <header class="panel-header">
          <div>
            <p class="eyebrow">Dashboard</p>
            <h1 id="metrics-admin-title">Dashboard Metrics</h1>
            <p class="small">Control the metric cards shown on the dashboard, including values, deltas, and sorting.</p>
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
                    <th scope="col">Label</th>
                    <th scope="col">Value</th>
                    <th scope="col">Delta</th>
                    <th scope="col">Timeframe</th>
                    <th scope="col">Accent</th>
                    <th scope="col" class="sortable" data-sort-key="sort" data-sort-type="number">Sort</th>
                    <th scope="col" class="actions">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($metrics === []): ?>
                    <tr><td colspan="7" class="small">No metrics defined yet. Use the form to add your first metric.</td></tr>
                  <?php else: ?>
                    <?php foreach ($metrics as $metric): ?>
                      <tr data-row data-sort="<?= e((string) $metric['sort_order']) ?>">
                        <td><strong><?= e($metric['label']) ?></strong></td>
                        <td><?= e($metric['value']) ?></td>
                        <td><?= $metric['delta'] !== null ? e($metric['delta']) : '<span class="muted">—</span>' ?></td>
                        <td><?= $metric['timeframe'] !== null ? e($metric['timeframe']) : '<span class="muted">—</span>' ?></td>
                        <td>
                          <span class="badge <?= $metric['accent'] ? 'success' : 'muted' ?>"><?= $metric['accent'] ? 'Yes' : 'No' ?></span>
                        </td>
                        <td class="numeric"><span class="quantity-pill"><?= e((string) $metric['sort_order']) ?></span></td>
                        <td class="actions">
                          <a class="button ghost" href="/admin/metrics.php?edit=<?= e((string) $metric['id']) ?>">Edit</a>
                          <form method="post" class="inline-form" onsubmit="return confirm('Delete this metric?');">
                            <input type="hidden" name="action" value="delete" />
                            <input type="hidden" name="id" value="<?= e((string) $metric['id']) ?>" />
                            <button type="submit" class="button ghost danger">Delete</button>
                          </form>
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
              <h2><?= $editingMetric !== null ? 'Update metric' : 'Add metric' ?></h2>
              <?php if ($editingMetric !== null): ?>
                <p class="small">Editing <strong><?= e($editingMetric['label']) ?></strong>. <a href="/admin/metrics.php">Cancel edit</a></p>
              <?php else: ?>
                <p class="small">Create dashboard cards with optional accent and timeframe notes.</p>
              <?php endif; ?>
            </div>

            <form method="post" class="form" novalidate>
              <input type="hidden" name="action" value="<?= $editingMetric !== null ? 'update' : 'create' ?>" />
              <?php if ($editingMetric !== null): ?>
                <input type="hidden" name="id" value="<?= e((string) $editingMetric['id']) ?>" />
              <?php endif; ?>

              <div class="field">
                <label for="metric-label">Label</label>
                <input id="metric-label" name="label" type="text" value="<?= e($formValues['label']) ?>" required />
                <?php if (!empty($errors['label'])): ?><p class="form-error"><?= e($errors['label']) ?></p><?php endif; ?>
              </div>

              <div class="field">
                <label for="metric-value">Value</label>
                <input id="metric-value" name="value" type="text" value="<?= e($formValues['value']) ?>" required />
                <?php if (!empty($errors['value'])): ?><p class="form-error"><?= e($errors['value']) ?></p><?php endif; ?>
              </div>

              <div class="field-group">
                <div class="field">
                  <label for="metric-delta">Delta</label>
                  <input id="metric-delta" name="delta" type="text" value="<?= e($formValues['delta'] ?? '') ?>" />
                </div>
                <div class="field">
                  <label for="metric-timeframe">Timeframe</label>
                  <input id="metric-timeframe" name="timeframe" type="text" value="<?= e($formValues['timeframe'] ?? '') ?>" />
                </div>
              </div>

              <div class="field-group">
                <div class="field checkbox">
                  <label class="checkbox">
                    <input type="checkbox" name="accent" value="1" <?= $formValues['accent'] ? 'checked' : '' ?> />
                    <span>Accent (highlight)</span>
                  </label>
                </div>
                <div class="field">
                  <label for="metric-sort-order">Sort order</label>
                  <input id="metric-sort-order" name="sort_order" type="number" value="<?= e((string) $formValues['sort_order']) ?>" />
                  <?php if (!empty($errors['sort_order'])): ?><p class="form-error"><?= e($errors['sort_order']) ?></p><?php endif; ?>
                </div>
              </div>

              <div class="form-actions">
                <button type="submit" class="button primary"><?= $editingMetric !== null ? 'Save changes' : 'Add metric' ?></button>
              </div>
            </form>
          </div>
        </div>
      </section>
    </main>
  </div>
  <script src="/js/dashboard.js"></script>
</body>
</html>
