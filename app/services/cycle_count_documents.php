<?php

declare(strict_types=1);

$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

use Dompdf\Dompdf;

require_once __DIR__ . '/../data/cycle_counts.php';

if (!function_exists('cycleCountReportHtml')) {
    /**
     * @param array{id:int,name:string,status:string,started_at:string,completed_at:?string,location_filter:?string,lines:array<int,array{id:int,sequence:int,location:string,sku:string,item:string,expected_qty:int,counted_qty:?int,variance:?int,is_skipped:bool,note:?string,counted_at:?string}>} $report
     */
    function cycleCountReportHtml(array $report): string
    {
        $startedAt = $report['started_at'] !== '' ? date('M j, Y g:ia', strtotime($report['started_at'])) : '';
        $completedAt = $report['completed_at'] !== null && $report['completed_at'] !== ''
            ? date('M j, Y g:ia', strtotime($report['completed_at']))
            : null;

        $countedLines = [];
        $skippedLines = [];

        foreach ($report['lines'] as $line) {
            if ($line['is_skipped'] === false && $line['counted_qty'] !== null) {
                $countedLines[] = $line;
            } else {
                $skippedLines[] = $line;
            }
        }

        $html = '<!doctype html><html lang="en"><head>'
            . '<meta charset="utf-8" />'
            . '<title>Cycle Count Report</title>'
            . '<style>'
            . 'body { font-family: Arial, sans-serif; margin: 36px; color: #111; }'
            . 'h1 { font-size: 24px; margin-bottom: 4px; }'
            . 'h2 { margin-top: 32px; font-size: 18px; }'
            . 'p.meta { margin: 0 0 12px; color: #444; }'
            . '.meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px 16px; margin: 12px 0 20px; }'
            . '.meta-grid div { font-size: 14px; }'
            . 'table { width: 100%; border-collapse: collapse; margin-top: 10px; }'
            . 'th, td { border: 1px solid #dcdfe3; padding: 8px 10px; font-size: 13px; }'
            . 'th { background: #f3f4f7; text-align: left; text-transform: uppercase; font-size: 12px; letter-spacing: .04em; color: #333; }'
            . 'td.numeric { text-align: right; font-variant-numeric: tabular-nums; }'
            . '.muted { color: #666; }'
            . '.badge { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #eef1f6; color: #2d3748; font-weight: 600; font-size: 12px; }'
            . '.section { margin-top: 28px; }'
            . '</style>'
            . '</head><body>';

        $html .= '<h1>Cycle Count Report</h1>'
            . '<p class="meta">Session #' . htmlspecialchars((string) $report['id'], ENT_QUOTES, 'UTF-8') . ' · '
            . htmlspecialchars($report['name'], ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div class="meta-grid">'
            . '<div><strong>Started:</strong> ' . htmlspecialchars($startedAt, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div><strong>Completed:</strong> ' . ($completedAt !== null ? htmlspecialchars($completedAt, ENT_QUOTES, 'UTF-8') : '<span class="muted">In progress</span>') . '</div>'
            . '<div><strong>Locations:</strong> ' . ($report['location_filter'] !== null ? htmlspecialchars($report['location_filter'], ENT_QUOTES, 'UTF-8') : 'All') . '</div>'
            . '<div><strong>Line items:</strong> ' . count($report['lines']) . '</div>'
            . '</div>';

        $html .= '<div class="section">'
            . '<h2>Counted items (' . count($countedLines) . ')</h2>';

        if ($countedLines === []) {
            $html .= '<p class="muted">No counted items were recorded.</p>';
        } else {
            $html .= '<table><thead><tr>'
                . '<th>Location</th><th>SKU</th><th>Item</th><th class="numeric">Counted</th><th class="numeric">Expected</th><th class="numeric">Variance</th><th>Notes</th>'
                . '</tr></thead><tbody>';

            foreach ($countedLines as $line) {
                $variance = $line['variance'] ?? ($line['counted_qty'] !== null ? $line['counted_qty'] - $line['expected_qty'] : 0);
                $note = $line['note'] !== null && $line['note'] !== ''
                    ? htmlspecialchars($line['note'], ENT_QUOTES, 'UTF-8')
                    : '<span class="muted">—</span>';

                $html .= '<tr>'
                    . '<td>' . htmlspecialchars($line['location'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars($line['sku'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars($line['item'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td class="numeric">' . ($line['counted_qty'] !== null ? htmlspecialchars((string) $line['counted_qty'], ENT_QUOTES, 'UTF-8') : '—') . '</td>'
                    . '<td class="numeric">' . htmlspecialchars((string) $line['expected_qty'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td class="numeric">' . htmlspecialchars((string) $variance, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . $note . '</td>'
                    . '</tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '</div>'
            . '<div class="section">'
            . '<h2>Skipped or pending items (' . count($skippedLines) . ')</h2>';

        if ($skippedLines === []) {
            $html .= '<p class="muted">No skipped lines.</p>';
        } else {
            $html .= '<table><thead><tr>'
                . '<th>Location</th><th>SKU</th><th>Item</th><th class="numeric">Expected</th><th>Status</th><th>Notes</th>'
                . '</tr></thead><tbody>';

            foreach ($skippedLines as $line) {
                $note = $line['note'] !== null && $line['note'] !== ''
                    ? htmlspecialchars($line['note'], ENT_QUOTES, 'UTF-8')
                    : '<span class="muted">—</span>';
                $statusLabel = $line['is_skipped'] === true ? 'Skipped' : 'Pending';

                $html .= '<tr>'
                    . '<td>' . htmlspecialchars($line['location'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars($line['sku'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars($line['item'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td class="numeric">' . htmlspecialchars((string) $line['expected_qty'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td><span class="badge">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span></td>'
                    . '<td>' . $note . '</td>'
                    . '</tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '</div></body></html>';

        return $html;
    }
}

if (!function_exists('generateCycleCountReportPdfFromData')) {
    /**
     * @param array{id:int,name:string,status:string,started_at:string,completed_at:?string,location_filter:?string,lines:array<int,array{id:int,sequence:int,location:string,sku:string,item:string,expected_qty:int,counted_qty:?int,variance:?int,is_skipped:bool,note:?string,counted_at:?string}>} $report
     */
    function generateCycleCountReportPdfFromData(array $report): string
    {
        $html = cycleCountReportHtml($report);

        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException('PDF rendering is unavailable: install PHP dependencies with composer.');
        }

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}

if (!function_exists('generateCycleCountReportPdfContent')) {
    function generateCycleCountReportPdfContent(\PDO $db, int $sessionId): string
    {
        $report = loadCycleCountSessionReport($db, $sessionId);

        if ($report === null) {
            throw new \RuntimeException('Cycle count session not found.');
        }

        return generateCycleCountReportPdfFromData($report);
    }
}
