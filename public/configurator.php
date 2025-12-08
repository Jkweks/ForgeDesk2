<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/configurator.php';
require_once __DIR__ . '/../app/data/inventory.php';

session_start();

$databaseConfig = $app['database'];
$localStorageOnly = false;
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
    'job_id' => '',
    'job_scope' => 'door_and_frame',
    'quantity' => 1,
    'status' => 'draft',
    'door_tags' => [],
];
$db = null;
$entryFormData = [
    'opening_type' => 'single',
    'hand_single' => 'LH - Inswing',
    'hand_pair' => 'RHR Active',
    'finish' => 'C2',
    'door_opening_width' => '',
    'door_opening_height' => '',
    'hinging' => 'continuous',
];
$frameFormData = [
    'system_id' => '',
    'glazing' => '1/4"',
    'transom' => 'no',
    'transom_glazing' => '1/4"',
    'transom_height' => '',
    'parts' => [],
];
$doorLeafDefaults = [
    'stile' => 'Standard Medium Stile',
    'glazing' => '1/4"',
    'parts' => [],
];

$doorFormData = [
    'system_id' => '',
    'active' => $doorLeafDefaults,
    'inactive' => $doorLeafDefaults,
];

$framePartDefinitions = configuratorFrameParts(
    $entryFormData['opening_type'],
    $frameFormData['transom'],
    $frameFormData['transom_glazing']
);
$frameFormData['parts'] = configuratorNormalizePartSelections($framePartDefinitions, $frameFormData['parts']);
$doorPartDefinitionsActive = configuratorDoorParts($doorFormData['active']['glazing']);
$doorPartDefinitionsInactive = configuratorDoorParts($doorFormData['inactive']['glazing']);
$doorFormData['active']['parts'] = configuratorNormalizePartSelections($doorPartDefinitionsActive, $doorFormData['active']['parts']);
$doorFormData['inactive']['parts'] = configuratorNormalizePartSelections($doorPartDefinitionsInactive, $doorFormData['inactive']['parts']);
$openingTypeOptions = [
    'single' => 'Single',
    'pair' => 'Pair',
];
$handOptionsPair = [
    'RHR Active' => 'RHR Active (default)',
    'LHRA Active' => 'LHRA Active — uncommon, confirm before selecting',
];
$handOptionsSingle = [
    'LH - Inswing' => 'LH - Inswing',
    'RH - Inswing' => 'RH - Inswing',
    'RHR - LH Outswing' => 'RHR - LH Outswing',
    'LHR - RH Outswing' => 'LHR - RH Outswing',
];
$finishOptions = [
    'C2' => 'C2',
    'DB' => 'DB',
    'BL' => 'BL',
];
$glazingOptions = [
    '1/4"',
    '1/2"',
    '1"',
];
$transomOptions = [
    'yes' => 'Yes',
    'no' => 'No',
];
$hingingOptions = [
    'continuous' => 'Continuous Hinge',
    'butt' => 'Butt Hinge',
    'pivot_offset' => 'Pivot - Offset',
    'pivot_center' => 'Pivot - Center',
];
$frameGlazingOptions = $glazingOptions;
$frameSystemOptions = [];
$frameSystemTrace = [
    'db_config' => [
        'host' => $databaseConfig['host'],
        'port' => $databaseConfig['port'],
        'name' => $databaseConfig['name'],
        'user' => $databaseConfig['user'],
    ],
    'db_error' => null,
    'query' => "SELECT system FROM public.inventory_systems WHERE system_type = 'framing' ORDER BY id ASC",
    'loaded' => [],
    'error' => null,
    'no_results' => false,
    'fallback_used' => false,
    'fallback_values' => [],
];
$doorSystemOptions = [];
$doorStileOptions = [];

function configuratorNormalizePartSelections(array $definitions, array $existing): array
{
    $normalized = [];

    foreach ($definitions as $definition) {
        $normalized[$definition['id']] = isset($existing[$definition['id']])
            ? (string) $existing[$definition['id']]
            : '';
    }

    return $normalized;
}

/**
 * @return list<array{id:string,label:string,use_path:list<string>,part_type:string}>
 */
function configuratorFrameParts(string $openingType, string $transom, string $transomGlazing): array
{
    $parts = [];

    if ($openingType === 'pair') {
        $parts = [
            ['id' => 'lh_hinge_rail', 'label' => 'LH Hinge Rail', 'use_path' => ['Frame', 'Pair Frame Rails', 'LH Hinge Rail'], 'part_type' => 'frame'],
            ['id' => 'rh_hinge_rail', 'label' => 'RH Hinge Rail', 'use_path' => ['Frame', 'Pair Frame Rails', 'RH Hinge Rail'], 'part_type' => 'frame'],
            ['id' => 'door_head', 'label' => 'Door Head', 'use_path' => ['Frame', 'Frame Head', 'Door Head'], 'part_type' => 'frame'],
            ['id' => 'head_door_stop', 'label' => 'Head Door Stop', 'use_path' => ['Frame', 'Frame Stops', 'Head Door Stop'], 'part_type' => 'frame'],
            ['id' => 'lh_door_stop', 'label' => 'LH Door Stop', 'use_path' => ['Frame', 'Frame Stops', 'Hinge Door Stop'], 'part_type' => 'frame'],
            ['id' => 'rh_door_stop', 'label' => 'RH Door Stop', 'use_path' => ['Frame', 'Frame Stops', 'Lock Door Stop'], 'part_type' => 'frame'],
        ];
    } else {
        $parts = [
            ['id' => 'hinge_jamb', 'label' => 'Hinge Jamb', 'use_path' => ['Frame', 'Frame Jambs', 'Hinge Jamb'], 'part_type' => 'frame'],
            ['id' => 'lock_jamb', 'label' => 'Lock Jamb', 'use_path' => ['Frame', 'Frame Jambs', 'Lock Jamb'], 'part_type' => 'frame'],
            ['id' => 'door_head', 'label' => 'Door Head', 'use_path' => ['Frame', 'Frame Head', 'Door Head'], 'part_type' => 'frame'],
            ['id' => 'head_door_stop', 'label' => 'Head Door Stop', 'use_path' => ['Frame', 'Frame Stops', 'Head Door Stop'], 'part_type' => 'frame'],
            ['id' => 'lock_door_stop', 'label' => 'Lock Door Stop', 'use_path' => ['Frame', 'Frame Stops', 'Lock Door Stop'], 'part_type' => 'frame'],
            ['id' => 'hinge_door_stop', 'label' => 'Hinge Door Stop', 'use_path' => ['Frame', 'Frame Stops', 'Hinge Door Stop'], 'part_type' => 'frame'],
        ];
    }

    if ($transom === 'yes') {
        $parts[] = ['id' => 'door_head_transom_active', 'label' => 'Door Head Transom Stop - Active', 'use_path' => ['Frame', 'Transom', 'Door Head Transom Stop - Active'], 'part_type' => 'frame'];
        $parts[] = ['id' => 'door_head_transom_fixed', 'label' => 'Door Head Transom Stop - Fixed', 'use_path' => ['Frame', 'Transom', 'Door Head Transom Stop - Fixed'], 'part_type' => 'frame'];
        $parts[] = ['id' => 'vertical_transom_active', 'label' => 'Vertical Transom Stop - Active', 'use_path' => ['Frame', 'Transom', 'Vertical Transom Stop - Active'], 'part_type' => 'frame'];
        $parts[] = ['id' => 'vertical_transom_fixed', 'label' => 'Vertical Transom Stop - Fixed', 'use_path' => ['Frame', 'Transom', 'Vertical Transom Stop - Fixed'], 'part_type' => 'frame'];

        if ($transomGlazing === '1/2"') {
            $parts[] = ['id' => 'half_glass_adapter', 'label' => '½ Glass adapter', 'use_path' => ['Frame', 'Transom', '½ Glass adapter'], 'part_type' => 'frame'];
        } elseif ($transomGlazing === '1/4"') {
            $parts[] = ['id' => 'quarter_glass_adapter', 'label' => '¼ Glass adapter', 'use_path' => ['Frame', 'Transom', '¼ Glass adapter'], 'part_type' => 'frame'];
        }
    }

    return $parts;
}

