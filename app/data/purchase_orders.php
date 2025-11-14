<?php

declare(strict_types=1);

if (!function_exists('purchaseOrderEnsureSchema')) {
    /**
     * @return list<string>
     */
    function purchaseOrderStatusList(): array
    {
        return ['draft', 'sent', 'partially_received', 'closed', 'cancelled'];
    }

    /**
     * Statuses that still contribute to on-order quantities.
     *
     * @return list<string>
     */
    function purchaseOrderOpenStatuses(): array
    {
        return ['draft', 'sent', 'partially_received'];
    }

    function purchaseOrderEnsureSchema(\PDO $db): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $db->exec(
            'CREATE TABLE IF NOT EXISTS suppliers (
                id BIGSERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                contact_name TEXT,
                contact_email TEXT,
                contact_phone TEXT,
                default_lead_time_days INTEGER DEFAULT 0,
                notes TEXT,
                created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )'
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_orders (
                id BIGSERIAL PRIMARY KEY,
                order_number TEXT UNIQUE,
                supplier_id BIGINT REFERENCES suppliers(id) ON UPDATE CASCADE ON DELETE SET NULL,
                status TEXT NOT NULL DEFAULT 'draft',
                order_date DATE DEFAULT CURRENT_DATE,
                expected_date DATE,
                total_cost NUMERIC(18, 6) DEFAULT 0,
                notes TEXT,
                created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft', 'sent', 'partially_received', 'closed', 'cancelled'))
            )"
        );

        $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_orders_supplier_id ON purchase_orders(supplier_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_orders_status ON purchase_orders(status)');

        $db->exec(
            'CREATE TABLE IF NOT EXISTS purchase_order_lines (
                id BIGSERIAL PRIMARY KEY,
                purchase_order_id BIGINT REFERENCES purchase_orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
                inventory_item_id BIGINT REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE SET NULL,
                supplier_sku TEXT,
                description TEXT,
                quantity_ordered NUMERIC(18, 6) NOT NULL DEFAULT 0,
                quantity_received NUMERIC(18, 6) NOT NULL DEFAULT 0,
                quantity_cancelled NUMERIC(18, 6) NOT NULL DEFAULT 0,
                unit_cost NUMERIC(18, 6) NOT NULL DEFAULT 0,
                expected_date DATE,
                created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )'
        );

        $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_po_id ON purchase_order_lines(purchase_order_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_inventory_item_id ON purchase_order_lines(inventory_item_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_outstanding ON purchase_order_lines(purchase_order_id, inventory_item_id)');

        $db->exec('ALTER TABLE purchase_order_lines ADD COLUMN IF NOT EXISTS quantity_cancelled NUMERIC(18, 6) NOT NULL DEFAULT 0');

        $db->exec(
            'CREATE TABLE IF NOT EXISTS purchase_order_receipts (
                id BIGSERIAL PRIMARY KEY,
                purchase_order_id BIGINT NOT NULL REFERENCES purchase_orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
                inventory_transaction_id INTEGER REFERENCES inventory_transactions(id) ON UPDATE CASCADE ON DELETE SET NULL,
                reference TEXT NOT NULL,
                notes TEXT,
                created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )'
        );

        $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_order_receipts_po_id ON purchase_order_receipts(purchase_order_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_order_receipts_created_at ON purchase_order_receipts(created_at DESC)');

        $db->exec(
            'CREATE TABLE IF NOT EXISTS purchase_order_receipt_lines (
                id BIGSERIAL PRIMARY KEY,
                receipt_id BIGINT NOT NULL REFERENCES purchase_order_receipts(id) ON UPDATE CASCADE ON DELETE CASCADE,
                purchase_order_line_id BIGINT NOT NULL REFERENCES purchase_order_lines(id) ON UPDATE CASCADE ON DELETE CASCADE,
                quantity_received NUMERIC(18, 6) NOT NULL DEFAULT 0,
                quantity_cancelled NUMERIC(18, 6) NOT NULL DEFAULT 0
            )'
        );

        $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_order_receipt_lines_receipt ON purchase_order_receipt_lines(receipt_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_order_receipt_lines_line ON purchase_order_receipt_lines(purchase_order_line_id)');

        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM information_schema.table_constraints tc
                    WHERE tc.constraint_type = 'FOREIGN KEY'
                      AND tc.table_schema = current_schema()
                      AND tc.table_name = 'inventory_items'
                      AND tc.constraint_name = 'inventory_items_supplier_id_fkey'
                ) THEN
                    ALTER TABLE inventory_items
                        ADD CONSTRAINT inventory_items_supplier_id_fkey
                        FOREIGN KEY (supplier_id)
                        REFERENCES suppliers(id)
                        ON UPDATE CASCADE
                        ON DELETE SET NULL;
                END IF;
            END;
            $$;"
        );

        $ensured = true;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,float>
     */
    function purchaseOrderOutstandingQuantities(\PDO $db, array $itemIds = []): array
    {
        purchaseOrderEnsureSchema($db);

        $params = [];
        $where = '';

        if ($itemIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($itemIds), '?'));
            $where = 'AND pol.inventory_item_id IN (' . $placeholders . ')';
            $params = array_map('intval', $itemIds);
        }

        $statement = $db->prepare(
            'SELECT pol.inventory_item_id, SUM(GREATEST(pol.quantity_ordered - pol.quantity_received - COALESCE(pol.quantity_cancelled, 0), 0)) AS outstanding
             FROM purchase_order_lines pol
             JOIN purchase_orders po ON po.id = pol.purchase_order_id
             WHERE po.status IN (' . implode(', ', array_map(static fn (string $status): string => $db->quote($status), purchaseOrderOpenStatuses())) . ')
             ' . $where . '
             GROUP BY pol.inventory_item_id'
        );

        $statement->execute($params);

        /** @var array<int,array{inventory_item_id:?string,outstanding:?string}> $rows */
        $rows = $statement->fetchAll();
        $totals = [];

        foreach ($rows as $row) {
            $itemId = $row['inventory_item_id'] !== null ? (int) $row['inventory_item_id'] : null;
            if ($itemId === null) {
                continue;
            }

            $value = $row['outstanding'];
            $totals[$itemId] = $value !== null ? (float) $value : 0.0;
        }

        return $totals;
    }

    /**
     * @param list<int> $itemIds
     */
    function purchaseOrderUpdateOnOrderCache(\PDO $db, array $itemIds = []): void
    {
        if (!function_exists('ensureInventorySchema')) {
            require_once __DIR__ . '/inventory.php';
        }

        ensureInventorySchema($db);
        purchaseOrderEnsureSchema($db);

        $ids = $itemIds;
        if ($ids === []) {
            $query = $db->query('SELECT DISTINCT inventory_item_id FROM purchase_order_lines WHERE inventory_item_id IS NOT NULL');
            $ids = $query !== false ? array_map('intval', array_filter($query->fetchAll(\PDO::FETCH_COLUMN), static fn ($value) => $value !== null)) : [];
        }

        if ($ids === []) {
            return;
        }

        $totals = purchaseOrderOutstandingQuantities($db, $ids);

        $update = $db->prepare('UPDATE inventory_items SET on_order_qty = :on_order WHERE id = :id');

        foreach ($ids as $itemId) {
            $onOrder = $totals[$itemId] ?? 0.0;
            $update->execute([
                ':id' => $itemId,
                ':on_order' => $onOrder,
            ]);
        }
    }

    /**
     * @param array{
     *   order_number?:?string,
     *   supplier_id?:?int,
     *   status?:?string,
     *   order_date?:?string,
     *   expected_date?:?string,
     *   notes?:?string,
     *   lines:list<array{
     *     inventory_item_id:?int,
     *     supplier_sku?:?string,
     *     description:string,
     *     quantity_ordered:float,
     *     unit_cost:float,
     *     expected_date?:?string,
     *     quantity_cancelled?:?float
     *   }>
     * } $payload
     */
    function createPurchaseOrder(\PDO $db, array $payload): int
    {
        if (!function_exists('ensureInventorySchema')) {
            require_once __DIR__ . '/inventory.php';
        }

        ensureInventorySchema($db);
        purchaseOrderEnsureSchema($db);

        if (empty($payload['lines'])) {
            throw new \InvalidArgumentException('Purchase orders require at least one line item.');
        }

        $status = $payload['status'] ?? 'draft';
        if (!in_array($status, purchaseOrderStatusList(), true)) {
            throw new \InvalidArgumentException('Invalid purchase order status: ' . $status);
        }

        $affectedItems = [];

        try {
            $db->beginTransaction();

            $statement = $db->prepare(
                'INSERT INTO purchase_orders (order_number, supplier_id, status, order_date, expected_date, notes, total_cost)
                 VALUES (:order_number, :supplier_id, :status, :order_date, :expected_date, :notes, 0)
                 RETURNING id'
            );

            $statement->execute([
                ':order_number' => $payload['order_number'] ?? null,
                ':supplier_id' => $payload['supplier_id'] ?? null,
                ':status' => $status,
                ':order_date' => $payload['order_date'] ?? null,
                ':expected_date' => $payload['expected_date'] ?? null,
                ':notes' => $payload['notes'] ?? null,
            ]);

            $orderId = (int) $statement->fetchColumn();

            $lineStatement = $db->prepare(
                'INSERT INTO purchase_order_lines (purchase_order_id, inventory_item_id, supplier_sku, description, quantity_ordered, quantity_cancelled, unit_cost, expected_date)
                 VALUES (:purchase_order_id, :inventory_item_id, :supplier_sku, :description, :quantity_ordered, :quantity_cancelled, :unit_cost, :expected_date)'
            );

            $totalCost = 0.0;

            foreach ($payload['lines'] as $line) {
                $quantity = (float) $line['quantity_ordered'];
                $unitCost = (float) $line['unit_cost'];

                $lineStatement->execute([
                    ':purchase_order_id' => $orderId,
                    ':inventory_item_id' => $line['inventory_item_id'] ?? null,
                    ':supplier_sku' => $line['supplier_sku'] ?? null,
                    ':description' => $line['description'],
                    ':quantity_ordered' => $quantity,
                    ':quantity_cancelled' => isset($line['quantity_cancelled']) ? max(0.0, (float) $line['quantity_cancelled']) : 0.0,
                    ':unit_cost' => $unitCost,
                    ':expected_date' => $line['expected_date'] ?? null,
                ]);

                if (isset($line['inventory_item_id'])) {
                    $affectedItems[(int) $line['inventory_item_id']] = true;
                }

                $totalCost += $quantity * $unitCost;
            }

            $updateTotal = $db->prepare('UPDATE purchase_orders SET total_cost = :total_cost WHERE id = :id');
            $updateTotal->execute([
                ':id' => $orderId,
                ':total_cost' => $totalCost,
            ]);

            $db->commit();

            purchaseOrderUpdateOnOrderCache($db, array_keys($affectedItems));

            return $orderId;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array{
     *   order_number?:?string,
     *   supplier_id?:?int,
     *   status?:?string,
     *   order_date?:?string,
     *   expected_date?:?string,
     *   notes?:?string,
     *   lines?:list<array{
     *     id?:?int,
     *     inventory_item_id:?int,
     *     supplier_sku?:?string,
     *     description:string,
     *     quantity_ordered:float,
     *     unit_cost:float,
     *     expected_date?:?string,
     *     quantity_cancelled?:?float
     *   }>
     * } $payload
     */
    function updatePurchaseOrder(\PDO $db, int $purchaseOrderId, array $payload): void
    {
        purchaseOrderEnsureSchema($db);

        $allowedStatuses = purchaseOrderStatusList();
        $fields = [];
        $params = [':id' => $purchaseOrderId];

        $map = [
            'order_number' => ':order_number',
            'supplier_id' => ':supplier_id',
            'status' => ':status',
            'order_date' => ':order_date',
            'expected_date' => ':expected_date',
            'notes' => ':notes',
        ];

        foreach ($map as $key => $placeholder) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if ($key === 'status' && $value !== null && !in_array((string) $value, $allowedStatuses, true)) {
                throw new \InvalidArgumentException('Invalid purchase order status: ' . $value);
            }

            $fields[] = $key . ' = ' . $placeholder;
            $params[$placeholder] = $value;
        }

        $affectedItems = [];

        try {
            $db->beginTransaction();

            if ($fields !== []) {
                $db->prepare('UPDATE purchase_orders SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id')->execute($params);
            }

            if (isset($payload['lines'])) {
                $lines = $payload['lines'];

                $existingStatement = $db->prepare('SELECT id, inventory_item_id FROM purchase_order_lines WHERE purchase_order_id = :id');
                $existingStatement->execute([':id' => $purchaseOrderId]);

                /** @var array<int,array{id:int,inventory_item_id:?int}> $existingLines */
                $existingLines = $existingStatement->fetchAll();
                $existingIds = [];

                foreach ($existingLines as $line) {
                    $existingIds[$line['id']] = $line['inventory_item_id'] !== null ? (int) $line['inventory_item_id'] : null;
                }

                $lineUpdate = $db->prepare(
                    'UPDATE purchase_order_lines
                     SET inventory_item_id = :inventory_item_id,
                         supplier_sku = :supplier_sku,
                         description = :description,
                         quantity_ordered = :quantity_ordered,
                         quantity_cancelled = :quantity_cancelled,
                         unit_cost = :unit_cost,
                         expected_date = :expected_date,
                         updated_at = NOW()
                     WHERE id = :id AND purchase_order_id = :purchase_order_id'
                );

                $lineInsert = $db->prepare(
                    'INSERT INTO purchase_order_lines (purchase_order_id, inventory_item_id, supplier_sku, description, quantity_ordered, quantity_cancelled, unit_cost, expected_date)
                     VALUES (:purchase_order_id, :inventory_item_id, :supplier_sku, :description, :quantity_ordered, :quantity_cancelled, :unit_cost, :expected_date)'
                );

                $seenIds = [];
                $totalCost = 0.0;

                foreach ($lines as $line) {
                    $quantity = (float) $line['quantity_ordered'];
                    $unitCost = (float) $line['unit_cost'];
                    $inventoryItemId = $line['inventory_item_id'] ?? null;

                    if (isset($line['id']) && $line['id'] !== null && isset($existingIds[(int) $line['id']])) {
                        $lineId = (int) $line['id'];
                        $seenIds[] = $lineId;

                        $previousItemId = $existingIds[$lineId];
                        if ($previousItemId !== null) {
                            $affectedItems[$previousItemId] = true;
                        }

                        $lineUpdate->execute([
                            ':id' => $lineId,
                            ':purchase_order_id' => $purchaseOrderId,
                            ':inventory_item_id' => $inventoryItemId,
                            ':supplier_sku' => $line['supplier_sku'] ?? null,
                            ':description' => $line['description'],
                            ':quantity_ordered' => $quantity,
                            ':quantity_cancelled' => isset($line['quantity_cancelled']) ? max(0.0, (float) $line['quantity_cancelled']) : 0.0,
                            ':unit_cost' => $unitCost,
                            ':expected_date' => $line['expected_date'] ?? null,
                        ]);
                    } else {
                        $lineInsert->execute([
                            ':purchase_order_id' => $purchaseOrderId,
                            ':inventory_item_id' => $inventoryItemId,
                            ':supplier_sku' => $line['supplier_sku'] ?? null,
                            ':description' => $line['description'],
                            ':quantity_ordered' => $quantity,
                            ':quantity_cancelled' => isset($line['quantity_cancelled']) ? max(0.0, (float) $line['quantity_cancelled']) : 0.0,
                            ':unit_cost' => $unitCost,
                            ':expected_date' => $line['expected_date'] ?? null,
                        ]);
                    }

                    if ($inventoryItemId !== null) {
                        $affectedItems[(int) $inventoryItemId] = true;
                    }

                    $totalCost += $quantity * $unitCost;
                }

                $deleteIds = array_diff(array_keys($existingIds), $seenIds);

                if ($deleteIds !== []) {
                    foreach ($deleteIds as $deleteId) {
                        $deletedItemId = $existingIds[$deleteId] ?? null;
                        if ($deletedItemId !== null) {
                            $affectedItems[$deletedItemId] = true;
                        }
                    }

                    $deleteStatement = $db->prepare(
                        'DELETE FROM purchase_order_lines WHERE purchase_order_id = :purchase_order_id AND id = ANY(:ids)'
                    );
                    $deleteStatement->bindValue(':purchase_order_id', $purchaseOrderId, \PDO::PARAM_INT);
                    $deleteStatement->bindValue(':ids', '{' . implode(',', array_map('intval', $deleteIds)) . '}', \PDO::PARAM_STR);
                    $deleteStatement->execute();
                }

                $db->prepare('UPDATE purchase_orders SET total_cost = :total_cost WHERE id = :id')->execute([
                    ':id' => $purchaseOrderId,
                    ':total_cost' => $totalCost,
                ]);
            }

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }

        if ($affectedItems !== []) {
            purchaseOrderUpdateOnOrderCache($db, array_keys($affectedItems));
        }
    }

    /**
     * @param array<int,float|array{receive?:float,cancel?:float}> $receipts Map of line ID to changes for this transaction
     * @return array{
     *   lines:list<array{id:int,received_delta:float,cancelled_delta:float,received_total:float,cancelled_total:float}>,
     *   status:string,
     *   inventory_transaction_id:?int,
     *   receipt_id:?int
     * }
     */
    function recordPurchaseOrderReceipt(\PDO $db, int $purchaseOrderId, array $receipts, ?string $reference = null, ?string $notes = null): array
    {
        if ($receipts === []) {
            return ['lines' => [], 'status' => '', 'inventory_transaction_id' => null, 'receipt_id' => null];
        }

        if (!function_exists('recordInventoryTransaction')) {
            require_once __DIR__ . '/inventory.php';
        }

        ensureInventorySchema($db);
        purchaseOrderEnsureSchema($db);

        $lineStatement = $db->prepare(
            'SELECT pol.id, pol.inventory_item_id, pol.description, pol.quantity_ordered, pol.quantity_received, i.sku, i.item
             FROM purchase_order_lines pol
             LEFT JOIN inventory_items i ON i.id = pol.inventory_item_id
             WHERE pol.purchase_order_id = :purchase_order_id AND pol.id = ANY(:ids)'
        );

        $lineIds = array_map('intval', array_keys($receipts));
        $lineStatement->bindValue(':purchase_order_id', $purchaseOrderId, \PDO::PARAM_INT);
        $lineStatement->bindValue(':ids', '{' . implode(',', $lineIds) . '}', \PDO::PARAM_STR);
        $lineStatement->execute();

        /** @var array<int,array{ id:int, inventory_item_id:?int, description:?string, quantity_ordered:?string, quantity_received:?string, quantity_cancelled:?string, sku:?string, item:?string }> $lines */
        $lines = $lineStatement->fetchAll();

        if ($lines === []) {
            throw new \RuntimeException('No purchase order lines found for receipt.');
        }

        $byId = [];
        foreach ($lines as $line) {
            $byId[$line['id']] = $line;
        }

        $affectedItems = [];
        $inventoryLines = [];
        $updates = [];

        foreach ($receipts as $lineId => $change) {
            $lineId = (int) $lineId;
            if (!isset($byId[$lineId])) {
                continue;
            }

            $line = $byId[$lineId];
            $ordered = $line['quantity_ordered'] !== null ? (float) $line['quantity_ordered'] : 0.0;
            $receivedSoFar = $line['quantity_received'] !== null ? (float) $line['quantity_received'] : 0.0;
            $cancelledSoFar = $line['quantity_cancelled'] !== null ? (float) $line['quantity_cancelled'] : 0.0;
            $incoming = 0.0;
            $cancelling = 0.0;

            if (is_array($change)) {
                if (isset($change['receive'])) {
                    $incoming = max(0.0, (float) $change['receive']);
                }
                if (isset($change['cancel'])) {
                    $cancelling = max(0.0, (float) $change['cancel']);
                }
            } else {
                $incoming = max(0.0, (float) $change);
            }

            $remaining = max(0.0, $ordered - $receivedSoFar - $cancelledSoFar);

            if ($remaining <= 0.0) {
                continue;
            }

            $receiptQty = min($incoming, $remaining);
            $remaining -= $receiptQty;
            $cancelQty = min($cancelling, $remaining);

            if ($receiptQty <= 0.0 && $cancelQty <= 0.0) {
                continue;
            }

            $newReceived = $receivedSoFar + $receiptQty;
            $newCancelled = $cancelledSoFar + $cancelQty;

            $updates[] = [
                'id' => $lineId,
                'quantity_received' => $newReceived,
                'quantity_cancelled' => $newCancelled,
                'received_delta' => $receiptQty,
                'cancelled_delta' => $cancelQty,
                'description' => $line['description'] !== null ? (string) $line['description'] : null,
            ];

            if ($line['inventory_item_id'] !== null) {
                $itemId = (int) $line['inventory_item_id'];
                $affectedItems[$itemId] = true;
                $quantityChange = (int) round($receiptQty);

                if ($quantityChange !== 0) {
                    $inventoryLines[] = [
                        'item_id' => $itemId,
                        'quantity_change' => $quantityChange,
                        'note' => sprintf('PO #%d line %d receipt', $purchaseOrderId, $lineId),
                    ];
                }
            }
        }

        if ($updates === []) {
            return ['lines' => [], 'status' => '', 'inventory_transaction_id' => null, 'receipt_id' => null];
        }

        $transactionId = null;
        $receiptId = null;

        try {
            $db->beginTransaction();

            $updateStatement = $db->prepare(
                'UPDATE purchase_order_lines SET quantity_received = :quantity_received, quantity_cancelled = :quantity_cancelled, updated_at = NOW() WHERE id = :id'
            );

            foreach ($updates as $update) {
                $updateStatement->execute([
                    ':id' => $update['id'],
                    ':quantity_received' => $update['quantity_received'],
                    ':quantity_cancelled' => $update['quantity_cancelled'],
                ]);
            }

            if ($inventoryLines !== []) {
                $transactionId = recordInventoryTransaction($db, [
                    'reference' => $reference ?? sprintf('PO %d receipt', $purchaseOrderId),
                    'notes' => $notes,
                    'lines' => $inventoryLines,
                ]);
            }

            $receiptReference = $reference ?? sprintf('PO %d receipt', $purchaseOrderId);
            $receiptStatement = $db->prepare(
                'INSERT INTO purchase_order_receipts (purchase_order_id, inventory_transaction_id, reference, notes)
                 VALUES (:purchase_order_id, :inventory_transaction_id, :reference, :notes)
                 RETURNING id'
            );
            $receiptStatement->execute([
                ':purchase_order_id' => $purchaseOrderId,
                ':inventory_transaction_id' => $transactionId,
                ':reference' => $receiptReference,
                ':notes' => $notes,
            ]);

            $receiptId = (int) $receiptStatement->fetchColumn();

            $receiptLineStatement = $db->prepare(
                'INSERT INTO purchase_order_receipt_lines (receipt_id, purchase_order_line_id, quantity_received, quantity_cancelled)
                 VALUES (:receipt_id, :purchase_order_line_id, :quantity_received, :quantity_cancelled)'
            );

            foreach ($updates as $update) {
                $deltaReceived = $update['received_delta'] ?? 0.0;
                $deltaCancelled = $update['cancelled_delta'] ?? 0.0;

                if ($deltaReceived <= 0.0 && $deltaCancelled <= 0.0) {
                    continue;
                }

                $receiptLineStatement->execute([
                    ':receipt_id' => $receiptId,
                    ':purchase_order_line_id' => $update['id'],
                    ':quantity_received' => $deltaReceived,
                    ':quantity_cancelled' => $deltaCancelled,
                ]);
            }

            $status = purchaseOrderRecalculateStatus($db, $purchaseOrderId);

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }

        purchaseOrderUpdateOnOrderCache($db, array_keys($affectedItems));

        return [
            'lines' => array_map(
                static function (array $update): array {
                    return [
                        'id' => $update['id'],
                        'received_delta' => $update['received_delta'] ?? 0.0,
                        'cancelled_delta' => $update['cancelled_delta'] ?? 0.0,
                        'received_total' => $update['quantity_received'],
                        'cancelled_total' => $update['quantity_cancelled'],
                    ];
                },
                $updates
            ),
            'status' => $status,
            'inventory_transaction_id' => $transactionId ?? null,
            'receipt_id' => $receiptId ?? null,
        ];
    }

    function purchaseOrderRecalculateStatus(\PDO $db, int $purchaseOrderId): string
    {
        $statement = $db->prepare(
            'SELECT
                SUM(GREATEST(pol.quantity_ordered - pol.quantity_received - COALESCE(pol.quantity_cancelled, 0), 0)) AS outstanding,
                SUM(pol.quantity_received) AS total_received,
                SUM(pol.quantity_ordered) AS total_ordered,
                SUM(pol.quantity_cancelled) AS total_cancelled
             FROM purchase_order_lines pol
             WHERE pol.purchase_order_id = :id'
        );
        $statement->execute([':id' => $purchaseOrderId]);

        /** @var array{outstanding:?string,total_received:?string,total_ordered:?string,total_cancelled:?string}|false $row */
        $row = $statement->fetch();

        $outstanding = $row !== false && $row['outstanding'] !== null ? (float) $row['outstanding'] : 0.0;
        $totalReceived = $row !== false && $row['total_received'] !== null ? (float) $row['total_received'] : 0.0;
        $totalOrdered = $row !== false && $row['total_ordered'] !== null ? (float) $row['total_ordered'] : 0.0;
        $totalCancelled = $row !== false && $row['total_cancelled'] !== null ? (float) $row['total_cancelled'] : 0.0;

        $status = 'draft';

        if ($totalOrdered <= 0.0) {
            $status = 'draft';
        } elseif ($outstanding <= 0.000001) {
            if ($totalReceived > 0.000001) {
                $status = 'closed';
            } elseif ($totalCancelled > 0.000001) {
                $status = 'cancelled';
            } else {
                $status = 'closed';
            }
        } elseif ($totalReceived > 0.0) {
            $status = 'partially_received';
        } elseif ($totalCancelled > 0.0) {
            $status = 'sent';
        } else {
            $status = 'sent';
        }

        $update = $db->prepare('UPDATE purchase_orders SET status = :status, updated_at = NOW() WHERE id = :id');
        $update->execute([
            ':id' => $purchaseOrderId,
            ':status' => $status,
        ]);

        return $status;
    }

    /**
     * @return array{
     *   id:int,
     *   order_number:?string,
     *   supplier:?array{id:int,name:string,contact_name:?string,contact_email:?string,contact_phone:?string,default_lead_time_days:int,notes:?string}|null,
     *   status:string,
     *   order_date:?string,
     *   expected_date:?string,
     *   total_cost:float,
     *   notes:?string,
     *   lines:list<array{
     *     id:int,
     *     inventory_item_id:?int,
     *     supplier_sku:?string,
     *     description:?string,
     *     quantity_ordered:float,
     *     quantity_received:float,
     *     unit_cost:float,
     *     expected_date:?string,
     *     quantity_cancelled:float,
     *     sku:?string,
     *     item:?string
     *   }>
     * }|null
     */
    function loadPurchaseOrder(\PDO $db, int $purchaseOrderId): ?array
    {
        purchaseOrderEnsureSchema($db);

        $statement = $db->prepare(
            'SELECT po.id, po.order_number, po.status, po.order_date, po.expected_date, po.total_cost, po.notes,
                    s.id AS supplier_id, s.name AS supplier_name, s.contact_name, s.contact_email, s.contact_phone,
                    s.default_lead_time_days, s.notes AS supplier_notes
             FROM purchase_orders po
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             WHERE po.id = :id'
        );

        $statement->execute([':id' => $purchaseOrderId]);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $lineStatement = $db->prepare(
            'SELECT pol.id, pol.inventory_item_id, pol.supplier_sku, pol.description, pol.quantity_ordered, pol.quantity_received,
                    pol.quantity_cancelled, pol.unit_cost, pol.expected_date, i.sku, i.item
             FROM purchase_order_lines pol
             LEFT JOIN inventory_items i ON i.id = pol.inventory_item_id
             WHERE pol.purchase_order_id = :id
             ORDER BY pol.id ASC'
        );
        $lineStatement->execute([':id' => $purchaseOrderId]);

        /** @var array<int,array<string,mixed>> $lineRows */
        $lineRows = $lineStatement->fetchAll();

        $lines = [];

        foreach ($lineRows as $line) {
            $lines[] = [
                'id' => (int) $line['id'],
                'inventory_item_id' => $line['inventory_item_id'] !== null ? (int) $line['inventory_item_id'] : null,
                'supplier_sku' => $line['supplier_sku'] !== null ? (string) $line['supplier_sku'] : null,
                'description' => $line['description'] !== null ? (string) $line['description'] : null,
                'quantity_ordered' => $line['quantity_ordered'] !== null ? (float) $line['quantity_ordered'] : 0.0,
                'quantity_received' => $line['quantity_received'] !== null ? (float) $line['quantity_received'] : 0.0,
                'quantity_cancelled' => $line['quantity_cancelled'] !== null ? (float) $line['quantity_cancelled'] : 0.0,
                'unit_cost' => $line['unit_cost'] !== null ? (float) $line['unit_cost'] : 0.0,
                'expected_date' => $line['expected_date'] !== null ? (string) $line['expected_date'] : null,
                'sku' => $line['sku'] !== null ? (string) $line['sku'] : null,
                'item' => $line['item'] !== null ? (string) $line['item'] : null,
                'outstanding_quantity' => max(0.0, ($line['quantity_ordered'] !== null ? (float) $line['quantity_ordered'] : 0.0)
                    - ($line['quantity_received'] !== null ? (float) $line['quantity_received'] : 0.0)
                    - ($line['quantity_cancelled'] !== null ? (float) $line['quantity_cancelled'] : 0.0)),
            ];
        }

        return [
            'id' => (int) $row['id'],
            'order_number' => $row['order_number'] !== null ? (string) $row['order_number'] : null,
            'supplier' => $row['supplier_id'] !== null ? [
                'id' => (int) $row['supplier_id'],
                'name' => (string) $row['supplier_name'],
                'contact_name' => $row['contact_name'] !== null ? (string) $row['contact_name'] : null,
                'contact_email' => $row['contact_email'] !== null ? (string) $row['contact_email'] : null,
                'contact_phone' => $row['contact_phone'] !== null ? (string) $row['contact_phone'] : null,
                'default_lead_time_days' => $row['default_lead_time_days'] !== null ? (int) $row['default_lead_time_days'] : 0,
                'notes' => $row['supplier_notes'] !== null ? (string) $row['supplier_notes'] : null,
            ] : null,
            'status' => (string) $row['status'],
            'order_date' => $row['order_date'] !== null ? (string) $row['order_date'] : null,
            'expected_date' => $row['expected_date'] !== null ? (string) $row['expected_date'] : null,
            'total_cost' => $row['total_cost'] !== null ? (float) $row['total_cost'] : 0.0,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'lines' => $lines,
        ];
    }

    /**
     * Load purchase orders that still have outstanding quantities.
     *
     * @return list<array{
     *   id:int,
     *   order_number:?string,
     *   supplier_name:?string,
     *   status:string,
     *   order_date:?string,
     *   expected_date:?string,
     *   outstanding_quantity:float,
     *   received_quantity:float,
     *   cancelled_quantity:float,
     *   line_count:int
     * }>
     */
    function purchaseOrderListOpen(\PDO $db): array
    {
        purchaseOrderEnsureSchema($db);

        $statement = $db->query(
            "SELECT\n"
            . "    po.id,\n"
            . "    po.order_number,\n"
            . "    po.status,\n"
            . "    po.order_date,\n"
            . "    po.expected_date,\n"
            . "    s.name AS supplier_name,\n"
            . "    SUM(GREATEST(pol.quantity_ordered - pol.quantity_received - COALESCE(pol.quantity_cancelled, 0), 0)) AS outstanding_quantity,\n"
            . "    SUM(pol.quantity_received) AS total_received,\n"
            . "    SUM(pol.quantity_cancelled) AS total_cancelled,\n"
            . "    COUNT(pol.id) AS line_count\n"
            . "FROM purchase_orders po\n"
            . "JOIN purchase_order_lines pol ON pol.purchase_order_id = po.id\n"
            . "LEFT JOIN suppliers s ON s.id = po.supplier_id\n"
            . "WHERE po.status IN ('sent', 'partially_received')\n"
            . "GROUP BY po.id, po.order_number, po.status, po.order_date, po.expected_date, s.name\n"
            . "HAVING SUM(GREATEST(pol.quantity_ordered - pol.quantity_received - COALESCE(pol.quantity_cancelled, 0), 0)) > 0\n"
            . "ORDER BY COALESCE(po.expected_date, po.order_date) ASC NULLS LAST, po.id ASC"
        );

        if ($statement === false) {
            return [];
        }

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'order_number' => $row['order_number'] !== null ? (string) $row['order_number'] : null,
                    'supplier_name' => $row['supplier_name'] !== null ? (string) $row['supplier_name'] : null,
                    'status' => (string) $row['status'],
                    'order_date' => $row['order_date'] !== null ? (string) $row['order_date'] : null,
                    'expected_date' => $row['expected_date'] !== null ? (string) $row['expected_date'] : null,
                    'outstanding_quantity' => $row['outstanding_quantity'] !== null ? (float) $row['outstanding_quantity'] : 0.0,
                    'received_quantity' => $row['total_received'] !== null ? (float) $row['total_received'] : 0.0,
                    'cancelled_quantity' => $row['total_cancelled'] !== null ? (float) $row['total_cancelled'] : 0.0,
                    'line_count' => $row['line_count'] !== null ? (int) $row['line_count'] : 0,
                ];
            },
            $rows
        );
    }

    /**
     * Load recent receipt events for reporting.
     *
     * @return list<array{
     *   id:int,
     *   purchase_order_id:int,
     *   order_number:?string,
     *   supplier_name:?string,
     *   reference:string,
     *   notes:?string,
     *   created_at:string,
     *   inventory_transaction_id:?int,
     *   total_received:float,
     *   total_cancelled:float
     * }>
     */
    function purchaseOrderLoadRecentReceipts(\PDO $db, int $limit = 10): array
    {
        purchaseOrderEnsureSchema($db);

        $limit = max(1, $limit);

        $statement = $db->prepare(
            'SELECT por.id, por.purchase_order_id, por.reference, por.notes, por.created_at, por.inventory_transaction_id,
                    po.order_number, s.name AS supplier_name,
                    COALESCE(SUM(porl.quantity_received), 0) AS total_received,
                    COALESCE(SUM(porl.quantity_cancelled), 0) AS total_cancelled
             FROM purchase_order_receipts por
             LEFT JOIN purchase_order_receipt_lines porl ON porl.receipt_id = por.id
             LEFT JOIN purchase_orders po ON po.id = por.purchase_order_id
             LEFT JOIN suppliers s ON s.id = po.supplier_id
             GROUP BY por.id, por.purchase_order_id, por.reference, por.notes, por.created_at, por.inventory_transaction_id, po.order_number, s.name
             ORDER BY por.created_at DESC, por.id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'purchase_order_id' => (int) $row['purchase_order_id'],
                    'order_number' => $row['order_number'] !== null ? (string) $row['order_number'] : null,
                    'supplier_name' => $row['supplier_name'] !== null ? (string) $row['supplier_name'] : null,
                    'reference' => (string) $row['reference'],
                    'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
                    'created_at' => (string) $row['created_at'],
                    'inventory_transaction_id' => $row['inventory_transaction_id'] !== null ? (int) $row['inventory_transaction_id'] : null,
                    'total_received' => $row['total_received'] !== null ? (float) $row['total_received'] : 0.0,
                    'total_cancelled' => $row['total_cancelled'] !== null ? (float) $row['total_cancelled'] : 0.0,
                ];
            },
            $rows
        );
    }

    /**
     * Load receipt history for a specific purchase order.
     *
     * @return list<array{
     *   id:int,
     *   reference:string,
     *   notes:?string,
     *   created_at:string,
     *   inventory_transaction_id:?int,
     *   lines:list<array{
     *     purchase_order_line_id:int,
     *     description:?string,
     *     quantity_received:float,
     *     quantity_cancelled:float,
     *     sku:?string,
     *     item:?string
     *   }>
     * }>
     */
    function purchaseOrderLoadReceiptHistory(\PDO $db, int $purchaseOrderId): array
    {
        purchaseOrderEnsureSchema($db);

        $receiptStatement = $db->prepare(
            'SELECT id, reference, notes, created_at, inventory_transaction_id
             FROM purchase_order_receipts
             WHERE purchase_order_id = :purchase_order_id
             ORDER BY created_at DESC, id DESC'
        );
        $receiptStatement->execute([':purchase_order_id' => $purchaseOrderId]);

        /** @var array<int,array<string,mixed>> $receipts */
        $receipts = $receiptStatement->fetchAll();

        if ($receipts === []) {
            return [];
        }

        $receiptIds = array_map(static fn (array $row): int => (int) $row['id'], $receipts);
        $placeholders = implode(', ', array_fill(0, count($receiptIds), '?'));

        $lineStatement = $db->prepare(
            'SELECT porl.receipt_id, porl.purchase_order_line_id, porl.quantity_received, porl.quantity_cancelled,
                    pol.description, pol.supplier_sku, pol.inventory_item_id,
                    i.sku, i.item
             FROM purchase_order_receipt_lines porl
             JOIN purchase_order_lines pol ON pol.id = porl.purchase_order_line_id
             LEFT JOIN inventory_items i ON i.id = pol.inventory_item_id
             WHERE porl.receipt_id IN (' . $placeholders . ')
             ORDER BY porl.receipt_id DESC, porl.id ASC'
        );
        $lineStatement->execute($receiptIds);

        /** @var array<int,array<string,mixed>> $lines */
        $lines = $lineStatement->fetchAll();

        $grouped = [];

        foreach ($lines as $line) {
            $receiptId = (int) $line['receipt_id'];
            $grouped[$receiptId][] = [
                'purchase_order_line_id' => (int) $line['purchase_order_line_id'],
                'description' => $line['description'] !== null ? (string) $line['description'] : null,
                'quantity_received' => $line['quantity_received'] !== null ? (float) $line['quantity_received'] : 0.0,
                'quantity_cancelled' => $line['quantity_cancelled'] !== null ? (float) $line['quantity_cancelled'] : 0.0,
                'sku' => $line['sku'] !== null ? (string) $line['sku'] : null,
                'item' => $line['item'] !== null ? (string) $line['item'] : null,
            ];
        }

        return array_map(
            static function (array $receipt) use ($grouped): array {
                $id = (int) $receipt['id'];

                return [
                    'id' => $id,
                    'reference' => (string) $receipt['reference'],
                    'notes' => $receipt['notes'] !== null ? (string) $receipt['notes'] : null,
                    'created_at' => (string) $receipt['created_at'],
                    'inventory_transaction_id' => $receipt['inventory_transaction_id'] !== null ? (int) $receipt['inventory_transaction_id'] : null,
                    'lines' => $grouped[$id] ?? [],
                ];
            },
            $receipts
        );
    }
}
