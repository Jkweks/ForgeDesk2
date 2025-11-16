<?php

declare(strict_types=1);

require_once __DIR__ . '/purchase_orders.php';

if (!function_exists('loadInventory')) {
    function inventoryIsDiscontinuedStatus(string $status): bool
    {
        return strcasecmp(trim($status), 'Discontinued') === 0;
    }

    function inventoryStatusFromAvailable(int $availableQty, int $reorderPoint): string
    {
        $normalizedReorder = max($reorderPoint, 0);

        if ($availableQty < $normalizedReorder) {
            return 'Critical';
        }

        $lowThreshold = (int) floor($normalizedReorder * 1.3);

        if ($availableQty <= $lowThreshold) {
            return 'Low';
        }

        return 'In Stock';
    }

    function inventoryResolveStatus(int $availableQty, int $reorderPoint, string $storedStatus): string
    {
        if (inventoryIsDiscontinuedStatus($storedStatus)) {
            return 'Discontinued';
        }

        return inventoryStatusFromAvailable($availableQty, $reorderPoint);
    }

    /**
     * @return list<string>
     */
    function inventoryFinishOptions(): array
    {
        return ['BL', 'C2', 'DB', '0R'];
    }

    /**
     * Canonical inventory categories and their available subcategory groupings.
     *
     * @return array<string,list<string>>
     */
    function inventoryCategoryOptions(): array
    {
        return [
            'Raw Materials' => ['Sheet Metal', 'Bar Stock', 'Plate', 'Plastics'],
            'Hardware' => ['Fasteners', 'Bearings', 'Seals & Gaskets', 'Structural Connectors'],
            'Finishing' => ['Powder Coat', 'Anodizing', 'Painting', 'Plating'],
            'Electrical' => ['Wire & Cable', 'Controls', 'Sensors', 'Lighting'],
        ];
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

    /**
     * Format inventory quantities for display while accepting either ints or floats.
     */
    function inventoryFormatQuantity($quantity): string
    {
        $quantity = (float) $quantity;

        $rounded = round($quantity);

        if (abs($quantity - $rounded) < 0.0005) {
            return number_format((int) $rounded);
        }

        return rtrim(rtrim(number_format($quantity, 3, '.', ','), '0'), '.');
    }

    function inventoryFormatDailyUse(?float $dailyUse): string
    {
        if ($dailyUse === null) {
            return '—';
        }

        return number_format($dailyUse, 2, '.', '');
    }

    /**
     * Normalize numeric values persisted as NUMERIC/DECIMAL strings into floats.
     *
     * @param mixed $value
     */
    function inventoryNormalizeNumericValue($value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        $stringValue = is_string($value) ? trim($value) : '';

        return is_numeric($stringValue) ? (float) $stringValue : 0.0;
    }

    function inventoryRoundUpToIncrement(float $quantity, float $increment): float
    {
        if ($quantity <= 0.0) {
            return 0.0;
        }

        if ($increment <= 0.0) {
            return $quantity;
        }

        return ceil($quantity / $increment) * $increment;
    }

    function inventoryCalculateRecommendedOrderQuantity(int $reorderPoint, float $availableNow): float
    {
        $reorderPoint = max(0, $reorderPoint);
        $availableNow = (float) $availableNow;

        $shortfall = $reorderPoint - $availableNow;

        if ($shortfall <= 0.0) {
            return 0.0;
        }

        return round($shortfall, 3);
    }

    function inventoryQuantityToEach(float $quantity, float $packSize, string $unit): float
    {
        $quantity = (float) $quantity;
        $packSize = (float) max($packSize, 0.0);
        $unit = strtolower(trim($unit));

        if ($unit === 'pack' && $packSize > 0.0) {
            return $quantity * $packSize;
        }

        return $quantity;
    }

    function inventoryEachToUnit(float $eachQuantity, float $packSize, string $unit): float
    {
        $eachQuantity = (float) $eachQuantity;
        $packSize = (float) max($packSize, 0.0);
        $unit = strtolower(trim($unit));

        if ($unit === 'pack' && $packSize > 0.0) {
            return $eachQuantity / $packSize;
        }

        return $eachQuantity;
    }

    /**
     * Number of trailing days used when calculating average daily usage.
     */
    function inventoryAverageDailyUseWindowDays(): int
    {
        return 30;
    }

    /**
     * Ensure the daily usage aggregation table exists.
     */
    function inventoryEnsureUsageSchema(\PDO $db): void
    {
        static $ensuredUsage = false;

        if ($ensuredUsage) {
            return;
        }

        $db->exec(
            'CREATE TABLE IF NOT EXISTS inventory_daily_usage (
                inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
                usage_date DATE NOT NULL,
                quantity_used INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (inventory_item_id, usage_date)
            )'
        );

        $db->exec('CREATE INDEX IF NOT EXISTS idx_inventory_daily_usage_date ON inventory_daily_usage (usage_date)');

        $ensuredUsage = true;
    }

    /**
     * Persist per-item usage totals for a specific calendar day.
     *
     * @param array<int,int> $usageByItem
     */
    function inventoryRecordDailyUsage(\PDO $db, string $usageDate, array $usageByItem): void
    {
        if ($usageByItem === []) {
            return;
        }

        inventoryEnsureUsageSchema($db);

        $statement = $db->prepare(
            'INSERT INTO inventory_daily_usage (inventory_item_id, usage_date, quantity_used)
             VALUES (:inventory_item_id, :usage_date, :quantity_used)
             ON CONFLICT (inventory_item_id, usage_date) DO UPDATE
             SET quantity_used = inventory_daily_usage.quantity_used + EXCLUDED.quantity_used'
        );

        foreach ($usageByItem as $itemId => $quantity) {
            if ($quantity <= 0) {
                continue;
            }

            $statement->execute([
                ':inventory_item_id' => $itemId,
                ':usage_date' => $usageDate,
                ':quantity_used' => $quantity,
            ]);
        }
    }

    /**
     * Calculate trailing average daily usage for the provided inventory items.
     *
     * @param list<int> $itemIds
     * @return array<int,?float>
     */
    function inventoryCalculateAverageDailyUseMap(\PDO $db, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if ($itemIds === []) {
            return [];
        }

        inventoryEnsureUsageSchema($db);

        $windowDays = inventoryAverageDailyUseWindowDays();
        $today = new \DateTimeImmutable('today');
        $startDate = $today->modify(sprintf('-%d days', $windowDays - 1))->format('Y-m-d');

        $placeholders = implode(', ', array_fill(0, count($itemIds), '?'));

        $statement = $db->prepare(
            'SELECT inventory_item_id, SUM(quantity_used) AS total_used, MIN(usage_date) AS first_usage
             FROM inventory_daily_usage
             WHERE usage_date >= ? AND inventory_item_id IN (' . $placeholders . ')
             GROUP BY inventory_item_id'
        );

        $statement->execute(array_merge([$startDate], $itemIds));

        /** @var array<int,array{inventory_item_id:int,total_used:string,first_usage:string|null}> $rows */
        $rows = $statement->fetchAll();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['inventory_item_id']] = [
                'total_used' => isset($row['total_used']) ? (float) $row['total_used'] : 0.0,
                'first_usage' => $row['first_usage'],
            ];
        }

        $averages = [];

        foreach ($itemIds as $itemId) {
            if (!isset($totals[$itemId])) {
                $averages[$itemId] = 0.0;
                continue;
            }

            $totalUsed = $totals[$itemId]['total_used'];
            $firstUsageRaw = $totals[$itemId]['first_usage'];

            if ($firstUsageRaw === null) {
                $averages[$itemId] = 0.0;
                continue;
            }

            try {
                $firstUsage = new \DateTimeImmutable($firstUsageRaw);
            } catch (\Exception $exception) {
                $averages[$itemId] = 0.0;
                continue;
            }

            $daysDiff = (int) $today->diff($firstUsage)->days;
            $daysCovered = max(1, min($windowDays, $daysDiff + 1));

            $averages[$itemId] = round($totalUsed / $daysCovered, 4);
        }

        $update = $db->prepare('UPDATE inventory_items SET average_daily_use = :average_daily_use WHERE id = :id');

        foreach ($averages as $itemId => $average) {
            $update->execute([
                ':id' => $itemId,
                ':average_daily_use' => $average,
            ]);
        }

        return $averages;
    }

    /**
     * Compose replenishment-oriented metrics for inventory items, including purchase order projections.
     *
     * @return list<array{
     *   id:int,
     *   item:string,
     *   sku:string,
     *   part_number:string,
     *   finish:?string,
     *   location:string,
     *   stock:int,
     *   committed_qty:int,
     *   available_now:int,
     *   on_order_qty:float,
     *   projected_available:float,
     *   average_daily_use:?float,
     *   demand_during_lead_time:float,
     *   target_stock:float,
     *   projected_shortfall:float,
     *   recommended_order_qty:float,
     *   days_of_supply:?float,
     *   safety_stock:float,
     *   min_order_qty:float,
     *   order_multiple:float,
     *   pack_size:float,
     *   purchase_uom:?string,
     *   stock_uom:?string,
     *   supplier_id:?int,
     *   supplier_display:string,
     *   supplier_name:?string,
     *   supplier_sku:?string,
     *   supplier_contact_name:?string,
     *   supplier_contact_email:?string,
     *   supplier_contact_phone:?string,
     *   legacy_supplier:string,
     *   legacy_supplier_contact:?string,
     *   lead_time_days:int,
     *   effective_lead_time_days:int,
     *   reorder_point:int,
     *   status:string,
     *   discontinued:bool,
     *   active_reservations:int
     * }>
     */
    function inventoryLoadReplenishmentSnapshot(\PDO $db): array
    {
        ensureInventorySchema($db);
        purchaseOrderEnsureSchema($db);

        $supportsReservations = inventorySupportsReservations($db);
        $committedSelect = $supportsReservations ? 'COALESCE(commitments.committed_qty, 0)' : '0';
        $activeSelect = $supportsReservations ? 'COALESCE(res.active_reservations, 0)' : '0';

        $joinCommitments = $supportsReservations
            ? 'LEFT JOIN inventory_item_commitments commitments ON commitments.inventory_item_id = i.id '
            : '';

        $joinReservations = $supportsReservations
            ? "LEFT JOIN (\n                SELECT jri.inventory_item_id,\n                    COUNT(*) FILTER (WHERE jr.status IN ('draft', 'committed', 'active', 'in_progress', 'on_hold')) AS active_reservations\n                FROM job_reservation_items jri\n                JOIN job_reservations jr ON jr.id = jri.reservation_id\n                GROUP BY jri.inventory_item_id\n            ) res ON res.inventory_item_id = i.id "
            : '';

        $statement = $db->query(
            'SELECT i.id, i.item, i.sku, i.part_number, i.finish, i.location, i.stock, i.status, '
            . $committedSelect . ' AS committed_qty, i.reorder_point, i.lead_time_days, i.average_daily_use, '
            . 'i.on_order_qty, i.safety_stock, i.min_order_qty, i.order_multiple, i.pack_size, i.purchase_uom, i.stock_uom, '
            . 'i.supplier_id, i.supplier_sku, i.supplier, i.supplier_contact, '
            . $activeSelect . ' AS active_reservations, '
            . 's.name AS supplier_name, s.contact_name AS supplier_contact_name, s.contact_email AS supplier_contact_email, '
            . 's.contact_phone AS supplier_contact_phone, s.default_lead_time_days AS supplier_default_lead_time '
            . 'FROM inventory_items i '
            . $joinCommitments
            . $joinReservations
            . 'LEFT JOIN suppliers s ON s.id = i.supplier_id '
            . 'ORDER BY i.item ASC'
        );

        if ($statement === false) {
            return [];
        }

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        if ($rows === []) {
            return [];
        }

        $idList = array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows
        );

        $averageUsage = inventoryCalculateAverageDailyUseMap($db, $idList);
        $onOrderMap = purchaseOrderOutstandingQuantities($db, $idList);
        $updateOnOrder = $db->prepare('UPDATE inventory_items SET on_order_qty = :on_order_qty WHERE id = :id');

        $results = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $stock = (int) $row['stock'];
            $committedQty = (int) $row['committed_qty'];
            $availableNow = $stock - $committedQty;
            $storedOnOrder = inventoryNormalizeNumericValue($row['on_order_qty'] ?? 0.0);
            $onOrder = $onOrderMap[$id] ?? 0.0;

            if (abs($storedOnOrder - $onOrder) >= 0.0001) {
                $updateOnOrder->execute([
                    ':id' => $id,
                    ':on_order_qty' => $onOrder,
                ]);
            }

            $projectedAvailable = $availableNow + $onOrder;
            $averageDailyUse = $averageUsage[$id] ?? ($row['average_daily_use'] !== null ? (float) $row['average_daily_use'] : null);

            $leadTimeDays = (int) $row['lead_time_days'];
            $supplierLead = $row['supplier_default_lead_time'] !== null ? (int) $row['supplier_default_lead_time'] : 0;
            $effectiveLead = $leadTimeDays > 0 ? $leadTimeDays : $supplierLead;

            $safetyStock = inventoryNormalizeNumericValue($row['safety_stock'] ?? 0.0);
            $minOrderQty = inventoryNormalizeNumericValue($row['min_order_qty'] ?? 0.0);
            $orderMultiple = inventoryNormalizeNumericValue($row['order_multiple'] ?? 0.0);
            $packSize = inventoryNormalizeNumericValue($row['pack_size'] ?? 0.0);

            $demandDuringLeadTime = $averageDailyUse !== null ? $averageDailyUse * max(0, $effectiveLead) : 0.0;
            $targetStock = $demandDuringLeadTime + $safetyStock;
            $projectedShortfall = max(0.0, $targetStock - $projectedAvailable);
            $reorderPoint = (int) $row['reorder_point'];
            $recommended = inventoryCalculateRecommendedOrderQuantity($reorderPoint, $availableNow);

            $daysOfSupply = ($averageDailyUse !== null && $averageDailyUse > 0)
                ? round($projectedAvailable / $averageDailyUse, 2)
                : null;
            $storedStatus = (string) $row['status'];

            $results[] = [
                'id' => $id,
                'item' => (string) $row['item'],
                'sku' => (string) $row['sku'],
                'part_number' => (string) $row['part_number'],
                'finish' => $row['finish'] !== null ? inventoryNormalizeFinish((string) $row['finish']) : null,
                'location' => (string) $row['location'],
                'stock' => $stock,
                'committed_qty' => $committedQty,
                'available_now' => $availableNow,
                'on_order_qty' => $onOrder,
                'projected_available' => $projectedAvailable,
                'average_daily_use' => $averageDailyUse,
                'demand_during_lead_time' => $demandDuringLeadTime,
                'target_stock' => $targetStock,
                'projected_shortfall' => $projectedShortfall,
                'recommended_order_qty' => $recommended,
                'days_of_supply' => $daysOfSupply,
                'safety_stock' => $safetyStock,
                'min_order_qty' => $minOrderQty,
                'order_multiple' => $orderMultiple,
                'pack_size' => $packSize,
                'purchase_uom' => $row['purchase_uom'] !== null ? (string) $row['purchase_uom'] : null,
                'stock_uom' => $row['stock_uom'] !== null ? (string) $row['stock_uom'] : null,
                'supplier_id' => $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null,
                'supplier_display' => $row['supplier_name'] !== null ? (string) $row['supplier_name'] : (string) $row['supplier'],
                'supplier_name' => $row['supplier_name'] !== null ? (string) $row['supplier_name'] : null,
                'supplier_sku' => $row['supplier_sku'] !== null ? (string) $row['supplier_sku'] : null,
                'supplier_contact_name' => $row['supplier_contact_name'] !== null ? (string) $row['supplier_contact_name'] : null,
                'supplier_contact_email' => $row['supplier_contact_email'] !== null ? (string) $row['supplier_contact_email'] : null,
                'supplier_contact_phone' => $row['supplier_contact_phone'] !== null ? (string) $row['supplier_contact_phone'] : null,
                'legacy_supplier' => (string) $row['supplier'],
                'legacy_supplier_contact' => $row['supplier_contact'] !== null ? (string) $row['supplier_contact'] : null,
                'lead_time_days' => $leadTimeDays,
                'effective_lead_time_days' => $effectiveLead,
                'reorder_point' => $reorderPoint,
                'status' => inventoryResolveStatus($availableNow, $reorderPoint, $storedStatus),
                'discontinued' => inventoryIsDiscontinuedStatus($storedStatus),
                'active_reservations' => (int) $row['active_reservations'],
            ];
        }

        return $results;
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
        static $ensuredItems = false;

        if (!$ensuredItems) {
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
                'average_daily_use' => 'ALTER TABLE inventory_items ADD COLUMN average_daily_use NUMERIC(12,4) NULL',
            ];

            foreach ($required as $column => $sql) {
                if (!in_array($column, $existing, true)) {
                    $db->exec($sql);
                }
            }

            $hasVariantPrimary = in_array('variant_primary', $existing, true);
            $hasVariantSecondary = in_array('variant_secondary', $existing, true);

            inventoryBackfillFinishColumn($db, $hasVariantPrimary, $hasVariantSecondary);

            $ensuredItems = true;
        }

        inventoryEnsureTransactionsSchema($db);

        if (inventorySupportsReservations($db)) {
            inventoryEnsureCommitmentView($db);
        }
    }

    /**
     * Ensure inventory transaction tables and indexes exist.
     */
    function inventoryEnsureTransactionsSchema(\PDO $db): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $db->exec(
            'CREATE TABLE IF NOT EXISTS inventory_transactions (
                id SERIAL PRIMARY KEY,
                reference TEXT NOT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->exec('ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS notes TEXT NULL');
        $db->exec('ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $db->exec('ALTER TABLE inventory_transactions ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP');

        $db->exec(
            'CREATE TABLE IF NOT EXISTS inventory_transaction_lines (
                id SERIAL PRIMARY KEY,
                transaction_id INTEGER NOT NULL REFERENCES inventory_transactions(id) ON DELETE CASCADE,
                inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE RESTRICT,
                quantity_change INTEGER NOT NULL,
                note TEXT NULL,
                stock_before INTEGER NOT NULL DEFAULT 0,
                stock_after INTEGER NOT NULL DEFAULT 0
            )'
        );

        $db->exec('ALTER TABLE inventory_transaction_lines ADD COLUMN IF NOT EXISTS note TEXT NULL');
        $db->exec('ALTER TABLE inventory_transaction_lines ADD COLUMN IF NOT EXISTS stock_before INTEGER NOT NULL DEFAULT 0');
        $db->exec('ALTER TABLE inventory_transaction_lines ADD COLUMN IF NOT EXISTS stock_after INTEGER NOT NULL DEFAULT 0');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_inventory_transaction_lines_transaction ON inventory_transaction_lines (transaction_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inventory_transaction_lines_item ON inventory_transaction_lines (inventory_item_id)');

        inventoryEnsureUsageSchema($db);

        $ensured = true;
    }

    function inventoryEnsureCommitmentView(\PDO $db): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $db->exec(
            "CREATE OR REPLACE VIEW inventory_item_commitments AS\n"
            . "SELECT\n"
            . "    i.id AS inventory_item_id,\n"
            . "    COALESCE(SUM(CASE\n"
            . "        WHEN jr.status IN ('active', 'committed', 'in_progress', 'on_hold')\n"
            . "            THEN jri.committed_qty\n"
            . "        ELSE 0\n"
            . "    END), 0) AS committed_qty\n"
            . "FROM inventory_items i\n"
            . "LEFT JOIN job_reservation_items jri ON jri.inventory_item_id = i.id\n"
            . "LEFT JOIN job_reservations jr ON jr.id = jri.reservation_id\n"
            . "GROUP BY i.id"
        );

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
     * Fetch committed inventory totals keyed by item id.
     *
     * @param list<int> $itemIds
     *
     * @return array<int,int>
     */
    function inventoryCommittedTotals(\PDO $db, array $itemIds = []): array
    {
        if (!inventorySupportsReservations($db)) {
            return [];
        }

        $sql = 'SELECT inventory_item_id AS id, committed_qty FROM inventory_item_commitments';
        $params = [];

        if ($itemIds !== []) {
            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $sql .= ' WHERE inventory_item_id IN (' . $placeholders . ')';
            $params = array_values($itemIds);
        }

        $statement = $db->prepare($sql);
        $statement->execute($params);

        /** @var list<array{ id:int|string, committed_qty:int|string|null }> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row['id']] = (int) ($row['committed_qty'] ?? 0);
        }

        return $totals;
    }

    /**
     * Retrieve inventory rows for lookup widgets.
     *
     * @return list<array{id:int,item:string,sku:string,part_number:string,stock:int,available_qty:int}>
     */
    function listInventoryLookupOptions(\PDO $db): array
    {
        ensureInventorySchema($db);

        $statement = $db->query(
            'SELECT id, item, sku, part_number, stock FROM inventory_items ORDER BY item ASC'
        );

        $rows = $statement !== false ? $statement->fetchAll() : [];

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows
        );

        $committedTotals = $ids !== [] ? inventoryCommittedTotals($db, $ids) : [];

        return array_map(
            static function (array $row) use ($committedTotals): array {
                $id = (int) $row['id'];
                $stock = (int) $row['stock'];
                $committed = $committedTotals[$id] ?? 0;

                return [
                    'id' => $id,
                    'item' => (string) $row['item'],
                    'sku' => (string) $row['sku'],
                    'part_number' => (string) $row['part_number'],
                    'stock' => $stock,
                    'available_qty' => $stock - $committed,
                ];
            },
            $rows
        );
    }

    /**
     * Resolve an inventory item by SKU, part number, or partial description.
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
    function resolveInventoryItemByIdentifier(\PDO $db, string $identifier): ?array
    {
        ensureInventorySchema($db);

        $query = trim($identifier);

        if ($query === '') {
            return null;
        }

        $exact = $db->prepare(
            'SELECT id FROM inventory_items WHERE LOWER(sku) = LOWER(:identifier) OR LOWER(part_number) = LOWER(:identifier) LIMIT 1'
        );
        $exact->execute([':identifier' => $query]);

        $matchedId = $exact->fetchColumn();

        if ($matchedId !== false) {
            return findInventoryItem($db, (int) $matchedId);
        }

        $normalized = strtolower($query);
        $like = '%' . str_replace(' ', '%', $normalized) . '%';
        $prefix = $normalized . '%';

        $search = $db->prepare(
            'SELECT id FROM inventory_items '
            . 'WHERE LOWER(item) LIKE :like OR LOWER(sku) LIKE :like OR LOWER(part_number) LIKE :like '
            . 'ORDER BY (
                CASE
                    WHEN LOWER(sku) LIKE :prefix OR LOWER(part_number) LIKE :prefix THEN 0
                    WHEN LOWER(item) LIKE :prefix THEN 1
                    ELSE 2
                END
            ), item ASC LIMIT 1'
        );
        $search->execute([
            ':like' => $like,
            ':prefix' => $prefix,
        ]);

        $matchedId = $search->fetchColumn();

        return $matchedId !== false ? findInventoryItem($db, (int) $matchedId) : null;
    }

    /**
     * Record an inventory transaction and persist line adjustments.
     *
     * @param array{
     *   reference:string,
     *   notes:?string,
     *   lines:list<array{item_id:int,quantity_change:int,note:?string}>
     * } $payload
     */
    function recordInventoryTransaction(\PDO $db, array $payload): int
    {
        ensureInventorySchema($db);
        inventoryEnsureTransactionsSchema($db);

        if (empty($payload['lines'])) {
            throw new \InvalidArgumentException('At least one transaction line is required.');
        }

        $startedTransaction = false;

        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $startedTransaction = true;
            }

            $transactionStatement = $db->prepare(
                'INSERT INTO inventory_transactions (reference, notes) VALUES (:reference, :notes) RETURNING id, created_at'
            );
            $transactionStatement->execute([
                ':reference' => $payload['reference'],
                ':notes' => $payload['notes'],
            ]);

            /** @var array{0:int,1:string}|false $transactionRow */
            $transactionRow = $transactionStatement->fetch(\PDO::FETCH_NUM);

            if ($transactionRow === false) {
                throw new \RuntimeException('Unable to create inventory transaction.');
            }

            $transactionId = (int) $transactionRow[0];
            $createdAt = $transactionRow[1] ?? null;
            $usageDate = $createdAt !== null ? substr($createdAt, 0, 10) : date('Y-m-d');

            $lockStatement = $db->prepare('SELECT stock FROM inventory_items WHERE id = :id FOR UPDATE');
            $updateStatement = $db->prepare('UPDATE inventory_items SET stock = :stock WHERE id = :id');
            $lineStatement = $db->prepare(
                'INSERT INTO inventory_transaction_lines (transaction_id, inventory_item_id, quantity_change, note, stock_before, stock_after) '
                . 'VALUES (:transaction_id, :inventory_item_id, :quantity_change, :note, :stock_before, :stock_after)'
            );

            $usageByItem = [];

            foreach ($payload['lines'] as $line) {
                $itemId = (int) $line['item_id'];
                $quantityChange = (int) $line['quantity_change'];
                $note = $line['note'] ?? null;

                $lockStatement->execute([':id' => $itemId]);
                $stockRow = $lockStatement->fetch();

                if ($stockRow === false) {
                    throw new \RuntimeException('Inventory item not found for transaction.');
                }

                $stockBefore = (int) $stockRow['stock'];
                $stockAfter = $stockBefore + $quantityChange;

                if ($stockAfter < 0) {
                    throw new \RuntimeException('Transaction would reduce stock below zero.');
                }

                $updateStatement->execute([
                    ':id' => $itemId,
                    ':stock' => $stockAfter,
                ]);

                $lineStatement->execute([
                    ':transaction_id' => $transactionId,
                    ':inventory_item_id' => $itemId,
                    ':quantity_change' => $quantityChange,
                    ':note' => $note,
                    ':stock_before' => $stockBefore,
                    ':stock_after' => $stockAfter,
                ]);

                if ($quantityChange < 0) {
                    $usageByItem[$itemId] = ($usageByItem[$itemId] ?? 0) + abs($quantityChange);
                }
            }

            if ($usageByItem !== []) {
                inventoryRecordDailyUsage($db, $usageDate, $usageByItem);
                inventoryCalculateAverageDailyUseMap($db, array_keys($usageByItem));
            }

            if ($startedTransaction) {
                $db->commit();
            }

            return $transactionId;
        } catch (\Throwable $exception) {
            if ($db->inTransaction() && $startedTransaction) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Load recent inventory transactions with expanded line items.
     *
     * @return list<array{
     *   id:int,
     *   reference:string,
     *   notes:?string,
     *   created_at:string,
     *   line_count:int,
     *   total_change:int,
     *   lines:list<array{
     *     item_id:int,
     *     sku:string,
     *     item:string,
     *     quantity_change:int,
     *     note:?string,
     *     stock_before:int,
     *     stock_after:int
     *   }>
     * }>
     */
    function loadRecentInventoryTransactions(\PDO $db, int $limit = 10): array
    {
        ensureInventorySchema($db);
        inventoryEnsureTransactionsSchema($db);

        $limit = max(1, $limit);

        $statement = $db->prepare(
            'SELECT id, reference, notes, created_at FROM inventory_transactions ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        /** @var array<int,array<string,mixed>> $transactions */
        $transactions = $statement->fetchAll();

        if ($transactions === []) {
            return [];
        }

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $transactions
        );

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $lineStatement = $db->prepare(
            'SELECT l.transaction_id, l.inventory_item_id, l.quantity_change, l.note, l.stock_before, l.stock_after, '
            . 'i.sku, i.item FROM inventory_transaction_lines l '
            . 'JOIN inventory_items i ON i.id = l.inventory_item_id '
            . 'WHERE l.transaction_id IN (' . $placeholders . ') '
            . 'ORDER BY l.transaction_id DESC, l.id ASC'
        );
        $lineStatement->execute($ids);

        /** @var array<int,array<string,mixed>> $lineRows */
        $lineRows = $lineStatement->fetchAll();

        $grouped = [];

        foreach ($lineRows as $row) {
            $transactionId = (int) $row['transaction_id'];

            if (!isset($grouped[$transactionId])) {
                $grouped[$transactionId] = [];
            }

            $grouped[$transactionId][] = [
                'item_id' => (int) $row['inventory_item_id'],
                'sku' => (string) $row['sku'],
                'item' => (string) $row['item'],
                'quantity_change' => (int) $row['quantity_change'],
                'note' => $row['note'] !== null ? (string) $row['note'] : null,
                'stock_before' => (int) $row['stock_before'],
                'stock_after' => (int) $row['stock_after'],
            ];
        }

        return array_map(
            static function (array $transaction) use ($grouped): array {
                $id = (int) $transaction['id'];
                $lines = $grouped[$id] ?? [];
                $totalChange = 0;

                foreach ($lines as $line) {
                    $totalChange += $line['quantity_change'];
                }

                return [
                    'id' => $id,
                    'reference' => (string) $transaction['reference'],
                    'notes' => $transaction['notes'] !== null ? (string) $transaction['notes'] : null,
                    'created_at' => (string) $transaction['created_at'],
                    'line_count' => count($lines),
                    'total_change' => $totalChange,
                    'lines' => $lines,
                ];
            },
            $transactions
        );
    }

    /**
     * Fetch inventory rows ordered by item name.
     *
     * Available quantities may be negative when commitments exceed on-hand stock.
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
     *   discontinued:bool,
     *   id:int
     * }>
     */
    function loadInventory(\PDO $db): array
    {
        ensureInventorySchema($db);

        try {
            $supportsReservations = inventorySupportsReservations($db);

            $committedSelect = $supportsReservations ? 'COALESCE(commitments.committed_qty, 0)' : '0';
            $activeSelect = $supportsReservations ? 'COALESCE(res.active_reservations, 0)' : '0';
            $availableExpr = 'i.stock - ' . $committedSelect;

            $joinCommitments = $supportsReservations
                ? 'LEFT JOIN inventory_item_commitments commitments ON commitments.inventory_item_id = i.id '
                : '';

            $joinReservations = $supportsReservations
                ? "LEFT JOIN (\n                    SELECT jri.inventory_item_id,\n                        COUNT(*) FILTER (WHERE jr.status IN ('draft', 'committed', 'active', 'in_progress', 'on_hold')) AS active_reservations\n                    FROM job_reservation_items jri\n                    JOIN job_reservations jr ON jr.id = jri.reservation_id\n                    GROUP BY jri.inventory_item_id\n                ) res ON res.inventory_item_id = i.id "
                : '';

            $statement = $db->query(
                'SELECT i.id, i.item, i.sku, i.part_number, i.finish, i.location, i.stock, '
                . $committedSelect . ' AS committed_qty, '
                . $availableExpr . ' AS available_qty, i.status, i.supplier, i.supplier_contact, '
                . 'i.reorder_point, i.lead_time_days, i.average_daily_use, '
                . $activeSelect . ' AS active_reservations '
                . 'FROM inventory_items i '
                . $joinCommitments
                . $joinReservations
                . 'ORDER BY i.item ASC'
            );

            $rows = $statement->fetchAll();

            $idList = array_map(
                static fn (array $row): int => (int) $row['id'],
                $rows
            );

            $averageUsage = inventoryCalculateAverageDailyUseMap($db, $idList);

            return array_map(
                static function (array $row) use ($averageUsage): array {
                    $available = (int) $row['available_qty'];
                    $reorderPoint = (int) $row['reorder_point'];
                    $storedStatus = (string) $row['status'];
                    $id = (int) $row['id'];

                    return [
                        'id' => $id,
                        'item' => (string) $row['item'],
                        'sku' => (string) $row['sku'],
                        'part_number' => (string) $row['part_number'],
                        'finish' => $row['finish'] !== null ? inventoryNormalizeFinish((string) $row['finish']) : null,
                        'location' => (string) $row['location'],
                        'stock' => (int) $row['stock'],
                        'committed_qty' => (int) $row['committed_qty'],
                        'available_qty' => $available,
                        'status' => inventoryResolveStatus($available, $reorderPoint, $storedStatus),
                        'supplier' => (string) $row['supplier'],
                        'supplier_contact' => $row['supplier_contact'] !== null ? (string) $row['supplier_contact'] : null,
                        'reorder_point' => $reorderPoint,
                        'lead_time_days' => (int) $row['lead_time_days'],
                        'average_daily_use' => $averageUsage[$id] ?? null,
                        'active_reservations' => (int) $row['active_reservations'],
                        'discontinued' => inventoryIsDiscontinuedStatus($storedStatus),
                    ];
                },
                $rows
            );
        } catch (\PDOException $exception) {
            throw new \PDOException('Unable to load inventory data: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    /**
     * Summarize inventory stock and reservation activity for dashboards.
     *
     * The aggregated available quantity may be negative when inventory is oversubscribed.
     *
     * @return array{total_stock:int,total_committed:int,total_available:int,active_reservations:int}
     */
    function inventoryReservationSummary(\PDO $db): array
    {
        ensureInventorySchema($db);

        $supportsReservations = inventorySupportsReservations($db);

        try {
            $committedSelect = $supportsReservations ? 'COALESCE(commitments.committed_qty, 0)' : '0';

            $totalsStatement = $db->query(
                'SELECT '
                . 'COALESCE(SUM(i.stock), 0) AS total_stock, '
                . 'COALESCE(SUM(' . $committedSelect . '), 0) AS total_committed, '
                . 'COALESCE(SUM(i.stock - ' . $committedSelect . '), 0) AS total_available '
                . 'FROM inventory_items i '
                . ($supportsReservations ? 'LEFT JOIN inventory_item_commitments commitments ON commitments.inventory_item_id = i.id' : '')
            );

            $totals = $totalsStatement !== false ? (array) $totalsStatement->fetch() : [];
        } catch (\PDOException $exception) {
            throw new \PDOException('Unable to summarize inventory commitments: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $activeReservations = 0;

        if ($supportsReservations) {
            try {
                $reservationStatement = $db->query(
                    "SELECT COUNT(*) AS active_count FROM job_reservations "
                    . "WHERE status IN ('draft', 'committed', 'active', 'in_progress', 'on_hold')"
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
     * Available quantity reflects stock minus global commitments and may be negative.
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
     *   average_daily_use:?float,
     *   active_reservations:int,
     *   discontinued:bool,
     *   id:int
     * }|null
     */
    function findInventoryItem(\PDO $db, int $id): ?array
    {
        ensureInventorySchema($db);

        $supportsReservations = inventorySupportsReservations($db);
        $committedSelect = $supportsReservations ? 'COALESCE(commitments.committed_qty, 0)' : '0';
        $activeSelect = $supportsReservations ? 'COALESCE(res.active_reservations, 0)' : '0';

        $joinCommitments = $supportsReservations
            ? 'LEFT JOIN inventory_item_commitments commitments ON commitments.inventory_item_id = i.id '
            : '';

        $joinClause = $supportsReservations
            ? "LEFT JOIN (\n                SELECT jri.inventory_item_id,\n                    COUNT(*) FILTER (WHERE jr.status IN ('draft', 'committed', 'active', 'in_progress', 'on_hold')) AS active_reservations\n                FROM job_reservation_items jri\n                JOIN job_reservations jr ON jr.id = jri.reservation_id\n                GROUP BY jri.inventory_item_id\n            ) res ON res.inventory_item_id = i.id "
            : '';

        $statement = $db->prepare(
            'SELECT i.id, i.item, i.sku, i.part_number, i.finish, i.location, i.stock, '
            . $committedSelect . ' AS committed_qty, '
            . '(i.stock - ' . $committedSelect . ') AS available_qty, i.status, i.supplier, i.supplier_contact, '
            . 'i.reorder_point, i.lead_time_days, i.average_daily_use, ' . $activeSelect . ' AS active_reservations '
            . 'FROM inventory_items i '
            . $joinCommitments
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

        $available = (int) $row['available_qty'];
        $reorderPoint = (int) $row['reorder_point'];
        $storedStatus = (string) $row['status'];
        $id = (int) $row['id'];

        $averageUsage = inventoryCalculateAverageDailyUseMap($db, [$id]);

        return [
            'id' => $id,
            'item' => (string) $row['item'],
            'sku' => (string) $row['sku'],
            'part_number' => (string) $row['part_number'],
            'finish' => $row['finish'] !== null ? inventoryNormalizeFinish((string) $row['finish']) : null,
            'location' => (string) $row['location'],
            'stock' => (int) $row['stock'],
            'committed_qty' => (int) $row['committed_qty'],
            'available_qty' => $available,
            'status' => inventoryResolveStatus($available, $reorderPoint, $storedStatus),
            'supplier' => (string) $row['supplier'],
            'supplier_contact' => $row['supplier_contact'] !== null ? (string) $row['supplier_contact'] : null,
            'reorder_point' => $reorderPoint,
            'lead_time_days' => (int) $row['lead_time_days'],
            'average_daily_use' => $averageUsage[$id] ?? null,
            'active_reservations' => (int) $row['active_reservations'],
            'discontinued' => inventoryIsDiscontinuedStatus($storedStatus),
        ];
    }

    /**
     * Retrieve an inventory item by SKU.
     *
     * Available quantity reflects stock minus global commitments and may be negative.
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
     *   average_daily_use:?float,
     *   active_reservations:int,
     *   discontinued:bool,
     *   id:int
     * }|null
     */
    function findInventoryItemBySku(\PDO $db, string $sku): ?array
    {
        ensureInventorySchema($db);

        $supportsReservations = inventorySupportsReservations($db);
        $committedSelect = $supportsReservations ? 'COALESCE(commitments.committed_qty, 0)' : '0';
        $activeSelect = $supportsReservations ? 'COALESCE(res.active_reservations, 0)' : '0';

        $joinCommitments = $supportsReservations
            ? 'LEFT JOIN inventory_item_commitments commitments ON commitments.inventory_item_id = i.id '
            : '';

        $joinClause = $supportsReservations
            ? "LEFT JOIN (\n                SELECT jri.inventory_item_id,\n                    COUNT(*) FILTER (WHERE jr.status IN ('draft', 'committed', 'active', 'in_progress', 'on_hold')) AS active_reservations\n                FROM job_reservation_items jri\n                JOIN job_reservations jr ON jr.id = jri.reservation_id\n                GROUP BY jri.inventory_item_id\n            ) res ON res.inventory_item_id = i.id "
            : '';

        $statement = $db->prepare(
            'SELECT i.id, i.item, i.sku, i.part_number, i.finish, i.location, i.stock, '
            . $committedSelect . ' AS committed_qty, '
            . '(i.stock - ' . $committedSelect . ') AS available_qty, i.status, i.supplier, i.supplier_contact, '
            . 'i.reorder_point, i.lead_time_days, i.average_daily_use, ' . $activeSelect . ' AS active_reservations '
            . 'FROM inventory_items i '
            . $joinCommitments
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

        $available = (int) $row['available_qty'];
        $reorderPoint = (int) $row['reorder_point'];
        $storedStatus = (string) $row['status'];
        $id = (int) $row['id'];

        $averageUsage = inventoryCalculateAverageDailyUseMap($db, [$id]);

        return [
            'id' => $id,
            'item' => (string) $row['item'],
            'sku' => (string) $row['sku'],
            'part_number' => (string) $row['part_number'],
            'finish' => $row['finish'] !== null ? inventoryNormalizeFinish((string) $row['finish']) : null,
            'location' => (string) $row['location'],
            'stock' => (int) $row['stock'],
            'committed_qty' => (int) $row['committed_qty'],
            'available_qty' => $available,
            'status' => inventoryResolveStatus($available, $reorderPoint, $storedStatus),
            'supplier' => (string) $row['supplier'],
            'supplier_contact' => $row['supplier_contact'] !== null ? (string) $row['supplier_contact'] : null,
            'reorder_point' => $reorderPoint,
            'lead_time_days' => (int) $row['lead_time_days'],
            'average_daily_use' => $averageUsage[$id] ?? null,
            'active_reservations' => (int) $row['active_reservations'],
            'discontinued' => inventoryIsDiscontinuedStatus($storedStatus),
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
     *   lead_time_days:int
     * } $payload
     */
    function createInventoryItem(\PDO $db, array $payload): int
    {
        ensureInventorySchema($db);

        $statement = $db->prepare(
            'INSERT INTO inventory_items (item, sku, part_number, finish, location, stock, status, supplier, '
            . 'supplier_contact, reorder_point, lead_time_days, average_daily_use) '
            . 'VALUES (:item, :sku, :part_number, :finish, :location, :stock, :status, :supplier, :supplier_contact, '
            . ':reorder_point, :lead_time_days, :average_daily_use) RETURNING id'
        );

        $statement->execute([
            ':item' => $payload['item'],
            ':sku' => $payload['sku'],
            ':part_number' => $payload['part_number'],
            ':finish' => $payload['finish'],
            ':location' => $payload['location'],
            ':stock' => $payload['stock'],
            ':status' => $payload['status'],
            ':supplier' => $payload['supplier'],
            ':supplier_contact' => $payload['supplier_contact'],
            ':reorder_point' => $payload['reorder_point'],
            ':lead_time_days' => $payload['lead_time_days'],
            ':average_daily_use' => null,
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
     *   lead_time_days:int
     * } $payload
     */
    function updateInventoryItem(\PDO $db, int $id, array $payload): void
    {
        ensureInventorySchema($db);

        $statement = $db->prepare(
            'UPDATE inventory_items SET item = :item, sku = :sku, part_number = :part_number, finish = :finish, '
            . 'location = :location, stock = :stock, status = :status, supplier = :supplier, '
            . 'supplier_contact = :supplier_contact, reorder_point = :reorder_point, lead_time_days = :lead_time_days, '
            . 'average_daily_use = :average_daily_use WHERE id = :id'
        );

        $statement->execute([
            ':id' => $id,
            ':item' => $payload['item'],
            ':sku' => $payload['sku'],
            ':part_number' => $payload['part_number'],
            ':finish' => $payload['finish'],
            ':location' => $payload['location'],
            ':stock' => $payload['stock'],
            ':status' => $payload['status'],
            ':supplier' => $payload['supplier'],
            ':supplier_contact' => $payload['supplier_contact'],
            ':reorder_point' => $payload['reorder_point'],
            ':lead_time_days' => $payload['lead_time_days'],
            ':average_daily_use' => null,
        ]);
    }
}
