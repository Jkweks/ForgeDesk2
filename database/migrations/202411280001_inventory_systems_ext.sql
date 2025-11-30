-- Add manufacturer/system detail and default component templates to inventory systems
ALTER TABLE inventory_systems
    ADD COLUMN IF NOT EXISTS manufacturer TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS system TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS default_glazing NUMERIC(10,4) NULL,
    ADD COLUMN IF NOT EXISTS default_frame_parts JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD COLUMN IF NOT EXISTS default_door_parts JSONB NOT NULL DEFAULT '[]'::jsonb;

-- Seed or update Tubelite defaults
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
