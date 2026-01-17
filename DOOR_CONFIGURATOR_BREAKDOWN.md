# Door Configurator - Technical Breakdown

**Last Updated:** 2026-01-17
**Purpose:** This document describes how the door configurator currently works so we can identify what needs to change.

---

## Overview

The door configurator is a multi-step form system for building door and frame configurations with automatic part selection, dimension calculations, and material lists.

**Core Files:**
- `public/configurator.php` (3,231 lines) - Main UI and form handling
- `app/data/configurator.php` (1,190 lines) - Backend data functions (50+ functions)
- `configurator.md` - UI/UX specification document

---

## 5-Step Workflow

### 1. Configuration Step
**What it does:** Sets up the basic job parameters

**Fields:**
- **Job** - Optional dropdown (can be "Unassigned")
- **Job Scope** - Required: `door_and_frame`, `frame_only`, or `door_only`
- **Quantity** - Required number (min 1)
- **Status** - Required: `draft`, `in_progress`, or `released`
- **Door IDs** - Required list of door tags (must match quantity count)

**Current Behavior:**
- Door tag templates exist in code but are hidden behind feature flag (`if (false)`)
- Session-backed state with database fallback
- Door ID count MUST equal quantity or validation fails

**Notes:**
- Template copying not currently exposed to users

---

### 2. Entry Step
**What it does:** Defines the opening characteristics and dimensions

**Fields:**
- **Opening Type** - `single` or `pair`
- **Hand** (orientation)
  - Single: `LH - Inswing`, `RH - Inswing`, `RHR - LH Outswing`, `LHR - RH Outswing`
  - Pair: `RHR Active` (default) or `LHRA Active` (with caution note)
- **Finish** - `C2`, `DB`, or `BL` (affects frame part filtering)
- **Door Opening Width** - Free-text string (no format enforcement)
- **Door Opening Height** - Free-text string (no format enforcement)
- **Hinging** - `continuous`, `butt`, `pivot_offset`, or `pivot_center`

