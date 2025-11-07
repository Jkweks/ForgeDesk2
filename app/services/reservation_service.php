<?php

declare(strict_types=1);

require_once __DIR__ . '/../data/inventory.php';

if (!function_exists('reservationStatusLabels')) {
    /**
     * Map internal reservation statuses to user-friendly labels.
     *
     * @return array<string,array{label:string,order:int,description:string}>
     */
    function reservationStatusLabels(): array
    {
        return [
            'committed' => [
                'label' => 'Holding',
                'order' => 1,
                'description' => 'Inventory has been committed and is waiting to be pulled.',
            ],
            'in_progress' => [
                'label' => 'In Process',
                'order' => 2,
                'description' => 'Work has started and the team is actively consuming inventory.',
            ],
            'fulfilled' => [
                'label' => 'Complete',
                'order' => 3,
                'description' => 'All committed inventory has been reconciled for this job.',
            ],
            'draft' => [
                'label' => 'Draft',
                'order' => 0,
                'description' => 'Reservation details are still being gathered.',
            ],
            'cancelled' => [
                'label' => 'Cancelled',
                'order' => 99,
                'description' => 'The reservation was cancelled and no inventory is being held.',
            ],
        ];
    }
}

if (!function_exists('reservationStatusDisplay')) {
    function reservationStatusDisplay(string $status): string
    {
        $map = reservationStatusLabels();

        return $map[$status]['label'] ?? ucfirst(str_replace('_', ' ', $status));
    }
}

if (!function_exists('reservationList')) {
    /**
     * Fetch reservations with aggregate quantity summaries for listing views.
     *
     * @return list<array{
     *   id:int,
     *   job_number:string,
     *   job_name:string,
     *   requested_by:string,
     *   needed_by:?string,
     *   status:string,
     *   notes:?string,
     *   created_at:string,
     *   updated_at:string,
     *   requested_qty:int,
     *   committed_qty:int,
     *   consumed_qty:int,
     *   line_count:int
     * }>
     */
    function reservationList(\PDO $db): array
    {
        ensureInventorySchema($db);

        if (!inventorySupportsReservations($db)) {
            throw new \RuntimeException('Job reservations are not supported by this database.');
        }

        $sql = "SELECT\n                jr.id, jr.job_number, jr.job_name, jr.requested_by, jr.needed_by, jr.status, jr.notes,\n                jr.created_at, jr.updated_at,\n                COALESCE(SUM(jri.requested_qty), 0) AS requested_qty,\n                COALESCE(SUM(jri.committed_qty), 0) AS committed_qty,\n                COALESCE(SUM(jri.consumed_qty), 0) AS consumed_qty,\n                COUNT(jri.id) AS line_count\n            FROM job_reservations jr\n            LEFT JOIN job_reservation_items jri ON jri.reservation_id = jr.id\n            GROUP BY jr.id\n            ORDER BY CASE WHEN jr.status IN ('fulfilled', 'cancelled') THEN 1 ELSE 0 END, jr.created_at DESC";

        $statement = $db->query($sql);

        if ($statement === false) {
            return [];
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'job_number' => (string) $row['job_number'],
                    'job_name' => (string) $row['job_name'],
                    'requested_by' => (string) $row['requested_by'],
                    'needed_by' => isset($row['needed_by']) ? (string) $row['needed_by'] : null,
                    'status' => (string) $row['status'],
                    'notes' => isset($row['notes']) ? (string) $row['notes'] : null,
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                    'requested_qty' => (int) $row['requested_qty'],
                    'committed_qty' => (int) $row['committed_qty'],
                    'consumed_qty' => (int) $row['consumed_qty'],
                    'line_count' => (int) $row['line_count'],
                ];
            },
            $rows
        );
    }
}

