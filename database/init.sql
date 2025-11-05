CREATE TABLE IF NOT EXISTS inventory_items (
    id SERIAL PRIMARY KEY,
    item TEXT NOT NULL,
    sku TEXT NOT NULL UNIQUE,
    part_number TEXT NOT NULL DEFAULT '',
    finish TEXT NULL,
    location TEXT NOT NULL,
    stock INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'In Stock',
    supplier TEXT NOT NULL DEFAULT 'Unknown Supplier',
    supplier_contact TEXT NULL,
    reorder_point INTEGER NOT NULL DEFAULT 0,
    lead_time_days INTEGER NOT NULL DEFAULT 0
);

ALTER TABLE inventory_items
    ADD COLUMN IF NOT EXISTS supplier TEXT NOT NULL DEFAULT 'Unknown Supplier',
    ADD COLUMN IF NOT EXISTS supplier_contact TEXT NULL,
    ADD COLUMN IF NOT EXISTS reorder_point INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS lead_time_days INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS part_number TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS finish TEXT NULL;

CREATE TABLE IF NOT EXISTS inventory_metrics (
    id SERIAL PRIMARY KEY,
    label TEXT NOT NULL UNIQUE,
    value TEXT NOT NULL,
    delta TEXT NULL,
    timeframe TEXT NULL,
    accent BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INTEGER NOT NULL DEFAULT 100
);

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

INSERT INTO inventory_items (item, sku, part_number, finish, location, stock, status, supplier, supplier_contact, reorder_point, lead_time_days) VALUES
    ('Aluminum Stile - 2"', 'AL-ST-02-0R', 'AL-ST-02', '0R', 'Aisle 1 / Bin 4', 86, 'In Stock', 'DoorCraft Metals', 'sales@doorcraftmetals.com', 40, 7),
    ('Tempered Glass Panel 36x84', 'GL-3684-BL', 'GL-3684', 'BL', 'Aisle 3 / Rack 2', 24, 'Reorder', 'ClearView Glass', 'orders@clearviewglass.com', 30, 14),
    ('Hinge Set - Heavy Duty', 'HD-HG-SET-DB', 'HD-HG-SET', 'DB', 'Aisle 2 / Bin 8', 140, 'In Stock', 'Precision Hardware', 'account@precisionhardware.com', 60, 10),
    ('Threshold Extrusion', 'AL-TH-10-C2', 'AL-TH-10', 'C2', 'Aisle 5 / Bin 1', 12, 'Low', 'Alloy Profiles Inc.', 'support@alloyprofiles.com', 20, 12),
    ('Exit Device Kit', 'EX-KT-44-BL', 'EX-KT-44', 'BL', 'Aisle 4 / Shelf 6', 6, 'Critical', 'SecureLatch Systems', 'rep@securelatchsystems.com', 15, 21)
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
    finish = EXCLUDED.finish;

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
