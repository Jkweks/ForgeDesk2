<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/xlsx.php';
require_once __DIR__ . '/../data/inventory.php';

if (!function_exists('seedInventoryFromXlsx')) {
    /**
     * Import inventory records from an XLSX spreadsheet.
     *
     * @return array{
     *   processed:int,
     *   inserted:int,
     *   updated:int,
     *   skipped:int,
     *   messages:list<array{type:string,text:string}>,
     *   preview:list<array{row:int,item:string,sku:string,status:string,action:string}>
     * }
     */
    function seedInventoryFromXlsx(\PDO $db, string $filePath): array
    {
        ensureInventorySchema($db);

        $rows = xlsxReadRows($filePath);
        if ($rows === []) {
            throw new \RuntimeException('The spreadsheet does not contain any rows.');
        }

        $headers = array_map('trim', array_shift($rows));
        $normalizedHeaders = array_map(
            static fn (string $header): string => strtolower(preg_replace('/\s+/', ' ', $header)),
            $headers
        );

        $definitions = [
            'item' => ['labels' => ['item', 'item name', 'name', 'part name'], 'required' => true],
            'part_number' => ['labels' => ['part number', 'part', 'part #', 'partno'], 'required' => true],
            'finish' => ['labels' => ['finish', 'finish code'], 'required' => false],
            'location' => ['labels' => ['location', 'bin', 'shelf', 'warehouse location'], 'required' => true],
            'stock' => ['labels' => ['stock', 'qty', 'quantity', 'on hand'], 'required' => false, 'default' => '0'],
            'reorder_point' => ['labels' => ['reorder point', 'reorder', 'min qty', 'minimum quantity'], 'required' => false, 'default' => '0'],
            'supplier' => ['labels' => ['supplier', 'vendor'], 'required' => true],
            'supplier_contact' => ['labels' => ['supplier contact', 'contact', 'email'], 'required' => false, 'default' => ''],
            'lead_time_days' => ['labels' => ['lead time', 'lead time (days)', 'lead time days', 'lt'], 'required' => false, 'default' => '0'],
            'average_daily_use' => ['labels' => ['average daily use', 'avg daily use', 'average use', 'daily use'], 'required' => false, 'default' => ''],
            'status' => ['labels' => ['status', 'state', 'stock status'], 'required' => false, 'default' => ''],
        ];

        $headerIndexes = [];
        foreach ($definitions as $field => $definition) {
            $index = null;
            foreach ($definition['labels'] as $label) {
                $normalized = strtolower(preg_replace('/\s+/', ' ', $label));
                $position = array_search($normalized, $normalizedHeaders, true);
                if ($position !== false) {
                    $index = $position;
                    break;
                }
            }

            if ($index === null) {
                if (!empty($definition['required'])) {
                    throw new \RuntimeException(sprintf(
                        'The spreadsheet is missing the required "%s" column.',
                        $definition['labels'][0]
                    ));
                }
            } else {
                $headerIndexes[$field] = $index;
            }
        }

        $result = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'messages' => [],
            'preview' => [],
        ];

        $ignoredStatusValues = [];

        foreach ($rows as $offset => $row) {
            $rowNumber = $offset + 2; // account for header row

            $values = [];
            foreach ($definitions as $field => $definition) {
                $index = $headerIndexes[$field] ?? null;
                $raw = $index !== null && isset($row[$index]) ? trim((string) $row[$index]) : null;

                if ($raw === null || $raw === '') {
                    if (!empty($definition['required'])) {
                        $result['messages'][] = [
                            'type' => 'error',
                            'text' => sprintf('Row %d skipped: %s is required.', $rowNumber, ucfirst(str_replace('_', ' ', $field))),
                        ];
                        $result['skipped']++;
                        continue 2;
                    }

                    $raw = $definition['default'] ?? '';
                }

                $values[$field] = $raw;
            }

            $nonEmptyFields = array_filter([
                $values['item'] ?? '',
                $values['part_number'] ?? '',
                $values['location'] ?? '',
                $values['supplier'] ?? '',
            ], static fn (string $value): bool => $value !== '');

            if ($nonEmptyFields === []) {
                continue;
            }

            $result['processed']++;

            $stock = filter_var($values['stock'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($stock === false) {
                $result['messages'][] = [
                    'type' => 'warning',
                    'text' => sprintf('Row %d stock value "%s" is not numeric; defaulted to 0.', $rowNumber, $values['stock'] ?? ''),
                ];
                $stock = 0;
            }

            $reorder = filter_var($values['reorder_point'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($reorder === false) {
                $result['messages'][] = [
                    'type' => 'warning',
                    'text' => sprintf('Row %d reorder point "%s" is not numeric; defaulted to 0.', $rowNumber, $values['reorder_point'] ?? ''),
                ];
                $reorder = 0;
            }

            $leadTime = filter_var($values['lead_time_days'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($leadTime === false) {
                $result['messages'][] = [
                    'type' => 'warning',
                    'text' => sprintf('Row %d lead time "%s" is not numeric; defaulted to 0.', $rowNumber, $values['lead_time_days'] ?? ''),
                ];
                $leadTime = 0;
            }

            $dailyUseRaw = isset($values['average_daily_use']) ? trim((string) $values['average_daily_use']) : '';
            $averageDailyUseProvided = $dailyUseRaw !== '';
            $averageDailyUseParsed = null;

            if ($averageDailyUseProvided) {
                $dailyUseNumeric = filter_var($dailyUseRaw, FILTER_VALIDATE_FLOAT);
                if ($dailyUseNumeric === false || $dailyUseNumeric < 0) {
                    $result['messages'][] = [
                        'type' => 'warning',
                        'text' => sprintf('Row %d average daily use "%s" is invalid; value ignored.', $rowNumber, $dailyUseRaw),
                    ];
                    $averageDailyUseProvided = false;
                } else {
                    $averageDailyUseParsed = (float) $dailyUseNumeric;
                }
            }

            $statusValue = $values['status'] ?? '';
            $explicitStatusProvided = $statusValue !== '';
            $requestedDiscontinued = $explicitStatusProvided && inventoryIsDiscontinuedStatus($statusValue);

            if ($explicitStatusProvided && !$requestedDiscontinued) {
                $statusKey = strtolower($statusValue);
                if (!isset($ignoredStatusValues[$statusKey])) {
                    $result['messages'][] = [
                        'type' => 'info',
                        'text' => sprintf('Row %d status "%s" ignored; status will be recalculated from stock levels.', $rowNumber, $statusValue),
                    ];
                    $ignoredStatusValues[$statusKey] = true;
                }
            }

            $finishRaw = $values['finish'] ?? '';
            $finish = $finishRaw !== '' ? inventoryNormalizeFinish($finishRaw) : null;

            if ($finishRaw !== '' && $finish === null) {
                $result['messages'][] = [
                    'type' => 'warning',
                    'text' => sprintf('Row %d finish "%s" is not recognised; ignored.', $rowNumber, $finishRaw),
                ];
            }

            $supplierContact = ($values['supplier_contact'] ?? '') !== '' ? $values['supplier_contact'] : null;

            $sku = inventoryComposeSku($values['part_number'], $finish);

            $payload = [
                'item' => $values['item'],
                'sku' => $sku,
                'part_number' => $values['part_number'],
                'finish' => $finish,
                'location' => $values['location'],
                'stock' => $stock,
                'supplier' => $values['supplier'],
                'supplier_contact' => $supplierContact,
                'reorder_point' => $reorder,
                'lead_time_days' => $leadTime,
                'average_daily_use' => null,
            ];

            try {
                $existing = findInventoryItemBySku($db, $sku);

                $payload['committed_qty'] = $existing !== null ? (int) $existing['committed_qty'] : 0;
                $existingDailyUse = $existing !== null ? ($existing['average_daily_use'] ?? null) : null;

                if ($averageDailyUseProvided && $averageDailyUseParsed !== null) {
                    $payload['average_daily_use'] = number_format($averageDailyUseParsed, 4, '.', '');
                } elseif ($existingDailyUse !== null) {
                    $payload['average_daily_use'] = number_format((float) $existingDailyUse, 4, '.', '');
                }

                $shouldDiscontinue = $requestedDiscontinued
                    || (!$explicitStatusProvided && $existing !== null && ($existing['discontinued'] ?? false));
                $availableQty = $payload['stock'] - $payload['committed_qty'];
                $payload['status'] = $shouldDiscontinue
                    ? 'Discontinued'
                    : inventoryStatusFromAvailable($availableQty, $payload['reorder_point']);

                if ($existing === null) {
                    createInventoryItem($db, $payload);
                    $result['inserted']++;
                    $action = 'Inserted';
                } else {
                    updateInventoryItem($db, (int) $existing['id'], $payload);
                    $result['updated']++;
                    $action = 'Updated';
                }

                if (count($result['preview']) < 10) {
                    $result['preview'][] = [
                        'row' => $rowNumber,
                        'item' => $payload['item'],
                        'sku' => $payload['sku'],
                        'status' => $payload['status'],
                        'action' => $action,
                    ];
                }
            } catch (\Throwable $exception) {
                $result['messages'][] = [
                    'type' => 'error',
                    'text' => sprintf('Row %d failed to import: %s', $rowNumber, $exception->getMessage()),
                ];
                $result['skipped']++;
            }
        }

        return $result;
    }
}
