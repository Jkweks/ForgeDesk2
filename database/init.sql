CREATE TABLE IF NOT EXISTS inventory_items (
    id SERIAL PRIMARY KEY,
    item TEXT NOT NULL,
    sku TEXT NOT NULL UNIQUE,
    part_number TEXT NOT NULL DEFAULT '',
    finish TEXT NULL,
    location TEXT NOT NULL,
    stock INTEGER NOT NULL DEFAULT 0,
    committed_qty INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'In Stock',
    supplier TEXT NOT NULL DEFAULT 'Unknown Supplier',
    supplier_contact TEXT NULL,
    reorder_point INTEGER NOT NULL DEFAULT 0,
    lead_time_days INTEGER NOT NULL DEFAULT 0,
    average_daily_use NUMERIC(12,4) NULL
);

ALTER TABLE inventory_items
    ADD COLUMN IF NOT EXISTS supplier TEXT NOT NULL DEFAULT 'Unknown Supplier',
    ADD COLUMN IF NOT EXISTS supplier_contact TEXT NULL,
    ADD COLUMN IF NOT EXISTS reorder_point INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS lead_time_days INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS part_number TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS finish TEXT NULL,
    ADD COLUMN IF NOT EXISTS committed_qty INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS average_daily_use NUMERIC(12,4) NULL;

CREATE TABLE IF NOT EXISTS inventory_metrics (
    id SERIAL PRIMARY KEY,
    label TEXT NOT NULL UNIQUE,
    value TEXT NOT NULL,
    delta TEXT NULL,
    timeframe TEXT NULL,
    accent BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INTEGER NOT NULL DEFAULT 100
);

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