if (!function_exists('reservationFetch')) {
    /**
     * @return array{
     *   reservation:array{
     *     id:int,job_number:string,job_name:string,requested_by:string,needed_by:?string,status:string,notes:?string
     *   },
     *   items:list<array{
     *     id:int,
     *     inventory_item_id:int,
     *     requested_qty:int,
     *     committed_qty:int,
     *     consumed_qty:int,
     *     item:string,
     *     sku:?string,
     *     part_number:string,
     *     finish:?string,
     *     stock:int,
     *     inventory_committed:int
     *   }>
     * }
     */
    function reservationFetch(\PDO $db, int $reservationId): array
    {
        ensureInventorySchema($db);

        if (!inventorySupportsReservations($db)) {
            throw new \RuntimeException('Job reservations are not supported by this database.');
        }

        $header = $db->prepare(
            'SELECT id, job_number, job_name, requested_by, needed_by, status, notes '
            . 'FROM job_reservations WHERE id = :id'
        );
        $header->execute([':id' => $reservationId]);

        $reservation = $header->fetch(\PDO::FETCH_ASSOC);

        if ($reservation === false) {
            throw new \RuntimeException('Reservation not found.');
        }

        $itemsStatement = $db->prepare(
            'SELECT jri.id, jri.inventory_item_id, jri.requested_qty, jri.committed_qty, jri.consumed_qty, '
            . 'i.item, i.sku, i.part_number, i.finish, i.stock, i.committed_qty AS inventory_committed '
            . 'FROM job_reservation_items jri '
            . 'JOIN inventory_items i ON i.id = jri.inventory_item_id '
            . 'WHERE jri.reservation_id = :id '
            . 'ORDER BY i.item, i.part_number'
        );
        $itemsStatement->execute([':id' => $reservationId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $itemsStatement->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'reservation' => [
                'id' => (int) $reservation['id'],
                'job_number' => (string) $reservation['job_number'],
                'job_name' => (string) $reservation['job_name'],
                'requested_by' => (string) $reservation['requested_by'],
                'needed_by' => isset($reservation['needed_by']) ? (string) $reservation['needed_by'] : null,
                'status' => (string) $reservation['status'],
                'notes' => isset($reservation['notes']) ? (string) $reservation['notes'] : null,
            ],
            'items' => array_map(
                static function (array $row): array {
                    return [
                        'id' => (int) $row['id'],
                        'inventory_item_id' => (int) $row['inventory_item_id'],
                        'requested_qty' => (int) $row['requested_qty'],
                        'committed_qty' => (int) $row['committed_qty'],
                        'consumed_qty' => (int) $row['consumed_qty'],
                        'item' => (string) $row['item'],
                        'sku' => isset($row['sku']) ? (string) $row['sku'] : null,
                        'part_number' => (string) $row['part_number'],
                        'finish' => isset($row['finish']) ? (string) $row['finish'] : null,
                        'stock' => (int) $row['stock'],
                        'inventory_committed' => (int) $row['inventory_committed'],
                    ];
                },
                $rows
            ),
        ];
    }
}

if (!function_exists('reservationUpdateStatus')) {
    /**
     * Transition a reservation through the supported status workflow.
     *
     * @return array{
     *   id:int,
     *   job_number:string,
     *   previous_status:string,
     *   new_status:string,
     *   warnings:list<string>,
     *   insufficient_items:list<array{
     *     inventory_item_id:int,
     *     item:string,
     *     sku:?string,
     *     committed_qty:int,
     *     on_hand:int,
     *     shortage:int,
     *     location:?string
     *   }>
     * }
     */
    function reservationUpdateStatus(\PDO $db, int $reservationId, string $targetStatus): array
    {
        ensureInventorySchema($db);

        if (!inventorySupportsReservations($db)) {
            throw new \RuntimeException('Job reservations are not supported by this database.');
        }

        $targetStatus = strtolower(trim($targetStatus));
        $allowed = reservationStatusLabels();

        if (!isset($allowed[$targetStatus])) {
            throw new \InvalidArgumentException('Select a valid reservation status.');
        }

        $db->beginTransaction();

        try {
            $statement = $db->prepare(
                'SELECT id, job_number, status FROM job_reservations WHERE id = :id FOR UPDATE'
            );
            $statement->execute([':id' => $reservationId]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                throw new \RuntimeException('Reservation not found.');
            }

            $currentStatus = (string) $row['status'];

            $warnings = [];
            $insufficient = [];

            if ($currentStatus === $targetStatus) {
                $db->commit();

                return [
                    'id' => (int) $row['id'],
                    'job_number' => (string) $row['job_number'],
                    'previous_status' => $currentStatus,
                    'new_status' => $targetStatus,
                    'warnings' => $warnings,
                    'insufficient_items' => $insufficient,
                ];
            }

            $transitions = [
                'committed' => ['in_progress'],
            ];

            if (!isset($transitions[$currentStatus]) || !in_array($targetStatus, $transitions[$currentStatus], true)) {
                throw new \RuntimeException('That status change is not allowed.');
            }

            if ($currentStatus === 'committed' && $targetStatus === 'in_progress') {
                $itemStatement = $db->prepare(
                    'SELECT jri.inventory_item_id, jri.committed_qty, i.item, i.sku, i.stock, i.location '
                    . 'FROM job_reservation_items jri '
                    . 'JOIN inventory_items i ON i.id = jri.inventory_item_id '
                    . 'WHERE jri.reservation_id = :id'
                );
                $itemStatement->execute([':id' => $reservationId]);

                /** @var list<array<string,mixed>> $itemRows */
                $itemRows = $itemStatement->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($itemRows as $itemRow) {
                    $committedQty = (int) $itemRow['committed_qty'];
                    $onHand = (int) $itemRow['stock'];

                    if ($committedQty > $onHand) {
                        $shortage = $committedQty - $onHand;
                        $insufficient[] = [
                            'inventory_item_id' => (int) $itemRow['inventory_item_id'],
                            'item' => (string) $itemRow['item'],
                            'sku' => isset($itemRow['sku']) ? (string) $itemRow['sku'] : null,
                            'committed_qty' => $committedQty,
                            'on_hand' => $onHand,
                            'shortage' => $shortage,
                            'location' => isset($itemRow['location']) ? (string) $itemRow['location'] : null,
                        ];
                    }
                }

                if ($insufficient !== []) {
                    $warnings[] = 'Starting work will overdraw inventory for at least one line item.';
                }
            }

            $update = $db->prepare('UPDATE job_reservations SET status = :status WHERE id = :id');
            $update->execute([
                ':status' => $targetStatus,
                ':id' => $reservationId,
            ]);

            $db->commit();

            return [
                'id' => (int) $row['id'],
                'job_number' => (string) $row['job_number'],
                'previous_status' => $currentStatus,
                'new_status' => $targetStatus,
                'warnings' => $warnings,
                'insufficient_items' => $insufficient,
            ];
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }
}

if (!function_exists('reservationComplete')) {
    /**
     * Mark a reservation as fulfilled while reconciling consumed inventory quantities.
     *
     * @param array<int,int|string> $actualQuantities keyed by inventory item identifier
     *
     * @return array{
     *   reservation_id:int,
     *   job_number:string,
     *   consumed:int,
     *   released:int,
     *   items:list<array{
     *     inventory_item_id:int,
     *     consumed:int,
     *     consumed_delta:int,
     *     released:int,
     *     item:string,
     *     sku:?string,
     *     part_number:string,
     *     finish:?string
     *   }>
     * }
     */
    function reservationComplete(\PDO $db, int $reservationId, array $actualQuantities): array
    {
        ensureInventorySchema($db);

        if (!inventorySupportsReservations($db)) {
            throw new \RuntimeException('Job reservations are not supported by this database.');
        }

        $normalized = [];
        foreach ($actualQuantities as $key => $value) {
            $itemId = (int) $key;
            if ($itemId <= 0) {
                continue;
            }

            $normalized[$itemId] = max(0, (int) $value);
        }

        $db->beginTransaction();

        try {
            $reservationStatement = $db->prepare(
                'SELECT id, job_number, status FROM job_reservations WHERE id = :id FOR UPDATE'
            );
            $reservationStatement->execute([':id' => $reservationId]);
            $reservation = $reservationStatement->fetch(\PDO::FETCH_ASSOC);

            if ($reservation === false) {
                throw new \RuntimeException('Reservation not found.');
            }

            $status = (string) $reservation['status'];
            if ($status !== 'in_progress') {
                throw new \RuntimeException('Jobs must be in process before they can be completed.');
            }

            $itemsStatement = $db->prepare(
                'SELECT id, inventory_item_id, requested_qty, committed_qty, consumed_qty '
                . 'FROM job_reservation_items WHERE reservation_id = :id'
            );
            $itemsStatement->execute([':id' => $reservationId]);

            /** @var list<array<string,mixed>> $reservationItems */
            $reservationItems = $itemsStatement->fetchAll(\PDO::FETCH_ASSOC);

            if ($reservationItems === []) {
                throw new \RuntimeException('This reservation does not contain any committed inventory.');
            }

            $selectInventory = $db->prepare(
                'SELECT item, sku, part_number, finish, stock, committed_qty FROM inventory_items WHERE id = :id FOR UPDATE'
            );
            $updateInventory = $db->prepare(
                'UPDATE inventory_items SET stock = stock - :consume, committed_qty = committed_qty - :release WHERE id = :id'
            );
            $updateReservationItem = $db->prepare(
                'UPDATE job_reservation_items SET committed_qty = 0, consumed_qty = :consumed WHERE id = :id'
            );

            $summaryItems = [];
            $totalConsumed = 0;
            $totalReleased = 0;

            foreach ($reservationItems as $itemRow) {
                $reservationItemId = (int) $itemRow['id'];
                $inventoryItemId = (int) $itemRow['inventory_item_id'];
                $committedQty = max(0, (int) $itemRow['committed_qty']);
                $alreadyConsumed = max(0, (int) $itemRow['consumed_qty']);

                $targetConsumed = $normalized[$inventoryItemId] ?? $committedQty + $alreadyConsumed;

                if ($targetConsumed < $alreadyConsumed) {
                    throw new \RuntimeException('Actual quantities cannot be less than what was previously consumed.');
                }

                $maxAllowed = $committedQty + $alreadyConsumed;
                if ($targetConsumed > $maxAllowed) {
                    throw new \RuntimeException('Cannot consume more than what was reserved for an item.');
                }

                $selectInventory->execute([':id' => $inventoryItemId]);
                $inventoryRow = $selectInventory->fetch(\PDO::FETCH_ASSOC);

                if ($inventoryRow === false) {
                    throw new \RuntimeException('Unable to load inventory item #' . $inventoryItemId . ' for completion.');
                }

                $consumeDelta = $targetConsumed - $alreadyConsumed;
                $releaseQty = $committedQty;

                if ($releaseQty > (int) $inventoryRow['committed_qty']) {
                    throw new \RuntimeException('Inventory commitments are out of sync for item #' . $inventoryItemId . '.');
                }

                if ($consumeDelta > 0) {
                    $updateInventory->execute([
                        ':consume' => $consumeDelta,
                        ':release' => $releaseQty,
                        ':id' => $inventoryItemId,
                    ]);
                } else {
                    $updateInventory->execute([
                        ':consume' => 0,
                        ':release' => $releaseQty,
                        ':id' => $inventoryItemId,
                    ]);
                }

                $updateReservationItem->execute([
                    ':consumed' => $targetConsumed,
                    ':id' => $reservationItemId,
                ]);

                $released = max($releaseQty - $consumeDelta, 0);

                $summaryItems[] = [
                    'inventory_item_id' => $inventoryItemId,
                    'consumed' => $targetConsumed,
                    'consumed_delta' => $consumeDelta,
                    'released' => $released,
                    'item' => (string) $inventoryRow['item'],
                    'sku' => isset($inventoryRow['sku']) ? (string) $inventoryRow['sku'] : null,
                    'part_number' => (string) $inventoryRow['part_number'],
                    'finish' => isset($inventoryRow['finish']) ? (string) $inventoryRow['finish'] : null,
                ];

                $totalConsumed += $consumeDelta;
                $totalReleased += $released;
            }

            $db->prepare('UPDATE job_reservations SET status = :status WHERE id = :id')->execute([
                ':status' => 'fulfilled',
                ':id' => $reservationId,
            ]);

            $db->commit();

            return [
                'reservation_id' => (int) $reservation['id'],
                'job_number' => (string) $reservation['job_number'],
                'consumed' => $totalConsumed,
                'released' => $totalReleased,
                'items' => $summaryItems,
            ];
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }
}

if (!function_exists('reservationCommitItems')) {
    /**
     * @param array{job_number:string,job_name:string,requested_by:string,needed_by?:?string,notes?:?string} $jobMetadata
     * @param list<array{inventory_item_id:int,requested_qty:int,commit_qty:int,part_number:string,finish:?string,sku:?string}> $lineItems
     *
     * @return array{
     *   reservation_id:int,
     *   job_number:string,
     *   job_name:string,
     *   items:list<array{
     *     inventory_item_id:int,
     *     requested_qty:int,
     *     committed_qty:int,
     *     available_before:int,
     *     available_after:int,
     *     item:string,
     *     sku:string,
     *     part_number:string,
     *     finish:?string
     *   }>
     * }
     */
    function reservationCommitItems(\PDO $db, array $jobMetadata, array $lineItems): array
    {
        ensureInventorySchema($db);

        if (!inventorySupportsReservations($db)) {
            throw new \RuntimeException('Job reservations are not supported by this database.');
        }

        if ($lineItems === []) {
            throw new \InvalidArgumentException('At least one inventory line item must be provided.');
        }

        $jobNumber = trim((string) ($jobMetadata['job_number'] ?? ''));
        $jobName = trim((string) ($jobMetadata['job_name'] ?? ''));
        $requestedBy = trim((string) ($jobMetadata['requested_by'] ?? ''));
        $notes = isset($jobMetadata['notes']) ? trim((string) $jobMetadata['notes']) : '';
        $neededByRaw = isset($jobMetadata['needed_by']) ? trim((string) $jobMetadata['needed_by']) : '';
        $neededBy = null;

        if ($jobNumber === '' || $jobName === '' || $requestedBy === '') {
            throw new \InvalidArgumentException('Job number, job name, and requester are required.');
        }

        if ($neededByRaw !== '') {
            $neededDate = \DateTimeImmutable::createFromFormat('Y-m-d', $neededByRaw);
            if ($neededDate === false) {
                throw new \InvalidArgumentException('Provide a valid "Needed by" date in YYYY-MM-DD format.');
            }
            $neededBy = $neededDate->format('Y-m-d');
        }

        $db->beginTransaction();

        try {
            $reservationStatement = $db->prepare(
                'INSERT INTO job_reservations (job_number, job_name, requested_by, needed_by, status, notes) '
                . 'VALUES (:job_number, :job_name, :requested_by, :needed_by, :status, :notes) '
                . 'ON CONFLICT (job_number) DO UPDATE SET '
                . 'job_name = EXCLUDED.job_name, '
                . 'requested_by = EXCLUDED.requested_by, '
                . 'needed_by = EXCLUDED.needed_by, '
                . 'notes = EXCLUDED.notes, '
                . 'status = EXCLUDED.status '
                . 'RETURNING id'
            );

            $reservationStatement->execute([
                ':job_number' => $jobNumber,
                ':job_name' => $jobName,
                ':requested_by' => $requestedBy,
                ':needed_by' => $neededBy,
                ':status' => 'committed',
                ':notes' => $notes !== '' ? $notes : null,
            ]);

            $reservationId = (int) $reservationStatement->fetchColumn();

            if ($reservationId <= 0) {
                throw new \RuntimeException('Failed to create the job reservation.');
            }

            $selectInventory = $db->prepare(
                'SELECT id, item, sku, part_number, finish, stock, committed_qty '
                . 'FROM inventory_items WHERE id = :id FOR UPDATE'
            );
            $updateInventory = $db->prepare(
                'UPDATE inventory_items SET committed_qty = committed_qty + :commit_qty WHERE id = :id'
            );
            $insertReservationItem = $db->prepare(
                'INSERT INTO job_reservation_items (reservation_id, inventory_item_id, requested_qty, committed_qty, consumed_qty) '
                . 'VALUES (:reservation_id, :inventory_item_id, :requested_qty, :committed_qty, 0) '
                . 'ON CONFLICT (reservation_id, inventory_item_id) DO UPDATE SET '
                . 'requested_qty = job_reservation_items.requested_qty + EXCLUDED.requested_qty, '
                . 'committed_qty = job_reservation_items.committed_qty + EXCLUDED.committed_qty '
                . 'RETURNING requested_qty, committed_qty'
            );

            $committedItems = [];

            foreach ($lineItems as $line) {
                $itemId = (int) $line['inventory_item_id'];
                $requestedQty = max(0, (int) $line['requested_qty']);
                $commitQty = max(0, (int) $line['commit_qty']);

                if ($itemId <= 0 || $commitQty === 0) {
                    continue;
                }

                $selectInventory->execute([':id' => $itemId]);
                $inventoryRow = $selectInventory->fetch(\PDO::FETCH_ASSOC);

                if ($inventoryRow === false) {
                    throw new \RuntimeException('Unable to load inventory item #' . $itemId . ' for reservation.');
                }

                $availableBefore = (int) $inventoryRow['stock'] - (int) $inventoryRow['committed_qty'];

                $updateInventory->execute([
                    ':commit_qty' => $commitQty,
                    ':id' => $itemId,
                ]);

                $insertReservationItem->execute([
                    ':reservation_id' => $reservationId,
                    ':inventory_item_id' => $itemId,
                    ':requested_qty' => $requestedQty,
                    ':committed_qty' => $commitQty,
                ]);

                $committedItems[] = [
                    'inventory_item_id' => $itemId,
                    'requested_qty' => $requestedQty,
                    'committed_qty' => $commitQty,
                    'available_before' => $availableBefore,
                    'available_after' => $availableBefore - $commitQty,
                    'item' => (string) $inventoryRow['item'],
                    'sku' => (string) $inventoryRow['sku'],
                    'part_number' => (string) $inventoryRow['part_number'],
                    'finish' => $inventoryRow['finish'] !== null ? (string) $inventoryRow['finish'] : null,
                ];
            }

            if ($committedItems === []) {
                throw new \InvalidArgumentException('No valid reservation items were provided.');
            }

            $db->commit();

            return [
                'reservation_id' => $reservationId,
                'job_number' => $jobNumber,
                'job_name' => $jobName,
                'items' => $committedItems,
            ];
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }
}
