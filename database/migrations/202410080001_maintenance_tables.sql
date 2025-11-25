CREATE TABLE IF NOT EXISTS maintenance_machines (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    equipment_type TEXT NOT NULL,
    manufacturer TEXT,
    model TEXT,
    serial_number TEXT,
    location TEXT,
    documents JSONB NOT NULL DEFAULT '[]'::jsonb,
    notes TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS maintenance_tasks (
    id BIGSERIAL PRIMARY KEY,
    machine_id BIGINT NOT NULL REFERENCES maintenance_machines(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    description TEXT,
    frequency TEXT,
    assigned_to TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS maintenance_records (
    id BIGSERIAL PRIMARY KEY,
    machine_id BIGINT NOT NULL REFERENCES maintenance_machines(id) ON DELETE CASCADE,
    task_id BIGINT REFERENCES maintenance_tasks(id) ON DELETE SET NULL,
    performed_by TEXT,
    performed_at DATE,
    notes TEXT,
    attachments JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS maintenance_tasks_machine_id_idx ON maintenance_tasks(machine_id);
CREATE INDEX IF NOT EXISTS maintenance_records_machine_id_idx ON maintenance_records(machine_id);
CREATE INDEX IF NOT EXISTS maintenance_records_task_id_idx ON maintenance_records(task_id);
