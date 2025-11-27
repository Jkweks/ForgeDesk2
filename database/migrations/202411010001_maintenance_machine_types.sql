CREATE TABLE IF NOT EXISTS maintenance_machine_types (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO maintenance_machine_types (name)
VALUES
    ('CNC Machining Center'),
    ('Chop saw'),
    ('Upcut Saw'),
    ('Drill Press'),
    ('Punch Press')
ON CONFLICT (name) DO NOTHING;

ALTER TABLE maintenance_machines
    ADD COLUMN IF NOT EXISTS machine_type_id BIGINT REFERENCES maintenance_machine_types(id);

DO $$
DECLARE
    record_row RECORD;
    type_id BIGINT;
BEGIN
    FOR record_row IN SELECT id, equipment_type FROM maintenance_machines LOOP
        IF record_row.equipment_type IS NULL OR trim(record_row.equipment_type) = '' THEN
            CONTINUE;
        END IF;

        INSERT INTO maintenance_machine_types (name)
        VALUES (record_row.equipment_type)
        ON CONFLICT (name) DO UPDATE SET updated_at = NOW()
        RETURNING id INTO type_id;

        UPDATE maintenance_machines
        SET machine_type_id = type_id
        WHERE id = record_row.id;
    END LOOP;
END $$;

UPDATE maintenance_machines
SET equipment_type = (SELECT name FROM maintenance_machine_types WHERE id = machine_type_id)
WHERE machine_type_id IS NOT NULL;
