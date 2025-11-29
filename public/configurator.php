<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/configurator.php';

session_start();

$databaseConfig = $app['database'];
$localStorageOnly = true;
$dbError = $localStorageOnly
    ? 'Local storage mode active. Configurations are stored in this browser until database integration is added.'
    : null;
$errors = [];
$successMessage = null;
$configurations = [];
$jobs = [];
$doorTagTemplates = [];
$localSavePayload = null;
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
$db = null;
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
$defaultBuilderForms = [
    'configuration' => $configFormData,
    'entry' => $entryFormData,
    'frame' => $frameFormData,
    'door' => $doorFormData,
    'hardware' => $hardwareFormData,
    'summary_notes' => $summaryNotes,
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
$builderSessionKey = 'configurator_builder';
$builderState = $_SESSION[$builderSessionKey] ?? [
    'config_id' => null,
    'current_step' => 'configuration',
    'completed' => [],
    'forms' => $defaultBuilderForms,
    'config_payload' => null,
];
$stepOrder = array_flip($stepIds);

$resetBuilderState = static function (?int $configId = null) use (&$builderState, $defaultBuilderForms, $builderSessionKey): void {
    $builderState = [
        'config_id' => $configId,
        'current_step' => 'configuration',
        'completed' => [],
        'forms' => $defaultBuilderForms,
        'config_payload' => null,
    ];
    $_SESSION[$builderSessionKey] = $builderState;
};

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Door Configurator');
    }
}
unset($groupItems, $item);

if (!$localStorageOnly) {
    try {
        $db = db($databaseConfig);
    } catch (\Throwable $exception) {
        $dbError = $exception->getMessage();
    }
}

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        if (($item['label'] ?? '') === 'Database Health') {
            $item['badge'] = $dbError === null ? 'Live' : 'Local';
            $item['badge_class'] = $dbError === null ? 'success' : 'info';
        }
    }
}
unset($groupItems, $item);

