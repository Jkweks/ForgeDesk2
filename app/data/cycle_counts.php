<?php

declare(strict_types=1);

require_once __DIR__ . '/inventory.php';

if (!function_exists('ensureCycleCountSchema')) {
    function ensureCycleCountSchema(\PDO $db): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $db->exec(
            'CREATE TABLE IF NOT EXISTS cycle_count_sessions (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'in_progress\',
                started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL,
                location_filter TEXT NULL,
                total_lines INTEGER NOT NULL DEFAULT 0,
                completed_lines INTEGER NOT NULL DEFAULT 0
            )'
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS cycle_count_lines (
                id SERIAL PRIMARY KEY,
                session_id INTEGER NOT NULL REFERENCES cycle_count_sessions(id) ON DELETE CASCADE,
                inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
                sequence INTEGER NOT NULL,
                expected_qty INTEGER NOT NULL DEFAULT 0,
                counted_qty INTEGER NULL,
                variance INTEGER NULL,
                counted_at TIMESTAMP NULL,
                note TEXT NULL,
                UNIQUE(session_id, sequence)
            )'
        );

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_cycle_count_lines_session_sequence
             ON cycle_count_lines (session_id, sequence)'
        );

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_cycle_count_lines_inventory
             ON cycle_count_lines (inventory_item_id)'
        );

        $ensured = true;
    }

    /**
     * Start a new cycle count session and seed line items for each inventory record.
     *
     * @param array{name?:string,location?:string|null} $filters
     */
    function createCycleCountSession(\PDO $db, array $filters): int
    {
        ensureCycleCountSchema($db);
        ensureInventorySchema($db);

        $name = trim($filters['name'] ?? '');
        if ($name === '') {
            $name = 'Cycle Count ' . date('Y-m-d H:i');
        }

        $locationFilter = null;
        if (array_key_exists('location', $filters)) {
            $locationCandidate = trim((string) $filters['location']);
            if ($locationCandidate !== '') {
                $locationFilter = $locationCandidate;
            }
        }

        $inventory = loadInventory($db);

        if ($locationFilter !== null) {
            $inventory = array_values(array_filter(
                $inventory,
                static fn (array $item): bool => stripos($item['location'], $locationFilter) !== false
            ));
        }

        usort(
            $inventory,
            static function (array $a, array $b): int {
                $locationComparison = strcasecmp($a['location'], $b['location']);
                if ($locationComparison !== 0) {
                    return $locationComparison;
                }

                $skuComparison = strcasecmp($a['sku'], $b['sku']);
                if ($skuComparison !== 0) {
                    return $skuComparison;
                }

                return strcasecmp($a['item'], $b['item']);
            }
        );

        $totalLines = count($inventory);

        $db->beginTransaction();

        try {
            $statement = $db->prepare(
                'INSERT INTO cycle_count_sessions (name, status, started_at, location_filter, total_lines, completed_lines)
                 VALUES (:name, :status, CURRENT_TIMESTAMP, :location_filter, :total_lines, 0)
                 RETURNING id'
            );

            $statement->execute([
                ':name' => $name,
                ':status' => $totalLines === 0 ? 'completed' : 'in_progress',
                ':location_filter' => $locationFilter,
                ':total_lines' => $totalLines,
            ]);

            $sessionId = (int) $statement->fetchColumn();

            if ($totalLines > 0) {
                $lineInsert = $db->prepare(
                    'INSERT INTO cycle_count_lines (session_id, inventory_item_id, sequence, expected_qty)
                     VALUES (:session_id, :inventory_item_id, :sequence, :expected_qty)'
                );

                $sequence = 1;
                foreach ($inventory as $item) {
                    $lineInsert->execute([
                        ':session_id' => $sessionId,
                        ':inventory_item_id' => $item['id'],
                        ':sequence' => $sequence,
                        ':expected_qty' => $item['stock'],
                    ]);

                    $sequence++;
                }
            } else {
                $db->exec(
                    'UPDATE cycle_count_sessions
                     SET completed_at = CURRENT_TIMESTAMP, completed_lines = 0
                     WHERE id = ' . $sessionId
                );
            }

            $db->commit();

            return $sessionId;
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    /**
     * List all cycle count sessions.
     *
     * @return array<int, array{id:int,name:string,status:string,started_at:string,completed_at:?string,location_filter:?string,total_lines:int,completed_lines:int}>
     */
    function loadCycleCountSessions(\PDO $db): array
    {
        ensureCycleCountSchema($db);

        $statement = $db->query(
            'SELECT id, name, status, started_at, completed_at, location_filter, total_lines, completed_lines
             FROM cycle_count_sessions
             ORDER BY started_at DESC'
        );

        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
                'started_at' => (string) $row['started_at'],
                'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
                'location_filter' => $row['location_filter'] !== null ? (string) $row['location_filter'] : null,
                'total_lines' => (int) $row['total_lines'],
                'completed_lines' => (int) $row['completed_lines'],
            ],
            $rows
        );
    }

    /**
     * Load a specific session line based on its sequence position.
     *
     * @return array{session:array{id:int,name:string,status:string,total_lines:int,completed_lines:int},line:array{id:int,sequence:int,expected_qty:int,counted_qty:?int,variance:?int,counted_at:?string,note:?string},item:array{id:int,item:string,sku:string,location:string}}
     */
    function loadCycleCountLine(\PDO $db, int $sessionId, int $position): ?array
    {
        ensureCycleCountSchema($db);

        $statement = $db->prepare(
            'SELECT
                s.id AS session_id,
                s.name AS session_name,
                s.status AS session_status,
                s.total_lines,
                s.completed_lines,
                l.id AS line_id,
                l.sequence,
                l.expected_qty,
                l.counted_qty,
                l.variance,
                l.counted_at,
                l.note,
                i.id AS item_id,
                i.item,
                i.sku,
                i.location
             FROM cycle_count_lines l
             INNER JOIN cycle_count_sessions s ON s.id = l.session_id
             INNER JOIN inventory_items i ON i.id = l.inventory_item_id
             WHERE l.session_id = :session_id AND l.sequence = :sequence
             LIMIT 1'
        );

        $statement->execute([
            ':session_id' => $sessionId,
            ':sequence' => $position,
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'session' => [
                'id' => (int) $row['session_id'],
                'name' => (string) $row['session_name'],
                'status' => (string) $row['session_status'],
                'total_lines' => (int) $row['total_lines'],
                'completed_lines' => (int) $row['completed_lines'],
            ],
            'line' => [
                'id' => (int) $row['line_id'],
                'sequence' => (int) $row['sequence'],
                'expected_qty' => (int) $row['expected_qty'],
                'counted_qty' => $row['counted_qty'] !== null ? (int) $row['counted_qty'] : null,
                'variance' => $row['variance'] !== null ? (int) $row['variance'] : null,
                'counted_at' => $row['counted_at'] !== null ? (string) $row['counted_at'] : null,
                'note' => $row['note'] !== null ? (string) $row['note'] : null,
            ],
            'item' => [
                'id' => (int) $row['item_id'],
                'item' => (string) $row['item'],
                'sku' => (string) $row['sku'],
                'location' => (string) $row['location'],
            ],
        ];
    }

    /**
     * Record the counted quantity for a cycle count line.
     */
    function recordCycleCount(\PDO $db, int $lineId, int $countedQty, ?string $note = null): void
    {
        ensureCycleCountSchema($db);

        $statement = $db->prepare(
            'SELECT l.session_id, l.inventory_item_id, l.expected_qty, s.status
             FROM cycle_count_lines l
             INNER JOIN cycle_count_sessions s ON s.id = l.session_id
             WHERE l.id = :line_id'
        );
        $statement->execute([':line_id' => $lineId]);

        $details = $statement->fetch();

        if ($details === false) {
            throw new \RuntimeException('Cycle count line not found.');
        }

        if ($details['status'] !== 'in_progress') {
            return;
        }

        $variance = $countedQty - (int) $details['expected_qty'];

        $db->beginTransaction();

        try {
            $updateLine = $db->prepare(
                'UPDATE cycle_count_lines
                 SET counted_qty = :counted_qty,
                     variance = :variance,
                     counted_at = CURRENT_TIMESTAMP,
                     note = :note
                 WHERE id = :line_id'
            );
            $updateLine->execute([
                ':counted_qty' => $countedQty,
                ':variance' => $variance,
                ':note' => $note,
                ':line_id' => $lineId,
            ]);

            $updateItem = $db->prepare(
                'UPDATE inventory_items SET stock = :stock WHERE id = :item_id'
            );
            $updateItem->execute([
                ':stock' => $countedQty,
                ':item_id' => (int) $details['inventory_item_id'],
            ]);

            $sessionUpdate = $db->prepare(
                'UPDATE cycle_count_sessions
                 SET completed_lines = (
                    SELECT COUNT(*) FROM cycle_count_lines
                    WHERE session_id = :session_id AND counted_qty IS NOT NULL
                 ),
                     status = CASE
                         WHEN total_lines > 0 AND (
                             SELECT COUNT(*) FROM cycle_count_lines
                             WHERE session_id = :session_id AND counted_qty IS NOT NULL
                         ) >= total_lines THEN \'completed\'
                         ELSE status
                     end,
                     completed_at = CASE
                         WHEN total_lines > 0 AND (
                             SELECT COUNT(*) FROM cycle_count_lines
                             WHERE session_id = :session_id AND counted_qty IS NOT NULL
                         ) >= total_lines THEN COALESCE(completed_at, CURRENT_TIMESTAMP)
                         ELSE completed_at
                     END
                 WHERE id = :session_id'
            );
            $sessionUpdate->execute([':session_id' => (int) $details['session_id']]);

            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    /**
     * Close a cycle count session, applying expected quantities for any remaining lines.
     */
    function completeCycleCountSession(\PDO $db, int $sessionId): void
    {
        ensureCycleCountSchema($db);

        $db->beginTransaction();

        try {
            $lines = $db->prepare(
                'SELECT id, inventory_item_id, expected_qty
                 FROM cycle_count_lines
                 WHERE session_id = :session_id AND counted_qty IS NULL
                 ORDER BY sequence ASC'
            );
            $lines->execute([':session_id' => $sessionId]);

            $updateLine = $db->prepare(
                'UPDATE cycle_count_lines
                 SET counted_qty = :qty,
                     variance = 0,
                     counted_at = CURRENT_TIMESTAMP
                 WHERE id = :line_id'
            );

            $updateItem = $db->prepare(
                'UPDATE inventory_items SET stock = :qty WHERE id = :item_id'
            );

            while ($row = $lines->fetch()) {
                $qty = (int) $row['expected_qty'];
                $updateLine->execute([
                    ':qty' => $qty,
                    ':line_id' => (int) $row['id'],
                ]);
                $updateItem->execute([
                    ':qty' => $qty,
                    ':item_id' => (int) $row['inventory_item_id'],
                ]);
            }

            $sessionFinalize = $db->prepare(
                'UPDATE cycle_count_sessions
                 SET status = \'completed\',
                     completed_lines = total_lines,
                     completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP)
                 WHERE id = :session_id'
            );
            $sessionFinalize->execute([':session_id' => $sessionId]);

            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }
}
