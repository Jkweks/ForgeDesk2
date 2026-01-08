<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../app/config/app.php';

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

$databaseConfig = $app['database'];
$dbError = null;
$errors = [];
$reservationData = null;
$reservationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($reservationId <= 0) {
    $errors[] = 'Select a job reservation to print.';
}

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null && isset($db) && $errors === []) {
    try {
        if (!inventorySupportsReservations($db)) {
            throw new \RuntimeException('Reservation support is not available in this environment.');
        }

        $reservationData = reservationFetch($db, $reservationId);
    } catch (\Throwable $exception) {
        $errors[] = $exception->getMessage();
        $reservationData = null;
    }
}

$reservation = $reservationData['reservation'] ?? null;
$items = $reservationData['items'] ?? [];

$committedItems = array_values(array_filter(
    $items,
    static fn (array $item): bool => (int) ($item['committed_qty'] ?? 0) > 0
));

$printedOn = date('M j, Y');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Committed Job Form · ForgeDesk</title>
    <link rel="stylesheet" href="/css/dashboard.css">
</head>
<body class="print-form">
<div class="print-form__page">
    <header class="print-form__header">
        <div>
            <h1>Committed Job Form</h1>
            <p class="small">Use this form to record parts pulled for fabrication.</p>
        </div>
        <div class="print-form__meta">
            <div><strong>Printed</strong> <?= e($printedOn) ?></div>
            <?php if ($reservation !== null): ?>
                <div><strong>Job</strong> <?= e($reservation['job_number']) ?> · Release <?= e((string) $reservation['release_number']) ?></div>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($dbError !== null): ?>
        <div class="panel message error">
            <strong>Database Error</strong>
            <p><?= e($dbError) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="panel message error">
            <strong>Unable to load reservation</strong>
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($reservation !== null): ?>
        <section class="print-form__details">
            <div>
                <strong>Job Name</strong>
                <span><?= e($reservation['job_name']) ?></span>
            </div>
            <div>
                <strong>Requested By</strong>
                <span><?= e($reservation['requested_by']) ?></span>
            </div>
            <div>
                <strong>Needed By</strong>
                <span><?= e(format_date($reservation['needed_by'] ?? null)) ?></span>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($reservation !== null): ?>
        <div class="table-wrapper print-form__table-wrapper">
            <table class="table print-form__table">
                <thead>
                <tr>
                    <th scope="col">SKU</th>
                    <th scope="col" class="numeric">Quantity Committed</th>
                    <th scope="col">Parts Used</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($committedItems === []): ?>
                    <tr>
                        <td colspan="3" class="muted">No committed inventory lines are available for this job.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($committedItems as $item): ?>
                        <tr>
                            <td><?= e($item['sku'] !== null && $item['sku'] !== '' ? $item['sku'] : '—') ?></td>
                            <td class="numeric"><?= e(inventoryFormatQuantity((int) $item['committed_qty'])) ?></td>
                            <td><div class="print-form__line" aria-hidden="true"></div></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <footer class="print-form__footer">
        <span class="small">Return this sheet with parts used for inventory reconciliation.</span>
        <div class="print-form__actions">
            <button type="button" class="button primary" onclick="window.print()">Print</button>
            <a href="/admin/job-reservations.php" class="button ghost">Back to Job Reservations</a>
        </div>
    </footer>
</div>
</body>
</html>
