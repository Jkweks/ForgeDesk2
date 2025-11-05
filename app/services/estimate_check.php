<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/xlsx.php';
require_once __DIR__ . '/../data/inventory.php';

if (!function_exists('analyzeEstimateRequirements')) {
    /**
     * @return array{
     *   items:list<array{
     *     part_number:string,
     *     finish:?string,
     *     required:int,
     *     available:?int,
     *     shortfall:int,
     *     status:string,
     *     sku:?string
     *   }>,
     *   messages:list<array{type:string,text:string}>,
     *   counts:array{total:int,available:int,short:int,missing:int}
     * }
     */
    function analyzeEstimateRequirements(\PDO $db, string $filePath): array
    {
        ensureInventorySchema($db);

        $sheetRanges = [
            ['name' => 'Accessories', 'start' => 'A11', 'end' => 'C46'],
            ['name' => 'Accessories (2)', 'start' => 'A11', 'end' => 'C46'],
            ['name' => 'Accessories (3)', 'start' => 'A11', 'end' => 'C46'],
            ['name' => 'Stock Lengths', 'start' => 'A11', 'end' => 'C47'],
            ['name' => 'Stock Lengths (2)', 'start' => 'A11', 'end' => 'C47'],
            ['name' => 'Stock Lengths (3)', 'start' => 'A11', 'end' => 'C47'],
        ];

        $requirements = [];
        $messages = [];

        foreach ($sheetRanges as $config) {
            $name = $config['name'];
            $startCell = $config['start'];
            $endCell = $config['end'];
            $startReference = xlsxSplitCellReference($startCell);
            $startRow = $startReference[1];

            try {
                $rows = xlsxReadRange($filePath, $name, $startCell, $endCell);
            } catch (\Throwable $exception) {
                $messages[] = [
                    'type' => 'error',
                    'text' => sprintf('Unable to read sheet %s: %s', $name, $exception->getMessage()),
                ];
                continue;
            }

            foreach ($rows as $offset => $row) {
                $rowNumber = $startRow + $offset;
                $quantityRaw = trim((string) ($row[0] ?? ''));
                $partNumberRaw = trim((string) ($row[1] ?? ''));
                $finishRaw = trim((string) ($row[2] ?? ''));

                if ($quantityRaw === '' && $partNumberRaw === '' && $finishRaw === '') {
                    continue;
                }

                if ($partNumberRaw === '') {
                    $messages[] = [
                        'type' => 'warning',
                        'text' => sprintf('Sheet %s row %d skipped: part number is missing.', $name, $rowNumber),
                    ];
                    continue;
                }

                $quantityNormalized = (float) str_replace([',', ' '], '', $quantityRaw);

                if (!is_finite($quantityNormalized)) {
                    $messages[] = [
                        'type' => 'warning',
                        'text' => sprintf('Sheet %s row %d skipped: quantity "%s" is invalid.', $name, $rowNumber, $quantityRaw),
                    ];
                    continue;
                }

                $quantity = (int) round($quantityNormalized);

                if ($quantity <= 0) {
                    continue;
                }

                $finish = $finishRaw !== '' ? inventoryNormalizeFinish($finishRaw) : null;
                if ($finishRaw !== '' && $finish === null) {
                    $messages[] = [
                        'type' => 'warning',
                        'text' => sprintf('Sheet %s row %d has unrecognised finish "%s"; treated as unspecified.', $name, $rowNumber, $finishRaw),
                    ];
                }

                $normalizedPart = strtoupper($partNumberRaw);
                $key = $normalizedPart . '|' . ($finish ?? '');

                if (!isset($requirements[$key])) {
                    $requirements[$key] = [
                        'part_number' => $normalizedPart,
                        'finish' => $finish,
                        'required' => 0,
                    ];
                }

                $requirements[$key]['required'] += $quantity;
            }
        }

        $inventory = loadInventory($db);
        $inventoryIndex = [];

        foreach ($inventory as $item) {
            $normalizedPart = strtoupper($item['part_number']);
            $finish = $item['finish'] ?? null;
            $key = $normalizedPart . '|' . ($finish ?? '');

            if (!isset($inventoryIndex[$key])) {
                $inventoryIndex[$key] = [
                    'stock' => (int) $item['stock'],
                    'sku' => (string) $item['sku'],
                    'finish' => $finish,
                    'part_number' => $item['part_number'],
                ];
            }
        }

        $items = [];
        $counts = [
            'total' => 0,
            'available' => 0,
            'short' => 0,
            'missing' => 0,
        ];

        foreach ($requirements as $key => $requirement) {
            $counts['total']++;
            $match = $inventoryIndex[$key] ?? null;
            $available = $match['stock'] ?? null;
            $sku = $match['sku'] ?? null;
            $finish = $requirement['finish'];
            $partNumber = $requirement['part_number'];
            $status = 'missing';
            $shortfall = $requirement['required'];

            if ($match !== null) {
                $availableQty = (int) $match['stock'];
                $status = $availableQty >= $requirement['required'] ? 'available' : 'short';
                $shortfall = max(0, $requirement['required'] - $availableQty);
                $counts[$status]++;
                $available = $availableQty;
                $partNumber = $match['part_number'];
                $finish = $match['finish'];
            } else {
                $counts['missing']++;
            }

            $items[] = [
                'part_number' => $partNumber,
                'finish' => $finish,
                'required' => $requirement['required'],
                'available' => $available,
                'shortfall' => $shortfall,
                'status' => $status,
                'sku' => $sku,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $order = ['missing' => 0, 'short' => 1, 'available' => 2];
            $statusCompare = $order[$a['status']] <=> $order[$b['status']];
            if ($statusCompare !== 0) {
                return $statusCompare;
            }

            return strcmp($a['part_number'], $b['part_number']);
        });

        return [
            'items' => $items,
            'messages' => $messages,
            'counts' => $counts,
        ];
    }
}
