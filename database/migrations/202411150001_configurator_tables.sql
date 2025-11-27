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
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT configurator_part_profiles_part_type_check
        CHECK (part_type IS NULL OR part_type IN ('door', 'frame', 'hardware', 'accessory'))
);

CREATE TABLE IF NOT EXISTS configurator_part_use_links (
    inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    use_option_id BIGINT NOT NULL REFERENCES configurator_part_use_options(id) ON DELETE CASCADE,
    PRIMARY KEY (inventory_item_id, use_option_id)
);

CREATE TABLE IF NOT EXISTS configurator_part_requirements (
    inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    required_inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
    quantity INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (inventory_item_id, required_inventory_item_id)
);

ALTER TABLE configurator_part_requirements
    ADD COLUMN IF NOT EXISTS quantity INTEGER NOT NULL DEFAULT 1;

ALTER TABLE configurator_part_use_options
    ADD COLUMN IF NOT EXISTS parent_id BIGINT NULL REFERENCES configurator_part_use_options(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_configurator_part_use_options_parent_id
    ON configurator_part_use_options(parent_id);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'configurator_part_requirements_quantity_check'
    ) THEN
        ALTER TABLE configurator_part_requirements
            ADD CONSTRAINT configurator_part_requirements_quantity_check CHECK (quantity > 0);
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
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE configurator_configurations
    ADD COLUMN IF NOT EXISTS job_scope TEXT NOT NULL DEFAULT 'door_and_frame';

ALTER TABLE configurator_configurations
    ADD COLUMN IF NOT EXISTS quantity INTEGER NOT NULL DEFAULT 1;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'configurator_configurations_quantity_check'
    ) THEN
        ALTER TABLE configurator_configurations
            ADD CONSTRAINT configurator_configurations_quantity_check CHECK (quantity > 0);
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'configurator_configurations_job_scope_check'
    ) THEN
        ALTER TABLE configurator_configurations
            ADD CONSTRAINT configurator_configurations_job_scope_check
            CHECK (job_scope IN ('door_and_frame', 'frame_only', 'door_only'));
    END IF;
END$$;

CREATE INDEX IF NOT EXISTS idx_configurator_configurations_job_id
    ON configurator_configurations(job_id);

CREATE TABLE IF NOT EXISTS configurator_configuration_doors (
    id BIGSERIAL PRIMARY KEY,
    configuration_id BIGINT NOT NULL REFERENCES configurator_configurations(id) ON DELETE CASCADE,
    door_tag TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (configuration_id, door_tag)
);

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
