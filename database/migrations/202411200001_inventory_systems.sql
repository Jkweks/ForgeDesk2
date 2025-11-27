-- Inventory system reference data
CREATE TABLE IF NOT EXISTS inventory_systems (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS inventory_item_systems (
    inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    system_id BIGINT NOT NULL REFERENCES inventory_systems(id) ON DELETE CASCADE,
    PRIMARY KEY (inventory_item_id, system_id)
);

CREATE INDEX IF NOT EXISTS idx_inventory_item_systems_system_id
    ON inventory_item_systems(system_id);

INSERT INTO inventory_systems (name)
VALUES
    ('Tubelite E4500'),
    ('Tubelite E14000'),
    ('Tubelite E14000 I/O'),
    ('Tubelite E24650')
ON CONFLICT (name) DO NOTHING;
