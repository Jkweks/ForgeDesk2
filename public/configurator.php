<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/configurator.php';

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$successMessage = null;
$configurations = [];
$jobs = [];
$statusOptions = ['draft', 'in_progress', 'released'];
$configFormData = [
    'name' => '',
    'job_id' => '',
    'status' => 'draft',
    'notes' => '',
];
$editingConfigId = null;
$builderSteps = [
    ['id' => 'configuration', 'label' => 'Configuration data', 'description' => 'Name, job, and lifecycle status'],
    ['id' => 'entry', 'label' => 'Entry data', 'description' => 'Opening type, handing, and measurements'],
    ['id' => 'frame', 'label' => 'Frame data', 'description' => 'Profiles, anchors, and accessories (if required)'],
    ['id' => 'door', 'label' => 'Door data', 'description' => 'Leaf construction and lite kit details (if required)'],
    ['id' => 'hardware', 'label' => 'Door hardware data', 'description' => 'Sets, preps, and templated routing'],
    ['id' => 'summary', 'label' => 'Summary & cut list', 'description' => 'Bill of materials and cut information'],
];
$stepIds = array_map(static fn (array $step): string => $step['id'], $builderSteps);
$currentStep = 'configuration';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Door Configurator');
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
        try {
            $jobs = configuratorListJobs($db);
        } catch (\Throwable $exception) {
            $errors[] = 'Unable to load jobs: ' . $exception->getMessage();
            $jobs = [];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';

            if (isset($_POST['builder_step']) && in_array($_POST['builder_step'], $stepIds, true)) {
                $currentStep = (string) $_POST['builder_step'];
            }

            if ($action === 'create_job') {
                $jobNumber = trim((string) ($_POST['job_number'] ?? ''));
                $jobName = trim((string) ($_POST['job_name'] ?? ''));

            if ($jobNumber === '') {
                $errors['job_number'] = 'Job number is required.';
            }

            if ($jobName === '') {
                $errors['job_name'] = 'Job name is required.';
            }

            if (!isset($errors['job_number']) && !isset($errors['job_name'])) {
                try {
                    configuratorCreateJob($db, $jobNumber, $jobName);
                    $successMessage = 'Job added to configurator directory.';
                    header('Location: configurator.php?success=job');
                    exit;
                } catch (\PDOException $exception) {
                    if ($exception->getCode() === '23505') {
                        $errors['job_number'] = 'Job number must be unique.';
                    } else {
                        $errors['general'] = 'Unable to save job: ' . $exception->getMessage();
                    }
                }
            }
        } elseif ($action === 'save_configuration') {
            $configName = trim((string) ($_POST['config_name'] ?? ''));
            $jobIdRaw = trim((string) ($_POST['config_job_id'] ?? ''));
            $configStatus = trim((string) ($_POST['config_status'] ?? 'draft'));
            $configNotes = trim((string) ($_POST['config_notes'] ?? ''));

            $configFormData = [
                'name' => $configName,
                'job_id' => $jobIdRaw,
                'status' => $configStatus,
                'notes' => $configNotes,
            ];

            if ($configName === '') {
                $errors['config_name'] = 'Configuration name is required.';
            }

            $jobId = null;
            if ($jobIdRaw !== '') {
                if (!ctype_digit($jobIdRaw)) {
                    $errors['config_job_id'] = 'Select a valid job or leave blank.';
                } else {
                    $jobId = (int) $jobIdRaw;
                    $jobIds = array_map(static fn (array $job): int => (int) $job['id'], $jobs);
                    if (!in_array($jobId, $jobIds, true)) {
                        $errors['config_job_id'] = 'Select a valid job or leave blank.';
                    }
                }
            }

            if (!in_array($configStatus, $statusOptions, true)) {
                $errors['config_status'] = 'Select a valid status.';
                $configFormData['status'] = 'draft';
            }

            $configIdRaw = trim((string) ($_POST['config_id'] ?? ''));
            if ($configIdRaw !== '') {
                if (ctype_digit($configIdRaw)) {
                    $editingConfigId = (int) $configIdRaw;
                } else {
                    $errors['general'] = 'Invalid configuration selected for update.';
                }
            }

            if ($errors === []) {
                $payload = [
                    'name' => $configName,
                    'job_id' => $jobId,
                    'status' => $configStatus,
                    'notes' => $configNotes !== '' ? $configNotes : null,
                ];

                try {
                    if ($editingConfigId !== null) {
                        configuratorUpdateConfiguration($db, $editingConfigId, $payload);
                        header('Location: configurator.php?success=updated');
                    } else {
                        configuratorCreateConfiguration($db, $payload);
                        header('Location: configurator.php?success=created');
                    }

                    exit;
                } catch (\Throwable $exception) {
                    $errors['general'] = 'Unable to save configuration: ' . $exception->getMessage();
                }
            }
        }
    } elseif (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) {
        $editingConfigId = (int) $_GET['id'];
        try {
            $existingConfig = configuratorFindConfiguration($db, $editingConfigId);
            if ($existingConfig !== null) {
                $configFormData = [
                    'name' => $existingConfig['name'],
                    'job_id' => $existingConfig['job_id'] !== null ? (string) $existingConfig['job_id'] : '',
                    'status' => $existingConfig['status'],
                    'notes' => $existingConfig['notes'] ?? '',
                ];
            } else {
                $editingConfigId = null;
                $errors['general'] = 'The requested configuration could not be found.';
            }
        } catch (\Throwable $exception) {
            $errors['general'] = 'Unable to load configuration: ' . $exception->getMessage();
            $editingConfigId = null;
        }
    }

    if (isset($_GET['step']) && in_array($_GET['step'], $stepIds, true)) {
        $currentStep = (string) $_GET['step'];
    }

    try {
        $configurations = configuratorListConfigurations($db);
    } catch (\Throwable $exception) {
        $errors[] = 'Unable to load configurations: ' . $exception->getMessage();
        $configurations = [];
    }
}

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'created') {
        $successMessage = 'Configuration saved to the catalog.';
    } elseif ($_GET['success'] === 'updated') {
        $successMessage = 'Configuration updated.';
    } elseif ($_GET['success'] === 'job') {
        $successMessage = 'Job added to the configurator directory.';
    }
}

