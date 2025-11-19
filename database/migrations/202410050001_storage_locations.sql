BEGIN;

CREATE TABLE IF NOT EXISTS storage_locations (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS storage_locations_name_unique
    ON storage_locations ((lower(name)));

CREATE TABLE IF NOT EXISTS inventory_item_locations (
    id SERIAL PRIMARY KEY,
    inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    storage_location_id INTEGER NOT NULL REFERENCES storage_locations(id) ON DELETE CASCADE,
    quantity INTEGER NOT NULL DEFAULT 0,
    UNIQUE (inventory_item_id, storage_location_id)
);

CREATE INDEX IF NOT EXISTS idx_inventory_item_locations_item
    ON inventory_item_locations (inventory_item_id);

CREATE INDEX IF NOT EXISTS idx_inventory_item_locations_location
    ON inventory_item_locations (storage_location_id);

WITH distinct_locations AS (
    SELECT DISTINCT trim(location) AS name
    FROM inventory_items
    WHERE location IS NOT NULL
      AND trim(location) <> ''
)
INSERT INTO storage_locations (name, sort_order)
SELECT name, COALESCE((SELECT MAX(sort_order) FROM storage_locations), 0) + row_number() OVER (ORDER BY name)
FROM distinct_locations
ON CONFLICT ((lower(name))) DO NOTHING;

WITH seeded AS (
    SELECT i.id AS inventory_item_id,
           COALESCE(sl.id, (
               SELECT id FROM storage_locations
               WHERE lower(name) = lower(i.location)
               LIMIT 1
           )) AS storage_location_id,
           i.stock AS quantity
    FROM inventory_items i
    LEFT JOIN storage_locations sl ON lower(sl.name) = lower(i.location)
    WHERE i.location IS NOT NULL
      AND trim(i.location) <> ''
)
INSERT INTO inventory_item_locations (inventory_item_id, storage_location_id, quantity)
SELECT inventory_item_id, storage_location_id, quantity
FROM seeded
WHERE storage_location_id IS NOT NULL
ON CONFLICT (inventory_item_id, storage_location_id)
    DO UPDATE SET quantity = EXCLUDED.quantity;

COMMIT;
