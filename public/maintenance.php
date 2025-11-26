<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/maintenance.php';

if (!function_exists('maintenanceParseDocumentInput')) {
    /**
     * @return array<int,array{label:string,url:string}>
     */
    function maintenanceParseDocumentInput(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $documents = [];
        $lines = preg_split('/\r?\n/', $raw) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $label = $line;
            $url = '';

            if (strpos($line, '|') !== false) {
                [$labelValue, $urlValue] = array_map('trim', explode('|', $line, 2));
                $label = $labelValue;
                $url = $urlValue;
            } elseif (filter_var($line, FILTER_VALIDATE_URL)) {
                $label = parse_url($line, PHP_URL_HOST) ?? 'Document';
                $url = $line;
            }

            if ($label === '' && $url === '') {
                continue;
            }

            $documents[] = [
                'label' => $label !== '' ? $label : ($url !== '' ? $url : 'Document'),
                'url' => $url,
            ];
        }

        return $documents;
    }
}

if (!function_exists('maintenanceParsePartsInput')) {
    /**
     * @return array<int,string>
     */
    function maintenanceParsePartsInput(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = [];
        $lines = preg_split('/\r?\n/', $raw) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts[] = $line;
        }

        return $parts;
    }
}

if (!function_exists('maintenanceFormatMinutes')) {
    function maintenanceFormatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        if ($remaining === 0) {
            return $hours . ' hr' . ($hours === 1 ? '' : 's');
        }

        return $hours . ' hr ' . $remaining . ' min';
    }
}

if (!function_exists('maintenanceFormatLaborHours')) {
    function maintenanceFormatLaborHours(?float $hours): string
    {
        if ($hours === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.') . ' hr';
    }
}

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] ?? '') === 'Maintenance Hub';
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$successMessage = null;

$machineForm = [
    'name' => '',
    'equipment_type' => 'CNC Machining Center',
    'manufacturer' => '',
    'model' => '',
    'serial_number' => '',
    'location' => '',
    'documents_raw' => '',
    'notes' => '',
];
$taskForm = [
    'machine_id' => '',
    'title' => '',
    'frequency' => '',
    'assigned_to' => '',
    'description' => '',
    'interval_count' => '',
    'interval_unit' => '',
    'start_date' => date('Y-m-d'),
    'status' => 'active',
    'priority' => 'medium',
];
$recordForm = [
    'machine_id' => '',
    'task_id' => '',
    'performed_by' => '',
    'performed_at' => date('Y-m-d'),
    'notes' => '',
    'downtime_minutes' => '',
    'labor_hours' => '',
    'parts_used_raw' => '',
    'attachments_raw' => '',
];

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

$machines = [];
$machineMap = [];
$tasks = [];
$taskMap = [];
$records = [];
$tasksByMachine = [];
$recordsByMachine = [];
$machineModalId = null;
$machineModal = null;
$machineModalOpen = false;
$showRetired = false;

if ((isset($_GET['show_retired']) && $_GET['show_retired'] === '1') || (isset($_POST['show_retired']) && $_POST['show_retired'] === '1')) {
    $showRetired = true;
}

$shouldReload = false;

if ($dbError === null) {
    try {
        $machines = maintenanceMachineList($db);
        foreach ($machines as $machine) {
            $machineMap[$machine['id']] = $machine;
        }
        $tasks = maintenanceTasksList($db, $showRetired);
        foreach ($tasks as $task) {
            $taskMap[$task['id']] = $task;
        }
        $records = maintenanceRecordsList($db);

        foreach ($tasks as $task) {
            $tasksByMachine[$task['machine_id']][] = $task;
        }

        foreach ($records as $record) {
            $recordsByMachine[$record['machine_id']][] = $record;
        }

        $modalRequest = $_GET['machine_modal'] ?? ($_POST['machine_modal'] ?? null);
        if ($modalRequest !== null && ctype_digit((string) $modalRequest)) {
            $machineModalId = (int) $modalRequest;
            if (isset($machineMap[$machineModalId])) {
                $machineModal = $machineMap[$machineModalId];
                $machineModalOpen = true;
            }
        }
    } catch (\Throwable $exception) {
        $errors[] = 'Unable to load maintenance data: ' . $exception->getMessage();
    }
}