if ($dbError === null || $localStorageOnly) {
    if (!$localStorageOnly) {
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
    }

    $requestedConfigId = isset($_GET['id']) && ctype_digit((string) $_GET['id'])
        ? (int) $_GET['id']
        : null;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (isset($_GET['create'])) {
            $resetBuilderState(null);
        } elseif ($builderState['config_id'] !== $requestedConfigId) {
            $resetBuilderState($requestedConfigId);
        }
    }

    $editingConfigId = $builderState['config_id'];

    if ($requestedConfigId !== null && $builderState['config_payload'] === null) {
        try {
            $existingConfig = configuratorFindConfiguration($db, $requestedConfigId);
            if ($existingConfig !== null) {
                $builderState['forms']['configuration'] = [
                    'name' => $existingConfig['name'],
                    'job_id' => $existingConfig['job_id'] !== null ? (string) $existingConfig['job_id'] : '',
                    'job_scope' => $existingConfig['job_scope'],
                    'quantity' => $existingConfig['quantity'],
                    'status' => $existingConfig['status'],
                    'notes' => $existingConfig['notes'] ?? '',
                    'door_tags' => $existingConfig['door_tags'],
                ];
                $builderState['config_payload'] = [
                    'name' => $existingConfig['name'],
                    'job_id' => $existingConfig['job_id'],
                    'job_scope' => $existingConfig['job_scope'],
                    'quantity' => (int) $existingConfig['quantity'],
                    'status' => $existingConfig['status'],
                    'notes' => $existingConfig['notes'],
                    'door_tags' => $existingConfig['door_tags'],
                ];
                $builderState['current_step'] = 'configuration';
            } else {
                $editingConfigId = null;
                $errors['general'] = 'The requested configuration could not be found.';
                $resetBuilderState(null);
            }
        } catch (\Throwable $exception) {
            $errors['general'] = 'Unable to load configuration: ' . $exception->getMessage();
            $editingConfigId = null;
            $resetBuilderState(null);
        }
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
                $builderState['forms']['configuration'] = $configFormData;
                $builderState['config_payload'] = null;
                $builderState['config_id'] = null;
                $builderState['completed'] = [];
                $builderState['current_step'] = 'configuration';
                $_SESSION[$builderSessionKey] = $builderState;
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
        $navigateToRaw = $_POST['navigate_to'] ?? null;
        $targetStep = $navigateToRaw !== null && in_array($navigateToRaw, $stepIds, true)
            ? (string) $navigateToRaw
            : null;

        $computeTargetStep = static function (string $current, ?string $requested) use ($stepIds, $stepOrder): string {
            $currentIndex = $stepOrder[$current] ?? 0;
            if ($requested === null || !array_key_exists($requested, $stepOrder)) {
                $nextIndex = $currentIndex + 1;
                return $stepIds[$nextIndex] ?? $current;
            }

            $requestedIndex = $stepOrder[$requested];
            if ($requestedIndex > $currentIndex + 1) {
                $nextIndex = $currentIndex + 1;
                return $stepIds[$nextIndex] ?? $current;
            }

            return $requested;
        };

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
        } elseif ($action === 'stage_configuration') {
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

            $jobId = $jobIdRaw !== '' ? $jobIdRaw : null;

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

                $builderState['forms']['configuration'] = $configFormData;
                $builderState['config_payload'] = $payload;
                $builderState['config_id'] = $editingConfigId;
                $builderState['completed'] = array_values(array_unique(array_merge($builderState['completed'], ['configuration'])));
                $builderState['current_step'] = $computeTargetStep('configuration', $targetStep ?? 'entry');
            } else {
                $builderState['current_step'] = 'configuration';
            }
        } elseif ($action === 'stage_entry') {
            $builderState['forms']['entry'] = $entryFormData;
            $builderState['completed'] = array_values(array_unique(array_merge($builderState['completed'], ['configuration', 'entry'])));
            $builderState['current_step'] = $computeTargetStep('entry', $targetStep ?? 'frame');
        } elseif ($action === 'stage_frame') {
            $builderState['forms']['frame'] = $frameFormData;
            $builderState['completed'] = array_values(array_unique(array_merge($builderState['completed'], ['configuration', 'entry', 'frame'])));
            $builderState['current_step'] = $computeTargetStep('frame', $targetStep ?? 'door');
        } elseif ($action === 'stage_door') {
            $builderState['forms']['door'] = $doorFormData;
            $builderState['completed'] = array_values(array_unique(array_merge($builderState['completed'], ['configuration', 'entry', 'frame', 'door'])));
            $builderState['current_step'] = $computeTargetStep('door', $targetStep ?? 'hardware');
        } elseif ($action === 'stage_hardware') {
            $builderState['forms']['hardware'] = $hardwareFormData;
            $builderState['forms']['summary_notes'] = $summaryNotes;
            $builderState['completed'] = array_values(array_unique(array_merge($builderState['completed'], ['configuration', 'entry', 'frame', 'door', 'hardware'])));
            $builderState['current_step'] = $computeTargetStep('hardware', $targetStep ?? 'summary');
        } elseif ($action === 'finalize_configuration') {
            $builderState['forms']['summary_notes'] = $summaryNotes;
            $requiredSteps = array_slice($stepIds, 0, count($stepIds) - 1);
            $missingStep = null;

            foreach ($requiredSteps as $stepId) {
                if (!in_array($stepId, $builderState['completed'], true)) {
                    $missingStep = $stepId;
                    break;
                }
            }

            if ($missingStep !== null) {
                $errors['general'] = 'Complete all steps before saving the configuration.';
                $builderState['current_step'] = $missingStep;
            } elseif ($builderState['config_payload'] === null) {
                $errors['general'] = 'Configuration details are missing. Please complete the first step.';
                $builderState['current_step'] = 'configuration';
            } else {
                $payload = $builderState['config_payload'];
                $editingConfigId = $builderState['config_id'] ?? null;

                $localSavePayload = [
                    'id' => $editingConfigId ?? uniqid('cfg_', true),
                    'configuration' => $payload,
                    'entry' => $entryFormData,
                    'frame' => $frameFormData,
                    'door' => $doorFormData,
                    'hardware' => $hardwareFormData,
                    'summary_notes' => $summaryNotes,
                    'updated_at' => date(DATE_ATOM),
                ];

                $builderState['config_id'] = $localSavePayload['id'];
                $builderState['completed'] = $stepIds;
                $builderState['forms']['summary_notes'] = $summaryNotes;
                $builderState['current_step'] = 'summary';
                $successMessage = 'Configuration saved locally in this browser. Database storage will be added later.';
            }
        }

        $_SESSION[$builderSessionKey] = $builderState;
    }

    $currentStep = $builderState['current_step'];
    if (isset($_GET['step']) && in_array($_GET['step'], $stepIds, true)) {
        $requestedStep = (string) $_GET['step'];
        if (in_array($requestedStep, $builderState['completed'], true) || $requestedStep === $currentStep) {
            $currentStep = $requestedStep;
            $builderState['current_step'] = $requestedStep;
            $_SESSION[$builderSessionKey] = $builderState;
        }
    }

    $configFormData = $builderState['forms']['configuration'];
    $entryFormData = $builderState['forms']['entry'];
    $frameFormData = $builderState['forms']['frame'];
    $doorFormData = $builderState['forms']['door'];
    $hardwareFormData = $builderState['forms']['hardware'];
    $summaryNotes = $builderState['forms']['summary_notes'];
    $editingConfigId = $builderState['config_id'];

    if (!$localStorageOnly) {
        try {
            $configurations = configuratorListConfigurations($db);
        } catch (\Throwable $exception) {
            $errors[] = 'Unable to load configurations: ' . $exception->getMessage();
            $configurations = [];
        }
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
    || (isset($_POST['action']) && in_array($_POST['action'], [
        'stage_configuration',
        'stage_entry',
        'stage_frame',
        'stage_door',
        'stage_hardware',
        'finalize_configuration',
    ], true))
    || (isset($_GET['create']) && $_GET['create'] === '1')
    || ($builderState['config_payload'] !== null);

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
          <div class="alert info" role="alert">
            <strong>Local storage mode:</strong> <?= e($dbError) ?>
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
                <input type="hidden" name="action" value="stage_configuration" />
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
                  <button type="submit" class="button primary" name="navigate_to" value="entry">Continue to entry data</button>
                  <a class="button ghost" href="configurator.php">Cancel</a>
                </div>
              </form>
            <?php elseif ($currentStep === 'entry'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_entry" />
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
                  <button type="submit" class="button secondary" name="navigate_to" value="configuration">Back to configuration</button>
                  <button type="submit" class="button primary" name="navigate_to" value="frame">Continue to frame data</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'frame'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_frame" />
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
                  <button type="submit" class="button secondary" name="navigate_to" value="entry">Back to entry</button>
                  <button type="submit" class="button primary" name="navigate_to" value="door">Continue to door data</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'door'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_door" />
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
                  <button type="submit" class="button secondary" name="navigate_to" value="frame">Back to frame</button>
                  <button type="submit" class="button primary" name="navigate_to" value="hardware">Continue to hardware</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'hardware'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_hardware" />
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
                  <button type="submit" class="button secondary" name="navigate_to" value="door">Back to door</button>
                  <button type="submit" class="button primary" name="navigate_to" value="summary">Continue to summary</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'summary'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="finalize_configuration" />
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
                  <button type="submit" class="button secondary" name="navigate_to" value="hardware">Back to hardware</button>
                  <a class="button ghost" href="configurator.php">Return to list</a>
                  <button type="submit" class="button primary">Save configuration</button>
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
            <tbody data-configs-body>
              <tr>
                <td colspan="8" class="muted">No configurations yet. Add one to start building a template.</td>
              </tr>
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

        <form method="post" class="form inline-form" novalidate data-job-form>
          <div class="field-grid two-column">
            <div class="field">
              <label for="job_number">Job Number<span aria-hidden="true">*</span></label>
              <input type="text" id="job_number" name="job_number" />
              <p class="field-error" data-job-error="number"></p>
            </div>
            <div class="field">
              <label for="job_name">Job Name<span aria-hidden="true">*</span></label>
              <input type="text" id="job_name" name="job_name" />
              <p class="field-error" data-job-error="name"></p>
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="button secondary">Add job</button>
            <p class="muted" data-job-feedback></p>
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
            <tbody data-jobs-body>
              <tr>
                <td colspan="3" class="muted">No jobs have been added yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <script>
    window.localSavePayload = <?= json_encode($localSavePayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.localBuilderForms = <?= json_encode([
        'configuration' => $configFormData,
        'entry' => $entryFormData,
        'frame' => $frameFormData,
        'door' => $doorFormData,
        'hardware' => $hardwareFormData,
        'summary_notes' => $summaryNotes,
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.localBuilderConfigId = <?= json_encode($builderState['config_id'] ?? null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.localJobScopes = <?= json_encode($jobScopeOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  </script>
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

      const CONFIG_KEY = 'configurator_configurations';
      const JOB_KEY = 'configurator_jobs';
      const ACTIVE_KEY = 'configurator_active_record';
      const PREFILL_KEY = 'configurator_builder_prefill';

      const configTableBody = document.querySelector('[data-configs-body]');
      const jobsTableBody = document.querySelector('[data-jobs-body]');
      const jobSelect = document.getElementById('config_job_id');
      const jobForm = document.querySelector('[data-job-form]');
      const jobNumberError = document.querySelector('[data-job-error="number"]');
      const jobNameError = document.querySelector('[data-job-error="name"]');
      const jobFeedback = document.querySelector('[data-job-feedback]');

      function readJson(key, fallback) {
        try {
          const stored = localStorage.getItem(key);
          return stored ? JSON.parse(stored) : fallback;
        } catch (error) {
          console.warn('Unable to parse local storage value for', key, error);
          return fallback;
        }
      }

      function writeJson(key, value) {
        localStorage.setItem(key, JSON.stringify(value));
      }

      let jobs = readJson(JOB_KEY, []);
      let configs = readJson(CONFIG_KEY, []);
      let activeRecord = readJson(ACTIVE_KEY, null);

      const prefillRaw = localStorage.getItem(PREFILL_KEY);
      if (prefillRaw) {
        activeRecord = readJson(PREFILL_KEY, null);
        localStorage.removeItem(PREFILL_KEY);
      }

      function renderJobs() {
        if (!jobsTableBody) {
          return;
        }

        jobsTableBody.innerHTML = '';

        if (jobs.length === 0) {
          const row = document.createElement('tr');
          const cell = document.createElement('td');
          cell.colSpan = 3;
          cell.className = 'muted';
          cell.textContent = 'No jobs have been added yet.';
          row.appendChild(cell);
          jobsTableBody.appendChild(row);
        } else {
          jobs.forEach((job) => {
            const row = document.createElement('tr');
            const numberCell = document.createElement('td');
            numberCell.textContent = job.job_number;
            const nameCell = document.createElement('td');
            nameCell.textContent = job.name;
            const addedCell = document.createElement('td');
            addedCell.className = 'muted';
            addedCell.textContent = job.created_at
              ? new Date(job.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
              : '';
            row.appendChild(numberCell);
            row.appendChild(nameCell);
            row.appendChild(addedCell);
            jobsTableBody.appendChild(row);
          });
        }

        if (jobSelect) {
          const selectedValue = jobSelect.value;
          jobSelect.innerHTML = '<option value="">Unassigned</option>';
          jobs.forEach((job) => {
            const option = document.createElement('option');
            option.value = job.id;
            option.textContent = `${job.job_number} — ${job.name}`;
            if (selectedValue === job.id) {
              option.selected = true;
            }
            jobSelect.appendChild(option);
          });
        }
      }

      function renderConfigs() {
        if (!configTableBody) {
          return;
        }

        configTableBody.innerHTML = '';

        if (configs.length === 0) {
          const row = document.createElement('tr');
          const cell = document.createElement('td');
          cell.colSpan = 8;
          cell.className = 'muted';
          cell.textContent = 'No configurations yet. Add one to start building a template.';
          row.appendChild(cell);
          configTableBody.appendChild(row);
          return;
        }

        configs.forEach((config) => {
          const row = document.createElement('tr');
          const job = jobs.find((entry) => entry.id === config.configuration.job_id);

          const nameCell = document.createElement('td');
          nameCell.textContent = config.configuration.name || 'Untitled configuration';

          const jobCell = document.createElement('td');
          if (job) {
            const stack = document.createElement('div');
            stack.className = 'stacked';
            const strong = document.createElement('strong');
            strong.textContent = job.job_number;
            const muted = document.createElement('span');
            muted.className = 'muted';
            muted.textContent = job.name;
            stack.appendChild(strong);
            stack.appendChild(muted);
            jobCell.appendChild(stack);
          } else if (config.configuration.job_id) {
            jobCell.textContent = config.configuration.job_id;
          } else {
            const muted = document.createElement('span');
            muted.className = 'muted';
            muted.textContent = 'Unassigned';
            jobCell.appendChild(muted);
          }

          const scopeCell = document.createElement('td');
          const scopeLabel = window.localJobScopes?.[config.configuration.job_scope] ?? config.configuration.job_scope;
          scopeCell.textContent = scopeLabel;

          const qtyCell = document.createElement('td');
          qtyCell.textContent = String(config.configuration.quantity ?? 0);

          const tagsCell = document.createElement('td');
          if (Array.isArray(config.configuration.door_tags) && config.configuration.door_tags.length > 0) {
            const wrap = document.createElement('div');
            wrap.className = 'stacked gap-xs';
            config.configuration.door_tags.forEach((tag) => {
              const pill = document.createElement('span');
              pill.className = 'pill secondary';
              pill.textContent = tag;
              wrap.appendChild(pill);
            });
            tagsCell.appendChild(wrap);
          } else {
            const muted = document.createElement('span');
            muted.className = 'muted';
            muted.textContent = 'No tags';
            tagsCell.appendChild(muted);
          }

          const statusCell = document.createElement('td');
          const statusPill = document.createElement('span');
          statusPill.className = 'pill';
          statusPill.textContent = (config.configuration.status || '').replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
          statusCell.appendChild(statusPill);

          const updatedCell = document.createElement('td');
          updatedCell.className = 'muted';
          updatedCell.textContent = config.updated_at
            ? new Date(config.updated_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
            : '';

          const actionCell = document.createElement('td');
          const editButton = document.createElement('button');
          editButton.type = 'button';
          editButton.className = 'button ghost';
          editButton.textContent = 'Edit';
          editButton.addEventListener('click', () => {
            localStorage.setItem(PREFILL_KEY, JSON.stringify(config));
            window.location.href = 'configurator.php?create=1&step=configuration';
          });
          actionCell.appendChild(editButton);

          row.appendChild(nameCell);
          row.appendChild(jobCell);
          row.appendChild(scopeCell);
          row.appendChild(qtyCell);
          row.appendChild(tagsCell);
          row.appendChild(statusCell);
          row.appendChild(updatedCell);
          row.appendChild(actionCell);

          configTableBody.appendChild(row);
        });
      }

      function applyConfigurationForm(data) {
        const config = data?.configuration;
        if (!config) return;

        const name = document.getElementById('config_name');
        if (name) name.value = config.name ?? '';

        if (jobSelect) {
          jobSelect.value = config.job_id ?? '';
          if (jobSelect.value === '' && config.job_id) {
            const option = document.createElement('option');
            option.value = config.job_id;
            option.textContent = config.job_id;
            option.selected = true;
            jobSelect.appendChild(option);
          }
        }

        const scope = document.getElementById('config_job_scope');
        if (scope && config.job_scope) scope.value = config.job_scope;

        if (quantityInput && config.quantity) {
          quantityInput.value = config.quantity;
          syncDoorTags();
        }

        const status = document.getElementById('config_status');
        if (status && config.status) status.value = config.status;

        const notes = document.getElementById('config_notes');
        if (notes) notes.value = config.notes ?? '';

        if (Array.isArray(config.door_tags) && doorTagsContainer) {
          syncDoorTags();
          const inputs = doorTagsContainer.querySelectorAll('input[name="door_tags[]"]');
          inputs.forEach((input, index) => {
            input.value = config.door_tags[index] ?? '';
          });
        }

        const configIdField = document.querySelector('input[name="config_id"]');
        if (configIdField && data.id) {
          configIdField.value = data.id;
        }
      }

      function applyEntryForm(data) {
        const entry = data?.entry;
        if (!entry) return;
        const openingType = document.getElementById('entry_opening_type');
        if (openingType && entry.opening_type) openingType.value = entry.opening_type;
        const handSingle = document.getElementById('entry_hand_single');
        if (handSingle && entry.hand_single) handSingle.value = entry.hand_single;
        const handPair = document.getElementById('entry_hand_pair');
        if (handPair && entry.hand_pair) handPair.value = entry.hand_pair;
        const glazing = document.getElementById('entry_door_glazing');
        if (glazing && entry.door_glazing) glazing.value = entry.door_glazing;
        const transom = document.getElementById('entry_transom');
        if (transom && entry.transom) transom.value = entry.transom;
        const transomGlazing = document.getElementById('entry_transom_glazing');
        if (transomGlazing && entry.transom_glazing) transomGlazing.value = entry.transom_glazing;
        const elevation = document.getElementById('entry_elevation');
        if (elevation) elevation.value = entry.elevation ?? '';
        const opening = document.getElementById('entry_opening');
        if (opening) opening.value = entry.opening ?? '';
        const notes = document.getElementById('entry_notes');
        if (notes) notes.value = entry.notes ?? '';
        syncHands();
        syncTransomGlazing();
      }

      function applyFrameForm(data) {
        const frame = data?.frame;
        if (!frame) return;
        const material = document.getElementById('frame_material');
        if (material && frame.material) material.value = frame.material;
        const profile = document.getElementById('frame_profile');
        if (profile && frame.profile) profile.value = frame.profile;
        const anchor = document.getElementById('frame_anchor_type');
        if (anchor && frame.anchor_type) anchor.value = frame.anchor_type;
        const head = document.getElementById('frame_head_condition');
        if (head && frame.head_condition) head.value = frame.head_condition;
        const sill = document.getElementById('frame_sill_condition');
        if (sill && frame.sill_condition) sill.value = frame.sill_condition;
        const notes = document.getElementById('frame_notes');
        if (notes) notes.value = frame.notes ?? '';
      }

      function applyDoorForm(data) {
        const door = data?.door;
        if (!door) return;
        const type = document.getElementById('door_type');
        if (type && door.door_type) type.value = door.door_type;
        const thickness = document.getElementById('door_thickness');
        if (thickness && door.thickness) thickness.value = door.thickness;
        const core = document.getElementById('door_core');
        if (core && door.core) core.value = door.core;
        const liteKit = document.getElementById('door_lite_kit');
        if (liteKit && door.lite_kit) liteKit.value = door.lite_kit;
        const bottomRail = document.getElementById('door_bottom_rail');
        if (bottomRail && door.bottom_rail) bottomRail.value = door.bottom_rail;
        const notes = document.getElementById('door_notes');
        if (notes) notes.value = door.notes ?? '';
      }

      function applyHardwareForm(data) {
        const hardware = data?.hardware;
        if (!hardware) return;
        const set = document.getElementById('hardware_set');
        if (set && hardware.set_name) set.value = hardware.set_name;
        const hinge = document.getElementById('hardware_hinge_prep');
        if (hinge && hardware.hinge_prep) hinge.value = hardware.hinge_prep;
        const strike = document.getElementById('hardware_strike_prep');
        if (strike && hardware.strike_prep) strike.value = hardware.strike_prep;
        const closer = document.getElementById('hardware_closer');
        if (closer && hardware.closer) closer.value = hardware.closer;
        const electrified = document.getElementById('hardware_electrified');
        if (electrified && hardware.electrified) electrified.value = hardware.electrified;
        const notes = document.getElementById('hardware_notes');
        if (notes) notes.value = hardware.notes ?? '';
      }

      function applySummary(data) {
        const summary = document.querySelector('textarea[name="summary_notes"]');
        if (summary && data?.summary_notes !== undefined) {
          summary.value = data.summary_notes ?? '';
        }
      }

      function applyActiveRecord(record) {
        applyConfigurationForm(record);
        applyEntryForm(record);
        applyFrameForm(record);
        applyDoorForm(record);
        applyHardwareForm(record);
        applySummary(record);
      }

      function attachJobForm() {
        if (!jobForm) return;
        jobForm.addEventListener('submit', (event) => {
          event.preventDefault();
          if (jobNumberError) jobNumberError.textContent = '';
          if (jobNameError) jobNameError.textContent = '';
          if (jobFeedback) jobFeedback.textContent = '';

          const numberValue = document.getElementById('job_number')?.value.trim() ?? '';
          const nameValue = document.getElementById('job_name')?.value.trim() ?? '';

          if (numberValue === '' && jobNumberError) {
            jobNumberError.textContent = 'Job number is required.';
          }

          if (nameValue === '' && jobNameError) {
            jobNameError.textContent = 'Job name is required.';
          }

          if (numberValue === '' || nameValue === '') {
            return;
          }

          const newJob = {
            id: `job_${Date.now()}`,
            job_number: numberValue,
            name: nameValue,
            created_at: new Date().toISOString(),
          };

          jobs.push(newJob);
          writeJson(JOB_KEY, jobs);
          renderJobs();

          const numberInput = document.getElementById('job_number');
          const nameInput = document.getElementById('job_name');
          if (numberInput) numberInput.value = '';
          if (nameInput) nameInput.value = '';
          if (jobFeedback) {
            jobFeedback.textContent = 'Job saved locally. It will sync to the database later.';
          }
        });
      }

      if (window.localSavePayload) {
        const payload = window.localSavePayload;
        const existingIndex = configs.findIndex((item) => item.id === payload.id);
        if (existingIndex >= 0) {
          configs[existingIndex] = payload;
        } else {
          configs.push(payload);
        }
        writeJson(CONFIG_KEY, configs);
        writeJson(ACTIVE_KEY, payload);
        activeRecord = payload;
      }

      if (window.localBuilderForms) {
        activeRecord = {
          ...(activeRecord || {}),
          id: window.localBuilderConfigId || (activeRecord ? activeRecord.id : null),
          ...window.localBuilderForms,
          configuration: {
            ...(window.localBuilderForms.configuration || {}),
            id: window.localBuilderConfigId || (activeRecord ? activeRecord.id : null),
          },
        };
        writeJson(ACTIVE_KEY, activeRecord);
      }

      attachJobForm();
      renderJobs();
      if (activeRecord) {
        applyActiveRecord(activeRecord);
      }
      renderConfigs();
    })();
  </script>

</body>
</html>
