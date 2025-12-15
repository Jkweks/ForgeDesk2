# Door Configurator

This file describes the current door configurator experience in `public/configurator.php`. Keep it in sync with the implemented UI so changes to the builder are reflected here.

## Workflow overview
- Landing page shows the multi-step builder alongside a table of saved configurations and a quick-add form for jobs.
- Builder steps: Configuration → Entry → Frame → Door → Summary. Navigation enforces step order but allows stepping back to edit previous stages.
- Session-backed state remembers in-progress forms so reloading the page restores the current step and values. Database failures fall back to local-only mode with a banner message.

## Configuration step
- Fields
  - **Job**: Optional select populated from jobs; "Unassigned" is allowed.
  - **Job scope**: Options come from `configuratorJobScopes()` (door and frame, frame only, door only).
  - **Quantity**: Required numeric field (minimum 1) with a helper note that door IDs must match the quantity.
  - **Status**: `draft`, `in_progress`, or `released`.
  - **Door IDs**: Required list; the number of IDs must equal the quantity. Rows are editable/insertable via JS on the page.
- The dormant "copy from door tag" UI remains in the code but is hidden behind a feature flag (`if (false)`), so copying from templates is not currently exposed.

## Entry step
- **Opening type**: Single or pair.
- **Hand**:
  - Single: LH – Inswing, RH – Inswing, RHR – LH Outswing, LHR – RH Outswing.
  - Pair: RHR Active (default) or LHRA Active with a caution note.
- **Finish**: C2, DB, BL. The finish choice feeds frame part filtering.
- **Door opening**: Width and height free-text fields (stored as strings) with no enforced format.
- **Hinging**: Continuous hinge, butt hinge, pivot offset, pivot center.

## Frame step (shown when the scope includes frames)
- **Frame system**: Select from `inventory_systems` rows where `system_type = 'framing'`. Dropdown stays disabled until a system is chosen; the trace is logged to the console for diagnostics when the query fails. Changing the system clears stale selections and reapplies defaults.
- **Defaults**: If the chosen framing system has default part names, matching options are auto-selected when the frame system is set. Defaults only apply to empty selections so user edits are preserved.
- **Glazing**: 1/4", 1/2", 1".
- **Transom**: Yes/No. When yes, the form reveals total frame height and transom glazing. Transom toggles auto-submit the frame step so the parts list refreshes with the correct transom items.
- **Frame parts list**
  - Parts vary by opening type and transom settings (hinge/lock jambs vs. pair rails; head and jamb stops; transom stops and adapters keyed to the chosen transom glazing).
  - Part dropdowns load only after a frame system is selected and are filtered by use path **and** the selected system plus entry finish. Empty option lists show a muted “no matching parts” note.

## Door step (shown when the scope includes doors)
- **Door system**: Select from `inventory_systems` rows where `system_type = 'door'`.
- **Leaf tabs**: Single openings show one tab labeled with the swing; pairs show active/inactive tabs labeled from the entry hand selection.
- **Per-leaf fields**:
  - **Stile**: Populated from door-system inventory options; defaults to “Standard Medium Stile.”
  - **Glazing**: 1/4", 1/2", 1" (drives the stop/vinyl/insert options).
  - **Preset**: Standard glazing-driven selection, WS–Continuous hinge (hinge rail A), WS–Butt hinge (hinge rail B), or Clear selection. Presets swap hinge rails while keeping glazing-driven accessories.
  - **Parts list**: Options filter by use path and glazing; each row includes contextual helper text. Empty lists display a muted empty-state note.

## Summary step
- Read-only recap of configuration, entry, frame, and door selections (including per-part labels) with actions to go back, return to list, or save the configuration.

## Jobs panel
- A quick inline form lets users add job numbers and names. The jobs table below shows added entries (defaults to an empty-state row until data exists).

## Driven item scratch pad

- Door Glass Stops and Vinyl
  - 1/4" Glazing
    - Interior Stop: E7410
    - Exterior Stop: E7410
    - Interior Vinyl: P0017
    - Exterior Vinyl: P0017

  - 1/2" Glazing
    - Interior Stop: E7926
    - Exterior Stop: E7926
    - Interior Vinyl: P0017
    - Exterior Vinyl: P912

  - 1" Glazing:
    - Interior Stop: E6422
    - Exterior Stop: E6422
    - Interior Vinyl: P0017
    - Exterior Vinyl: P0017

## Math scratchpad
Use this section to stage length formulas for upcoming cut-list work (any LY or LZ variables will be pulled from the appropriate inventory items configurator data, a null field in that configurator data should be treated as 0):

Door Frame Parts (non-transom)
- lock jamb length = door opening height + door header lz
- hinge jamb length = door opening height + door header lz
- door header length = door opening width
- lock jamb door stop = door opening height
- hinge jamb door stop = door opening height
- door head door stop = door opening width - lock jamb door stop LZ - hinge lock jamb LZ

Door Frame Parts (transom, if not specified use non transom formula)
- lock jamb length = total frame height with transom
- hinge jamb length = total frame height with transom
- transom frame header length = door header length
- door head transom stop (fixed) length = door header length
- door head transom stop (active) = door head transom stop (fixed) - 1/32"
- vertical transom stop (fixed) = total frame height with transom - door opening height - door header lz - transom frame header lz - door head transom stop (fixed) lz
- vertical transom stop (active) = vertical transom stop (fixed) - 1/32"

Door Part Global Variables (we need to implement a way to view/change these, they are rarely changed so shouldn't be promenently displayed but still should be accessible)
- Top Gap = 1/8"
- Bottom Gap = 11/16"
- Hinge Gap = 1/16" (unless overriden by future hardware variable)
- lock gap = 1/8"

Door Parts:
- Hinge rail length = Door Opening height - top gap - bottom gap
- Lock Rail length = door opening height - top gap - bottom gap
- Top Rail length = door opening width - hinge gap - lock gap
- Bottom Rail length = door opening width - hinge gap - lock gap
- Horizontal Glass Stops = door opening width - hinge gap - lock gap - hinge rail lz - lock rail lz - 1/32"
- Vertical Glass Stops = door opening height - top gap - bottom gap - top rail lz - bottom rail lz - 1/32"

---

Open questions

None at this time.
