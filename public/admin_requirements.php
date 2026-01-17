<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/inventory.php';
require_once __DIR__ . '/../app/data/configurator.php';

session_start();

$databaseConfig = $app['database'];
$db = null;
$errors = [];
$successMessage = null;
$requirements = [];
$inventoryItems = [];
$filterPartId = null;

try {
    $db = dbConnect($databaseConfig);
    configuratorEnsureSchema($db);
} catch (\Throwable $e) {
    $errors[] = 'Database connection failed: ' . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_requirement') {
        try {
            $stmt = $db->prepare('
                INSERT INTO configurator_part_requirements (
                    inventory_item_id,
                    required_inventory_item_id,
                    quantity,
                    finish_policy,
                    fixed_finish,
                    applies_when_opening_type,
                    applies_when_hand,
                    applies_when_hinging,
                    applies_when_job_scope,
                    target_component,
                    auto_add,
                    allow_removal,
                    priority,
                    fallback_item_id
                ) VALUES (
                    :inventory_item_id,
                    :required_inventory_item_id,
                    :quantity,
                    :finish_policy,
                    :fixed_finish,
                    :applies_when_opening_type,
                    :applies_when_hand,
                    :applies_when_hinging,
                    :applies_when_job_scope,
                    :target_component,
                    :auto_add,
                    :allow_removal,
                    :priority,
                    :fallback_item_id
                )
            ');

            $stmt->execute([
                'inventory_item_id' => (int)$_POST['inventory_item_id'],
                'required_inventory_item_id' => (int)$_POST['required_inventory_item_id'],
                'quantity' => (int)$_POST['quantity'],
                'finish_policy' => $_POST['finish_policy'],
                'fixed_finish' => $_POST['fixed_finish'] !== '' ? $_POST['fixed_finish'] : null,
                'applies_when_opening_type' => $_POST['applies_when_opening_type'] !== '' ? $_POST['applies_when_opening_type'] : null,
                'applies_when_hand' => $_POST['applies_when_hand'] !== '' ? $_POST['applies_when_hand'] : null,
                'applies_when_hinging' => $_POST['applies_when_hinging'] !== '' ? $_POST['applies_when_hinging'] : null,
                'applies_when_job_scope' => $_POST['applies_when_job_scope'] !== '' ? $_POST['applies_when_job_scope'] : null,
                'target_component' => $_POST['target_component'],
                'auto_add' => isset($_POST['auto_add']) ? 1 : 0,
                'allow_removal' => isset($_POST['allow_removal']) ? 1 : 0,
                'priority' => (int)$_POST['priority'],
                'fallback_item_id' => $_POST['fallback_item_id'] !== '' ? (int)$_POST['fallback_item_id'] : null,
            ]);

            $successMessage = 'Requirement added successfully!';
        } catch (\Throwable $e) {
            $errors[] = 'Failed to add requirement: ' . $e->getMessage();
        }
    } elseif ($action === 'delete_requirement') {
        try {
            $stmt = $db->prepare('DELETE FROM configurator_part_requirements WHERE id = :id');
            $stmt->execute(['id' => (int)$_POST['requirement_id']]);
            $successMessage = 'Requirement deleted successfully!';
        } catch (\Throwable $e) {
            $errors[] = 'Failed to delete requirement: ' . $e->getMessage();
        }
    } elseif ($action === 'export_requirements') {
        $format = $_POST['format'] ?? 'csv';

        try {
            $stmt = $db->query('
                SELECT
                    r.*,
                    i1.name as source_part_name,
                    i1.part_number as source_part_number,
                    i2.name as required_part_name,
                    i2.part_number as required_part_number,
                    i3.name as fallback_part_name
                FROM configurator_part_requirements r
                JOIN inventory_items i1 ON i1.id = r.inventory_item_id
                JOIN inventory_items i2 ON i2.id = r.required_inventory_item_id
                LEFT JOIN inventory_items i3 ON i3.id = r.fallback_item_id
                ORDER BY r.inventory_item_id, r.priority
            ');

            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($format === 'json') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="requirements_' . date('Y-m-d') . '.json"');
                echo json_encode($data, JSON_PRETTY_PRINT);
            } else {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="requirements_' . date('Y-m-d') . '.csv"');

                $output = fopen('php://output', 'w');

                // Headers
                if (!empty($data)) {
                    fputcsv($output, array_keys($data[0]));
                }

                // Data rows
                foreach ($data as $row) {
                    fputcsv($output, $row);
                }

                fclose($output);
            }
            exit;
        } catch (\Throwable $e) {
            $errors[] = 'Export failed: ' . $e->getMessage();
        }
    }
}

