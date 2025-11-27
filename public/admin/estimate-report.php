<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/services/estimate_report.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed.']);
    exit;
}

$rawAnalysis = isset($_POST['analysis']) ? (string) $_POST['analysis'] : '';
$reportTitle = isset($_POST['title']) && trim((string) $_POST['title']) !== ''
    ? trim((string) $_POST['title'])
    : 'EZ Estimate comparison';

$analysis = json_decode($rawAnalysis, true);

if (!is_array($analysis) || !isset($analysis['items']) || !is_array($analysis['items'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Missing or invalid comparison data.']);
    exit;
}

try {
    $pdf = estimateComparisonPdf($analysis, $reportTitle);
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Unable to generate report: ' . $exception->getMessage()]);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="estimate-comparison.pdf"');
header('Content-Length: ' . strlen($pdf));

echo $pdf;

