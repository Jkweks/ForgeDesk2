<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/data/inventory.php';
require_once __DIR__ . '/../../app/services/reservation_service.php';

function format_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }

    $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if ($parsed instanceof \DateTimeImmutable) {
        return $parsed->format('M j, Y');
    }

    $timestamp = strtotime($date);

    return $timestamp !== false ? date('M j, Y', $timestamp) : $date;
}

/**
 * Build a readable label for inventory options displayed in the reservation editor.
 *
 * @param array{item:string,sku:?string,part_number:string,location:string,available_qty:int} $inventory
 */
function reservationInventoryOptionLabel(array $inventory): string
{
    $segments = [$inventory['item']];

    if (!empty($inventory['part_number'])) {
        $segments[] = 'PN ' . $inventory['part_number'];
    }

    if (!empty($inventory['sku'])) {
        $segments[] = 'SKU ' . $inventory['sku'];
    }

    if ($inventory['location'] !== '') {
        $segments[] = '@ ' . $inventory['location'];
    }

    $label = implode(' · ', $segments);

    return $label . ' — ' . inventoryFormatQuantity($inventory['available_qty']) . ' available';
}

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Job Reservations');
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$flashMessages = [
    'success' => [],
    'warning' => [],
    'error' => [],
];
$reservations = [];
$completionData = null;
$editData = null;
$supportsReservations = false;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editMetadata = null;
$editExistingValues = [];
$editNewValues = [];
$editUsingPost = false;
$inventoryCatalog = [];
$completeId = isset($_GET['complete']) ? (int) $_GET['complete'] : null;
$prefillActuals = [];

if ($editId !== null && $editId <= 0) {
    $editId = null;
}

if ($completeId !== null && $completeId <= 0) {
    $completeId = null;
}

