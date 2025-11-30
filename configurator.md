Door Configurator-

- Configuration Info
  - Job Connection - pulls from job list. Top of list is "Draft - D1", "Draft - D3", "Draft - D4"
  - Quantity
  - Door ID
    - Increases/decreases with quantity, required
  - Scope
    - Frame and Door Only
    - Frame Only
    - Door Only
  - Status
- Entry
  - Entry type
    - Single
    - Pair
  - Hand
    - If type is single
      - LH - Inswing
      - RH - Inswing
      - RHR - LH Outswing
      - LHR - RH Outswing
    - If type is pair
      - RHR Active - Default
      - LHRA Active
        - A prompt should indicate that the LHRA setup is not common and they should verify before selection
  - Finish
    - C2
    - DB
    - BL
  - Door Opening Dimensions
    - Door Opening Width - (DOW variable)
    - Door Opening Height - (DOH variable)
  - Hinging
    - Continuous Hinge
    - Butt Hinge
    - Pivot - Offset
    - Pivot - Center
- Frame (active only if scope is frame or frame and door)
  - Frame System
    - Pull from Inventory systems table
  - Glazing
    - 1/4, 1/2, 1"
  - Transom?
    - If yes, prompt total frame height
  - Frame Parts List
    - If single:
      - Hinge Jamb
      - Lock Jamb
      - Door Head
      - Head Door Stop
      - Lock Door Stop
      - Hinge Door Stop
      - If transom:
        - Door Head Transom Stop - Active
        - Door Head Transom Stop - Fixed
        - Vertical Transom Stop - Active
        - Vertical Transom Stop - Fixed
        - ½ Glass adapter

If transom glass is ½"

- - - - 1. ¼ glass adapter

If transom glass is ¼" or

- - 1. If pair
            -  LH Hinge Rail
            -  RH Hinge Rail
            -  Door Head
            -  Head Door Stop
            -  LH Door Stop
            -  RH door Stop
            -  If transom:
                -  Door Head Transom Stop - Active
                -  Door Head Transom Stop - Fixed
                -  Vertical Transom Stop - Active
                -  Vertical Transom Stop - Fixed
                -  ½ Glass adapter

If transom glass is ½"

- - - - 1. ¼ glass adapter

If transom glass is ¼" or

- Door (active if scope is door or frame and door)
  - Note: lets make this a tabbed page each leaf being configured independently.
    - if the type is single the tab head should match the swing (i.e. LH - Inswing)
    - if the type is pair the two tabs should be named to match the leafs being configured. (RHR or LHR, with the active leaf being first)
  - Stile:
    - Standard Medium Stile
    - Standard Wide Stile
    - Standard Narrow Stile
    - Thermal Narrow Stile
    - Thermal Wide Stile
    - Thermal Medium Stile
    - Monumental Medium Stile
    - Monumental Wide Stile
  - Glazing
    - ¼, ½, 1"
  - Door Parts list
    - Hinge Rail
    - Lock Rail
    - Top Rail
    - Bottom Rail
    - Interior Glass Stops
      - Generated based on door glazing choice
    - Exterior Glass Stops
      - Generated based on door glazing choice
    - Interior Glass Vinyl
      - Generated based on door glazing choice
    - Exterior Glass Vinyl
      - Generated based on door glazing choice
    - Door Set block
      - Generated based on door glazing choice
    - Door glass jack
      - Generated based on door glazing choice