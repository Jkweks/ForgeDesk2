from __future__ import annotations

from django.db import migrations


class Migration(migrations.Migration):
    dependencies = [
        ("inventory", "0005_merge_0002_configurator_requirement_finish_policy_0004_job_reservation_release_number"),
    ]

    operations = [
        migrations.RunSQL(
            """
            ALTER TABLE inventory_item_systems ADD COLUMN IF NOT EXISTS id BIGSERIAL;
            CREATE UNIQUE INDEX IF NOT EXISTS idx_inventory_item_systems_id ON inventory_item_systems(id);

            ALTER TABLE inventory_daily_usage ADD COLUMN IF NOT EXISTS id BIGSERIAL;
            CREATE UNIQUE INDEX IF NOT EXISTS idx_inventory_daily_usage_id ON inventory_daily_usage(id);

            ALTER TABLE maintenance_asset_machines ADD COLUMN IF NOT EXISTS id BIGSERIAL;
            CREATE UNIQUE INDEX IF NOT EXISTS idx_maintenance_asset_machines_id ON maintenance_asset_machines(id);
            """,
            reverse_sql="""
            DROP INDEX IF EXISTS idx_inventory_item_systems_id;
            DROP INDEX IF EXISTS idx_inventory_daily_usage_id;
            DROP INDEX IF EXISTS idx_maintenance_asset_machines_id;

            ALTER TABLE inventory_item_systems DROP COLUMN IF EXISTS id;
            ALTER TABLE inventory_daily_usage DROP COLUMN IF EXISTS id;
            ALTER TABLE maintenance_asset_machines DROP COLUMN IF EXISTS id;
            """,
        ),
    ]
