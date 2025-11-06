<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/xlsx.php';
require_once __DIR__ . '/../data/inventory.php';

if (!class_exists('EstimateAnalysisException')) {
    /**
     * @extends \RuntimeException
     */
    class EstimateAnalysisException extends \RuntimeException
    {
        /** @var list<array{message:string,at:float}> */
        private array $log;

        /**
         * @param list<array{message:string,at:float}> $log
         */
        public function __construct(string $message, array $log, ?\Throwable $previous = null)
        {
            parent::__construct($message, 0, $previous);
            $this->log = $log;
        }

        /**
         * @return list<array{message:string,at:float}>
         */
        public function getLog(): array
        {
            return $this->log;
        }
    }
}

if (!function_exists('analyzeEstimateRequirements')) {

    function analyzeEstimateRequirements(\PDO $db, string $filePath): array
    {
        ensureInventorySchema($db);

        $startTime = microtime(true);
        $debugLog = [];
        $log = static function (string $message) use (&$debugLog, $startTime): void {
            $debugLog[] = [
                'at' => microtime(true) - $startTime,
                'message' => $message,
            ];
        };

        $log('Initializing inventory schema checks.');

        try {
            $sheetNames = [];

            try {
                $availableSheets = xlsxListSheets($filePath);

                if ($availableSheets !== []) {
                    $log(sprintf('Workbook exposes %d sheet(s).', count($availableSheets)));

                    $groups = [
                        'accessories' => [],
                        'stock_lengths' => [],
                        'special_length' => [],
                    ];

                    foreach ($availableSheets as $candidate) {
                        $normalized = strtolower(trim($candidate));

                        if (preg_match('/^accessories(\b|\s|\(|-|$)/', $normalized) === 1) {
                            $groups['accessories'][] = $candidate;
                            continue;
                        }

                        if (preg_match('/^stock lengths(\b|\s|\(|-|$)/', $normalized) === 1) {
                            $groups['stock_lengths'][] = $candidate;
                            continue;
                        }

                        if (preg_match('/^special length(\b|\s|\(|-|$)/', $normalized) === 1) {
                            $groups['special_length'][] = $candidate;
                        }
                    }

                    $order = ['accessories', 'stock_lengths', 'special_length'];
                    $seen = [];

                    foreach ($order as $groupKey) {
                        foreach ($groups[$groupKey] as $name) {
                            if (!isset($seen[$name])) {
                                $sheetNames[] = $name;
                                $seen[$name] = true;
                            }
                        }
                    }
                }
            } catch (\Throwable $exception) {
                $log('Unable to enumerate workbook sheets: ' . $exception->getMessage());
            }

            if ($sheetNames === []) {
                $sheetNames = [
                    'Accessories', 'Accessories (2)', 'Accessories (3)',
                    'Stock Lengths', 'Stock Lengths (2)', 'Stock Lengths (3)',
                    'Special Length',
                ];

                $log('Falling back to default sheet list: ' . implode(', ', $sheetNames));
            } else {
                $log('Dynamic sheet list: ' . implode(', ', $sheetNames));
            }

            // ---- helpers -------------------------------------------------------

            $findHeaderIndex = static function (array $map, array $candidates) use (&$log): ?int {
                foreach ($map as $index => $value) {
                    $normalized = strtolower(trim((string) $value));
                    $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

                    foreach ($candidates as $candidate) {
                        if ($normalized === $candidate) {
                            $log(sprintf('Header match "%s" detected at column %d.', $candidate, $index));
                            return $index;
                        }

                        if ($candidate === 'part' && str_contains($normalized, 'part')) {
                            $log(sprintf('Header partial match for part detected at column %d.', $index));
                            return $index;
                        }

                        if ($candidate === 'qty' && (
                            $normalized === 'qty'
                            || $normalized === 'quantity'
                            || str_contains($normalized, 'qty')
                            || str_contains($normalized, 'quantity')
                        )) {
                            $log(sprintf('Header partial match for quantity detected at column %d.', $index));
                            return $index;
                        }

                        if ($candidate === 'finish' && str_contains($normalized, 'finish')) {
                            $log(sprintf('Header partial match for finish detected at column %d.', $index));
                            return $index;
                        }

                        if ($candidate === 'color' && str_contains($normalized, 'color')) {
                            $log(sprintf('Header partial match for color detected at column %d.', $index));
                            return $index;
                        }
                    }
                }

                return null;
            };

            // try to find header row + column indexes for qty/part/finish
            $findTable = static function(array $rows) use ($findHeaderIndex, &$log): ?array {
                // scan first 60 rows for headers
                $headerRowIndex = null;
                $idxQty = $idxPart = $idxFinish = null;

                for ($r = 0; $r < min(60, count($rows)); $r++) {
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
                        if ($cQty === $cPart) {
                            $log(sprintf(
                                'Row %d rejected as header: qty/part resolved to the same column %d.',
                                $r + 1,
                                $cQty
                            ));
                            continue;
                        }

                        $headerRowIndex = $r;
                        $idxQty = $cQty;
                        $idxPart = $cPart;
                        $idxFinish = $cFinish; // may be null
                        break;
                    }
                }

                if ($headerRowIndex === null) {
                    $log('Header row could not be identified in the scanned range.');
                    return null;
                }

                $log(sprintf('Headers located on row %d (qty=%d, part=%d, finish=%s).',
                    $headerRowIndex + 1,
                    $idxQty,
                    $idxPart,
                    $idxFinish !== null ? (string) $idxFinish : 'null'
                ));

                return [
                    'headerRow' => $headerRowIndex,
                    'qtyCol'    => $idxQty,
                    'partCol'   => $idxPart,
                    'finishCol' => $idxFinish,
                ];
            };

            // Read a broad range and return rows (arrays of scalar cell values)
            $readBroad = static function(string $file, string $sheet) use (&$log): array {
                // Big, safe superset; cheap to slice in PHP and avoids off-by-one grief
                try {
                    $log(sprintf('Reading range A1:BZ2000 from sheet "%s".', $sheet));
                    return xlsxReadRange($file, $sheet, 'A1', 'BZ2000');
                } catch (\Throwable $e) {
                    $log(sprintf('Failed to read sheet "%s": %s', $sheet, $e->getMessage()));
                    return []; // handled by caller
                }
            };

            // ---- main parse ----------------------------------------------------

            $requirements = [];
            $messages = [];

            $log(sprintf('Processing sheets: %s', implode(', ', $sheetNames)));

            foreach ($sheetNames as $name) {
                $log(sprintf('Starting sheet "%s".', $name));
                $rows = $readBroad($filePath, $name);
                if (!$rows) {
                    $messages[] = ['type' => 'warning', 'text' => "Sheet $name not found or unreadable."];
                    $log(sprintf('Sheet "%s" yielded no rows.', $name));
                    continue;
                }

                $table = $findTable($rows);
                if ($table === null) {
                    $messages[] = ['type' => 'warning', 'text' => "Sheet $name: no headers (qty/part) detected in top 60 rows."];
                    $log(sprintf('Sheet "%s" skipped due to missing headers.', $name));
                    continue;
                }

                $startDataRow = $table['headerRow'] + 1;
                $qtyCol = $table['qtyCol'];
                $partCol = $table['partCol'];
                $finishCol = $table['finishCol']; // may be null

                // walk until 6 consecutive empty data rows
                $emptyStreak = 0;
                $rowCount = 0;
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
                        $log(sprintf('Sheet "%s" row %d skipped: missing part.', $name, $r + 1));
                        continue;
                    }

                    // strip commas/spaces; allow decimals
                    $qtyNormalized = (float) str_replace([',', ' '], '', $qtyRaw);
                    if (!is_finite($qtyNormalized) || $qtyNormalized <= 0) {
                        // soft-skip; many rows have notes or formulas
                        $log(sprintf('Sheet "%s" row %d ignored: qty "%s" not positive.', $name, $r + 1, $qtyRaw));
                        continue;
                    }
                    $quantity = (int) round($qtyNormalized);

                    $finish = $finishRaw !== '' ? inventoryNormalizeFinish($finishRaw) : null;
                    if ($finishRaw !== '' && $finish === null) {
                        $messages[] = ['type' => 'warning', 'text' => "Sheet $name row ".($r+1)." has unrecognised finish \"$finishRaw\"; treated as unspecified."];
                        $log(sprintf('Sheet "%s" row %d finish "%s" not recognized.', $name, $r + 1, $finishRaw));
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
                    $rowCount++;
                }

                $log(sprintf('Sheet "%s" contributed %d line items.', $name, $rowCount));
            }

            // inventory join stays the same --------------------------------------
            $log(sprintf('Joining %d unique requirements with inventory.', count($requirements)));
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

            $log(sprintf('Analysis complete. Totals: total=%d, available=%d, short=%d, missing=%d.',
                $counts['total'],
                $counts['available'],
                $counts['short'],
                $counts['missing']
            ));

            return ['items'=>$items,'messages'=>$messages,'counts'=>$counts,'log'=>$debugLog];
        } catch (EstimateAnalysisException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $log('Fatal error: ' . $exception->getMessage());
            throw new EstimateAnalysisException($exception->getMessage(), $debugLog, $exception);
        }
    }
}
