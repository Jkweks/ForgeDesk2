ALTER TABLE maintenance_tasks
    ADD COLUMN IF NOT EXISTS interval_count INTEGER CHECK (interval_count > 0),
    ADD COLUMN IF NOT EXISTS interval_unit TEXT CHECK (interval_unit IN ('day', 'week', 'month', 'year')),
    ADD COLUMN IF NOT EXISTS start_date DATE,
    ADD COLUMN IF NOT EXISTS last_completed_at DATE;
