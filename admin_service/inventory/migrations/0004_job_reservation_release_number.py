from django.db import migrations


class Migration(migrations.Migration):

    dependencies = [
        ("inventory", "0003_remove_use_option_name_unique"),
    ]

    operations = [
        migrations.RunSQL(
            sql=r'''
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_name = 'job_reservations'
                      AND column_name = 'release_number'
                ) THEN
                    ALTER TABLE job_reservations
                        ADD COLUMN release_number INTEGER;
                END IF;

                UPDATE job_reservations
                SET release_number = 1
                WHERE release_number IS NULL;

                ALTER TABLE job_reservations
                    ALTER COLUMN release_number SET DEFAULT 1;
                ALTER TABLE job_reservations
                    ALTER COLUMN release_number SET NOT NULL;

                IF EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'job_reservations_job_number_key'
                      AND conrelid = 'job_reservations'::regclass
                ) THEN
                    ALTER TABLE job_reservations
                        DROP CONSTRAINT job_reservations_job_number_key;
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'job_reservations_job_release_key'
                      AND conrelid = 'job_reservations'::regclass
                ) THEN
                    ALTER TABLE job_reservations
                        ADD CONSTRAINT job_reservations_job_release_key UNIQUE (job_number, release_number);
                END IF;
            END
            $$;
            ''',
            reverse_sql=migrations.RunSQL.noop,
        ),
    ]