if ($editId !== null) {
    $completeId = null;
}

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null && isset($db)) {
    try {
        $supportsReservations = inventorySupportsReservations($db);
    } catch (\Throwable $exception) {
        $supportsReservations = false;
        $flashMessages['error'][] = 'Reservation support could not be verified. Please ensure the latest migrations have been applied.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbError === null && isset($db) && $supportsReservations) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'status') {
        $reservationId = (int) ($_POST['reservation_id'] ?? 0);
        $targetStatus = (string) ($_POST['status'] ?? '');

        try {
            if ($reservationId <= 0) {
                throw new \InvalidArgumentException('Select a reservation to update.');
            }

            $result = reservationUpdateStatus($db, $reservationId, $targetStatus);
            $label = reservationStatusDisplay($result['new_status']);
            $flashMessages['success'][] = sprintf(
                'Moved job %s to %s.',
                $result['job_number'],
                $label
            );

            foreach ($result['warnings'] as $warning) {
                $flashMessages['warning'][] = $warning;
            }

            if (!empty($result['insufficient_items'])) {
                foreach ($result['insufficient_items'] as $item) {
                    $skuLabel = $item['sku'] !== null && $item['sku'] !== ''
                        ? $item['sku']
                        : 'SKU unavailable';
                    $location = $item['location'] !== null && $item['location'] !== ''
                        ? ' @ ' . $item['location']
                        : '';
                    $flashMessages['warning'][] = sprintf(
                        'Short on %s (%s%s): committed %s, on hand %s, short %s.',
                        $skuLabel,
                        $item['item'],
                        $location,
                        inventoryFormatQuantity($item['committed_qty']),
                        inventoryFormatQuantity($item['on_hand']),
                        inventoryFormatQuantity($item['shortage'])
                    );
                }
            }
            $completeId = null;
        } catch (\Throwable $exception) {
            $flashMessages['error'][] = $exception->getMessage();
        }
    } elseif ($action === 'edit') {
        $reservationId = (int) ($_POST['reservation_id'] ?? 0);
        $completeId = null;
        $completionData = null;
        $editUsingPost = true;
        $editId = $reservationId > 0 ? $reservationId : $editId;

        $editExistingValues = [];
        $editNewValues = [];

        $metadata = [
            'job_name' => trim((string) ($_POST['job_name'] ?? '')),
            'requested_by' => trim((string) ($_POST['requested_by'] ?? '')),
            'needed_by' => trim((string) ($_POST['needed_by'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];
        $editMetadata = $metadata;

        $existingLines = [];
        $newLines = [];
        $validationErrors = [];

        if ($reservationId <= 0) {
            $validationErrors[] = 'Select a reservation to edit.';
        }

        if (isset($_POST['existing']) && is_array($_POST['existing'])) {
            foreach ($_POST['existing'] as $key => $payload) {
                if (!is_array($payload)) {
                    continue;
                }

                $reservationItemId = ctype_digit((string) $key)
                    ? (int) $key
                    : (int) ($payload['reservation_item_id'] ?? 0);
                $inventoryItemId = isset($payload['inventory_item_id']) ? (int) $payload['inventory_item_id'] : 0;
                $requestedRaw = trim((string) ($payload['requested_qty'] ?? ''));
                $committedRaw = trim((string) ($payload['committed_qty'] ?? ''));

                if ($reservationItemId > 0) {
                    $editExistingValues[$reservationItemId] = [
                        'requested_qty' => $requestedRaw,
                        'committed_qty' => $committedRaw,
                    ];
                }

                if ($reservationItemId <= 0 || $inventoryItemId <= 0) {
                    $validationErrors[] = 'One of the reservation lines is missing required identifiers.';
                    continue;
                }

                if ($requestedRaw === '' || !ctype_digit($requestedRaw)) {
                    $validationErrors[] = 'Requested quantities must be whole numbers.';
                    continue;
                }

                if ($committedRaw === '' || !ctype_digit($committedRaw)) {
                    $validationErrors[] = 'Committed quantities must be whole numbers.';
                    continue;
                }

                $existingLines[] = [
                    'reservation_item_id' => $reservationItemId,
                    'inventory_item_id' => $inventoryItemId,
                    'requested_qty' => (int) $requestedRaw,
                    'committed_qty' => (int) $committedRaw,
                ];
            }
        }

        if (isset($_POST['new_items']) && is_array($_POST['new_items'])) {
            foreach ($_POST['new_items'] as $payload) {
                if (!is_array($payload)) {
                    continue;
                }

                $inventoryRaw = trim((string) ($payload['inventory_item_id'] ?? ''));
                $requestedRaw = trim((string) ($payload['requested_qty'] ?? ''));
                $committedRaw = trim((string) ($payload['committed_qty'] ?? ''));

                $editNewValues[] = [
                    'inventory_item_id' => $inventoryRaw,
                    'requested_qty' => $requestedRaw,
                    'committed_qty' => $committedRaw,
                ];

                if ($inventoryRaw === '' && ($requestedRaw !== '' || $committedRaw !== '')) {
                    $validationErrors[] = 'Select an inventory item before committing new stock.';
                    continue;
                }

                if ($inventoryRaw === '') {
                    continue;
                }

                if (!ctype_digit($inventoryRaw)) {
                    $validationErrors[] = 'Select a valid inventory item.';
                    continue;
                }

                $inventoryItemId = (int) $inventoryRaw;

                if ($committedRaw === '' || !ctype_digit($committedRaw)) {
                    $validationErrors[] = 'Committed quantities must be whole numbers.';
                    continue;
                }

                $committedQty = (int) $committedRaw;

                if ($committedQty <= 0) {
                    $validationErrors[] = 'Committed quantities for new lines must be greater than zero.';
                    continue;
                }

                $requestedQty = 0;
                if ($requestedRaw !== '') {
                    if (!ctype_digit($requestedRaw)) {
                        $validationErrors[] = 'Requested quantities must be whole numbers.';
                        continue;
                    }

                    $requestedQty = (int) $requestedRaw;
                }

                $newLines[] = [
                    'inventory_item_id' => $inventoryItemId,
                    'requested_qty' => $requestedQty,
                    'committed_qty' => $committedQty,
                ];
            }
        }

        if ($validationErrors !== []) {
            foreach (array_unique($validationErrors) as $message) {
                $flashMessages['error'][] = $message;
            }

            if ($reservationId > 0) {
                $editId = $reservationId;
            }
        } else {
            try {
                $result = reservationUpdateItems($db, $reservationId, $metadata, $existingLines, $newLines);

                $details = [];
                if ($result['updated'] > 0) {
                    $details[] = sprintf(
                        '%d existing %s adjusted',
                        $result['updated'],
                        $result['updated'] === 1 ? 'line' : 'lines'
                    );
                }
                if ($result['added'] > 0) {
                    $details[] = sprintf(
                        '%d new %s committed',
                        $result['added'],
                        $result['added'] === 1 ? 'line' : 'lines'
                    );
                }
                if ($result['committed'] > 0) {
                    $details[] = inventoryFormatQuantity($result['committed']) . ' unit(s) newly committed';
                }
                if ($result['released'] > 0) {
                    $details[] = inventoryFormatQuantity($result['released']) . ' unit(s) released';
                }

                $message = sprintf('Updated reservation %s.', $result['job_number']);
                if ($details !== []) {
                    $message .= ' ' . implode('; ', $details) . '.';
                } else {
                    $message .= ' No inventory adjustments were required.';
                }

                $flashMessages['success'][] = $message;
                $editId = null;
                $editMetadata = null;
                $editExistingValues = [];
                $editNewValues = [];
                $editUsingPost = false;
            } catch (\Throwable $exception) {
                $flashMessages['error'][] = $exception->getMessage();
                $editId = $reservationId;
            }
        }
    } elseif ($action === 'complete') {
        $reservationId = (int) ($_POST['reservation_id'] ?? 0);
        $prefillActuals = isset($_POST['actual_qty']) && is_array($_POST['actual_qty']) ? $_POST['actual_qty'] : [];
        $normalizedInputs = [];
        foreach ($prefillActuals as $key => $value) {
            $itemId = (int) $key;
            if ($itemId <= 0) {
                continue;
            }

            $normalizedInputs[(string) $itemId] = max(0, (int) $value);
        }
        $prefillActuals = $normalizedInputs;

        try {
            if ($reservationId <= 0) {
                throw new \InvalidArgumentException('Select a reservation to complete.');
            }

            $summary = reservationComplete($db, $reservationId, $prefillActuals);

            $flashMessages['success'][] = sprintf(
                'Completed job %s. Consumed %d unit(s) and released %d back to inventory.',
                $summary['job_number'],
                $summary['consumed'],
                $summary['released']
            );

            $completeId = null;
            $prefillActuals = [];
        } catch (\Throwable $exception) {
            $flashMessages['error'][] = $exception->getMessage();
            $completeId = $reservationId;
        }
    } else {
        $flashMessages['error'][] = 'Unsupported action requested.';
    }
}

if ($dbError === null && isset($db) && $supportsReservations) {
    try {
        $reservations = reservationList($db);
    } catch (\Throwable $exception) {
        $flashMessages['error'][] = $exception->getMessage();
        $reservations = [];
    }

    if ($completeId !== null) {
        try {
            $completionData = reservationFetch($db, $completeId);
            if ($completionData['reservation']['status'] !== 'in_progress') {
                $flashMessages['error'][] = 'Only in-process reservations can be completed.';
                $completionData = null;
                $completeId = null;
            }
        } catch (\Throwable $exception) {
            $flashMessages['error'][] = $exception->getMessage();
            $completionData = null;
            $completeId = null;
        }
    }

    if ($editId !== null) {
        try {
            $editData = reservationFetch($db, $editId);

            $status = $editData['reservation']['status'];
            if (in_array($status, ['fulfilled', 'cancelled'], true)) {
                $flashMessages['error'][] = 'Completed or cancelled reservations cannot be edited.';
                $editData = null;
                $editId = null;
            }
        } catch (\Throwable $exception) {
            $flashMessages['error'][] = $exception->getMessage();
            $editData = null;
            $editId = null;
        }

        if ($editData !== null) {
            try {
                $inventoryCatalog = loadInventory($db);
            } catch (\Throwable $exception) {
                $inventoryCatalog = [];
                $flashMessages['error'][] = 'Unable to load inventory catalog for editing: ' . $exception->getMessage();
            }

            $reservation = $editData['reservation'];

            if ($editMetadata === null || !$editUsingPost) {
                $editMetadata = [
                    'job_name' => $reservation['job_name'],
                    'requested_by' => $reservation['requested_by'],
                    'needed_by' => $reservation['needed_by'] ?? '',
                    'notes' => $reservation['notes'] ?? '',
                ];
            }

            if (!$editUsingPost) {
                $editExistingValues = [];
                foreach ($editData['items'] as $item) {
                    $editExistingValues[$item['id']] = [
                        'requested_qty' => (string) $item['requested_qty'],
                        'committed_qty' => (string) $item['committed_qty'],
                    ];
                }
                $editNewValues = [];
            } else {
                foreach ($editData['items'] as $item) {
                    if (!isset($editExistingValues[$item['id']])) {
                        $editExistingValues[$item['id']] = [
                            'requested_qty' => (string) $item['requested_qty'],
                            'committed_qty' => (string) $item['committed_qty'],
                        ];
                    }
                }
            }
        }
    }
}

$statusLabels = reservationStatusLabels();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Job Reservations · ForgeDesk</title>
    <link rel="stylesheet" href="/css/dashboard.css">
</head>
<body>
<div class="layout">
    <?php $sidebarAriaLabel = 'Primary'; require __DIR__ . '/../../app/views/partials/sidebar.php'; ?>
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
        <div class="search" role="search">
            <?= icon('search') ?>
            <input type="search" placeholder="Search reservations" aria-label="Search reservations">
        </div>
        <button class="user" type="button">
            <span class="user-avatar" aria-hidden="true">JD</span>
            <span class="user-details">
                <span>Jordan Doe</span>
                <span class="user-email">ops@forgedesk.test</span>
            </span>
            <span aria-hidden="true"><?= icon('chev') ?></span>
        </button>
    </header>
    <main class="content">
        <div class="panel">
            <header>
                <div>
                    <h1>Job Reservations</h1>
                    <p class="small">Track reservations from commitment through fulfillment.</p>
                </div>
                <div class="header-actions">
                    <a href="/admin/estimate-check.php" class="button ghost">Back to Estimate Check</a>
                </div>
            </header>
            <?php if ($dbError !== null): ?>
                <div class="panel message error">
                    <strong>Database Error</strong>
                    <p><?= e($dbError) ?></p>
                </div>
            <?php endif; ?>
            <?php foreach ($flashMessages['success'] as $message): ?>
                <div class="panel message success">
                    <strong>Success</strong>
                    <p><?= e($message) ?></p>
                </div>
            <?php endforeach; ?>
            <?php foreach ($flashMessages['warning'] as $message): ?>
                <div class="panel message warning">
                    <strong>Warning</strong>
                    <p><?= e($message) ?></p>
                </div>
            <?php endforeach; ?>
            <?php foreach ($flashMessages['error'] as $message): ?>
                <div class="panel message error">
                    <strong>Heads up</strong>
                    <p><?= e($message) ?></p>
                </div>
            <?php endforeach; ?>
            <?php if ($dbError === null && !$supportsReservations): ?>
                <div class="panel message error">
                    <strong>Reservation support unavailable</strong>
                    <p>This environment does not have the reservation tables installed.</p>
                </div>
            <?php endif; ?>
            <?php if ($dbError === null && $supportsReservations): ?>
                <div class="table-wrapper reservation-table-wrapper">
                    <table class="table reservation-table">
                        <thead>
                        <tr>
                            <th scope="col">Job</th>
                            <th scope="col">Needed</th>
                            <th scope="col">Requested By</th>
                            <th scope="col">Lines</th>
                            <th scope="col">Committed</th>
                            <th scope="col">Consumed</th>
                            <th scope="col">Remaining</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="actions">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($reservations === []): ?>
                            <tr>
                                <td colspan="9" class="muted">No reservations have been created yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reservations as $reservation): ?>
                                <?php
                                $statusKey = $reservation['status'];
                                $statusMeta = $statusLabels[$statusKey] ?? ['label' => ucfirst($statusKey), 'order' => 99];
                                $statusLabel = $statusMeta['label'];
                                $neededBy = format_date($reservation['needed_by']);
                                $remaining = max($reservation['committed_qty'] - $reservation['consumed_qty'], 0);
                                ?>
                                <tr>
                                    <td>
                                        <div class="job-title"><?= e($reservation['job_number']) ?></div>
                                        <div class="small muted"><?= e($reservation['job_name']) ?></div>
                                    </td>
                                    <td><?= e($neededBy) ?></td>
                                    <td><?= e($reservation['requested_by']) ?></td>
                                    <td><?= e((string) $reservation['line_count']) ?></td>
                                    <td>
                                        <span class="quantity-pill brand" aria-label="Total committed">
                                            <?= e((string) $reservation['committed_qty']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="quantity-pill success" aria-label="Total consumed">
                                            <?= e((string) $reservation['consumed_qty']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="quantity-pill<?= $remaining > 0 ? ' warning' : ' success' ?>" aria-label="Remaining commitment">
                                            <?= e((string) $remaining) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="reservation-status" data-state="<?= e($statusKey) ?>">
                                            <?= e($statusLabel) ?>
                                        </span>
                                    </td>
                                    <td class="actions">
                                        <div class="action-stack">
                                            <?php $hasAction = false; ?>
                                            <?php if (!in_array($statusKey, ['fulfilled', 'cancelled'], true)): ?>
                                                <?php $hasAction = true; ?>
                                                <a class="button ghost" href="?edit=<?= e((string) $reservation['id']) ?>">View &amp; Edit</a>
                                            <?php endif; ?>
                                            <?php if ($statusKey === 'active'): ?>
                                                <?php $hasAction = true; ?>
                                                <form method="post" class="inline-form">
                                                    <input type="hidden" name="action" value="status">
                                                    <input type="hidden" name="reservation_id" value="<?= e((string) $reservation['id']) ?>">
                                                    <input type="hidden" name="status" value="in_progress">
                                                    <button type="submit" class="button secondary">Start Work</button>
                                                </form>
                                            <?php elseif ($statusKey === 'in_progress'): ?>
                                                <?php $hasAction = true; ?>
                                                <a class="button primary" href="?complete=<?= e((string) $reservation['id']) ?>">Complete Job</a>
                                            <?php endif; ?>
                                            <?php if (!$hasAction): ?>
                                                <span class="muted">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php if ($editData !== null): ?>
    <?php
    $reservation = $editData['reservation'];
    $items = $editData['items'];
    $inventoryOptions = array_map(
        static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'item' => (string) $row['item'],
                'part_number' => (string) $row['part_number'],
                'sku' => (string) $row['sku'],
                'location' => (string) $row['location'],
                'available_qty' => (int) $row['available_qty'],
            ];
        },
        $inventoryCatalog
    );
    ?>
    <div class="modal-backdrop" aria-hidden="true"></div>
    <section class="reservation-editor" role="dialog" aria-modal="true" aria-labelledby="reservation-editor-title">
        <header class="reservation-editor__header">
            <div>
                <h2 id="reservation-editor-title">Edit Reservation</h2>
                <p class="small">Update commitments for <?= e($reservation['job_number']) ?>.</p>
            </div>
            <a href="/admin/job-reservations.php" class="button ghost" aria-label="Close reservation editor">Close</a>
        </header>
        <form method="post" class="reservation-editor__form">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="reservation_id" value="<?= e((string) $reservation['id']) ?>">
            <section class="reservation-editor__grid">
                <div class="field">
                    <label for="edit-job-number">Job Number</label>
                    <input type="text" id="edit-job-number" value="<?= e($reservation['job_number']) ?>" readonly>
                </div>
                <div class="field">
                    <label for="edit-job-name">Job Name<span aria-hidden="true">*</span></label>
                    <input type="text" id="edit-job-name" name="job_name" value="<?= e($editMetadata['job_name'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="edit-requested-by">Requested By<span aria-hidden="true">*</span></label>
                    <input type="text" id="edit-requested-by" name="requested_by" value="<?= e($editMetadata['requested_by'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="edit-needed-by">Needed By</label>
                    <input type="date" id="edit-needed-by" name="needed_by" value="<?= e($editMetadata['needed_by'] ?? '') ?>">
                </div>
            </section>
            <div class="field">
                <label for="edit-notes">Notes</label>
                <textarea id="edit-notes" name="notes" rows="3" placeholder="Add context for the team"><?= e($editMetadata['notes'] ?? '') ?></textarea>
            </div>
            <div class="reservation-editor__table-wrapper">
                <table class="table reservation-editor__table">
                    <thead>
                    <tr>
                        <th scope="col">Inventory Item</th>
                        <th scope="col">Requested</th>
                        <th scope="col">Committed</th>
                        <th scope="col">Consumed</th>
                        <th scope="col">On Hand</th>
                        <th scope="col">Available</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $reservationItemId = (int) $item['id'];
                        $values = $editExistingValues[$reservationItemId] ?? [
                            'requested_qty' => (string) $item['requested_qty'],
                            'committed_qty' => (string) $item['committed_qty'],
                        ];
                        $requestedInputId = 'existing-requested-' . $reservationItemId;
                        $committedInputId = 'existing-committed-' . $reservationItemId;
                        $consumedQty = (int) $item['consumed_qty'];
                        $onHand = (int) $item['stock'];
                        $availableQty = $onHand - (int) $item['inventory_committed'];
                        $availableClass = 'success';
                        if ($availableQty < 0) {
                            $availableClass = 'danger';
                        } elseif ($availableQty === 0) {
                            $availableClass = 'warning';
                        }
                        ?>
                        <tr>
                            <th scope="row">
                                <div class="job-title"><?= e($item['item']) ?></div>
                                <div class="small muted"><?= e($item['part_number']) ?><?php if (!empty($item['finish'])): ?> · <?= e((string) $item['finish']) ?><?php endif; ?></div>
                                <?php if (!empty($item['sku'])): ?>
                                    <div class="small muted">SKU <?= e((string) $item['sku']) ?></div>
                                <?php endif; ?>
                            </th>
                            <td>
                                <label class="sr-only" for="<?= e($requestedInputId) ?>">Requested quantity</label>
                                <input
                                    id="<?= e($requestedInputId) ?>"
                                    type="number"
                                    name="existing[<?= e((string) $reservationItemId) ?>][requested_qty]"
                                    value="<?= e($values['requested_qty']) ?>"
                                    min="0"
                                    required
                                >
                            </td>
                            <td>
                                <label class="sr-only" for="<?= e($committedInputId) ?>">Committed quantity</label>
                                <input
                                    id="<?= e($committedInputId) ?>"
                                    type="number"
                                    name="existing[<?= e((string) $reservationItemId) ?>][committed_qty]"
                                    value="<?= e($values['committed_qty']) ?>"
                                    min="<?= e((string) $consumedQty) ?>"
                                    required
                                >
                                <input type="hidden" name="existing[<?= e((string) $reservationItemId) ?>][inventory_item_id]" value="<?= e((string) $item['inventory_item_id']) ?>">
                            </td>
                            <td>
                                <span class="quantity-pill success"><?= e(inventoryFormatQuantity($consumedQty)) ?></span>
                            </td>
                            <td>
                                <span class="quantity-pill"><?= e(inventoryFormatQuantity($onHand)) ?></span>
                            </td>
                            <td>
                                <span class="quantity-pill <?= $availableClass ?>"><?= e(inventoryFormatQuantity($availableQty)) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <section class="reservation-editor__new-lines">
                <header>
                    <h3>Commit additional inventory</h3>
                    <p class="small muted">Add new inventory lines to hold stock for this job.</p>
                </header>
                <div class="reservation-editor__new-container" data-new-items>
                    <?php $rowIndex = 0; ?>
                    <?php foreach ($editNewValues as $newValue): ?>
                        <?php
                        $inventoryFieldId = 'new-inventory-' . $rowIndex;
                        $requestedFieldId = 'new-requested-' . $rowIndex;
                        $committedFieldId = 'new-committed-' . $rowIndex;
                        ?>
                        <div class="reservation-editor__new-row" data-new-row>
                            <div class="field">
                                <label class="sr-only" for="<?= e($inventoryFieldId) ?>">Inventory item</label>
                                <select id="<?= e($inventoryFieldId) ?>" name="new_items[<?= e((string) $rowIndex) ?>][inventory_item_id]">
                                    <option value="">Select inventory item</option>
                                    <?php foreach ($inventoryOptions as $option): ?>
                                        <option value="<?= e((string) $option['id']) ?>"<?= ($newValue['inventory_item_id'] ?? '') === (string) $option['id'] ? ' selected' : '' ?>><?= e(reservationInventoryOptionLabel($option)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label class="sr-only" for="<?= e($requestedFieldId) ?>">Requested quantity</label>
                                <input type="number" id="<?= e($requestedFieldId) ?>" name="new_items[<?= e((string) $rowIndex) ?>][requested_qty]" min="0" value="<?= e($newValue['requested_qty'] ?? '') ?>" placeholder="Requested">
                            </div>
                            <div class="field">
                                <label class="sr-only" for="<?= e($committedFieldId) ?>">Committed quantity</label>
                                <input type="number" id="<?= e($committedFieldId) ?>" name="new_items[<?= e((string) $rowIndex) ?>][committed_qty]" min="0" value="<?= e($newValue['committed_qty'] ?? '') ?>" placeholder="Committed">
                            </div>
                            <button type="button" class="button ghost reservation-editor__remove" data-remove-new-row aria-label="Remove inventory line">
                                <span aria-hidden="true">&times;</span>
                                <span class="sr-only">Remove</span>
                            </button>
                        </div>
                        <?php $rowIndex++; ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button secondary" data-add-new-item>Add inventory line</button>
            </section>
            <div class="reservation-editor__actions">
                <a href="/admin/job-reservations.php" class="button secondary">Cancel</a>
                <button type="submit" class="button primary">Save changes</button>
            </div>
        </form>
    </section>
    <template id="reservation-new-item-template">
        <div class="reservation-editor__new-row" data-new-row>
            <div class="field">
                <label class="sr-only" for="new-inventory-__INDEX__">Inventory item</label>
                <select id="new-inventory-__INDEX__" name="new_items[__INDEX__][inventory_item_id]">
                    <option value="">Select inventory item</option>
                    <?php foreach ($inventoryOptions as $option): ?>
                        <option value="<?= e((string) $option['id']) ?>"><?= e(reservationInventoryOptionLabel($option)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="sr-only" for="new-requested-__INDEX__">Requested quantity</label>
                <input type="number" id="new-requested-__INDEX__" name="new_items[__INDEX__][requested_qty]" min="0" placeholder="Requested">
            </div>
            <div class="field">
                <label class="sr-only" for="new-committed-__INDEX__">Committed quantity</label>
                <input type="number" id="new-committed-__INDEX__" name="new_items[__INDEX__][committed_qty]" min="0" placeholder="Committed">
            </div>
            <button type="button" class="button ghost reservation-editor__remove" data-remove-new-row aria-label="Remove inventory line">
                <span aria-hidden="true">&times;</span>
                <span class="sr-only">Remove</span>
            </button>
        </div>
    </template>
    <script>
    (function () {
        const container = document.querySelector('[data-new-items]');
        const addButton = document.querySelector('[data-add-new-item]');
        const template = document.getElementById('reservation-new-item-template');

        if (!container || !addButton || !template) {
            return;
        }

        let index = container.querySelectorAll('[data-new-row]').length;

        addButton.addEventListener('click', () => {
            const html = template.innerHTML.replace(/__INDEX__/g, String(index));
            container.insertAdjacentHTML('beforeend', html);
            index += 1;
        });

        container.addEventListener('click', (event) => {
            const target = event.target instanceof HTMLElement ? event.target : null;
            if (!target) {
                return;
            }

            const removeButton = target.closest('[data-remove-new-row]');
            if (!removeButton) {
                return;
            }

            event.preventDefault();
            const row = removeButton.closest('[data-new-row]');
            if (row) {
                row.remove();
            }
        });
    })();
    </script>
<?php elseif ($completionData !== null): ?>
    <?php
    $reservation = $completionData['reservation'];
    $items = $completionData['items'];
    ?>
    <div class="modal-backdrop" aria-hidden="true"></div>
    <section class="completion-modal" role="dialog" aria-modal="true" aria-labelledby="completion-title">
        <header class="completion-modal__header">
            <div>
                <h2 id="completion-title">Complete Reservation</h2>
                <p class="small">Confirm the actual quantities consumed for <?= e($reservation['job_number']) ?>.</p>
            </div>
            <a href="/admin/job-reservations.php" class="button ghost" aria-label="Close completion form">Close</a>
        </header>
        <form method="post" class="completion-form">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="reservation_id" value="<?= e((string) $reservation['id']) ?>">
            <div class="completion-summary">
                <div>
                    <strong><?= e($reservation['job_name']) ?></strong>
                    <p class="small">Requested by <?= e($reservation['requested_by']) ?><?php if ($reservation['needed_by'] !== null): ?> · Needed by <?= e(format_date($reservation['needed_by'])) ?><?php endif; ?></p>
                </div>
                <?php if (!empty($reservation['notes'])): ?>
                    <p class="small muted">Notes: <?= e($reservation['notes']) ?></p>
                <?php endif; ?>
            </div>
            <div class="table-wrapper completion-table-wrapper">
                <table class="table completion-table">
                    <thead>
                    <tr>
                        <th scope="col">Inventory Item</th>
                        <th scope="col">Committed</th>
                        <th scope="col">Actual Used</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $inventoryId = (int) $item['inventory_item_id'];
                        $inputId = 'actual-' . $inventoryId;
                        $defaultValue = $item['committed_qty'] + $item['consumed_qty'];
                        $prefill = isset($prefillActuals[(string) $inventoryId]) ? (int) $prefillActuals[(string) $inventoryId] : $defaultValue;
                        $maxAllowed = $item['committed_qty'] + $item['consumed_qty'];
                        if ($prefill > $maxAllowed) {
                            $prefill = $maxAllowed;
                        }
                        ?>
                        <tr>
                            <th scope="row">
                                <div class="job-title"><?= e($item['item']) ?></div>
                                <div class="small muted"><?= e($item['part_number']) ?><?php if (!empty($item['finish'])): ?> · <?= e((string) $item['finish']) ?><?php endif; ?></div>
                                <?php if (!empty($item['sku'])): ?>
                                    <div class="small muted">SKU <?= e((string) $item['sku']) ?></div>
                                <?php endif; ?>
                            </th>
                            <td>
                                <span class="quantity-pill brand"><?= e((string) $item['committed_qty']) ?></span>
                            </td>
                            <td>
                                <label class="sr-only" for="<?= e($inputId) ?>">Actual quantity used</label>
                                <input
                                    id="<?= e($inputId) ?>"
                                    class="quantity-input"
                                    name="actual_qty[<?= e((string) $inventoryId) ?>]"
                                    type="number"
                                    inputmode="numeric"
                                    min="0"
                                    max="<?= e((string) ($item['committed_qty'] + $item['consumed_qty'])) ?>"
                                    value="<?= e((string) $prefill) ?>"
                                    required
                                >
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="completion-actions">
                <a href="/admin/job-reservations.php" class="button secondary">Cancel</a>
                <button type="submit" class="button primary">Complete Reservation</button>
            </div>
        </form>
    </section>
<?php endif; ?>
<script src="/js/dashboard.js"></script>
</body>
</html>
