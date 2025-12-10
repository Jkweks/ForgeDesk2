from __future__ import annotations

from django.db import migrations


class Migration(migrations.Migration):
    dependencies = [
        ("inventory", "0001_configurator_tables"),
    ]

    operations = [
        migrations.RunSQL(
            sql="""
            ALTER TABLE inventory_systems
                ADD COLUMN IF NOT EXISTS system_type TEXT NOT NULL DEFAULT 'framing';
            """,
            reverse_sql="""
            ALTER TABLE inventory_systems
                DROP COLUMN IF EXISTS system_type;
            """,
        ),
        migrations.RunSQL(
            sql="""
            UPDATE inventory_systems
            SET system_type = COALESCE(NULLIF(system_type, ''), 'framing');
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
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
                    '[]'::jsonb,
                    '["hinge rail a","lock rail","top rail","bottom rail"]'::jsonb,
                    'door'
                ),
                (
                    'Tubelite WS Butt',
                    'Tubelite',
                    'WS Butt',
                    0.25,
                    '[]'::jsonb,
                    '["hinge rail b","lock rail","top rail","bottom rail"]'::jsonb,
                    'door'
                )
            ON CONFLICT (name) DO UPDATE
            SET manufacturer = EXCLUDED.manufacturer,
                system = EXCLUDED.system,
                default_glazing = EXCLUDED.default_glazing,
                default_frame_parts = EXCLUDED.default_frame_parts,
                default_door_parts = EXCLUDED.default_door_parts,
                system_type = EXCLUDED.system_type;
            """,
            reverse_sql="""
            DELETE FROM inventory_systems
            WHERE name IN ('Tubelite WS Continuous', 'Tubelite WS Butt');
            """,
        ),
    ]
