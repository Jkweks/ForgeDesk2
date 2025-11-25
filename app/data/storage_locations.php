<?php

declare(strict_types=1);

if (!function_exists('storageLocationsEnsureSchema')) {
    function storageLocationFormatName(array $parts, ?string $fallback = null): string
    {
        $segments = array_values(array_filter(
            [$parts['aisle'] ?? null, $parts['rack'] ?? null, $parts['shelf'] ?? null, $parts['bin'] ?? null],
            static fn ($value): bool => $value !== null && trim((string) $value) !== ''
        ));

        if ($segments === []) {
            return $fallback !== null ? trim($fallback) : '';
        }

        return implode('.', array_map(static fn ($segment): string => trim((string) $segment), $segments));
    }

    /**
     * @return array{aisle:?string,rack:?string,shelf:?string,bin:?string}
     */
    function storageLocationExtractComponents(array $payload): array
    {
        $extract = static fn (string $key): ?string => isset($payload[$key]) && trim((string) $payload[$key]) !== ''
            ? trim((string) $payload[$key])
            : null;

        return [
            'aisle' => $extract('aisle'),
            'rack' => $extract('rack'),
            'shelf' => $extract('shelf'),
            'bin' => $extract('bin'),
        ];
    }

    /**
     * @return array{aisle:?string,rack:?string,shelf:?string,bin:?string}
     */
    function storageLocationParseName(string $name): array
    {
        $parts = preg_split('/[\.\-]/', $name);
        $parts = $parts === false ? [] : array_map('trim', $parts);

        return [
            'aisle' => $parts[0] ?? null,
            'rack' => $parts[1] ?? null,
            'shelf' => $parts[2] ?? null,
            'bin' => $parts[3] ?? null,
        ];
    }

    function storageLocationDescribe(array $location, string $delimiter = ' · ', bool $includePrefixes = true): string
    {
        $parts = [];

        $append = static function (?string $value, string $prefix) use (&$parts, $includePrefixes): void {
            if ($value === null || trim((string) $value) === '') {
                return;
            }

            $normalized = trim((string) $value);
            $parts[] = $includePrefixes ? $prefix . $normalized : $normalized;
        };

        $append($location['aisle'] ?? null, 'Aisle ');
        $append($location['rack'] ?? null, 'Rack ');
        $append($location['shelf'] ?? null, 'Shelf ');
        $append($location['bin'] ?? null, 'Bin ');

        if ($parts === []) {
            return isset($location['display_name']) && trim((string) $location['display_name']) !== ''
                ? trim((string) $location['display_name'])
                : (isset($location['name']) ? trim((string) $location['name']) : '');
        }

        return implode($delimiter, $parts);
    }

    function storageLocationsBackfillComponents(PDO $db): void
    {
        $statement = $db->query(
            'SELECT id, name, aisle, rack, shelf, bin
             FROM storage_locations
             WHERE aisle IS NULL AND rack IS NULL AND shelf IS NULL AND bin IS NULL'
        );

        if ($statement === false) {
            return;
        }

        $rows = $statement->fetchAll();
        if ($rows === false || $rows === []) {
            return;
        }

        $update = $db->prepare(
            'UPDATE storage_locations
             SET aisle = :aisle, rack = :rack, shelf = :shelf, bin = :bin
             WHERE id = :id'
        );

        foreach ($rows as $row) {
            $components = storageLocationParseName((string) $row['name']);
            $update->execute([
                ':aisle' => $components['aisle'],
                ':rack' => $components['rack'],
                ':shelf' => $components['shelf'],
                ':bin' => $components['bin'],
                ':id' => (int) $row['id'],
            ]);
        }
    }

    /**
     * @return array<int,array{aisle:?string,rack:?string,shelf:?string,bin:?string,label:string,location_ids:list<int>,racks:array}>
     */
    function storageLocationsHierarchy(PDO $db, bool $includeInactive = false): array
    {
        $locations = storageLocationsList($db, $includeInactive);

        $tree = [];

        foreach ($locations as $location) {
            $aisleKey = strtolower($location['aisle'] ?? '');
            $rackKey = strtolower($location['rack'] ?? '');
            $shelfKey = strtolower($location['shelf'] ?? '');
            $binLabel = $location['bin'] ?? null;

            $leafLabel = null;
            if ($binLabel !== null && $binLabel !== '') {
                $leafLabel = 'Bin ' . $binLabel;
            } elseif ($location['shelf'] !== null && $location['shelf'] !== '') {
                $leafLabel = 'Shelf ' . $location['shelf'];
            } elseif ($location['rack'] !== null && $location['rack'] !== '') {
                $leafLabel = 'Rack ' . $location['rack'];
            } elseif ($location['aisle'] !== null && $location['aisle'] !== '') {
                $leafLabel = 'Aisle ' . $location['aisle'];
            }

            if (!isset($tree[$aisleKey])) {
                $aisleLabel = $location['aisle'] !== null && $location['aisle'] !== ''
                    ? 'Aisle ' . $location['aisle']
                    : 'Unassigned aisle';
                $tree[$aisleKey] = [
                    'aisle' => $location['aisle'],
                    'label' => $aisleLabel,
                    'location_ids' => [],
                    'racks' => [],
                ];
            }

            if (!isset($tree[$aisleKey]['racks'][$rackKey])) {
                $rackLabel = $location['rack'] !== null && $location['rack'] !== ''
                    ? 'Rack ' . $location['rack']
                    : 'Unassigned rack';
                $tree[$aisleKey]['racks'][$rackKey] = [
                    'rack' => $location['rack'],
                    'label' => $rackLabel,
                    'location_ids' => [],
                    'shelves' => [],
                ];
            }

            if (!isset($tree[$aisleKey]['racks'][$rackKey]['shelves'][$shelfKey])) {
                $shelfLabel = $location['shelf'] !== null && $location['shelf'] !== ''
                    ? 'Shelf ' . $location['shelf']
                    : 'Unassigned shelf';
                $tree[$aisleKey]['racks'][$rackKey]['shelves'][$shelfKey] = [
                    'shelf' => $location['shelf'],
                'label' => $shelfLabel,
                'location_ids' => [],
                'bins' => [],
            ];
        }

            $tree[$aisleKey]['location_ids'][] = $location['id'];
            $tree[$aisleKey]['racks'][$rackKey]['location_ids'][] = $location['id'];
            $tree[$aisleKey]['racks'][$rackKey]['shelves'][$shelfKey]['location_ids'][] = $location['id'];

            $tree[$aisleKey]['racks'][$rackKey]['shelves'][$shelfKey]['bins'][] = [
                'id' => $location['id'],
                'label' => $leafLabel !== null ? $leafLabel : $location['display_name'],
                'path_label' => storageLocationFormatName([
                    'aisle' => $location['aisle'],
                    'rack' => $location['rack'],
                    'shelf' => $location['shelf'],
                    'bin' => $location['bin'],
                ], $location['display_name']),
                'bin' => $location['bin'],
                'display_name' => $location['display_name'],
            ];
        }

        foreach ($tree as &$aisle) {
            foreach ($aisle['racks'] as &$rack) {
                foreach ($rack['shelves'] as &$shelf) {
                    $shelf['bins'] = array_values($shelf['bins']);
                    sort($shelf['location_ids']);
                }
                $rack['shelves'] = array_values($rack['shelves']);
                sort($rack['location_ids']);
            }
            $aisle['racks'] = array_values($aisle['racks']);
            sort($aisle['location_ids']);
        }
        unset($aisle, $rack, $shelf);

        return array_values($tree);
    }

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
                aisle TEXT NULL,
                rack TEXT NULL,
                shelf TEXT NULL,
                bin TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $db->exec('ALTER TABLE storage_locations ADD COLUMN IF NOT EXISTS aisle TEXT NULL');
        $db->exec('ALTER TABLE storage_locations ADD COLUMN IF NOT EXISTS rack TEXT NULL');
        $db->exec('ALTER TABLE storage_locations ADD COLUMN IF NOT EXISTS shelf TEXT NULL');
        $db->exec('ALTER TABLE storage_locations ADD COLUMN IF NOT EXISTS bin TEXT NULL');

        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS storage_locations_name_unique
             ON storage_locations ((lower(name)))'
        );

        $db->exec('CREATE INDEX IF NOT EXISTS idx_storage_locations_aisle ON storage_locations ((lower(aisle)))');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_storage_locations_rack ON storage_locations ((lower(rack)))');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_storage_locations_shelf ON storage_locations ((lower(shelf)))');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_storage_locations_bin ON storage_locations ((lower(bin)))');

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

        storageLocationsBackfillComponents($db);

        $ensured = true;
    }

    /**
     * @return list<array{id:int,name:string,description:?string,is_active:bool,sort_order:int,assigned_items:int,aisle:?string,rack:?string,shelf:?string,bin:?string,display_name:string}>
     */
    function storageLocationsList(PDO $db, bool $includeInactive = false): array
    {
        storageLocationsEnsureSchema($db);

        $sql = 'SELECT sl.id, sl.name, sl.description, sl.is_active, sl.sort_order, sl.aisle, sl.rack, sl.shelf, sl.bin,
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

        $sql .= ' ORDER BY sl.sort_order ASC, lower(sl.aisle) ASC NULLS LAST, lower(sl.rack) ASC NULLS LAST, lower(sl.shelf) ASC NULLS LAST, lower(sl.bin) ASC NULLS LAST, sl.name ASC';

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
                'aisle' => $row['aisle'] !== null ? (string) $row['aisle'] : null,
                'rack' => $row['rack'] !== null ? (string) $row['rack'] : null,
                'shelf' => $row['shelf'] !== null ? (string) $row['shelf'] : null,
                'bin' => $row['bin'] !== null ? (string) $row['bin'] : null,
                'display_name' => storageLocationFormatName([
                    'aisle' => $row['aisle'],
                    'rack' => $row['rack'],
                    'shelf' => $row['shelf'],
                    'bin' => $row['bin'],
                ], (string) $row['name']),
            ],
            $rows
        );
    }

    /**
     * @return array{id:int,name:string,description:?string,is_active:bool,aisle:?string,rack:?string,shelf:?string,bin:?string}|null
     */
    function storageLocationsFind(PDO $db, int $id): ?array
    {
        storageLocationsEnsureSchema($db);

        $statement = $db->prepare('SELECT id, name, description, is_active, aisle, rack, shelf, bin FROM storage_locations WHERE id = :id');
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
            'aisle' => $row['aisle'] !== null ? (string) $row['aisle'] : null,
            'rack' => $row['rack'] !== null ? (string) $row['rack'] : null,
            'shelf' => $row['shelf'] !== null ? (string) $row['shelf'] : null,
            'bin' => $row['bin'] !== null ? (string) $row['bin'] : null,
        ];
    }

    /**
     * @return array<int, array{id:int,name:string,description:?string,is_active:bool,aisle:?string,rack:?string,shelf:?string,bin:?string}>
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
            'SELECT id, name, description, is_active, aisle, rack, shelf, bin FROM storage_locations WHERE id IN (' . $placeholders . ')'
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
                'aisle' => $row['aisle'] !== null ? (string) $row['aisle'] : null,
                'rack' => $row['rack'] !== null ? (string) $row['rack'] : null,
                'shelf' => $row['shelf'] !== null ? (string) $row['shelf'] : null,
                'bin' => $row['bin'] !== null ? (string) $row['bin'] : null,
            ];
        }

        return $map;
    }

    function storageLocationsCreate(PDO $db, array $payload): int
    {
        storageLocationsEnsureSchema($db);

        $components = storageLocationExtractComponents($payload);
        if (isset($payload['name']) && trim((string) $payload['name']) !== '') {
            $name = trim((string) $payload['name']);
        } else {
            $name = storageLocationFormatName($components, 'Unspecified location');
        }

        $statement = $db->prepare(
            'INSERT INTO storage_locations (name, description, is_active, sort_order, aisle, rack, shelf, bin)
             VALUES (:name, :description, TRUE,
                COALESCE((SELECT MAX(sort_order) FROM storage_locations), 0) + 10,
                :aisle, :rack, :shelf, :bin)
             RETURNING id'
        );

        $statement->execute([
            ':name' => $name,
            ':description' => $payload['description'] ?? null,
            ':aisle' => $components['aisle'],
            ':rack' => $components['rack'],
            ':shelf' => $components['shelf'],
            ':bin' => $components['bin'],
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
     * @return array{id:int,name:string,description:?string,is_active:bool,aisle:?string,rack:?string,shelf:?string,bin:?string}
     */
    function storageLocationsGetOrCreateByName(PDO $db, string $name): array
    {
        storageLocationsEnsureSchema($db);

        $normalized = trim($name);
        if ($normalized === '') {
            throw new InvalidArgumentException('Location name is required.');
        }

        $statement = $db->prepare(
            'SELECT id, name, description, is_active, aisle, rack, shelf, bin
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
                'aisle' => $row['aisle'] !== null ? (string) $row['aisle'] : null,
                'rack' => $row['rack'] !== null ? (string) $row['rack'] : null,
                'shelf' => $row['shelf'] !== null ? (string) $row['shelf'] : null,
                'bin' => $row['bin'] !== null ? (string) $row['bin'] : null,
            ];
        }

        $components = storageLocationParseName($normalized);
        $id = storageLocationsCreate($db, [
            'name' => $normalized,
            'description' => null,
            'aisle' => $components['aisle'],
            'rack' => $components['rack'],
            'shelf' => $components['shelf'],
            'bin' => $components['bin'],
        ]);

        return [
            'id' => $id,
            'name' => $normalized,
            'description' => null,
            'is_active' => true,
            'aisle' => $components['aisle'],
            'rack' => $components['rack'],
            'shelf' => $components['shelf'],
            'bin' => $components['bin'],
        ];
    }
}