**Current Behavior:**
- Dimensions stored as strings, not validated
- Parser function `configuratorParseDimension()` handles: feet/inches (3' 6"), fractions (1/4), decimals
- Finish selection feeds into frame parts filtering

**Notes:**
- No dimension validation at entry time
- Parser supports multiple formats: "3' 6 1/4", "42.25", "42 1/4"

---

### 3. Frame Step
**What it does:** Selects frame system and all frame components

**Shown When:** `job_scope` includes frames (`door_and_frame` or `frame_only`)

**Fields:**
- **Frame System** - Dropdown from `inventory_systems` where `system_type = 'framing'`
- **Glazing** - `1/4"`, `1/2"`, or `1"`
- **Transom** - `yes` or `no`
  - If yes, reveals: **Total Frame Height** and **Transom Glazing** (`1/4"`, `1/2"`, `1"`)
- **Frame Parts List** - Dynamic based on opening type and transom setting

**Frame Parts (Single Opening, No Transom):**
1. Hinge Jamb
2. Lock Jamb
3. Door Head
4. Head Door Stop
5. Lock Door Stop
6. Hinge Door Stop

**Frame Parts (Pair Opening, No Transom):**
1. LH Hinge Rail
2. RH Hinge Rail
3. Door Head
4. Head Door Stop
5. LH Door Stop
6. RH Door Stop

**Frame Parts (With Transom) - Adds:**
7. Door Head Transom Stop - Active
8. Door Head Transom Stop - Fixed
9. Vertical Transom Stop - Active
10. Vertical Transom Stop - Fixed
11. Glass Adapter (½" or ¼" based on transom glazing)

**Current Behavior:**
- Frame system dropdown disabled until system selected
- Changing system clears stale selections and reapplies defaults
- Defaults auto-select if system has default part names defined
- Parts filtered by: use path + system + finish (from Entry step)
- Transom toggle auto-submits form to refresh parts list
- Empty option lists show "no matching parts" message

**System Defaults:**
- Function `configuratorApplyFrameDefaults()` matches part labels with default names
- Only applies to empty selections (preserves user edits)

**Notes:**
- Diagnostics logged to console when system query fails
- System selection is REQUIRED before parts load

---

### 4. Door Step
**What it does:** Selects door system and all door components

**Shown When:** `job_scope` includes doors (`door_and_frame` or `door_only`)

**Fields:**
- **Door System** - Dropdown from `inventory_systems` where `system_type = 'door'`
- **Leaf Tabs** - Single shows one tab; Pair shows Active/Inactive tabs

**Per-Leaf Fields:**
- **Stile** - Dropdown (defaults to "Standard Medium Stile")
- **Glazing** - `1/4"`, `1/2"`, or `1"` (drives stop/vinyl/insert selection)
- **Preset** - Optional quick-select options:
  - Standard glazing-driven selection
  - WS–Continuous hinge (hinge rail A)
  - WS–Butt hinge (hinge rail B)
  - Clear selection
- **Parts List** - Filtered by use path and glazing

**Door Parts List (per leaf):**
1. Hinge Rail (Standard)
2. Hinge Rail A (WS - Continuous Hinge)
3. Hinge Rail B (WS - Butt Hinge)
4. Lock Rail
5. Top Rail
6. Bottom Rail
7. Interior Glass Stops - *generated from glazing*
8. Exterior Glass Stops - *generated from glazing*
9. Interior Glass Vinyl - *generated from glazing*
10. Exterior Glass Vinyl - *generated from glazing*
11. Door Set block - *generated from glazing*
12. Door glass jack - *generated from glazing*
13. Glazing package reference - *display only*

**Glazing-Driven Parts (from configurator.md):**

*1/4" Glazing:*
- Interior Stop: E7410
- Exterior Stop: E7410
- Interior Vinyl: P0017
- Exterior Vinyl: P0017

*1/2" Glazing:*
- Interior Stop: E7926
- Exterior Stop: E7926
- Interior Vinyl: P0017
- Exterior Vinyl: P912

*1" Glazing:*
- Interior Stop: E6422
- Exterior Stop: E6422
- Interior Vinyl: P0017
- Exterior Vinyl: P0017

**Current Behavior:**
- Presets swap hinge rails while keeping glazing-driven accessories
- Parts filter by use path and selected glazing
- Empty lists show muted empty-state note
- Each part row includes contextual helper text

**Notes:**
- Glass stops, vinyl, set blocks, and jacks are "generated from glazing" (currently manual selection)
- Three hinge rail options (only one should be selected per door)

---

### 5. Summary Step
**What it does:** Read-only review before saving

**Displays:**
- Configuration details (job, scope, quantity, status, door IDs)
- Entry details (opening type, hand, finish, dimensions, hinging)
- Frame selections (if applicable) - system, glazing, transom, all part selections with labels
- Door selections (if applicable) - system, per-leaf stile, glazing, preset, all part selections with labels

**Actions:**
- Go back (to edit any step)
- Return to list (abandon configuration)
- Save configuration (commit to database)

**Current Behavior:**
- All data displayed is read-only
- Shows part names/labels (not just IDs)
- No dimension calculations shown here

**Notes:**
- Summary doesn't show calculated cut lengths (those are in math formulas)

---

## Data Model

### Database Tables

**1. configurator_part_use_options**
- Hierarchical category tree for parts
- Fields: `id`, `name`, `parent_id`
- Root categories: Door, Frame, Hardware, Accessory

**2. configurator_part_profiles**
- Configurator metadata for inventory items
- Fields: `inventory_item_id`, `is_enabled`, `part_type`, `height_lz`, `depth_ly`, `created_at`
- `height_lz` and `depth_ly` used in dimension calculations

**3. configurator_part_use_links**
- Maps inventory items to use option nodes
- Fields: `inventory_item_id`, `use_option_id`

**4. configurator_part_requirements**
- Defines part dependencies
- Fields: `inventory_item_id`, `required_inventory_item_id`, `quantity`, `finish_policy`, `fixed_finish`
- Finish policies: `fixed`, `match_frame`, `match_door`

**5. configurator_jobs**
- Job directory
- Fields: `id`, `job_number`, `name`, `created_at`

**6. configurator_configurations**
- Configuration headers
- Fields: `id`, `name`, `job_id`, `job_scope`, `quantity`, `status`, `notes`, `created_at`, `updated_at`

**7. configurator_configuration_doors**
- Door tags attached to configurations
- Fields: `id`, `configuration_id`, `door_tag`, `created_at`
- Unique constraint on `(configuration_id, door_tag)`

---

## Dimension Calculations

### Global Variables (configurator.md - lines 93-97)
```
top_gap = 1/8" (0.125")
bottom_gap = 11/16" (0.6875")
hinge_gap = 1/16" (0.0625")
lock_gap = 1/8" (0.125")
```

**Note:** These are hardcoded in `public/configurator.php:75-80`. No UI exists to change them yet.

### Door Frame Parts (Non-Transom)
```
lock_jamb_length = door_opening_height + door_header.height_lz
hinge_jamb_length = door_opening_height + door_header.height_lz
door_header_length = door_opening_width
lock_jamb_door_stop = door_opening_height
hinge_jamb_door_stop = door_opening_height
door_head_door_stop = door_opening_width - lock_jamb_door_stop.height_lz - hinge_jamb_door_stop.height_lz
```

### Door Frame Parts (With Transom)
```
lock_jamb_length = total_frame_height_with_transom
hinge_jamb_length = total_frame_height_with_transom
transom_frame_header_length = door_header_length
door_head_transom_stop_fixed_length = door_header_length
door_head_transom_stop_active = door_head_transom_stop_fixed - 1/32"
vertical_transom_stop_fixed = total_frame_height - door_opening_height - door_header.height_lz - transom_frame_header.height_lz - door_head_transom_stop_fixed.height_lz
vertical_transom_stop_active = vertical_transom_stop_fixed - 1/32"
```

### Door Leaf Parts
```
hinge_rail_length = door_opening_height - top_gap - bottom_gap
lock_rail_length = door_opening_height - top_gap - bottom_gap
top_rail_length = door_opening_width - hinge_gap - lock_gap
bottom_rail_length = door_opening_width - hinge_gap - lock_gap
horizontal_glass_stops = door_opening_width - hinge_gap - lock_gap - hinge_rail.height_lz - lock_rail.height_lz - 1/32"
vertical_glass_stops = door_opening_height - top_gap - bottom_gap - top_rail.height_lz - bottom_rail.height_lz - 1/32"
```

**Implementation:**
- `configuratorParseDimension()` (public/configurator.php:161-198) - Parses strings to decimals
- `configuratorFormatLength()` (public/configurator.php:200-210) - Formats decimals to "X.XXX in"
- `configuratorComputeDoorLeafMath()` (public/configurator.php:283-324) - Calculates door part lengths

---

## Key Backend Functions (app/data/configurator.php)

**Schema Management:**
- `configuratorEnsureSchema()` - Initialize all tables
- `configuratorAllowedPartTypes()` - Returns `['door', 'frame', 'hardware', 'accessory']`
- `configuratorJobScopes()` - Returns job scope options

**Part Hierarchy:**
- `configuratorListUseOptions()` - Get full category tree
- `configuratorInventoryOptionsByUse()` - Filter items by use path, system, finish

**Part Profiles:**
- `configuratorLoadPartProfile()` - Load configurator data for an item
- `configuratorSyncPartProfile()` - Save configurator data and dependencies
- `configuratorListPartProfiles()` - Browse all enabled parts

**Configurations:**
- `configuratorListConfigurations()` - List saved configurations with door tags
- `configuratorCreateConfiguration()` - Save new configuration
- `configuratorUpdateConfiguration()` - Update existing configuration
- `configuratorDeleteConfiguration()` - Remove configuration
- `configuratorLoadConfiguration()` - Load single configuration by ID
- `configuratorLoadConfigurationDoorTags()` - Get door IDs for a configuration

**Jobs:**
- `configuratorListJobs()` - Browse jobs
- `configuratorCreateJob()` - Add new job
- `configuratorDeleteJob()` - Remove job

**Templates:**
- `configuratorListDoorTagTemplates()` - Browse saved door templates
- `configuratorLoadDoorTagTemplate()` - Load template data

**Part Definitions:**
- `configuratorFrameParts()` - Generate frame part list based on opening type and transom
- `configuratorDoorParts()` - Generate door part list based on glazing
- `configuratorApplyFrameDefaults()` - Auto-select default parts for a system

**Math Helpers:**
- `configuratorComputeDoorLeafMath()` - Calculate all door part lengths
- `configuratorParseDimension()` - Parse dimension strings
- `configuratorFormatLength()` - Format dimension numbers

---

## Part Hierarchy Structure

```
Door
├── Interior Opening
├── Exterior Opening
├── Fire Rated
├── Pair Door
├── Single Door
├── Stiles
│   ├── Hinge Rail
│   ├── Lock Rail
│   ├── Top Rail
│   └── Bottom Rail
└── Glazing
    ├── Interior Glass Stop
    ├── Exterior Glass Stop
    ├── Interior Glass Vinyl
    ├── Exterior Glass Vinyl
    ├── Door Set block
    └── Door glass jack

Frame
├── Frame Jambs
│   ├── Hinge Jamb
│   └── Lock Jamb
├── Frame Head
│   └── Door Head
├── Frame Stops
│   ├── Head Door Stop
│   ├── Lock Door Stop
│   └── Hinge Door Stop
├── Pair Frame Rails
│   ├── LH Hinge Rail
│   └── RH Hinge Rail
└── Transom
    ├── Door Head Transom Stop - Active
    ├── Door Head Transom Stop - Fixed
    ├── Vertical Transom Stop - Active
    ├── Vertical Transom Stop - Fixed
    ├── ½ Glass adapter
    └── ¼ Glass adapter

Hardware
└── Door Hardware
    └── (Hinge subtypes)

Accessory
└── (General accessories)
```

---

## Part Filtering Logic

**How parts are filtered for dropdowns:**

1. **Use Path** - Part must be linked to the correct category node
2. **System** - Part must belong to selected door/frame system
3. **Finish** - Part must match selected finish (for frame parts)
4. **Glazing** - Part must match selected glazing (for door glazing-related parts)
5. **Opening Type** - Different parts for single vs pair (affects frame)
6. **Transom** - Different parts when transom enabled (affects frame)

**Implementation:**
- Backend function: `configuratorInventoryOptionsByUse()` in app/data/configurator.php
- Frontend: Dropdowns call this function with appropriate filters
- Empty results show: "no matching parts" message

---

## State Management

**Session Storage:**
- Form state stored in PHP `$_SESSION`
- Survives page reloads
- Allows stepping back/forward through wizard

**Database Fallback:**
- If DB unavailable, falls back to local-only mode
- Shows banner: "Local storage mode active..."
- Configurations saved to session only (not persisted)

**Form State Variables:**
```php
$configFormData = [job_id, job_scope, quantity, status, door_tags]
$entryFormData = [opening_type, hand_single, hand_pair, finish, door_opening_width, door_opening_height, hinging]
$frameFormData = [system_id, glazing, transom, transom_glazing, transom_height, parts]
$doorFormData = [system_id, active[stile, glazing, parts], inactive[stile, glazing, parts]]
```

---

## Current Issues / Gaps

### Missing Functionality

1. **Glazing-driven parts are manual**
   - Glass stops, vinyl, set blocks, jacks labeled "generated from glazing"
   - Currently require manual selection from dropdowns
   - Should auto-populate based on glazing choice

2. **No cut list / dimension output**
   - Formulas documented in configurator.md
   - `configuratorComputeDoorLeafMath()` calculates door lengths
   - No UI showing calculated lengths to user
   - No export to cut list or material list

3. **Gap variables hardcoded**
   - Top gap, bottom gap, hinge gap, lock gap in code
   - configurator.md notes: "we need to implement a way to view/change these"
   - No admin UI to adjust gaps

4. **Door tag templates hidden**
   - Code exists for copying from templates
   - Behind feature flag: `if (false)`
   - Not exposed to users

5. **Dimension validation lacking**
   - Width/height stored as strings
   - Parser handles multiple formats
   - No validation at entry time (could enter invalid data)

6. **Frame parts math incomplete**
   - Door leaf math implemented
   - Frame jamb/header/stop formulas documented but not calculated in UI

7. **Part requirements not shown**
   - Database tracks part dependencies (`configurator_part_requirements`)
   - Finish policies: `fixed`, `match_frame`, `match_door`
   - Not displayed or validated during configuration

### Known Limitations

1. **Single transom glazing size**
   - Can't specify different glazing for different transom sections

2. **No BOM (Bill of Materials) export**
   - Configurations save selections but don't generate material lists

3. **No quantity calculations**
   - Doesn't multiply parts by door quantity
   - Doesn't calculate total linear footage needed

4. **No hardware requirements**
   - Hardware category exists but not integrated into workflow
   - No hinge quantity calculations based on door height

---

## Questions to Address

**For implementing full functionality:**

1. Should glazing-driven parts auto-populate or remain selectable?
2. Where should cut lists be displayed? (Summary step? Separate report?)
3. Should gap variables be globally configurable or per-configuration?
4. How should part requirements be surfaced to users?
5. Should the system calculate total material needs (quantities × doors)?
6. Do we need a separate "hardware" step or integrate into door step?
7. Should configurations export to ERP/inventory system?
8. Do we need approval workflows (status transitions)?

---

## Next Steps

**To make this fully functional:**

1. ☐ Implement auto-selection for glazing-driven parts
2. ☐ Add cut list calculations and display
3. ☐ Create admin UI for gap variables
4. ☐ Enable door tag template copying
5. ☐ Add dimension validation to Entry step
6. ☐ Implement frame parts length calculations
7. ☐ Surface part requirements and dependencies
8. ☐ Build material list/BOM export
9. ☐ Add quantity multiplier for bulk orders
10. ☐ Integrate hardware requirements

---

**File Locations:**
- Main UI: `/home/user/ForgeDesk2/public/configurator.php`
- Backend: `/home/user/ForgeDesk2/app/data/configurator.php`
- Spec: `/home/user/ForgeDesk2/configurator.md`
- This document: `/home/user/ForgeDesk2/DOOR_CONFIGURATOR_BREAKDOWN.md`
