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

    function configuratorEnsureSchema(\PDO $db): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $db->exec(
            'CREATE TABLE IF NOT EXISTS configurator_part_use_options (
                id BIGSERIAL PRIMARY KEY,
                name TEXT NOT NULL UNIQUE
            )'
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS configurator_part_profiles (
                inventory_item_id BIGINT PRIMARY KEY REFERENCES inventory_items(id) ON DELETE CASCADE,
                is_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                part_type TEXT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT configurator_part_profiles_part_type_check
                    CHECK (part_type IS NULL OR part_type IN ('door', 'frame', 'hardware', 'accessory'))
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
                PRIMARY KEY (inventory_item_id, required_inventory_item_id)
            )'
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
                status TEXT NOT NULL DEFAULT 'draft',
                notes TEXT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_configurator_configurations_job_id
                ON configurator_configurations(job_id)'
        );

        $seed = $db->prepare('INSERT INTO configurator_part_use_options (name) VALUES (:name) ON CONFLICT (name) DO NOTHING');
        foreach (['Interior Opening', 'Exterior Opening', 'Fire Rated', 'Pair Door', 'Single Door', 'Hardware Set'] as $name) {
            $seed->execute([':name' => $name]);
        }

        $ensured = true;
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    function configuratorListUseOptions(\PDO $db): array
    {
        configuratorEnsureSchema($db);

        $statement = $db->query('SELECT id, name FROM configurator_part_use_options ORDER BY name ASC');
        if ($statement === false) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return array<int,array{id:int,name:string}>
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
     * Lightweight inventory listing for requirement dropdowns.
     *
     * @return list<array{id:int,label:string}>
     */
    function configuratorInventoryOptions(\PDO $db): array
    {
        configuratorEnsureSchema($db);

        $statement = $db->query(
            'SELECT id, sku, item FROM inventory_items ORDER BY item ASC'
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
     * @return array{enabled:bool,part_type:?string,use_ids:list<int>,required_ids:list<int>}
     */
    function configuratorLoadPartProfile(\PDO $db, int $inventoryItemId): array
    {
        configuratorEnsureSchema($db);

        $profileStatement = $db->prepare(
            'SELECT is_enabled, part_type FROM configurator_part_profiles WHERE inventory_item_id = :item_id'
        );
        $profileStatement->execute([':item_id' => $inventoryItemId]);
        $profile = $profileStatement->fetch();

        $useStatement = $db->prepare(
            'SELECT use_option_id FROM configurator_part_use_links WHERE inventory_item_id = :item_id'
        );
        $useStatement->execute([':item_id' => $inventoryItemId]);
        $useIds = array_map('intval', $useStatement->fetchAll(\PDO::FETCH_COLUMN));

        $requiresStatement = $db->prepare(
            'SELECT required_inventory_item_id FROM configurator_part_requirements WHERE inventory_item_id = :item_id'
        );
        $requiresStatement->execute([':item_id' => $inventoryItemId]);
        $requiredIds = array_map('intval', $requiresStatement->fetchAll(\PDO::FETCH_COLUMN));

        return [
            'enabled' => $profile !== false ? (bool) $profile['is_enabled'] : false,
            'part_type' => $profile !== false && $profile['part_type'] !== null
                ? (string) $profile['part_type']
                : null,
            'use_ids' => $useIds,
            'required_ids' => $requiredIds,
        ];
    }

    /**
     * Persist configurator metadata for an inventory item.
     *
     * @param list<int> $useIds
     * @param list<int> $requiredItemIds
     */
    function configuratorSyncPartProfile(
        \PDO $db,
        int $inventoryItemId,
        bool $enabled,
        ?string $partType,
        array $useIds,
        array $requiredItemIds
    ): void {
        configuratorEnsureSchema($db);

        $normalizedType = $partType !== null && in_array($partType, configuratorAllowedPartTypes(), true)
            ? $partType
            : null;

        $db->beginTransaction();
        try {
            $profileStatement = $db->prepare(
                'INSERT INTO configurator_part_profiles (inventory_item_id, is_enabled, part_type)
                 VALUES (:id, :enabled, :type)
                 ON CONFLICT (inventory_item_id)
                 DO UPDATE SET is_enabled = EXCLUDED.is_enabled, part_type = EXCLUDED.part_type'
            );
            $profileStatement->execute([
                ':id' => $inventoryItemId,
                ':enabled' => $enabled,
                ':type' => $enabled ? $normalizedType : null,
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

            if ($enabled && $requiredItemIds !== []) {
                $requireInsert = $db->prepare(
                    'INSERT INTO configurator_part_requirements (inventory_item_id, required_inventory_item_id)
                     VALUES (:item_id, :required_id)'
                );

                $uniqueRequired = array_values(array_unique(array_map('intval', $requiredItemIds)));
                foreach ($uniqueRequired as $requiredId) {
                    if ($requiredId === $inventoryItemId) {
                        continue;
                    }

                    $requireInsert->execute([
                        ':item_id' => $inventoryItemId,
                        ':required_id' => $requiredId,
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
     * @return list<array{id:int,name:string,job_id:?int,job_number:?string,job_name:?string,status:string,notes:?string,updated_at:string}>
     */
    function configuratorListConfigurations(\PDO $db): array
    {
        configuratorEnsureSchema($db);

        $statement = $db->query(
            'SELECT cc.id, cc.name, cc.job_id, cc.status, cc.notes, cc.updated_at, cj.job_number, cj.name AS job_name
             FROM configurator_configurations cc
             LEFT JOIN configurator_jobs cj ON cj.id = cc.job_id
             ORDER BY cc.updated_at DESC, cc.id DESC'
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
                'status' => (string) $row['status'],
                'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
                'updated_at' => (string) $row['updated_at'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return array{id:int,name:string,job_id:?int,status:string,notes:?string}|null
     */
    function configuratorFindConfiguration(\PDO $db, int $id): ?array
    {
        configuratorEnsureSchema($db);

        $statement = $db->prepare(
            'SELECT id, name, job_id, status, notes FROM configurator_configurations WHERE id = :id'
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
            'status' => (string) $row['status'],
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
        ];
    }

    /**
     * @param array{name:string,job_id:?int,status:string,notes:?string} $payload
     */
    function configuratorCreateConfiguration(\PDO $db, array $payload): int
    {
        configuratorEnsureSchema($db);

        $statement = $db->prepare(
            'INSERT INTO configurator_configurations (name, job_id, status, notes)
             VALUES (:name, :job_id, :status, :notes) RETURNING id'
        );
        $statement->execute([
            ':name' => $payload['name'],
            ':job_id' => $payload['job_id'],
            ':status' => $payload['status'],
            ':notes' => $payload['notes'],
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{name:string,job_id:?int,status:string,notes:?string} $payload
     */
    function configuratorUpdateConfiguration(\PDO $db, int $id, array $payload): void
    {
        configuratorEnsureSchema($db);

        $statement = $db->prepare(
            'UPDATE configurator_configurations
             SET name = :name,
                 job_id = :job_id,
                 status = :status,
                 notes = :notes,
                 updated_at = NOW()
             WHERE id = :id'
        );

        $statement->execute([
            ':id' => $id,
            ':name' => $payload['name'],
            ':job_id' => $payload['job_id'],
            ':status' => $payload['status'],
            ':notes' => $payload['notes'],
        ]);
    }
}
