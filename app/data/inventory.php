<?php

declare(strict_types=1);

if (!function_exists('loadInventory')) {
    /**
     * Fetch inventory rows ordered by item name.
     *
     * @return array<int, array{item:string,sku:string,location:string,stock:int,status:string,supplier:string,supplier_contact:?string,reorder_point:int,lead_time_days:int,id:int}>
     */
    function loadInventory(\PDO $db): array
    {
        try {
            $statement = $db->query(
                'SELECT id, item, sku, location, stock, status, supplier, supplier_contact, reorder_point, lead_time_days FROM inventory_items ORDER BY item ASC'
            );

            $rows = $statement->fetchAll();

            return array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'item' => (string) $row['item'],
                    'sku' => (string) $row['sku'],
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
     * @return array{item:string,sku:string,location:string,stock:int,status:string,supplier:string,supplier_contact:?string,reorder_point:int,lead_time_days:int,id:int}|null
     */
    function findInventoryItem(\PDO $db, int $id): ?array
    {
        $statement = $db->prepare('SELECT id, item, sku, location, stock, status, supplier, supplier_contact, reorder_point, lead_time_days FROM inventory_items WHERE id = :id');
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
     * @param array{item:string,sku:string,location:string,stock:int,status:string,supplier:string,supplier_contact:?string,reorder_point:int,lead_time_days:int} $payload
     */
    function createInventoryItem(\PDO $db, array $payload): int
    {
        $statement = $db->prepare('INSERT INTO inventory_items (item, sku, location, stock, status, supplier, supplier_contact, reorder_point, lead_time_days) VALUES (:item, :sku, :location, :stock, :status, :supplier, :supplier_contact, :reorder_point, :lead_time_days) RETURNING id');

        $statement->execute([
            ':item' => $payload['item'],
            ':sku' => $payload['sku'],
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
     * @param array{item:string,sku:string,location:string,stock:int,status:string,supplier:string,supplier_contact:?string,reorder_point:int,lead_time_days:int} $payload
     */
    function updateInventoryItem(\PDO $db, int $id, array $payload): void
    {
        $statement = $db->prepare('UPDATE inventory_items SET item = :item, sku = :sku, location = :location, stock = :stock, status = :status, supplier = :supplier, supplier_contact = :supplier_contact, reorder_point = :reorder_point, lead_time_days = :lead_time_days WHERE id = :id');

        $statement->execute([
            ':id' => $id,
            ':item' => $payload['item'],
            ':sku' => $payload['sku'],
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
