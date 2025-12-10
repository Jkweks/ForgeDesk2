from __future__ import annotations

from django.db import migrations


class Migration(migrations.Migration):
    dependencies = [
        ("inventory", "0002_inventory_system_types"),
    ]

    operations = [
        migrations.RunSQL(
            sql="""
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'configurator_part_use_options_name_key'
                        AND conrelid = 'configurator_part_use_options'::regclass
                ) THEN
                    ALTER TABLE configurator_part_use_options
                        DROP CONSTRAINT configurator_part_use_options_name_key;
                END IF;
            END$$;
            """,
            reverse_sql="""
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'configurator_part_use_options_name_key'
                        AND conrelid = 'configurator_part_use_options'::regclass
                ) THEN
                    ALTER TABLE configurator_part_use_options
                        ADD CONSTRAINT configurator_part_use_options_name_key UNIQUE (name);
                END IF;
            END$$;
            """,
        ),
    ]
