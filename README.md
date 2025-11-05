# ForgeDesk ERP

ForgeDesk ERP is a minimal, front-end ready prototype for an operations dashboard. The initial focus is on inventory tracking for aluminum entrance door fabrication, with a roadmap toward work order management and assembly configuration.

## Project layout

```
app/
  config/         Application level configuration such as branding metadata.
  data/           Domain data stubs that can be replaced with database calls or APIs.
  helpers/        Shared PHP helpers.
public/
  css/            Styles extracted from the original single-file template.
  index.php       Entry point that composes the dashboard from modular pieces.
```

## Next steps

* Replace the PHP array data sources with persistent storage.
* Implement inventory CRUD flows and bin transfers.
* Build the work order and assembly modules outlined in the roadmap cards.
