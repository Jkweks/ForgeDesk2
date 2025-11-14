ALTER TABLE purchase_order_lines
    ADD COLUMN IF NOT EXISTS quantity_cancelled NUMERIC(18, 6) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS purchase_order_receipts (
    id BIGSERIAL PRIMARY KEY,
    purchase_order_id BIGINT NOT NULL REFERENCES purchase_orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
    inventory_transaction_id INTEGER REFERENCES inventory_transactions(id) ON UPDATE CASCADE ON DELETE SET NULL,
    reference TEXT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_purchase_order_receipts_po_id
    ON purchase_order_receipts (purchase_order_id);
CREATE INDEX IF NOT EXISTS idx_purchase_order_receipts_created_at
    ON purchase_order_receipts (created_at DESC);

CREATE TABLE IF NOT EXISTS purchase_order_receipt_lines (
    id BIGSERIAL PRIMARY KEY,
    receipt_id BIGINT NOT NULL REFERENCES purchase_order_receipts(id) ON UPDATE CASCADE ON DELETE CASCADE,
    purchase_order_line_id BIGINT NOT NULL REFERENCES purchase_order_lines(id) ON UPDATE CASCADE ON DELETE CASCADE,
    quantity_received NUMERIC(18, 6) NOT NULL DEFAULT 0,
    quantity_cancelled NUMERIC(18, 6) NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_purchase_order_receipt_lines_receipt
    ON purchase_order_receipt_lines (receipt_id);
CREATE INDEX IF NOT EXISTS idx_purchase_order_receipt_lines_line
    ON purchase_order_receipt_lines (purchase_order_line_id);
