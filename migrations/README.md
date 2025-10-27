# Database Migrations

This directory contains Flyway-compatible database migrations for the Matrikkel integration system.

## Schema Files

### V1__baseline_schema.sql
**The official baseline schema** - This is the consolidated migration that replaces all previous migrations (V1-V9).

**Key Features:**
- ✅ Junction table `matrikkel_matrikkelenhet_adresse` for M:N relation (adresse ↔ matrikkelenhet)
- ✅ Junction table `matrikkel_bygning_matrikkelenhet` for M:N relation (bygning ↔ matrikkelenhet)
- ✅ Proper foreign keys and indexes
- ✅ Support for both fysiske personer and juridiske personer
- ✅ Eierforhold (ownership) tracking

**Tables:**
1. `matrikkel_matrikkelenheter` - Cadastral units (matrikkelenheter)
2. `matrikkel_kommuner` - Municipalities
3. `matrikkel_personer` - Base table for all persons (owners)
4. `matrikkel_fysiske_personer` - Physical persons (with fødselsnummer)
5. `matrikkel_juridiske_personer` - Legal entities (with organisasjonsnummer)
6. `matrikkel_eierforhold` - Ownership records
7. `matrikkel_bygninger` - Buildings
8. `matrikkel_bygning_matrikkelenhet` - Junction: bygning ↔ matrikkelenhet
9. `matrikkel_bruksenheter` - Dwelling units
10. `matrikkel_veger` - Streets/roads
11. `matrikkel_adresser` - Base table for all addresses
12. `matrikkel_vegadresser` - Street addresses (vegadresse subtype)
13. `matrikkel_matrikkelenhet_adresse` - Junction: matrikkelenhet ↔ adresse

## Important Notes

### Deprecated Fields
- `matrikkel_adresser.matrikkelenhet_id` - Use junction table instead
- `matrikkel_eierforhold.person_id` - Use `fysisk_person_id` instead
- `matrikkel_eierforhold.juridisk_person_id` - Use `juridisk_person_entity_id` instead

### Migration Strategy
For new installations, only `V1__baseline_schema.sql` runs. Old migrations (V1-V9) have been consolidated and archived.

## Applying Migrations

### Manual Application (PostgreSQL)
```bash
psql -U username -d database_name -f migrations/V1__baseline_schema.sql
```

### Docker
```bash
docker-compose exec db psql -U bergenaktiva -d test -f /migrations/V1__baseline_schema.sql
```

## Schema Documentation

**Always refer to V1__baseline_schema.sql for the current schema!**
