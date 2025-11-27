CREATE TABLE IF NOT EXISTS maintenance_assets (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT,
    documents JSONB NOT NULL DEFAULT '[]'::jsonb,
    notes TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS maintenance_asset_machines (
    asset_id BIGINT NOT NULL REFERENCES maintenance_assets(id) ON DELETE CASCADE,
    machine_id BIGINT NOT NULL REFERENCES maintenance_machines(id) ON DELETE CASCADE,
    PRIMARY KEY (asset_id, machine_id)
);

ALTER TABLE maintenance_records
    ADD COLUMN IF NOT EXISTS asset_id BIGINT REFERENCES maintenance_assets(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS maintenance_asset_machines_asset_idx ON maintenance_asset_machines(asset_id);
CREATE INDEX IF NOT EXISTS maintenance_asset_machines_machine_idx ON maintenance_asset_machines(machine_id);
