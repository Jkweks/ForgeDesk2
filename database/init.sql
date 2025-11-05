CREATE TABLE IF NOT EXISTS inventory_items (
    id SERIAL PRIMARY KEY,
    item TEXT NOT NULL,
    sku TEXT NOT NULL UNIQUE,
    location TEXT NOT NULL,
    stock INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'In Stock'
);

CREATE TABLE IF NOT EXISTS inventory_metrics (
    id SERIAL PRIMARY KEY,
    label TEXT NOT NULL UNIQUE,
    value TEXT NOT NULL,
    delta TEXT NULL,
    timeframe TEXT NULL,
    accent BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INTEGER NOT NULL DEFAULT 100
);

INSERT INTO inventory_items (item, sku, location, stock, status) VALUES
    ('Aluminum Stile - 2"', 'AL-ST-02', 'Aisle 1 / Bin 4', 86, 'In Stock'),
    ('Tempered Glass Panel 36x84', 'GL-3684-T', 'Aisle 3 / Rack 2', 24, 'Reorder'),
    ('Hinge Set - Heavy Duty', 'HD-HG-SET', 'Aisle 2 / Bin 8', 140, 'In Stock'),
    ('Threshold Extrusion', 'AL-TH-10', 'Aisle 5 / Bin 1', 12, 'Low'),
    ('Exit Device Kit', 'EX-KT-44', 'Aisle 4 / Shelf 6', 6, 'Critical')
ON CONFLICT (sku) DO UPDATE SET
    item = EXCLUDED.item,
    location = EXCLUDED.location,
    stock = EXCLUDED.stock,
    status = EXCLUDED.status;

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
