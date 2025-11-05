<?php

declare(strict_types=1);

if (!function_exists('loadInventory')) {
    /**
     * Parse a SKU into its base part number and up to two variant codes.
     *
     * @return array{part_number:string,variant_primary:?string,variant_secondary:?string}
     */
    function inventoryParseSku(string $sku): array
    {
        $normalized = trim($sku);

        if ($normalized === '') {
            return [
                'part_number' => '',
                'variant_primary' => null,
                'variant_secondary' => null,
            ];
        }

        $segments = preg_split('/-+/', $normalized) ?: [$normalized];
        $segments = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => $segment !== ''
        ));

        $partNumber = array_shift($segments) ?? '';
        $variantPrimary = null;
        $variantSecondary = null;

        if ($segments !== []) {
            $variantPrimary = array_shift($segments);
            if ($segments !== []) {
                $variantSecondary = implode('-', $segments);
            }
        }

        return [
            'part_number' => $partNumber,
            'variant_primary' => $variantPrimary,
            'variant_secondary' => $variantSecondary,
        ];
    }

    /**
     * Build a SKU string from the provided part number and variant codes.
     */
    function inventoryComposeSku(string $partNumber, ?string $variantPrimary, ?string $variantSecondary): string
    {
        $segments = [trim($partNumber)];
        $primary = $variantPrimary !== null ? trim($variantPrimary) : '';
        $secondary = $variantSecondary !== null ? trim($variantSecondary) : '';

        if ($primary !== '') {
            $segments[] = $primary;
        }

        if ($secondary !== '') {
            $segments[] = $secondary;
        }

        $segments = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => $segment !== ''
        ));

        return implode('-', $segments);
    }

    /**
     * Provide a user-friendly representation of the variant codes.
     */
    function inventoryFormatVariantCodes(?string $variantPrimary, ?string $variantSecondary): string
    {
        $codes = array_values(array_filter(
            [$variantPrimary, $variantSecondary],
            static fn (?string $code): bool => $code !== null && $code !== ''
        ));

        return $codes === [] ? '—' : implode(' / ', $codes);
    }

    /**
     * Backfill part and variant columns for legacy rows that pre-date variant support.
     */
    function inventoryBackfillVariantColumns(\PDO $db): void
    {
        $statement = $db->query(
            'SELECT id, sku, part_number, variant_primary, variant_secondary FROM inventory_items'
        );

        if ($statement === false) {
            return;
        }

        $rows = $statement->fetchAll();

        if ($rows === []) {
            return;
        }

        $update = $db->prepare(
            'UPDATE inventory_items SET part_number = :part_number, variant_primary = :variant_primary, variant_secondary = :variant_secondary WHERE id = :id'
        );

        foreach ($rows as $row) {
            $components = inventoryParseSku((string) $row['sku']);
            $partNumber = isset($row['part_number']) ? (string) $row['part_number'] : '';
            $variantPrimary = $row['variant_primary'] !== null ? (string) $row['variant_primary'] : null;
            $variantSecondary = $row['variant_secondary'] !== null ? (string) $row['variant_secondary'] : null;

            $needsUpdate = false;

            if ($partNumber === '' && $components['part_number'] !== '') {
                $partNumber = $components['part_number'];
                $needsUpdate = true;
            }

            if ($variantPrimary === null && $components['variant_primary'] !== null) {
                $variantPrimary = $components['variant_primary'];
                $needsUpdate = true;
            }

            if ($variantSecondary === null && $components['variant_secondary'] !== null) {
                $variantSecondary = $components['variant_secondary'];
                $needsUpdate = true;
            }

            if (!$needsUpdate) {
                continue;
            }

            $update->execute([
                ':id' => (int) $row['id'],
                ':part_number' => $partNumber,
                ':variant_primary' => $variantPrimary,
                ':variant_secondary' => $variantSecondary,
            ]);
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
            'variant_primary' => 'ALTER TABLE inventory_items ADD COLUMN variant_primary TEXT NULL',
            'variant_secondary' => 'ALTER TABLE inventory_items ADD COLUMN variant_secondary TEXT NULL',
        ];

        foreach ($required as $column => $sql) {
            if (!in_array($column, $existing, true)) {
                $db->exec($sql);
            }
        }

        inventoryBackfillVariantColumns($db);

        $ensured = true;
    }

    /**
     * Fetch inventory rows ordered by item name.
     *
     * @return array<int, array{item:string,sku:string,part_number:string,variant_primary:?string,variant_secondary:?string,location:string,stock:int,status:string,supplier:string,supplier_contact:?string,reorder_point:int,lead_time_days:int,id:int}>
     */
    function loadInventory(\PDO $db): array
    {
        ensureInventorySchema($db);

        try {
            $statement = $db->query(
                'SELECT id, item, sku, part_number, variant_primary, variant_secondary, location, stock, status, supplier, supplier_contact, reorder_point, lead_time_days FROM inventory_items ORDER BY item ASC'
            );

            $rows = $statement->fetchAll();

            return array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'item' => (string) $row['item'],
                    'sku' => (string) $row['sku'],
                    'part_number' => (string) $row['part_number'],
                    'variant_primary' => $row['variant_primary'] !== null ? (string) $row['variant_primary'] : null,
                    'variant_secondary' => $row['variant_secondary'] !== null ? (string) $row['variant_secondary'] : null,
                    'location' => (string) $row['location'],
                    'stock' => (int) $row['stock'],
                    'status' => (string) $row['status'],
                    'supplier' => (string) $row['supplier'],
                    'supplier_contact' => $row['supplier_contact'] !== null ? (string) $row['supplier_contact'] : null,
                    'reorder_point' => (int) $row['reorder_point'],
                    'lead_time_days' => (int) $row['lead_time_days'],
                ],
                $rows
            );
        } catch (\PDOException $exception) {
            throw new \PDOException('Unable to load inventory data: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    /**
     * Retrieve a single inventory item or null if it does not exist.
     *
     * @return array{item:string,sku:string,part_number:string,variant_primary:?string,variant_secondary:?string,location:string,stock:int,status:string,supplier:string,supplier_contact:?string,reorder_point:int,lead_time_days:int,id:int}|null
     */
    function findInventoryItem(\PDO $db, int $id): ?array
    {
        ensureInventorySchema($db);

        $statement = $db->prepare('SELECT id, item, sku, part_number, variant_primary, variant_secondary, location, stock, status, supplier, supplier_contact, reorder_point, lead_time_days FROM inventory_items WHERE id = :id');
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
            'variant_primary' => $row['variant_primary'] !== null ? (string) $row['variant_primary'] : null,
            'variant_secondary' => $row['variant_secondary'] !== null ? (string) $row['variant_secondary'] : null,
            'location' => (string) $row['location'],
            'stock' => (int) $row['stock'],
            'status' => (string) $row['status'],
            'supplier' => (string) $row['supplier'],
            'supplier_contact' => $row['supplier_contact'] !== null ? (string) $row['supplier_contact'] : null,
            'reorder_point' => (int) $row['reorder_point'],
            'lead_time_days' => (int) $row['lead_time_days'],
        ];
    }

    /**
     * Insert a new inventory item.
     *
     * @param array{item:string,sku:string,part_number:string,variant_primary:?string,variant_secondary:?string,location:string,stock:int,status:string,supplier:string,supplier_contact:?string,reorder_point:int,lead_time_days:int} $payload
     */
    function createInventoryItem(\PDO $db, array $payload): int
    {
        ensureInventorySchema($db);

        $statement = $db->prepare(
            'INSERT INTO inventory_items (item, sku, part_number, variant_primary, variant_secondary, location, stock, status, supplier, supplier_contact, reorder_point, lead_time_days) '
            . 'VALUES (:item, :sku, :part_number, :variant_primary, :variant_secondary, :location, :stock, :status, :supplier, :supplier_contact, :reorder_point, :lead_time_days) RETURNING id'
        );

        $statement->execute([
            ':item' => $payload['item'],
            ':sku' => $payload['sku'],
            ':part_number' => $payload['part_number'],
            ':variant_primary' => $payload['variant_primary'],
            ':variant_secondary' => $payload['variant_secondary'],
            ':location' => $payload['location'],
            ':stock' => $payload['stock'],
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
     * @param array{item:string,sku:string,part_number:string,variant_primary:?string,variant_secondary:?string,location:string,stock:int,status:string,supplier:string,supplier_contact:?string,reorder_point:int,lead_time_days:int} $payload
     */
    function updateInventoryItem(\PDO $db, int $id, array $payload): void
    {
        ensureInventorySchema($db);

        $statement = $db->prepare(
            'UPDATE inventory_items SET item = :item, sku = :sku, part_number = :part_number, variant_primary = :variant_primary, variant_secondary = :variant_secondary, '
            . 'location = :location, stock = :stock, status = :status, supplier = :supplier, supplier_contact = :supplier_contact, '
            . 'reorder_point = :reorder_point, lead_time_days = :lead_time_days WHERE id = :id'
        );

        $statement->execute([
            ':id' => $id,
            ':item' => $payload['item'],
            ':sku' => $payload['sku'],
            ':part_number' => $payload['part_number'],
            ':variant_primary' => $payload['variant_primary'],
            ':variant_secondary' => $payload['variant_secondary'],
            ':location' => $payload['location'],
            ':stock' => $payload['stock'],
            ':status' => $payload['status'],
            ':supplier' => $payload['supplier'],
            ':supplier_contact' => $payload['supplier_contact'],
            ':reorder_point' => $payload['reorder_point'],
            ':lead_time_days' => $payload['lead_time_days'],
        ]);
    }
}
