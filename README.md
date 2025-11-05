# ForgeDesk ERP

ForgeDesk ERP is a minimal inventory dashboard for fabrication teams. The prototype now ships with a PostgreSQL backend and Docker Compose stack so you can run the UI against live data, extend the schema, and prepare for upcoming work order and aluminum door assembly modules.

## Project layout

```
app/
  config/         Application-level configuration and environment bindings.
  data/           Data accessors that query PostgreSQL for dashboard content.
  helpers/        Shared PHP helpers such as icons and database connections.
database/
  init.sql        Bootstrap schema and sample data executed by PostgreSQL on first run.
public/
  css/            Styles extracted from the original single-file template.
  index.php       Entry point that composes the dashboard from modular pieces.
  inventory.php   CRUD workspace for managing inventory parts and suppliers.
Dockerfile        PHP Apache image with PDO_PGSQL support.
docker-compose.yml  Two-service stack (PHP + PostgreSQL) for local development.
```

## Getting started

1. Install [Docker Desktop](https://www.docker.com/products/docker-desktop/) or Docker Engine with Compose V2 support.
2. Clone this repository and switch into the project directory.
3. Build and start the stack:

   ```bash
   docker compose up --build
   ```

4. Visit [http://localhost:8046](http://localhost:8046) to view the dashboard. The PostgreSQL service is exposed on port `5432` with default credentials (`forge` / `forgepass`).

   - The dashboard summary lives at `/index.php`.
   - The inventory management workspace (view, insert, and edit) lives at `/inventory.php`.

The `database/init.sql` script seeds sample inventory items and metrics. You can modify this file or connect with your preferred SQL client to adjust values in real time.

## Environment configuration

The PHP container reads environment variables defined in `docker-compose.yml` and surfaces them through `app/config/app.php`. Override these values in the Compose file or via a `.env` file to customize branding or point to an external database.

Key variables:

- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `APP_NAME`, `APP_TAGLINE`, `APP_USER_EMAIL`, `APP_USER_AVATAR`, `APP_USER_NAME`

## Next steps

* Expand the schema with work order and assembly tables to power the roadmap modules.
* Extend the inventory workspace with delete/bulk adjustments and supplier collaboration tools.
* Add authentication and audit trails to support production deployments.