$editorMode = ($editingConfigId !== null)
    || (($_POST['action'] ?? '') === 'save_configuration')
    || (isset($_GET['create']) && $_GET['create'] === '1');

if ($editorMode && !in_array($currentStep, $stepIds, true)) {
    $currentStep = 'configuration';
}

$bodyAttributes = ' class="has-sidebar-toggle"';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> Door Configurator</title>
  <link rel="stylesheet" href="css/dashboard.css" />
</head>
<body<?= $bodyAttributes ?>>
  <div class="layout">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <header class="topbar">
      <button
        class="topbar-toggle"
        type="button"
        data-sidebar-toggle
        aria-controls="app-sidebar"
        aria-expanded="false"
        aria-label="Toggle navigation"
      >
        <span aria-hidden="true"><?= icon('menu') ?></span>
      </button>
      <form class="search" role="search" aria-label="Configurator search">
        <span aria-hidden="true"><?= icon('search') ?></span>
        <input type="search" name="q" placeholder="Search configurations" />
      </form>
      <button class="user" type="button">
        <span class="user-avatar" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
        <span class="user-email"><?= e($app['user']['email']) ?></span>
        <span aria-hidden="true"><?= icon('chev') ?></span>
      </button>
    </header>

    <main class="content">
      <section class="panel" aria-labelledby="configurator-title">
        <header class="panel-header">
          <div>
            <h1 id="configurator-title">Door &amp; Frame Configurator</h1>
            <p class="small">Capture bill-of-material templates, tie them to jobs, and keep required parts organized.</p>
          </div>
          <div class="header-actions">
            <a class="button primary" href="configurator.php?create=1&step=configuration">Add configuration</a>
            <?php if ($editingConfigId !== null): ?>
              <a class="button secondary" href="configurator.php">Exit edit</a>
            <?php endif; ?>
          </div>
        </header>

        <?php if ($dbError !== null): ?>
          <div class="alert error" role="alert">
            <strong>Database connection issue:</strong> <?= e($dbError) ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
          <div class="alert error" role="alert"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <?php if ($successMessage !== null): ?>
          <div class="alert success" role="status"><?= e($successMessage) ?></div>
        <?php endif; ?>

        <?php if ($editorMode): ?>
          <?php $activeIndex = array_search($currentStep, $stepIds, true); ?>
          <section class="panel" aria-labelledby="configurator-builder-title">
            <header class="panel-header">
              <div>
                <h2 id="configurator-builder-title">Configuration builder</h2>
                <p class="small">Step <?= e((string) (($activeIndex === false ? 0 : $activeIndex) + 1)) ?> of <?= e((string) count($builderSteps)) ?> · <?= e(ucwords(str_replace('_', ' ', $currentStep))) ?></p>
              </div>
              <div class="header-actions">
                <a class="button secondary" href="configurator.php">Return to list</a>
                <?php if ($editingConfigId !== null): ?>
                  <span class="pill">Editing #<?= e((string) $editingConfigId) ?></span>
                <?php endif; ?>
              </div>
            </header>

            <ol class="stepper">
              <?php foreach ($builderSteps as $index => $step): ?>
                <?php
                  $state = $step['id'] === $currentStep
                    ? 'current'
                    : ($index < ($activeIndex === false ? 0 : $activeIndex) ? 'complete' : 'upcoming');
                ?>
                <li class="step <?= e($state) ?>">
                  <div class="step-label"><?= e($step['label']) ?></div>
                  <p class="small muted"><?= e($step['description']) ?></p>
                </li>
              <?php endforeach; ?>
            </ol>

            <?php if ($currentStep === 'configuration'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="save_configuration" />
                <input type="hidden" name="builder_step" value="<?= e($currentStep) ?>" />
                <?php if ($editingConfigId !== null): ?>
                  <input type="hidden" name="config_id" value="<?= e((string) $editingConfigId) ?>" />
                <?php endif; ?>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="config_name">Configuration Name<span aria-hidden="true">*</span></label>
                    <input type="text" id="config_name" name="config_name" value="<?= e($configFormData['name']) ?>" required />
                    <?php if (!empty($errors['config_name'])): ?>
                      <p class="field-error"><?= e($errors['config_name']) ?></p>
                    <?php endif; ?>
                  </div>

                  <div class="field">
                    <label for="config_job_id">Job (optional)</label>
                    <select id="config_job_id" name="config_job_id">
                      <option value="">Unassigned</option>
                      <?php foreach ($jobs as $job): ?>
                        <option value="<?= e((string) $job['id']) ?>"<?= $configFormData['job_id'] !== '' && (int) $configFormData['job_id'] === (int) $job['id'] ? ' selected' : '' ?>>
                          <?= e($job['job_number']) ?> — <?= e($job['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if (!empty($errors['config_job_id'])): ?>
                      <p class="field-error"><?= e($errors['config_job_id']) ?></p>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="config_status">Status</label>
                    <select id="config_status" name="config_status">
                      <?php foreach ($statusOptions as $option): ?>
                        <option value="<?= e($option) ?>"<?= $configFormData['status'] === $option ? ' selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $option))) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php if (!empty($errors['config_status'])): ?>
                      <p class="field-error"><?= e($errors['config_status']) ?></p>
                    <?php endif; ?>
                  </div>

                  <div class="field">
                    <label for="config_notes">Notes</label>
                    <textarea id="config_notes" name="config_notes" rows="3" placeholder="Add scope, opening counts, or prep details."><?= e($configFormData['notes']) ?></textarea>
                  </div>
                </div>

                <div class="form-actions">
                  <button type="submit" class="button primary">Save configuration</button>
                  <a class="button ghost" href="configurator.php">Cancel</a>
                </div>
              </form>
            <?php else: ?>
              <div class="card muted">
                <p class="small">This step will capture <?= e(strtolower($builderSteps[array_search($currentStep, $stepIds, true)]['label'] ?? 'additional')) ?> details. Content for this stage is coming in the next update.</p>
              </div>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th scope="col">Configuration</th>
                <th scope="col">Job</th>
                <th scope="col">Status</th>
                <th scope="col">Updated</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($configurations === []): ?>
                <tr>
                  <td colspan="5" class="muted">No configurations yet. Add one to start building a template.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($configurations as $configuration): ?>
                  <tr>
                    <td><?= e($configuration['name']) ?></td>
                    <td>
                      <?php if ($configuration['job_number'] !== null): ?>
                        <div class="stacked">
                          <strong><?= e($configuration['job_number']) ?></strong>
                          <span class="muted"><?= e($configuration['job_name'] ?? '') ?></span>
                        </div>
                      <?php else: ?>
                        <span class="muted">Unassigned</span>
                      <?php endif; ?>
                    </td>
                    <td><span class="pill"><?= e(ucwords(str_replace('_', ' ', $configuration['status']))) ?></span></td>
                    <td class="muted"><?= e(date('M j, Y', strtotime($configuration['updated_at']))) ?></td>
                    <td>
                      <a class="button ghost" href="configurator.php?id=<?= e((string) $configuration['id']) ?>&step=configuration">Edit</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel">
        <header class="panel-header">
          <div>
            <h2>Jobs</h2>
            <p class="small">Add job numbers and names for associating templates.</p>
          </div>
        </header>

        <form method="post" class="form inline-form" novalidate>
          <input type="hidden" name="action" value="create_job" />
          <div class="field-grid two-column">
            <div class="field">
              <label for="job_number">Job Number<span aria-hidden="true">*</span></label>
              <input type="text" id="job_number" name="job_number" value="<?= e($_POST['job_number'] ?? '') ?>" />
              <?php if (!empty($errors['job_number'])): ?>
                <p class="field-error"><?= e($errors['job_number']) ?></p>
              <?php endif; ?>
            </div>
            <div class="field">
              <label for="job_name">Job Name<span aria-hidden="true">*</span></label>
              <input type="text" id="job_name" name="job_name" value="<?= e($_POST['job_name'] ?? '') ?>" />
              <?php if (!empty($errors['job_name'])): ?>
                <p class="field-error"><?= e($errors['job_name']) ?></p>
              <?php endif; ?>
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="button secondary">Add job</button>
          </div>
        </form>

        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th scope="col">Job Number</th>
                <th scope="col">Name</th>
                <th scope="col">Added</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($jobs === []): ?>
                <tr>
                  <td colspan="3" class="muted">No jobs have been added yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($jobs as $job): ?>
                  <tr>
                    <td><?= e($job['job_number']) ?></td>
                    <td><?= e($job['name']) ?></td>
                    <td class="muted"><?= e(date('M j, Y', strtotime($job['created_at']))) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

</body>
</html>
