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
$doorTagTemplates = [];
$statusOptions = ['draft', 'in_progress', 'released'];
$jobScopeOptions = configuratorJobScopes();
$configFormData = [
    'name' => '',
    'job_id' => '',
    'job_scope' => 'door_and_frame',
    'quantity' => 1,
    'status' => 'draft',
    'notes' => '',
    'door_tags' => [],
];
$entryFormData = [
    'opening_type' => 'single',
    'hand_single' => 'LH - Inswing',
    'hand_pair' => 'RHRA',
    'door_glazing' => '1/4”',
    'transom' => 'no',
    'transom_glazing' => '1/4”',
    'elevation' => '',
    'opening' => '',
    'notes' => '',
];
$frameFormData = [
    'material' => 'aluminum',
    'profile' => 'narrow',
    'anchor_type' => 'screw_anchor',
    'head_condition' => 'standard_head',
    'sill_condition' => 'standard_sill',
    'notes' => '',
];
$doorFormData = [
    'door_type' => 'aluminum_stile',
    'thickness' => '1-3/4"',
    'core' => 'honeycomb',
    'lite_kit' => 'half_lite',
    'bottom_rail' => 'standard',
    'notes' => '',
];
$hardwareFormData = [
    'set_name' => 'Standard',
    'hinge_prep' => 'template_hinge',
    'strike_prep' => 'asa_strike',
    'closer' => 'surface',
    'electrified' => 'no',
    'notes' => '',
];
$summaryNotes = '';
$openingTypeOptions = [
    'single' => 'Single',
    'pair' => 'Pair',
];
$handOptionsPair = [
    'RHRA' => 'RHRA',
    'LHRA' => 'LHRA',
];
$handOptionsSingle = [
    'LH - Inswing' => 'LH - Inswing',
    'LHR - RH Outswing' => 'LHR - RH Outswing',
    'RH - Inswing' => 'RH - Inswing',
    'RHR - LH Outswing' => 'RHR - LH Outswing',
];
$glazingOptions = [
    '1/4”',
    '3/8”',
    '1/2”',
    '9/16”',
    '1”',
];
$transomOptions = [
    'yes' => 'Yes',
    'no' => 'No',
];
$frameMaterialOptions = [
    'aluminum' => 'Aluminum',
    'steel' => 'Steel',
    'wood' => 'Wood',
];
$frameProfileOptions = [
    'narrow' => 'Narrow stile',
    'medium' => 'Medium stile',
    'wide' => 'Wide stile',
];
$frameAnchorOptions = [
    'screw_anchor' => 'Screw anchor',
    'expansion_bolt' => 'Expansion bolt',
    'weld_plate' => 'Weld plate',
];
$headConditionOptions = [
    'standard_head' => 'Standard head',
    'transom_head' => 'Transom prep',
    'sidelite_head' => 'Sidelite head',
];
$sillConditionOptions = [
    'standard_sill' => 'Standard sill',
    'threshold' => 'Threshold',
    'no_sill' => 'No sill',
];
$doorTypeOptions = [
    'aluminum_stile' => 'Aluminum stile',
    'hollow_metal' => 'Hollow metal',
    'storefront_panel' => 'Storefront panel',
];
$doorThicknessOptions = [
    '1-3/4"' => '1-3/4"',
    '2-1/4"' => '2-1/4"',
];
$doorCoreOptions = [
    'honeycomb' => 'Honeycomb',
    'polystyrene' => 'Polystyrene',
    'aluminum' => 'Aluminum infill',
];
$liteKitOptions = [
    'half_lite' => 'Half lite',
    'full_lite' => 'Full lite',
    'narrow_lite' => 'Narrow lite',
    'none' => 'No lite',
];
$bottomRailOptions = [
    'standard' => 'Standard rail',
    '10_inch' => '10" ADA rail',
    'plank' => 'Plank style',
];
$hardwareSetOptions = [
    'Standard' => 'Standard template',
    'Grade 1' => 'Grade 1 heavy duty',
    'Custom' => 'Custom prep',
];
$hingePrepOptions = [
    'template_hinge' => 'Template hinge',
    'continuous' => 'Continuous hinge',
    'pivot' => 'Pivot set',
];
$strikePrepOptions = [
    'asa_strike' => 'ASA strike',
    'cylindrical' => 'Cylindrical',
    'mortise' => 'Mortise prep',
];
$closerOptions = [
    'surface' => 'Surface closer',
    'concealed' => 'Concealed closer',
    'none' => 'No closer',
];
$electrifiedOptions = [
    'no' => 'No electrified hardware',
    'prewire' => 'Prewire and conduit',
    'fully_prepped' => 'Fully prepped',
];
$editingConfigId = null;
$builderSteps = [
    ['id' => 'configuration', 'label' => 'Configuration data', 'description' => 'Name, job, scope, and lifecycle status'],
    ['id' => 'entry', 'label' => 'Entry data', 'description' => 'Elevation information and opening measurements'],
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

    try {
        $doorTagTemplates = configuratorListDoorTagTemplates($db);
    } catch (\Throwable $exception) {
        $errors[] = 'Unable to load door tags: ' . $exception->getMessage();
        $doorTagTemplates = [];
    }

    if (isset($_GET['template_door_id']) && ctype_digit((string) $_GET['template_door_id'])) {
        try {
            $template = configuratorFindDoorTagTemplate($db, (int) $_GET['template_door_id']);
            if ($template !== null) {
                $configFormData = [
                    'name' => $template['configuration_name'] . ' — ' . $template['door_tag'],
                    'job_id' => $template['job_id'] !== null ? (string) $template['job_id'] : '',
                    'job_scope' => $template['job_scope'],
                    'quantity' => 1,
                    'status' => $template['status'],
                    'notes' => $template['notes'] ?? '',
                    'door_tags' => [$template['door_tag']],
                ];
                $successMessage = 'Starting a new configuration from door tag ' . $template['door_tag'] . '.';
                $currentStep = 'configuration';
                $editingConfigId = null;
            }
        } catch (\Throwable $exception) {
            $errors[] = 'Unable to load door tag template: ' . $exception->getMessage();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if (isset($_POST['builder_step']) && in_array($_POST['builder_step'], $stepIds, true)) {
            $currentStep = (string) $_POST['builder_step'];
        }

        $openingTypeRaw = $_POST['entry_opening_type'] ?? null;
        if ($openingTypeRaw !== null && isset($openingTypeOptions[$openingTypeRaw])) {
            $entryFormData['opening_type'] = (string) $openingTypeRaw;
        }

        $handSingleRaw = $_POST['entry_hand_single'] ?? null;
        if ($handSingleRaw !== null && isset($handOptionsSingle[$handSingleRaw])) {
            $entryFormData['hand_single'] = (string) $handSingleRaw;
        }

        $handPairRaw = $_POST['entry_hand_pair'] ?? null;
        if ($handPairRaw !== null && isset($handOptionsPair[$handPairRaw])) {
            $entryFormData['hand_pair'] = (string) $handPairRaw;
        }
        $entryFormData['door_glazing'] = in_array($_POST['entry_door_glazing'] ?? '', $glazingOptions, true)
            ? (string) $_POST['entry_door_glazing']
            : $entryFormData['door_glazing'];
        $transomRaw = $_POST['entry_transom'] ?? null;
        if ($transomRaw !== null && isset($transomOptions[$transomRaw])) {
            $entryFormData['transom'] = (string) $transomRaw;
        }
        $entryFormData['transom_glazing'] = in_array($_POST['entry_transom_glazing'] ?? '', $glazingOptions, true)
            ? (string) $_POST['entry_transom_glazing']
            : $entryFormData['transom_glazing'];
        $entryFormData['elevation'] = isset($_POST['entry_elevation'])
            ? trim((string) $_POST['entry_elevation'])
            : $entryFormData['elevation'];
        $entryFormData['opening'] = isset($_POST['entry_opening'])
            ? trim((string) $_POST['entry_opening'])
            : $entryFormData['opening'];
        $entryFormData['notes'] = isset($_POST['entry_notes'])
            ? trim((string) $_POST['entry_notes'])
            : $entryFormData['notes'];

        $frameMaterialRaw = $_POST['frame_material'] ?? null;
        if ($frameMaterialRaw !== null && array_key_exists($frameMaterialRaw, $frameMaterialOptions)) {
            $frameFormData['material'] = (string) $frameMaterialRaw;
        }

        $frameProfileRaw = $_POST['frame_profile'] ?? null;
        if ($frameProfileRaw !== null && array_key_exists($frameProfileRaw, $frameProfileOptions)) {
            $frameFormData['profile'] = (string) $frameProfileRaw;
        }

        $frameAnchorRaw = $_POST['frame_anchor_type'] ?? null;
        if ($frameAnchorRaw !== null && array_key_exists($frameAnchorRaw, $frameAnchorOptions)) {
            $frameFormData['anchor_type'] = (string) $frameAnchorRaw;
        }

        $frameHeadRaw = $_POST['frame_head_condition'] ?? null;
        if ($frameHeadRaw !== null && array_key_exists($frameHeadRaw, $headConditionOptions)) {
            $frameFormData['head_condition'] = (string) $frameHeadRaw;
        }

        $frameSillRaw = $_POST['frame_sill_condition'] ?? null;
        if ($frameSillRaw !== null && array_key_exists($frameSillRaw, $sillConditionOptions)) {
            $frameFormData['sill_condition'] = (string) $frameSillRaw;
        }

        $frameFormData['notes'] = isset($_POST['frame_notes'])
            ? trim((string) $_POST['frame_notes'])
            : $frameFormData['notes'];

        $doorTypeRaw = $_POST['door_type'] ?? null;
        if ($doorTypeRaw !== null && array_key_exists($doorTypeRaw, $doorTypeOptions)) {
            $doorFormData['door_type'] = (string) $doorTypeRaw;
        }

        $doorThicknessRaw = $_POST['door_thickness'] ?? null;
        if ($doorThicknessRaw !== null && array_key_exists($doorThicknessRaw, $doorThicknessOptions)) {
            $doorFormData['thickness'] = (string) $doorThicknessRaw;
        }

        $doorCoreRaw = $_POST['door_core'] ?? null;
        if ($doorCoreRaw !== null && array_key_exists($doorCoreRaw, $doorCoreOptions)) {
            $doorFormData['core'] = (string) $doorCoreRaw;
        }

        $liteKitRaw = $_POST['door_lite_kit'] ?? null;
        if ($liteKitRaw !== null && array_key_exists($liteKitRaw, $liteKitOptions)) {
            $doorFormData['lite_kit'] = (string) $liteKitRaw;
        }

        $bottomRailRaw = $_POST['door_bottom_rail'] ?? null;
        if ($bottomRailRaw !== null && array_key_exists($bottomRailRaw, $bottomRailOptions)) {
            $doorFormData['bottom_rail'] = (string) $bottomRailRaw;
        }

        $doorFormData['notes'] = isset($_POST['door_notes'])
            ? trim((string) $_POST['door_notes'])
            : $doorFormData['notes'];

        $hardwareSetRaw = $_POST['hardware_set'] ?? null;
        if ($hardwareSetRaw !== null && array_key_exists($hardwareSetRaw, $hardwareSetOptions)) {
            $hardwareFormData['set_name'] = (string) $hardwareSetRaw;
        }

        $hingePrepRaw = $_POST['hardware_hinge_prep'] ?? null;
        if ($hingePrepRaw !== null && array_key_exists($hingePrepRaw, $hingePrepOptions)) {
            $hardwareFormData['hinge_prep'] = (string) $hingePrepRaw;
        }

        $strikePrepRaw = $_POST['hardware_strike_prep'] ?? null;
        if ($strikePrepRaw !== null && array_key_exists($strikePrepRaw, $strikePrepOptions)) {
            $hardwareFormData['strike_prep'] = (string) $strikePrepRaw;
        }

        $closerRaw = $_POST['hardware_closer'] ?? null;
        if ($closerRaw !== null && array_key_exists($closerRaw, $closerOptions)) {
            $hardwareFormData['closer'] = (string) $closerRaw;
        }

        $electrifiedRaw = $_POST['hardware_electrified'] ?? null;
        if ($electrifiedRaw !== null && array_key_exists($electrifiedRaw, $electrifiedOptions)) {
            $hardwareFormData['electrified'] = (string) $electrifiedRaw;
        }

        $hardwareFormData['notes'] = isset($_POST['hardware_notes'])
            ? trim((string) $_POST['hardware_notes'])
            : $hardwareFormData['notes'];

        $summaryNotes = isset($_POST['summary_notes'])
            ? trim((string) $_POST['summary_notes'])
            : $summaryNotes;

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
            $configScope = (string) ($_POST['config_job_scope'] ?? 'door_and_frame');
            $configQuantityRaw = trim((string) ($_POST['config_quantity'] ?? '1'));
            $configStatus = trim((string) ($_POST['config_status'] ?? 'draft'));
            $configNotes = trim((string) ($_POST['config_notes'] ?? ''));
            $doorTagsRaw = isset($_POST['door_tags']) && is_array($_POST['door_tags']) ? $_POST['door_tags'] : [];

            $configFormData = [
                'name' => $configName,
                'job_id' => $jobIdRaw,
                'job_scope' => $configScope,
                'quantity' => $configQuantityRaw,
                'status' => $configStatus,
                'notes' => $configNotes,
                'door_tags' => array_map('strval', $doorTagsRaw),
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

            if (!array_key_exists($configScope, $jobScopeOptions)) {
                $errors['config_job_scope'] = 'Select a valid job scope.';
                $configFormData['job_scope'] = 'door_and_frame';
            }

            $quantity = ctype_digit($configQuantityRaw) ? (int) $configQuantityRaw : 0;
            if ($quantity < 1) {
                $errors['config_quantity'] = 'Quantity must be at least 1.';
                $quantity = 1;
            }

            if (!in_array($configStatus, $statusOptions, true)) {
                $errors['config_status'] = 'Select a valid status.';
                $configFormData['status'] = 'draft';
            }

            $doorTags = array_values(array_filter(
                array_map(static fn ($value): string => trim((string) $value), $doorTagsRaw),
                static fn (string $value): bool => $value !== ''
            ));

            if (count($doorTags) !== $quantity) {
                $errors['door_tags'] = 'Door tag count must match the quantity.';
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
                    'job_scope' => array_key_exists($configScope, $jobScopeOptions) ? $configScope : 'door_and_frame',
                    'quantity' => $quantity,
                    'status' => $configStatus,
                    'notes' => $configNotes !== '' ? $configNotes : null,
                    'door_tags' => $doorTags,
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
        } elseif (in_array($action, ['stage_entry', 'stage_frame', 'stage_door', 'stage_hardware', 'stage_summary'], true)) {
            $successMessage = 'Step details recorded for this session. Saving to the database will follow in a future update.';
        }
    } elseif (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) {
        $editingConfigId = (int) $_GET['id'];
        try {
            $existingConfig = configuratorFindConfiguration($db, $editingConfigId);
            if ($existingConfig !== null) {
                $configFormData = [
                    'name' => $existingConfig['name'],
                    'job_id' => $existingConfig['job_id'] !== null ? (string) $existingConfig['job_id'] : '',
                    'job_scope' => $existingConfig['job_scope'],
                    'quantity' => $existingConfig['quantity'],
                    'status' => $existingConfig['status'],
                    'notes' => $existingConfig['notes'] ?? '',
                    'door_tags' => $existingConfig['door_tags'],
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
                    <label for="config_job_scope">Job Scope</label>
                    <select id="config_job_scope" name="config_job_scope">
                      <?php foreach ($jobScopeOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $configFormData['job_scope'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php if (!empty($errors['config_job_scope'])): ?>
                      <p class="field-error"><?= e($errors['config_job_scope']) ?></p>
                    <?php endif; ?>
                  </div>

                  <div class="field">
                    <label for="config_quantity">Quantity<span aria-hidden="true">*</span></label>
                    <input type="number" min="1" id="config_quantity" name="config_quantity" value="<?= e((string) $configFormData['quantity']) ?>" required />
                    <p class="small muted">Door tag count must match this quantity.</p>
                    <?php if (!empty($errors['config_quantity'])): ?>
                      <p class="field-error"><?= e($errors['config_quantity']) ?></p>
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

                <div class="field">
                  <label>Door Tags<span aria-hidden="true">*</span></label>
                  <p class="small muted">Provide one tag per opening. The number of tags must equal the quantity.</p>
                  <?php if (!empty($errors['door_tags'])): ?>
                    <p class="field-error"><?= e($errors['door_tags']) ?></p>
                  <?php endif; ?>
                  <div id="door-tags-container" class="stacked gap-sm">
                    <?php foreach ($configFormData['door_tags'] as $tag): ?>
                      <div class="door-tag-row">
                        <input type="text" name="door_tags[]" value="<?= e($tag) ?>" required />
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <?php if (false): ?>
                  <div class="field">
                    <label for="template_door_select">Copy from door tag</label>
                    <div class="stacked gap-xs">
                      <select id="template_door_select" name="template_door_select">
                        <option value="">Select a door tag to copy</option>
                        <?php foreach ($doorTagTemplates as $template): ?>
                          <option value="<?= e((string) $template['door_id']) ?>">
                            <?= e($template['door_tag']) ?> — <?= e($template['configuration_name']) ?><?php if ($template['job_number'] !== null): ?> (Job <?= e($template['job_number']) ?>)<?php endif; ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <p class="small muted">Loading a door tag will start a new configuration using that tag as the starting point.</p>
                      <div class="form-actions inline">
                        <button type="button" class="button ghost" id="template-door-start" aria-label="Copy from selected door tag">Use door tag</button>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>

                <div class="form-actions">
                  <button type="submit" class="button primary">Save configuration</button>
                  <a class="button ghost" href="configurator.php">Cancel</a>
                </div>
              </form>
            <?php elseif ($currentStep === 'entry'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_entry" />
                <input type="hidden" name="builder_step" value="entry" />
                <div class="field-grid two-column">
                  <div class="field">
                    <label for="entry_opening_type">Opening type</label>
                    <select id="entry_opening_type" name="entry_opening_type" data-opening-type>
                      <?php foreach ($openingTypeOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $entryFormData['opening_type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field" data-hand="single">
                    <label for="entry_hand_single">Hand (single)</label>
                    <select id="entry_hand_single" name="entry_hand_single">
                      <?php foreach ($handOptionsSingle as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $entryFormData['hand_single'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field" data-hand="pair">
                    <label for="entry_hand_pair">Hand (pair)</label>
                    <select id="entry_hand_pair" name="entry_hand_pair">
                      <?php foreach ($handOptionsPair as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $entryFormData['hand_pair'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="entry_door_glazing">Door glazing</label>
                    <select id="entry_door_glazing" name="entry_door_glazing">
                      <?php foreach ($glazingOptions as $option): ?>
                        <option value="<?= e($option) ?>"<?= $entryFormData['door_glazing'] === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="entry_transom">Transom</label>
                    <select id="entry_transom" name="entry_transom" data-transom>
                      <?php foreach ($transomOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $entryFormData['transom'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field" data-transom-glazing>
                    <label for="entry_transom_glazing">Transom glazing</label>
                    <select id="entry_transom_glazing" name="entry_transom_glazing">
                      <?php foreach ($glazingOptions as $option): ?>
                        <option value="<?= e($option) ?>"<?= $entryFormData['transom_glazing'] === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="entry_elevation">Elevation / Mark</label>
                    <input type="text" id="entry_elevation" name="entry_elevation" placeholder="Example: Elevation A" value="<?= e($entryFormData['elevation']) ?>" />
                  </div>
                  <div class="field">
                    <label for="entry_opening">Opening location</label>
                    <input type="text" id="entry_opening" name="entry_opening" placeholder="Floor, room, or grid reference" value="<?= e($entryFormData['opening']) ?>" />
                  </div>
                </div>
                <div class="field">
                  <label for="entry_notes">Elevation notes</label>
                  <textarea id="entry_notes" name="entry_notes" rows="3" placeholder="List elevation details, head heights, and any unique conditions."><?= e($entryFormData['notes']) ?></textarea>
                </div>
                <p class="small muted">These entry details are staged for the workflow and will be wired into persistence and calculations in a follow-up update.</p>
                <div class="form-actions">
                  <button type="submit" class="button secondary" name="builder_step" value="configuration">Save entry data</button>
                  <button type="submit" class="button primary" name="builder_step" value="frame">Continue to frame data</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'frame'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_frame" />
                <input type="hidden" name="builder_step" value="frame" />
                <div class="field-grid two-column">
                  <div class="field">
                    <label for="frame_material">Frame material</label>
                    <select id="frame_material" name="frame_material">
                      <?php foreach ($frameMaterialOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $frameFormData['material'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="frame_profile">Frame profile</label>
                    <select id="frame_profile" name="frame_profile">
                      <?php foreach ($frameProfileOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $frameFormData['profile'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="frame_anchor_type">Anchor type</label>
                    <select id="frame_anchor_type" name="frame_anchor_type">
                      <?php foreach ($frameAnchorOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $frameFormData['anchor_type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="frame_head_condition">Head condition</label>
                    <select id="frame_head_condition" name="frame_head_condition">
                      <?php foreach ($headConditionOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $frameFormData['head_condition'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="frame_sill_condition">Sill condition</label>
                    <select id="frame_sill_condition" name="frame_sill_condition">
                      <?php foreach ($sillConditionOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $frameFormData['sill_condition'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="frame_notes">Frame notes</label>
                    <textarea id="frame_notes" name="frame_notes" rows="3" placeholder="Anchors, shims, and reinforcing details."><?= e($frameFormData['notes']) ?></textarea>
                  </div>
                </div>
                <p class="small muted">Use this stage to outline frame makeup and installation needs. These values will map to frame part selection and cut lists.</p>
                <div class="form-actions">
                  <button type="submit" class="button secondary" name="builder_step" value="entry">Back to entry</button>
                  <button type="submit" class="button primary" name="builder_step" value="door">Continue to door data</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'door'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_door" />
                <input type="hidden" name="builder_step" value="door" />
                <div class="field-grid two-column">
                  <div class="field">
                    <label for="door_type">Door type</label>
                    <select id="door_type" name="door_type">
                      <?php foreach ($doorTypeOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $doorFormData['door_type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="door_thickness">Door thickness</label>
                    <select id="door_thickness" name="door_thickness">
                      <?php foreach ($doorThicknessOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $doorFormData['thickness'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="door_core">Core</label>
                    <select id="door_core" name="door_core">
                      <?php foreach ($doorCoreOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $doorFormData['core'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="door_lite_kit">Lite kit</label>
                    <select id="door_lite_kit" name="door_lite_kit">
                      <?php foreach ($liteKitOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $doorFormData['lite_kit'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="door_bottom_rail">Bottom rail</label>
                    <select id="door_bottom_rail" name="door_bottom_rail">
                      <?php foreach ($bottomRailOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $doorFormData['bottom_rail'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="door_notes">Door notes</label>
                    <textarea id="door_notes" name="door_notes" rows="3" placeholder="Rail sizes, stiles, reinforcing, or glazing callouts."><?= e($doorFormData['notes']) ?></textarea>
                  </div>
                </div>
                <p class="small muted">Outline the leaf construction to drive BOM selection. Preps and lite kits will guide required components.</p>
                <div class="form-actions">
                  <button type="submit" class="button secondary" name="builder_step" value="frame">Back to frame</button>
                  <button type="submit" class="button primary" name="builder_step" value="hardware">Continue to hardware</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'hardware'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_hardware" />
                <input type="hidden" name="builder_step" value="hardware" />
                <div class="field-grid two-column">
                  <div class="field">
                    <label for="hardware_set">Hardware set</label>
                    <select id="hardware_set" name="hardware_set">
                      <?php foreach ($hardwareSetOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $hardwareFormData['set_name'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="hardware_hinge_prep">Hinge prep</label>
                    <select id="hardware_hinge_prep" name="hardware_hinge_prep">
                      <?php foreach ($hingePrepOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $hardwareFormData['hinge_prep'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="hardware_strike_prep">Strike prep</label>
                    <select id="hardware_strike_prep" name="hardware_strike_prep">
                      <?php foreach ($strikePrepOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $hardwareFormData['strike_prep'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="hardware_closer">Closer</label>
                    <select id="hardware_closer" name="hardware_closer">
                      <?php foreach ($closerOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $hardwareFormData['closer'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="hardware_electrified">Electrified hardware</label>
                    <select id="hardware_electrified" name="hardware_electrified">
                      <?php foreach ($electrifiedOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $hardwareFormData['electrified'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label for="hardware_notes">Hardware notes</label>
                    <textarea id="hardware_notes" name="hardware_notes" rows="3" placeholder="Handing, power transfers, or security devices."><?= e($hardwareFormData['notes']) ?></textarea>
                  </div>
                </div>
                <p class="small muted">Hardware selections will drive preps and required parts lists. Use notes to call out special conditions.</p>
                <div class="form-actions">
                  <button type="submit" class="button secondary" name="builder_step" value="door">Back to door</button>
                  <button type="submit" class="button primary" name="builder_step" value="summary">Continue to summary</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'summary'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_summary" />
                <input type="hidden" name="builder_step" value="summary" />
                <div class="card-grid two-column">
                  <div class="card">
                    <h3>Entry overview</h3>
                    <ul class="stacked gap-xs">
                      <li><strong>Opening type:</strong> <?= e($openingTypeOptions[$entryFormData['opening_type']] ?? $entryFormData['opening_type']) ?></li>
                      <li><strong>Hand:</strong> <?= e($entryFormData['opening_type'] === 'pair' ? ($handOptionsPair[$entryFormData['hand_pair']] ?? $entryFormData['hand_pair']) : ($handOptionsSingle[$entryFormData['hand_single']] ?? $entryFormData['hand_single'])) ?></li>
                      <li><strong>Glazing:</strong> <?= e($entryFormData['door_glazing']) ?></li>
                      <li><strong>Transom:</strong> <?= e($transomOptions[$entryFormData['transom']] ?? $entryFormData['transom']) ?></li>
                      <li><strong>Transom glazing:</strong> <?= e($entryFormData['transom'] === 'yes' ? ($entryFormData['transom_glazing'] ?? '') : 'N/A') ?></li>
                      <li><strong>Elevation:</strong> <?= e($entryFormData['elevation'] !== '' ? $entryFormData['elevation'] : 'TBD') ?></li>
                      <li><strong>Opening location:</strong> <?= e($entryFormData['opening'] !== '' ? $entryFormData['opening'] : 'TBD') ?></li>
                    </ul>
                  </div>
                  <div class="card">
                    <h3>Frame and door</h3>
                    <ul class="stacked gap-xs">
                      <li><strong>Frame:</strong> <?= e($frameMaterialOptions[$frameFormData['material']] ?? $frameFormData['material']) ?> · <?= e($frameProfileOptions[$frameFormData['profile']] ?? $frameFormData['profile']) ?></li>
                      <li><strong>Anchorage:</strong> <?= e($frameAnchorOptions[$frameFormData['anchor_type']] ?? $frameFormData['anchor_type']) ?></li>
                      <li><strong>Head:</strong> <?= e($headConditionOptions[$frameFormData['head_condition']] ?? $frameFormData['head_condition']) ?></li>
                      <li><strong>Sill:</strong> <?= e($sillConditionOptions[$frameFormData['sill_condition']] ?? $frameFormData['sill_condition']) ?></li>
                      <li><strong>Door:</strong> <?= e($doorTypeOptions[$doorFormData['door_type']] ?? $doorFormData['door_type']) ?> · <?= e($doorFormData['thickness']) ?> · <?= e($doorCoreOptions[$doorFormData['core']] ?? $doorFormData['core']) ?></li>
                      <li><strong>Lite kit:</strong> <?= e($liteKitOptions[$doorFormData['lite_kit']] ?? $doorFormData['lite_kit']) ?></li>
                      <li><strong>Bottom rail:</strong> <?= e($bottomRailOptions[$doorFormData['bottom_rail']] ?? $doorFormData['bottom_rail']) ?></li>
                    </ul>
                  </div>
                </div>

                <div class="card-grid two-column">
                  <div class="card">
                    <h3>Hardware</h3>
                    <ul class="stacked gap-xs">
                      <li><strong>Set:</strong> <?= e($hardwareFormData['set_name']) ?></li>
                      <li><strong>Hinge prep:</strong> <?= e($hingePrepOptions[$hardwareFormData['hinge_prep']] ?? $hardwareFormData['hinge_prep']) ?></li>
                      <li><strong>Strike prep:</strong> <?= e($strikePrepOptions[$hardwareFormData['strike_prep']] ?? $hardwareFormData['strike_prep']) ?></li>
                      <li><strong>Closer:</strong> <?= e($closerOptions[$hardwareFormData['closer']] ?? $hardwareFormData['closer']) ?></li>
                      <li><strong>Electrified:</strong> <?= e($electrifiedOptions[$hardwareFormData['electrified']] ?? $hardwareFormData['electrified']) ?></li>
                    </ul>
                  </div>
                  <div class="card">
                    <h3>Notes</h3>
                    <p class="small muted">These notes help the team visualize outstanding questions before we wire persistence and BOM calculations.</p>
                    <textarea name="summary_notes" rows="6" placeholder="Document open items or approvals needed."><?= e($summaryNotes) ?></textarea>
                  </div>
                </div>
                <div class="form-actions">
                  <button type="submit" class="button secondary" name="builder_step" value="hardware">Back to hardware</button>
                  <a class="button ghost" href="configurator.php">Return to list</a>
                  <button type="submit" class="button primary" name="builder_step" value="summary">Acknowledge summary</button>
                </div>
              </form>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th scope="col">Configuration</th>
                <th scope="col">Job</th>
                <th scope="col">Scope</th>
                <th scope="col">Quantity</th>
                <th scope="col">Door Tags</th>
                <th scope="col">Status</th>
                <th scope="col">Updated</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($configurations === []): ?>
                <tr>
                  <td colspan="8" class="muted">No configurations yet. Add one to start building a template.</td>
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
                    <td><?= e($jobScopeOptions[$configuration['job_scope']] ?? $configuration['job_scope']) ?></td>
                    <td><?= e((string) $configuration['quantity']) ?></td>
                    <td>
                      <?php if ($configuration['door_tags'] === []): ?>
                        <span class="muted">No tags</span>
                      <?php else: ?>
                        <div class="stacked gap-xs">
                          <?php foreach ($configuration['door_tags'] as $tag): ?>
                            <span class="pill secondary"><?= e($tag) ?></span>
                          <?php endforeach; ?>
                        </div>
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

      <?php if (false): ?>
        <section class="panel">
          <header class="panel-header">
            <div>
              <h2>Door tags</h2>
              <p class="small">Reuse existing door tags to start a new configuration without rebuilding from scratch.</p>
            </div>
          </header>

          <?php if ($doorTagTemplates === []): ?>
            <p class="muted">No door tags are available yet. Add tags to a configuration to unlock copying.</p>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="table">
                <thead>
                  <tr>
                    <th scope="col">Door Tag</th>
                    <th scope="col">Configuration</th>
                    <th scope="col">Job</th>
                    <th scope="col">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($doorTagTemplates as $template): ?>
                    <tr>
                      <td><?= e($template['door_tag']) ?></td>
                      <td><?= e($template['configuration_name']) ?></td>
                      <td>
                        <?php if ($template['job_number'] !== null): ?>
                          <?= e($template['job_number']) ?>
                        <?php else: ?>
                          <span class="muted">Unassigned</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <a class="button ghost" href="configurator.php?create=1&step=configuration&template_door_id=<?= e((string) $template['door_id']) ?>">Start from tag</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

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

  <script>
    (function () {
      const quantityInput = document.getElementById('config_quantity');
      const doorTagsContainer = document.getElementById('door-tags-container');
      const templateSelect = document.getElementById('template_door_select');
      const templateButton = document.getElementById('template-door-start');
      const openingTypeSelect = document.querySelector('[data-opening-type]');
      const handFields = document.querySelectorAll('[data-hand]');
      const transomSelect = document.querySelector('[data-transom]');
      const transomGlazingField = document.querySelector('[data-transom-glazing]');

      function syncDoorTags() {
        if (!quantityInput || !doorTagsContainer) {
          return;
        }

        const parsed = parseInt(quantityInput.value, 10);
        const quantity = Number.isNaN(parsed) || parsed < 1 ? 1 : parsed;
        quantityInput.value = quantity;

        const existing = Array.from(doorTagsContainer.querySelectorAll('input[name="door_tags[]"]')).map((input) => input.value);

        doorTagsContainer.innerHTML = '';

        for (let index = 0; index < quantity; index += 1) {
          const wrapper = document.createElement('div');
          wrapper.className = 'door-tag-row';

          const input = document.createElement('input');
          input.type = 'text';
          input.name = 'door_tags[]';
          input.required = true;
          input.placeholder = `Door tag #${index + 1}`;
          input.value = existing[index] ?? '';

          wrapper.appendChild(input);
          doorTagsContainer.appendChild(wrapper);
        }
      }

      quantityInput?.addEventListener('change', syncDoorTags);
      quantityInput?.addEventListener('blur', syncDoorTags);
      syncDoorTags();

      if (templateSelect && templateButton) {
        templateButton.addEventListener('click', () => {
          const selected = templateSelect.value;
          if (selected === '') {
            return;
          }

          const url = new URL(window.location.href);
          url.searchParams.set('create', '1');
          url.searchParams.set('step', 'configuration');
          url.searchParams.set('template_door_id', selected);
          window.location.href = url.toString();
        });
      }

      function syncHands() {
        const type = (openingTypeSelect instanceof HTMLSelectElement ? openingTypeSelect.value : 'single') === 'pair'
          ? 'pair'
          : 'single';

        handFields.forEach((field) => {
          if (!(field instanceof HTMLElement)) {
            return;
          }

          const handType = field.getAttribute('data-hand');
          const isMatch = handType === type;
          field.hidden = !isMatch;

          const select = field.querySelector('select');
          if (select instanceof HTMLSelectElement) {
            select.disabled = !isMatch;
          }
        });
      }

      function syncTransomGlazing() {
        const hasTransom = (transomSelect instanceof HTMLSelectElement ? transomSelect.value : 'no') === 'yes';
        if (transomGlazingField instanceof HTMLElement) {
          transomGlazingField.hidden = !hasTransom;

          const select = transomGlazingField.querySelector('select');
          if (select instanceof HTMLSelectElement) {
            select.disabled = !hasTransom;
          }
        }
      }

      openingTypeSelect?.addEventListener('change', syncHands);
      transomSelect?.addEventListener('change', syncTransomGlazing);
      syncHands();
      syncTransomGlazing();
    })();
  </script>

</body>
</html>
