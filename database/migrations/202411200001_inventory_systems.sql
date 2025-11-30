-- Inventory system reference data
CREATE TABLE IF NOT EXISTS inventory_systems (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    manufacturer TEXT NOT NULL DEFAULT '',
    system TEXT NOT NULL DEFAULT '',
    default_glazing NUMERIC(10,4) NULL,
    default_frame_parts JSONB NOT NULL DEFAULT '[]'::jsonb,
    default_door_parts JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS inventory_item_systems (
    inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    system_id BIGINT NOT NULL REFERENCES inventory_systems(id) ON DELETE CASCADE,
    PRIMARY KEY (inventory_item_id, system_id)
);

CREATE INDEX IF NOT EXISTS idx_inventory_item_systems_system_id
    ON inventory_item_systems(system_id);

INSERT INTO inventory_systems (
    name,
    manufacturer,
    system,
    default_glazing,
    default_frame_parts,
    default_door_parts
)
VALUES
    (
        'Tubelite E4500',
        'Tubelite',
        'E4500',
        0.25,
        '["hinge jamb","lock jamb","door head","threshold","transom head","horizontal transom stop - fixed","horizontal transom stop - active","vertical transom stop - fixed","vertical transom stop - active","head transom stop - fixed","head transom stop - active","head door stop","lock door stop","hinge door stop"]',
        '["hinge rail","lock rail","top rail","bottom rail","interior glass stop","exterior glass stop"]'
    ),
    (
        'Tubelite E14000',
        'Tubelite',
        'E14000',
        0.25,
        '["hinge jamb","lock jamb","door head","threshold","transom head","horizontal transom stop - fixed","horizontal transom stop - active","vertical transom stop - fixed","vertical transom stop - active","head transom stop - fixed","head transom stop - active","head door stop","lock door stop","hinge door stop"]',
        '["hinge rail","lock rail","top rail","bottom rail","interior glass stop","exterior glass stop"]'
    ),
    (
        'Tubelite E14000 I/O',
        'Tubelite',
        'E14000 I/O',
        0.25,
        '["hinge jamb","lock jamb","door head","threshold","transom head","horizontal transom stop - fixed","horizontal transom stop - active","vertical transom stop - fixed","vertical transom stop - active","head transom stop - fixed","head transom stop - active","head door stop","lock door stop","hinge door stop"]',
        '["hinge rail","lock rail","top rail","bottom rail","interior glass stop","exterior glass stop"]'
    ),
    (
        'Tubelite E24650',
        'Tubelite',
        'E24650',
        0.25,
        '["hinge jamb","lock jamb","door head","threshold","transom head","horizontal transom stop - fixed","horizontal transom stop - active","vertical transom stop - fixed","vertical transom stop - active","head transom stop - fixed","head transom stop - active","head door stop","lock door stop","hinge door stop"]',
        '["hinge rail","lock rail","top rail","bottom rail","interior glass stop","exterior glass stop"]'
    )
ON CONFLICT (name) DO UPDATE SET
    manufacturer = EXCLUDED.manufacturer,
    system = EXCLUDED.system,
    default_glazing = EXCLUDED.default_glazing,
    default_frame_parts = EXCLUDED.default_frame_parts,
    default_door_parts = EXCLUDED.default_door_parts;
