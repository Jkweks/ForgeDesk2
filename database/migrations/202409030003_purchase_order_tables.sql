-- Migration: Add supplier and purchase order structures
-- Ensure inventory_items has replenishment columns and introduce supplier + PO tables

BEGIN;

-- Extend inventory_items with replenishment/supplier fields
ALTER TABLE inventory_items
    ADD COLUMN IF NOT EXISTS on_order_qty NUMERIC(18, 6) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS safety_stock NUMERIC(18, 6) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS min_order_qty NUMERIC(18, 6) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS order_multiple NUMERIC(18, 6) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS pack_size NUMERIC(18, 6) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS purchase_uom TEXT,
    ADD COLUMN IF NOT EXISTS stock_uom TEXT,
    ADD COLUMN IF NOT EXISTS supplier_id BIGINT,
    ADD COLUMN IF NOT EXISTS supplier_sku TEXT;

-- Suppliers table to support purchasing workflows
CREATE TABLE IF NOT EXISTS suppliers (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    contact_name TEXT,
    contact_email TEXT,
    contact_phone TEXT,
    default_lead_time_days INTEGER DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

-- Purchase orders table for inbound replenishment
CREATE TABLE IF NOT EXISTS purchase_orders (
    id BIGSERIAL PRIMARY KEY,
    order_number TEXT UNIQUE,
    supplier_id BIGINT REFERENCES suppliers(id) ON UPDATE CASCADE ON DELETE SET NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    order_date DATE DEFAULT CURRENT_DATE,
    expected_date DATE,
    total_cost NUMERIC(18, 6) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft', 'sent', 'partially_received', 'closed', 'cancelled'))
);

-- Purchase order lines break down each ordered item
CREATE TABLE IF NOT EXISTS purchase_order_lines (
    id BIGSERIAL PRIMARY KEY,
    purchase_order_id BIGINT REFERENCES purchase_orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
    inventory_item_id BIGINT REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE SET NULL,
    supplier_sku TEXT,
    description TEXT,
    quantity_ordered NUMERIC(18, 6) NOT NULL DEFAULT 0,
    quantity_received NUMERIC(18, 6) NOT NULL DEFAULT 0,
    unit_cost NUMERIC(18, 6) NOT NULL DEFAULT 0,
    expected_date DATE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

-- Supporting indexes to speed up common lookups
CREATE INDEX IF NOT EXISTS idx_purchase_orders_supplier_id ON purchase_orders(supplier_id);
CREATE INDEX IF NOT EXISTS idx_purchase_orders_status ON purchase_orders(status);
CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_po_id ON purchase_order_lines(purchase_order_id);
CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_inventory_item_id ON purchase_order_lines(inventory_item_id);

-- Ensure inventory_items.supplier_id references suppliers
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints tc
        WHERE tc.constraint_type = 'FOREIGN KEY'
          AND tc.table_schema = 'public'
          AND tc.table_name = 'inventory_items'
          AND tc.constraint_name = 'inventory_items_supplier_id_fkey'
    ) THEN
        ALTER TABLE inventory_items
            ADD CONSTRAINT inventory_items_supplier_id_fkey
            FOREIGN KEY (supplier_id)
            REFERENCES suppliers(id)
            ON UPDATE CASCADE
            ON DELETE SET NULL;
    END IF;
END;
$$;

COMMIT;
