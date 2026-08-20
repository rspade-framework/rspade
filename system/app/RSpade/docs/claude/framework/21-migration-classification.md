<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## MIGRATION CLASSIFICATION (CORE-SYSTEM VS TEMPLATE-APP)

**MANDATE: classify every new migration as core-system vs template-app.** After creating ANY migration, decide whether the table belongs to the framework (a table framework `/system/` code needs to function) or to the reference/template app.

- **Framework-core** migrations live in `system/database/migrations/` and ship with `rspade_system`.
- **Template-app** migrations (clients, contacts, projects, memberships, shared_items, announcements, request threads - anything whose model lives in `rsx/models/`) MUST live in `rsx/resource/migrations/`, shipping with the template app and NOT the framework.

**The trap**: `make:migration:safe` defaults app-feature migrations to the FRAMEWORK directory under `is_framework_developer=true`. Move them to `rsx/resource/migrations/` (and add them to that directory's `.migration_whitelist`) **so they never leak into the framework and break framework-only installs that lack the app tables.**

**Template-flavored references** (this repo's worked examples, not downstream instructions):
- Detail tables: the `Party` entity - `/rsx/models/party_model.php` plus the staff module under `/rsx/app/frontend/party/`. Its `Rsx_Detail_Table::create(...)` migration belongs in the template-app directory.
- The trashed-record escape from the model-fetch policy: `Frontend_Clients_Controller::fetch_deleted`.
