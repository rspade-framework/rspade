# rsx/models — application models

Every application Eloquent model, flat, one file per model, named
`<thing>_model.php` declaring class `<Thing>_Model`. There are no subdirectories:
the manifest finds classes by NAME, never by path, so nesting buys nothing and
costs grep-ability. (`Project_Model.js` is the one JS file here — a client-side
model stub, not an ORM class.)

## WHAT IS HERE

One file per model, grouped by what they are for (the manifest finds classes by name, so
the grouping is for readers only):

- **CRM** — `Client_Model`, `Client_Department_Model`, `Contact_Model`, `Project_Model`,
  `Project_Contact_Model`, `Project_User_Model`, `Task_Model`.
- **Party** (class-table inheritance) — `Party_Model` plus `Party_Person_Detail_Model` and
  `Party_Company_Detail_Model`, the worked example for `$detail_tables`.
- **Portal** — `Portal_Membership_Model`, `Portal_Invitation_Model`,
  `Portal_Password_Reset_Model`, `Portal_Project_Model`, `Shared_Item_Model`, and the
  request-thread set (`Portal_Request_Thread_Model` plus its `_Message_`, `_Document_` and
  `_Event_` siblings).
- **Activity** — `Action_Log_Model`, `Action_Log_Related_Model`, `Notification_Model`,
  `Announcement_Model`.
- **Misc** — `User_Group_Model`, `Demo_Product_Model` (a fixture; delete it with the demo
  data).

`Project_Model.js` is the one JS file here — a client-side model stub, not an ORM class.

## HOW TO CUSTOMIZE

- **Add a model**: `<thing>_model.php` declaring `<Thing>_Model`, extending the right
  abstract for its table, with its migration in `rsx/resource/migrations/`.
- **Give it a gated `fetch()`** if JavaScript must load it; a model with no `fetch()` is
  unreachable from the client on purpose, and a separate controller endpoint that fetches
  one record is the anti-pattern.
- **Changing an existing `fetch()` changes a security boundary** — it is not the place to
  rename a field or format a date, and a change there wants the developer's explicit
  agreement.
- **Deleting a feature deletes its models, its migration and its `fetch()` together.**
  A model kept "just in case" still exposes whatever its `fetch()` allows.
- Enums, revisions and realtime are all per-model declarations here rather than
  configuration elsewhere — turn them on where the data lives.

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
