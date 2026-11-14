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
  `_Event_` siblings). `Portal_Membership_Model`'s portal-access readers (`has_membership`,
  `find_for_user_and_client`, `get_for_user`) and `live_portal_user_ids()` - the recipient
  list of every portal notification addressed to a client's members - count a row only while
  its client is portal-enabled and not deleted; `has_membership_row` / `get_all_for_user` /
  `get_for_client` read every row, for staff screens and for code that creates one.
  `Shared_Item_Model` is per contact and expires: `find_valid_share()` is the one share check.
- **Sign-in** — `Login_User_Password_Reset_Model` (staff "Forgot your password?" links,
  `login_user_password_resets`, NOT site-scoped: a login identity spans every site). It and
  `Portal_Password_Reset_Model` share one token implementation, the
  `Rsx\Lib\PasswordReset\Password_Reset_Token` trait: only the SHA-256 of a link's secret
  is stored, one live link per identity, single-use `claim()`, `invalidate_open_for()` (called
  for every password change by `rsx/handlers/Password_Event_Handlers`, never by a controller),
  and `query_spent()` - the used-or-expired rule `Password_Reset_Link_Service::sweep` deletes
  by, nightly (`rsx/services/CLAUDE.md`).
- **Activity** rows for account security (50-59) are written only by tasks:
  `Action_Log_Model::TYPE_PASSWORD_*` by `Password_Audit_Service`, `TYPE_API_KEY_*` by
  `Api_Key_Audit_Service`; every row carries `audit_session_id`.
- **Activity** — `Action_Log_Model`, `Action_Log_Related_Model`, `Notification_Model`,
  `Announcement_Model`.
- **Misc** — `Demo_Product_Model` (a fixture; delete it with the demo data).
- **No framework-model override ships here.** Every core model is an abstract base
  carrying its whole implementation plus a three-line concrete; an application that needs
  to change one writes `class User_Model extends User_Model_Abstract` in this directory and
  declares only what changes (copying the framework file is refused at manifest build; see
  `rsx:man class_override`). This template needs none: the one framework answer only an
  application can give - where a staff user's profile screen is - is a resolve hook,
  `user.view_profile_url`, answered in `rsx/handlers/User_Profile_Url_Handlers.php`.

`Project_Model.js` is the one JS file here — a client-side model stub, not an ORM class.

## HOW TO CUSTOMIZE

- **Add a model**: `<thing>_model.php` declaring `<Thing>_Model`, extending the right
  abstract for its table, with its migration in `rsx/resource/migrations/`.
- **Give it a gated `fetch()`** if JavaScript must load it; a model with no `fetch()` is
  unreachable from the client on purpose, and a separate controller endpoint that fetches
  one record is the anti-pattern. A data model's staff `fetch()` adds
  `#[Auth('can_view_data')]` to the class gate (`Action_Log_Model`: `can_view_user_activity`)
  - method-level, because a class-level staff check would also land on the model's
  realm-`any` relationship surfaces and the portal realm has no such check.
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
- **A credential column is declared `never_export`** in `$columns` — `Shared_Item_Model`'s,
  `Portal_Invitation_Model`'s bearer token and both reset models' `token_hash` are. It is
  stripped from every payload after Laravel builds it (`$hidden` is not a security
  control: `makeVisible()` undoes it), and an overriding entry can never drop it.
  `rsx:check` flags a `password` / `token` / `secret` column left off it
  (`MODEL-NEVER-EXPORT-01`).
- **A computed value that must reach JS is an APPENDED property: `$appends` + a `getXAttribute()` accessor
  delegating to the public method** — Eloquent serializes it inside `toArray()`, so it
  rides every payload, and the JS stub declares it. Hand-adding the key inside `fetch()`
  puts it on that one payload and on no other, so **no `fetch()` here does that**: every
  body is security plus a bare `toArray()`. The declarations are `party_model.php`
  (`party_id_formatted`), `client_model.php` (`country_name`, `client_id_formatted`,
  `region_name`, `portal_member_count`), `contact_model.php` (`contact_id_formatted`,
  `full_name`, `portal_user_id`), `project_model.php` (`project_id_formatted`),
  `action_log_model.php` (`render`, `actor_display`,
  `subject_display`), `notification_model.php` (`render`, `is_read`)
  and `announcement_model.php` (`is_published`). A derivation that READS A ROW
  (`portal_user_id`, `portal_member_count`, `country_name`, `region_name`) says so in its docblock: it is
  still declared once, and a caller wanting a wide list selects columns rather than
  serializing. `client_model.php::to_fetch_array()` keeps `get_created_by_author`
  hand-added on purpose — it resolves a realm-gated profile URL for one viewer, which a
  list payload must not ask per row.
