<?php

declare(strict_types=1);

if (!function_exists('configuratorEnsureSchema')) {
    /**
     * Allowed part type choices for configurator-enabled items.
     *
     * @return list<string>
     */
    function configuratorAllowedPartTypes(): array
    {
        return ['door', 'frame', 'hardware', 'accessory'];
    }

    /**
     * @return array<string,string>
     */
    function configuratorJobScopes(): array
    {
        return [
            'door_and_frame' => 'Door and Frame',
            'frame_only' => 'Frame Only',
            'door_only' => 'Door Only',
        ];
    }

    function configuratorEnsureSchema(\PDO $db): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $db->exec(
            'CREATE TABLE IF NOT EXISTS configurator_part_use_options (
                id BIGSERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                parent_id BIGINT NULL REFERENCES configurator_part_use_options(id) ON DELETE SET NULL
            )'
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS configurator_part_profiles (
                inventory_item_id BIGINT PRIMARY KEY REFERENCES inventory_items(id) ON DELETE CASCADE,
                is_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                part_type TEXT NULL,
                height_lz NUMERIC(12,4) NULL,
                depth_ly NUMERIC(12,4) NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT configurator_part_profiles_part_type_check
                    CHECK (part_type IS NULL OR part_type IN ('door', 'frame', 'hardware', 'accessory')),
                CONSTRAINT configurator_part_profiles_height_lz_check
                    CHECK (height_lz IS NULL OR height_lz > 0),
                CONSTRAINT configurator_part_profiles_depth_ly_check
                    CHECK (depth_ly IS NULL OR depth_ly > 0)
            )"
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS configurator_part_use_links (
                inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
                use_option_id BIGINT NOT NULL REFERENCES configurator_part_use_options(id) ON DELETE CASCADE,
                PRIMARY KEY (inventory_item_id, use_option_id)
            )'
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS configurator_part_requirements (
                inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
                required_inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
                quantity INTEGER NOT NULL DEFAULT 1,
                finish_policy TEXT NOT NULL DEFAULT \'fixed\',
                fixed_finish TEXT NULL,
                PRIMARY KEY (inventory_item_id, required_inventory_item_id)
            )'
        );

        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'configurator_part_use_links' AND column_name = 'id'
                ) THEN
                    ALTER TABLE configurator_part_use_links ADD COLUMN id BIGSERIAL;
                END IF;
            END$$;"
        );

        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'configurator_part_requirements' AND column_name = 'id'
                ) THEN
                    ALTER TABLE configurator_part_requirements ADD COLUMN id BIGSERIAL;
                END IF;
            END$$;"
        );

        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS quantity INTEGER NOT NULL DEFAULT 1"
        );
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS finish_policy TEXT NOT NULL DEFAULT 'fixed'"
        );
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS fixed_finish TEXT NULL"
        );

        $db->exec(
            "ALTER TABLE configurator_part_use_options
                ADD COLUMN IF NOT EXISTS parent_id BIGINT NULL REFERENCES configurator_part_use_options(id) ON DELETE SET NULL"
        );

        $db->exec(
            "DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'configurator_part_use_options_name_key'
                        OR (conrelid = 'configurator_part_use_options'::regclass AND contype = 'u')
                ) THEN
                    ALTER TABLE configurator_part_use_options DROP CONSTRAINT IF EXISTS configurator_part_use_options_name_key;
                END IF;
            END$$;"
        );

        $db->exec(
            "CREATE INDEX IF NOT EXISTS idx_configurator_part_use_options_parent_id
                ON configurator_part_use_options(parent_id)"
        );

        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_configurator_part_use_links_id
                ON configurator_part_use_links(id)'
        );

        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_configurator_part_requirements_id
                ON configurator_part_requirements(id)'
        );

        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'configurator_part_requirements_quantity_check'
                ) THEN
                    ALTER TABLE configurator_part_requirements
                        ADD CONSTRAINT configurator_part_requirements_quantity_check CHECK (quantity > 0);
                END IF;
            END$$;"
        );

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_configurator_part_requirements_required
                ON configurator_part_requirements(required_inventory_item_id)'
        );

        // Context-aware requirements columns
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS applies_when_opening_type TEXT NULL"
        );
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS applies_when_hand TEXT NULL"
        );
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS applies_when_hinging TEXT NULL"
        );
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS applies_when_job_scope TEXT NULL"
        );
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS target_component TEXT NOT NULL DEFAULT 'parent'"
        );
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS auto_add BOOLEAN NOT NULL DEFAULT TRUE"
        );
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS allow_removal BOOLEAN NOT NULL DEFAULT FALSE"
        );
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS priority INTEGER NOT NULL DEFAULT 0"
        );
        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS fallback_item_id BIGINT NULL"
        );

        // Add constraints for context-aware requirements
        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'configurator_part_requirements_opening_type_check'
                ) THEN
                    ALTER TABLE configurator_part_requirements
                        ADD CONSTRAINT configurator_part_requirements_opening_type_check
                        CHECK (applies_when_opening_type IS NULL OR applies_when_opening_type IN ('single', 'pair'));
                END IF;
            END$$;"
        );

        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'configurator_part_requirements_target_check'
                ) THEN
                    ALTER TABLE configurator_part_requirements
                        ADD CONSTRAINT configurator_part_requirements_target_check
                        CHECK (target_component IN ('parent', 'active_door', 'inactive_door', 'lock_jamb',
                                                    'hinge_jamb', 'door_head', 'frame', 'door', 'transom'));
                END IF;
            END$$;"
        );

        // Add foreign key constraint for fallback_item_id
        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'configurator_part_requirements_fallback_fkey'
                ) THEN
                    ALTER TABLE configurator_part_requirements
                        ADD CONSTRAINT configurator_part_requirements_fallback_fkey
                        FOREIGN KEY (fallback_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL;
                END IF;
            END$$;"
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS configurator_jobs (
                id BIGSERIAL PRIMARY KEY,
                job_number TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )'
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS configurator_configurations (
                id BIGSERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                job_id BIGINT NULL REFERENCES configurator_jobs(id) ON DELETE SET NULL,
                job_scope TEXT NOT NULL DEFAULT 'door_and_frame',
                quantity INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'draft',
                notes TEXT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );

        $db->exec(
            "ALTER TABLE configurator_configurations
                ADD COLUMN IF NOT EXISTS job_scope TEXT NOT NULL DEFAULT 'door_and_frame'"
        );

        $db->exec(
            "ALTER TABLE configurator_part_profiles
                ADD COLUMN IF NOT EXISTS height_lz NUMERIC(12,4) NULL"
        );

        $db->exec(
            "ALTER TABLE configurator_part_profiles
                ADD COLUMN IF NOT EXISTS depth_ly NUMERIC(12,4) NULL"
        );

        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'configurator_part_profiles_inventory_item_id_key'
                ) THEN
                    ALTER TABLE configurator_part_profiles
                        ADD CONSTRAINT configurator_part_profiles_inventory_item_id_key UNIQUE (inventory_item_id);
                END IF;
            END$$;"
        );

        $db->exec(
            "ALTER TABLE configurator_configurations
                ADD COLUMN IF NOT EXISTS quantity INTEGER NOT NULL DEFAULT 1"
        );

        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'configurator_configurations_quantity_check'
                ) THEN
                    ALTER TABLE configurator_configurations
                        ADD CONSTRAINT configurator_configurations_quantity_check CHECK (quantity > 0);
                END IF;
            END$$;"
        );

        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'configurator_part_profiles_height_lz_check'
                ) THEN
                    ALTER TABLE configurator_part_profiles
                        ADD CONSTRAINT configurator_part_profiles_height_lz_check CHECK (height_lz IS NULL OR height_lz > 0);
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'configurator_part_profiles_depth_ly_check'
                ) THEN
                    ALTER TABLE configurator_part_profiles
                        ADD CONSTRAINT configurator_part_profiles_depth_ly_check CHECK (depth_ly IS NULL OR depth_ly > 0);
                END IF;
            END$$;"
        );

        $db->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'configurator_configurations_job_scope_check'
                ) THEN
                    ALTER TABLE configurator_configurations
                        ADD CONSTRAINT configurator_configurations_job_scope_check
                        CHECK (job_scope IN ('door_and_frame', 'frame_only', 'door_only'));
                END IF;
            END$$;"
        );

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_configurator_configurations_job_id
                ON configurator_configurations(job_id)'
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS configurator_configuration_doors (
                id BIGSERIAL PRIMARY KEY,
                configuration_id BIGINT NOT NULL REFERENCES configurator_configurations(id) ON DELETE CASCADE,
                door_tag TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (configuration_id, door_tag)
            )'
        );

        $seed = $db->prepare(
            'INSERT INTO configurator_part_use_options (name, parent_id)
             SELECT :name, :parent_id
             WHERE NOT EXISTS (
                 SELECT 1 FROM configurator_part_use_options
                 WHERE name = :name AND parent_id IS NOT DISTINCT FROM :parent_id
             )'
        );
        $parentLookup = $db->prepare(
            'SELECT id FROM configurator_part_use_options
             WHERE name = :name AND parent_id IS NOT DISTINCT FROM :parent_id
             ORDER BY id ASC
             LIMIT 1'
        );

        $seedOptions = [
            ['name' => 'Door', 'parent' => null],
            ['name' => 'Frame', 'parent' => null],
            ['name' => 'Hardware', 'parent' => null],
            ['name' => 'Accessory', 'parent' => null],
            ['name' => 'Interior Opening', 'parent' => 'Door'],
            ['name' => 'Exterior Opening', 'parent' => 'Door'],
            ['name' => 'Fire Rated', 'parent' => 'Door'],
            ['name' => 'Pair Door', 'parent' => 'Door'],
            ['name' => 'Single Door', 'parent' => 'Door'],
            ['name' => 'Stiles', 'parent' => 'Door'],
            ['name' => 'Hinge Rail', 'parent' => 'Stiles'],
            ['name' => 'Lock Rail', 'parent' => 'Stiles'],
            ['name' => 'Top Rail', 'parent' => 'Stiles'],
            ['name' => 'Bottom Rail', 'parent' => 'Stiles'],
            ['name' => 'Glazing', 'parent' => 'Door'],
            ['name' => 'Interior Glass Stop', 'parent' => 'Glazing'],
            ['name' => 'Exterior Glass Stop', 'parent' => 'Glazing'],
            ['name' => 'Interior Glass Vinyl', 'parent' => 'Glazing'],
            ['name' => 'Exterior Glass Vinyl', 'parent' => 'Glazing'],
            ['name' => 'Door Set block', 'parent' => 'Glazing'],
            ['name' => 'Door glass jack', 'parent' => 'Glazing'],
            ['name' => 'Frame Jambs', 'parent' => 'Frame'],
            ['name' => 'Hinge Jamb', 'parent' => 'Frame Jambs'],
            ['name' => 'Lock Jamb', 'parent' => 'Frame Jambs'],
            ['name' => 'Frame Head', 'parent' => 'Frame'],
            ['name' => 'Door Head', 'parent' => 'Frame Head'],
            ['name' => 'Frame Stops', 'parent' => 'Frame'],
            ['name' => 'Head Door Stop', 'parent' => 'Frame Stops'],
            ['name' => 'Lock Door Stop', 'parent' => 'Frame Stops'],
            ['name' => 'Hinge Door Stop', 'parent' => 'Frame Stops'],
            ['name' => 'Transom', 'parent' => 'Frame'],
            ['name' => 'Door Head Transom Stop - Active', 'parent' => 'Transom'],
            ['name' => 'Door Head Transom Stop - Fixed', 'parent' => 'Transom'],
            ['name' => 'Vertical Transom Stop - Active', 'parent' => 'Transom'],
            ['name' => 'Vertical Transom Stop - Fixed', 'parent' => 'Transom'],
            ['name' => '½ Glass adapter', 'parent' => 'Transom'],
            ['name' => '¼ Glass adapter', 'parent' => 'Transom'],
            ['name' => 'Pair Frame Rails', 'parent' => 'Frame'],
            ['name' => 'LH Hinge Rail', 'parent' => 'Pair Frame Rails'],
            ['name' => 'RH Hinge Rail', 'parent' => 'Pair Frame Rails'],
        ];

        $resolvedParents = [];

        foreach ($seedOptions as $option) {
            $parentId = null;

            if ($option['parent'] !== null) {
                $parentId = $resolvedParents[$option['parent']] ?? null;

                if ($parentId === null) {
                    $parentLookup->execute([
                        ':name' => $option['parent'],
                        ':parent_id' => $resolvedParents[$option['parent']] ?? null,
                    ]);
                    $parentCandidate = $parentLookup->fetchColumn();
                    if ($parentCandidate !== false) {
                        $parentId = (int) $parentCandidate;
                    }
                }
            }

            $seed->execute([
                ':name' => $option['name'],
                ':parent_id' => $parentId,
            ]);

            $parentLookup->execute([
                ':name' => $option['name'],
                ':parent_id' => $parentId,
            ]);
            $insertedId = $parentLookup->fetchColumn();
            if ($insertedId !== false) {
                $resolvedParents[$option['name']] = (int) $insertedId;
            }
        }

        $ensured = true;
    }

    /**
     * @return list<array{id:int,name:string,parent_id:int|null}>
     */
    function configuratorListUseOptions(\PDO $db): array
    {
        configuratorEnsureSchema($db);

        $statement = $db->query('SELECT id, name, parent_id FROM configurator_part_use_options ORDER BY name ASC');
        if ($statement === false) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return array<int,array{id:int,name:string,parent_id:int|null}>
     */
    function configuratorUseOptionsMap(\PDO $db): array
    {
        $options = configuratorListUseOptions($db);
        $map = [];

        foreach ($options as $option) {
            $map[$option['id']] = $option;
        }

        return $map;
    }

    function configuratorResolveUsePath(\PDO $db, array $path): ?int
    {
        if ($path === []) {
            return null;
        }

        $lookup = $db->prepare(
            'SELECT id FROM configurator_part_use_options
             WHERE name = :name AND parent_id IS NOT DISTINCT FROM :parent_id
             ORDER BY id ASC
             LIMIT 1'
        );

        $parentId = null;

        foreach ($path as $name) {
            $lookup->execute([
                ':name' => $name,
                ':parent_id' => $parentId,
            ]);

            $found = $lookup->fetchColumn();
            if ($found === false) {
                return null;
            }

            $parentId = (int) $found;
        }

        return $parentId;
    }

    /**
     * @param list<string> $usePath
     * @return list<array{id:int,label:string}>
     */
    function configuratorInventoryOptionsByUse(
        \PDO $db,
        array $usePath,
        string $partType,
        ?int $systemId = null,
        ?string $finish = null
    ): array
    {
        configuratorEnsureSchema($db);

        $useId = configuratorResolveUsePath($db, $usePath);
        if ($useId === null) {
            return [];
        }

        $normalizedFinish = inventoryNormalizeFinish($finish);

        $sql = 'SELECT ii.id, ii.sku, ii.item, cpp.height_lz, cpp.depth_ly
                FROM configurator_part_use_links cpul
                JOIN configurator_part_profiles cpp ON cpp.inventory_item_id = cpul.inventory_item_id AND cpp.is_enabled = TRUE AND cpp.part_type = :part_type
                JOIN inventory_items ii ON ii.id = cpp.inventory_item_id';

        $params = [
            ':use_id' => $useId,
            ':part_type' => $partType,
        ];

        if ($systemId !== null) {
            $sql .= ' JOIN inventory_item_systems iis ON iis.inventory_item_id = ii.id AND iis.system_id = :system_id';
            $params[':system_id'] = $systemId;
        }

        $sql .= ' WHERE cpul.use_option_id = :use_id';

        if ($normalizedFinish !== null) {
            $sql .= ' AND UPPER(ii.finish) = :finish';
            $params[':finish'] = $normalizedFinish;
        }

        $sql .= ' ORDER BY ii.item ASC';

        $statement = $db->prepare($sql);

        $statement->execute($params);

        return array_map(
            static function (array $row): array {
                $sku = trim((string) $row['sku']);
                $item = trim((string) $row['item']);
                $label = $sku !== '' ? $sku . ' – ' . $item : $item;

                return [
                    'id' => (int) $row['id'],
                    'label' => $label,
                    'height_lz' => $row['height_lz'] !== null ? (float) $row['height_lz'] : null,
                    'depth_ly' => $row['depth_ly'] !== null ? (float) $row['depth_ly'] : null,
                ];
            },
            $statement->fetchAll()
        );
    }

    /**
     * Infer the configurator part type from the selected use IDs.
     *
     * @param list<int> $useIds
     * @param array<int,array{id:int,name:string,parent_id:int|null}> $useMap
     */
    function configuratorInferPartType(array $useIds, array $useMap): ?string
    {
        $allowed = configuratorAllowedPartTypes();
        $detected = [];

        foreach ($useIds as $useId) {
            if (!isset($useMap[$useId])) {
                continue;
            }

            $current = $useMap[$useId];
            while ($current['parent_id'] !== null && isset($useMap[$current['parent_id']])) {
                $current = $useMap[$current['parent_id']];
            }

            $rootName = strtolower(trim((string) $current['name']));
            foreach ($allowed as $type) {
                if ($rootName === $type) {
                    $detected[$type] = true;
                }
            }
        }

        if (count($detected) !== 1) {
            return null;
        }

        return array_keys($detected)[0];
    }

    /**
     * Lightweight inventory listing for requirement dropdowns.
     *
     * @return list<array{id:int,label:string}>
     */
    function configuratorInventoryOptions(\PDO $db): array
    {
        configuratorEnsureSchema($db);

        $statement = $db->query(
            'SELECT ii.id, ii.sku, ii.item, ii.part_number, ii.finish
             FROM inventory_items ii
             JOIN configurator_part_profiles cpp ON cpp.inventory_item_id = ii.id AND cpp.is_enabled = TRUE
             ORDER BY ii.item ASC'
        );

        if ($statement === false) {
            return [];
        }

        return array_map(
            static function (array $row): array {
                $sku = trim((string) $row['sku']);
                $item = trim((string) $row['item']);
                $label = $sku !== '' ? $sku . ' – ' . $item : $item;

                return [
                    'id' => (int) $row['id'],
                    'label' => $label,
                    'sku' => $sku,
                    'item' => $item,
                    'part_number' => isset($row['part_number']) ? (string) $row['part_number'] : '',
                    'finish' => $row['finish'] !== null ? (string) $row['finish'] : '',
                ];
            },
            $statement->fetchAll()
        );
    }

    /**
     * @param list<int> $itemIds
     * @return array<int,array{id:int,sku:string,item:string,part_number:string,finish:?string}>
     */
    function configuratorLoadInventoryItemsByIds(\PDO $db, array $itemIds): array
    {
        configuratorEnsureSchema($db);

        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($itemIds), '?'));
        $statement = $db->prepare(
            'SELECT id, sku, item, part_number, finish
             FROM inventory_items
             WHERE id IN (' . $placeholders . ')'
        );
        $statement->execute($itemIds);

        $rows = $statement->fetchAll();
        $map = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $map[$id] = [
                'id' => $id,
                'sku' => (string) $row['sku'],
                'item' => (string) $row['item'],
                'part_number' => (string) $row['part_number'],
                'finish' => $row['finish'] !== null ? (string) $row['finish'] : null,
            ];
        }

        return $map;
    }

    /**
     * @param list<int> $itemIds
     * @return list<array{
     *   inventory_item_id:int,
     *   required_inventory_item_id:int,
     *   quantity:int,
     *   finish_policy:string,
     *   fixed_finish:?string,
     *   required_part_number:string,
     *   required_finish:?string,
     *   required_sku:string,
     *   required_item:string
     * }>
     */
    function configuratorLoadRequirementsForItems(\PDO $db, array $itemIds): array
    {
        configuratorEnsureSchema($db);

        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($itemIds), '?'));
        $statement = $db->prepare(
            'SELECT r.inventory_item_id,
                    r.required_inventory_item_id,
                    r.quantity,
                    r.finish_policy,
                    r.fixed_finish,
                    ii.part_number AS required_part_number,
                    ii.finish AS required_finish,
                    ii.sku AS required_sku,
                    ii.item AS required_item
             FROM configurator_part_requirements r
             JOIN inventory_items ii ON ii.id = r.required_inventory_item_id
             WHERE r.inventory_item_id IN (' . $placeholders . ')'
        );
        $statement->execute($itemIds);

        $rows = $statement->fetchAll();
        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'inventory_item_id' => (int) $row['inventory_item_id'],
                'required_inventory_item_id' => (int) $row['required_inventory_item_id'],
                'quantity' => max(1, (int) $row['quantity']),
                'finish_policy' => configuratorNormalizeFinishPolicy($row['finish_policy'] ?? null),
                'fixed_finish' => isset($row['fixed_finish']) && $row['fixed_finish'] !== null
                    ? (string) $row['fixed_finish']
                    : null,
                'required_part_number' => (string) $row['required_part_number'],
                'required_finish' => $row['required_finish'] !== null ? (string) $row['required_finish'] : null,
                'required_sku' => (string) $row['required_sku'],
                'required_item' => (string) $row['required_item'],
            ];
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    function configuratorRequirementFinishPolicies(): array
    {
        return ['fixed', 'match_frame', 'match_door'];
    }

    function configuratorNormalizeFinishPolicy(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = configuratorRequirementFinishPolicies();

        return in_array($value, $allowed, true) ? $value : 'fixed';
    }

    /**
     * @return array{enabled:bool,part_type:?string,height_lz:?float,depth_ly:?float,use_ids:list<int>,requirements:list<array{item_id:int,quantity:int,finish_policy:string,fixed_finish:?string}>}
     */
    function configuratorLoadPartProfile(\PDO $db, int $inventoryItemId): array
    {
        configuratorEnsureSchema($db);

        $profileStatement = $db->prepare(
            'SELECT is_enabled, part_type, height_lz, depth_ly FROM configurator_part_profiles WHERE inventory_item_id = :item_id'
        );
        $profileStatement->execute([':item_id' => $inventoryItemId]);
        $profile = $profileStatement->fetch();

        $useStatement = $db->prepare(
            'SELECT use_option_id FROM configurator_part_use_links WHERE inventory_item_id = :item_id'
        );
        $useStatement->execute([':item_id' => $inventoryItemId]);
        $useIds = array_map('intval', $useStatement->fetchAll(\PDO::FETCH_COLUMN));

        $requiresStatement = $db->prepare(
            'SELECT required_inventory_item_id, quantity, finish_policy, fixed_finish
             FROM configurator_part_requirements
             WHERE inventory_item_id = :item_id'
        );
        $requiresStatement->execute([':item_id' => $inventoryItemId]);
        $requiredParts = array_map(
            static fn (array $row): array => [
                'item_id' => (int) $row['required_inventory_item_id'],
                'quantity' => max(1, (int) $row['quantity']),
                'finish_policy' => configuratorNormalizeFinishPolicy($row['finish_policy'] ?? null),
                'fixed_finish' => isset($row['fixed_finish']) && $row['fixed_finish'] !== null
                    ? (string) $row['fixed_finish']
                    : null,
            ],
            $requiresStatement->fetchAll()
        );

        return [
            'enabled' => $profile !== false ? (bool) $profile['is_enabled'] : false,
            'part_type' => $profile !== false && $profile['part_type'] !== null
                ? (string) $profile['part_type']
                : null,
            'height_lz' => $profile !== false && $profile['height_lz'] !== null
                ? (float) $profile['height_lz']
                : null,
            'depth_ly' => $profile !== false && $profile['depth_ly'] !== null
                ? (float) $profile['depth_ly']
                : null,
            'use_ids' => $useIds,
            'requirements' => $requiredParts,
        ];
    }

    /**
     * Persist configurator metadata for an inventory item.
     *
     * @param list<int> $useIds
     * @param list<array{item_id:int,quantity:int,finish_policy?:string,fixed_finish?:?string}> $requiredItems
     */
    function configuratorSyncPartProfile(
        \PDO $db,
        int $inventoryItemId,
        bool $enabled,
        ?string $partType,
        array $useIds,
        array $requiredItems,
        ?float $heightLz = null,
        ?float $depthLy = null
    ): void {
        configuratorEnsureSchema($db);

        $normalizedType = $partType !== null && in_array($partType, configuratorAllowedPartTypes(), true)
            ? $partType
            : null;

        $startedTransaction = dbBeginTransactionSafe($db);

        try {
            $profileStatement = $db->prepare(
                'INSERT INTO configurator_part_profiles (inventory_item_id, is_enabled, part_type, height_lz, depth_ly)
                 VALUES (:id, :enabled, :type, :height_lz, :depth_ly)
                 ON CONFLICT (inventory_item_id)
                 DO UPDATE SET is_enabled = EXCLUDED.is_enabled, part_type = EXCLUDED.part_type, height_lz = EXCLUDED.height_lz, depth_ly = EXCLUDED.depth_ly'
            );
            $profileStatement->execute([
                ':id' => $inventoryItemId,
                ':enabled' => $enabled ? 'true' : 'false',
                ':type' => $enabled ? $normalizedType : null,
                ':height_lz' => $enabled ? $heightLz : null,
                ':depth_ly' => $enabled ? $depthLy : null,
            ]);

            $db->prepare('DELETE FROM configurator_part_use_links WHERE inventory_item_id = :id')
                ->execute([':id' => $inventoryItemId]);
            $db->prepare('DELETE FROM configurator_part_requirements WHERE inventory_item_id = :id')
                ->execute([':id' => $inventoryItemId]);

            if ($enabled && $useIds !== []) {
                $useInsert = $db->prepare(
                    'INSERT INTO configurator_part_use_links (inventory_item_id, use_option_id)
                     VALUES (:item_id, :use_id)'
                );

                $uniqueUseIds = array_values(array_unique(array_map('intval', $useIds)));
                foreach ($uniqueUseIds as $useId) {
                    $useInsert->execute([
                        ':item_id' => $inventoryItemId,
                        ':use_id' => $useId,
                    ]);
                }
            }

            if ($enabled && $requiredItems !== []) {
                $requireInsert = $db->prepare(
                    'INSERT INTO configurator_part_requirements (
                        inventory_item_id,
                        required_inventory_item_id,
                        quantity,
                        finish_policy,
                        fixed_finish
                     )
                     VALUES (:item_id, :required_id, :quantity, :finish_policy, :fixed_finish)'
                );

                $uniqueRequired = [];

                foreach ($requiredItems as $requirement) {
                    $requiredId = (int) $requirement['item_id'];
                    $quantity = max(1, (int) $requirement['quantity']);
                    $finishPolicy = configuratorNormalizeFinishPolicy($requirement['finish_policy'] ?? null);
                    $fixedFinish = isset($requirement['fixed_finish']) ? trim((string) $requirement['fixed_finish']) : '';
                    $fixedFinish = $fixedFinish !== '' ? $fixedFinish : null;

                    if ($requiredId === $inventoryItemId) {
                        continue;
                    }

                    if (!isset($uniqueRequired[$requiredId])) {
                        $uniqueRequired[$requiredId] = [
                            'quantity' => $quantity,
                            'finish_policy' => $finishPolicy,
                            'fixed_finish' => $fixedFinish,
                        ];
                    } else {
                        $uniqueRequired[$requiredId]['quantity'] += $quantity;
                        if ($uniqueRequired[$requiredId]['fixed_finish'] === null && $fixedFinish !== null) {
                            $uniqueRequired[$requiredId]['fixed_finish'] = $fixedFinish;
                        }
                    }
                }

                foreach ($uniqueRequired as $requiredId => $payload) {
                    $requireInsert->execute([
                        ':item_id' => $inventoryItemId,
                        ':required_id' => $requiredId,
                        ':quantity' => $payload['quantity'],
                        ':finish_policy' => $payload['finish_policy'],
                        ':fixed_finish' => $payload['fixed_finish'],
                    ]);
                }
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return list<array{id:int,job_number:string,name:string,created_at:string}>
     */
    function configuratorListJobs(\PDO $db): array
    {
        configuratorEnsureSchema($db);

        $statement = $db->query(
            'SELECT id, job_number, name, created_at FROM configurator_jobs ORDER BY created_at DESC, job_number ASC'
        );

        if ($statement === false) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'job_number' => (string) $row['job_number'],
                'name' => (string) $row['name'],
                'created_at' => (string) $row['created_at'],
            ],
            $statement->fetchAll()
        );
    }

    function configuratorCreateJob(\PDO $db, string $jobNumber, string $name): int
    {
        configuratorEnsureSchema($db);

        $statement = $db->prepare(
            'INSERT INTO configurator_jobs (job_number, name) VALUES (:number, :name) RETURNING id'
        );
        $statement->execute([
            ':number' => $jobNumber,
            ':name' => $name,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array{id:int,name:string,job_id:?int,job_number:?string,job_name:?string,job_scope:string,quantity:int,status:string,notes:?string,updated_at:string,door_tags:list<string>}>
     */
    function configuratorListConfigurations(\PDO $db): array
    {
        configuratorEnsureSchema($db);

        $statement = $db->query(
            "SELECT cc.id,
                    cc.name,
                    cc.job_id,
                    cc.job_scope,
                    cc.quantity,
                    cc.status,
                    cc.notes,
                    cc.updated_at,
                    cj.job_number,
                    cj.name AS job_name,
                    COALESCE(ARRAY_REMOVE(ARRAY_AGG(ccd.door_tag ORDER BY ccd.door_tag), NULL), '{}') AS door_tags
             FROM configurator_configurations cc
             LEFT JOIN configurator_jobs cj ON cj.id = cc.job_id
             LEFT JOIN configurator_configuration_doors ccd ON ccd.configuration_id = cc.id
             GROUP BY cc.id, cj.id, cj.job_number, cj.name
             ORDER BY cc.updated_at DESC, cc.id DESC"
        );

        if ($statement === false) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'job_id' => $row['job_id'] !== null ? (int) $row['job_id'] : null,
                'job_number' => $row['job_number'] !== null ? (string) $row['job_number'] : null,
                'job_name' => $row['job_name'] !== null ? (string) $row['job_name'] : null,
                'job_scope' => (string) $row['job_scope'],
                'quantity' => max(1, (int) $row['quantity']),
                'status' => (string) $row['status'],
                'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
                'updated_at' => (string) $row['updated_at'],
                'door_tags' => array_values(array_filter(
                    array_map('strval', is_array($row['door_tags']) ? $row['door_tags'] : []),
                    static fn (string $tag): bool => $tag !== ''
                )),
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return array{id:int,name:string,job_id:?int,job_scope:string,quantity:int,status:string,notes:?string,door_tags:list<string>}|null
     */
    function configuratorFindConfiguration(\PDO $db, int $id): ?array
    {
        configuratorEnsureSchema($db);

        $statement = $db->prepare(
            'SELECT id, name, job_id, job_scope, quantity, status, notes FROM configurator_configurations WHERE id = :id'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'job_id' => $row['job_id'] !== null ? (int) $row['job_id'] : null,
            'job_scope' => (string) $row['job_scope'],
            'quantity' => max(1, (int) $row['quantity']),
            'status' => (string) $row['status'],
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'door_tags' => configuratorLoadConfigurationDoorTags($db, (int) $row['id']),
        ];
    }

    /**
     * @param array{name:string,job_id:?int,job_scope:string,quantity:int,status:string,notes:?string,door_tags:list<string>} $payload
     */
    function configuratorCreateConfiguration(\PDO $db, array $payload): int
    {
        configuratorEnsureSchema($db);

        $db->beginTransaction();

        try {
            $statement = $db->prepare(
                'INSERT INTO configurator_configurations (name, job_id, job_scope, quantity, status, notes)
                 VALUES (:name, :job_id, :job_scope, :quantity, :status, :notes) RETURNING id'
            );
            $statement->execute([
                ':name' => $payload['name'],
                ':job_id' => $payload['job_id'],
                ':job_scope' => $payload['job_scope'],
                ':quantity' => $payload['quantity'],
                ':status' => $payload['status'],
                ':notes' => $payload['notes'],
            ]);

            $configId = (int) $statement->fetchColumn();

            configuratorSyncConfigurationDoorTags($db, $configId, $payload['door_tags']);

            $db->commit();

            return $configId;
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array{name:string,job_id:?int,job_scope:string,quantity:int,status:string,notes:?string,door_tags:list<string>} $payload
     */
    function configuratorUpdateConfiguration(\PDO $db, int $id, array $payload): void
    {
        configuratorEnsureSchema($db);

        $db->beginTransaction();

        try {
            $statement = $db->prepare(
                'UPDATE configurator_configurations
                 SET name = :name,
                     job_id = :job_id,
                     job_scope = :job_scope,
                     quantity = :quantity,
                     status = :status,
                     notes = :notes,
                     updated_at = NOW()
                 WHERE id = :id'
            );

            $statement->execute([
                ':id' => $id,
                ':name' => $payload['name'],
                ':job_id' => $payload['job_id'],
                ':job_scope' => $payload['job_scope'],
                ':quantity' => $payload['quantity'],
                ':status' => $payload['status'],
                ':notes' => $payload['notes'],
            ]);

            configuratorSyncConfigurationDoorTags($db, $id, $payload['door_tags']);

            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    /**
     * @return list<string>
     */
    function configuratorLoadConfigurationDoorTags(\PDO $db, int $configurationId): array
    {
        configuratorEnsureSchema($db);

        $statement = $db->prepare(
            'SELECT door_tag FROM configurator_configuration_doors WHERE configuration_id = :id ORDER BY door_tag ASC'
        );
        $statement->execute([':id' => $configurationId]);

        return array_values(array_filter(
            array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)),
            static fn (string $tag): bool => $tag !== ''
        ));
    }

    /**
     * @param list<string> $doorTags
     */
    function configuratorSyncConfigurationDoorTags(\PDO $db, int $configurationId, array $doorTags): void
    {
        $db->prepare('DELETE FROM configurator_configuration_doors WHERE configuration_id = :id')
            ->execute([':id' => $configurationId]);

        if ($doorTags === []) {
            return;
        }

        $insert = $db->prepare(
            'INSERT INTO configurator_configuration_doors (configuration_id, door_tag)
             VALUES (:configuration_id, :door_tag)'
        );

        $uniqueTags = [];

        foreach ($doorTags as $tag) {
            $normalized = trim((string) $tag);

            if ($normalized === '') {
                continue;
            }

            $uniqueTags[$normalized] = true;
        }

        foreach (array_keys($uniqueTags) as $tag) {
            $insert->execute([
                ':configuration_id' => $configurationId,
                ':door_tag' => $tag,
            ]);
        }
    }

    /**
     * @return list<array{door_id:int,door_tag:string,configuration_id:int,configuration_name:string,job_number:?string}>
     */
    function configuratorListDoorTagTemplates(\PDO $db): array
    {
        configuratorEnsureSchema($db);

        $statement = $db->query(
            'SELECT ccd.id AS door_id, ccd.door_tag, ccd.configuration_id, cc.name AS configuration_name, cj.job_number
             FROM configurator_configuration_doors ccd
             JOIN configurator_configurations cc ON cc.id = ccd.configuration_id
             LEFT JOIN configurator_jobs cj ON cj.id = cc.job_id
             ORDER BY ccd.door_tag ASC'
        );

        if ($statement === false) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'door_id' => (int) $row['door_id'],
                'door_tag' => (string) $row['door_tag'],
                'configuration_id' => (int) $row['configuration_id'],
                'configuration_name' => (string) $row['configuration_name'],
                'job_number' => $row['job_number'] !== null ? (string) $row['job_number'] : null,
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return array{door_tag:string,configuration_id:int,configuration_name:string,job_id:?int,job_scope:string,status:string,notes:?string}|null
     */
    function configuratorFindDoorTagTemplate(\PDO $db, int $doorId): ?array
    {
        configuratorEnsureSchema($db);

        $statement = $db->prepare(
            'SELECT ccd.door_tag, ccd.configuration_id, cc.name AS configuration_name, cc.job_id, cc.job_scope, cc.status, cc.notes
             FROM configurator_configuration_doors ccd
             JOIN configurator_configurations cc ON cc.id = ccd.configuration_id
             WHERE ccd.id = :door_id'
        );
        $statement->execute([':door_id' => $doorId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'door_tag' => (string) $row['door_tag'],
            'configuration_id' => (int) $row['configuration_id'],
            'configuration_name' => (string) $row['configuration_name'],
            'job_id' => $row['job_id'] !== null ? (int) $row['job_id'] : null,
            'job_scope' => (string) $row['job_scope'],
            'status' => (string) $row['status'],
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
        ];
    }

    /**
     * Get all applicable requirements for a part given the current configuration context.
     * Handles conditional matching and multi-level dependencies.
     *
     * @param array<string,mixed> $context Context with keys: opening_type, hand, hinging, job_scope, frame_finish, door_finish
     * @param int $depth Current recursion depth for preventing infinite loops
     * @param list<int> $visited Already visited part IDs to prevent cycles
     * @return list<array{required_item_id:int,required_item_name:string,quantity:int,target_component:string,auto_add:bool,allow_removal:bool,source_part_id:int,finish_applied:?string}>
     */
    function configuratorGetApplicableRequirements(
        \PDO $db,
        int $partId,
        array $context,
        int $depth = 0,
        array $visited = []
    ): array {
        // Prevent infinite loops
        if ($depth > 10 || in_array($partId, $visited, true)) {
            return [];
        }
        $visited[] = $partId;

        $stmt = $db->prepare('
            SELECT
                r.required_inventory_item_id,
                r.quantity,
                r.finish_policy,
                r.fixed_finish,
                r.fallback_item_id,
                r.target_component,
                r.auto_add,
                r.allow_removal,
                r.priority,
                i.name as required_part_name
            FROM configurator_part_requirements r
            JOIN inventory_items i ON i.id = r.required_inventory_item_id
            WHERE r.inventory_item_id = :part_id
              AND (r.applies_when_opening_type IS NULL OR r.applies_when_opening_type = :opening_type)
              AND (r.applies_when_hand IS NULL OR r.applies_when_hand = :hand)
              AND (r.applies_when_hinging IS NULL OR r.applies_when_hinging = :hinging)
              AND (r.applies_when_job_scope IS NULL OR r.applies_when_job_scope = :job_scope)
            ORDER BY r.priority ASC
        ');

        $stmt->execute([
            'part_id' => $partId,
            'opening_type' => $context['opening_type'] ?? null,
            'hand' => $context['hand'] ?? null,
            'hinging' => $context['hinging'] ?? null,
            'job_scope' => $context['job_scope'] ?? null,
        ]);

        $requirements = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $req) {
            // Resolve finish policy
            $resolvedItem = configuratorResolveFinishPolicy($db, $req, $context);

            if ($resolvedItem) {
                $requirements[] = [
                    'required_item_id' => $resolvedItem['item_id'],
                    'required_item_name' => $resolvedItem['item_name'],
                    'quantity' => (int)$req['quantity'],
                    'target_component' => $req['target_component'],
                    'auto_add' => (bool)$req['auto_add'],
                    'allow_removal' => (bool)$req['allow_removal'],
                    'source_part_id' => $partId,
                    'finish_applied' => $resolvedItem['finish'],
                ];

                // Multi-level: get requirements of this required part
                $nestedReqs = configuratorGetApplicableRequirements(
                    $db,
                    $resolvedItem['item_id'],
                    $context,
                    $depth + 1,
                    $visited
                );
                $requirements = array_merge($requirements, $nestedReqs);
            }
        }

        return $requirements;
    }

    /**
     * Resolve which specific item to use based on finish policy.
     * Handles finish mismatches with fallbacks.
     *
     * @param array<string,mixed> $requirement
     * @param array<string,mixed> $context
     * @return array{item_id:int,item_name:string,finish:?string}|null
     */
    function configuratorResolveFinishPolicy(
        \PDO $db,
        array $requirement,
        array $context
    ): ?array {
        $itemId = (int)$requirement['required_inventory_item_id'];
        $policy = $requirement['finish_policy'];
        $fixedFinish = $requirement['fixed_finish'];
        $fallbackId = $requirement['fallback_item_id'] ? (int)$requirement['fallback_item_id'] : null;

        $targetFinish = null;

        // Determine target finish based on policy
        switch ($policy) {
            case 'fixed':
                $targetFinish = $fixedFinish;
                break;
            case 'match_frame':
                $targetFinish = $context['frame_finish'] ?? null;
                break;
            case 'match_door':
                $targetFinish = $context['door_finish'] ?? null;
                break;
            case 'any_available':
                // Don't filter by finish - use whatever is available
                $targetFinish = null;
                break;
        }

        // Check if item is available in target finish
        if ($targetFinish !== null) {
            $available = configuratorCheckItemFinishAvailability($db, $itemId, $targetFinish);

            if ($available) {
                return [
                    'item_id' => $itemId,
                    'item_name' => $available['name'],
                    'finish' => $targetFinish,
                ];
            }

            // Finish not available - try fallback
            if ($fallbackId) {
                $fallbackAvailable = configuratorCheckItemFinishAvailability($db, $fallbackId, $targetFinish);
                if ($fallbackAvailable) {
                    return [
                        'item_id' => $fallbackId,
                        'item_name' => $fallbackAvailable['name'],
                        'finish' => $targetFinish,
                    ];
                }
            }

            // No match found - log warning but continue
            error_log("Warning: Required part $itemId not available in finish $targetFinish");
            return null;
        }

        // No finish constraint - use item as-is
        $stmt = $db->prepare('SELECT name FROM inventory_items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        $item = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $item ? [
            'item_id' => $itemId,
            'item_name' => $item['name'],
            'finish' => null,
        ] : null;
    }

    /**
     * Check if an inventory item is available in a specific finish.
     *
     * @return array{id:int,name:string}|null
     */
    function configuratorCheckItemFinishAvailability(
        \PDO $db,
        int $itemId,
        string $finish
    ): ?array {
        // Check if the item exists and matches the finish
        // This assumes inventory items have a finish field - adjust based on your schema
        $stmt = $db->prepare('
            SELECT id, name
            FROM inventory_items
            WHERE id = :id
              AND (finish = :finish OR finish IS NULL)
            LIMIT 1
        ');
        $stmt->execute(['id' => $itemId, 'finish' => $finish]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
            ];
        }

        return null;
    }

    /**
     * Extract all currently selected parts from a config array.
     *
     * @param array<string,mixed> $config
     * @return list<int>
     */
    function configuratorExtractAllSelectedParts(array $config): array
    {
        $parts = [];

        // Frame parts
        if (isset($config['frame']['parts']) && is_array($config['frame']['parts'])) {
            foreach ($config['frame']['parts'] as $part) {
                if (is_numeric($part)) {
                    $parts[] = (int)$part;
                }
            }
        }

        // Door parts (active)
        if (isset($config['door']['active']['parts']) && is_array($config['door']['active']['parts'])) {
            foreach ($config['door']['active']['parts'] as $part) {
                if (is_numeric($part)) {
                    $parts[] = (int)$part;
                }
            }
        }

        // Door parts (inactive)
        if (isset($config['door']['inactive']['parts']) && is_array($config['door']['inactive']['parts'])) {
            foreach ($config['door']['inactive']['parts'] as $part) {
                if (is_numeric($part)) {
                    $parts[] = (int)$part;
                }
            }
        }

        return array_values(array_unique($parts));
    }

    /**
     * Add a required part to the appropriate component in config.
     * Returns true if the part was added, false if it was already present.
     *
     * @param array<string,mixed> $config
     */
    function configuratorAddPartToComponent(
        array &$config,
        string $targetComponent,
        int $partId,
        int $quantity
    ): bool {
        // Map target component to config structure
        switch ($targetComponent) {
            case 'active_door':
                if (!isset($config['door']['active']['parts'])) {
                    $config['door']['active']['parts'] = [];
                }
                if (!in_array($partId, $config['door']['active']['parts'], true)) {
                    $config['door']['active']['parts'][] = $partId;
                    return true;
                }
                break;

            case 'inactive_door':
                if (!isset($config['door']['inactive']['parts'])) {
                    $config['door']['inactive']['parts'] = [];
                }
                if (!in_array($partId, $config['door']['inactive']['parts'], true)) {
                    $config['door']['inactive']['parts'][] = $partId;
                    return true;
                }
                break;

            case 'lock_jamb':
                if (($config['frame']['parts']['lock_jamb'] ?? null) !== $partId) {
                    $config['frame']['parts']['lock_jamb'] = $partId;
                    return true;
                }
                break;

            case 'hinge_jamb':
                if (($config['frame']['parts']['hinge_jamb'] ?? null) !== $partId) {
                    $config['frame']['parts']['hinge_jamb'] = $partId;
                    return true;
                }
                break;

            case 'door_head':
                if (($config['frame']['parts']['door_head'] ?? null) !== $partId) {
                    $config['frame']['parts']['door_head'] = $partId;
                    return true;
                }
                break;

            case 'frame':
                // Add to general frame parts array
                if (!isset($config['frame']['parts'])) {
                    $config['frame']['parts'] = [];
                }
                if (is_array($config['frame']['parts']) && !in_array($partId, $config['frame']['parts'], true)) {
                    $config['frame']['parts'][] = $partId;
                    return true;
                }
                break;

            case 'door':
                // Add to active door by default
                if (!isset($config['door']['active']['parts'])) {
                    $config['door']['active']['parts'] = [];
                }
                if (!in_array($partId, $config['door']['active']['parts'], true)) {
                    $config['door']['active']['parts'][] = $partId;
                    return true;
                }
                break;
        }

        return false; // Already present or invalid component
    }

    /**
     * Apply all requirements to a configuration (auto-add parts).
     * Returns modified config with list of what was added.
     *
     * @param array<string,mixed> $config
     * @return array{config:array<string,mixed>,added_parts:list<array{part_id:int,part_name:string,target:string,source:int,quantity:int,allow_removal:bool}>}
     */
    function configuratorApplyAllRequirements(
        \PDO $db,
        array $config
    ): array {
        $context = [
            'opening_type' => $config['entry']['opening_type'] ?? null,
            'hand' => $config['entry']['hand'] ?? null,
            'hinging' => $config['entry']['hinging'] ?? null,
            'job_scope' => $config['config']['job_scope'] ?? null,
            'frame_finish' => $config['entry']['finish'] ?? null,
            'door_finish' => $config['entry']['finish'] ?? null,
        ];

        $addedParts = [];
        $allSelectedParts = configuratorExtractAllSelectedParts($config);

        foreach ($allSelectedParts as $partId) {
            $requirements = configuratorGetApplicableRequirements($db, $partId, $context);

            foreach ($requirements as $req) {
                if (!$req['auto_add']) {
                    continue; // Skip validation-only requirements
                }

                // Add to appropriate component
                $added = configuratorAddPartToComponent(
                    $config,
                    $req['target_component'],
                    $req['required_item_id'],
                    $req['quantity']
                );

                if ($added) {
                    $addedParts[] = [
                        'part_id' => $req['required_item_id'],
                        'part_name' => $req['required_item_name'],
                        'target' => $req['target_component'],
                        'source' => $partId,
                        'quantity' => $req['quantity'],
                        'allow_removal' => $req['allow_removal'],
                    ];
                }
            }
        }

        // Store auto-added parts in config for UI tracking
        $config['_auto_added_parts'] = $addedParts;

        return [
            'config' => $config,
            'added_parts' => $addedParts,
        ];
    }

    /**
     * Check if a part is present in a specific component of the config.
     */
    function configuratorCheckPartPresent(
        array $config,
        string $targetComponent,
        int $partId
    ): bool {
        switch ($targetComponent) {
            case 'active_door':
                return in_array($partId, $config['door']['active']['parts'] ?? [], true);

            case 'inactive_door':
                return in_array($partId, $config['door']['inactive']['parts'] ?? [], true);

            case 'lock_jamb':
                return ($config['frame']['parts']['lock_jamb'] ?? null) === $partId;

            case 'hinge_jamb':
                return ($config['frame']['parts']['hinge_jamb'] ?? null) === $partId;

            case 'door_head':
                return ($config['frame']['parts']['door_head'] ?? null) === $partId;

            case 'frame':
                $frameParts = $config['frame']['parts'] ?? [];
                return is_array($frameParts) ? in_array($partId, $frameParts, true) : false;

            case 'door':
                return in_array($partId, $config['door']['active']['parts'] ?? [], true);
        }

        return false;
    }

    /**
     * Validate configuration against requirements.
     * Returns list of missing required parts.
     *
     * @param array<string,mixed> $config
     * @return list<array{source_part_id:int,required_part_id:int,required_part_name:string,target_component:string,message:string}>
     */
    function configuratorValidateRequirements(
        \PDO $db,
        array $config
    ): array {
        $context = [
            'opening_type' => $config['entry']['opening_type'] ?? null,
            'hand' => $config['entry']['hand'] ?? null,
            'hinging' => $config['entry']['hinging'] ?? null,
            'job_scope' => $config['config']['job_scope'] ?? null,
            'frame_finish' => $config['entry']['finish'] ?? null,
            'door_finish' => $config['entry']['finish'] ?? null,
        ];

        $errors = [];
        $allSelectedParts = configuratorExtractAllSelectedParts($config);

        foreach ($allSelectedParts as $partId) {
            $requirements = configuratorGetApplicableRequirements($db, $partId, $context);

            foreach ($requirements as $req) {
                $present = configuratorCheckPartPresent(
                    $config,
                    $req['target_component'],
                    $req['required_item_id']
                );

                if (!$present) {
                    $errors[] = [
                        'source_part_id' => $partId,
                        'required_part_id' => $req['required_item_id'],
                        'required_part_name' => $req['required_item_name'],
                        'target_component' => $req['target_component'],
                        'message' => "Missing required part: {$req['required_item_name']} " .
                                    "for {$req['target_component']}",
                    ];
                }
            }
        }

        return $errors;
    }
}
