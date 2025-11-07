<?php

declare(strict_types=1);

require_once __DIR__ . '/../data/inventory.php';

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

                $availableBefore = max((int) $inventoryRow['stock'] - (int) $inventoryRow['committed_qty'], 0);

                if ($commitQty > $availableBefore) {
                    throw new \RuntimeException(sprintf(
                        'Only %d unit(s) remain available for SKU %s.',
                        $availableBefore,
                        (string) $inventoryRow['sku']
                    ));
                }

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
                    'available_after' => max($availableBefore - $commitQty, 0),
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
