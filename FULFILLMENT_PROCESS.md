# Fulfillment Process Documentation

**Version:** 1.0
**Last Updated:** 2026-01-24
**Purpose:** Reference documentation for understanding and redesigning the ForgeDesk fulfillment workflow from estimate through job completion.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture Components](#architecture-components)
3. [Process Flow Stages](#process-flow-stages)
4. [Database Schema](#database-schema)
5. [API Layer](#api-layer)
6. [UI Components](#ui-components)
7. [State Management](#state-management)
8. [Key Business Rules](#key-business-rules)
9. [Data Flows](#data-flows)
10. [File Reference Map](#file-reference-map)

---

## Overview

The fulfillment process manages the complete lifecycle of job material requirements, from initial estimate analysis through material commitment, job execution, and completion. The system implements a just-in-time inventory reservation model that prevents overselling while allowing real-time visibility into material availability.

### Core Workflow Stages

```
Estimate Upload → Analysis → Material Commitment → Job Reservation → Job Execution → Job Completion
```

### Key Principles

- **Real-time availability tracking**: Uses database views to calculate available inventory (stock - committed)
- **Multi-location inventory support**: Materials can be stored across multiple locations
- **Consumption reconciliation**: Tracks committed vs. actual consumption with delta tracking
- **Daily usage analytics**: Records consumption patterns for forecasting and reorder calculations

---

## Architecture Components

### Technology Stack

- **Backend**: PHP 7.4+ with PDO
- **Database**: MySQL/MariaDB with views and transactions
- **Frontend**: Server-side rendered PHP with JavaScript enhancements
- **File Processing**: PhpSpreadsheet for XLSX parsing
- **PDF Generation**: TCPDF for reports

### System Layers

1. **Data Layer** (`/app/data/`): Database models and basic CRUD operations
2. **Service Layer** (`/app/services/`): Business logic and complex workflows
3. **Helper Layer** (`/app/helpers/`): Utility functions and shared code
4. **Presentation Layer** (`/public/admin/`): UI pages and endpoints

---

## Process Flow Stages

### Stage 1: Estimate Upload

**Purpose**: Accept and store customer estimate files for analysis

**Entry Points**:
- `/public/admin/estimate-upload.php` - Chunked upload endpoint
- `/public/admin/estimate-check.php` - Main workflow page

**Implementation**: `/app/helpers/estimate_uploads.php`

**Functions**:
- `estimate_upload_sanitize_id()` - Validates upload ID (alphanumeric, 8+ chars)
- `estimate_upload_paths()` - Returns storage paths for XLSX and metadata JSON
- `estimate_upload_store_metadata()` - Persists upload metadata
- `estimate_upload_load_metadata()` - Retrieves upload metadata
- `estimate_upload_cleanup()` - Removes temporary files

**Storage**:
- Location: `/tmp/estimate-uploads/`
- Format: `{uploadId}.xlsx` + `{uploadId}.json`
- Lifecycle: Temporary, cleaned up after processing

**Upload Flow**:
1. Client splits XLSX into chunks (JavaScript)
2. Server receives chunks via POST with `upload_id`, `chunk_index`, `total_chunks`, `chunk_data` (base64)
3. Server appends chunks to temp file
4. On final chunk, server validates XLSX integrity
5. Returns JSON: `{status, chunk, complete, bytes}`

---

### Stage 2: Estimate Analysis

**Purpose**: Parse estimate file, extract material requirements, compare against inventory

**Implementation**: `/app/services/estimate_check.php`

**Main Function**: `analyzeEstimateRequirements(\PDO $db, string $filePath): array`

**Process**:
1. Opens XLSX file with PhpSpreadsheet (range A1:BZ2000)
2. Scans sheets: "Accessories", "Stock Lengths", "Special Length"
3. Finds headers in first 60 rows: "Part #", "Qty", "Finish", optional "Color"
4. Reads data rows until 6 consecutive empty rows encountered
5. Normalizes data:
   - Part numbers → uppercase
   - Finishes → validates against: BL, C2, DB, 0R (null if unrecognized)
6. Queries inventory for each unique (part_number, finish) pair
7. Calculates availability:
   - Uses `inventory_item_commitments` view for real-time committed quantities
   - `available_qty = stock - committed_qty`
   - Determines status: `available` (sufficient), `short` (partial), `missing` (not in inventory)

**Output Structure**:
```php
[
  'items' => [
    [
      'part_number' => string,      // Uppercase normalized
      'finish' => ?string,           // BL|C2|DB|0R or null
      'required' => int,             // Quantity from estimate
      'available' => ?int,           // Current available inventory
      'shortfall' => int,            // required - available (if short/missing)
      'status' => string,            // 'available'|'short'|'missing'
      'sku' => ?string,              // Inventory SKU if found
      'inventory_item_id' => ?int,   // Database ID if found
      'committed_qty' => ?int        // Current commitments
    ]
  ],
  'messages' => [string],          // Warnings (missing headers, bad finishes, etc.)
  'counts' => [
    'total' => int,
    'available' => int,
    'short' => int,
    'missing' => int
  ]
]
```

**Header Detection Logic**:
- Searches first 60 rows for header patterns
- Part #: matches "part", "part #", "part#", "part number"
- Qty: matches "qty", "quantity"
- Finish: matches "finish", "color"
- Case-insensitive, trim whitespace

**Data Validation**:
- Empty rows: 6 consecutive empty rows triggers end of data
- Invalid finishes: logs warning, sets finish to null
- Duplicate items: aggregates quantities

---

### Stage 3: Material Commitment

**Purpose**: Reserve inventory for a specific job release, preventing overselling

**Implementation**: `/app/services/reservation_service.php`

**Main Function**: `reservationCommitItems(\PDO $db, array $jobMetadata, array $lineItems): array`

**Parameters**:

```php
$jobMetadata = [
  'job_number' => string,      // Required, job identifier
  'release_number' => int,     // Required, release within job
  'job_name' => string,        // Required, descriptive name
  'requested_by' => string,    // Required, person/department
  'needed_by' => ?string,      // Optional, date (YYYY-MM-DD format)
  'notes' => ?string           // Optional, additional info
];

$lineItems = [
  [
    'inventory_item_id' => int,  // Required, from inventory_items table
    'requested_qty' => int,      // Required, quantity needed
    'commit_qty' => int,         // Required, quantity to actually commit
    'part_number' => string,     // For reference only
    'finish' => ?string,         // For reference only
    'sku' => ?string            // For reference only
  ]
];
```

**Process Flow**:
1. **Validation**:
   - Checks required fields in jobMetadata
   - Validates needed_by date format (Y-m-d)
   - Ensures job_number + release_number combination is unique

2. **Transaction Start** (PDO::beginTransaction())

3. **Create Reservation Header**:
   ```sql
   INSERT INTO job_reservations (
     job_number, release_number, job_name,
     requested_by, needed_by, notes, status
   ) VALUES (?, ?, ?, ?, ?, ?, 'active')
   ```

4. **Create Reservation Line Items**:
   - For each lineItem:
     - Validates inventory_item_id exists
     - Checks for duplicates within same reservation
     - Calculates available_before = stock - committed_qty
     - Inserts into job_reservation_items:
       ```sql
       INSERT INTO job_reservation_items (
         reservation_id, inventory_item_id,
         requested_qty, committed_qty, consumed_qty
       ) VALUES (?, ?, ?, ?, 0)
       ```
     - Calculates available_after (view auto-updates committed_qty)

5. **Transaction Commit**

**Returns**:
```php
[
  'reservation_id' => int,
  'job_number' => string,
  'release_number' => int,
  'job_name' => string,
  'items' => [
    [
      'inventory_item_id' => int,
      'requested_qty' => int,
      'committed_qty' => int,
      'available_before' => int,    // Before this commitment
      'available_after' => int,     // After this commitment
      'item' => string,             // Item description
      'sku' => string,
      'part_number' => string,
      'finish' => ?string
    ]
  ]
]
```

**Business Rules**:
- Status is set to `'active'` on creation (ready to work)
- Committed quantities immediately reduce available inventory via view
- Duplicate (job_number, release_number) is rejected
- Duplicate inventory_item_id within same reservation is rejected
- Can commit partial quantities (commit_qty < requested_qty)
- Does NOT validate sufficient inventory (can overcommit)

---

### Stage 4: Job Reservation Management

**Purpose**: Update reservation details, modify committed quantities, change status

**Implementation**: `/app/services/reservation_service.php`

#### 4A. Update Reservation Metadata & Items

**Function**: `reservationUpdateItems(\PDO $db, int $reservationId, array $jobMetadata, array $existingLines, array $newLines): array`

**Parameters**:
```php
$jobMetadata = [
  'job_name' => ?string,
  'requested_by' => ?string,
  'needed_by' => ?string,  // Y-m-d format
  'notes' => ?string
];

$existingLines = [
  [
    'id' => int,                  // job_reservation_items.id
    'inventory_item_id' => int,
    'requested_qty' => int,
    'new_committed_qty' => int    // Updated commitment
  ]
];

$newLines = [
  [
    'inventory_item_id' => int,
    'requested_qty' => int,
    'committed_qty' => int
  ]
];
```

**Process**:
1. Uses FOR UPDATE lock on reservation row
2. Updates job_reservations with new metadata
3. For each existing line:
   - Validates new_committed_qty >= consumed_qty (can't reduce below consumption)
   - Updates committed_qty
   - Tracks delta for summary
4. Inserts new lines
5. Returns summary with committed/released totals

**Business Rules**:
- Cannot reduce committed_qty below already consumed_qty
- Cannot edit fulfilled or cancelled reservations
- Tracks committed_delta and released_delta for visibility

#### 4B. Change Reservation Status

**Function**: `reservationUpdateStatus(\PDO $db, int $reservationId, string $targetStatus): array`

**Status Transitions**:
```
draft → active (estimate committed)
active → in_progress (work starts)
in_progress → fulfilled (job completed)
Any → on_hold (paused)
on_hold → in_progress (resumed)
Any → cancelled (aborted)
```

**Special Logic: active → in_progress**:
- Validates sufficient on-hand inventory for each committed item
- Checks: `stock >= committed_qty` for each line item
- Returns warnings if shortfall detected
- Allows override but logs insufficient_items array

**Returns**:
```php
[
  'id' => int,
  'job_number' => string,
  'release_number' => int,
  'previous_status' => string,
  'new_status' => string,
  'warnings' => [string],           // If validation issues
  'insufficient_items' => [          // If stock < committed_qty
    [
      'inventory_item_id' => int,
      'item' => string,
      'sku' => ?string,
      'committed_qty' => int,
      'on_hand' => int,
      'shortage' => int,
      'location' => ?string
    ]
  ]
]
```

**Status Labels** (`reservationStatusLabels()`):
- `draft`: "Details still being gathered"
- `active`: "Inventory committed, waiting to be pulled"
- `in_progress`: "Work started, team consuming inventory"
- `fulfilled`: "All inventory reconciled"
- `on_hold`: "Reserved but temporarily paused"
- `cancelled`: "No inventory being held"

#### 4C. List Reservations

**Function**: `reservationList(\PDO $db): array`

**Query**:
```sql
SELECT
  r.*,
  COUNT(i.id) as line_count,
  COALESCE(SUM(i.requested_qty), 0) as total_requested_qty,
  COALESCE(SUM(i.committed_qty), 0) as total_committed_qty,
  COALESCE(SUM(i.consumed_qty), 0) as total_consumed_qty
FROM job_reservations r
LEFT JOIN job_reservation_items i ON r.id = i.reservation_id
GROUP BY r.id
ORDER BY
  CASE WHEN r.status IN ('fulfilled', 'cancelled') THEN 1 ELSE 0 END,
  r.created_at DESC
```

**Returns**: Array of reservation summaries (non-fulfilled first, newest first)

#### 4D. Fetch Reservation Details

**Function**: `reservationFetch(\PDO $db, int $reservationId): array`

**Returns**:
```php
[
  'reservation' => [
    'id' => int,
    'job_number' => string,
    'release_number' => int,
    'job_name' => string,
    'requested_by' => string,
    'needed_by' => ?string,
    'status' => string,
    'notes' => ?string,
    'created_at' => string,
    'updated_at' => string
  ],
  'items' => [
    [
      'id' => int,                      // job_reservation_items.id
      'inventory_item_id' => int,
      'requested_qty' => int,
      'committed_qty' => int,
      'consumed_qty' => int,
      'item' => string,                 // From inventory_items
      'sku' => ?string,
      'part_number' => string,
      'finish' => ?string,
      'location' => ?string,
      'stock' => int,                   // Current on-hand
      'inventory_committed' => int      // From view (all reservations)
    ]
  ]
]
```

---

### Stage 5: Job Execution (In Progress)

**Purpose**: Mark job as actively consuming inventory

**Status**: `in_progress`

**Characteristics**:
- Inventory remains committed (view still counts it)
- Team is pulling materials from locations
- Can update consumed_qty incrementally (not in current implementation)
- Cannot reduce committed_qty below consumed_qty

**Validation on Entry** (from active → in_progress):
- System checks stock >= committed_qty for each line
- Warns if shortfall but allows override
- Returns insufficient_items array for visibility

**UI Behavior**:
- Shows as "Work started, team consuming inventory"
- Enables "Complete Job" action
- Allows editing consumed quantities

---

### Stage 6: Job Completion

**Purpose**: Record actual consumption, release unused inventory, update stock levels

**Implementation**: `/app/services/reservation_service.php`

**Main Function**: `reservationComplete(\PDO $db, int $reservationId, array $actualQuantities): array`

**Parameters**:
```php
$actualQuantities = [
  inventory_item_id => actual_qty,  // Key-value pairs
  // Example:
  // 42 => 95,  // Item 42: consumed 95 units
  // 43 => 120  // Item 43: consumed 120 units
];
```

**Process Flow**:

1. **Validation**:
   - Reservation must exist and status must be 'in_progress'
   - All reservation items must have actual_qty provided

2. **Transaction Start**

3. **Calculate Deltas**:
   ```php
   For each item:
     consumed_delta = actual_qty - already_consumed_qty
     released_qty = committed_qty - actual_qty

     Validations:
     - actual_qty >= already_consumed_qty (can't decrease)
     - actual_qty <= committed_qty (can't exceed commitment)
   ```

4. **Record Inventory Transaction**:
   - Calls `recordInventoryTransaction()` from `/app/data/inventory.php`
   - Creates inventory_transactions record with reference: `"Job {job_number} release {release_number} completion"`
   - For each consumed item, creates transaction line with negative quantity_change
   - Updates inventory_items.stock via `inventoryApplyLocationTransaction()`

5. **Update Reservation Items**:
   ```sql
   UPDATE job_reservation_items
   SET consumed_qty = ?
   WHERE id = ?
   ```

6. **Update Reservation Status**:
   ```sql
   UPDATE job_reservations
   SET status = 'fulfilled'
   WHERE id = ?
   ```

7. **Transaction Commit**

**Returns**:
```php
[
  'reservation_id' => int,
  'job_number' => string,
  'release_number' => int,
  'consumed' => int,                    // Total units consumed
  'released' => int,                    // Total units released back
  'inventory_transaction_id' => ?int,   // Reference for audit
  'items' => [
    [
      'inventory_item_id' => int,
      'consumed' => int,                // Final consumed_qty
      'consumed_delta' => int,          // Units consumed in this completion
      'released' => int,                // Units released back to inventory
      'item' => string,
      'sku' => ?string,
      'part_number' => string,
      'finish' => ?string
    ]
  ]
]
```

**Inventory Transaction Details**:

**Function**: `recordInventoryTransaction(\PDO $db, array $payload): int`

Located in: `/app/data/inventory.php`

**Payload Structure**:
```php
[
  'reference' => string,    // Human-readable identifier
  'notes' => ?string,       // Optional details
  'lines' => [
    [
      'item_id' => int,
      'quantity_change' => int,  // Negative for consumption
      'note' => ?string
    ]
  ]
]
```

**Transaction Process**:
1. Creates inventory_transactions record
2. For each line:
   - Calls `inventoryApplyLocationTransaction()` to distribute across locations
   - Updates inventory_items.stock
   - Records to inventory_transaction_items
3. Records daily usage via `recordDailyUsage()`
4. Recalculates average_daily_use (30-day rolling window)
5. Returns transaction_id

**Location Distribution Logic** (`inventoryApplyLocationTransaction()`):
- **Positive changes** (receiving): Adds to primary location (lowest location_id)
- **Negative changes** (consumption): Deducts from primary first, then secondary, etc.
- Prevents negative balances in any location
- Updates inventory_items.stock
- Returns stock_before and stock_after

**Business Rules**:
- Status changes to 'fulfilled' (locks reservation)
- Committed quantities are released (view no longer counts them)
- Stock levels decrease by consumed_delta
- Released quantities (committed - consumed) return to available inventory
- Daily usage is recorded for forecasting
- Cannot reverse completion (one-way transition)

---

## Database Schema

### Core Tables

#### inventory_items
Primary inventory master table

```sql
CREATE TABLE inventory_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item VARCHAR(255) NOT NULL,           -- Description
  sku VARCHAR(100) UNIQUE,              -- Stock Keeping Unit
  part_number VARCHAR(100) NOT NULL,    -- Manufacturer part number
  finish VARCHAR(50),                   -- BL|C2|DB|0R
  location VARCHAR(255),                -- Display location summary
  stock INT DEFAULT 0,                  -- On-hand quantity
  committed_qty INT DEFAULT 0,          -- Calculated field (not used directly)
  status ENUM('In Stock', 'Low', 'Critical', 'Discontinued'),
  supplier VARCHAR(255),
  supplier_contact TEXT,
  reorder_point INT DEFAULT 0,          -- Trigger for replenishment
  lead_time_days INT DEFAULT 0,
  average_daily_use DECIMAL(10,2),
  on_order_qty DECIMAL(10,2) DEFAULT 0,
  pack_size INT DEFAULT 1,
  purchase_uom VARCHAR(50),             -- Purchase unit of measure
  stock_uom VARCHAR(50),                -- Stock unit of measure
  discontinued BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_part_finish (part_number, finish),
  INDEX idx_sku (sku),
  INDEX idx_status (status)
)
```

#### job_reservations
Reservation header (job-level metadata)

```sql
CREATE TABLE job_reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_number VARCHAR(100) NOT NULL,
  release_number INT NOT NULL,
  job_name VARCHAR(255) NOT NULL,
  requested_by VARCHAR(255) NOT NULL,
  needed_by DATE,
  status ENUM('draft', 'committed', 'active', 'on_hold', 'in_progress', 'fulfilled', 'cancelled')
    DEFAULT 'draft',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY unique_job_release (job_number, release_number),
  INDEX idx_status (status),
  INDEX idx_needed_by (needed_by)
)
```

#### job_reservation_items
Reservation line items (material commitments)

```sql
CREATE TABLE job_reservation_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  inventory_item_id INT NOT NULL,
  requested_qty INT NOT NULL,           -- Quantity requested
  committed_qty INT NOT NULL,           -- Quantity committed
  consumed_qty INT DEFAULT 0,           -- Quantity actually used

  FOREIGN KEY (reservation_id) REFERENCES job_reservations(id) ON DELETE CASCADE,
  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  UNIQUE KEY unique_reservation_item (reservation_id, inventory_item_id),
  INDEX idx_reservation (reservation_id),
  INDEX idx_inventory_item (inventory_item_id)
)
```

#### inventory_transactions
Transaction header (audit trail for stock changes)

```sql
CREATE TABLE inventory_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(255),               -- "Job 12345 release 1 completion"
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_reference (reference),
  INDEX idx_created_at (created_at)
)
```

#### inventory_transaction_items
Transaction line items (detailed stock changes)

```sql
CREATE TABLE inventory_transaction_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT NOT NULL,
  inventory_item_id INT NOT NULL,
  quantity_change INT NOT NULL,         -- Positive = receive, Negative = consume
  note TEXT,

  FOREIGN KEY (transaction_id) REFERENCES inventory_transactions(id) ON DELETE CASCADE,
  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  INDEX idx_transaction (transaction_id),
  INDEX idx_inventory_item (inventory_item_id)
)
```

#### inventory_item_locations
Multi-location storage tracking

```sql
CREATE TABLE inventory_item_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id INT NOT NULL,
  location_id INT NOT NULL,
  quantity INT DEFAULT 0,

  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES locations(id),
  UNIQUE KEY unique_item_location (inventory_item_id, location_id),
  INDEX idx_inventory_item (inventory_item_id),
  INDEX idx_location (location_id)
)
```

#### daily_usage_records
Historical consumption data for forecasting

```sql
CREATE TABLE daily_usage_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id INT NOT NULL,
  usage_date DATE NOT NULL,
  quantity_used INT NOT NULL,

  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  UNIQUE KEY unique_item_date (inventory_item_id, usage_date),
  INDEX idx_usage_date (usage_date)
)
```

### Critical Database Views

#### inventory_item_commitments
Real-time calculation of committed quantities

```sql
CREATE VIEW inventory_item_commitments AS
SELECT
  i.id AS inventory_item_id,
  i.item,
  i.sku,
  i.part_number,
  i.finish,
  i.stock,
  COALESCE(SUM(
    CASE
      WHEN r.status IN ('active', 'in_progress', 'on_hold', 'committed')
      THEN ri.committed_qty
      ELSE 0
    END
  ), 0) AS committed_qty,
  i.stock - COALESCE(SUM(
    CASE
      WHEN r.status IN ('active', 'in_progress', 'on_hold', 'committed')
      THEN ri.committed_qty
      ELSE 0
    END
  ), 0) AS available_qty
FROM inventory_items i
LEFT JOIN job_reservation_items ri ON i.id = ri.inventory_item_id
LEFT JOIN job_reservations r ON ri.reservation_id = r.id
GROUP BY i.id
```

**Purpose**: Provides real-time available quantities without modifying base table

**Key Logic**:
- Only counts reservations with active statuses (active, in_progress, on_hold, committed)
- Fulfilled and cancelled reservations do not reduce availability
- available_qty = stock - committed_qty

**Usage**:
- Estimate analysis queries this view to check availability
- Dashboard "Committed Parts" tab uses this view
- Prevents overselling by showing true available inventory

---

## API Layer

All service functions follow consistent patterns and are located in `/app/services/reservation_service.php` and `/app/data/inventory.php`.

### Reservation Service API

#### reservationCommitItems()
**Purpose**: Create new job reservation from estimate
**Input**: Job metadata, line items array
**Output**: Reservation summary with committed items
**Transaction**: Yes
**Side Effects**: Creates job_reservations + job_reservation_items, updates committed_qty view

#### reservationUpdateItems()
**Purpose**: Modify existing reservation metadata and line items
**Input**: Reservation ID, metadata updates, existing line changes, new lines
**Output**: Summary with delta tracking
**Transaction**: Yes
**Locks**: FOR UPDATE on job_reservations
**Side Effects**: Updates job_reservations + job_reservation_items

#### reservationUpdateStatus()
**Purpose**: Change reservation status with validation
**Input**: Reservation ID, target status
**Output**: Status change summary with warnings
**Transaction**: Yes
**Validation**: Checks stock availability for in_progress transition
**Side Effects**: Updates job_reservations.status

#### reservationComplete()
**Purpose**: Finalize job with actual consumption quantities
**Input**: Reservation ID, actual quantities per item
**Output**: Completion summary with consumption deltas
**Transaction**: Yes
**Side Effects**:
- Creates inventory_transactions record
- Updates inventory_items.stock (via inventoryApplyLocationTransaction)
- Updates job_reservation_items.consumed_qty
- Sets job_reservations.status = 'fulfilled'
- Records daily_usage_records
- Recalculates average_daily_use

#### reservationList()
**Purpose**: Fetch all reservations with aggregated quantities
**Input**: Database connection
**Output**: Array of reservation summaries
**Transaction**: No

#### reservationFetch()
**Purpose**: Get detailed reservation with line items and current inventory status
**Input**: Reservation ID
**Output**: Full reservation details with inventory data
**Transaction**: No

#### reservationStatusLabels()
**Purpose**: Get human-readable status descriptions
**Input**: None
**Output**: Array mapping status codes to labels
**Transaction**: No

### Inventory Service API

#### recordInventoryTransaction()
**Purpose**: Create transaction record and update stock levels
**Input**: Reference string, notes, array of line items
**Output**: Transaction ID
**Transaction**: Yes
**Side Effects**:
- Creates inventory_transactions + inventory_transaction_items
- Updates inventory_items.stock
- Calls inventoryApplyLocationTransaction for each line
- Records daily_usage_records
- Recalculates average_daily_use

#### inventoryApplyLocationTransaction()
**Purpose**: Distribute quantity change across storage locations
**Input**: Item ID, quantity change (positive or negative)
**Output**: Stock before/after
**Transaction**: Expects to be called within transaction
**Logic**:
- Positive: Adds to primary location
- Negative: Deducts from primary first, cascades to secondary
- Prevents negative balances
**Side Effects**: Updates inventory_item_locations, inventory_items.stock

#### loadInventory()
**Purpose**: Fetch inventory items with availability calculations
**Input**: Database connection, optional filters
**Output**: Array of inventory items with committed_qty and available_qty
**Transaction**: No
**Data Source**: Joins inventory_items with inventory_item_commitments view

#### inventoryReservationSummary()
**Purpose**: Get aggregate inventory metrics
**Input**: Database connection
**Output**: Total stock, committed, available, reservation count
**Transaction**: No

### Estimate Service API

#### analyzeEstimateRequirements()
**Purpose**: Parse XLSX estimate and compare with inventory
**Input**: Database connection, file path
**Output**: Analysis array with items, messages, counts
**Transaction**: No
**File Processing**: PhpSpreadsheet, scans multiple sheets
**Side Effects**: None (read-only)

---

## UI Components

### /public/admin/estimate-check.php
**Size**: 37 KB
**Purpose**: Main estimate workflow page

**Responsibilities**:
- Upload estimate XLSX (via AJAX to estimate-upload.php)
- Display upload progress
- Trigger analysis on server
- Render analysis results (available/short/missing tables)
- Allow item selection for commitment
- Collect job metadata (job_number, release_number, job_name, etc.)
- Submit commitment to reservationCommitItems()
- Show commit success/error messages

**Key Features**:
- Chunked upload for large files
- Real-time progress indicator
- Tabbed interface for results (Available | Short | Missing)
- "Select All" functionality per tab
- Form validation before commit
- Downloadable PDF report via estimate-report.php

**Form Structure**:
```html
<form method="POST">
  <input type="hidden" name="action" value="commit">
  <input type="text" name="job_number" required>
  <input type="number" name="release_number" required>
  <input type="text" name="job_name" required>
  <input type="text" name="requested_by" required>
  <input type="date" name="needed_by">
  <textarea name="notes"></textarea>

  <!-- Per selected item -->
  <input type="hidden" name="items[0][inventory_item_id]" value="...">
  <input type="hidden" name="items[0][part_number]" value="...">
  <input type="hidden" name="items[0][finish]" value="...">
  <input type="number" name="items[0][requested_qty]" value="...">
  <input type="number" name="items[0][commit_qty]" value="...">
</form>
```

### /public/admin/job-reservations.php
**Size**: 44 KB
**Purpose**: Reservation management interface

**Capabilities**:
1. **List View** (GET with no params):
   - Shows all reservations in table
   - Columns: Job #, Release, Name, Status, Requested By, Needed By, Items, Actions
   - Color-coded by status
   - Sort: non-fulfilled first, newest first

2. **Edit Mode** (GET ?edit={id}):
   - Loads reservation details
   - Allows metadata updates
   - Allows line item quantity changes
   - Allows adding new line items
   - POST action='edit'

3. **Complete Mode** (GET ?complete={id}):
   - Shows committed quantities
   - Input fields for actual consumed quantities
   - Validates: actual <= committed
   - POST action='complete'

4. **Status Change**:
   - POST action='status' with target_status
   - Shows warnings if validation fails

**Actions**:
```php
// POST action=edit
$_POST = [
  'action' => 'edit',
  'reservation_id' => int,
  'job_name' => string,
  'requested_by' => string,
  'needed_by' => date,
  'notes' => string,
  'existing_items' => [
    [
      'id' => int,
      'inventory_item_id' => int,
      'requested_qty' => int,
      'committed_qty' => int
    ]
  ],
  'new_items' => [...]
];

// POST action=status
$_POST = [
  'action' => 'status',
  'reservation_id' => int,
  'target_status' => string
];

// POST action=complete
$_POST = [
  'action' => 'complete',
  'reservation_id' => int,
  'actual_qty' => [
    inventory_item_id => consumed_qty
  ]
];
```

### /public/admin/estimate-upload.php
**Size**: 3.8 KB
**Purpose**: AJAX endpoint for chunked file uploads

**Request Format**:
```json
POST /admin/estimate-upload.php
{
  "upload_id": "abc12345",
  "chunk_index": 0,
  "total_chunks": 10,
  "chunk_data": "base64_encoded_data..."
}
```

**Response**:
```json
{
  "status": "success",
  "chunk": 1,
  "complete": false,
  "bytes": 102400
}
```

**Error Handling**:
- Validates upload_id format
- Checks chunk sequence
- Validates base64 encoding
- Returns JSON error on failure

### /public/admin/estimate-report.php
**Size**: 1.3 KB
**Purpose**: PDF report generation

**Input**: POST with estimate analysis array
**Output**: PDF download with tables for Missing, Short, Available items
**Uses**: estimateComparisonPdf() from `/app/services/estimate_report.php`

---

## State Management

### Reservation Lifecycle

```
┌─────────────────────────────────────────────────────────────┐
│                    RESERVATION STATUS FLOW                   │
└─────────────────────────────────────────────────────────────┘

  [Estimate Committed]
          │
          ▼
      ┌────────┐
      │ active │  ← Inventory committed, waiting to start
      └────┬───┘
           │
           ├──────────────────┐
           │                  │
           ▼                  ▼
    ┌─────────────┐     ┌──────────┐
    │ in_progress │     │ on_hold  │  ← Temporarily paused
    └──────┬──────┘     └────┬─────┘
           │                  │
           │                  └────────┐
           │                           │
           ▼                           ▼
     ┌───────────┐           ┌───────────────┐
     │ fulfilled │           │  cancelled    │
     └───────────┘           └───────────────┘

     (Locked)                 (Locked)
     Stock reduced            No stock impact
     Commitment released      Commitment released
```

### Status Constraints

| From Status | To Status | Validation Required | Side Effects |
|-------------|-----------|---------------------|--------------|
| draft | active | None | Commits inventory |
| active | in_progress | Stock >= committed_qty | Warns if shortfall |
| active | on_hold | None | Maintains commitment |
| in_progress | fulfilled | actual_qty provided | Stock reduction, commitment release |
| on_hold | in_progress | Stock >= committed_qty | Warns if shortfall |
| on_hold | active | None | Maintains commitment |
| Any | cancelled | None | Releases commitment |
| fulfilled | Any | **BLOCKED** | Terminal state |
| cancelled | Any | **BLOCKED** | Terminal state |

### Inventory Item Status

Auto-calculated based on available_qty vs. reorder_point:

```php
if (discontinued) {
  status = 'Discontinued';
} else if (available_qty >= reorder_point) {
  status = 'In Stock';
} else if (available_qty > 0) {
  status = 'Low';
} else {
  status = 'Critical';
}
```

**Note**: available_qty = stock - committed_qty (from view)

---

## Key Business Rules

### Commitment Rules

1. **Overselling Prevention**:
   - View calculates committed_qty in real-time
   - available_qty visible on dashboard
   - System ALLOWS overcommitment but shows warnings

2. **Duplicate Prevention**:
   - (job_number, release_number) must be unique across job_reservations
   - (reservation_id, inventory_item_id) must be unique within job_reservation_items

3. **Partial Commitment**:
   - Can commit less than requested (commit_qty < requested_qty)
   - Useful for phased material availability

### Consumption Rules

1. **Delta Validation**:
   - actual_qty >= already_consumed_qty (cannot decrease)
   - actual_qty <= committed_qty (cannot exceed commitment)

2. **Over/Under Consumption**:
   - Under: released_qty = committed_qty - actual_qty (returned to stock)
   - Over: **BLOCKED** by validation (cannot exceed committed_qty)

3. **Incremental Consumption** (not currently implemented):
   - Could update consumed_qty during in_progress phase
   - Would require additional UI and API

### Transaction Safety

1. **Database Transactions**:
   - All multi-table operations wrapped in PDO transactions
   - Rollback on error ensures data consistency

2. **Row Locking**:
   - FOR UPDATE used when modifying reservation quantities
   - Prevents race conditions in concurrent updates

3. **View Refresh**:
   - inventory_item_commitments view updates automatically
   - No manual refresh needed

### Inventory Location Rules

1. **Receiving** (positive quantity_change):
   - Always adds to primary location (lowest location_id)

2. **Consumption** (negative quantity_change):
   - Deducts from primary first
   - Cascades to secondary if primary insufficient
   - **Blocks** if total across all locations is insufficient

3. **Location Display**:
   - inventory_items.location is display-only summary
   - Real locations tracked in inventory_item_locations

---

## Data Flows

### Complete Estimate-to-Completion Flow

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. ESTIMATE UPLOAD                                              │
└─────────────────────────────────────────────────────────────────┘
   User uploads estimate.xlsx via estimate-check.php
        │
        ▼
   JavaScript chunks file → POST to estimate-upload.php
        │
        ▼
   Server writes to /tmp/estimate-uploads/{id}.xlsx
        │
        ▼
   Metadata stored: {id}.json (filename, size, timestamp)

┌─────────────────────────────────────────────────────────────────┐
│ 2. ESTIMATE ANALYSIS                                            │
└─────────────────────────────────────────────────────────────────┘
   User clicks "Analyze" → POST to estimate-check.php
        │
        ▼
   analyzeEstimateRequirements($db, $filePath)
        │
        ├─→ PhpSpreadsheet opens XLSX
        ├─→ Scans sheets: Accessories, Stock Lengths, Special Length
        ├─→ Finds headers (Part #, Qty, Finish)
        ├─→ Reads data rows until 6 empty rows
        ├─→ Normalizes: part_number (uppercase), finish (BL|C2|DB|0R)
        │
        ├─→ Query inventory_item_commitments view:
        │     SELECT stock, committed_qty
        │     WHERE part_number = ? AND finish = ?
        │
        └─→ Classify items:
              available: available_qty >= required
              short: 0 < available_qty < required
              missing: available_qty = 0 OR not in inventory
        │
        ▼
   Returns: {items[], messages[], counts{}}
        │
        ▼
   UI renders tables (Available | Short | Missing)

┌─────────────────────────────────────────────────────────────────┐
│ 3. MATERIAL COMMITMENT                                          │
└─────────────────────────────────────────────────────────────────┘
   User selects items, fills job metadata, clicks "Commit"
        │
        ▼
   POST to estimate-check.php action=commit
        │
        ▼
   reservationCommitItems($db, $jobMetadata, $lineItems)
        │
        ├─→ BEGIN TRANSACTION
        │
        ├─→ INSERT INTO job_reservations
        │     (job_number, release_number, job_name,
        │      requested_by, needed_by, notes, status='active')
        │
        ├─→ For each line item:
        │     INSERT INTO job_reservation_items
        │       (reservation_id, inventory_item_id,
        │        requested_qty, committed_qty, consumed_qty=0)
        │
        ├─→ COMMIT TRANSACTION
        │
        └─→ inventory_item_commitments view auto-updates
              (committed_qty increases, available_qty decreases)
        │
        ▼
   Returns: {reservation_id, items[{available_before, available_after}]}
        │
        ▼
   UI shows success message with reservation ID
   Cleanup: estimate_upload_cleanup() deletes temp files

┌─────────────────────────────────────────────────────────────────┐
│ 4. INVENTORY ALLOCATED STATE                                    │
└─────────────────────────────────────────────────────────────────┘
   Reservation status = 'active'
   View shows:
     committed_qty = SUM(committed_qty WHERE status IN active/in_progress)
     available_qty = stock - committed_qty

   Dashboard "Committed Parts" tab shows items with committed_qty > 0
   Other estimates see reduced availability

┌─────────────────────────────────────────────────────────────────┐
│ 5. JOB START (Status Transition)                                │
└─────────────────────────────────────────────────────────────────┘
   User clicks "Start Work" in job-reservations.php
        │
        ▼
   POST action=status, target_status=in_progress
        │
        ▼
   reservationUpdateStatus($db, $reservationId, 'in_progress')
        │
        ├─→ Validation: Check stock >= committed_qty for each item
        │     If shortfall: return warnings + insufficient_items[]
        │     Allow override (user confirms despite shortfall)
        │
        ├─→ UPDATE job_reservations SET status='in_progress'
        │
        └─→ Returns: {previous_status, new_status, warnings[]}
        │
        ▼
   UI shows "Work started" status
   Committed inventory still reserved (view unchanged)

┌─────────────────────────────────────────────────────────────────┐
│ 6. JOB COMPLETION                                                │
└─────────────────────────────────────────────────────────────────┘
   User clicks "Complete Job" in job-reservations.php ?complete={id}
        │
        ▼
   UI shows form with committed quantities, input for actual consumed
        │
        ▼
   User enters actual_qty per item, submits
        │
        ▼
   POST action=complete with actual_qty[inventory_item_id] = consumed
        │
        ▼
   reservationComplete($db, $reservationId, $actualQuantities)
        │
        ├─→ BEGIN TRANSACTION
        │
        ├─→ For each item:
        │     consumed_delta = actual_qty - already_consumed_qty
        │     released_qty = committed_qty - actual_qty
        │     Validate: actual_qty >= already_consumed_qty
        │     Validate: actual_qty <= committed_qty
        │
        ├─→ recordInventoryTransaction($db, [
        │       'reference' => "Job {job_number} release {release} completion",
        │       'lines' => [
        │         ['item_id' => ..., 'quantity_change' => -consumed_delta]
        │       ]
        │     ])
        │     │
        │     ├─→ INSERT INTO inventory_transactions
        │     │
        │     ├─→ For each line:
        │     │     inventoryApplyLocationTransaction(item_id, -consumed_delta)
        │     │     │
        │     │     ├─→ Query inventory_item_locations ORDER BY location_id
        │     │     ├─→ Deduct from primary first
        │     │     ├─→ If insufficient, cascade to secondary
        │     │     ├─→ UPDATE inventory_item_locations SET quantity
        │     │     └─→ UPDATE inventory_items SET stock
        │     │
        │     ├─→ INSERT INTO inventory_transaction_items
        │     │
        │     ├─→ recordDailyUsage(item_id, consumed_delta, today)
        │     │     INSERT INTO daily_usage_records
        │     │       (inventory_item_id, usage_date, quantity_used)
        │     │     ON DUPLICATE KEY UPDATE quantity_used += ?
        │     │
        │     └─→ Recalculate average_daily_use:
        │           SELECT AVG(quantity_used) FROM daily_usage_records
        │           WHERE usage_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        │           UPDATE inventory_items SET average_daily_use = ?
        │
        ├─→ For each item:
        │     UPDATE job_reservation_items SET consumed_qty = actual_qty
        │
        ├─→ UPDATE job_reservations SET status='fulfilled'
        │
        ├─→ COMMIT TRANSACTION
        │
        └─→ inventory_item_commitments view auto-updates
              (committed_qty decreases for status=fulfilled)
              (available_qty increases by released_qty)
        │
        ▼
   Returns: {
     consumed (total),
     released (total),
     inventory_transaction_id,
     items[{consumed, consumed_delta, released}]
   }
        │
        ▼
   UI shows completion summary
   Inventory levels updated:
     - stock decreased by consumed_delta
     - committed_qty no longer includes this reservation
     - available_qty = stock - (other active commitments)

┌─────────────────────────────────────────────────────────────────┐
│ 7. POST-COMPLETION STATE                                        │
└─────────────────────────────────────────────────────────────────┘
   Reservation status = 'fulfilled' (locked, cannot edit)
   inventory_items.stock reflects actual consumption
   inventory_transactions provides audit trail
   daily_usage_records updated for forecasting
   average_daily_use recalculated (30-day rolling window)
   Released quantities (committed - consumed) returned to available stock
```

---

## File Reference Map

### Core Services
- `/app/services/reservation_service.php` - Reservation lifecycle management
- `/app/services/estimate_check.php` - Estimate parsing and analysis
- `/app/services/estimate_report.php` - PDF report generation
- `/app/data/inventory.php` - Inventory models and transactions

### Helpers
- `/app/helpers/estimate_uploads.php` - Upload utilities and metadata management

### UI Pages
- `/public/admin/estimate-check.php` - Main estimate workflow (37 KB)
- `/public/admin/estimate-upload.php` - AJAX upload endpoint (3.8 KB)
- `/public/admin/estimate-report.php` - PDF download endpoint (1.3 KB)
- `/public/admin/job-reservations.php` - Reservation management (44 KB)

### Database
- `/database/init.sql` - Schema definition with tables and views

### Configuration
- `/app/config/app.php` - Database connection settings

---

## Redesign Considerations

When redesigning this fulfillment process, consider:

### Architectural Improvements
1. **Separate API layer**: Move business logic from UI pages to dedicated API endpoints
2. **Service abstraction**: Create interfaces for services to enable testing and extensibility
3. **Event system**: Emit events on state changes (e.g., on commit, on completion) for extensibility
4. **Transaction management**: Centralize transaction handling with decorators or middleware

### Data Model Enhancements
1. **Incremental consumption**: Allow updating consumed_qty during in_progress phase
2. **Commitment history**: Track changes to committed_qty over time
3. **Location preferences**: Allow specifying preferred pull locations per job
4. **Multi-unit support**: Handle conversion between purchase_uom and stock_uom

### Business Logic Improvements
1. **Reservation expiration**: Auto-cancel reservations not started within timeframe
2. **Over-commitment alerts**: Proactive warnings when available_qty goes negative
3. **Reservation priority**: Allow prioritizing reservations when inventory is scarce
4. **Partial fulfillment**: Support completing jobs in stages (multiple partial completions)

### User Experience Enhancements
1. **Real-time updates**: WebSocket or polling for live inventory updates
2. **Drag-and-drop**: File upload with drag-and-drop interface
3. **Inline editing**: Edit quantities without full page reload
4. **Batch operations**: Select multiple reservations for status changes
5. **Export capabilities**: CSV/Excel export of reservations and analysis results

### Performance Optimizations
1. **View materialization**: Consider materialized views for inventory_item_commitments on large datasets
2. **Caching layer**: Cache frequently accessed inventory data with invalidation on updates
3. **Lazy loading**: Paginate reservation lists and lazy-load line items
4. **Background jobs**: Move heavy operations (PDF generation, analysis) to queue workers

### Security Enhancements
1. **CSRF protection**: Add tokens to all POST requests
2. **Input validation**: Server-side validation with consistent error messages
3. **Authorization**: Role-based access control for reservation actions
4. **Audit logging**: Track all state changes with user attribution

### Testing Improvements
1. **Unit tests**: Test service functions in isolation
2. **Integration tests**: Test complete workflows with database
3. **API tests**: Validate all endpoints with various scenarios
4. **UI tests**: Selenium/Playwright for critical user paths

---

**End of Documentation**
