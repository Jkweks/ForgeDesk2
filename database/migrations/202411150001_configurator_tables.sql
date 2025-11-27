-- Configurator tables for door and frame CPQ module
CREATE TABLE IF NOT EXISTS configurator_part_use_options (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
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
    PRIMARY KEY (inventory_item_id, required_inventory_item_id)
);

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
    status TEXT NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_configurator_configurations_job_id
    ON configurator_configurations(job_id);

INSERT INTO configurator_part_use_options (name)
VALUES
    ('Interior Opening'),
    ('Exterior Opening'),
    ('Fire Rated'),
    ('Pair Door'),
    ('Single Door'),
    ('Hardware Set')
ON CONFLICT (name) DO NOTHING;
