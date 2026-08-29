# Cached schema

Two build artifacts that let a FRESH install arrive at the current schema without
replaying every migration ever written.

| File | What it is |
|---|---|
| `schema_cache.sql.gz` | A gzipped `mysqldump` of a database migrated from zero. No user rows: the first-run screen creates user 1 after the restore. |
| `uploads_cache.tar.gz` | The content-addressed blob store's contents at that same point - whatever the data-seed migrations wrote. Relative paths, so it extracts into any blob root. |

## Who writes them

`php artisan rsx:db:rebuild_provision_cache_snapshot`, in development mode only. It backs the live database
and blob store up, wipes both, migrates from zero, records the result here, and then
restores the live data.

REGENERATED RARELY - every few months, and only when the operator asks for it by name.
A stale cache is not a bug: migrations newer than it apply on top, because the restore
is a pre-migrate step and not a replacement for the migration run.

## Who reads them

`php artisan migrate`. When the database has NO TABLES AT ALL and
`schema_cache.sql.gz` is present, the restore runs first and the migration run then
applies only the migrations NEWER than the cache. A non-empty database, or a missing
cache, migrates exactly as it always did.

These files are generated. Edit the migrations, not the cache.
