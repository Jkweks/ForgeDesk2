-- init.sql - canonical ForgeDesk inventory + reservations schema

-- Supplier master data
CREATE TABLE IF NOT EXISTS suppliers (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    contact_name TEXT,
    contact_email TEXT,
    contact_phone TEXT,
    default_lead_time_days INTEGER DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

-- Base inventory items table
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
    average_daily_use NUMERIC(12,4) NULL,
    on_order_qty NUMERIC(18, 6) DEFAULT 0,
    safety_stock NUMERIC(18, 6) DEFAULT 0,
    min_order_qty NUMERIC(18, 6) DEFAULT 0,
    order_multiple NUMERIC(18, 6) DEFAULT 0,
    pack_size NUMERIC(18, 6) DEFAULT 0,
    purchase_uom TEXT,
    stock_uom TEXT,
    supplier_id BIGINT REFERENCES suppliers(id) ON UPDATE CASCADE ON DELETE SET NULL,
    supplier_sku TEXT
);

-- Backfill / keep idempotent across schema tweaks
ALTER TABLE inventory_items
    ADD COLUMN IF NOT EXISTS supplier TEXT NOT NULL DEFAULT 'Unknown Supplier',
    ADD COLUMN IF NOT EXISTS supplier_contact TEXT NULL,
    ADD COLUMN IF NOT EXISTS reorder_point INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS lead_time_days INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS part_number TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS finish TEXT NULL,
    ADD COLUMN IF NOT EXISTS average_daily_use NUMERIC(12,4) NULL,
    ADD COLUMN IF NOT EXISTS committed_qty INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS on_order_qty NUMERIC(18, 6) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS safety_stock NUMERIC(18, 6) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS min_order_qty NUMERIC(18, 6) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS order_multiple NUMERIC(18, 6) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS pack_size NUMERIC(18, 6) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS purchase_uom TEXT,
    ADD COLUMN IF NOT EXISTS stock_uom TEXT,
    ADD COLUMN IF NOT EXISTS supplier_id BIGINT,
    ADD COLUMN IF NOT EXISTS supplier_sku TEXT;

-- Ensure inventory_items.supplier_id references suppliers
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints tc
        WHERE tc.constraint_type = 'FOREIGN KEY'
          AND tc.table_schema = 'public'
          AND tc.table_name = 'inventory_items'
          AND tc.constraint_name = 'inventory_items_supplier_id_fkey'
    ) THEN
        ALTER TABLE inventory_items
            ADD CONSTRAINT inventory_items_supplier_id_fkey
            FOREIGN KEY (supplier_id)
            REFERENCES suppliers(id)
            ON UPDATE CASCADE
            ON DELETE SET NULL;
    END IF;
END;
$$;

-- Metrics table for dashboard cards
CREATE TABLE IF NOT EXISTS inventory_metrics (
    id SERIAL PRIMARY KEY,
    label TEXT NOT NULL UNIQUE,
    value TEXT NOT NULL,
    delta TEXT NULL,
    timeframe TEXT NULL,
    accent BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INTEGER NOT NULL DEFAULT 100
);

-- Job reservation status enum (full / final set)
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

-- Generic "updated_at" trigger function
CREATE OR REPLACE FUNCTION set_updated_at_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Job reservations header
CREATE TABLE IF NOT EXISTS job_reservations (
    id SERIAL PRIMARY KEY,
    job_number TEXT NOT NULL,
    release_number INTEGER NOT NULL DEFAULT 1,
    job_name TEXT NOT NULL,
    requested_by TEXT NOT NULL,
    needed_by DATE NULL,
    status job_reservation_status NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (job_number, release_number)
);

-- Updated-at trigger on job_reservations
DROP TRIGGER IF EXISTS trg_job_reservations_updated_at ON job_reservations;
CREATE TRIGGER trg_job_reservations_updated_at
    BEFORE UPDATE ON job_reservations
    FOR EACH ROW
    EXECUTE FUNCTION set_updated_at_timestamp();

