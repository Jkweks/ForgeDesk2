<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';
$nav = require __DIR__ . '/../../app/data/navigation.php';

require_once __DIR__ . '/../../app/helpers/icons.php';
require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/data/inventory.php';
require_once __DIR__ . '/../../app/services/reservation_service.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

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
 * @param array{label:string,href?:string} $item
 */
function nav_href(array $item): string
{
    if (!empty($item['href'])) {
        return $item['href'];
    }

    $anchor = strtolower(str_replace(' ', '-', $item['label']));

    return '#' . $anchor;
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
    'error' => [],
];
$reservations = [];
$completionData = null;
$supportsReservations = false;
$completeId = isset($_GET['complete']) ? (int) $_GET['complete'] : null;
$prefillActuals = [];

if ($completeId !== null && $completeId <= 0) {
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
            $completeId = null;
        } catch (\Throwable $exception) {
            $flashMessages['error'][] = $exception->getMessage();
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
    <aside class="sidebar" aria-label="Primary">
        <div class="brand">
            <div class="brand-badge">FD</div>
            <div>
                <strong>ForgeDesk</strong><br>
                <span class="small">Operations Hub</span>
            </div>
            <span class="brand-version">beta</span>
        </div>
        <?php foreach ($nav as $group => $links): ?>
            <div class="nav-group">
                <h6><?= e($group) ?></h6>
                <?php foreach ($links as $link): ?>
                    <a class="nav-item<?= !empty($link['active']) ? ' active' : '' ?>" href="<?= e(nav_href($link)) ?>">
                        <?= icon($link['icon'] ?? 'grid') ?>
                        <span><?= e($link['label']) ?></span>
                        <?php if (!empty($link['badge'])): ?>
                            <span class="badge"><?= e((string) $link['badge']) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </aside>
    <header class="topbar">
        <div class="search" role="search">
            <?= icon('search') ?>
            <input type="search" placeholder="Search reservations" aria-label="Search reservations">
        </div>
        <div class="user">
            <div class="user-avatar">JD</div>
            <div>
                <div>Jordan Doe</div>
                <div class="user-email">ops@forgedesk.test</div>
            </div>
        </div>
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
                                        <?php if ($statusKey === 'committed'): ?>
                                            <form method="post" class="inline-form">
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="reservation_id" value="<?= e((string) $reservation['id']) ?>">
                                                <input type="hidden" name="status" value="in_progress">
                                                <button type="submit" class="button secondary">Start Work</button>
                                            </form>
                                        <?php elseif ($statusKey === 'in_progress'): ?>
                                            <a class="button primary" href="?complete=<?= e((string) $reservation['id']) ?>">Complete Job</a>
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
            <?php endif; ?>
        </div>
    </main>
</div>
<?php if ($completionData !== null): ?>
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
</body>
</html>
