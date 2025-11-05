<?php

declare(strict_types=1);

if (!function_exists('loadMetrics')) {
    /**
     * Fetch dashboard metrics ordered by their configured sort weight.
     *
     * @return array<int, array{label:string,value:string|int,delta:?string,time:?string,accent:bool}>
     */
    function loadMetrics(\PDO $db): array
    {
        try {
            $statement = $db->query(
                'SELECT label, value, delta, timeframe, accent FROM inventory_metrics ORDER BY sort_order ASC, id ASC'
            );

            $rows = $statement->fetchAll();

            return array_map(
                static fn (array $row): array => [
                    'label' => (string) $row['label'],
                    'value' => is_numeric($row['value']) ? (int) $row['value'] : (string) $row['value'],
                    'delta' => $row['delta'] !== null ? (string) $row['delta'] : null,
                    'time' => $row['timeframe'] !== null ? (string) $row['timeframe'] : null,
                    'accent' => filter_var($row['accent'], FILTER_VALIDATE_BOOLEAN),
                ],
                $rows
            );
        } catch (\PDOException $exception) {
            throw new \PDOException('Unable to load metrics: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }
}
