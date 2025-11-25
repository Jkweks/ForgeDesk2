BEGIN;

ALTER TABLE storage_locations ADD COLUMN IF NOT EXISTS aisle TEXT NULL;
ALTER TABLE storage_locations ADD COLUMN IF NOT EXISTS rack TEXT NULL;
ALTER TABLE storage_locations ADD COLUMN IF NOT EXISTS shelf TEXT NULL;
ALTER TABLE storage_locations ADD COLUMN IF NOT EXISTS bin TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_storage_locations_aisle ON storage_locations ((lower(aisle)));
CREATE INDEX IF NOT EXISTS idx_storage_locations_rack ON storage_locations ((lower(rack)));
CREATE INDEX IF NOT EXISTS idx_storage_locations_shelf ON storage_locations ((lower(shelf)));
CREATE INDEX IF NOT EXISTS idx_storage_locations_bin ON storage_locations ((lower(bin)));

-- Backfill components from dot-delimited names when unset
UPDATE storage_locations
SET aisle = NULLIF(split_part(name, '.', 1), ''),
    rack = NULLIF(split_part(name, '.', 2), ''),
    shelf = NULLIF(split_part(name, '.', 3), ''),
    bin = NULLIF(split_part(name, '.', 4), '')
WHERE aisle IS NULL AND rack IS NULL AND shelf IS NULL AND bin IS NULL;

COMMIT;
