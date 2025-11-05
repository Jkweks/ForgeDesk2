<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/xlsx.php';
require_once __DIR__ . '/../data/inventory.php';

if (!function_exists('analyzeEstimateRequirements')) {

    function analyzeEstimateRequirements(\PDO $db, string $filePath): array
    {
        ensureInventorySchema($db);

        // Accept all three “Accessories*”, all three “Stock Lengths*”, and Special Length
        $sheetNames = [
            'Accessories', 'Accessories (2)', 'Accessories (3)',
            'Stock Lengths', 'Stock Lengths (2)', 'Stock Lengths (3)',
            'Special Length',
        ];

        // ---- helpers -------------------------------------------------------

        $findHeaderIndex = static function (array $map, array $candidates): ?int {
            foreach ($map as $index => $value) {
                $normalized = strtolower(trim((string) $value));
                $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

                foreach ($candidates as $candidate) {
                    if ($normalized === $candidate) {
                        return $index;
                    }

                    if ($candidate === 'part' && str_contains($normalized, 'part')) {
                        return $index;
                    }

                    if ($candidate === 'qty' && ($normalized === 'qty' || $normalized === 'quantity')) {
                        return $index;
                    }

                    if ($candidate === 'finish' && str_contains($normalized, 'finish')) {
                        return $index;
                    }

                    if ($candidate === 'color' && str_contains($normalized, 'color')) {
                        return $index;
                    }
                }
            }

            return null;
        };

        // try to find header row + column indexes for qty/part/finish
        $findTable = static function(array $rows) use ($findHeaderIndex): ?array {
            // scan first 20 rows for headers
            $headerRowIndex = null;
            $idxQty = $idxPart = $idxFinish = null;

            for ($r = 0; $r < min(20, count($rows)); $r++) {
                $row = $rows[$r] ?? [];
                // quick skip for very empty rows
                $nonEmpty = 0;
                foreach ($row as $v) { if (trim((string)$v) !== '') { $nonEmpty++; } }
                if ($nonEmpty < 2) continue;

                $map = [];
                foreach ($row as $c => $val) {
                    $map[$c] = strtolower(trim((string) $val));
                }

                // candidates with common variants
                $cQty    = $findHeaderIndex($map, ['qty', 'quantity']);
                $cPart   = $findHeaderIndex($map, ['part #', 'part#', 'part no', 'part', 'item']);
                $cFinish = $findHeaderIndex($map, ['finish', 'color']); // accept color as finish fallback

                if ($cQty !== null && $cPart !== null) {
                    $headerRowIndex = $r;
                    $idxQty = $cQty;
                    $idxPart = $cPart;
                    $idxFinish = $cFinish; // may be null
                    break;
                }
            }

            if ($headerRowIndex === null) {
                return null;
            }

            return [
                'headerRow' => $headerRowIndex,
                'qtyCol'    => $idxQty,
                'partCol'   => $idxPart,
                'finishCol' => $idxFinish,
            ];
        };

        // Read a broad range and return rows (arrays of scalar cell values)
        $readBroad = static function(string $file, string $sheet): array {
            // Big, safe superset; cheap to slice in PHP and avoids off-by-one grief
            try {
                return xlsxReadRange($file, $sheet, 'A1', 'Z2000');
            } catch (\Throwable $e) {
                return []; // handled by caller
            }
        };

        // ---- main parse ----------------------------------------------------

        $requirements = [];
        $messages = [];

        foreach ($sheetNames as $name) {
            $rows = $readBroad($filePath, $name);
            if (!$rows) {
                $messages[] = ['type' => 'warning', 'text' => "Sheet $name not found or unreadable."];
                continue;
            }

            $table = $findTable($rows);
            if ($table === null) {
                $messages[] = ['type' => 'warning', 'text' => "Sheet $name: no headers (qty/part) detected in top 20 rows."];
                continue;
            }

            $startDataRow = $table['headerRow'] + 1;
            $qtyCol = $table['qtyCol'];
            $partCol = $table['partCol'];
            $finishCol = $table['finishCol']; // may be null

            // walk until 6 consecutive empty data rows
            $emptyStreak = 0;
            for ($r = $startDataRow; $r < count($rows); $r++) {
                $row = $rows[$r] ?? [];
                $qtyRaw    = trim((string)($row[$qtyCol]   ?? ''));
                $partRaw   = trim((string)($row[$partCol]  ?? ''));
                $finishRaw = trim((string)($finishCol !== null ? ($row[$finishCol] ?? '') : ''));

                if ($qtyRaw === '' && $partRaw === '' && $finishRaw === '') {
                    $emptyStreak++;
                    if ($emptyStreak >= 6) break;
                    continue;
                } else {
                    $emptyStreak = 0;
                }

                if ($partRaw === '') {
                    $messages[] = ['type' => 'warning', 'text' => "Sheet $name row ".($r+1)." skipped: part number missing."];
                    continue;
                }

                // strip commas/spaces; allow decimals
                $qtyNormalized = (float) str_replace([',', ' '], '', $qtyRaw);
                if (!is_finite($qtyNormalized) || $qtyNormalized <= 0) {
                    // soft-skip; many rows have notes or formulas
                    continue;
                }
                $quantity = (int) round($qtyNormalized);

                $finish = $finishRaw !== '' ? inventoryNormalizeFinish($finishRaw) : null;
                if ($finishRaw !== '' && $finish === null) {
                    $messages[] = ['type' => 'warning', 'text' => "Sheet $name row ".($r+1)." has unrecognised finish \"$finishRaw\"; treated as unspecified."];
                }

                $normalizedPart = strtoupper($partRaw);
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

        // inventory join stays the same --------------------------------------
        $inventory   = loadInventory($db);
        $inventoryIndex = [];
        foreach ($inventory as $item) {
            $normalizedPart = strtoupper($item['part_number']);
            $finish = $item['finish'] ?? null;
            $key = $normalizedPart . '|' . ($finish ?? '');
            if (!isset($inventoryIndex[$key])) {
                $inventoryIndex[$key] = [
                    'stock' => (int) $item['stock'],
                    'sku'   => (string) $item['sku'],
                    'finish' => $finish,
                    'part_number' => $item['part_number'],
                ];
            }
        }

        $items = [];
        $counts = ['total'=>0,'available'=>0,'short'=>0,'missing'=>0];

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
                'finish'      => $finish,
                'required'    => $requirement['required'],
                'available'   => $available,
                'shortfall'   => $shortfall,
                'status'      => $status,
                'sku'         => $sku,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $order = ['missing' => 0, 'short' => 1, 'available' => 2];
            $statusCompare = $order[$a['status']] <=> $order[$b['status']];
            return $statusCompare !== 0 ? $statusCompare : strcmp($a['part_number'], $b['part_number']);
        });

        // if truly nothing parsed, give the UI a friendly breadcrumb instead of a silent reset
        if ($counts['total'] === 0) {
            $messages[] = ['type' => 'error', 'text' => 'No line items detected. Check that the sheet contains headers like "Qty", "Part #", and rows beneath them.'];
        }

        return ['items'=>$items,'messages'=>$messages,'counts'=>$counts];
    }
}
