# Non-Destructive Database Schema Deploys

CoreBB production schema deploys should be additive by default. The deploy
tool compares a target schema dump against either a live schema dump or the
configured database, then generates only safe operations:

- `CREATE TABLE IF NOT EXISTS` for tables missing from the destination.
- `ALTER TABLE ... ADD COLUMN` for columns missing from existing tables.
- `ALTER TABLE ... ADD ... KEY` for indexes missing from existing tables.

The tool never drops, renames, modifies, truncates, or deletes. Existing live
tables, columns, indexes, archive structures, and definition drift are
preserved and reported as warnings.

## Dry Run From Two Dumps

Use this before a production merge to review the exact schema delta:

```powershell
php .\tools\db_schema_deploy.php `
  --target-dump='staging-schema.sql' `
  --live-dump='production-schema.sql'
```

If `php` is not available on `PATH`, replace it with the PHP executable path
for the current server or workstation.

```powershell
php .\tools\db_schema_deploy.php --target-dump=staging.sql --live-dump=live.sql
```

## Web GUI

Administrators can use the browser-based deploy tool at:

```text
/admin/?act=db_schema_deploy
```

The GUI accepts both workflows:

- Paste or upload the staging target schema and production current schema.
- Fetch either schema directly from a database using credentials entered on the page.

The page does not store database passwords. Password fields must be re-entered
for each dry run or apply that fetches a schema from a database.

Dry-run mode may compare dumps only. Apply mode always reconnects to the
production database using the production credentials on the page, inspects the
actual live schema, rebuilds the plan, and only then applies non-destructive
operations. Production apply also requires typing `APPLY SCHEMA`.

## Production Apply

Run from the deployed production codebase after the release files and private
config are in place:

```powershell
php tools/db_schema_deploy.php --target-dump=staging.sql --apply --confirm-non-destructive
```

Apply mode loads the normal CoreBB private config, inspects the current
database with `SHOW CREATE TABLE`, rechecks each operation before running it,
and skips anything already present. The confirmation flag is required so a dry
run cannot accidentally become a deploy.

## Archive Safety

Archive and legacy identifiers are treated as protected schema. The tool marks
operations involving names such as `archive`, `legacy`, `secure_archive`,
`is_archive_user`, `vn`, or `vault` as archive-sensitive. If the live database
contains archive/legacy tables or columns that are not present in the target
dump, they are preserved and reported rather than removed.

That means a production archive can safely contain extra one-time import
structures or historical columns without the deploy tool trying to make live
look smaller.

## Initial Release 1.0.0 Schema Delta

The final pre-release comparison that fed the initial public release produced
two additive operations and no warnings:

- Create `admin_tool_permissions`.
- Create `corebb_rate_limits`.

No shared tables have column or index differences in the supplied dumps.