CREATE TABLE IF NOT EXISTS cycle_count_sessions (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'in_progress',
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    location_filter TEXT NULL,
    total_lines INTEGER NOT NULL DEFAULT 0,
    completed_lines INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS cycle_count_lines (
    id SERIAL PRIMARY KEY,
    session_id INTEGER NOT NULL REFERENCES cycle_count_sessions(id) ON DELETE CASCADE,
    inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    sequence INTEGER NOT NULL,
    expected_qty INTEGER NOT NULL DEFAULT 0,
    counted_qty INTEGER NULL,
    variance INTEGER NULL,
    counted_at TIMESTAMP NULL,
    note TEXT NULL,
    UNIQUE(session_id, sequence)
);

CREATE INDEX IF NOT EXISTS idx_cycle_count_lines_session_sequence
    ON cycle_count_lines (session_id, sequence);

CREATE INDEX IF NOT EXISTS idx_cycle_count_lines_inventory
    ON cycle_count_lines (inventory_item_id);

CREATE TABLE IF NOT EXISTS inventory_transactions (
    id SERIAL PRIMARY KEY,
    reference TEXT NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_transaction_lines (
    id SERIAL PRIMARY KEY,
    transaction_id INTEGER NOT NULL REFERENCES inventory_transactions(id) ON DELETE CASCADE,
    inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE RESTRICT,
    quantity_change INTEGER NOT NULL,
    note TEXT NULL,
    stock_before INTEGER NOT NULL DEFAULT 0,
    stock_after INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_inventory_transaction_lines_transaction
    ON inventory_transaction_lines (transaction_id);

CREATE INDEX IF NOT EXISTS idx_inventory_transaction_lines_item
    ON inventory_transaction_lines (inventory_item_id);

CREATE TABLE IF NOT EXISTS inventory_daily_usage (
    inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    usage_date DATE NOT NULL,
    quantity_used INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (inventory_item_id, usage_date)
);

CREATE INDEX IF NOT EXISTS idx_inventory_daily_usage_date
    ON inventory_daily_usage (usage_date);

INSERT INTO inventory_items (item, sku, part_number, finish, location, stock, status, supplier, supplier_contact, reorder_point, lead_time_days, average_daily_use) VALUES
    ('Aluminum Stile - 2"', 'AL-ST-02-0R', 'AL-ST-02', '0R', 'Aisle 1 / Bin 4', 86, 'In Stock', 'DoorCraft Metals', 'sales@doorcraftmetals.com', 40, 7, 3.5000),
    ('Tempered Glass Panel 36x84', 'GL-3684-BL', 'GL-3684', 'BL', 'Aisle 3 / Rack 2', 24, 'Reorder', 'ClearView Glass', 'orders@clearviewglass.com', 30, 14, 1.2500),
    ('Hinge Set - Heavy Duty', 'HD-HG-SET-DB', 'HD-HG-SET', 'DB', 'Aisle 2 / Bin 8', 140, 'In Stock', 'Precision Hardware', 'account@precisionhardware.com', 60, 10, 4.7500),
    ('Threshold Extrusion', 'AL-TH-10-C2', 'AL-TH-10', 'C2', 'Aisle 5 / Bin 1', 12, 'Low', 'Alloy Profiles Inc.', 'support@alloyprofiles.com', 20, 12, 0.9000),
    ('Exit Device Kit', 'EX-KT-44-BL', 'EX-KT-44', 'BL', 'Aisle 4 / Shelf 6', 6, 'Critical', 'SecureLatch Systems', 'rep@securelatchsystems.com', 15, 21, 0.6500)
ON CONFLICT (sku) DO UPDATE SET
    item = EXCLUDED.item,
    location = EXCLUDED.location,
    stock = EXCLUDED.stock,
    status = EXCLUDED.status,
    supplier = EXCLUDED.supplier,
    supplier_contact = EXCLUDED.supplier_contact,
    reorder_point = EXCLUDED.reorder_point,
    lead_time_days = EXCLUDED.lead_time_days,
    part_number = EXCLUDED.part_number,
    finish = EXCLUDED.finish,
    committed_qty = EXCLUDED.committed_qty,
    average_daily_use = EXCLUDED.average_daily_use;

INSERT INTO inventory_metrics (label, value, delta, timeframe, accent, sort_order) VALUES
    ('SKUs Tracked', '248', '+12 vs. last quarter', 'Quarter to date', FALSE, 10),
    ('Units on Hand', '2680', '-4% vs. target', 'Weekly', FALSE, 20),
    ('Critical Items', '6', '+2 new issues', 'Daily', TRUE, 30),
    ('Supplier OTIF', '92%', '+3 pts', 'Monthly', TRUE, 40)
ON CONFLICT (label) DO UPDATE SET
    value = EXCLUDED.value,
    delta = EXCLUDED.delta,
    timeframe = EXCLUDED.timeframe,
    accent = EXCLUDED.accent,
    sort_order = EXCLUDED.sort_order;

INSERT INTO job_reservations (job_number, job_name, requested_by, needed_by, status, notes)
VALUES
    ('JOB-2024-001', 'Downtown Lobby Retrofit', 'Morgan Smith', CURRENT_DATE + INTERVAL '14 days', 'committed', 'Initial staging reservation for retrofit work')
ON CONFLICT (job_number) DO UPDATE SET
    job_name = EXCLUDED.job_name,
    requested_by = EXCLUDED.requested_by,
    needed_by = EXCLUDED.needed_by,
    status = EXCLUDED.status,
    notes = EXCLUDED.notes;

INSERT INTO job_reservation_items (reservation_id, inventory_item_id, requested_qty, committed_qty, consumed_qty)
SELECT r.id, i.id, 20, 16, 0
FROM job_reservations r
JOIN inventory_items i ON i.sku = 'AL-ST-02-0R'
WHERE r.job_number = 'JOB-2024-001'
ON CONFLICT (reservation_id, inventory_item_id) DO UPDATE SET
    requested_qty = EXCLUDED.requested_qty,
    committed_qty = EXCLUDED.committed_qty,
    consumed_qty = EXCLUDED.consumed_qty;

INSERT INTO job_reservation_items (reservation_id, inventory_item_id, requested_qty, committed_qty, consumed_qty)
SELECT r.id, i.id, 8, 6, 0
FROM job_reservations r
JOIN inventory_items i ON i.sku = 'HD-HG-SET-DB'
WHERE r.job_number = 'JOB-2024-001'
ON CONFLICT (reservation_id, inventory_item_id) DO UPDATE SET
    requested_qty = EXCLUDED.requested_qty,
    committed_qty = EXCLUDED.committed_qty,
    consumed_qty = EXCLUDED.consumed_qty;
