# rsx/models — application models

Every application Eloquent model, flat, one file per model, named
`<thing>_model.php` declaring class `<Thing>_Model`. There are no subdirectories:
the manifest finds classes by NAME, never by path, so nesting buys nothing and
costs grep-ability. (`Project_Model.js` is the one JS file here — a client-side
model stub, not an ORM class.)

## Invariants

- **Extend the right abstract.** `Rsx_Model_Abstract` for a plain model;
  `Rsx_Site_Model_Abstract` when the table has `site_id` (it installs the tenant
  scope, forces `site_id` on create, and fatals on cross-site access — never
  hand-write `where('site_id')`); `Rsx_Detail_Model_Abstract` for a detail table;
  `Rsx_Actor_Model_Abstract` / `Rsx_Site_Actor_Model_Abstract` for anything that
  signs in or stamps authorship.
- **No mass assignment. No eager loading. No `$fillable`/`$guarded`.** All three
  throw. Assign fields explicitly.
- **Never define `$casts` with `'date'`/`'datetime'`/`'timestamp'`** — the
  framework applies its own string casts, and datetime attributes are already ISO
  strings. Never call `->format()` on one.
- **`fetch()` is a SECURITY boundary, not an aliasing layer.** It strips what the
  caller must not see, and it must carry `#[Ajax_Endpoint_Model_Fetch]` **plus a
  mandatory `#[Auth]`**. Record-level rules (ownership, membership, record state)
  live in the body and return `false`. Never alias enum properties — the
  BEM-style `status_id__label` names are the API.
- **`portal_fetch()` needs `portal_can_read()`**, fail-closed. Enforced by
  `PORTAL-MODEL-FETCH-01`.
- **Mark growth**: `public static $unbounded = true;` on any model whose row count
  grows with customer activity. `DB-UNBOUNDED-01` lints against it.
- **Enums** are integer columns with a `$enums` map on the model. Run
  `php artisan rsx:constants:regenerate` after changing one.

## Pointers

`rsx:man model` · `rsx:man model_fetch` · `rsx:man enums` ·
`rsx:man detail_tables` · `rsx:man polymorphic` · `rsx:man actors` ·
`rsx:man model_normalization` (audit authorship) ·
skills `rspade:model-fetch`, `rspade:model-enums`, `rspade:detail-tables`,
`rspade:actors-and-authorship`, `rspade:polymorphic`

Migrations for these tables belong in `rsx/resource/migrations/`, never in the
framework's `system/database/migrations/`.