/**
 * @return list<array{id:string,label:string,use_path:list<string>,part_type:string}>
 */
function configuratorDoorParts(string $glazing): array
{
    $parts = [
        ['id' => 'hinge_rail_standard', 'label' => 'Hinge Rail', 'use_path' => ['Door', 'Stiles', 'Hinge Rail'], 'part_type' => 'door'],
        ['id' => 'hinge_rail_ws_a', 'label' => 'Hinge Rail A (WS - Continuous Hinge)', 'use_path' => ['Door', 'Stiles', 'Hinge Rail'], 'part_type' => 'door'],
        ['id' => 'hinge_rail_ws_b', 'label' => 'Hinge Rail B (WS - Butt Hinge)', 'use_path' => ['Door', 'Stiles', 'Hinge Rail'], 'part_type' => 'door'],
        ['id' => 'lock_rail', 'label' => 'Lock Rail', 'use_path' => ['Door', 'Stiles', 'Lock Rail'], 'part_type' => 'door'],
        ['id' => 'top_rail', 'label' => 'Top Rail', 'use_path' => ['Door', 'Stiles', 'Top Rail'], 'part_type' => 'door'],
        ['id' => 'bottom_rail', 'label' => 'Bottom Rail', 'use_path' => ['Door', 'Stiles', 'Bottom Rail'], 'part_type' => 'door'],
        ['id' => 'interior_glass_stop', 'label' => 'Interior Glass Stops — generated from glazing', 'use_path' => ['Door', 'Glazing', 'Interior Glass Stop'], 'part_type' => 'door'],
        ['id' => 'exterior_glass_stop', 'label' => 'Exterior Glass Stops — generated from glazing', 'use_path' => ['Door', 'Glazing', 'Exterior Glass Stop'], 'part_type' => 'door'],
        ['id' => 'interior_glass_vinyl', 'label' => 'Interior Glass Vinyl — generated from glazing', 'use_path' => ['Door', 'Glazing', 'Interior Glass Vinyl'], 'part_type' => 'door'],
        ['id' => 'exterior_glass_vinyl', 'label' => 'Exterior Glass Vinyl — generated from glazing', 'use_path' => ['Door', 'Glazing', 'Exterior Glass Vinyl'], 'part_type' => 'door'],
        ['id' => 'door_set_block', 'label' => 'Door Set block — generated from glazing', 'use_path' => ['Door', 'Glazing', 'Door Set block'], 'part_type' => 'door'],
        ['id' => 'door_glass_jack', 'label' => 'Door glass jack — generated from glazing', 'use_path' => ['Door', 'Glazing', 'Door glass jack'], 'part_type' => 'door'],
    ];

    $parts[] = ['id' => 'glazing_reference', 'label' => sprintf('Glazing package reference: %s', $glazing), 'use_path' => ['Door', 'Glazing'], 'part_type' => 'door'];

    return $parts;
}
$defaultBuilderForms = [
    'configuration' => $configFormData,
    'entry' => $entryFormData,
    'frame' => $frameFormData,
    'door' => $doorFormData,
];
$editingConfigId = null;
$builderSteps = [
    ['id' => 'configuration', 'label' => 'Configuration data', 'description' => 'Job, scope, quantity, and door IDs'],
    ['id' => 'entry', 'label' => 'Entry data', 'description' => 'Opening type, hand, finish, and measurements'],
    ['id' => 'frame', 'label' => 'Frame data', 'description' => 'System, glazing, and transom parts (if required)'],
    ['id' => 'door', 'label' => 'Door data', 'description' => 'Stile, glazing, and leaf parts (if required)'],
    ['id' => 'summary', 'label' => 'Summary & cut list', 'description' => 'Bill of materials and cut information'],
];
$stepIds = array_map(static fn (array $step): string => $step['id'], $builderSteps);
$currentStep = 'configuration';
$builderSessionKey = 'configurator_builder';
$redirectStep = null;
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

    $frameSystemTrace['db_error'] = $dbError;
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

        try {
            $systems = inventoryListSystems($db, 'framing');
            foreach ($systems as $system) {
                $frameSystemOptions[(string) $system['id']] = (string) $system['system'];
                $frameSystemTrace['loaded'][] = [
                    'id' => (string) $system['id'],
                    'label' => (string) $system['system'],
                ];
            }
        } catch (\Throwable $exception) {
            $errors[] = 'Unable to load frame systems: ' . $exception->getMessage();
            $frameSystemTrace['error'] = $exception->getMessage();
            $frameSystemOptions = [];
        }

        $frameSystemTrace['no_results'] = $frameSystemOptions === [];

        try {
            $doorSystems = inventoryListSystems($db, 'door');
            foreach ($doorSystems as $system) {
                $doorSystemOptions[(string) $system['id']] = trim($system['manufacturer'] . ' ' . $system['system']);
            }
        } catch (\Throwable $exception) {
            $errors[] = 'Unable to load door systems: ' . $exception->getMessage();
            $doorSystemOptions = [];
        }
    }
}

if ($frameSystemOptions === []) {
    $frameSystemOptions = [
        'draft_system_alpha' => 'Draft - System Alpha',
        'draft_system_beta' => 'Draft - System Beta',
        'draft_system_gamma' => 'Draft - System Gamma',
    ];
    $frameSystemTrace['fallback_used'] = true;
    $frameSystemTrace['fallback_values'] = array_values($frameSystemOptions);
}

$frameSystemTrace['options'] = $frameSystemOptions;

if ($doorSystemOptions === []) {
    $doorSystemOptions = [
        'draft_ws_continuous' => 'Draft - WS Continuous Hinge',
        'draft_ws_butt' => 'Draft - WS Butt Hinge',
    ];
}

$doorStileOptions = $doorSystemOptions;

