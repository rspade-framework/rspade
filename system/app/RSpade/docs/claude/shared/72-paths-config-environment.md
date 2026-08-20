<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of base_path/storage rules, the directory tree, config tiers, config-vs-env and the .env invariants. The module/feature layout inside rsx/app/ belongs to 31. -->

## PATHS, CONFIG & ENVIRONMENT

**Laravel runs FROM `system/`, so `base_path()` resolves to `<project>/system` — not the project root.** Never join a project-logical path onto it: `base_path('storage/...')` lands inside the framework tree, where nothing lives. Use **`storage_path()`** for anything under storage, **`rsx_project_file_path('storage/...')`** when the project-logical spelling matters (manifest keys use it), and reserve `base_path()` for framework-internal locations.

Volatile storage lives at **`<project>/storage`**, one level ABOVE the Laravel base path; `storage_path()` follows it automatically. Inside it: `rsx-build/` (build artifacts), `rsx-tmp/` (temp caches), `flock/` (system-lock files), `rsx-framework/` (updater ledger + maintenance flag — deliberately NOT `storage/framework/`, Laravel's own tree).

**Project layout**: `rsx/` is your code (`app/` modules, `models/`, `lib/`, `services/`, `handlers/`, `commands/`, `public/`, `resource/`, `theme/`); `system/` is the framework. **Path-agnostic rules**: ANY directory named **`resource/`** is framework-ignored (exception: `/rsx/resource/config/`); ANY directory named **`public/`** is web-accessible and framework-ignored. **Classes are found by NAME, not path** — no imports, no manual namespaces.

**Config is two-tier**: framework `/system/config/rsx.php` (**never modify**) + user `/rsx/resource/config/rsx.php`, deep-merged. **`system/app/Http/Kernel.php` is framework-OWNED** (hard-synced by every framework update, tamper-gated against local edits) — an app's own HTTP middleware is declared in `config('rsx.middleware')` (append-only: it runs AFTER the framework stack). **Use `env()` ONLY for deployment-specific values** (`DB_PASSWORD`, `APP_DEBUG`); application behavior belongs in a config file, under version control.

**`APP_URL` in `.env` is the single hostname source, and outside development it MUST be https** (RSpade assumes upstream SSL termination; http throws at boot in debug and production, where the session cookie is unconditionally `Secure` and a plain-http page would discard it). **Development also accepts http**, so a local container with no TLS in front of it runs as-is (`APP_URL=http://localhost:8080`) — include the port whenever it is not the scheme default. On a host that has TLS write the literal `APP_URL=https://$HOSTNAME` — unquoted, no braces, the only spelling phpdotenv passes through. In development a guard fatals loudly when the browsed host disagrees with it. `Rsx::get_hostname()` / `is_dev_site()` (contains `.dev.`, suppresses real email/SMS delivery) / `is_debug_site()` (developer backdoors) all derive from it.

**`system/.env` is a SYMLINK to the project-root `.env`; the root file is authoritative.** A deploy or clone can materialize it into a real file, at which point edits to the root become inert — `php artisan rsx:env:heal [--dry-run]` repairs it and reports every discarded value.

Skill `rspade:environment-config` (config recipes, hostname-guard diagnosis, `.env` drift repair, path-helper selection). Details: `rsx:man config_rsx`, `rsx:man storage_directories`.
