<?php

declare(strict_types=1);

require_once __DIR__ . '/purchase_orders.php';

if (!function_exists('suppliersEnsureSchema')) {
    function suppliersEnsureSchema(\PDO $db): void
    {
        purchaseOrderEnsureSchema($db);
    }

    /**
     * @return list<array{id:int,name:string,contact_name:?string,contact_email:?string,contact_phone:?string,default_lead_time_days:int,notes:?string}>
     */
    function suppliersList(\PDO $db): array
    {
        suppliersEnsureSchema($db);

        $statement = $db->query(
            'SELECT id, name, contact_name, contact_email, contact_phone, default_lead_time_days, notes'
            . ' FROM suppliers'
            . ' ORDER BY name ASC, id ASC'
        );

        if ($statement === false) {
            return [];
        }

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'contact_name' => $row['contact_name'] !== null ? (string) $row['contact_name'] : null,
                    'contact_email' => $row['contact_email'] !== null ? (string) $row['contact_email'] : null,
                    'contact_phone' => $row['contact_phone'] !== null ? (string) $row['contact_phone'] : null,
                    'default_lead_time_days' => $row['default_lead_time_days'] !== null ? (int) $row['default_lead_time_days'] : 0,
                    'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
                ];
            },
            $rows
        );
    }

    /**
     * @return array<int,array{id:int,name:string,contact_name:?string,contact_email:?string,contact_phone:?string,default_lead_time_days:int,notes:?string}>
     */
    function suppliersMapById(\PDO $db): array
    {
        $map = [];

        foreach (suppliersList($db) as $supplier) {
            $map[$supplier['id']] = $supplier;
        }

        return $map;
    }

    /**
     * @return array{id:int,name:string,contact_name:?string,contact_email:?string,contact_phone:?string,default_lead_time_days:int,notes:?string}|null
     */
    function suppliersFind(\PDO $db, int $supplierId): ?array
    {
        suppliersEnsureSchema($db);

        $statement = $db->prepare(
            'SELECT id, name, contact_name, contact_email, contact_phone, default_lead_time_days, notes'
            . ' FROM suppliers WHERE id = :id'
        );
        $statement->bindValue(':id', $supplierId, \PDO::PARAM_INT);
        $statement->execute();

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'contact_name' => $row['contact_name'] !== null ? (string) $row['contact_name'] : null,
            'contact_email' => $row['contact_email'] !== null ? (string) $row['contact_email'] : null,
            'contact_phone' => $row['contact_phone'] !== null ? (string) $row['contact_phone'] : null,
            'default_lead_time_days' => $row['default_lead_time_days'] !== null ? (int) $row['default_lead_time_days'] : 0,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
        ];
    }

    /**
     * @param array{name:string,contact_name?:?string,contact_email?:?string,contact_phone?:?string,default_lead_time_days?:int,notes?:?string} $payload
     */
    function suppliersCreate(\PDO $db, array $payload): int
    {
        suppliersEnsureSchema($db);

        $statement = $db->prepare(
            'INSERT INTO suppliers (name, contact_name, contact_email, contact_phone, default_lead_time_days, notes)'
            . ' VALUES (:name, :contact_name, :contact_email, :contact_phone, :default_lead_time_days, :notes)'
            . ' RETURNING id'
        );

        $statement->execute([
            ':name' => $payload['name'],
            ':contact_name' => $payload['contact_name'] ?? null,
            ':contact_email' => $payload['contact_email'] ?? null,
            ':contact_phone' => $payload['contact_phone'] ?? null,
            ':default_lead_time_days' => $payload['default_lead_time_days'] ?? 0,
            ':notes' => $payload['notes'] ?? null,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{name?:string,contact_name?:?string,contact_email?:?string,contact_phone?:?string,default_lead_time_days?:int,notes?:?string} $payload
     */
    function suppliersUpdate(\PDO $db, int $supplierId, array $payload): void
    {
        suppliersEnsureSchema($db);

        $fields = [];
        $params = [
            ':id' => $supplierId,
        ];

        $map = [
            'name' => ':name',
            'contact_name' => ':contact_name',
            'contact_email' => ':contact_email',
            'contact_phone' => ':contact_phone',
            'default_lead_time_days' => ':default_lead_time_days',
            'notes' => ':notes',
        ];

        foreach ($map as $key => $placeholder) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $fields[] = $key . ' = ' . $placeholder;
            $params[$placeholder] = $payload[$key];
        }

        if ($fields === []) {
            return;
        }

        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE suppliers SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $statement = $db->prepare($sql);
        $statement->execute($params);
    }
}
