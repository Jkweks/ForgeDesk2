from __future__ import annotations

from django.db import migrations


class Migration(migrations.Migration):
    dependencies = [
        ("inventory", "0002_configurator_requirement_finish_policy"),
        ("inventory", "0004_job_reservation_release_number"),
    ]

    operations = []
