-- 202411130002_consolidate_job_reservation_schema.sql
-- Normalize job_reservation_status, reservations tables, committed_qty, and commitment view.

-- Ensure job_reservation_status enum exists with full label set
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'job_reservation_status') THEN
        CREATE TYPE job_reservation_status AS ENUM (
            'draft',
            'committed',
            'active',
            'on_hold',
            'in_progress',
            'fulfilled',
            'cancelled'
        );
    ELSE
        -- Add any missing values (safe even if already there)
        ALTER TYPE job_reservation_status ADD VALUE IF NOT EXISTS 'draft';
        ALTER TYPE job_reservation_status ADD VALUE IF NOT EXISTS 'committed';
        ALTER TYPE job_reservation_status ADD VALUE IF NOT EXISTS 'active';
        ALTER TYPE job_reservation_status ADD VALUE IF NOT EXISTS 'on_hold';
        ALTER TYPE job_reservation_status ADD VALUE IF NOT EXISTS 'in_progress';
        ALTER TYPE job_reservation_status ADD VALUE IF NOT EXISTS 'fulfilled';
        ALTER TYPE job_reservation_status ADD VALUE IF NOT EXISTS 'cancelled';
    END IF;
END
$$;

-- Make sure the timestamp trigger function exists
CREATE OR REPLACE FUNCTION set_updated_at_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Job reservations header (idempotent)
CREATE TABLE IF NOT EXISTS job_reservations (
    id SERIAL PRIMARY KEY,
    job_number TEXT NOT NULL UNIQUE,
    job_name TEXT NOT NULL,
    requested_by TEXT NOT NULL,
    needed_by DATE NULL,
    status job_reservation_status NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Updated-at trigger (drop + recreate to be sure)
DROP TRIGGER IF EXISTS trg_job_reservations_updated_at ON job_reservations;
CREATE TRIGGER trg_job_reservations_updated_at
    BEFORE UPDATE ON job_reservations
    FOR EACH ROW
    EXECUTE FUNCTION set_updated_at_timestamp();

-- Job reservation items
CREATE TABLE IF NOT EXISTS job_reservation_items (
    id SERIAL PRIMARY KEY,
    reservation_id INTEGER NOT NULL REFERENCES job_reservations(id) ON DELETE CASCADE,
    inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE RESTRICT,
    requested_qty INTEGER NOT NULL DEFAULT 0,
    committed_qty INTEGER NOT NULL DEFAULT 0,
    consumed_qty INTEGER NOT NULL DEFAULT 0,
    UNIQUE (reservation_id, inventory_item_id)
);

CREATE INDEX IF NOT EXISTS idx_job_reservation_items_reservation
    ON job_reservation_items (reservation_id);

CREATE INDEX IF NOT EXISTS idx_job_reservation_items_inventory
    ON job_reservation_items (inventory_item_id);

-- Ensure inventory_items has committed_qty column
ALTER TABLE inventory_items
    ADD COLUMN IF NOT EXISTS committed_qty INTEGER NOT NULL DEFAULT 0;

-- Normalize commitments view to match the enum semantics
DROP VIEW IF EXISTS inventory_item_commitments;

CREATE VIEW inventory_item_commitments AS
SELECT
    i.id AS inventory_item_id,
    COALESCE(
        SUM(
            CASE
                WHEN jr.status IN ('active', 'committed', 'in_progress', 'on_hold')
                    THEN jri.committed_qty
                ELSE 0
            END
        ),
        0
    ) AS committed_qty
FROM inventory_items i
LEFT JOIN job_reservation_items jri
    ON jri.inventory_item_id = i.id
LEFT JOIN job_reservations jr
    ON jr.id = jri.reservation_id
GROUP BY i.id;
