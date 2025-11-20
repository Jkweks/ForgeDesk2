<?php

declare(strict_types=1);

if (!function_exists('storageLocationsEnsureSchema')) {
    function storageLocationsEnsureSchema(PDO $db): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $db->exec(
            'CREATE TABLE IF NOT EXISTS storage_locations (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS storage_locations_name_unique
             ON storage_locations ((lower(name)))'
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS inventory_item_locations (
                id SERIAL PRIMARY KEY,
                inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
                storage_location_id INTEGER NOT NULL REFERENCES storage_locations(id) ON DELETE CASCADE,
                quantity INTEGER NOT NULL DEFAULT 0,
                UNIQUE (inventory_item_id, storage_location_id)
            )'
        );

        $db->exec('CREATE INDEX IF NOT EXISTS idx_inventory_item_locations_item
            ON inventory_item_locations (inventory_item_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inventory_item_locations_location
            ON inventory_item_locations (storage_location_id)');

        $ensured = true;
    }

    /**
     * @return list<array{id:int,name:string,description:?string,is_active:bool,sort_order:int,assigned_items:int}>
     */
    function storageLocationsList(PDO $db, bool $includeInactive = false): array
    {
        storageLocationsEnsureSchema($db);

        $sql = 'SELECT sl.id, sl.name, sl.description, sl.is_active, sl.sort_order,
                    COALESCE(item_counts.total_items, 0) AS assigned_items
                FROM storage_locations sl
                LEFT JOIN (
                    SELECT storage_location_id, COUNT(*) AS total_items
                    FROM inventory_item_locations
                    GROUP BY storage_location_id
                ) item_counts ON item_counts.storage_location_id = sl.id';

        if (!$includeInactive) {
            $sql .= ' WHERE sl.is_active = TRUE';
        }

        $sql .= ' ORDER BY sl.sort_order ASC, sl.name ASC';

        $statement = $db->query($sql);
        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
                'is_active' => (bool) $row['is_active'],
                'sort_order' => (int) $row['sort_order'],
                'assigned_items' => (int) $row['assigned_items'],
            ],
            $rows
        );
    }

    /**
     * @return array{id:int,name:string,description:?string,is_active:bool}|null
     */
    function storageLocationsFind(PDO $db, int $id): ?array
    {
        storageLocationsEnsureSchema($db);

        $statement = $db->prepare('SELECT id, name, description, is_active FROM storage_locations WHERE id = :id');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'is_active' => (bool) $row['is_active'],
        ];
    }

    /**
     * @return array<int, array{id:int,name:string,description:?string,is_active:bool}>
     */
    function storageLocationsMapByIds(PDO $db, array $ids): array
    {
        storageLocationsEnsureSchema($db);

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($value) => (int) $value, $ids),
            static fn (int $value): bool => $value > 0
        )));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $db->prepare(
            'SELECT id, name, description, is_active FROM storage_locations WHERE id IN (' . $placeholders . ')'
        );
        $statement->execute($ids);

        $rows = $statement->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
                'is_active' => (bool) $row['is_active'],
            ];
        }

        return $map;
    }

    function storageLocationsCreate(PDO $db, array $payload): int
    {
        storageLocationsEnsureSchema($db);

        $statement = $db->prepare(
            'INSERT INTO storage_locations (name, description, is_active, sort_order)
             VALUES (:name, :description, TRUE,
                COALESCE((SELECT MAX(sort_order) FROM storage_locations), 0) + 10)
             RETURNING id'
        );

        $statement->execute([
            ':name' => $payload['name'],
            ':description' => $payload['description'] ?? null,
        ]);

        return (int) $statement->fetchColumn();
    }

    function storageLocationsSetActive(PDO $db, int $id, bool $isActive): void
    {
        storageLocationsEnsureSchema($db);

        $statement = $db->prepare('UPDATE storage_locations SET is_active = :is_active WHERE id = :id');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        $statement->execute();
    }

    /**
     * @return array{id:int,name:string,description:?string,is_active:bool}
     */
    function storageLocationsGetOrCreateByName(PDO $db, string $name): array
    {
        storageLocationsEnsureSchema($db);

        $normalized = trim($name);
        if ($normalized === '') {
            throw new InvalidArgumentException('Location name is required.');
        }

        $statement = $db->prepare(
            'SELECT id, name, description, is_active
             FROM storage_locations
             WHERE lower(name) = lower(:name)
             LIMIT 1'
        );
        $statement->execute([':name' => $normalized]);

        $row = $statement->fetch();
        if ($row !== false) {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
                'is_active' => (bool) $row['is_active'],
            ];
        }

        $id = storageLocationsCreate($db, ['name' => $normalized, 'description' => null]);

        return [
            'id' => $id,
            'name' => $normalized,
            'description' => null,
            'is_active' => true,
        ];
    }
}
