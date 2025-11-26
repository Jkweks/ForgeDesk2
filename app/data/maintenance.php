<?php

declare(strict_types=1);

/**
 * @return array<int,array<string,mixed>>
 */
function maintenanceMachineList(\PDO $db): array
{
    $sql = <<<SQL
        SELECT
            m.*,
            COALESCE(task_counts.task_count, 0) AS task_count,
            latest.performed_at AS last_service_at,
            COALESCE(downtime.total_downtime_minutes, 0) AS total_downtime_minutes
        FROM maintenance_machines AS m
        LEFT JOIN (
            SELECT machine_id, COUNT(*) AS task_count
            FROM maintenance_tasks
            GROUP BY machine_id
        ) AS task_counts ON task_counts.machine_id = m.id
        LEFT JOIN (
            SELECT machine_id, SUM(downtime_minutes) AS total_downtime_minutes
            FROM maintenance_records
            GROUP BY machine_id
        ) AS downtime ON downtime.machine_id = m.id
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
                'total_downtime_minutes' => (int) $row['total_downtime_minutes'],
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
function maintenanceTasksList(\PDO $db, bool $includeRetired = false): array
{
    $includeRetiredFlag = $includeRetired ? 1 : 0;

    $sql = <<<SQL
        SELECT
            t.*,
            m.name AS machine_name,
            last_records.last_performed_at,
            COALESCE(last_records.last_performed_at, t.last_completed_at, t.start_date) AS base_completed_at,
            CASE
                WHEN t.interval_count IS NOT NULL
                    AND t.interval_unit IS NOT NULL
                    AND COALESCE(last_records.last_performed_at, t.last_completed_at, t.start_date) IS NOT NULL
                THEN (
                    COALESCE(last_records.last_performed_at, t.last_completed_at, t.start_date)
                        + (t.interval_count || ' ' || t.interval_unit)::interval
                )::date
                ELSE NULL
            END AS next_due_date,
            CASE
                WHEN t.interval_count IS NOT NULL
                    AND t.interval_unit IS NOT NULL
                    AND COALESCE(last_records.last_performed_at, t.last_completed_at, t.start_date) IS NOT NULL
                THEN (
                    COALESCE(last_records.last_performed_at, t.last_completed_at, t.start_date)
                        + (t.interval_count || ' ' || t.interval_unit)::interval
                )::date < CURRENT_DATE
                ELSE FALSE
            END AS is_overdue
        FROM maintenance_tasks AS t
        INNER JOIN maintenance_machines AS m ON m.id = t.machine_id
        LEFT JOIN (
            SELECT task_id, MAX(performed_at) AS last_performed_at
            FROM maintenance_records
            WHERE performed_at IS NOT NULL
            GROUP BY task_id
        ) AS last_records ON last_records.task_id = t.id
        WHERE (:include_retired = 1 OR t.status <> 'retired')
        ORDER BY
            CASE t.priority
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
                ELSE 5
            END ASC,
            m.name ASC,
            t.title ASC
    SQL;

    $statement = $db->prepare($sql);
    $statement->execute([
        'include_retired' => $includeRetiredFlag,
    ]);

    return array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'machine_id' => (int) $row['machine_id'],
            'title' => (string) $row['title'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'frequency' => $row['frequency'] !== null ? (string) $row['frequency'] : null,
            'assigned_to' => $row['assigned_to'] !== null ? (string) $row['assigned_to'] : null,
            'machine_name' => (string) $row['machine_name'],
            'status' => (string) $row['status'],
            'priority' => (string) $row['priority'],
            'interval_count' => $row['interval_count'] !== null ? (int) $row['interval_count'] : null,
            'interval_unit' => $row['interval_unit'] !== null ? (string) $row['interval_unit'] : null,
            'start_date' => $row['start_date'] !== null ? (string) $row['start_date'] : null,
            'last_completed_at' => $row['last_completed_at'] !== null ? (string) $row['last_completed_at'] : null,
            'next_due_date' => $row['next_due_date'] !== null ? (string) $row['next_due_date'] : null,
            'is_overdue' => (bool) $row['is_overdue'],
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
        INSERT INTO maintenance_tasks (
            machine_id,
            title,
            description,
            frequency,
            assigned_to,
            interval_count,
            interval_unit,
            start_date,
            status,
            priority
        )
        VALUES (
            :machine_id,
            :title,
            :description,
            :frequency,
            :assigned_to,
            :interval_count,
            :interval_unit,
            :start_date,
            :status,
            :priority
        )
        RETURNING id
    SQL;

    $statement = $db->prepare($sql);
    $statement->execute([
        'machine_id' => $payload['machine_id'],
        'title' => $payload['title'],
        'description' => $payload['description'],
        'frequency' => $payload['frequency'],
        'assigned_to' => $payload['assigned_to'],
        'interval_count' => $payload['interval_count'],
        'interval_unit' => $payload['interval_unit'],
        'start_date' => $payload['start_date'],
        'status' => $payload['status'],
        'priority' => $payload['priority'],
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
                'downtime_minutes' => $row['downtime_minutes'] !== null ? (int) $row['downtime_minutes'] : null,
                'labor_hours' => $row['labor_hours'] !== null ? (float) $row['labor_hours'] : null,
                'parts_used' => maintenanceDecodeParts($row['parts_used'] ?? '[]'),
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
        INSERT INTO maintenance_records (
            machine_id,
            task_id,
            performed_by,
            performed_at,
            notes,
            attachments,
            downtime_minutes,
            labor_hours,
            parts_used
        )
        VALUES (
            :machine_id,
            :task_id,
            :performed_by,
            :performed_at,
            :notes,
            :attachments,
            :downtime_minutes,
            :labor_hours,
            :parts_used
        )
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
        'downtime_minutes' => $payload['downtime_minutes'],
        'labor_hours' => $payload['labor_hours'],
        'parts_used' => maintenanceEncodeParts($payload['parts_used'] ?? []),
    ]);

    $recordId = (int) $statement->fetchColumn();

    if ($payload['task_id'] !== null && $payload['performed_at'] !== null) {
        $update = $db->prepare(
            'UPDATE maintenance_tasks SET last_completed_at = :performed_at, updated_at = NOW() WHERE id = :task_id'
        );
        $update->execute([
            'performed_at' => $payload['performed_at'],
            'task_id' => $payload['task_id'],
        ]);
    }

    return $recordId;
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

/**
 * @param array<int,string> $parts
 */
function maintenanceEncodeParts(array $parts): string
{
    $cleaned = [];

    foreach ($parts as $part) {
        $partValue = trim((string) $part);

        if ($partValue === '') {
            continue;
        }

        $cleaned[] = $partValue;
    }

    $encoded = json_encode($cleaned, JSON_UNESCAPED_SLASHES);

    return $encoded !== false ? $encoded : '[]';
}

/**
 * @return array<int,string>
 */
function maintenanceDecodeParts(null|string $encoded): array
{
    if (!is_string($encoded) || $encoded === '') {
        return [];
    }

    $decoded = json_decode($encoded, true);

    if (!is_array($decoded)) {
        return [];
    }

    $parts = [];

    foreach ($decoded as $value) {
        if (is_string($value)) {
            $partValue = trim($value);
            if ($partValue !== '') {
                $parts[] = $partValue;
            }
        }
    }

    return $parts;
}