if ($dbError === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_machine') {
        $machineForm = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'equipment_type' => trim((string) ($_POST['equipment_type'] ?? '')),
            'manufacturer' => trim((string) ($_POST['manufacturer'] ?? '')),
            'model' => trim((string) ($_POST['model'] ?? '')),
            'serial_number' => trim((string) ($_POST['serial_number'] ?? '')),
            'location' => trim((string) ($_POST['location'] ?? '')),
            'documents_raw' => trim((string) ($_POST['documents_raw'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];

        if ($machineForm['name'] === '') {
            $errors[] = 'Machine name is required.';
        }
        if ($machineForm['equipment_type'] === '') {
            $errors[] = 'Select an equipment type.';
        }

        if (empty($errors)) {
            try {
                maintenanceMachineCreate($db, [
                    'name' => $machineForm['name'],
                    'equipment_type' => $machineForm['equipment_type'],
                    'manufacturer' => $machineForm['manufacturer'] !== '' ? $machineForm['manufacturer'] : null,
                    'model' => $machineForm['model'] !== '' ? $machineForm['model'] : null,
                    'serial_number' => $machineForm['serial_number'] !== '' ? $machineForm['serial_number'] : null,
                    'location' => $machineForm['location'] !== '' ? $machineForm['location'] : null,
                    'documents' => maintenanceParseDocumentInput($machineForm['documents_raw']),
                    'notes' => $machineForm['notes'] !== '' ? $machineForm['notes'] : null,
                ]);
                $successMessage = 'Machine saved successfully.';
                $shouldReload = true;
                $machineForm = [
                    'name' => '',
                    'equipment_type' => 'CNC Machining Center',
                    'manufacturer' => '',
                    'model' => '',
                    'serial_number' => '',
                    'location' => '',
                    'documents_raw' => '',
                    'notes' => '',
                ];
            } catch (\Throwable $exception) {
                $errors[] = 'Unable to save machine: ' . $exception->getMessage();
            }
        }
    } elseif ($action === 'create_task') {
        $taskForm = [
            'machine_id' => trim((string) ($_POST['task_machine_id'] ?? '')),
            'title' => trim((string) ($_POST['task_title'] ?? '')),
            'frequency' => trim((string) ($_POST['task_frequency'] ?? '')),
            'assigned_to' => trim((string) ($_POST['task_assigned_to'] ?? '')),
            'description' => trim((string) ($_POST['task_description'] ?? '')),
            'interval_count' => trim((string) ($_POST['task_interval_count'] ?? '')),
            'interval_unit' => trim((string) ($_POST['task_interval_unit'] ?? '')),
            'start_date' => trim((string) ($_POST['task_start_date'] ?? date('Y-m-d'))),
            'status' => trim((string) ($_POST['task_status'] ?? 'active')),
            'priority' => trim((string) ($_POST['task_priority'] ?? 'medium')),
        ];

        $machineId = $taskForm['machine_id'] !== '' && ctype_digit($taskForm['machine_id']) ? (int) $taskForm['machine_id'] : null;

        $intervalCount = $taskForm['interval_count'] !== '' && ctype_digit($taskForm['interval_count'])
            ? (int) $taskForm['interval_count']
            : null;
        $intervalUnit = $taskForm['interval_unit'] !== '' ? $taskForm['interval_unit'] : null;
        $validUnits = ['day', 'week', 'month', 'year'];
        $validStatuses = ['active', 'paused', 'retired'];
        $validPriorities = ['low', 'medium', 'high', 'critical'];

        if ($intervalUnit !== null && !in_array($intervalUnit, $validUnits, true)) {
            $errors[] = 'Select a valid interval unit.';
        }

        if (($intervalCount !== null && $intervalUnit === null) || ($intervalCount === null && $intervalUnit !== null)) {
            $errors[] = 'Provide both interval amount and unit, or leave both blank.';
        }

        if ($intervalCount !== null && $intervalCount <= 0) {
            $errors[] = 'Interval amount must be greater than zero.';
        }

        $startDate = $taskForm['start_date'] !== '' ? $taskForm['start_date'] : null;

        if ($intervalCount === null) {
            $startDate = null;
        }

        if ($startDate !== null && \DateTime::createFromFormat('Y-m-d', $startDate) === false) {
            $errors[] = 'Provide a valid start date (YYYY-MM-DD).';
        }

        if ($intervalCount !== null && $startDate === null) {
            $startDate = date('Y-m-d');
        }

        if ($machineId === null || !isset($machineMap[$machineId])) {
            $errors[] = 'Select a valid machine for the task.';
        }
        if ($taskForm['title'] === '') {
            $errors[] = 'Task title is required.';
        }

        if (!in_array($taskForm['status'], $validStatuses, true)) {
            $errors[] = 'Select a valid task status.';
        }

        if (!in_array($taskForm['priority'], $validPriorities, true)) {
            $errors[] = 'Select a valid task priority.';
        }

        if (empty($errors) && $machineId !== null) {
            try {
                maintenanceTaskCreate($db, [
                    'machine_id' => $machineId,
                    'title' => $taskForm['title'],
                    'description' => $taskForm['description'] !== '' ? $taskForm['description'] : null,
                    'frequency' => $taskForm['frequency'] !== '' ? $taskForm['frequency'] : null,
                    'assigned_to' => $taskForm['assigned_to'] !== '' ? $taskForm['assigned_to'] : null,
                    'interval_count' => $intervalCount,
                    'interval_unit' => $intervalUnit,
                    'start_date' => $startDate,
                    'status' => $taskForm['status'],
                    'priority' => $taskForm['priority'],
                ]);
                $successMessage = 'Maintenance task added.';
                $shouldReload = true;
                $taskForm = [
                    'machine_id' => '',
                    'title' => '',
                    'frequency' => '',
                    'assigned_to' => '',
                    'description' => '',
                    'interval_count' => '',
                    'interval_unit' => '',
                    'start_date' => date('Y-m-d'),
                    'status' => 'active',
                    'priority' => 'medium',
                ];
            } catch (\Throwable $exception) {
                $errors[] = 'Unable to save task: ' . $exception->getMessage();
            }
        }
    } elseif ($action === 'create_record') {
        $recordForm = [
            'machine_id' => trim((string) ($_POST['record_machine_id'] ?? '')),
            'task_id' => trim((string) ($_POST['record_task_id'] ?? '')),
            'performed_by' => trim((string) ($_POST['performed_by'] ?? '')),
            'performed_at' => trim((string) ($_POST['performed_at'] ?? date('Y-m-d'))),
            'notes' => trim((string) ($_POST['record_notes'] ?? '')),
            'downtime_minutes' => trim((string) ($_POST['downtime_minutes'] ?? '')),
            'labor_hours' => trim((string) ($_POST['labor_hours'] ?? '')),
            'parts_used_raw' => trim((string) ($_POST['parts_used'] ?? '')),
            'attachments_raw' => trim((string) ($_POST['record_attachments'] ?? '')),
        ];

        $recordMachineId = $recordForm['machine_id'] !== '' && ctype_digit($recordForm['machine_id'])
            ? (int) $recordForm['machine_id']
            : null;
        $recordTaskId = $recordForm['task_id'] !== '' && ctype_digit($recordForm['task_id'])
            ? (int) $recordForm['task_id']
            : null;

        $downtimeMinutes = null;
        if ($recordForm['downtime_minutes'] !== '') {
            if (ctype_digit($recordForm['downtime_minutes'])) {
                $downtimeMinutes = (int) $recordForm['downtime_minutes'];
            } else {
                $errors[] = 'Downtime must be a whole number of minutes.';
            }
        }

        if ($downtimeMinutes !== null && $downtimeMinutes < 0) {
            $errors[] = 'Downtime cannot be negative.';
        }

        $laborHours = null;
        if ($recordForm['labor_hours'] !== '') {
            if (is_numeric($recordForm['labor_hours'])) {
                $laborHours = (float) $recordForm['labor_hours'];
            } else {
                $errors[] = 'Labor hours must be numeric.';
            }
        }

        if ($laborHours !== null && $laborHours < 0) {
            $errors[] = 'Labor hours cannot be negative.';
        }

        $partsUsed = maintenanceParsePartsInput($recordForm['parts_used_raw']);

        if ($recordMachineId === null || !isset($machineMap[$recordMachineId])) {
            $errors[] = 'Select a machine for the maintenance record.';
        }

        if ($recordForm['performed_at'] !== '' && \DateTime::createFromFormat('Y-m-d', $recordForm['performed_at']) === false) {
            $errors[] = 'Provide a valid service date (YYYY-MM-DD).';
        }

        if ($recordTaskId !== null && !isset($taskMap[$recordTaskId])) {
            $errors[] = 'Select a valid task, or leave it blank.';
        }

        if (empty($errors) && $recordMachineId !== null) {
            try {
                maintenanceRecordCreate($db, [
                    'machine_id' => $recordMachineId,
                    'task_id' => $recordTaskId,
                    'performed_by' => $recordForm['performed_by'] !== '' ? $recordForm['performed_by'] : null,
                    'performed_at' => $recordForm['performed_at'] !== '' ? $recordForm['performed_at'] : null,
                    'notes' => $recordForm['notes'] !== '' ? $recordForm['notes'] : null,
                    'downtime_minutes' => $downtimeMinutes,
                    'labor_hours' => $laborHours !== null ? round($laborHours, 2) : null,
                    'parts_used' => $partsUsed,
                    'attachments' => maintenanceParseDocumentInput($recordForm['attachments_raw']),
                ]);
                $successMessage = 'Maintenance activity logged.';
                $shouldReload = true;
                $recordForm = [
                    'machine_id' => '',
                    'task_id' => '',
                    'performed_by' => '',
                    'performed_at' => date('Y-m-d'),
                    'notes' => '',
                    'downtime_minutes' => '',
                    'labor_hours' => '',
                    'parts_used_raw' => '',
                    'attachments_raw' => '',
                ];
            } catch (\Throwable $exception) {
                $errors[] = 'Unable to save maintenance record: ' . $exception->getMessage();
            }
        }
    }
}

if ($dbError === null && $shouldReload) {
    try {
        $machines = maintenanceMachineList($db);
        $machineMap = [];
        foreach ($machines as $machine) {
            $machineMap[$machine['id']] = $machine;
        }
        $tasks = maintenanceTasksList($db, $showRetired);
        $taskMap = [];
        foreach ($tasks as $task) {
            $taskMap[$task['id']] = $task;
        }
        $records = maintenanceRecordsList($db);

        $tasksByMachine = [];
        foreach ($tasks as $task) {
            $tasksByMachine[$task['machine_id']][] = $task;
        }

        $recordsByMachine = [];
        foreach ($records as $record) {
            $recordsByMachine[$record['machine_id']][] = $record;
        }
    } catch (\Throwable $exception) {
        $errors[] = 'Unable to refresh maintenance data: ' . $exception->getMessage();
    }
}

$machineCount = count($machines);
$visibleTasks = array_filter(
    $tasks,
    static fn (array $task): bool => $task['status'] !== 'retired'
);
$taskCount = count($visibleTasks);
$recordsCount = count($records);
$documentsCount = array_reduce(
    $machines,
    static fn (int $carry, array $machine): int => $carry + count($machine['documents']),
    0
);
$lastPerformed = $records[0]['performed_at'] ?? null;
$todayTs = strtotime(date('Y-m-d'));
$windowEndTs = strtotime('+14 days', $todayTs);
$overdueTasks = array_filter(
    $visibleTasks,
    static fn (array $task): bool => $task['is_overdue'] === true
);
$dueSoonTasks = array_filter(
    $visibleTasks,
    static function (array $task) use ($todayTs, $windowEndTs): bool {
        if ($task['next_due_date'] === null) {
            return false;
        }

        $dueTs = strtotime($task['next_due_date']);

        if ($dueTs === false) {
            return false;
        }

        return $dueTs >= $todayTs && $dueTs <= $windowEndTs;
    }
);
$nextDueDate = null;

foreach ($visibleTasks as $task) {
    if ($task['next_due_date'] === null) {
        continue;
    }

    if ($nextDueDate === null || $task['next_due_date'] < $nextDueDate) {
        $nextDueDate = $task['next_due_date'];
    }
}

$dueSoonTaskIds = array_column($dueSoonTasks, 'id');
$overdueTaskIds = array_column($overdueTasks, 'id');

$bodyClasses = ['has-sidebar-toggle'];

if ($machineModalOpen) {
    $bodyClasses[] = 'modal-open';
}

$bodyClassString = implode(' ', $bodyClasses);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> Maintenance Hub</title>
  <link rel="stylesheet" href="css/dashboard.css" />
</head>
<body class="<?= e($bodyClassString) ?>">
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
      <h1>Maintenance Hub</h1>
      <div class="topbar-spacer"></div>
    </header>

    <main class="content">
      <section class="metrics" aria-label="Maintenance summary">
        <article class="metric">
          <div class="metric-header">
            <span>Machines</span>
          </div>
          <p class="metric-value"><?= e((string) $machineCount) ?></p>
          <p class="metric-delta small">Tracked assets with documentation.</p>
        </article>
        <article class="metric">
          <div class="metric-header">
            <span>Active Tasks</span>
          </div>
          <p class="metric-value"><?= e((string) $taskCount) ?></p>
          <p class="metric-delta small">Preventative tasks tied to machines.</p>
        </article>
        <article class="metric">
          <div class="metric-header">
            <span>Records Logged</span>
          </div>
          <p class="metric-value"><?= e((string) $recordsCount) ?></p>
          <p class="metric-delta small">Service entries kept in the system.</p>
        </article>
        <article class="metric">
          <div class="metric-header">
            <span>Due soon</span>
            <?php if ($nextDueDate !== null): ?>
              <span class="metric-time">Next due <?= e(date('M j', strtotime($nextDueDate))) ?></span>
            <?php endif; ?>
          </div>
          <p class="metric-value"><?= e((string) count($dueSoonTasks)) ?></p>
          <p class="metric-delta small">
            <?php if (!empty($overdueTasks)): ?>
              <?= e((string) count($overdueTasks)) ?> overdue task<?= count($overdueTasks) === 1 ? '' : 's' ?>.
            <?php else: ?>
              <?= e('No overdue tasks.') ?>
            <?php endif; ?>
          </p>
        </article>
        <article class="metric accent">
          <div class="metric-header">
            <span>Documents</span>
            <?php if ($lastPerformed !== null): ?>
              <span class="metric-time">Last service <?= e(date('M j', strtotime($lastPerformed))) ?></span>
            <?php endif; ?>
          </div>
          <p class="metric-value"><?= e((string) $documentsCount) ?></p>
          <p class="metric-delta small">Safety sheets, manuals, and setup notes.</p>
        </article>
      </section>

      <section class="panel" aria-labelledby="machine-title">
        <header>
          <div>
            <h2 id="machine-title">Equipment Library</h2>
            <p class="small">Store specifications, manuals, and safety references for every asset.</p>
          </div>
        </header>
        <?php if ($dbError !== null): ?>
          <div class="alert error" role="alert">Unable to connect to the database: <?= e($dbError) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
          <div class="alert error" role="alert"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php if ($successMessage !== null): ?>
          <div class="alert success" role="status"><?= e($successMessage) ?></div>
        <?php endif; ?>
        <div class="panel-grid">
          <div>
            <h3>Add or update a machine</h3>
            <form method="post" class="form-grid">
              <input type="hidden" name="action" value="create_machine" />
              <input type="hidden" name="show_retired" value="<?= $showRetired ? '1' : '0' ?>" />
              <div class="field">
                <label for="machine-name">Machine Name</label>
                <input id="machine-name" name="name" type="text" value="<?= e($machineForm['name']) ?>" placeholder="C.R. Onsrud CNC" required />
              </div>
              <div class="field">
                <label for="machine-type">Equipment Type</label>
                <select id="machine-type" name="equipment_type" required>
                  <?php
                    $types = ['CNC Machining Center', 'Upcut Saw', 'Panel Saw', 'Press Brake', 'Welding Station', 'Custom Cell'];
                    foreach ($types as $type):
                        $selected = $machineForm['equipment_type'] === $type ? ' selected' : '';
                  ?>
                    <option value="<?= e($type) ?>"<?= $selected ?>><?= e($type) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="machine-manufacturer">Manufacturer</label>
                <input id="machine-manufacturer" name="manufacturer" type="text" value="<?= e($machineForm['manufacturer']) ?>" placeholder="Biesse" />
              </div>
              <div class="field">
                <label for="machine-model">Model</label>
                <input id="machine-model" name="model" type="text" value="<?= e($machineForm['model']) ?>" placeholder="Rover A 2232" />
              </div>
              <div class="field">
                <label for="machine-serial">Serial Number</label>
                <input id="machine-serial" name="serial_number" type="text" value="<?= e($machineForm['serial_number']) ?>" placeholder="SN-45821" />
              </div>
              <div class="field">
                <label for="machine-location">Bay or Cell</label>
                <input id="machine-location" name="location" type="text" value="<?= e($machineForm['location']) ?>" placeholder="Fab Bay 3" />
              </div>
              <div class="field">
                <label for="machine-docs">Documents (one per line, Label|URL)</label>
                <textarea id="machine-docs" name="documents_raw" rows="3" placeholder="Safety manual|https://...&#10;Tooling list|https://..."><?= e($machineForm['documents_raw']) ?></textarea>
              </div>
              <div class="field full-width">
                <label for="machine-notes">Notes</label>
                <textarea id="machine-notes" name="notes" rows="3" placeholder="Electrical requirements, setup notes, etc."><?= e($machineForm['notes']) ?></textarea>
              </div>
              <div class="field full-width">
                <button type="submit" class="btn btn-primary">Save Machine</button>
              </div>
            </form>
          </div>
          <div>
            <h3>Machine roster</h3>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Asset</th>
                    <th>Type</th>
                    <th>Tasks</th>
                    <th>Last Service</th>
                    <th>Downtime</th>
                    <th>Documents</th>
                    <th>Manage</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($machines)): ?>
                    <tr>
                      <td colspan="6">No machines recorded yet.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($machines as $machine): ?>
                      <tr>
                        <td>
                          <strong><?= e($machine['name']) ?></strong>
                          <?php if (!empty($machine['manufacturer']) || !empty($machine['model'])): ?>
                            <div class="small"><?= e(trim(($machine['manufacturer'] ?? '') . ' ' . ($machine['model'] ?? ''))) ?></div>
                          <?php endif; ?>
                          <?php if (!empty($machine['serial_number'])): ?>
                            <div class="small">Serial <?= e($machine['serial_number']) ?></div>
                          <?php endif; ?>
                          <?php if (!empty($machine['location'])): ?>
                            <div class="small"><?= e($machine['location']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= e($machine['equipment_type']) ?></td>
                        <td><?= e((string) $machine['task_count']) ?></td>
                        <td>
                          <?php if ($machine['last_service_at'] !== null): ?>
                            <?= e(date('M j, Y', strtotime($machine['last_service_at']))) ?>
                          <?php else: ?>
                            <span class="muted">No log</span>
                          <?php endif; ?>
                        </td>
                        <td><?= e(maintenanceFormatMinutes((int) $machine['total_downtime_minutes'])) ?></td>
                        <td>
                          <?php if (empty($machine['documents'])): ?>
                            <span class="muted">None</span>
                          <?php else: ?>
                            <ul class="document-list">
                              <?php foreach ($machine['documents'] as $doc): ?>
                                <li>
                                  <?php if ($doc['url'] !== ''): ?>
                                    <a href="<?= e($doc['url']) ?>" target="_blank" rel="noreferrer"><?= e($doc['label']) ?></a>
                                  <?php else: ?>
                                    <?= e($doc['label']) ?>
                                  <?php endif; ?>
                                </li>
                              <?php endforeach; ?>
                            </ul>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php
                            $modalUrl = 'maintenance.php?machine_modal=' . $machine['id'];
                            if ($showRetired) {
                                $modalUrl .= '&show_retired=1';
                            }
                          ?>
                          <a class="btn btn-secondary small" href="<?= e($modalUrl) ?>">Manage</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <section class="panel" aria-labelledby="task-title">
        <header>
          <h2 id="task-title">Preventative tasks</h2>
          <p class="small">Assign recurring inspections, calibration, lubrication, and cleaning checkpoints.</p>
        </header>
        <div class="panel-grid">
          <div>
            <h3>Create task</h3>
            <form method="post" class="form-grid">
              <input type="hidden" name="action" value="create_task" />
              <input type="hidden" name="show_retired" value="<?= $showRetired ? '1' : '0' ?>" />
              <div class="field">
                <label for="task-machine">Machine</label>
                <select id="task-machine" name="task_machine_id" required>
                  <option value="">Select machine</option>
                  <?php foreach ($machines as $machine): ?>
                    <option value="<?= e((string) $machine['id']) ?>"<?= $taskForm['machine_id'] === (string) $machine['id'] ? ' selected' : '' ?>><?= e($machine['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="task-title-input">Task</label>
                <input id="task-title-input" name="task_title" type="text" value="<?= e($taskForm['title']) ?>" placeholder="Lubricate rails" required />
              </div>
              <div class="field">
                <label for="task-frequency">Frequency</label>
                <input id="task-frequency" name="task_frequency" type="text" value="<?= e($taskForm['frequency']) ?>" placeholder="Monthly" />
              </div>
              <div class="field">
                <label for="task-interval-count">Interval Amount</label>
                <input
                  id="task-interval-count"
                  name="task_interval_count"
                  type="number"
                  min="1"
                  value="<?= e($taskForm['interval_count']) ?>"
                  placeholder="e.g. 1"
                />
              </div>
              <div class="field">
                <label for="task-interval-unit">Interval Unit</label>
                <select id="task-interval-unit" name="task_interval_unit">
                  <option value=""<?= $taskForm['interval_unit'] === '' ? ' selected' : '' ?>>No interval</option>
                  <?php $units = ['day' => 'Day(s)', 'week' => 'Week(s)', 'month' => 'Month(s)', 'year' => 'Year(s)']; ?>
                  <?php foreach ($units as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $taskForm['interval_unit'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="task-start-date">Start Date</label>
                <input id="task-start-date" name="task_start_date" type="date" value="<?= e($taskForm['start_date']) ?>" />
              </div>
              <div class="field">
                <label for="task-priority">Priority</label>
                <select id="task-priority" name="task_priority">
                  <?php $priorities = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical']; ?>
                  <?php foreach ($priorities as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $taskForm['priority'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="task-status">Status</label>
                <select id="task-status" name="task_status">
                  <?php $statuses = ['active' => 'Active', 'paused' => 'Paused', 'retired' => 'Retired']; ?>
                  <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $taskForm['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="task-owner">Owner / Technician</label>
                <input id="task-owner" name="task_assigned_to" type="text" value="<?= e($taskForm['assigned_to']) ?>" placeholder="Maintenance crew" />
              </div>
              <div class="field full-width">
                <label for="task-notes">Description</label>
                <textarea id="task-notes" name="task_description" rows="3" placeholder="Steps, tools, torque specs."><?= e($taskForm['description']) ?></textarea>
              </div>
              <div class="field full-width">
                <button class="btn btn-primary" type="submit">Add Task</button>
              </div>
            </form>
          </div>
          <div>
            <h3>Task list</h3>
            <form method="get" class="inline-form" style="margin-bottom: 0.5rem;">
              <label class="small">
                <input
                  type="checkbox"
                  name="show_retired"
                  value="1"
                  <?= $showRetired ? 'checked' : '' ?>
                  onchange="this.form.submit()"
                />
                Show retired tasks
              </label>
            </form>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Machine</th>
                    <th>Task</th>
                    <th>Priority</th>
                    <th>Frequency</th>
                    <th>Next due</th>
                    <th>Due status</th>
                    <th>Owner</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($tasks)): ?>
                    <tr><td colspan="7">No tasks have been defined.</td></tr>
                  <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                      <tr>
                        <td><?= e($task['machine_name']) ?></td>
                        <td>
                          <strong><?= e($task['title']) ?></strong>
                          <?php if ($task['status'] === 'paused'): ?>
                            <span class="badge muted">Paused</span>
                          <?php elseif ($task['status'] === 'retired'): ?>
                            <span class="badge muted">Retired</span>
                          <?php endif; ?>
                          <?php if (!empty($task['description'])): ?>
                            <div class="small"><?= e($task['description']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($task['priority'] === 'critical'): ?>
                            <span class="badge danger">Critical</span>
                          <?php elseif ($task['priority'] === 'high'): ?>
                            <span class="badge warning">High</span>
                          <?php elseif ($task['priority'] === 'low'): ?>
                            <span class="badge muted">Low</span>
                          <?php else: ?>
                            <span class="badge muted">Medium</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($task['frequency'] !== null): ?>
                            <?= e($task['frequency']) ?>
                          <?php else: ?>
                            <span class="muted">Ad hoc</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($task['next_due_date'] !== null): ?>
                            <?= e(date('M j, Y', strtotime($task['next_due_date']))) ?>
                          <?php else: ?>
                            <span class="muted">Not scheduled</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($task['status'] === 'retired'): ?>
                            <span class="muted">Not tracked</span>
                          <?php elseif (in_array($task['id'], $overdueTaskIds, true)): ?>
                            <span class="badge danger">Overdue</span>
                          <?php elseif (in_array($task['id'], $dueSoonTaskIds, true)): ?>
                            <span class="badge warning">Due soon</span>
                          <?php elseif ($task['next_due_date'] !== null): ?>
                            <span class="badge muted">Scheduled</span>
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($task['assigned_to'] !== null): ?>
                            <?= e($task['assigned_to']) ?>
                          <?php else: ?>
                            <span class="muted">Unassigned</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <section class="panel" aria-labelledby="record-title">
        <header>
          <h2 id="record-title">Service log</h2>
          <p class="small">Capture when work was performed, who touched the machine, and supporting attachments.</p>
        </header>
        <div class="panel-grid">
          <div>
            <h3>Log maintenance</h3>
            <form method="post" class="form-grid">
              <input type="hidden" name="action" value="create_record" />
              <input type="hidden" name="show_retired" value="<?= $showRetired ? '1' : '0' ?>" />
              <div class="field">
                <label for="record-machine">Machine</label>
                <select id="record-machine" name="record_machine_id" required>
                  <option value="">Select machine</option>
                  <?php foreach ($machines as $machine): ?>
                    <option value="<?= e((string) $machine['id']) ?>"<?= $recordForm['machine_id'] === (string) $machine['id'] ? ' selected' : '' ?>><?= e($machine['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="record-task">Related Task</label>
                <select id="record-task" name="record_task_id">
                  <option value="">Optional</option>
                  <?php foreach ($tasks as $task): ?>
                    <option value="<?= e((string) $task['id']) ?>"<?= $recordForm['task_id'] === (string) $task['id'] ? ' selected' : '' ?>><?= e($task['machine_name'] . ' – ' . $task['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="record-owner">Performed By</label>
                <input id="record-owner" name="performed_by" type="text" value="<?= e($recordForm['performed_by']) ?>" placeholder="Tech name" />
              </div>
              <div class="field">
                <label for="record-date">Date</label>
                <input id="record-date" name="performed_at" type="date" value="<?= e($recordForm['performed_at']) ?>" />
              </div>
              <div class="field">
                <label for="record-downtime">Downtime (minutes)</label>
                <input
                  id="record-downtime"
                  name="downtime_minutes"
                  type="number"
                  min="0"
                  inputmode="numeric"
                  value="<?= e($recordForm['downtime_minutes']) ?>"
                  placeholder="e.g. 30"
                />
              </div>
              <div class="field">
                <label for="record-labor">Labor Hours</label>
                <input
                  id="record-labor"
                  name="labor_hours"
                  type="number"
                  min="0"
                  step="0.1"
                  inputmode="decimal"
                  value="<?= e($recordForm['labor_hours']) ?>"
                  placeholder="e.g. 1.5"
                />
              </div>
              <div class="field">
                <label for="record-parts">Parts / Consumables (one per line)</label>
                <textarea
                  id="record-parts"
                  name="parts_used"
                  rows="3"
                  placeholder="Coolant top-off 1 qt&#10;Linear bearing grease"
                ><?= e($recordForm['parts_used_raw']) ?></textarea>
              </div>
              <div class="field">
                <label for="record-attachments">Attachments (Label|URL)</label>
                <textarea id="record-attachments" name="record_attachments" rows="3" placeholder="Inspection photos|https://..."><?= e($recordForm['attachments_raw']) ?></textarea>
              </div>
              <div class="field full-width">
                <label for="record-notes">Notes</label>
                <textarea id="record-notes" name="record_notes" rows="3" placeholder="Observations, part replacements, corrective action."><?= e($recordForm['notes']) ?></textarea>
              </div>
              <div class="field full-width">
                <button class="btn btn-primary" type="submit">Log Service</button>
              </div>
            </form>
          </div>
          <div>
            <h3>Recent activity</h3>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Machine</th>
                    <th>Task</th>
                    <th>Technician</th>
                    <th>Downtime</th>
                    <th>Labor</th>
                    <th>Parts / Consumables</th>
                    <th>Notes</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($records)): ?>
                    <tr><td colspan="8">No maintenance records yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($records as $record): ?>
                      <tr>
                        <td>
                          <?php if ($record['performed_at'] !== null): ?>
                            <?= e(date('M j, Y', strtotime($record['performed_at']))) ?>
                          <?php else: ?>
                            <span class="muted">Pending</span>
                          <?php endif; ?>
                        </td>
                        <td><?= e($record['machine_name']) ?></td>
                        <td>
                          <?php if ($record['task_title'] !== null): ?>
                            <?= e($record['task_title']) ?>
                          <?php else: ?>
                            <span class="muted">Unplanned</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($record['performed_by'] !== null): ?>
                            <?= e($record['performed_by']) ?>
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td><?= e(maintenanceFormatMinutes($record['downtime_minutes'])) ?></td>
                        <td><?= e(maintenanceFormatLaborHours($record['labor_hours'])) ?></td>
                        <td>
                          <?php if (!empty($record['parts_used'])): ?>
                            <ul class="document-list">
                              <?php foreach ($record['parts_used'] as $part): ?>
                                <li><?= e($part) ?></li>
                              <?php endforeach; ?>
                            </ul>
                          <?php else: ?>
                            <span class="muted">None</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if (!empty($record['notes'])): ?>
                            <div><?= e($record['notes']) ?></div>
                          <?php endif; ?>
                          <?php if (!empty($record['attachments'])): ?>
                            <ul class="document-list">
                              <?php foreach ($record['attachments'] as $doc): ?>
                                <li>
                                  <?php if ($doc['url'] !== ''): ?>
                                    <a href="<?= e($doc['url']) ?>" target="_blank" rel="noreferrer"><?= e($doc['label']) ?></a>
                                  <?php else: ?>
                                    <?= e($doc['label']) ?>
                                  <?php endif; ?>
                                </li>
                              <?php endforeach; ?>
                            </ul>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <?php if ($machineModal !== null): ?>
    <?php
      $modalClasses = 'modal' . ($machineModalOpen ? ' open' : '');
      $machineTasks = $tasksByMachine[$machineModal['id']] ?? [];
      $machineRecords = $recordsByMachine[$machineModal['id']] ?? [];
      $closeUrl = $showRetired ? 'maintenance.php?show_retired=1' : 'maintenance.php';
    ?>
    <div id="machine-modal" class="<?= e($modalClasses) ?>" role="dialog" aria-modal="true" aria-labelledby="machine-modal-title" aria-hidden="<?= $machineModalOpen ? 'false' : 'true' ?>" data-close-url="<?= e($closeUrl) ?>">
      <div class="modal-dialog">
        <header>
          <div>
            <h2 id="machine-modal-title">Manage <?= e($machineModal['name']) ?></h2>
            <p>Preventative tasks and service log entries scoped to this asset.</p>
          </div>
          <a class="modal-close" href="<?= e($closeUrl) ?>" aria-label="Close machine dialog">&times;</a>
        </header>

        <div class="grid two-columns">
          <div>
            <h3>Machine details</h3>
            <dl class="inline-list">
              <div>
                <dt>Type</dt>
                <dd><?= e($machineModal['equipment_type']) ?></dd>
              </div>
              <?php if (!empty($machineModal['manufacturer']) || !empty($machineModal['model'])): ?>
                <div>
                  <dt>Make / Model</dt>
                  <dd><?= e(trim(($machineModal['manufacturer'] ?? '') . ' ' . ($machineModal['model'] ?? ''))) ?></dd>
                </div>
              <?php endif; ?>
              <?php if (!empty($machineModal['serial_number'])): ?>
                <div>
                  <dt>Serial</dt>
                  <dd><?= e($machineModal['serial_number']) ?></dd>
                </div>
              <?php endif; ?>
              <?php if (!empty($machineModal['location'])): ?>
                <div>
                  <dt>Location</dt>
                  <dd><?= e($machineModal['location']) ?></dd>
                </div>
              <?php endif; ?>
              <div>
                <dt>Total downtime logged</dt>
                <dd><?= e(maintenanceFormatMinutes((int) $machineModal['total_downtime_minutes'])) ?></dd>
              </div>
            </dl>
            <?php if (!empty($machineModal['documents'])): ?>
              <h4>Documents</h4>
              <ul class="document-list">
                <?php foreach ($machineModal['documents'] as $doc): ?>
                  <li>
                    <?php if ($doc['url'] !== ''): ?>
                      <a href="<?= e($doc['url']) ?>" target="_blank" rel="noreferrer"><?= e($doc['label']) ?></a>
                    <?php else: ?>
                      <?= e($doc['label']) ?>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if (!empty($machineModal['notes'])): ?>
              <p class="small" style="margin-top: 0.5rem;">Notes: <?= e($machineModal['notes']) ?></p>
            <?php endif; ?>
          </div>
          <div>
            <h3>Preventative task</h3>
            <form method="post" class="form-grid" style="margin-bottom: 1rem;">
              <input type="hidden" name="action" value="create_task" />
              <input type="hidden" name="task_machine_id" value="<?= e((string) $machineModal['id']) ?>" />
              <input type="hidden" name="machine_modal" value="<?= e((string) $machineModal['id']) ?>" />
              <input type="hidden" name="show_retired" value="<?= $showRetired ? '1' : '0' ?>" />
              <div class="field">
                <label for="task-title-modal">Task</label>
                <input id="task-title-modal" name="task_title" type="text" value="<?= e($taskForm['title']) ?>" placeholder="Lubricate rails" required />
              </div>
              <div class="field">
                <label for="task-frequency-modal">Frequency</label>
                <input id="task-frequency-modal" name="task_frequency" type="text" value="<?= e($taskForm['frequency']) ?>" placeholder="Monthly" />
              </div>
              <div class="field">
                <label for="task-interval-count-modal">Interval Amount</label>
                <input
                  id="task-interval-count-modal"
                  name="task_interval_count"
                  type="number"
                  min="1"
                  value="<?= e($taskForm['interval_count']) ?>"
                  placeholder="e.g. 1"
                />
              </div>
              <div class="field">
                <label for="task-interval-unit-modal">Interval Unit</label>
                <select id="task-interval-unit-modal" name="task_interval_unit">
                  <option value=""<?= $taskForm['interval_unit'] === '' ? ' selected' : '' ?>>No interval</option>
                  <?php $units = ['day' => 'Day(s)', 'week' => 'Week(s)', 'month' => 'Month(s)', 'year' => 'Year(s)']; ?>
                  <?php foreach ($units as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $taskForm['interval_unit'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="task-start-date-modal">Start Date</label>
                <input id="task-start-date-modal" name="task_start_date" type="date" value="<?= e($taskForm['start_date']) ?>" />
              </div>
              <div class="field">
                <label for="task-priority-modal">Priority</label>
                <select id="task-priority-modal" name="task_priority">
                  <?php $priorities = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical']; ?>
                  <?php foreach ($priorities as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $taskForm['priority'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="task-status-modal">Status</label>
                <select id="task-status-modal" name="task_status">
                  <?php $statuses = ['active' => 'Active', 'paused' => 'Paused', 'retired' => 'Retired']; ?>
                  <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $taskForm['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="task-owner-modal">Owner / Technician</label>
                <input id="task-owner-modal" name="task_assigned_to" type="text" value="<?= e($taskForm['assigned_to']) ?>" placeholder="Maintenance crew" />
              </div>
              <div class="field full-width">
                <label for="task-notes-modal">Description</label>
                <textarea id="task-notes-modal" name="task_description" rows="2" placeholder="Steps, tools, torque specs."><?= e($taskForm['description']) ?></textarea>
              </div>
              <div class="field full-width">
                <button class="btn btn-primary" type="submit">Add Task</button>
              </div>
            </form>

            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Task</th>
                    <th>Priority</th>
                    <th>Frequency</th>
                    <th>Next due</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($machineTasks)): ?>
                    <tr><td colspan="5">No tasks for this machine yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($machineTasks as $task): ?>
                      <tr>
                        <td>
                          <strong><?= e($task['title']) ?></strong>
                          <?php if ($task['status'] === 'paused'): ?>
                            <span class="badge muted">Paused</span>
                          <?php elseif ($task['status'] === 'retired'): ?>
                            <span class="badge muted">Retired</span>
                          <?php endif; ?>
                          <?php if (!empty($task['description'])): ?>
                            <div class="small"><?= e($task['description']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($task['priority'] === 'critical'): ?>
                            <span class="badge danger">Critical</span>
                          <?php elseif ($task['priority'] === 'high'): ?>
                            <span class="badge warning">High</span>
                          <?php elseif ($task['priority'] === 'low'): ?>
                            <span class="badge muted">Low</span>
                          <?php else: ?>
                            <span class="badge muted">Medium</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($task['frequency'] !== null): ?>
                            <?= e($task['frequency']) ?>
                          <?php else: ?>
                            <span class="muted">Ad hoc</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($task['next_due_date'] !== null): ?>
                            <?= e(date('M j, Y', strtotime($task['next_due_date']))) ?>
                          <?php else: ?>
                            <span class="muted">Not scheduled</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($task['status'] === 'retired'): ?>
                            <span class="muted">Not tracked</span>
                          <?php elseif (in_array($task['id'], $overdueTaskIds, true)): ?>
                            <span class="badge danger">Overdue</span>
                          <?php elseif (in_array($task['id'], $dueSoonTaskIds, true)): ?>
                            <span class="badge warning">Due soon</span>
                          <?php elseif ($task['next_due_date'] !== null): ?>
                            <span class="badge muted">Scheduled</span>
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="grid two-columns" style="margin-top: 1.5rem;">
          <div>
            <h3>Log maintenance</h3>
            <form method="post" class="form-grid">
              <input type="hidden" name="action" value="create_record" />
              <input type="hidden" name="record_machine_id" value="<?= e((string) $machineModal['id']) ?>" />
              <input type="hidden" name="machine_modal" value="<?= e((string) $machineModal['id']) ?>" />
              <input type="hidden" name="show_retired" value="<?= $showRetired ? '1' : '0' ?>" />
              <div class="field">
                <label>Machine</label>
                <div class="muted"><?= e($machineModal['name']) ?></div>
              </div>
              <div class="field">
                <label for="record-task-modal">Related Task</label>
                <select id="record-task-modal" name="record_task_id">
                  <option value="">Optional</option>
                  <?php foreach ($machineTasks as $task): ?>
                    <option value="<?= e((string) $task['id']) ?>"<?= $recordForm['task_id'] === (string) $task['id'] ? ' selected' : '' ?>><?= e($task['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="record-owner-modal">Performed By</label>
                <input id="record-owner-modal" name="performed_by" type="text" value="<?= e($recordForm['performed_by']) ?>" placeholder="Tech name" />
              </div>
              <div class="field">
                <label for="record-date-modal">Date</label>
                <input id="record-date-modal" name="performed_at" type="date" value="<?= e($recordForm['performed_at']) ?>" />
              </div>
              <div class="field">
                <label for="record-downtime-modal">Downtime (minutes)</label>
                <input
                  id="record-downtime-modal"
                  name="downtime_minutes"
                  type="number"
                  min="0"
                  inputmode="numeric"
                  value="<?= e($recordForm['downtime_minutes']) ?>"
                  placeholder="e.g. 30"
                />
              </div>
              <div class="field">
                <label for="record-labor-modal">Labor Hours</label>
                <input
                  id="record-labor-modal"
                  name="labor_hours"
                  type="number"
                  min="0"
                  step="0.1"
                  inputmode="decimal"
                  value="<?= e($recordForm['labor_hours']) ?>"
                  placeholder="e.g. 1.5"
                />
              </div>
              <div class="field">
                <label for="record-parts-modal">Parts / Consumables (one per line)</label>
                <textarea
                  id="record-parts-modal"
                  name="parts_used"
                  rows="3"
                  placeholder="Coolant top-off 1 qt&#10;Linear bearing grease"
                ><?= e($recordForm['parts_used_raw']) ?></textarea>
              </div>
              <div class="field">
                <label for="record-attachments-modal">Attachments (Label|URL)</label>
                <textarea id="record-attachments-modal" name="record_attachments" rows="3" placeholder="Inspection photos|https://..."><?= e($recordForm['attachments_raw']) ?></textarea>
              </div>
              <div class="field full-width">
                <label for="record-notes-modal">Notes</label>
                <textarea id="record-notes-modal" name="record_notes" rows="3" placeholder="Observations, part replacements, corrective action."><?= e($recordForm['notes']) ?></textarea>
              </div>
              <div class="field full-width">
                <button class="btn btn-primary" type="submit">Log Service</button>
              </div>
            </form>
          </div>
          <div>
            <h3>Recent activity</h3>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Task</th>
                    <th>Downtime</th>
                    <th>Labor</th>
                    <th>Notes</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($machineRecords)): ?>
                    <tr><td colspan="5">No records for this machine yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($machineRecords as $record): ?>
                      <tr>
                        <td>
                          <?php if ($record['performed_at'] !== null): ?>
                            <?= e(date('M j, Y', strtotime($record['performed_at']))) ?>
                          <?php else: ?>
                            <span class="muted">Pending</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($record['task_title'] !== null): ?>
                            <?= e($record['task_title']) ?>
                          <?php else: ?>
                            <span class="muted">Unplanned</span>
                          <?php endif; ?>
                        </td>
                        <td><?= e(maintenanceFormatMinutes($record['downtime_minutes'])) ?></td>
                        <td><?= e(maintenanceFormatLaborHours($record['labor_hours'])) ?></td>
                        <td>
                          <?php if (!empty($record['notes'])): ?>
                            <div><?= e($record['notes']) ?></div>
                          <?php endif; ?>
                          <?php if (!empty($record['parts_used'])): ?>
                            <ul class="document-list">
                              <?php foreach ($record['parts_used'] as $part): ?>
                                <li><?= e($part) ?></li>
                              <?php endforeach; ?>
                            </ul>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</body>
</html>