-- Job reservation lines
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

-- View of committed qty per inventory item, based on active-ish reservations
DROP VIEW IF EXISTS inventory_item_commitments;
CREATE OR REPLACE VIEW inventory_item_commitments AS
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

-- Cycle count sessions
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

-- Cycle count lines
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
    is_skipped BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE(session_id, sequence)
);

CREATE INDEX IF NOT EXISTS idx_cycle_count_lines_session_sequence
    ON cycle_count_lines (session_id, sequence);

CREATE INDEX IF NOT EXISTS idx_cycle_count_lines_inventory
    ON cycle_count_lines (inventory_item_id);

-- Inventory transactions header
CREATE TABLE IF NOT EXISTS inventory_transactions (
    id SERIAL PRIMARY KEY,
    reference TEXT NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Inventory transaction lines
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

-- Daily usage tracking
CREATE TABLE IF NOT EXISTS inventory_daily_usage (
    inventory_item_id INTEGER NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    usage_date DATE NOT NULL,
    quantity_used INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (inventory_item_id, usage_date)
);

CREATE INDEX IF NOT EXISTS idx_inventory_daily_usage_date
    ON inventory_daily_usage (usage_date);

-- Storage locations and per-location quantities
CREATE TABLE IF NOT EXISTS storage_locations (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    aisle TEXT NULL,
    rack TEXT NULL,
    shelf TEXT NULL,
    bin TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS storage_locations_name_unique
    ON storage_locations ((lower(name)));

CREATE INDEX IF NOT EXISTS idx_storage_locations_aisle ON storage_locations ((lower(aisle)));
CREATE INDEX IF NOT EXISTS idx_storage_locations_rack ON storage_locations ((lower(rack)));
CREATE INDEX IF NOT EXISTS idx_storage_locations_shelf ON storage_locations ((lower(shelf)));
CREATE INDEX IF NOT EXISTS idx_storage_locations_bin ON storage_locations ((lower(bin)));

-- Backfill components from dot-delimited names when unset
UPDATE storage_locations
SET aisle = NULLIF(split_part(name, '.', 1), ''),
    rack = NULLIF(split_part(name, '.', 2), ''),
    shelf = NULLIF(split_part(name, '.', 3), ''),
    bin = NULLIF(split_part(name, '.', 4), '')
WHERE aisle IS NULL AND rack IS NULL AND shelf IS NULL AND bin IS NULL;

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

-- Backfill storage locations from inventory_items.location labels
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

-- Populate inventory_item_locations with existing stock levels
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

-- Purchase order structures
CREATE TABLE IF NOT EXISTS purchase_orders (
    id BIGSERIAL PRIMARY KEY,
    order_number TEXT UNIQUE,
    supplier_id BIGINT REFERENCES suppliers(id) ON UPDATE CASCADE ON DELETE SET NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    order_date DATE DEFAULT CURRENT_DATE,
    expected_date DATE,
    total_cost NUMERIC(18, 6) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft', 'sent', 'partially_received', 'closed', 'cancelled'))
);

CREATE TABLE IF NOT EXISTS purchase_order_lines (
    id BIGSERIAL PRIMARY KEY,
    purchase_order_id BIGINT REFERENCES purchase_orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
    inventory_item_id BIGINT REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE SET NULL,
    supplier_sku TEXT,
    description TEXT,
    quantity_ordered NUMERIC(18, 6) NOT NULL DEFAULT 0,
    quantity_received NUMERIC(18, 6) NOT NULL DEFAULT 0,
    unit_cost NUMERIC(18, 6) NOT NULL DEFAULT 0,
    expected_date DATE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    quantity_cancelled NUMERIC(18, 6) NOT NULL DEFAULT 0,
    packs_ordered NUMERIC(18, 6) DEFAULT 0,
    pack_size NUMERIC(18, 6) DEFAULT 0,
    purchase_uom TEXT,
    stock_uom TEXT
);

CREATE INDEX IF NOT EXISTS idx_purchase_orders_supplier_id ON purchase_orders(supplier_id);
CREATE INDEX IF NOT EXISTS idx_purchase_orders_status ON purchase_orders(status);
CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_po_id ON purchase_order_lines(purchase_order_id);
CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_inventory_item_id ON purchase_order_lines(inventory_item_id);

CREATE TABLE IF NOT EXISTS purchase_order_receipts (
    id BIGSERIAL PRIMARY KEY,
    purchase_order_id BIGINT NOT NULL REFERENCES purchase_orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
    inventory_transaction_id INTEGER REFERENCES inventory_transactions(id) ON UPDATE CASCADE ON DELETE SET NULL,
    reference TEXT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_purchase_order_receipts_po_id
    ON purchase_order_receipts (purchase_order_id);
CREATE INDEX IF NOT EXISTS idx_purchase_order_receipts_created_at
    ON purchase_order_receipts (created_at DESC);

CREATE TABLE IF NOT EXISTS purchase_order_receipt_lines (
    id BIGSERIAL PRIMARY KEY,
    receipt_id BIGINT NOT NULL REFERENCES purchase_order_receipts(id) ON UPDATE CASCADE ON DELETE CASCADE,
    purchase_order_line_id BIGINT NOT NULL REFERENCES purchase_order_lines(id) ON UPDATE CASCADE ON DELETE CASCADE,
    quantity_received NUMERIC(18, 6) NOT NULL DEFAULT 0,
    quantity_cancelled NUMERIC(18, 6) NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_purchase_order_receipt_lines_receipt
    ON purchase_order_receipt_lines (receipt_id);
CREATE INDEX IF NOT EXISTS idx_purchase_order_receipt_lines_line
    ON purchase_order_receipt_lines (purchase_order_line_id);

-- Maintenance reference and activity tables
CREATE TABLE IF NOT EXISTS maintenance_machine_types (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO maintenance_machine_types (name)
VALUES
    ('CNC Machining Center'),
    ('Chop saw'),
    ('Upcut Saw'),
    ('Drill Press'),
    ('Punch Press')
ON CONFLICT (name) DO NOTHING;

CREATE TABLE IF NOT EXISTS maintenance_machines (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    equipment_type TEXT NOT NULL,
    manufacturer TEXT,
    model TEXT,
    serial_number TEXT,
    location TEXT,
    documents JSONB NOT NULL DEFAULT '[]'::jsonb,
    notes TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    machine_type_id BIGINT REFERENCES maintenance_machine_types(id)
);

CREATE TABLE IF NOT EXISTS maintenance_tasks (
    id BIGSERIAL PRIMARY KEY,
    machine_id BIGINT NOT NULL REFERENCES maintenance_machines(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    description TEXT,
    frequency TEXT,
    assigned_to TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    interval_count INTEGER CHECK (interval_count > 0),
    interval_unit TEXT CHECK (interval_unit IN ('day', 'week', 'month', 'year')),
    start_date DATE,
    last_completed_at DATE,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused', 'retired')),
    priority TEXT NOT NULL DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high', 'critical'))
);

CREATE TABLE IF NOT EXISTS maintenance_assets (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT,
    documents JSONB NOT NULL DEFAULT '[]'::jsonb,
    notes TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS maintenance_records (
    id BIGSERIAL PRIMARY KEY,
    machine_id BIGINT NOT NULL REFERENCES maintenance_machines(id) ON DELETE CASCADE,
    task_id BIGINT REFERENCES maintenance_tasks(id) ON DELETE SET NULL,
    performed_by TEXT,
    performed_at DATE,
    notes TEXT,
    attachments JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    downtime_minutes INTEGER,
    labor_hours NUMERIC(10,2),
    parts_used JSONB NOT NULL DEFAULT '[]'::jsonb,
    asset_id BIGINT REFERENCES maintenance_assets(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS maintenance_tasks_machine_id_idx ON maintenance_tasks(machine_id);
CREATE INDEX IF NOT EXISTS maintenance_records_machine_id_idx ON maintenance_records(machine_id);
CREATE INDEX IF NOT EXISTS maintenance_records_task_id_idx ON maintenance_records(task_id);
CREATE INDEX IF NOT EXISTS idx_maintenance_tasks_status ON maintenance_tasks (status);

CREATE TABLE IF NOT EXISTS maintenance_asset_machines (
    asset_id BIGINT NOT NULL REFERENCES maintenance_assets(id) ON DELETE CASCADE,
    machine_id BIGINT NOT NULL REFERENCES maintenance_machines(id) ON DELETE CASCADE,
    PRIMARY KEY (asset_id, machine_id)
);

CREATE INDEX IF NOT EXISTS maintenance_asset_machines_asset_idx ON maintenance_asset_machines(asset_id);
CREATE INDEX IF NOT EXISTS maintenance_asset_machines_machine_idx ON maintenance_asset_machines(machine_id);

-- Configurator tables for door and frame CPQ module
CREATE TABLE IF NOT EXISTS configurator_part_use_options (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    parent_id BIGINT NULL REFERENCES configurator_part_use_options(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS configurator_part_profiles (
    inventory_item_id BIGINT PRIMARY KEY REFERENCES inventory_items(id) ON DELETE CASCADE,
    is_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    part_type TEXT NULL,
    height_lz NUMERIC(12,4) NULL,
    depth_ly NUMERIC(12,4) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT configurator_part_profiles_part_type_check
        CHECK (part_type IS NULL OR part_type IN ('door', 'frame', 'hardware', 'accessory')),
    CONSTRAINT configurator_part_profiles_height_lz_check
        CHECK (height_lz IS NULL OR height_lz > 0),
    CONSTRAINT configurator_part_profiles_depth_ly_check
        CHECK (depth_ly IS NULL OR depth_ly > 0)
);

CREATE TABLE IF NOT EXISTS configurator_part_use_links (
    inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    use_option_id BIGINT NOT NULL REFERENCES configurator_part_use_options(id) ON DELETE CASCADE,
    id BIGSERIAL,
    PRIMARY KEY (inventory_item_id, use_option_id)
);

CREATE TABLE IF NOT EXISTS configurator_part_requirements (
    inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    required_inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    quantity INTEGER NOT NULL DEFAULT 1,
    id BIGSERIAL,
    PRIMARY KEY (inventory_item_id, required_inventory_item_id)
);

CREATE INDEX IF NOT EXISTS idx_configurator_part_use_options_parent_id
    ON configurator_part_use_options(parent_id);

CREATE UNIQUE INDEX IF NOT EXISTS idx_configurator_part_use_links_id
    ON configurator_part_use_links(id);

CREATE UNIQUE INDEX IF NOT EXISTS idx_configurator_part_requirements_id
    ON configurator_part_requirements(id);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'configurator_part_requirements_quantity_check'
    ) THEN
        ALTER TABLE configurator_part_requirements
            ADD CONSTRAINT configurator_part_requirements_quantity_check CHECK (quantity > 0);
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'configurator_part_profiles_height_lz_check'
    ) THEN
        ALTER TABLE configurator_part_profiles
            ADD CONSTRAINT configurator_part_profiles_height_lz_check CHECK (height_lz IS NULL OR height_lz > 0);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'configurator_part_profiles_depth_ly_check'
    ) THEN
        ALTER TABLE configurator_part_profiles
            ADD CONSTRAINT configurator_part_profiles_depth_ly_check CHECK (depth_ly IS NULL OR depth_ly > 0);
    END IF;
END$$;

CREATE INDEX IF NOT EXISTS idx_configurator_part_requirements_required
    ON configurator_part_requirements(required_inventory_item_id);

CREATE TABLE IF NOT EXISTS configurator_jobs (
    id BIGSERIAL PRIMARY KEY,
    job_number TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS configurator_configurations (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    job_id BIGINT NULL REFERENCES configurator_jobs(id) ON DELETE SET NULL,
    job_scope TEXT NOT NULL DEFAULT 'door_and_frame',
    quantity INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT configurator_configurations_quantity_check CHECK (quantity > 0),
    CONSTRAINT configurator_configurations_job_scope_check
        CHECK (job_scope IN ('door_and_frame', 'frame_only', 'door_only'))
);

CREATE INDEX IF NOT EXISTS idx_configurator_configurations_job_id
    ON configurator_configurations(job_id);

CREATE TABLE IF NOT EXISTS configurator_configuration_doors (
    id BIGSERIAL PRIMARY KEY,
    configuration_id BIGINT NOT NULL REFERENCES configurator_configurations(id) ON DELETE CASCADE,
    door_tag TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (configuration_id, door_tag)
);

-- Seed configurator hierarchy
DO $$
DECLARE
    door_id BIGINT;
    frame_id BIGINT;
    hardware_id BIGINT;
    accessory_id BIGINT;
    door_hardware_id BIGINT;
    hinge_id BIGINT;
    butt_hinge_id BIGINT;
BEGIN
    INSERT INTO configurator_part_use_options (name, parent_id)
    VALUES
        ('Door', NULL),
        ('Frame', NULL),
        ('Hardware', NULL),
        ('Accessory', NULL)
    ON CONFLICT (name) DO NOTHING;

    SELECT id INTO door_id FROM configurator_part_use_options WHERE name = 'Door';
    SELECT id INTO frame_id FROM configurator_part_use_options WHERE name = 'Frame';
    SELECT id INTO hardware_id FROM configurator_part_use_options WHERE name = 'Hardware';
    SELECT id INTO accessory_id FROM configurator_part_use_options WHERE name = 'Accessory';

    INSERT INTO configurator_part_use_options (name, parent_id)
    VALUES
        ('Interior Opening', door_id),
        ('Exterior Opening', door_id),
        ('Fire Rated', door_id),
        ('Pair Door', door_id),
        ('Single Door', door_id),
        ('Hardware Set', hardware_id),
        ('Door Hardware', hardware_id)
    ON CONFLICT (name) DO NOTHING;

    SELECT id INTO door_hardware_id FROM configurator_part_use_options WHERE name = 'Door Hardware';

    INSERT INTO configurator_part_use_options (name, parent_id)
    VALUES ('Hinge', door_hardware_id)
    ON CONFLICT (name) DO NOTHING;

    SELECT id INTO hinge_id FROM configurator_part_use_options WHERE name = 'Hinge';
    UPDATE configurator_part_use_options SET parent_id = door_hardware_id WHERE name = 'Hinge';

    INSERT INTO configurator_part_use_options (name, parent_id)
    VALUES ('Butt Hinge', hinge_id)
    ON CONFLICT (name) DO NOTHING;

    SELECT id INTO butt_hinge_id FROM configurator_part_use_options WHERE name = 'Butt Hinge';
    UPDATE configurator_part_use_options SET parent_id = hinge_id WHERE name = 'Butt Hinge';

    INSERT INTO configurator_part_use_options (name, parent_id)
    VALUES ('Heavy Duty', butt_hinge_id)
    ON CONFLICT (name) DO NOTHING;

    UPDATE configurator_part_use_options SET parent_id = door_id WHERE name IN ('Interior Opening', 'Exterior Opening', 'Fire Rated', 'Pair Door', 'Single Door');
    UPDATE configurator_part_use_options SET parent_id = hardware_id WHERE name IN ('Hardware Set', 'Door Hardware');
    UPDATE configurator_part_use_options SET parent_id = butt_hinge_id WHERE name = 'Heavy Duty';
END$$;

-- Inventory systems reference data
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

-- Seed inventory items
INSERT INTO inventory_items (
    item,
    sku,
    part_number,
    finish,
    location,
    stock,
    committed_qty,
    status,
    supplier,
    supplier_contact,
    reorder_point,
    lead_time_days,
    average_daily_use
) VALUES
    ('Aluminum Stile - 2"', 'AL-ST-02-0R', 'AL-ST-02', '0R', 'Aisle 1 / Bin 4', 86, 0, 'In Stock', 'DoorCraft Metals', 'sales@doorcraftmetals.com', 40, 7, 3.5000),
    ('Tempered Glass Panel 36x84', 'GL-3684-BL', 'GL-3684', 'BL', 'Aisle 3 / Rack 2', 24, 0, 'Reorder', 'ClearView Glass', 'orders@clearviewglass.com', 30, 14, 1.2500),
    ('Hinge Set - Heavy Duty', 'HD-HG-SET-DB', 'HD-HG-SET', 'DB', 'Aisle 2 / Bin 8', 140, 0, 'In Stock', 'Precision Hardware', 'account@precisionhardware.com', 60, 10, 4.7500),
    ('Threshold Extrusion', 'AL-TH-10-C2', 'AL-TH-10', 'C2', 'Aisle 5 / Bin 1', 12, 0, 'Low', 'Alloy Profiles Inc.', 'support@alloyprofiles.com', 20, 12, 0.9000),
    ('Exit Device Kit', 'EX-KT-44-BL', 'EX-KT-44', 'BL', 'Aisle 4 / Shelf 6', 6, 0, 'Critical', 'SecureLatch Systems', 'rep@securelatchsystems.com', 15, 21, 0.6500)
ON CONFLICT (sku) DO UPDATE SET
    item = EXCLUDED.item,
    location = EXCLUDED.location,
    stock = EXCLUDED.stock,
    committed_qty = EXCLUDED.committed_qty,
    status = EXCLUDED.status,
    supplier = EXCLUDED.supplier,
    supplier_contact = EXCLUDED.supplier_contact,
    reorder_point = EXCLUDED.reorder_point,
    lead_time_days = EXCLUDED.lead_time_days,
    part_number = EXCLUDED.part_number,
    finish = EXCLUDED.finish,
    average_daily_use = EXCLUDED.average_daily_use;

-- Seed metrics
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

-- Seed example job reservation (uses 'active' status)
INSERT INTO job_reservations (job_number, release_number, job_name, requested_by, needed_by, status, notes)
VALUES
    ('JOB-2024-001', 1, 'Downtown Lobby Retrofit', 'Morgan Smith', CURRENT_DATE + INTERVAL '14 days', 'active', 'Initial staging reservation for retrofit work')
ON CONFLICT (job_number, release_number) DO UPDATE SET
    job_name = EXCLUDED.job_name,
    requested_by = EXCLUDED.requested_by,
    needed_by = EXCLUDED.needed_by,
    status = EXCLUDED.status,
    notes = EXCLUDED.notes;

-- Link some items to that reservation
INSERT INTO job_reservation_items (reservation_id, inventory_item_id, requested_qty, committed_qty, consumed_qty)
SELECT r.id, i.id, 20, 16, 0
FROM job_reservations r
JOIN inventory_items i ON i.sku = 'AL-ST-02-0R'
WHERE r.job_number = 'JOB-2024-001' AND r.release_number = 1
ON CONFLICT (reservation_id, inventory_item_id) DO UPDATE SET
    requested_qty = EXCLUDED.requested_qty,
    committed_qty = EXCLUDED.committed_qty,
    consumed_qty = EXCLUDED.consumed_qty;

INSERT INTO job_reservation_items (reservation_id, inventory_item_id, requested_qty, committed_qty, consumed_qty)
SELECT r.id, i.id, 8, 6, 0
FROM job_reservations r
JOIN inventory_items i ON i.sku = 'HD-HG-SET-DB'
WHERE r.job_number = 'JOB-2024-001' AND r.release_number = 1
ON CONFLICT (reservation_id, inventory_item_id) DO UPDATE SET
    requested_qty = EXCLUDED.requested_qty,
    committed_qty = EXCLUDED.committed_qty,
    consumed_qty = EXCLUDED.consumed_qty;
