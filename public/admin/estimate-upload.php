<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers/estimate_uploads.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed.']);
    exit;
}

$uploadId = estimate_upload_sanitize_id($_POST['upload_id'] ?? null);

if ($uploadId === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid upload identifier.']);
    exit;
}

$chunkIndex = filter_var($_POST['chunk_index'] ?? null, FILTER_VALIDATE_INT);
$totalChunks = filter_var($_POST['total_chunks'] ?? null, FILTER_VALIDATE_INT);
$fileName = isset($_POST['file_name']) ? trim((string) $_POST['file_name']) : '';

if ($chunkIndex === false || $chunkIndex < 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid chunk index.']);
    exit;
}

if ($totalChunks === false || $totalChunks < 1) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid chunk count.']);
    exit;
}

if ($chunkIndex >= $totalChunks) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Chunk index exceeds total chunk count.']);
    exit;
}

if (!isset($_FILES['chunk'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Chunk payload missing.']);
    exit;
}

/** @var array{tmp_name?:string,error?:int,size?:int} $chunkFile */
$chunkFile = $_FILES['chunk'];
$errorCode = $chunkFile['error'] ?? UPLOAD_ERR_NO_FILE;

if ($errorCode !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => sprintf('Chunk upload failed with code %d.', $errorCode)]);
    exit;
}

$tmpName = $chunkFile['tmp_name'] ?? null;

if (!is_string($tmpName) || $tmpName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Chunk storage missing.']);
    exit;
}

$paths = estimate_upload_paths($uploadId);

if ($chunkIndex === 0) {
    if (is_file($paths['file'])) {
        @unlink($paths['file']);
    }

    if (is_file($paths['meta'])) {
        @unlink($paths['meta']);
    }
}

$chunkData = file_get_contents($tmpName);

if ($chunkData === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => 'Unable to read uploaded chunk.']);
    exit;
}

if ($chunkData === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Chunk payload is empty.']);
    exit;
}

$bytesWritten = file_put_contents(
    $paths['file'],
    $chunkData,
    $chunkIndex === 0 ? LOCK_EX : FILE_APPEND | LOCK_EX
);

if ($bytesWritten === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => 'Failed to persist uploaded chunk.']);
    exit;
}

$metadata = estimate_upload_load_metadata($uploadId) ?? [
    'name' => $fileName,
    'size' => 0,
    'chunks' => $totalChunks,
    'created_at' => time(),
];

$metadata['name'] = $fileName !== '' ? $fileName : ($metadata['name'] ?? 'workbook.xlsx');
$metadata['size'] = (int) ($metadata['size'] ?? 0) + strlen($chunkData);
$metadata['chunks'] = $totalChunks;
$metadata['updated_at'] = time();
$metadata['complete'] = ($chunkIndex + 1) >= $totalChunks;

estimate_upload_store_metadata($uploadId, $metadata);

echo json_encode([
    'status' => 'ok',
    'chunk' => $chunkIndex,
    'complete' => $metadata['complete'],
    'bytes' => strlen($chunkData),
]);
