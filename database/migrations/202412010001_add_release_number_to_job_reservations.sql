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
END
$$;

-- Backfill existing rows before enforcing constraints
UPDATE job_reservations
SET release_number = 1
WHERE release_number IS NULL;

-- Ensure the column is required and defaults to the first release
ALTER TABLE job_reservations
    ALTER COLUMN release_number SET DEFAULT 1;
ALTER TABLE job_reservations
    ALTER COLUMN release_number SET NOT NULL;

-- Replace the legacy unique constraint on job_number
ALTER TABLE job_reservations
    DROP CONSTRAINT IF EXISTS job_reservations_job_number_key;

ALTER TABLE job_reservations
    ADD CONSTRAINT job_reservations_job_release_key UNIQUE (job_number, release_number);
