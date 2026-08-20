# Database System Documentation

## Migration Policy

- **Forward-only migrations** - No rollbacks, no down() methods
- **Automatic snapshot protection** in development mode (handled by `php artisan migrate`)
- **All migrations must be created via artisan** - Manual creation is blocked by whitelist
  - Use `php artisan make:migration:safe` to create whitelisted migrations
- **Sequential execution only** - No --path option allowed
- **Fail on errors** - No fallback to old schemas, migrations must succeed or fail loudly

## Database Rules

- **No mass assignment**: All fields assigned explicitly
- **No eager loading**: ALL eager loading methods throw exceptions - use explicit queries for relationships
  - *Note: Premature optimization is the root of all evil. When you have thousands of concurrent users in production and N+1 queries become a real bottleneck, you'll be motivated enough to remove these restrictions yourself.*
- **Forward-only migrations**: No down() methods

## Naming Conventions

- **Primary keys**: All tables must have `id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY` (exception: sessions table uses Laravel's VARCHAR id)
- **Foreign keys**: Always suffix with `_id` (e.g., `user_id`, `site_id`)
- **Boolean columns**: Always prefix with `is_` (e.g., `is_active`, `is_published`)
- **Timestamp columns**: Always suffix with `_at` (e.g., `created_at`, `updated_at`, `deleted_at`)
- **Integer types**: Use BIGINT for all integers, TINYINT(1) for booleans only
- **No unsigned integers**: All integers should be signed for easier migrations
- **UTF-8 encoding**: All text columns use UTF-8 (utf8mb4_unicode_ci collation)

## Schema Rules

- **BIGINT ID required**: ALL tables must have `id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY` - no exceptions (SIGNED for easier migrations)
- **Raw SQL migrations**: Use DB::statement() with raw MySQL, not Laravel schema builder
- **Restricted commands**: Several dangerous commands disabled (migrate:rollback, db:wipe, etc.)
- **Required columns enforced**: All tables have the audit authorship pairs (created_by_id/created_by_type, updated_by_id/updated_by_type), timestamps, etc. Soft-deleting tables also carry the deletion pair (deleted_by_id/deleted_by_type).
- **UTF-8 encoding standard**: All text columns use UTF-8

## Migration Commands

```bash
php artisan make:migration:safe     # Create whitelisted migration
php artisan migrate                 # Run migrations (auto-snapshot in dev mode)
php artisan migrate:status          # View pending migrations
```

## Mode-Aware Behavior

**Development mode** (`RSX_MODE=development`):
- Automatically creates database snapshot before migrations
- On success: commits changes, regenerates constants, recompiles bundles
- On failure: automatically rolls back to snapshot

**Debug/Production mode** (`RSX_MODE=debug` or `production`):
- No snapshot protection (source code is read-only)
- Schema normalization still runs
- Constants and bundles NOT regenerated
