<?php

declare(strict_types=1);

if (!function_exists('loadInventory')) {
    /**
     * Fetch inventory rows ordered by item name.
     *
     * @return array<int, array{item:string,sku:string,location:string,stock:int,status:string}>
     */
    function loadInventory(\PDO $db): array
    {
        try {
            $statement = $db->query(
                'SELECT item, sku, location, stock, status FROM inventory_items ORDER BY item ASC'
            );

            $rows = $statement->fetchAll();

            return array_map(
                static fn (array $row): array => [
                    'item' => (string) $row['item'],
                    'sku' => (string) $row['sku'],
                    'location' => (string) $row['location'],
                    'stock' => (int) $row['stock'],
                    'status' => (string) $row['status'],
                ],
                $rows
            );
        } catch (\PDOException $exception) {
            throw new \PDOException('Unable to load inventory data: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }
}
