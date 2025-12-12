# Copilot Instructions for "matrikkel"

These instructions help AI coding agents work productively in this Symfony PHP project that integrates with Kartverket's Matrikkel SOAP APIs and a local PostgreSQL database.

## Architecture & Data Flow
- **Two-phase import:** Phase 1 imports base data (municipalities, cadastral units, persons, ownership). Phase 2 imports roads, buildings, property units, and addresses, filtered by owner.
- **Clients & Services:** SOAP clients live in `src/Client/` (extend `AbstractSoapClient`); import/business services live in `src/Service/`; local DB helpers in `src/LocalDb/`; console commands in `src/Console/`.
- **SOAP Classmap:** Always extend `AbstractSoapClient` to get correct classmaps and `MatrikkelContext`. See examples in `src/Client/` and details in COPILOT_AGENT_INSTRUCTIONS.md.
- **Two-step pattern:** For entities like `Bruksenhet`, `Bygning`, and `Adresse`, first fetch IDs via their specific service, then fetch full objects via `StoreService` in batches.
- **Database:** PostgreSQL schema defined in `migrations/V1__baseline_schema.sql`; insert order and foreign keys matter (Phase 1 tables before Phase 2). See the diagram and schema references in `doc/`.

## Workflows (Docker & CLI)
- **Run with Docker:** `./docker-setup.sh`, then `docker compose up -d`. Web: `http://localhost:8083`. CLI in container: `docker compose exec app php bin/console <command>`.
- **Key commands:**
  - `php bin/console matrikkel:ping` – verify API connectivity.
  - Phase 1: `php bin/console matrikkel:phase1-import --kommune=4601`
  - Phase 2: `php bin/console matrikkel:phase2-import --kommune=4601 --organisasjonsnummer=XXXXXX`
  - Unified import: `php bin/console matrikkel:import --kommune=4627 [--organisasjonsnummer=964338442] [--limit=10] [--skip-phase1|--skip-phase2]`
- **REST API:** Base `http://localhost:8083/api`. See routes in [config/routes/api.yaml](../config/routes/api.yaml) and examples in [README.md](../README.md). Key endpoints:
  - `GET /api/ping` – health check.
  - `GET /api/endpoints` – list available endpoints.
  - `GET /api/address/{id}`; `GET /api/address/search?q=...`; `GET /api/address/search/db?q=...`.
  - `GET /api/municipality/{id}`; `GET /api/municipality/number/{number}`.
  - `GET /api/property-unit/{id}`; `GET /api/property-unit/address/{addressId}`.
  - `GET /api/cadastral-unit/{id}` and matrikkel number variants.
  - `GET /api/codelist`; `GET /api/codelist/{id}`.
  - `GET /api/search?q=...&source=api|db&limit=N&offset=M` – pagination supported beyond 100.

## Conventions & Patterns
- **Batch sizes:** NedlastningService ≈ 5000, StoreService ≈ 1000, filter services ≈ 200–500. Always batch IDs; avoid per-object loops.
- **Response handling:** Normalize single vs array items: `$items = $response->return->items ?? []; if (!is_array($items)) { $items = [$items]; }`.
- **MatrikkelContext:** Ensure `snapshotVersion.timestamp = '9999-01-01T00:00:00+01:00'`, `koordinatsystemKodeId.value = 22 (EPSG:25832)`, and proper `locale`.
- **Insert order:** 1) kommuner, 2) matrikkelenheter, 3) personer (physical & legal), 4) eierforhold, 5) veger, 6) bygninger, 7) bruksenheter, 8) adresser.
- **LocalDb tables:** Use table classes' `insertRow(...)` and `flush()` for batch writes. Do not write per-row transactions.
- **Error handling:** Catch `SoapFault` and treat 404-like "not found" as non-fatal for person lookups.

## Database Schema
- **Primary tables:**
  - `matrikkel_kommuner` – municipalities.
  - `matrikkel_matrikkelenheter` – cadastral units/properties.
  - `matrikkel_personer` + `matrikkel_fysiske_personer` + `matrikkel_juridiske_personer` – owners.
  - `matrikkel_eierforhold` – ownership relations (FKs to units and persons).
  - `matrikkel_veger`, `matrikkel_bygninger`, `matrikkel_bruksenheter`, `matrikkel_adresser` – roads/buildings/units/addresses.
- **Order of inserts:** See above; Phase 1 before Phase 2 to satisfy FKs.
- **References:** Full SQL in [migrations/V1__baseline_schema.sql](../migrations/V1__baseline_schema.sql); schema diagram in [doc/database-schema.puml](../doc/database-schema.puml).

## Key Files
- Overall: README.md, COPILOT_AGENT_INSTRUCTIONS.md
- SOAP WSDLs: `doc/wsdl/` (e.g., `AdresseServiceWS.wsdl`, `BruksenhetServiceWS.wsdl`, `StoreServiceWS.wsdl`)
- Commands: `src/Console/` (e.g., phase commands and unified import)
- Clients: `src/Client/AbstractSoapClient.php`, `src/Client/NedlastningClient.php`, others per domain
- Services: `src/Service/` (e.g., `MatrikkelenhetImportService.php`, planned `PersonImportService.php`, etc.)
- DB schema: `migrations/V1__baseline_schema.sql`; data docs in `doc/`

## Examples to Follow
- Two-step import: `Bruksenhet`/`Bygning`/`Adresse` → first `find*ForMatrikkelenheter()` (IDs) then `StoreService::getObjects()` (objects), stored via `LocalDb` tables with batch `flush()`.
- Pagination via NedlastningService: iterate with a cursor (`id`) and batch size until the batch size drops below the threshold.

## Troubleshooting
- Missing `ext-soap`: install the PHP SOAP extension (see README).
- Classmap errors: ensure client extends `AbstractSoapClient` and options set after parent constructor.
- Foreign key violations: verify insert order and entity dependencies.
- Cache issues: `php bin/console cache:clear` (or `docker compose exec app php bin/console cache:clear`).

If any section feels incomplete or unclear, tell us where you need more specifics (e.g., exact batch sizes used in current services, concrete client method names), and we’ll iterate. 