- **A model written from more than one surface owns its value rules**:
  `Client_Model::value_errors()`, `Contact_Model::value_errors()`,
  `Task_Model::value_errors()` and `Project_Model::value_errors()` answer `[field => message]`
  for every submitted value the record cannot hold (lengths from `field_length()`, enum
  membership, formats, and for a task or a project its site-scoped references and parent
  cycle, for a project its budget through `money_from_submitted()`; built on
  `rsx/lib/field_checks/`), and both the staff save and the external API run them, so the two
  can never disagree. A task's and a project's write is shared too: `Task_Model::save_edit()`
  (fields, parent pair, `direct_project_id` when sent), called inside `Form_Edit::run()` by
  both, each passing `fields: Task_Model::EDIT_FIELDS`; `Project_Model::save_edit()` (the ten
  columns; the staff save syncs the contact and user links itself).
- **Derived columns**: `Task_Model::$project_id` is the one here - declared
  `['derived' => 'derive_project_id', 'from' => ['taskable_type', 'taskable_id', 'direct_project_id']]`,
  computed by the framework's `save()` and never assigned (an assignment, a `Form_Edit::set()`
  or a bulk `update()` naming it throws). It is the project the parent chain reaches
  (`resolve_chain_project_id()`), else the user-chosen `direct_project_id`, which a task under
  a project keeps untouched. Because a descendant's value depends on its ANCESTORS' parent pairs
  (other rows), `Task_Model::save()` is overridden: when an existing task's parent pair changed it
  calls `parent::save()` and then `rederive()` on every task below it, level by level, in one
  transaction - every descendant, also below a child whose own value did not move.
- **Fact groups**: columns that hold ONE edited fact are declared in `$fact_groups`, so a
  save of any of them is a conflict for a stale edit of any field stored in them
  (`rsx:man model`, FACT GROUPS). `Task_Model`'s `parent` group (`taskable_type` +
  `taskable_id`) is the one here; no other template model stores one fact in several
  columns.
- **Mark growth**: `public static $unbounded = true;` on any model whose row count
  grows with customer activity. `DB-UNBOUNDED-01` lints against it.
- **Every column's kind is declared in `public static $columns`** (skill `rspade:models`,
  `rsx:man model`, COLUMN DECLARATIONS): `enum`, `text_type`, `type_ref`, `money`,
  `never_export`, `revision_exclude`, `derived` + `from`. A model with nothing to declare
  declares nothing - except a DECIMAL, FLOAT or DOUBLE column, which is always declared (MODEL-DECIMAL-01).
  Read a declaration through the accessors (`enum_values('col')`, ...), never `::$columns`.
- **Enums** are BIGINT columns with an `enum` entry in `$columns`. `migrate` regenerates
  the constants and the `_AUTO_GENERATED_` header in development; run
  `php artisan rsx:constants:regenerate` after changing one without a migration.
- **Declared TEXT types**: a `text_type` entry in `$columns` says what kind of string a
  TEXT column holds (`Rich_Text`, `Raw_Text` — see `rsx/lib/text_types/CLAUDE.md`). A
  declared column is read as a value object, filtered by its type on write, and printed by
  its type's component instead of by whatever escape the template picked. `Project_Model`
  and `Client_Model` are the worked examples; every `description` column in this
  application is declared `Rich_Text` — `Project_Model`, `Task_Model` and
  `Demo_Product_Model` — and `Project_Model::$notes` is the `Raw_Text` one. An
  undeclared TEXT column is an ordinary string; declaration is opt-in. Use `is_empty()` on such a column, never `=== ''` — a
  value object is never identical to a string, so the comparison is permanently false.
- **Money**: a DECIMAL(p,2) column holding an amount is declared `['money' => true]` and
  reads as an `Rsx_Money` (exact to the cent, never a float), null when the column is NULL;
  a DECIMAL holding anything else is declared `[]`. Here `Project_Model::$budget` and
  `Demo_Product_Model::$price` are money and `Task_Model::$hour_estimate` (hours) is
  declared not money. Assign an `Rsx_Money` or a decimal string (`'29.99'`) - a float
  throws, and an amount beyond what the column can hold (`Model::money_limit('col')`) throws
  before the database sees it; an endpoint reads typed input with
  `Model::money_from_submitted('col', $value)`, which refuses both as field errors. Ask "is there
  an amount" with `!== null` (an object is always truthy) and compare with `equals()` /
  `less_than()`, never `==` / `<` (MODEL-MONEY-02); total a column with `sum_money('col')`.
  The projects feature is the worked example (`rsx/app/frontend/projects/CLAUDE.md`).

## Pointers

`rsx/lib/text_types/CLAUDE.md` ·
`rsx:man model` · `rsx:man model_fetch` · `rsx:man enums` ·
`rsx:man detail_tables` · `rsx:man polymorphic` · `rsx:man actors` ·
`rsx:man model_normalization` (audit authorship) ·
skills `rspade:model-fetch`, `rspade:model-enums`, `rspade:detail-tables`,
`rspade:actors-and-authorship`, `rspade:polymorphic`

Migrations for these tables belong in `rsx/resource/migrations/`, never in the
framework's `system/database/migrations/`.
