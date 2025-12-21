from __future__ import annotations

from django.db import migrations


class Migration(migrations.Migration):
    dependencies = [
        ("inventory", "0001_configurator_tables"),
    ]

    operations = [
        migrations.RunSQL(
            sql="""
            ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS finish_policy TEXT NOT NULL DEFAULT 'fixed';
            """,
            reverse_sql="SELECT 1;",
        ),
        migrations.RunSQL(
            sql="""
            ALTER TABLE configurator_part_requirements
                ADD COLUMN IF NOT EXISTS fixed_finish TEXT NULL;
            """,
            reverse_sql="SELECT 1;",
        ),
    ]