// Load requirements with filtering
if ($db) {
    try {
        $filterPartId = isset($_GET['filter_part_id']) && $_GET['filter_part_id'] !== ''
            ? (int)$_GET['filter_part_id']
            : null;

        $sql = '
            SELECT
                r.*,
                i1.name as source_part_name,
                i1.part_number as source_part_number,
                i2.name as required_part_name,
                i2.part_number as required_part_number,
                i3.name as fallback_part_name
            FROM configurator_part_requirements r
            JOIN inventory_items i1 ON i1.id = r.inventory_item_id
            JOIN inventory_items i2 ON i2.id = r.required_inventory_item_id
            LEFT JOIN inventory_items i3 ON i3.id = r.fallback_item_id
        ';

        if ($filterPartId) {
            $sql .= ' WHERE r.inventory_item_id = :filter_part_id';
        }

        $sql .= ' ORDER BY r.inventory_item_id, r.priority';

        $stmt = $db->prepare($sql);
        if ($filterPartId) {
            $stmt->execute(['filter_part_id' => $filterPartId]);
        } else {
            $stmt->execute();
        }

        $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Load inventory items for dropdowns
        $itemsStmt = $db->query('
            SELECT id, name, part_number, finish
            FROM inventory_items
            ORDER BY name ASC
        ');
        $inventoryItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (\Throwable $e) {
        $errors[] = 'Failed to load requirements: ' . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Requirements Manager - <?= e($app['app_name']) ?></title>
  <link rel="stylesheet" href="css/styles.css" />
  <style>
    .requirements-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
    }
    .requirements-table th,
    .requirements-table td {
      padding: 0.75rem;
      border: 1px solid #ddd;
      text-align: left;
      font-size: 0.875rem;
    }
    .requirements-table th {
      background: #f5f5f5;
      font-weight: 600;
    }
    .requirements-table tr:hover {
      background: #fafafa;
    }
    .badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 3px;
      font-size: 11px;
      font-weight: 600;
    }
    .badge-success { background: #4CAF50; color: white; }
    .badge-warning { background: #FF9800; color: white; }
    .badge-info { background: #2196F3; color: white; }
    .badge-secondary { background: #9E9E9E; color: white; }
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
    }
    .modal.active {
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .modal-content {
      background: white;
      padding: 2rem;
      border-radius: 8px;
      max-width: 600px;
      width: 90%;
      max-height: 90vh;
      overflow-y: auto;
    }
    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    .form-grid .full-width {
      grid-column: 1 / -1;
    }
  </style>
</head>
<body>
  <div class="app-layout">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <div class="app-content">
      <?php require __DIR__ . '/../app/views/partials/topbar.php'; ?>

      <main class="main-content">
        <div class="page-header">
          <div>
            <h1>Requirements Manager</h1>
            <p class="muted">Manage context-aware part requirements for the configurator</p>
          </div>
          <div style="display: flex; gap: 1rem;">
            <button type="button" class="button primary" onclick="openAddModal()">
              + Add Requirement
            </button>
            <button type="button" class="button secondary" onclick="openExportModal()">
              📥 Export
            </button>
          </div>
        </div>

        <?php if ($successMessage): ?>
          <div class="callout success">
            <?= e($successMessage) ?>
          </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
          <div class="callout error">
            <ul>
              <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="card">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3>All Requirements (<?= count($requirements) ?>)</h3>
            <form method="get" style="display: flex; gap: 0.5rem;">
              <select name="filter_part_id" class="input">
                <option value="">All source parts</option>
                <?php foreach ($inventoryItems as $item): ?>
                  <option value="<?= $item['id'] ?>" <?= $filterPartId === (int)$item['id'] ? 'selected' : '' ?>>
                    <?= e($item['name']) ?> (<?= e($item['part_number'] ?? '') ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="button secondary">Filter</button>
              <?php if ($filterPartId): ?>
                <a href="admin_requirements.php" class="button ghost">Clear</a>
              <?php endif; ?>
            </form>
          </div>

          <?php if (empty($requirements)): ?>
            <p class="muted">No requirements found. Click "Add Requirement" to create one.</p>
          <?php else: ?>
            <table class="requirements-table">
              <thead>
                <tr>
                  <th>Source Part</th>
                  <th>Requires</th>
                  <th>Qty</th>
                  <th>Target</th>
                  <th>Conditions</th>
                  <th>Finish Policy</th>
                  <th>Flags</th>
                  <th>Priority</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($requirements as $req): ?>
                  <tr>
                    <td>
                      <strong><?= e($req['source_part_name']) ?></strong><br>
                      <small class="muted"><?= e($req['source_part_number'] ?? '') ?></small>
                    </td>
                    <td>
                      <strong><?= e($req['required_part_name']) ?></strong><br>
                      <small class="muted"><?= e($req['required_part_number'] ?? '') ?></small>
                      <?php if ($req['fallback_part_name']): ?>
                        <br><small class="badge badge-info">Fallback: <?= e($req['fallback_part_name']) ?></small>
                      <?php endif; ?>
                    </td>
                    <td><?= $req['quantity'] ?>×</td>
                    <td><code><?= e($req['target_component']) ?></code></td>
                    <td>
                      <?php if ($req['applies_when_opening_type']): ?>
                        <span class="badge badge-info"><?= e($req['applies_when_opening_type']) ?></span>
                      <?php endif; ?>
                      <?php if ($req['applies_when_hand']): ?>
                        <span class="badge badge-info"><?= e($req['applies_when_hand']) ?></span>
                      <?php endif; ?>
                      <?php if ($req['applies_when_hinging']): ?>
                        <span class="badge badge-info"><?= e($req['applies_when_hinging']) ?></span>
                      <?php endif; ?>
                      <?php if ($req['applies_when_job_scope']): ?>
                        <span class="badge badge-info"><?= e($req['applies_when_job_scope']) ?></span>
                      <?php endif; ?>
                      <?php if (!$req['applies_when_opening_type'] && !$req['applies_when_hand'] && !$req['applies_when_hinging'] && !$req['applies_when_job_scope']): ?>
                        <span class="muted">Always</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge badge-secondary"><?= e($req['finish_policy']) ?></span>
                      <?php if ($req['fixed_finish']): ?>
                        <br><small><?= e($req['fixed_finish']) ?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($req['auto_add']): ?>
                        <span class="badge badge-success">AUTO</span>
                      <?php endif; ?>
                      <?php if ($req['allow_removal']): ?>
                        <span class="badge badge-warning">Removable</span>
                      <?php else: ?>
                        <span class="badge badge-secondary">Locked</span>
                      <?php endif; ?>
                    </td>
                    <td><?= $req['priority'] ?></td>
                    <td>
                      <form method="post" style="display: inline;" onsubmit="return confirm('Delete this requirement?');">
                        <input type="hidden" name="action" value="delete_requirement">
                        <input type="hidden" name="requirement_id" value="<?= $req['id'] ?>">
                        <button type="submit" class="button small ghost">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </main>
    </div>
  </div>

  <!-- Add Requirement Modal -->
  <div id="add-modal" class="modal">
    <div class="modal-content">
      <h2>Add New Requirement</h2>
      <form method="post" class="form">
        <input type="hidden" name="action" value="add_requirement">

        <div class="form-grid">
          <div class="full-width">
            <label>Source Part (When this part is selected...)</label>
            <select name="inventory_item_id" required class="input">
              <option value="">Select source part</option>
              <?php foreach ($inventoryItems as $item): ?>
                <option value="<?= $item['id'] ?>">
                  <?= e($item['name']) ?> (<?= e($item['part_number'] ?? '') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="full-width">
            <label>Required Part (...automatically add this part)</label>
            <select name="required_inventory_item_id" required class="input">
              <option value="">Select required part</option>
              <?php foreach ($inventoryItems as $item): ?>
                <option value="<?= $item['id'] ?>">
                  <?= e($item['name']) ?> (<?= e($item['part_number'] ?? '') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Quantity</label>
            <input type="number" name="quantity" value="1" min="1" required class="input">
          </div>

          <div>
            <label>Target Component</label>
            <select name="target_component" required class="input">
              <option value="parent">Parent (same as source)</option>
              <option value="active_door">Active Door</option>
              <option value="inactive_door">Inactive Door</option>
              <option value="door">Door (defaults to active)</option>
              <option value="lock_jamb">Lock Jamb</option>
              <option value="hinge_jamb">Hinge Jamb</option>
              <option value="door_head">Door Head</option>
              <option value="frame">Frame</option>
              <option value="transom">Transom</option>
            </select>
          </div>

          <div>
            <label>Finish Policy</label>
            <select name="finish_policy" required class="input">
              <option value="fixed">Fixed</option>
              <option value="match_frame">Match Frame</option>
              <option value="match_door">Match Door</option>
              <option value="any_available">Any Available</option>
            </select>
          </div>

          <div>
            <label>Fixed Finish (if policy = fixed)</label>
            <input type="text" name="fixed_finish" placeholder="e.g., DB, C2" class="input">
          </div>

          <div>
            <label>Priority (lower = first)</label>
            <input type="number" name="priority" value="0" class="input">
          </div>

          <div>
            <label>Fallback Part (if finish unavailable)</label>
            <select name="fallback_item_id" class="input">
              <option value="">None</option>
              <?php foreach ($inventoryItems as $item): ?>
                <option value="<?= $item['id'] ?>">
                  <?= e($item['name']) ?> (<?= e($item['part_number'] ?? '') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="full-width">
            <h4>Context Conditions (leave empty for "always")</h4>
          </div>

          <div>
            <label>Opening Type</label>
            <select name="applies_when_opening_type" class="input">
              <option value="">Any</option>
              <option value="single">Single</option>
              <option value="pair">Pair</option>
            </select>
          </div>

          <div>
            <label>Hand</label>
            <input type="text" name="applies_when_hand" placeholder="e.g., RHR Active" class="input">
          </div>

          <div>
            <label>Hinging</label>
            <select name="applies_when_hinging" class="input">
              <option value="">Any</option>
              <option value="continuous">Continuous</option>
              <option value="butt">Butt</option>
              <option value="pivot_offset">Pivot Offset</option>
              <option value="pivot_center">Pivot Center</option>
            </select>
          </div>

          <div>
            <label>Job Scope</label>
            <select name="applies_when_job_scope" class="input">
              <option value="">Any</option>
              <option value="door_and_frame">Door and Frame</option>
              <option value="frame_only">Frame Only</option>
              <option value="door_only">Door Only</option>
            </select>
          </div>

          <div class="full-width">
            <label>
              <input type="checkbox" name="auto_add" checked>
              Auto-add this part automatically
            </label>
          </div>

          <div class="full-width">
            <label>
              <input type="checkbox" name="allow_removal">
              Allow user to remove this part
            </label>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="button ghost" onclick="closeAddModal()">Cancel</button>
          <button type="submit" class="button primary">Add Requirement</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Export Modal -->
  <div id="export-modal" class="modal">
    <div class="modal-content">
      <h2>Export Requirements</h2>
      <form method="post">
        <input type="hidden" name="action" value="export_requirements">
        <div class="form-group">
          <label>Export Format</label>
          <select name="format" class="input">
            <option value="csv">CSV (Spreadsheet)</option>
            <option value="json">JSON (API/Code)</option>
          </select>
        </div>
        <div class="form-actions">
          <button type="button" class="button ghost" onclick="closeExportModal()">Cancel</button>
          <button type="submit" class="button primary">Download</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openAddModal() {
      document.getElementById('add-modal').classList.add('active');
    }
    function closeAddModal() {
      document.getElementById('add-modal').classList.remove('active');
    }
    function openExportModal() {
      document.getElementById('export-modal').classList.add('active');
    }
    function closeExportModal() {
      document.getElementById('export-modal').classList.remove('active');
    }

    // Close modals on background click
    document.querySelectorAll('.modal').forEach(modal => {
      modal.addEventListener('click', (e) => {
        if (e.target === modal) {
          modal.classList.remove('active');
        }
      });
    });
  </script>
</body>
</html>
