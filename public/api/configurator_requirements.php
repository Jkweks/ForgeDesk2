<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/helpers/database.php';
require_once __DIR__ . '/../../app/data/configurator.php';

session_start();

$app = require __DIR__ . '/../../app/config/app.php';
$databaseConfig = $app['database'];

try {
    $db = dbConnect($databaseConfig);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'apply_requirements':
            // Get current configuration state from request
            $config = $input['config'] ?? [];

            if (empty($config)) {
                http_response_code(400);
                echo json_encode(['error' => 'Configuration data required']);
                exit;
            }

            // Apply all requirements
            $result = configuratorApplyAllRequirements($db, $config);

            echo json_encode([
                'success' => true,
                'config' => $result['config'],
                'added_parts' => $result['added_parts'],
            ]);
            break;

        case 'validate_requirements':
            // Validate configuration against requirements
            $config = $input['config'] ?? [];

            if (empty($config)) {
                http_response_code(400);
                echo json_encode(['error' => 'Configuration data required']);
                exit;
            }

            $errors = configuratorValidateRequirements($db, $config);

            echo json_encode([
                'success' => true,
                'errors' => $errors,
                'is_valid' => empty($errors),
            ]);
            break;

        case 'check_part_requirements':
            // Check requirements for a single part
            $partId = $input['part_id'] ?? null;
            $context = $input['context'] ?? [];

            if ($partId === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Part ID required']);
                exit;
            }

            $requirements = configuratorGetApplicableRequirements($db, (int)$partId, $context);

            echo json_encode([
                'success' => true,
                'requirements' => $requirements,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage(),
    ]);
}
