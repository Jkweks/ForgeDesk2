ALTER TABLE maintenance_tasks
    ADD COLUMN IF NOT EXISTS status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused', 'retired')),
    ADD COLUMN IF NOT EXISTS priority TEXT NOT NULL DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high', 'critical'));

CREATE INDEX IF NOT EXISTS idx_maintenance_tasks_status ON maintenance_tasks (status);
