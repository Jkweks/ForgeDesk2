ALTER TABLE maintenance_records
    ADD COLUMN IF NOT EXISTS downtime_minutes INTEGER,
    ADD COLUMN IF NOT EXISTS labor_hours NUMERIC(10,2),
    ADD COLUMN IF NOT EXISTS parts_used JSONB NOT NULL DEFAULT '[]'::jsonb;
