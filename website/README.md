# SmartState Website

See the [root README](../README.md) for the full platform documentation, architecture, and setup.

- `SmartState-frontend/` — the PHP application (served by php-fpm behind apache).
- `SmartState-backend/` — the apache, php, and postgres image build contexts, the database schema
  (`postgres/init.sql`), and migrations (`postgres/migrations/`).
