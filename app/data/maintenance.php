<?php

declare(strict_types=1);

/**
 * @return array<int,array<string,mixed>>
 */
function maintenanceMachineList(\PDO $db): array
{
    $sql = <<<SQL
        SELECT m.*, COALESCE(task_counts.task_count, 0) AS task_count, latest.performed_at AS last_service_at
        FROM maintenance_machines AS m
        LEFT JOIN (
            SELECT machine_id, COUNT(*) AS task_count
            FROM maintenance_tasks
            GROUP BY machine_id
        ) AS task_counts ON task_counts.machine_id = m.id
        LEFT JOIN (
            SELECT DISTINCT ON (machine_id) machine_id, performed_at
            FROM maintenance_records
            ORDER BY machine_id, performed_at DESC
        ) AS latest ON latest.machine_id = m.id
        ORDER BY m.name ASC
    SQL;

    $statement = $db->query($sql);
    $rows = $statement->fetchAll();

    return array_map(
        static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'equipment_type' => (string) $row['equipment_type'],
                'manufacturer' => $row['manufacturer'] !== null ? (string) $row['manufacturer'] : null,
                'model' => $row['model'] !== null ? (string) $row['model'] : null,
                'serial_number' => $row['serial_number'] !== null ? (string) $row['serial_number'] : null,
                'location' => $row['location'] !== null ? (string) $row['location'] : null,
                'documents' => maintenanceDecodeDocuments($row['documents'] ?? '[]'),
                'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
                'task_count' => (int) $row['task_count'],
                'last_service_at' => $row['last_service_at'] !== null ? (string) $row['last_service_at'] : null,
            ];
        },
        $rows
    );
}

/**
 * @param array<string,mixed> $payload
 */
function maintenanceMachineCreate(\PDO $db, array $payload): int
{
    $sql = <<<SQL
        INSERT INTO maintenance_machines (name, equipment_type, manufacturer, model, serial_number, location, documents, notes)
        VALUES (:name, :equipment_type, :manufacturer, :model, :serial_number, :location, :documents, :notes)
        RETURNING id
    SQL;

    $statement = $db->prepare($sql);
    $statement->execute([
        'name' => $payload['name'],
        'equipment_type' => $payload['equipment_type'],
        'manufacturer' => $payload['manufacturer'],
        'model' => $payload['model'],
        'serial_number' => $payload['serial_number'],
        'location' => $payload['location'],
        'documents' => maintenanceEncodeDocuments($payload['documents'] ?? []),
        'notes' => $payload['notes'],
    ]);

    return (int) $statement->fetchColumn();
}

/**
 * @return array<int,array<string,mixed>>
 */
function maintenanceTasksList(\PDO $db): array
{
    $sql = <<<SQL
        SELECT t.*, m.name AS machine_name
        FROM maintenance_tasks AS t
        INNER JOIN maintenance_machines AS m ON m.id = t.machine_id
        ORDER BY m.name ASC, t.title ASC
    SQL;

    $statement = $db->query($sql);

    return array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'machine_id' => (int) $row['machine_id'],
            'title' => (string) $row['title'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'frequency' => $row['frequency'] !== null ? (string) $row['frequency'] : null,
            'assigned_to' => $row['assigned_to'] !== null ? (string) $row['assigned_to'] : null,
            'machine_name' => (string) $row['machine_name'],
        ],
        $statement->fetchAll()
    );
}

/**
 * @param array<string,mixed> $payload
 */
function maintenanceTaskCreate(\PDO $db, array $payload): int
{
    $sql = <<<SQL
        INSERT INTO maintenance_tasks (machine_id, title, description, frequency, assigned_to)
        VALUES (:machine_id, :title, :description, :frequency, :assigned_to)
        RETURNING id
    SQL;

    $statement = $db->prepare($sql);
    $statement->execute([
        'machine_id' => $payload['machine_id'],
        'title' => $payload['title'],
        'description' => $payload['description'],
        'frequency' => $payload['frequency'],
        'assigned_to' => $payload['assigned_to'],
    ]);

    return (int) $statement->fetchColumn();
}

/**
 * @return array<int,array<string,mixed>>
 */
function maintenanceRecordsList(\PDO $db): array
{
    $sql = <<<SQL
        SELECT r.*, m.name AS machine_name, t.title AS task_title
        FROM maintenance_records AS r
        INNER JOIN maintenance_machines AS m ON m.id = r.machine_id
        LEFT JOIN maintenance_tasks AS t ON t.id = r.task_id
        ORDER BY r.performed_at DESC NULLS LAST, r.id DESC
    SQL;

    $statement = $db->query($sql);

    return array_map(
        static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'machine_id' => (int) $row['machine_id'],
                'task_id' => $row['task_id'] !== null ? (int) $row['task_id'] : null,
                'performed_by' => $row['performed_by'] !== null ? (string) $row['performed_by'] : null,
                'performed_at' => $row['performed_at'] !== null ? (string) $row['performed_at'] : null,
                'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
                'machine_name' => (string) $row['machine_name'],
                'task_title' => $row['task_title'] !== null ? (string) $row['task_title'] : null,
                'attachments' => maintenanceDecodeDocuments($row['attachments'] ?? '[]'),
            ];
        },
        $statement->fetchAll()
    );
}

/**
 * @param array<string,mixed> $payload
 */
function maintenanceRecordCreate(\PDO $db, array $payload): int
{
    $sql = <<<SQL
        INSERT INTO maintenance_records (machine_id, task_id, performed_by, performed_at, notes, attachments)
        VALUES (:machine_id, :task_id, :performed_by, :performed_at, :notes, :attachments)
        RETURNING id
    SQL;

    $statement = $db->prepare($sql);
    $statement->execute([
        'machine_id' => $payload['machine_id'],
        'task_id' => $payload['task_id'],
        'performed_by' => $payload['performed_by'],
        'performed_at' => $payload['performed_at'],
        'notes' => $payload['notes'],
        'attachments' => maintenanceEncodeDocuments($payload['attachments'] ?? []),
    ]);

    return (int) $statement->fetchColumn();
}

/**
 * @param array<int,array<string,string>> $documents
 */
function maintenanceEncodeDocuments(array $documents): string
{
    $cleaned = [];

    foreach ($documents as $document) {
        $label = trim((string) ($document['label'] ?? ''));
        $url = trim((string) ($document['url'] ?? ''));

        if ($label === '' && $url === '') {
            continue;
        }

        $cleaned[] = [
            'label' => $label !== '' ? $label : ($url !== '' ? $url : 'Document'),
            'url' => $url,
        ];
    }

    $encoded = json_encode($cleaned, JSON_UNESCAPED_SLASHES);

    return $encoded !== false ? $encoded : '[]';
}

/**
 * @return array<int,array{label:string,url:string}>
 */
function maintenanceDecodeDocuments(null|string $encoded): array
{
    if (!is_string($encoded) || $encoded === '') {
        return [];
    }

    $decoded = json_decode($encoded, true);

    if (!is_array($decoded)) {
        return [];
    }

    $documents = [];

    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $label = trim((string) ($row['label'] ?? ''));
        $url = trim((string) ($row['url'] ?? ''));

        if ($label === '' && $url === '') {
            continue;
        }

        $documents[] = [
            'label' => $label !== '' ? $label : ($url !== '' ? $url : 'Document'),
            'url' => $url,
        ];
    }

    return $documents;
}
