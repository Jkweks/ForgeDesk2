<?php

declare(strict_types=1);

if (!function_exists('inventoryMetricsList')) {
    /**
     * @return list<array{id:int,label:string,value:string,delta:?string,timeframe:?string,accent:bool,sort_order:int}>
     */
    function inventoryMetricsList(\PDO $db): array
    {
        $statement = $db->query(
            'SELECT id, label, value, delta, timeframe, accent, sort_order'
            . ' FROM inventory_metrics'
            . ' ORDER BY sort_order ASC, id ASC'
        );

        if ($statement === false) {
            return [];
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'label' => (string) $row['label'],
                    'value' => (string) $row['value'],
                    'delta' => $row['delta'] !== null ? (string) $row['delta'] : null,
                    'timeframe' => $row['timeframe'] !== null ? (string) $row['timeframe'] : null,
                    'accent' => filter_var($row['accent'], FILTER_VALIDATE_BOOLEAN),
                    'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 100,
                ];
            },
            $rows
        );
    }

    /**
     * @return array{id:int,label:string,value:string,delta:?string,timeframe:?string,accent:bool,sort_order:int}|null
     */
    function inventoryMetricsFind(\PDO $db, int $metricId): ?array
    {
        $statement = $db->prepare(
            'SELECT id, label, value, delta, timeframe, accent, sort_order'
            . ' FROM inventory_metrics WHERE id = :id'
        );
        $statement->bindValue(':id', $metricId, \PDO::PARAM_INT);
        $statement->execute();

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'value' => (string) $row['value'],
            'delta' => $row['delta'] !== null ? (string) $row['delta'] : null,
            'timeframe' => $row['timeframe'] !== null ? (string) $row['timeframe'] : null,
            'accent' => filter_var($row['accent'], FILTER_VALIDATE_BOOLEAN),
            'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 100,
        ];
    }

    function inventoryMetricsLabelExists(\PDO $db, string $label, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM inventory_metrics WHERE LOWER(label) = LOWER(:label)';
        $params = [':label' => $label];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $statement = $db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array{label:string,value:string,delta:?string,timeframe:?string,accent:bool,sort_order:int} $payload
     */
    function inventoryMetricsCreate(\PDO $db, array $payload): int
    {
        $statement = $db->prepare(
            'INSERT INTO inventory_metrics (label, value, delta, timeframe, accent, sort_order)'
            . ' VALUES (:label, :value, :delta, :timeframe, :accent, :sort_order)'
            . ' RETURNING id'
        );

        $statement->execute([
            ':label' => $payload['label'],
            ':value' => $payload['value'],
            ':delta' => $payload['delta'],
            ':timeframe' => $payload['timeframe'],
            ':accent' => $payload['accent'],
            ':sort_order' => $payload['sort_order'],
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{label?:string,value?:string,delta?:?string,timeframe?:?string,accent?:bool,sort_order?:int} $payload
     */
    function inventoryMetricsUpdate(\PDO $db, int $metricId, array $payload): void
    {
        $fields = [];
        $params = [
            ':id' => $metricId,
        ];

        $map = [
            'label' => ':label',
            'value' => ':value',
            'delta' => ':delta',
            'timeframe' => ':timeframe',
            'accent' => ':accent',
            'sort_order' => ':sort_order',
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

        $sql = 'UPDATE inventory_metrics SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $statement = $db->prepare($sql);
        $statement->execute($params);
    }

    function inventoryMetricsDelete(\PDO $db, int $metricId): void
    {
        $statement = $db->prepare('DELETE FROM inventory_metrics WHERE id = :id');
        $statement->bindValue(':id', $metricId, \PDO::PARAM_INT);
        $statement->execute();
    }
}
