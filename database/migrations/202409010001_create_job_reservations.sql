DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'job_reservation_status') THEN
        CREATE TYPE job_reservation_status AS ENUM (
            'draft',
            'committed',
            'in_progress',
            'fulfilled',
            'cancelled'
        );
    END IF;
END
$$;

CREATE OR REPLACE FUNCTION set_updated_at_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

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

DROP TRIGGER IF EXISTS trg_job_reservations_updated_at ON job_reservations;
CREATE TRIGGER trg_job_reservations_updated_at
    BEFORE UPDATE ON job_reservations
    FOR EACH ROW
    EXECUTE FUNCTION set_updated_at_timestamp();

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

ALTER TABLE inventory_items
    ADD COLUMN IF NOT EXISTS committed_qty INTEGER NOT NULL DEFAULT 0;
