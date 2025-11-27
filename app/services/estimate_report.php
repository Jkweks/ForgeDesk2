<?php

declare(strict_types=1);

$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

use Dompdf\Dompdf;

if (!function_exists('estimateComparisonHtml')) {
    /**
     * @param array{items:list<array{part_number:string,finish:?string,required:int,available:?int,shortfall:int,status:string,sku:?string}>,messages?:array<int,array{type:string,text:string}>} $analysis
     */
    function estimateComparisonHtml(array $analysis, string $title): string
    {
        $items = $analysis['items'] ?? [];

        $groups = [
            'missing' => [],
            'short' => [],
            'available' => [],
        ];

        foreach ($items as $item) {
            $status = $item['status'] ?? 'missing';
            if (!isset($groups[$status])) {
                $status = 'missing';
            }
            $groups[$status][] = $item;
        }

        $sectionOrder = [
            'missing' => 'Not in inventory',
            'short' => 'Short / overcommitted',
            'available' => 'Ready to commit',
        ];

        $style = <<<STYLE
        <style>
          body { font-family: Arial, sans-serif; margin: 32px; color: #1a202c; }
          h1 { font-size: 24px; margin: 0 0 8px; }
          h2 { font-size: 18px; margin: 28px 0 10px; }
          p.meta { margin: 0 0 12px; color: #4a5568; }
          table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; }
          th, td { border: 1px solid #e2e8f0; padding: 8px 10px; font-size: 13px; }
          th { background: #f8fafc; text-align: left; text-transform: uppercase; letter-spacing: .05em; font-size: 12px; color: #334155; }
          td.numeric { text-align: right; font-variant-numeric: tabular-nums; }
          .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
          .badge-missing { background: #fff1f2; color: #9f1239; }
          .badge-short { background: #fff7ed; color: #9a3412; }
          .badge-available { background: #ecfeff; color: #0f172a; }
          .muted { color: #64748b; }
          .messages { margin: 16px 0; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
          .messages li { margin: 4px 0; font-size: 13px; }
        </style>
        STYLE;

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8" />'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
            . $style
            . '</head><body>';

        $html .= '<h1>EZ Estimate comparison</h1>'
            . '<p class="meta">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p class="meta">Summary: '
            . htmlspecialchars((string) count($items), ENT_QUOTES, 'UTF-8') . ' line(s) reviewed.</p>';

        if (!empty($analysis['messages'])) {
            $html .= '<ul class="messages">';
            foreach ($analysis['messages'] as $message) {
                $html .= '<li>' . htmlspecialchars($message['text'] ?? '', ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $html .= '</ul>';
        }

        foreach ($sectionOrder as $key => $heading) {
            $lines = $groups[$key];
            $badgeClass = $key === 'available'
                ? 'badge-available'
                : ($key === 'short' ? 'badge-short' : 'badge-missing');

            $html .= '<h2>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8')
                . ' (' . count($lines) . ')</h2>';

            if ($lines === []) {
                $html .= '<p class="muted">None.</p>';
                continue;
            }

            $html .= '<table><thead><tr>'
                . '<th>Part #</th><th>Finish</th><th>SKU</th>'
                . '<th class="numeric">Required</th><th class="numeric">Available</th>'
                . '<th class="numeric">Shortfall</th><th>Status</th>'
                . '</tr></thead><tbody>';

            foreach ($lines as $line) {
                $finish = $line['finish'] ?? '—';
                $available = $line['available'];
                $shortfall = $line['shortfall'] ?? 0;
                $statusLabel = $key === 'available'
                    ? 'Can fulfill'
                    : ($key === 'short' ? 'Short by ' . max(0, (int) $shortfall) : 'Not in database');

                $html .= '<tr>'
                    . '<td>' . htmlspecialchars($line['part_number'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars((string) $finish, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . ($line['sku'] !== null ? htmlspecialchars((string) $line['sku'], ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>') . '</td>'
                    . '<td class="numeric">' . htmlspecialchars((string) ($line['required'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td class="numeric">' . ($available !== null ? htmlspecialchars((string) $available, ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>') . '</td>'
                    . '<td class="numeric">' . htmlspecialchars((string) max(0, (int) $shortfall), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td><span class="badge ' . $badgeClass . '">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span></td>'
                    . '</tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '</body></html>';

        return $html;
    }
}

if (!function_exists('estimateComparisonPdf')) {
    /**
     * @param array{items:list<array{part_number:string,finish:?string,required:int,available:?int,shortfall:int,status:string,sku:?string}>,messages?:array<int,array{type:string,text:string}>} $analysis
     */
    function estimateComparisonPdf(array $analysis, string $title): string
    {
        $html = estimateComparisonHtml($analysis, $title);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
