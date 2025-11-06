<?php

declare(strict_types=1);

if (!function_exists('estimate_upload_sanitize_id')) {
    function estimate_upload_sanitize_id(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $id = trim($id);

        if ($id === '' || preg_match('/^[A-Za-z0-9_-]{8,}$/', $id) !== 1) {
            return null;
        }

        return $id;
    }
}

if (!function_exists('estimate_upload_storage_dir')) {
    function estimate_upload_storage_dir(): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'estimate-uploads';

        if (!is_dir($base)) {
            $previousUmask = umask(0);
            @mkdir($base, 0770, true);
            umask($previousUmask);
        }

        return $base;
    }
}

if (!function_exists('estimate_upload_paths')) {
    /**
     * @return array{file:string,meta:string}
     */
    function estimate_upload_paths(string $uploadId): array
    {
        $safeId = preg_replace('/[^A-Za-z0-9_-]/', '', $uploadId);

        return [
            'file' => estimate_upload_storage_dir() . DIRECTORY_SEPARATOR . $safeId . '.xlsx',
            'meta' => estimate_upload_storage_dir() . DIRECTORY_SEPARATOR . $safeId . '.json',
        ];
    }
}

if (!function_exists('estimate_upload_store_metadata')) {
    /**
     * @param array<string,mixed> $metadata
     */
    function estimate_upload_store_metadata(string $uploadId, array $metadata): void
    {
        $paths = estimate_upload_paths($uploadId);
        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode upload metadata.');
        }

        file_put_contents($paths['meta'], $json, LOCK_EX);
    }
}

if (!function_exists('estimate_upload_load_metadata')) {
    /**
     * @return array<string,mixed>|null
     */
    function estimate_upload_load_metadata(string $uploadId): ?array
    {
        $paths = estimate_upload_paths($uploadId);

        if (!is_file($paths['meta'])) {
            return null;
        }

        $json = file_get_contents($paths['meta']);

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            return null;
        }

        return $data;
    }
}

if (!function_exists('estimate_upload_cleanup')) {
    function estimate_upload_cleanup(string $uploadId): void
    {
        $paths = estimate_upload_paths($uploadId);

        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
