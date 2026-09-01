# rsx/tests — the application test suite

## WHAT IS HERE

Twenty test classes, flat in this directory, all `Rsx\Tests\<Thing>_Test extends
Rsx_Test_Abstract` with `public static function test_*()` methods and optional
`setup()` / `teardown()`.

- **The reference**: `Example_Test` — the runnable template for the assertion API,
  `__assert_throws` and `__acting_as_site`.
- **App libraries**: `Formatters_Test` (phone and currency, transactions off),
  `Analytics_Test` (the external-resource example ships inert).
- **Domain models**: `Party_Test` (class-table inheritance end to end),
  `Polymorphic_Parents_Test` (type-ref pairs read through stock `morphTo()`),
  `Task_Derived_Project_Test` (the derived `project_id`, chain resolution and cycle guard),
  `Contact_Phone_Validation_Test` (server-side E.164 normalisation).
- **UI seams**: `Datagrid_Mass_Actions_Test` (selection modes and CSV export),
  `Revision_History_Test` (the history endpoint's allowlist and its no-enumeration rule),
  `Timezone_Settings_Test`, `Api_Key_Scope_Ui_Test` (presets re-derived by name, so a
  tampering browser cannot widen a key).
- **Portal**: `Portal_Workspaces_Test`, `Portal_Documents_Test`,
  `Portal_Request_Threads_Test`, `Portal_Invitation_Lifecycle_Test`,
  `Portal_Register_Flow_Test`, `Portal_User_Admin_Test`, `Portal_Impersonation_Test`
  (the per-endpoint read-only guard — every Ajax call is a POST, so a blanket POST block
  would break the portal), `Announcement_Test`.
- **External API**: `Client_Attachments_Api_Test` — the app-owned half of file attachment
  (claim once, ownership re-verified on delete, retention and share revocation).

## HOW IT IS USED

```
php artisan rsx:test                     # the whole application suite
php artisan rsx:test Portal_Documents    # substring match on the class name
php artisan rsx:test --filter=test_unshare
php artisan rsx:test --fresh             # drop, recreate and migrate the test database
```

Selectors are conjunctive — each one narrows further.

**The suite runs against a separate database and refuses otherwise.** The runner compares
the developer database with `database.connections.test.database` and aborts before any test
when that key is empty or identical, naming `DB_TEST_DATABASE` as the fix. The file
subsystem is redirected under `storage/rsx-tmp` for the run, so a model-layer delete can
never unlink a blob the developer database shares.

Each test method runs in its own rolled-back transaction; fixtures built in `setup()` sit
outside it and persist for the class. A pure-logic class opts out with
`protected static $use_database_transactions = false;` and gets a blank-slate database
instead. The runner provisions a baseline user (id 1) in the test database, which is why
most tests pin `USER_ID = 1` / `SITE_ID = 1` and call `static::__acting_as_site(1)`.

**These files sit directly in `rsx/tests/`, so none of them belongs to a `--group`** — a
group is a concern subdirectory, matched exactly on the directory name.

## HOW TO CUSTOMIZE

- **Add a test**: a `<Thing>_Test.php` here extending `Rsx_Test_Abstract`. Test the failure
  paths — the denial, the wrong tenant, the second claim — not only the happy one; most of
  the value in the shipped suite is in exactly those cases.
- **Introduce groups** by moving related classes into a concern subdirectory once the flat
  list stops being scannable; `--group=<dir>` then selects them.
- Never point the runner at the developer database and never mark a failing test as
  passing. The refusal above is a guardrail, not an obstacle.
- Delete a test when you delete the feature it covers — a suite that tests removed code is
  the same lie as documentation that describes it.

## RELATED

skills `rspade:rsx-testing`, `rspade:rsx-debug` · `rsx:man testing`