$defaultDoorStile = array_key_first($doorStileOptions) ?? '';
if (!array_key_exists($doorFormData['active']['stile'], $doorStileOptions)) {
    $doorFormData['active']['stile'] = $defaultDoorStile;
}
if (!array_key_exists($doorFormData['inactive']['stile'], $doorStileOptions)) {
    $doorFormData['inactive']['stile'] = $defaultDoorStile;
}

    $requestedConfigId = isset($_GET['id']) && ctype_digit((string) $_GET['id'])
        ? (int) $_GET['id']
        : null;
    $requestedStepQuery = isset($_GET['step']) && in_array($_GET['step'], $stepIds, true)
        ? (string) $_GET['step']
        : null;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $hasActiveBuilder = $builderState['config_payload'] !== null
            || $builderState['config_id'] !== null
            || $builderState['completed'] !== [];

        if (isset($_GET['create'])) {
            if (!$hasActiveBuilder || $requestedStepQuery === null || $requestedStepQuery === 'configuration') {
                $resetBuilderState(null);
            }
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
                    'job_id' => $existingConfig['job_id'] !== null ? (string) $existingConfig['job_id'] : '',
                    'job_scope' => $existingConfig['job_scope'],
                    'quantity' => $existingConfig['quantity'],
                    'status' => $existingConfig['status'],
                    'door_tags' => $existingConfig['door_tags'],
                ];
                $builderState['config_payload'] = [
                    'name' => $existingConfig['name'],
                    'job_id' => $existingConfig['job_id'],
                    'job_scope' => $existingConfig['job_scope'],
                    'quantity' => (int) $existingConfig['quantity'],
                    'status' => $existingConfig['status'],
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
                    'job_id' => $template['job_id'] !== null ? (string) $template['job_id'] : '',
                    'job_scope' => $template['job_scope'],
                    'quantity' => 1,
                    'status' => $template['status'],
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
        $finishRaw = $_POST['entry_finish'] ?? null;
        if ($finishRaw !== null && array_key_exists($finishRaw, $finishOptions)) {
            $entryFormData['finish'] = (string) $finishRaw;
        }
        $entryFormData['door_opening_width'] = isset($_POST['entry_door_opening_width'])
            ? trim((string) $_POST['entry_door_opening_width'])
            : $entryFormData['door_opening_width'];
        $entryFormData['door_opening_height'] = isset($_POST['entry_door_opening_height'])
            ? trim((string) $_POST['entry_door_opening_height'])
            : $entryFormData['door_opening_height'];
        $hingingRaw = $_POST['entry_hinging'] ?? null;
        if ($hingingRaw !== null && array_key_exists($hingingRaw, $hingingOptions)) {
            $entryFormData['hinging'] = (string) $hingingRaw;
        }

        $frameSystemRaw = $_POST['frame_system_id'] ?? null;
        if ($frameSystemRaw !== null && array_key_exists($frameSystemRaw, $frameSystemOptions)) {
            $frameFormData['system_id'] = (string) $frameSystemRaw;
        }

        $frameGlazingRaw = $_POST['frame_glazing'] ?? null;
        if ($frameGlazingRaw !== null && in_array($frameGlazingRaw, $frameGlazingOptions, true)) {
            $frameFormData['glazing'] = (string) $frameGlazingRaw;
        }

        $frameTransomRaw = $_POST['frame_transom'] ?? null;
        if ($frameTransomRaw !== null && isset($transomOptions[$frameTransomRaw])) {
            $frameFormData['transom'] = (string) $frameTransomRaw;
        }

        $frameTransomGlazingRaw = $_POST['frame_transom_glazing'] ?? null;
        if ($frameTransomGlazingRaw !== null && in_array($frameTransomGlazingRaw, $glazingOptions, true)) {
            $frameFormData['transom_glazing'] = (string) $frameTransomGlazingRaw;
        }

        $frameFormData['transom_height'] = $frameFormData['transom'] === 'yes'
            ? trim((string) ($_POST['frame_transom_height'] ?? ''))
            : '';

        $framePartDefinitions = configuratorFrameParts(
            $entryFormData['opening_type'],
            $frameFormData['transom'],
            $frameFormData['transom_glazing']
        );
        $framePartSelectionRaw = $_POST['frame_part_selection'] ?? [];
        $frameSelections = [];
        if (is_array($framePartSelectionRaw)) {
            foreach ($framePartSelectionRaw as $partKey => $value) {
                $frameSelections[$partKey] = trim((string) $value);
            }
        }
        $frameFormData['parts'] = configuratorNormalizePartSelections($framePartDefinitions, $frameSelections);

        $doorSystemRaw = $_POST['door_system_id'] ?? null;
        if ($doorSystemRaw !== null && array_key_exists($doorSystemRaw, $doorSystemOptions)) {
            $doorFormData['system_id'] = (string) $doorSystemRaw;
        }

        foreach (['active', 'inactive'] as $leafKey) {
            $stileRaw = $_POST['door_' . $leafKey . '_stile'] ?? null;
            if ($stileRaw !== null && array_key_exists($stileRaw, $doorStileOptions)) {
                $doorFormData[$leafKey]['stile'] = (string) $stileRaw;
            }

            $glazingRaw = $_POST['door_' . $leafKey . '_glazing'] ?? null;
            if ($glazingRaw !== null && in_array($glazingRaw, $glazingOptions, true)) {
                $doorFormData[$leafKey]['glazing'] = (string) $glazingRaw;
            }

            $partDefinitions = $leafKey === 'active'
                ? configuratorDoorParts($doorFormData['active']['glazing'])
                : configuratorDoorParts($doorFormData['inactive']['glazing']);

            $partsRaw = $_POST['door_' . $leafKey . '_part_selection'] ?? null;
            $partSelections = [];
            if (is_array($partsRaw)) {
                foreach ($partsRaw as $partKey => $value) {
                    $partSelections[$partKey] = trim((string) $value);
                }
            }

            $doorFormData[$leafKey]['parts'] = configuratorNormalizePartSelections($partDefinitions, $partSelections);
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
        } elseif ($action === 'stage_configuration') {
            $jobIdRaw = trim((string) ($_POST['config_job_id'] ?? ''));
            $configScope = (string) ($_POST['config_job_scope'] ?? 'door_and_frame');
            $configQuantityRaw = trim((string) ($_POST['config_quantity'] ?? '1'));
            $configStatus = trim((string) ($_POST['config_status'] ?? 'draft'));
            $doorTagsRaw = isset($_POST['door_tags']) && is_array($_POST['door_tags']) ? $_POST['door_tags'] : [];

            $configFormData = [
                'job_id' => $jobIdRaw,
                'job_scope' => $configScope,
                'quantity' => $configQuantityRaw,
                'status' => $configStatus,
                'door_tags' => array_map('strval', $doorTagsRaw),
            ];

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
                $configName = $builderState['config_payload']['name'] ?? ($doorTags[0] ?? 'Door configuration');
                $payload = [
                    'name' => $configName,
                    'job_id' => $jobId,
                    'job_scope' => array_key_exists($configScope, $jobScopeOptions) ? $configScope : 'door_and_frame',
                    'quantity' => $quantity,
                    'status' => $configStatus,
                    'door_tags' => $doorTags,
                ];

                $builderState['forms']['configuration'] = $configFormData;
                $builderState['config_payload'] = $payload;
                $builderState['config_id'] = $editingConfigId;
                $builderState['completed'] = array_values(array_unique(array_merge($builderState['completed'], ['configuration'])));
                $builderState['current_step'] = $computeTargetStep('configuration', $targetStep ?? 'entry');
                $redirectStep = $builderState['current_step'];
            } else {
                $builderState['current_step'] = 'configuration';
            }
        } elseif ($action === 'stage_entry') {
            $builderState['forms']['entry'] = $entryFormData;
            $builderState['completed'] = array_values(array_unique(array_merge($builderState['completed'], ['configuration', 'entry'])));
            $builderState['current_step'] = $computeTargetStep('entry', $targetStep ?? 'frame');
            $redirectStep = $builderState['current_step'];
        } elseif ($action === 'stage_frame') {
            $builderState['forms']['frame'] = $frameFormData;
            $builderState['completed'] = array_values(array_unique(array_merge($builderState['completed'], ['configuration', 'entry', 'frame'])));
            $builderState['current_step'] = $computeTargetStep('frame', $targetStep ?? 'door');
            $redirectStep = $builderState['current_step'];
        } elseif ($action === 'stage_door') {
            $builderState['forms']['door'] = $doorFormData;
            $builderState['completed'] = array_values(array_unique(array_merge($builderState['completed'], ['configuration', 'entry', 'frame', 'door'])));
            $builderState['current_step'] = $computeTargetStep('door', $targetStep ?? 'summary');
            $redirectStep = $builderState['current_step'];
        } elseif ($action === 'finalize_configuration') {
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
                    'updated_at' => date(DATE_ATOM),
                ];

                $builderState['config_id'] = $localSavePayload['id'];
                $builderState['completed'] = $stepIds;
                $builderState['current_step'] = 'summary';
                $successMessage = 'Configuration saved locally in this browser. Database storage will be added later.';
            }
        }

        $_SESSION[$builderSessionKey] = $builderState;

        if ($redirectStep !== null && $errors === []) {
            $query = http_build_query([
                'create' => '1',
                'step' => $builderState['current_step'],
            ]);
            header('Location: configurator.php?' . $query);
            exit;
        }
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
    $isPairOpening = $entryFormData['opening_type'] === 'pair';
    $doorLeafLabels = $isPairOpening
        ? ['active' => $entryFormData['hand_pair'], 'inactive' => $entryFormData['hand_pair'] === 'LHRA Active' ? 'RHR Inactive' : 'LHR Inactive']
        : ['active' => $entryFormData['hand_single']];
    $editingConfigId = $builderState['config_id'];

    $framePartDefinitions = configuratorFrameParts(
        $entryFormData['opening_type'],
        $frameFormData['transom'],
        $frameFormData['transom_glazing']
    );
    $frameFormData['parts'] = configuratorNormalizePartSelections($framePartDefinitions, $frameFormData['parts']);

    $doorPartDefinitionsActive = configuratorDoorParts($doorFormData['active']['glazing']);
    $doorPartDefinitionsInactive = configuratorDoorParts($doorFormData['inactive']['glazing']);
    $doorFormData['active']['parts'] = configuratorNormalizePartSelections($doorPartDefinitionsActive, $doorFormData['active']['parts']);
    $doorFormData['inactive']['parts'] = configuratorNormalizePartSelections($doorPartDefinitionsInactive, $doorFormData['inactive']['parts']);

    $framePartOptions = [];
    $doorPartOptions = ['active' => [], 'inactive' => []];

    if (!$localStorageOnly && $db !== null) {
        foreach ($framePartDefinitions as $definition) {
            $framePartOptions[$definition['id']] = configuratorInventoryOptionsByUse($db, $definition['use_path'], $definition['part_type']);
        }

        foreach ($doorPartDefinitionsActive as $definition) {
            $doorPartOptions['active'][$definition['id']] = configuratorInventoryOptionsByUse($db, $definition['use_path'], $definition['part_type']);
        }

        foreach ($doorPartDefinitionsInactive as $definition) {
            $doorPartOptions['inactive'][$definition['id']] = configuratorInventoryOptionsByUse($db, $definition['use_path'], $definition['part_type']);
        }
    }

    if (!$localStorageOnly) {
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
    || (isset($_POST['action']) && in_array($_POST['action'], [
        'stage_configuration',
        'stage_entry',
        'stage_frame',
        'stage_door',
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
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="config_quantity">Quantity<span aria-hidden="true">*</span></label>
                    <input type="number" min="1" id="config_quantity" name="config_quantity" value="<?= e((string) $configFormData['quantity']) ?>" required />
                    <p class="small muted">Door ID count must match this quantity.</p>
                    <?php if (!empty($errors['config_quantity'])): ?>
                      <p class="field-error"><?= e($errors['config_quantity']) ?></p>
                    <?php endif; ?>
                  </div>

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
                </div>

                <div class="field">
                  <label>Door IDs<span aria-hidden="true">*</span></label>
                  <p class="small muted">Provide one ID per opening. The number of IDs must equal the quantity.</p>
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
                  <div class="field">
                    <label for="entry_finish">Finish</label>
                    <select id="entry_finish" name="entry_finish">
                      <?php foreach ($finishOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $entryFormData['finish'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
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
                    <p class="small muted" data-lhra-warning>LHRA Active is uncommon. Verify this choice with the team before continuing.</p>
                  </div>
                  <div class="field">
                    <label for="entry_hinging">Hinging</label>
                    <select id="entry_hinging" name="entry_hinging">
                      <?php foreach ($hingingOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $entryFormData['hinging'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="entry_door_opening_width">Door opening width (DOW)</label>
                    <input type="text" id="entry_door_opening_width" name="entry_door_opening_width" placeholder="DOW" value="<?= e($entryFormData['door_opening_width']) ?>" />
                  </div>
                  <div class="field">
                    <label for="entry_door_opening_height">Door opening height (DOH)</label>
                    <input type="text" id="entry_door_opening_height" name="entry_door_opening_height" placeholder="DOH" value="<?= e($entryFormData['door_opening_height']) ?>" />
                  </div>
                </div>
                <p class="small muted">Opening dimensions and hand drive every downstream step. Elevation notes will be added in a future update.</p>
                <div class="form-actions">
                  <button type="submit" class="button secondary" name="navigate_to" value="configuration">Back to configuration</button>
                  <button type="submit" class="button primary" name="navigate_to" value="frame">Continue to frame data</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'frame'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_frame" />
                <?php if (($configFormData['job_scope'] ?? 'door_and_frame') === 'door_only'): ?>
                  <div class="alert info" role="status">Frame selections are optional for a door-only scope. Keep values blank if no frame is required.</div>
                <?php endif; ?>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="frame_system_id">Frame system</label>
                    <select id="frame_system_id" name="frame_system_id">
                      <option value="">Select a system</option>
                      <?php foreach ($frameSystemOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $frameFormData['system_id'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <p class="small muted">Pulled from the inventory systems directory.</p>
                  </div>
                  <div class="field">
                    <label for="frame_glazing">Frame glazing</label>
                    <select id="frame_glazing" name="frame_glazing">
                      <?php foreach ($frameGlazingOptions as $option): ?>
                        <option value="<?= e($option) ?>"<?= $frameFormData['glazing'] === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="field-grid two-column">
                  <div class="field">
                    <label for="frame_transom">Transom</label>
                    <select id="frame_transom" name="frame_transom" data-frame-transom>
                      <?php foreach ($transomOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $frameFormData['transom'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"></div>
                </div>

                <div class="field-grid two-column" data-frame-transom-height>
                  <div class="field">
                    <label for="frame_transom_height">Total frame height (with transom)</label>
                    <input type="text" id="frame_transom_height" name="frame_transom_height" placeholder="Example: 11' - 0\"" value="<?= e($frameFormData['transom_height']) ?>" />
                  </div>
                  <div class="field">
                    <label for="frame_transom_glazing">Transom glazing</label>
                    <select id="frame_transom_glazing" name="frame_transom_glazing" data-frame-transom-glazing>
                      <?php foreach ($glazingOptions as $option): ?>
                        <option value="<?= e($option) ?>"<?= $frameFormData['transom_glazing'] === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <p class="small muted">Only required when a transom is present.</p>
                  </div>
                </div>

                <div class="field">
                  <label>Frame parts list</label>
                  <div class="stacked gap-xs" aria-live="polite">
                    <?php foreach ($framePartDefinitions as $definition): ?>
                      <?php $selected = $frameFormData['parts'][$definition['id']] ?? ''; ?>
                      <?php $options = $framePartOptions[$definition['id']] ?? []; ?>
                      <label class="stacked">
                        <span><?= e($definition['label']) ?></span>
                        <select name="frame_part_selection[<?= e($definition['id']) ?>]">
                          <option value="">Select a part</option>
                          <?php foreach ($options as $option): ?>
                            <option value="<?= e((string) $option['id']) ?>"<?= (string) $selected === (string) $option['id'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <p class="small muted">Filtered by <?= e(implode(' → ', $definition['use_path'])) ?> (frame).</p>
                      </label>
                      <?php if ($options === []): ?>
                        <p class="small muted">No matching frame parts available for this use type.</p>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                </div>
                <p class="small muted">Use this stage to outline frame system, glazing, and transom stops. Additional frame details will be added later.</p>
                <div class="form-actions">
                  <button type="submit" class="button secondary" name="navigate_to" value="entry">Back to entry</button>
                  <button type="submit" class="button primary" name="navigate_to" value="door">Continue to door data</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'door'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="stage_door" />
                <p class="small muted">Configure each leaf independently. Tabs reflect the swing selected in the entry step.</p>

                <div class="field">
                  <label for="door_system_id">Door system</label>
                  <select id="door_system_id" name="door_system_id">
                    <option value="">Select a system</option>
                    <?php foreach ($doorSystemOptions as $value => $label): ?>
                      <option value="<?= e($value) ?>"<?= $doorFormData['system_id'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <p class="small muted">Pulled from the inventory systems directory and scoped to door packages.</p>
                </div>

                <div class="tabs" data-door-tabs>
                  <div class="tab-list" role="tablist">
                    <button type="button" role="tab" data-door-tab="active" aria-selected="true" class="tab-button"><?= e($doorLeafLabels['active']) ?></button>
                    <?php if ($isPairOpening): ?>
                      <button type="button" role="tab" data-door-tab="inactive" aria-selected="false" class="tab-button"><?= e($doorLeafLabels['inactive']) ?></button>
                    <?php endif; ?>
                  </div>

                  <div class="tab-panels">
                    <section data-door-panel="active" role="tabpanel">
                      <div class="field-grid two-column">
                        <div class="field">
                          <label for="door_active_stile">Stile</label>
                          <select id="door_active_stile" name="door_active_stile">
                            <?php foreach ($doorStileOptions as $value => $label): ?>
                              <option value="<?= e($value) ?>"<?= $doorFormData['active']['stile'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="field">
                          <label for="door_active_glazing">Glazing</label>
                          <select id="door_active_glazing" name="door_active_glazing">
                            <?php foreach ($glazingOptions as $option): ?>
                              <option value="<?= e($option) ?>"<?= $doorFormData['active']['glazing'] === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                      <div class="field-grid two-column">
                        <div class="field">
                          <label for="door_active_preset" class="small">Apply preset</label>
                          <select id="door_active_preset" name="door_active_preset" data-door-preset="active">
                            <option value="standard">Standard glazing-driven</option>
                            <option value="ws_continuous">WS — Continuous hinge (hinge rail A)</option>
                            <option value="ws_butt">WS — Butt hinge (hinge rail B)</option>
                            <option value="clear">Clear selection</option>
                          </select>
                          <p class="small muted">Presets drive hinge rail choice while keeping glazing-driven stops.</p>
                        </div>
                        <div class="field"></div>
                      </div>
                      <div class="field">
                        <label>Door parts list</label>
                        <div class="stacked gap-xs" aria-live="polite">
                          <?php foreach ($doorPartDefinitionsActive as $definition): ?>
                            <?php $selected = $doorFormData['active']['parts'][$definition['id']] ?? ''; ?>
                            <?php $options = $doorPartOptions['active'][$definition['id']] ?? []; ?>
                            <label class="stacked">
                              <span><?= e($definition['label']) ?></span>
                              <select name="door_active_part_selection[<?= e($definition['id']) ?>]">
                                <option value="">Select a part</option>
                                <?php foreach ($options as $option): ?>
                                  <option value="<?= e((string) $option['id']) ?>"<?= (string) $selected === (string) $option['id'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                                <?php endforeach; ?>
                              </select>
                              <p class="small muted">Filtered by <?= e(implode(' → ', $definition['use_path'])) ?> (door).</p>
                            </label>
                            <?php if ($options === []): ?>
                              <p class="small muted">No matching door parts available for this use type.</p>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </section>

                      <?php if ($isPairOpening): ?>
                        <section data-door-panel="inactive" role="tabpanel" hidden>
                          <div class="field-grid two-column">
                            <div class="field">
                              <label for="door_inactive_stile">Stile</label>
                            <select id="door_inactive_stile" name="door_inactive_stile">
                              <?php foreach ($doorStileOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $doorFormData['inactive']['stile'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="field">
                            <label for="door_inactive_glazing">Glazing</label>
                            <select id="door_inactive_glazing" name="door_inactive_glazing">
                              <?php foreach ($glazingOptions as $option): ?>
                                <option value="<?= e($option) ?>"<?= $doorFormData['inactive']['glazing'] === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                        </div>
                        <div class="field-grid two-column">
                          <div class="field">
                            <label for="door_inactive_preset" class="small">Apply preset</label>
                            <select id="door_inactive_preset" name="door_inactive_preset" data-door-preset="inactive">
                              <option value="standard">Standard glazing-driven</option>
                              <option value="ws_continuous">WS — Continuous hinge (hinge rail A)</option>
                              <option value="ws_butt">WS — Butt hinge (hinge rail B)</option>
                              <option value="clear">Clear selection</option>
                            </select>
                            <p class="small muted">Presets drive hinge rail choice while keeping glazing-driven stops.</p>
                          </div>
                          <div class="field"></div>
                        </div>
                        <div class="field">
                          <label>Door parts list</label>
                          <div class="stacked gap-xs" aria-live="polite">
                            <?php foreach ($doorPartDefinitionsInactive as $definition): ?>
                              <?php $selected = $doorFormData['inactive']['parts'][$definition['id']] ?? ''; ?>
                              <?php $options = $doorPartOptions['inactive'][$definition['id']] ?? []; ?>
                              <label class="stacked">
                                <span><?= e($definition['label']) ?></span>
                                <select name="door_inactive_part_selection[<?= e($definition['id']) ?>]">
                                  <option value="">Select a part</option>
                                  <?php foreach ($options as $option): ?>
                                    <option value="<?= e((string) $option['id']) ?>"<?= (string) $selected === (string) $option['id'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                                  <?php endforeach; ?>
                                </select>
                                <p class="small muted">Filtered by <?= e(implode(' → ', $definition['use_path'])) ?> (door).</p>
                              </label>
                              <?php if ($options === []): ?>
                                <p class="small muted">No matching door parts available for this use type.</p>
                              <?php endif; ?>
                            <?php endforeach; ?>
                          </div>
                        </div>
                      </section>
                      <?php endif; ?>
                  </div>
                </div>

                <p class="small muted">Outline the leaf construction to drive BOM selection. Parts lists react to the glazing choice for each leaf.</p>
                <div class="form-actions">
                  <button type="submit" class="button secondary" name="navigate_to" value="frame">Back to frame</button>
                  <button type="submit" class="button primary" name="navigate_to" value="summary">Review summary</button>
                </div>
              </form>
            <?php elseif ($currentStep === 'summary'): ?>
              <form method="post" class="form" novalidate>
                <input type="hidden" name="action" value="finalize_configuration" />
                <div class="card-grid two-column">
                  <div class="card">
                    <h3>Configuration</h3>
                    <ul class="stacked gap-xs">
                      <li><strong>Job:</strong> <?php
                        $jobLabel = 'Unassigned';
                        foreach ($jobs as $job) {
                            if ((string) $job['id'] === $configFormData['job_id']) {
                                $jobLabel = $job['job_number'] . ' — ' . $job['name'];
                                break;
                            }
                        }
                        echo e($jobLabel);
                      ?></li>
                      <li><strong>Scope:</strong> <?= e($jobScopeOptions[$configFormData['job_scope']] ?? $configFormData['job_scope']) ?></li>
                      <li><strong>Quantity:</strong> <?= e((string) $configFormData['quantity']) ?></li>
                      <li><strong>Status:</strong> <?= e(ucwords(str_replace('_', ' ', $configFormData['status']))) ?></li>
                      <li><strong>Door IDs:</strong> <?= e($configFormData['door_tags'] !== [] ? implode(', ', $configFormData['door_tags']) : 'Pending') ?></li>
                    </ul>
                  </div>
                  <div class="card">
                    <h3>Entry overview</h3>
                    <ul class="stacked gap-xs">
                      <li><strong>Opening type:</strong> <?= e($openingTypeOptions[$entryFormData['opening_type']] ?? $entryFormData['opening_type']) ?></li>
                      <li><strong>Hand:</strong> <?= e($entryFormData['opening_type'] === 'pair' ? ($handOptionsPair[$entryFormData['hand_pair']] ?? $entryFormData['hand_pair']) : ($handOptionsSingle[$entryFormData['hand_single']] ?? $entryFormData['hand_single'])) ?></li>
                      <li><strong>Finish:</strong> <?= e($finishOptions[$entryFormData['finish']] ?? $entryFormData['finish']) ?></li>
                      <li><strong>Hinging:</strong> <?= e($hingingOptions[$entryFormData['hinging']] ?? $entryFormData['hinging']) ?></li>
                      <li><strong>DOW × DOH:</strong> <?= e($entryFormData['door_opening_width'] !== '' ? $entryFormData['door_opening_width'] : 'TBD') ?> × <?= e($entryFormData['door_opening_height'] !== '' ? $entryFormData['door_opening_height'] : 'TBD') ?></li>
                    </ul>
                  </div>
                </div>

                <div class="card-grid two-column">
                  <div class="card">
                    <h3>Frame</h3>
                    <ul class="stacked gap-xs">
                      <li><strong>System:</strong> <?= $frameFormData['system_id'] !== '' ? e($frameSystemOptions[$frameFormData['system_id']] ?? $frameFormData['system_id']) : 'Unassigned' ?></li>
                      <li><strong>Glazing:</strong> <?= e($frameFormData['glazing']) ?></li>
                      <li><strong>Transom:</strong> <?= e($transomOptions[$frameFormData['transom']] ?? $frameFormData['transom']) ?><?php if ($frameFormData['transom'] === 'yes' && $frameFormData['transom_height'] !== ''): ?> — <?= e($frameFormData['transom_height']) ?><?php endif; ?></li>
                      <li><strong>Transom glazing:</strong> <?= e($frameFormData['transom'] === 'yes' ? $frameFormData['transom_glazing'] : 'N/A') ?></li>
                    </ul>
                    <div class="stacked gap-xxs">
                      <p class="small muted">Frame parts</p>
                      <ul class="stacked gap-xxs">
                        <?php foreach ($framePartDefinitions as $definition): ?>
                          <?php $selection = $frameFormData['parts'][$definition['id']] ?? ''; ?>
                          <?php $options = $framePartOptions[$definition['id']] ?? []; ?>
                          <?php $label = 'Not selected'; ?>
                          <?php foreach ($options as $option): ?>
                            <?php if ((string) $option['id'] === (string) $selection) { $label = $option['label']; break; } ?>
                          <?php endforeach; ?>
                          <?php if ($label === 'Not selected' && $selection !== '') { $label = $selection; } ?>
                          <li><strong><?= e($definition['label']) ?>:</strong> <?= e($label) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  </div>
                  <div class="card">
                    <h3>Door</h3>
                    <ul class="stacked gap-xs">
                      <li><strong>Door system:</strong> <?= $doorFormData['system_id'] !== '' ? e($doorSystemOptions[$doorFormData['system_id']] ?? $doorFormData['system_id']) : 'Unassigned' ?></li>
                      <li><strong>Active leaf:</strong> <?= e($doorFormData['active']['stile']) ?> · <?= e($doorFormData['active']['glazing']) ?></li>
                      <li>
                        <div class="stacked gap-xxs">
                          <p class="small muted">Active parts</p>
                          <ul class="stacked gap-xxs">
                            <?php foreach ($doorPartDefinitionsActive as $definition): ?>
                              <?php $selection = $doorFormData['active']['parts'][$definition['id']] ?? ''; ?>
                              <?php $options = $doorPartOptions['active'][$definition['id']] ?? []; ?>
                              <?php $label = 'Not selected'; ?>
                              <?php foreach ($options as $option): ?>
                                <?php if ((string) $option['id'] === (string) $selection) { $label = $option['label']; break; } ?>
                              <?php endforeach; ?>
                              <?php if ($label === 'Not selected' && $selection !== '') { $label = $selection; } ?>
                              <li><strong><?= e($definition['label']) ?>:</strong> <?= e($label) ?></li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      </li>
                      <?php if ($entryFormData['opening_type'] === 'pair'): ?>
                        <li><strong><?= e($doorLeafLabels['inactive']) ?> leaf:</strong> <?= e($doorFormData['inactive']['stile']) ?> · <?= e($doorFormData['inactive']['glazing']) ?></li>
                        <li>
                          <div class="stacked gap-xxs">
                            <p class="small muted"><?= e($doorLeafLabels['inactive']) ?> parts</p>
                            <ul class="stacked gap-xxs">
                              <?php foreach ($doorPartDefinitionsInactive as $definition): ?>
                                <?php $selection = $doorFormData['inactive']['parts'][$definition['id']] ?? ''; ?>
                                <?php $options = $doorPartOptions['inactive'][$definition['id']] ?? []; ?>
                                <?php $label = 'Not selected'; ?>
                                <?php foreach ($options as $option): ?>
                                  <?php if ((string) $option['id'] === (string) $selection) { $label = $option['label']; break; } ?>
                                <?php endforeach; ?>
                                <?php if ($label === 'Not selected' && $selection !== '') { $label = $selection; } ?>
                                <li><strong><?= e($definition['label']) ?>:</strong> <?= e($label) ?></li>
                              <?php endforeach; ?>
                            </ul>
                          </div>
                        </li>
                      <?php endif; ?>
                    </ul>
                  </div>
                </div>
                <div class="form-actions">
                  <button type="submit" class="button secondary" name="navigate_to" value="door">Back to door</button>
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
                <th scope="col">Door IDs</th>
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
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.localBuilderConfigId = <?= json_encode($builderState['config_id'] ?? null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.localJobScopes = <?= json_encode($jobScopeOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    window.frameSystemTrace = <?= json_encode($frameSystemTrace, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    console.info('Configurator frame systems (framing system_type)', window.frameSystemTrace);
    if (window.frameSystemTrace.error) {
      console.error('Error loading framing systems from inventory_systems:', window.frameSystemTrace.error);
    }
    if (Array.isArray(window.frameSystemTrace.loaded) && window.frameSystemTrace.loaded.length === 0) {
      console.warn('No framing systems available from inventory_systems; using fallback options:', window.frameSystemTrace.fallback_values);
    }
  </script>
  <script>
    (function () {
      const quantityInput = document.getElementById('config_quantity');
      const doorTagsContainer = document.getElementById('door-tags-container');
      const templateSelect = document.getElementById('template_door_select');
      const templateButton = document.getElementById('template-door-start');
      const openingTypeSelect = document.querySelector('[data-opening-type]');
      const handFields = document.querySelectorAll('[data-hand]');
      const handPairSelect = document.getElementById('entry_hand_pair');
      const lhWarning = document.querySelector('[data-lhra-warning]');
      const frameTransomSelect = document.querySelector('[data-frame-transom]');
      const frameTransomHeightRow = document.querySelector('[data-frame-transom-height]');
      const frameTransomGlazingSelect = document.getElementById('frame_transom_glazing');
      const framePartsList = document.querySelector('[data-frame-parts]');
      const frameGlazingSelect = document.getElementById('frame_glazing');
      const doorTabs = document.querySelectorAll('[data-door-tab]');
      const doorPanels = document.querySelectorAll('[data-door-panel]');
      const doorPartsLists = document.querySelectorAll('[data-door-parts]');

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

        syncPairWarning();
        renderFrameParts();
      }

      function syncPairWarning() {
        const shouldWarn = (openingTypeSelect instanceof HTMLSelectElement ? openingTypeSelect.value : 'single') === 'pair'
          && (handPairSelect instanceof HTMLSelectElement ? handPairSelect.value : '') === 'LHRA Active';
        if (lhWarning instanceof HTMLElement) {
          lhWarning.hidden = !shouldWarn;
        }
      }

      function syncFrameTransom() {
        const hasFrameTransom = (frameTransomSelect instanceof HTMLSelectElement ? frameTransomSelect.value : 'no') === 'yes';
        if (frameTransomHeightRow instanceof HTMLElement) {
          frameTransomHeightRow.hidden = !hasFrameTransom;
          const input = frameTransomHeightRow.querySelector('input');
          if (input instanceof HTMLInputElement) {
            input.disabled = !hasFrameTransom;
          }
        }

        if (frameTransomGlazingSelect instanceof HTMLSelectElement) {
          frameTransomGlazingSelect.disabled = !hasFrameTransom;
        }
      }

      function computeFrameParts() {
        const type = (openingTypeSelect instanceof HTMLSelectElement ? openingTypeSelect.value : 'single') === 'pair'
          ? 'pair'
          : 'single';
        const hasTransom = (frameTransomSelect instanceof HTMLSelectElement ? frameTransomSelect.value : 'no') === 'yes';
        const glazing = frameTransomGlazingSelect instanceof HTMLSelectElement ? frameTransomGlazingSelect.value : '1/4"';
        const parts = type === 'pair'
          ? ['LH Hinge Rail', 'RH Hinge Rail', 'Door Head', 'Head Door Stop', 'LH Door Stop', 'RH Door Stop']
          : ['Hinge Jamb', 'Lock Jamb', 'Door Head', 'Head Door Stop', 'Lock Door Stop', 'Hinge Door Stop'];

        if (hasTransom) {
          const adapter = glazing === '1/2"'
            ? '½ Glass adapter'
            : glazing === '1/4"'
              ? '¼ Glass adapter'
              : null;

          parts.push(
            'Door Head Transom Stop - Active',
            'Door Head Transom Stop - Fixed',
            'Vertical Transom Stop - Active',
            'Vertical Transom Stop - Fixed',
            adapter,
          );
        }

        return parts.filter(Boolean);
      }

      function renderFrameParts(selectedParts) {
        if (!(framePartsList instanceof HTMLElement)) {
          return;
        }

        const recommended = computeFrameParts();
        let selected = selectedParts instanceof Set
          ? new Set(selectedParts)
          : new Set(
            framePartsList.dataset.selected
              ? JSON.parse(framePartsList.dataset.selected)
              : Array.from(framePartsList.querySelectorAll('input[name="frame_parts[]"]:checked')).map((input) => input.value)
          );

        if (selected.size === 0) {
          selected = new Set(recommended);
        }

        const allParts = Array.from(new Set([...recommended, ...selected]));
        framePartsList.innerHTML = '';

        allParts.forEach((part) => {
          const label = document.createElement('label');
          label.className = 'checkbox';

          const input = document.createElement('input');
          input.type = 'checkbox';
          input.name = 'frame_parts[]';
          input.value = part;
          input.checked = selected.has(part);

          const span = document.createElement('span');
          span.textContent = part;

          label.appendChild(input);
          label.appendChild(span);
          framePartsList.appendChild(label);
        });

        framePartsList.dataset.selected = JSON.stringify(Array.from(selected));
      }

      function computeDoorParts(glazing) {
        const glazingLabel = glazing || 'N/A';
        return [
          'Hinge Rail - Standard',
          'Hinge Rail A (WS - Continuous Hinge)',
          'Hinge Rail B (WS - Butt Hinge)',
          'Lock Rail',
          'Top Rail',
          'Bottom Rail',
          'Interior Glass Stops — generated from glazing',
          'Exterior Glass Stops — generated from glazing',
          'Interior Glass Vinyl — generated from glazing',
          'Exterior Glass Vinyl — generated from glazing',
          'Door Set block — generated from glazing',
          'Door glass jack — generated from glazing',
          `Glazing package reference: ${glazingLabel}`,
        ];
      }

      function renderDoorPartsList(selectedMap) {
        if (!doorPartsLists || doorPartsLists.length === 0) {
          return;
        }

        doorPartsLists.forEach((list) => {
          const key = list.getAttribute('data-door-parts');
          const glazingSelect = document.getElementById(`door_${key}_glazing`);
          const glazing = glazingSelect instanceof HTMLSelectElement ? glazingSelect.value : '1/4"';
          const recommended = computeDoorParts(glazing);
          const presetSelection = selectedMap?.[key];
          let selected = presetSelection instanceof Set
            ? new Set(presetSelection)
            : new Set(
              list.dataset.selected
                ? JSON.parse(list.dataset.selected)
                : Array.from(list.querySelectorAll('input[type="checkbox"]:checked')).map((input) => input.value)
            );

          if (selected.size === 0) {
            selected = new Set(recommended);
          }

          const allParts = Array.from(new Set([...recommended, ...selected]));
          list.innerHTML = '';

          allParts.forEach((part) => {
            const label = document.createElement('label');
            label.className = 'checkbox';

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = `door_${key}_parts[]`;
            input.value = part;
            input.checked = selected.has(part);

            const span = document.createElement('span');
            span.textContent = part;

            label.appendChild(input);
            label.appendChild(span);
            list.appendChild(label);
          });

          list.dataset.selected = JSON.stringify(Array.from(selected));
        });
      }

      function applyDoorPreset(key, preset) {
        const glazingSelect = document.getElementById(`door_${key}_glazing`);
        const glazing = glazingSelect instanceof HTMLSelectElement ? glazingSelect.value : '1/4"';
        const recommended = new Set(computeDoorParts(glazing));

        if (preset === 'clear') {
          renderDoorPartsList({ [key]: new Set() });
          return;
        }

        if (preset === 'ws_continuous') {
          recommended.add('Hinge Rail A (WS - Continuous Hinge)');
          recommended.delete('Hinge Rail B (WS - Butt Hinge)');
        } else if (preset === 'ws_butt') {
          recommended.add('Hinge Rail B (WS - Butt Hinge)');
          recommended.delete('Hinge Rail A (WS - Continuous Hinge)');
        }

        renderDoorPartsList({ [key]: recommended });
      }

      function activateDoorTab(target) {
        if (!target) return;
        doorTabs.forEach((tab) => {
          const isActive = tab.getAttribute('data-door-tab') === target;
          tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
          tab.classList.toggle('active', isActive);
        });

        doorPanels.forEach((panel) => {
          const isMatch = panel.getAttribute('data-door-panel') === target;
          panel.hidden = !isMatch;
        });
      }

      openingTypeSelect?.addEventListener('change', () => {
        syncHands();
        renderFrameParts();
      });
      handPairSelect?.addEventListener('change', syncPairWarning);
      frameTransomSelect?.addEventListener('change', () => {
        syncFrameTransom();
        renderFrameParts();
      });
      frameTransomGlazingSelect?.addEventListener('change', renderFrameParts);
      frameGlazingSelect?.addEventListener('change', renderFrameParts);
      document.getElementById('door_active_glazing')?.addEventListener('change', () => renderDoorPartsList());
      document.getElementById('door_inactive_glazing')?.addEventListener('change', () => renderDoorPartsList());
      document.getElementById('door_active_preset')?.addEventListener('change', (event) => {
        applyDoorPreset('active', event.target?.value || 'standard');
      });
      document.getElementById('door_inactive_preset')?.addEventListener('change', (event) => {
        applyDoorPreset('inactive', event.target?.value || 'standard');
      });

      doorTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
          const target = tab.getAttribute('data-door-tab');
          activateDoorTab(target);
        });
      });

      syncHands();
      syncPairWarning();
      syncFrameTransom();
      renderFrameParts();
      renderDoorPartsList();
      activateDoorTab(document.querySelector('[data-door-tab]')?.getAttribute('data-door-tab') ?? 'active');

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

      const defaultJobs = [
        { id: 'draft_d1', job_number: 'Draft - D1', name: 'Door package draft 1', created_at: new Date().toISOString() },
        { id: 'draft_d3', job_number: 'Draft - D3', name: 'Door package draft 3', created_at: new Date().toISOString() },
        { id: 'draft_d4', job_number: 'Draft - D4', name: 'Door package draft 4', created_at: new Date().toISOString() },
      ];

      let jobListChanged = false;
      defaultJobs.slice().reverse().forEach((seed) => {
        if (!jobs.some((job) => job.job_number === seed.job_number)) {
          jobs.unshift(seed);
          jobListChanged = true;
        }
      });

      if (jobListChanged) {
        writeJson(JOB_KEY, jobs);
      }

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
        const finish = document.getElementById('entry_finish');
        if (finish && entry.finish) finish.value = entry.finish;
        const handSingle = document.getElementById('entry_hand_single');
        if (handSingle && entry.hand_single) handSingle.value = entry.hand_single;
        const handPair = document.getElementById('entry_hand_pair');
        if (handPair && entry.hand_pair) handPair.value = entry.hand_pair;
        const dow = document.getElementById('entry_door_opening_width');
        if (dow) dow.value = entry.door_opening_width ?? '';
        const doh = document.getElementById('entry_door_opening_height');
        if (doh) doh.value = entry.door_opening_height ?? '';
        const hinging = document.getElementById('entry_hinging');
        if (hinging && entry.hinging) hinging.value = entry.hinging;
        syncHands();
        syncPairWarning();
        renderFrameParts();
      }

      function applyFrameForm(data) {
        const frame = data?.frame;
        if (!frame) return;
        const system = document.getElementById('frame_system_id');
        if (system && frame.system_id !== undefined) system.value = frame.system_id;
        if (frameGlazingSelect && frame.glazing) frameGlazingSelect.value = frame.glazing;
        if (frameTransomSelect && frame.transom) frameTransomSelect.value = frame.transom;
        if (frameTransomGlazingSelect && frame.transom_glazing) frameTransomGlazingSelect.value = frame.transom_glazing;
        const frameHeight = document.getElementById('frame_transom_height');
        if (frameHeight) frameHeight.value = frame.transom_height ?? '';
        if (framePartsList && Array.isArray(frame.parts)) {
          framePartsList.dataset.selected = JSON.stringify(frame.parts);
        }
        syncFrameTransom();
        renderFrameParts(new Set(Array.isArray(frame.parts) ? frame.parts : []));
      }

      function applyDoorForm(data) {
        const door = data?.door;
        if (!door) return;
        const doorSystem = document.getElementById('door_system_id');
        if (doorSystem && door.system_id !== undefined) doorSystem.value = door.system_id;
        const activeStile = document.getElementById('door_active_stile');
        if (activeStile && door.active?.stile) activeStile.value = door.active.stile;
        const activeGlazing = document.getElementById('door_active_glazing');
        if (activeGlazing && door.active?.glazing) activeGlazing.value = door.active.glazing;
        const activeParts = document.querySelector('[data-door-parts="active"]');
        if (activeParts && Array.isArray(door.active?.parts)) {
          activeParts.dataset.selected = JSON.stringify(door.active.parts);
        }

        const inactiveStile = document.getElementById('door_inactive_stile');
        if (inactiveStile && door.inactive?.stile) inactiveStile.value = door.inactive.stile;
        const inactiveGlazing = document.getElementById('door_inactive_glazing');
        if (inactiveGlazing && door.inactive?.glazing) inactiveGlazing.value = door.inactive.glazing;
        const inactiveParts = document.querySelector('[data-door-parts="inactive"]');
        if (inactiveParts && Array.isArray(door.inactive?.parts)) {
          inactiveParts.dataset.selected = JSON.stringify(door.inactive.parts);
        }

        renderDoorPartsList({
          active: new Set(Array.isArray(door.active?.parts) ? door.active.parts : []),
          inactive: new Set(Array.isArray(door.inactive?.parts) ? door.inactive.parts : []),
        });
      }

      function applyActiveRecord(record) {
        applyConfigurationForm(record);
        applyEntryForm(record);
        applyFrameForm(record);
        applyDoorForm(record);
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
