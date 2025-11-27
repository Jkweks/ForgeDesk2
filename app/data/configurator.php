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
                name TEXT NOT NULL UNIQUE,
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
                PRIMARY KEY (inventory_item_id, required_inventory_item_id)
            )'
        );

        $db->exec(
            "ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS quantity INTEGER NOT NULL DEFAULT 1"
        );

        $db->exec(
            "ALTER TABLE configurator_part_use_options
                ADD COLUMN IF NOT EXISTS parent_id BIGINT NULL REFERENCES configurator_part_use_options(id) ON DELETE SET NULL"
        );

        $db->exec(
            "CREATE INDEX IF NOT EXISTS idx_configurator_part_use_options_parent_id
                ON configurator_part_use_options(parent_id)"
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
             VALUES (:name, :parent_id)
             ON CONFLICT (name) DO NOTHING'
        );
        $updateParent = $db->prepare(
            'UPDATE configurator_part_use_options SET parent_id = :parent_id WHERE name = :name'
        );
        $parentLookup = $db->prepare(
            'SELECT id FROM configurator_part_use_options WHERE name = :name'
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
            ['name' => 'Hardware Set', 'parent' => 'Hardware'],
            ['name' => 'Door Hardware', 'parent' => 'Hardware'],
            ['name' => 'Hinge', 'parent' => 'Door Hardware'],
            ['name' => 'Butt Hinge', 'parent' => 'Hinge'],
            ['name' => 'Heavy Duty', 'parent' => 'Butt Hinge'],
        ];

        foreach ($seedOptions as $option) {
            $parentId = null;

            if ($option['parent'] !== null) {
                $parentLookup->execute([':name' => $option['parent']]);
                $parentCandidate = $parentLookup->fetchColumn();
                if ($parentCandidate !== false) {
                    $parentId = (int) $parentCandidate;
                }
            }

            $seed->execute([
                ':name' => $option['name'],
                ':parent_id' => $parentId,
            ]);

            if ($parentId !== null) {
                $updateParent->execute([
                    ':name' => $option['name'],
                    ':parent_id' => $parentId,
                ]);
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
            'SELECT ii.id, ii.sku, ii.item
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
                ];
            },
            $statement->fetchAll()
        );
    }

    /**
     * @return array{enabled:bool,part_type:?string,height_lz:?float,depth_ly:?float,use_ids:list<int>,requirements:list<array{item_id:int,quantity:int}>}
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
            'SELECT required_inventory_item_id, quantity
             FROM configurator_part_requirements
             WHERE inventory_item_id = :item_id'
        );
        $requiresStatement->execute([':item_id' => $inventoryItemId]);
        $requiredParts = array_map(
            static fn (array $row): array => [
                'item_id' => (int) $row['required_inventory_item_id'],
                'quantity' => max(1, (int) $row['quantity']),
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
     * @param list<array{item_id:int,quantity:int}> $requiredItems
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

        $db->beginTransaction();
        try {
            $profileStatement = $db->prepare(
                'INSERT INTO configurator_part_profiles (inventory_item_id, is_enabled, part_type, height_lz, depth_ly)
                 VALUES (:id, :enabled, :type, :height_lz, :depth_ly)
                 ON CONFLICT (inventory_item_id)
                 DO UPDATE SET is_enabled = EXCLUDED.is_enabled, part_type = EXCLUDED.part_type, height_lz = EXCLUDED.height_lz, depth_ly = EXCLUDED.depth_ly'
            );
            $profileStatement->execute([
                ':id' => $inventoryItemId,
                ':enabled' => $enabled,
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
                    'INSERT INTO configurator_part_requirements (inventory_item_id, required_inventory_item_id, quantity)
                     VALUES (:item_id, :required_id, :quantity)'
                );

                $uniqueRequired = [];

                foreach ($requiredItems as $requirement) {
                    $requiredId = (int) $requirement['item_id'];
                    $quantity = max(1, (int) $requirement['quantity']);

                    if ($requiredId === $inventoryItemId) {
                        continue;
                    }

                    if (!isset($uniqueRequired[$requiredId])) {
                        $uniqueRequired[$requiredId] = $quantity;
                    } else {
                        $uniqueRequired[$requiredId] += $quantity;
                    }
                }

                foreach ($uniqueRequired as $requiredId => $quantity) {
                    $requireInsert->execute([
                        ':item_id' => $inventoryItemId,
                        ':required_id' => $requiredId,
                        ':quantity' => $quantity,
                    ]);
                }
            }

            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();
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
}
