-- Classify inventory systems by use
ALTER TABLE inventory_systems
    ADD COLUMN IF NOT EXISTS system_type TEXT NOT NULL DEFAULT 'framing';

-- Backfill existing rows
UPDATE inventory_systems
SET system_type = COALESCE(NULLIF(system_type, ''), 'framing');

-- Seed door-specific placeholders for configurator use
INSERT INTO inventory_systems (
    name,
    manufacturer,
    system,
    default_glazing,
    default_frame_parts,
    default_door_parts,
    system_type
) VALUES
    (
        'Tubelite WS Continuous',
        'Tubelite',
        'WS Continuous',
        0.25,
        '[]',
        '["hinge rail a","lock rail","top rail","bottom rail"]',
        'door'
    ),
    (
        'Tubelite WS Butt',
        'Tubelite',
        'WS Butt',
        0.25,
        '[]',
        '["hinge rail b","lock rail","top rail","bottom rail"]',
        'door'
    )
ON CONFLICT (name) DO UPDATE
SET manufacturer = EXCLUDED.manufacturer,
    system = EXCLUDED.system,
    default_glazing = EXCLUDED.default_glazing,
    default_frame_parts = EXCLUDED.default_frame_parts,
    default_door_parts = EXCLUDED.default_door_parts,
    system_type = EXCLUDED.system_type;
