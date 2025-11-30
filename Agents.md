# ForgeDesk – Codex Agent Guide
A developer guide for AI agents working inside the ForgeDesk ERP repository.  
Your mission: write correct code, follow conventions, avoid regressions, and build features efficiently.

---

## 1. Project Overview

ForgeDesk ERP is a fabrication-focused inventory, estimating, and production environment integrating:

- **PHP backend** (helpers + procedural logic)
- **PostgreSQL database** (migrations + optional init.sql)
- **HTML/CSS/JS frontend** using Bootstrap-style UX
- **Spreadsheet utilities** (`xlsx.php`, EZ Estimate integration)
- **Inventory logic** (job reservations, cycle counts, transactions)
- **Docker-based environments** (dev + prod)
- **Versioned GHCR images + CI/CD workflows**

Agents MUST follow existing structure and naming unless explicitly instructed to change it.

---

## 2. Repository Structure (Typical)

/web
/helpers
/assets
/views
index.php

/data

/database
/migrations
init.sql

/admin_service (optional Django-admin module)

docker-compose.yml


Always search for similar patterns before introducing new logic.

---

## 3. Database Conventions

### 3.1 General Rules
- Use **migrations** for production schema changes.
- Use `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` when adding columns.
- Prefer foreign keys when appropriate.
- Add indexes for columns used in filters/joins.
- Avoid destructive edits unless explicitly instructed.

### 3.2 Key Tables
- `inventory_items`
- `inventory_transactions`
- `inventory_transaction_lines`
- `cycle_count_sessions`
- `cycle_count_lines`
- `job_reservations`
- Future: `suppliers`, `purchase_orders`, `purchase_order_lines`

---

## 4. Coding Style

### PHP
- Procedural with reusable helpers.
- Always use `PDO`.
- Sanitize input. Never echo raw SQL errors.
- Prefer structured arrays for return values.

### HTML/CSS
- Bootstrap-like tables, tabs, cards, components.
- Minimal inline styling.
- Consistent spacing and container structure.

### JS
- Simple inline scripts unless complexity demands helpers.
- No frameworks unless explicitly instructed.

---

## 5. Inventory Concepts

Always handle quantities consistently:

- **On Hand** – physical stock
- **Committed** – reserved for jobs
- **On Order** – open purchase orders
- **Available Now**:  
  `on_hand - committed`
- **Projected Available**:  
  `on_hand - committed + on_order`

Never confuse these or mix their meanings.

---

## 6. Replenishment Module (Core Reference)

Codex will frequently be asked to modify, extend, or implement these.

### 6.1 Required Schema Additions

#### Extend `inventory_items`
Add:
- `committed_qty`
- `on_order_qty`
- `safety_stock`
- `min_order_qty`
- `order_multiple`
- `pack_size`
- `purchase_uom`
- `stock_uom`
- `supplier_id`
- `supplier_sku`

#### Create `suppliers`
Columns:
- `id`, `name`, `email`, `phone`,  
- `default_lead_time_days`, `notes`

#### Create Purchase Order Tables
- `purchase_orders`
- `purchase_order_lines`

Include indexes on supplier, PO, inventory_item.

---

## 6.2 Replenishment Calculations

Always compute:
lead_time_demand = adu * lead_time_days
target_level = lead_time_demand + safety_stock
projected_available = on_hand - committed + on_order
recommended_qty = max(target_level - projected_available, 0)


Apply rules:
- Enforce minimum order quantity.
- Round up to order multiples.
- Convert to packs when pack_size > 1.
- Return recommended in both EACH and PACK units.

---

## 6.3 Replenishment UI

Supplier-tabbed interface:

Columns:
- SKU, Description  
- On Hand, Committed  
- On Order  
- Available Now  
- Projected  
- Reorder Point  
- Days of Supply  
- Recommended Qty  
- Editable Order Qty  
- Include checkbox  

Actions:
- For Tubelite → **Generate EZ Estimate**
- Others → **Generate PO PDF**

Follow existing Bootstrap card/table styles.

---

## 7. EZ Estimate Integration (Tubelite)

Prefix mapping:
- Accessories: `P*`, `S*`, `PTB*`
- Stock Lengths: `T*`, `TU*`, `E*`, `A*`

Rules:
- Insert into appropriate sheets (`Accessories`, `Stock Lengths`)
- Use existing Excel helper patterns
- Flag unmapped SKUs so nothing is silently omitted

---

## 8. Purchase Orders & Receiving

Lifecycle:
draft → sent → partially_received → closed/cancelled

Receiving workflow:
- User selects PO
- Enters qty received for each line
- System:
  - Increases `inventory_items.stock`
  - Updates `qty_received`
  - Refreshes `on_order_qty`
  - Creates `inventory_transactions` and `transaction_line` entries
  - Updates PO status (close when fully received)

Handle:
- Partial receipts  
- Pack-size conversions  
- Backorders  

---

## 9. Docker & Environment

- Dev database may run on port **5433**.
- Prod runs on **5432**.
- GHCR images follow versioned tags.
- Codex must avoid breaking the production docker orchestration.

---

## 10. GitHub Actions / CI

Agents modifying workflows must:
- Maintain semantic versioning
- Preserve existing publish steps
- Avoid breaking `latest`/release-based image tags

---

## 11. Safety Rules

Agents must NOT:
- Remove existing features unless explicitly told to
- Perform global refactors unasked
- Introduce external dependencies without permission
- Break database compatibility
- Guess spreadsheet ranges — pull from repo
- Rewrite UI styling conventions

Agents MUST:
- Keep changes minimal and safe
- Follow existing patterns
- Document ambiguous assumptions in comments
- Make sure all database tables etc are available in Django Admin
- Register every new database table (including configurator tables) in the Django admin app, even if the client UI does not surface them yet
- Modify instructions in AGENTS.md files when a user explicitly asks for instruction updates that do not conflict with existing rules

---

## 12. Efficiency Tips

Agents should:
1. Search the repo for patterns before coding.
2. Add helpers instead of duplicating logic.
3. Keep UI consistent.
4. Document complex calculations inline.
5. Prefer small, atomic commits.
6. Follow prompt hierarchy:
   - **Detailed user instruction → follow exactly**
   - **Vague instruction → improve minimally, safely**

---

## 13. Summary for Codex

- Follow existing patterns  
- Respect inventory math  
- Use migrations  
- Don’t break pack logic  
- Tubelite → EZ Estimate  
- Others → PO PDFs  
- Never remove functionality  
- Keep output small, safe, and consistent  

If unsure, add a comment explaining your assumption rather than inventing structure.


