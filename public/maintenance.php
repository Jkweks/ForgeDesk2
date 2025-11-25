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
];
$recordForm = [
    'machine_id' => '',
    'task_id' => '',
    'performed_by' => '',
    'performed_at' => date('Y-m-d'),
    'notes' => '',
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

$shouldReload = false;

if ($dbError === null) {
    try {
        $machines = maintenanceMachineList($db);
        foreach ($machines as $machine) {
            $machineMap[$machine['id']] = $machine;
        }
        $tasks = maintenanceTasksList($db);
        foreach ($tasks as $task) {
            $taskMap[$task['id']] = $task;
        }
        $records = maintenanceRecordsList($db);
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
        ];

        $machineId = $taskForm['machine_id'] !== '' && ctype_digit($taskForm['machine_id']) ? (int) $taskForm['machine_id'] : null;

        if ($machineId === null || !isset($machineMap[$machineId])) {
            $errors[] = 'Select a valid machine for the task.';
        }
        if ($taskForm['title'] === '') {
            $errors[] = 'Task title is required.';
        }

        if (empty($errors) && $machineId !== null) {
            try {
                maintenanceTaskCreate($db, [
                    'machine_id' => $machineId,
                    'title' => $taskForm['title'],
                    'description' => $taskForm['description'] !== '' ? $taskForm['description'] : null,
                    'frequency' => $taskForm['frequency'] !== '' ? $taskForm['frequency'] : null,
                    'assigned_to' => $taskForm['assigned_to'] !== '' ? $taskForm['assigned_to'] : null,
                ]);
                $successMessage = 'Maintenance task added.';
                $shouldReload = true;
                $taskForm = [
                    'machine_id' => '',
                    'title' => '',
                    'frequency' => '',
                    'assigned_to' => '',
                    'description' => '',
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
            'attachments_raw' => trim((string) ($_POST['record_attachments'] ?? '')),
        ];

        $recordMachineId = $recordForm['machine_id'] !== '' && ctype_digit($recordForm['machine_id'])
            ? (int) $recordForm['machine_id']
            : null;
        $recordTaskId = $recordForm['task_id'] !== '' && ctype_digit($recordForm['task_id'])
            ? (int) $recordForm['task_id']
            : null;

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
        $tasks = maintenanceTasksList($db);
        $taskMap = [];
        foreach ($tasks as $task) {
            $taskMap[$task['id']] = $task;
        }
        $records = maintenanceRecordsList($db);
    } catch (\Throwable $exception) {
        $errors[] = 'Unable to refresh maintenance data: ' . $exception->getMessage();
    }
}

$machineCount = count($machines);
$taskCount = count($tasks);
$recordsCount = count($records);
$documentsCount = array_reduce(
    $machines,
    static fn (int $carry, array $machine): int => $carry + count($machine['documents']),
    0
);
$lastPerformed = $records[0]['performed_at'] ?? null;

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> Maintenance Hub</title>
  <link rel="stylesheet" href="css/dashboard.css" />
</head>
<body class="has-sidebar-toggle">
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
                    <th>Documents</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($machines)): ?>
                    <tr>
                      <td colspan="5">No machines recorded yet.</td>
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
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Machine</th>
                    <th>Task</th>
                    <th>Frequency</th>
                    <th>Owner</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($tasks)): ?>
                    <tr><td colspan="4">No tasks have been defined.</td></tr>
                  <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                      <tr>
                        <td><?= e($task['machine_name']) ?></td>
                        <td>
                          <strong><?= e($task['title']) ?></strong>
                          <?php if (!empty($task['description'])): ?>
                            <div class="small"><?= e($task['description']) ?></div>
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
                    <th>Notes</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($records)): ?>
                    <tr><td colspan="5">No maintenance records yet.</td></tr>
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
</body>
</html>
