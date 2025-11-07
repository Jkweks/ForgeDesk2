<?php

declare(strict_types=1);

if (!function_exists('loadInventory')) {
    /**
     * @return list<string>
     */
    function inventoryFinishOptions(): array
    {
        return ['BL', 'C2', 'DB', '0R'];
    }

    /**
     * Parse a SKU into its base part number and optional finish code.
     *
     * @return array{part_number:string,finish:?string}
     */
    function inventoryParseSku(string $sku): array
    {
        $normalized = trim($sku);

        if ($normalized === '') {
            return [
                'part_number' => '',
                'finish' => null,
            ];
        }

        $segments = preg_split('/-+/', $normalized) ?: [$normalized];
        $segments = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            return [
                'part_number' => '',
                'finish' => null,
            ];
        }

        $finish = null;
        $options = inventoryFinishOptions();

        if (count($segments) > 1) {
            $last = strtoupper(end($segments));
            if (in_array($last, $options, true)) {
                $finish = $last;
                array_pop($segments);
            }
        }

        $partNumber = implode('-', $segments);

        return [
            'part_number' => $partNumber,
            'finish' => $finish,
        ];
    }

    /**
     * Build a SKU string from the provided part number and finish code.
     */
    function inventoryComposeSku(string $partNumber, ?string $finish): string
    {
        $segments = [trim($partNumber)];
        $finish = inventoryNormalizeFinish($finish);

        if ($finish !== null) {
            $segments[] = $finish;
        }

        $segments = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => $segment !== ''
        ));

        return implode('-', $segments);
    }

    function inventoryFormatFinish(?string $finish): string
    {
        return $finish !== null && $finish !== '' ? strtoupper($finish) : '—';
    }

    function inventoryFormatQuantity(int $quantity): string
    {
        return number_format($quantity);
    }

    function inventoryNormalizeFinish(?string $finish): ?string
    {
        if ($finish === null) {
            return null;
        }

        $normalized = strtoupper(trim($finish));

        return in_array($normalized, inventoryFinishOptions(), true) ? $normalized : null;
    }

    /**
     * Backfill part numbers and finish codes for legacy rows and remove deprecated columns.
     */
    function inventoryBackfillFinishColumn(\PDO $db, bool $hasVariantPrimary, bool $hasVariantSecondary): void
    {
        $columns = ['id', 'sku', 'part_number', 'finish'];

        if ($hasVariantPrimary) {
            $columns[] = 'variant_primary';
        }

        if ($hasVariantSecondary) {
            $columns[] = 'variant_secondary';
        }

        $statement = $db->query('SELECT ' . implode(', ', $columns) . ' FROM inventory_items');

        if ($statement === false) {
            return;
        }

        $rows = $statement->fetchAll();

        if ($rows === []) {
            return;
        }

        $update = $db->prepare(
            'UPDATE inventory_items SET part_number = :part_number, finish = :finish, sku = :sku WHERE id = :id'
        );

        foreach ($rows as $row) {
            $partNumber = (string) $row['part_number'];
            $currentFinish = isset($row['finish']) && $row['finish'] !== null ? inventoryNormalizeFinish((string) $row['finish']) : null;
            $variantPrimary = $hasVariantPrimary && $row['variant_primary'] !== null ? (string) $row['variant_primary'] : null;
            $variantSecondary = $hasVariantSecondary && $row['variant_secondary'] !== null ? (string) $row['variant_secondary'] : null;
            $components = inventoryParseSku((string) $row['sku']);

            $finish = $currentFinish ?? inventoryNormalizeFinish($variantPrimary) ?? inventoryNormalizeFinish($variantSecondary) ?? $components['finish'];

            $partSegments = $partNumber !== '' ? preg_split('/-+/', $partNumber) : [];
            $partSegments = is_array($partSegments) ? array_values(array_filter($partSegments, static fn ($segment) => $segment !== '')) : [];

            $appendSegments = [];

            if ($variantPrimary !== null && inventoryNormalizeFinish($variantPrimary) === null) {
                $appendSegments[] = trim($variantPrimary);
            }

            if ($variantSecondary !== null && inventoryNormalizeFinish($variantSecondary) === null) {
                $appendSegments[] = trim($variantSecondary);
            }

            foreach ($appendSegments as $segment) {
                if ($segment === '') {
                    continue;
                }

                if (!in_array($segment, $partSegments, true)) {
                    $partSegments[] = $segment;
                }
            }

            if ($partSegments === []) {
                $partSegments = $components['part_number'] !== ''
                    ? preg_split('/-+/', $components['part_number']) ?: []
                    : [];
                $partSegments = array_values(array_filter(
                    is_array($partSegments) ? $partSegments : [],
                    static fn ($segment) => $segment !== ''
                ));
            }

            $newPartNumber = implode('-', $partSegments);
            $newSku = inventoryComposeSku($newPartNumber, $finish);

            if ($newPartNumber === $partNumber && $finish === $currentFinish && $newSku === (string) $row['sku']) {
                continue;
            }

            $update->execute([
                ':id' => (int) $row['id'],
                ':part_number' => $newPartNumber,
                ':finish' => $finish,
                ':sku' => $newSku,
            ]);
        }

        if ($hasVariantPrimary) {
            $db->exec('ALTER TABLE inventory_items DROP COLUMN IF EXISTS variant_primary');
        }

        if ($hasVariantSecondary) {
            $db->exec('ALTER TABLE inventory_items DROP COLUMN IF EXISTS variant_secondary');
        }
    }

    /**
     * Ensure the inventory_items table has the extended inventory columns.
     */
    function ensureInventorySchema(\PDO $db): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $statement = $db->prepare(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table'
        );
        $statement->execute([':table' => 'inventory_items']);

        /** @var list<string> $existing */
        $existing = $statement->fetchAll(\PDO::FETCH_COLUMN);

        $required = [
            'supplier' => "ALTER TABLE inventory_items ADD COLUMN supplier TEXT NOT NULL DEFAULT 'Unknown Supplier'",
            'supplier_contact' => 'ALTER TABLE inventory_items ADD COLUMN supplier_contact TEXT NULL',
            'reorder_point' => 'ALTER TABLE inventory_items ADD COLUMN reorder_point INTEGER NOT NULL DEFAULT 0',
            'lead_time_days' => 'ALTER TABLE inventory_items ADD COLUMN lead_time_days INTEGER NOT NULL DEFAULT 0',
            'part_number' => "ALTER TABLE inventory_items ADD COLUMN part_number TEXT NOT NULL DEFAULT ''",
            'finish' => 'ALTER TABLE inventory_items ADD COLUMN finish TEXT NULL',
            'committed_qty' => 'ALTER TABLE inventory_items ADD COLUMN committed_qty INTEGER NOT NULL DEFAULT 0',
        ];

        foreach ($required as $column => $sql) {
            if (!in_array($column, $existing, true)) {
                $db->exec($sql);
            }
        }

        $hasVariantPrimary = in_array('variant_primary', $existing, true);
        $hasVariantSecondary = in_array('variant_secondary', $existing, true);

        inventoryBackfillFinishColumn($db, $hasVariantPrimary, $hasVariantSecondary);

        $ensured = true;
    }

    function inventorySupportsReservations(\PDO $db): bool
    {
        static $supports = null;

        if ($supports !== null) {
            return $supports;
        }

        try {
            $statement = $db->query(
                "SELECT to_regclass('job_reservations') AS job_reservations, "
                . "to_regclass('job_reservation_items') AS job_reservation_items"
            );

            $row = $statement !== false ? $statement->fetch(\PDO::FETCH_ASSOC) : false;

            $supports = $row !== false
                && !empty($row['job_reservations'])
                && !empty($row['job_reservation_items']);
        } catch (\PDOException $exception) {
            $supports = false;
        }

        return $supports;
    }

    /**
     * Fetch inventory rows ordered by item name.
     *
     * @return array<int, array{
     *   item:string,
     *   sku:string,
     *   part_number:string,
     *   finish:?string,
     *   location:string,
     *   stock:int,
     *   committed_qty:int,
     *   available_qty:int,
     *   status:string,
     *   supplier:string,
     *   supplier_contact:?string,
     *   reorder_point:int,
     *   lead_time_days:int,
     *   active_reservations:int,
     *   id:int
     * }>
     */
    function loadInventory(\PDO $db): array
    {
        ensureInventorySchema($db);

        try {
            $supportsReservations = inventorySupportsReservations($db);

            $statement = $db->query(
                'SELECT i.id, i.item, i.sku, i.part_number, i.finish, i.location, i.stock, i.committed_qty, '
                . 'GREATEST(i.stock - i.committed_qty, 0) AS available_qty, i.status, i.supplier, i.supplier_contact, '
                . 'i.reorder_point, i.lead_time_days, '
                . ($supportsReservations ? 'COALESCE(res.active_reservations, 0)' : '0') . ' AS active_reservations '
                . 'FROM inventory_items i '
                . ($supportsReservations
                    ? 'LEFT JOIN (
                    SELECT jri.inventory_item_id,
                        COUNT(*) FILTER (WHERE jr.status IN (\'draft\', \'committed\', \'in_progress\')) AS active_reservations
                    FROM job_reservation_items jri
                    JOIN job_reservations jr ON jr.id = jri.reservation_id
                    GROUP BY jri.inventory_item_id
                ) res ON res.inventory_item_id = i.id '
                    : ''
                )
                . 'ORDER BY i.item ASC'
            );

            $rows = $statement->fetchAll();

            return array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'item' => (string) $row['item'],
                    'sku' => (string) $row['sku'],
                    'part_number' => (string) $row['part_number'],
                    'finish' => $row['finish'] !== null ? inventoryNormalizeFinish((string) $row['finish']) : null,
                    'location' => (string) $row['location'],
                    'stock' => (int) $row['stock'],
                    'committed_qty' => (int) $row['committed_qty'],
                    'available_qty' => (int) $row['available_qty'],
                    'status' => (string) $row['status'],
                    'supplier' => (string) $row['supplier'],
                    'supplier_contact' => $row['supplier_contact'] !== null ? (string) $row['supplier_contact'] : null,
                    'reorder_point' => (int) $row['reorder_point'],
                    'lead_time_days' => (int) $row['lead_time_days'],
                    'active_reservations' => (int) $row['active_reservations'],
                ],
                $rows
            );
        } catch (\PDOException $exception) {
            throw new \PDOException('Unable to load inventory data: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    /**
     * Summarize inventory stock and reservation activity for dashboards.
     *
     * @return array{total_stock:int,total_committed:int,total_available:int,active_reservations:int}
     */
    function inventoryReservationSummary(\PDO $db): array
    {
        ensureInventorySchema($db);

        try {
            $totalsStatement = $db->query(
                'SELECT '
                . 'COALESCE(SUM(stock), 0) AS total_stock, '
                . 'COALESCE(SUM(committed_qty), 0) AS total_committed, '
                . 'COALESCE(SUM(GREATEST(stock - committed_qty, 0)), 0) AS total_available '
                . 'FROM inventory_items'
            );

            $totals = $totalsStatement !== false ? (array) $totalsStatement->fetch() : [];
        } catch (\PDOException $exception) {
            throw new \PDOException('Unable to summarize inventory commitments: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $activeReservations = 0;

        if (inventorySupportsReservations($db)) {
            try {
                $reservationStatement = $db->query(
                    "SELECT COUNT(*) AS active_count FROM job_reservations "
                    . "WHERE status IN ('draft', 'committed', 'in_progress')"
                );

                if ($reservationStatement !== false) {
                    $activeReservations = (int) $reservationStatement->fetchColumn();
                }
            } catch (\PDOException $exception) {
                $activeReservations = 0;
            }
        }

        return [
            'total_stock' => isset($totals['total_stock']) ? (int) $totals['total_stock'] : 0,
            'total_committed' => isset($totals['total_committed']) ? (int) $totals['total_committed'] : 0,
            'total_available' => isset($totals['total_available']) ? (int) $totals['total_available'] : 0,
            'active_reservations' => $activeReservations,
        ];
    }

    /**
     * Retrieve a single inventory item or null if it does not exist.
     *
     * @return array{
     *   item:string,
     *   sku:string,
     *   part_number:string,
     *   finish:?string,
     *   location:string,
     *   stock:int,
     *   committed_qty:int,
     *   available_qty:int,
     *   status:string,
     *   supplier:string,
     *   supplier_contact:?string,
     *   reorder_point:int,
     *   lead_time_days:int,
     *   active_reservations:int,
     *   id:int
     * }|null
     */
    function findInventoryItem(\PDO $db, int $id): ?array
    {
        ensureInventorySchema($db);

        $supportsReservations = inventorySupportsReservations($db);
        $activeSelect = $supportsReservations ? 'COALESCE(res.active_reservations, 0)' : '0';
        $joinClause = $supportsReservations
            ? 'LEFT JOIN (
                SELECT jri.inventory_item_id,
                    COUNT(*) FILTER (WHERE jr.status IN (\'draft\', \'committed\', \'in_progress\')) AS active_reservations
                FROM job_reservation_items jri
                JOIN job_reservations jr ON jr.id = jri.reservation_id
                GROUP BY jri.inventory_item_id
            ) res ON res.inventory_item_id = i.id '
            : '';

        $statement = $db->prepare(
            'SELECT i.id, i.item, i.sku, i.part_number, i.finish, i.location, i.stock, i.committed_qty, '
            . 'GREATEST(i.stock - i.committed_qty, 0) AS available_qty, i.status, i.supplier, i.supplier_contact, '
            . 'i.reorder_point, i.lead_time_days, ' . $activeSelect . ' AS active_reservations '
            . 'FROM inventory_items i '
            . $joinClause
            . 'WHERE i.id = :id'
        );
        $statement->bindValue(':id', $id, \PDO::PARAM_INT);
        $statement->execute();

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'item' => (string) $row['item'],
            'sku' => (string) $row['sku'],
            'part_number' => (string) $row['part_number'],
            'finish' => $row['finish'] !== null ? inventoryNormalizeFinish((string) $row['finish']) : null,
            'location' => (string) $row['location'],
            'stock' => (int) $row['stock'],
            'committed_qty' => (int) $row['committed_qty'],
            'available_qty' => (int) $row['available_qty'],
            'status' => (string) $row['status'],
            'supplier' => (string) $row['supplier'],
            'supplier_contact' => $row['supplier_contact'] !== null ? (string) $row['supplier_contact'] : null,
            'reorder_point' => (int) $row['reorder_point'],
            'lead_time_days' => (int) $row['lead_time_days'],
            'active_reservations' => (int) $row['active_reservations'],
        ];
    }

    /**
     * Retrieve an inventory item by SKU.
     *
     * @return array{
     *   item:string,
     *   sku:string,
     *   part_number:string,
     *   finish:?string,
     *   location:string,
     *   stock:int,
     *   committed_qty:int,
     *   available_qty:int,
     *   status:string,
     *   supplier:string,
     *   supplier_contact:?string,
     *   reorder_point:int,
     *   lead_time_days:int,
     *   active_reservations:int,
     *   id:int
     * }|null
     */
    function findInventoryItemBySku(\PDO $db, string $sku): ?array
    {
        ensureInventorySchema($db);

        $supportsReservations = inventorySupportsReservations($db);
        $activeSelect = $supportsReservations ? 'COALESCE(res.active_reservations, 0)' : '0';
        $joinClause = $supportsReservations
            ? 'LEFT JOIN (
                SELECT jri.inventory_item_id,
                    COUNT(*) FILTER (WHERE jr.status IN (\'draft\', \'committed\', \'in_progress\')) AS active_reservations
                FROM job_reservation_items jri
                JOIN job_reservations jr ON jr.id = jri.reservation_id
                GROUP BY jri.inventory_item_id
            ) res ON res.inventory_item_id = i.id '
            : '';

        $statement = $db->prepare(
            'SELECT i.id, i.item, i.sku, i.part_number, i.finish, i.location, i.stock, i.committed_qty, '
            . 'GREATEST(i.stock - i.committed_qty, 0) AS available_qty, i.status, i.supplier, i.supplier_contact, '
            . 'i.reorder_point, i.lead_time_days, ' . $activeSelect . ' AS active_reservations '
            . 'FROM inventory_items i '
            . $joinClause
            . 'WHERE i.sku = :sku LIMIT 1'
        );
        $statement->bindValue(':sku', $sku, \PDO::PARAM_STR);
        $statement->execute();

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'item' => (string) $row['item'],
            'sku' => (string) $row['sku'],
            'part_number' => (string) $row['part_number'],
            'finish' => $row['finish'] !== null ? inventoryNormalizeFinish((string) $row['finish']) : null,
            'location' => (string) $row['location'],
            'stock' => (int) $row['stock'],
            'committed_qty' => (int) $row['committed_qty'],
            'available_qty' => (int) $row['available_qty'],
            'status' => (string) $row['status'],
            'supplier' => (string) $row['supplier'],
            'supplier_contact' => $row['supplier_contact'] !== null ? (string) $row['supplier_contact'] : null,
            'reorder_point' => (int) $row['reorder_point'],
            'lead_time_days' => (int) $row['lead_time_days'],
            'active_reservations' => (int) $row['active_reservations'],
        ];
    }

    /**
     * Insert a new inventory item.
     *
     * @param array{
     *   item:string,
     *   sku:string,
     *   part_number:string,
     *   finish:?string,
     *   location:string,
     *   stock:int,
     *   status:string,
     *   supplier:string,
     *   supplier_contact:?string,
     *   reorder_point:int,
     *   lead_time_days:int,
     *   committed_qty?:int
     * } $payload
     */
    function createInventoryItem(\PDO $db, array $payload): int
    {
        ensureInventorySchema($db);

        $statement = $db->prepare(
            'INSERT INTO inventory_items (item, sku, part_number, finish, location, stock, committed_qty, status, supplier, '
            . 'supplier_contact, reorder_point, lead_time_days) '
            . 'VALUES (:item, :sku, :part_number, :finish, :location, :stock, :committed_qty, :status, :supplier, :supplier_contact, '
            . ':reorder_point, :lead_time_days) RETURNING id'
        );

        $statement->execute([
            ':item' => $payload['item'],
            ':sku' => $payload['sku'],
            ':part_number' => $payload['part_number'],
            ':finish' => $payload['finish'],
            ':location' => $payload['location'],
            ':stock' => $payload['stock'],
            ':committed_qty' => $payload['committed_qty'] ?? 0,
            ':status' => $payload['status'],
            ':supplier' => $payload['supplier'],
            ':supplier_contact' => $payload['supplier_contact'],
            ':reorder_point' => $payload['reorder_point'],
            ':lead_time_days' => $payload['lead_time_days'],
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Update an existing inventory item.
     *
     * @param array{
     *   item:string,
     *   sku:string,
     *   part_number:string,
     *   finish:?string,
     *   location:string,
     *   stock:int,
     *   status:string,
     *   supplier:string,
     *   supplier_contact:?string,
     *   reorder_point:int,
     *   lead_time_days:int,
     *   committed_qty?:int
     * } $payload
     */
    function updateInventoryItem(\PDO $db, int $id, array $payload): void
    {
        ensureInventorySchema($db);

        $statement = $db->prepare(
            'UPDATE inventory_items SET item = :item, sku = :sku, part_number = :part_number, finish = :finish, '
            . 'location = :location, stock = :stock, committed_qty = :committed_qty, status = :status, supplier = :supplier, '
            . 'supplier_contact = :supplier_contact, reorder_point = :reorder_point, lead_time_days = :lead_time_days WHERE id = :id'
        );

        $statement->execute([
            ':id' => $id,
            ':item' => $payload['item'],
            ':sku' => $payload['sku'],
            ':part_number' => $payload['part_number'],
            ':finish' => $payload['finish'],
            ':location' => $payload['location'],
            ':stock' => $payload['stock'],
            ':committed_qty' => $payload['committed_qty'] ?? 0,
            ':status' => $payload['status'],
            ':supplier' => $payload['supplier'],
            ':supplier_contact' => $payload['supplier_contact'],
            ':reorder_point' => $payload['reorder_point'],
            ':lead_time_days' => $payload['lead_time_days'],
        ]);
    }
}
