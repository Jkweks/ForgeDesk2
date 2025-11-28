from __future__ import annotations

from django.db import migrations


class Migration(migrations.Migration):
    initial = True

    dependencies = []

    operations = [
        migrations.RunSQL(
            sql="""
            CREATE TABLE IF NOT EXISTS configurator_part_use_options (
                id BIGSERIAL PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                parent_id BIGINT NULL REFERENCES configurator_part_use_options(id) ON DELETE SET NULL
            );
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
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
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            CREATE TABLE IF NOT EXISTS configurator_part_use_links (
                inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
                use_option_id BIGINT NOT NULL REFERENCES configurator_part_use_options(id) ON DELETE CASCADE,
                PRIMARY KEY (inventory_item_id, use_option_id)
            );
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            CREATE TABLE IF NOT EXISTS configurator_part_requirements (
                inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
                required_inventory_item_id BIGINT NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
                quantity INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (inventory_item_id, required_inventory_item_id)
            );
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'configurator_part_use_links' AND column_name = 'id'
                ) THEN
                    ALTER TABLE configurator_part_use_links ADD COLUMN id BIGSERIAL;
                END IF;
            END$$;
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'configurator_part_requirements' AND column_name = 'id'
                ) THEN
                    ALTER TABLE configurator_part_requirements ADD COLUMN id BIGSERIAL;
                END IF;
            END$$;
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS quantity INTEGER NOT NULL DEFAULT 1;
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            ALTER TABLE configurator_part_use_options
                ADD COLUMN IF NOT EXISTS parent_id BIGINT NULL REFERENCES configurator_part_use_options(id) ON DELETE SET NULL;
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            CREATE INDEX IF NOT EXISTS idx_configurator_part_use_options_parent_id
                ON configurator_part_use_options(parent_id);
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            CREATE UNIQUE INDEX IF NOT EXISTS idx_configurator_part_use_links_id
                ON configurator_part_use_links(id);
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            CREATE TABLE IF NOT EXISTS configurator_jobs (
                id BIGSERIAL PRIMARY KEY,
                job_number TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
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
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'configurator_configurations_quantity_check'
                ) THEN
                    ALTER TABLE configurator_configurations
                        ADD CONSTRAINT configurator_configurations_quantity_check CHECK (quantity > 0);
                END IF;
            END$$;
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
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
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            CREATE INDEX IF NOT EXISTS idx_configurator_configurations_job_id
                ON configurator_configurations(job_id);
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            CREATE TABLE IF NOT EXISTS configurator_configuration_doors (
                id BIGSERIAL PRIMARY KEY,
                configuration_id BIGINT NOT NULL REFERENCES configurator_configurations(id) ON DELETE CASCADE,
                door_tag TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (configuration_id, door_tag)
            );
            """,
            reverse_sql="SELECT 1;",
        ),
    ]
