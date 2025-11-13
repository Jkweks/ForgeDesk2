DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'inventory_items' AND column_name = 'committed_qty') THEN
        ALTER TABLE inventory_items DROP COLUMN committed_qty;
    END IF;
END
$$;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'job_reservation_status') THEN
        ALTER TYPE job_reservation_status ADD VALUE IF NOT EXISTS 'active';
        ALTER TYPE job_reservation_status ADD VALUE IF NOT EXISTS 'on_hold';
    END IF;
END
$$;

DO $$
BEGIN
    IF to_regclass('job_reservations') IS NOT NULL THEN
        UPDATE job_reservations SET status = 'active' WHERE status = 'committed';
    END IF;
END
$$;

DO $$
BEGIN
    IF to_regclass('job_reservation_items') IS NOT NULL THEN
        EXECUTE $$
            CREATE OR REPLACE VIEW inventory_item_commitments AS
            SELECT
                i.id AS inventory_item_id,
                COALESCE(SUM(CASE
                    WHEN jr.status IN ('active', 'committed', 'in_progress', 'on_hold')
                        THEN jri.committed_qty
                    ELSE 0
                END), 0) AS committed_qty
            FROM inventory_items i
            LEFT JOIN job_reservation_items jri ON jri.inventory_item_id = i.id
            LEFT JOIN job_reservations jr ON jr.id = jri.reservation_id
            GROUP BY i.id
        $$;
    END IF;
END
$$;
