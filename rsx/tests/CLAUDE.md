# rsx/tests — the application test suite

## WHAT IS HERE

Fifty-four test classes, flat in this directory, all `Rsx\Tests\<Thing>_Test extends
Rsx_Test_Abstract` with `public static function test_*()` methods and optional
`setup()` / `teardown()`.

- **The reference**: `Example_Test` — the runnable template for the assertion API,
  `__assert_throws` and `__acting_as_site`.
- **App libraries**: `Formatters_Test` (phone and currency, transactions off),
  `Analytics_Test` (the external-resource example ships inert).
- **Domain models**: `Party_Test` (class-table inheritance end to end),
  `Client_Region_Name_Test` (a client's region is resolved within its own country - two
  countries sharing a region code never borrow each other's name),
  `Client_Address_Codes_Test` (a client's address codes are ISO: an enabled alpha-2 country,
  a subdivision of THAT country or `N/A` - the rule the staff save and the API both check),
  `Polymorphic_Parents_Test` (type-ref pairs read through stock `morphTo()`),
  `Task_Derived_Project_Test` (the derived `project_id`, chain resolution and cycle guard),
  `Contact_Phone_Validation_Test` (server-side E.164 normalisation),
  `Contact_Duplicate_Question_Test` (the duplicate-email question: asking writes nothing,
  `true` creates the second contact, `false` hands back the existing one, an edit never asks).
- **Error pages**: `App_Error_Pages_Test` — this application's `/error/` declarations actually
  answer: an unmatched staff URL and a gate denial render `Errors_Layout` with 404 and 403,
  a foreign-origin form POST renders the 419 page, a portal failure renders
  `Portal_Auth_Layout` instead, and the `/error/generic` preview is a 500 carrying its
  detail block. The framework suite pins the funnel; this pins the declarations. Named
  `App_` because the framework suite already has an `Error_Pages_Test`, and a same-named
  class in `rsx/` would override it out of the run.
- **UI seams**: `Datagrid_Mass_Actions_Test` (selection modes and CSV export),
  `Revision_History_Test` (the history endpoint's allowlist and its no-enumeration rule),
  `Task_Record_Attachments_Test` (a task's file list: claim, all-or-nothing, single claim, row shape),
  `Timezone_Settings_Test`, `Api_Key_Scope_Ui_Test` (presets re-derived by name, so a
  tampering browser cannot widen a key), `Api_Key_Read_Only_Ui_Test` (only the literal
  '1' mints a read-only key, so a garbled form can never mint the wider one). Both reach
  `create_key`, which requires a password confirmation that is not their subject, so they set
  `Rsx_Password_Confirmation::$force_confirmed_for_tests` for the class.
- **The Developer chip**: `Developer_Chip_Test` — the three payloads that drive it
  (`get_profile`, `get_user`, the users grid) carry `is_developer` ALWAYS and as a real
  boolean, read from the LOGIN identity; a membership with no login identity is false rather
  than an error; and the grid's `login_users` join multiplies no rows and leaves the search
  and the sort resolving to the `users` columns.
- **Staff notifications**: `Notification_Fetch_Test` (the ORM read serves the recipient
  only) and `Notification_Recipient_Test` (`Notification::send()` and every reader agree
  that `user_id` is a LOGIN identity id; the subject's site-user id is forced to differ so
  a reader keyed on the wrong id cannot pass by coincidence).
- **Credentials never serialize**: `Portal_Credential_Never_Export_Test` (a portal user's
  password, an invitation's code and a reset's token are absent from `toArray()` and its JSON).
- **Portal**: `Portal_Workspaces_Test`, `Portal_Documents_Test`,
  `Portal_Request_Threads_Test`, `Portal_Thread_Upload_Claim_Test` (a client's file end to end:
  uploaded through `/_upload` in the portal realm, claimed onto the thread by the reply
  endpoint as a pending document, readable by that client's member and no other client's,
  and never re-claimed by another client presenting its key), `Portal_Invitation_Lifecycle_Test` (including the hourly expiry reaching
  every site's invitations),
  `Portal_Register_Flow_Test`, `Portal_User_Admin_Test`, `Portal_Impersonation_Test`
  (the per-endpoint read-only guard — every Ajax call is a POST, so a blanket POST block
  would break the portal), `Portal_Client_Authorization_Test` (the permission facade
  beneath those screens — membership, role, `accessible_client_ids()`, the shared-item and
  wrong-site denials), `Portal_Access_Revocation_Test` (closing or deleting a client revokes
  every membership's access with the rows kept, and the file gate answers a portal URL for
  the portal identity only, through a live share of the caller's own contact),
  `Portal_Member_Validation_Test` (a membership goes only to the client's own contact, with
  an enum role), `Portal_Notifications_Test` (the notification endpoints return only
  the caller's own feed, and `clients.portal_last_activity_at` is stamped),
  `Announcement_Test`, `Portal_Tenancy_App_Test` (a site with no client portal - off for the
  install or for the site - refuses the invite, the resend and View as Client with nothing
  written or sent; a sent link names the record's site's portal; Site Settings save the
  portal switch and validate the custom domain - not a hostname, another site's; the
  self-signup slug is a DNS label read from the name with the first free numeric suffix).
- **Authorization**: `Endpoint_Data_Gates_Test` reads the manifest's surface index and fails
  when a write-shaped `rsx/app` Ajax or API endpoint lacks `can_edit_data` (or an
  administrative check), or when any other one carries nothing beyond `is_logged_in` - the
  own-record and sign-in endpoints it excuses are listed by name, with the reason.
  `User_Management_Authority_Test` (equal-or-lower administration: a superior's current
  role, a new role above the caller's, the hidden invitation link, disabling through
  `users.is_enabled`, site-scoped session lists), `Session_Screens_Test` (the "where you're
  signed in" screens: user management's this-site list and session page, Password & Security
  and portal settings - the three-month cut, the record rule that answers another user's,
  another site's or a dormant session as not found, Sign out refused for a superior and for
  the browser making the request, "everywhere" staying on this site),
  `Portal_Change_Password_Test` (portal Change Password: every field rule answered at once, a
  wrong current password counted as a failed attempt and changing nothing, a correct change
  saved; the throttle refusal is verified over HTTP, since a CLI test has no client address),
  `Api_Access_Permission_Test` (the API and
  key minting require a GRANTED `can_use_api`, a DENY wins), `Cross_Site_Reference_Test`
  (saves refuse another site's ids, grids print no foreign row),
  `Self_Registration_Policy_Test` (self-signup: off answers 404, on without live mail
  throws, on creates a new site + owner + a pending address verification and signs nobody in,
  the emailed code and the emailed link each sign the owner in verified on the new site, an
  existing address gets the same answer with nothing written; an unverified address signing
  in with a password owes the same verification; an invitation's account is verified and
  refuses another address; SSO declines an open invitation, an unverified and an unknown
  address), `Require_Two_Factor_Test` (`rsx.auth.require_two_factor`: off holds nothing; on,
  the password still signs in, page requests are held on Password & Security while the
  enrollment screen, sign-out and Ajax go through, and enrolling releases the hold).
- **Public bundles**: `Public_Bundle_Contents_Test` — the three bundles served to visitors
  who have not signed in (`Login_Bundle`, `Errors_Bundle`, `Portal_Auth_Bundle`) publish
  none of this application's internals. It derives the internals from the manifest - every
  class, component, model and gated-Ajax controller under `rsx/`, minus what the bundle's
  own file list declares - compiles each bundle, and fails when the output NAMES such a
  controller or model or DEFINES such a class, component or component stylesheet. It also
  fails on a shared directory (`rsx/theme`, `rsx/lib`, `rsx/models`) in one of those
  include lists, on an application model the bundle includes without declaring it in the
  test, and on a bundle printed by a layout beside a public page route that the test does
  not list - the case of a public page inheriting a signed-in module's bundle.
- **Two-factor**: `Two_Factor_Login_Verify_Test` — this application's
  `Login_Controller::verify_2fa`, the endpoint `<Two_Factor_Challenge>` is pointed at.
  The framework deliberately ships no verification endpoint (the post-login destination is
  application logic), so the contract it must satisfy — one argument shape in, `{redirect}`
  out, every failure a user-safe `ERROR_VALIDATION` — is pinned here.
  `Portal_Emailed_Sign_In_Codes_Test` pins the portal's emailed codes (the email carries the
  code and its link, either signs in, a resend repeats both). `Portal_Two_Factor_Login_Test`
  pins the portal ladder's equivalent: a portal user holding a
  second factor is PARKED by `Portal_Login_Controller::index()` and sent to `/login/verify`
  rather than signed in, `verify_2fa` answers `{redirect}` and signs them in, and both it and
  `passkey_login` turn a wrong answer into an `ERROR_VALIDATION`.
- **Password reset required**: `Password_Reset_Required_Login_Test` — both password forms
  (`Login_Controller::index()`, `Portal_Login_Controller::index()`) against an account
  flagged `password_reset_required`: the form shows the refusal naming the way through (an
  administrator for staff, "Forgot your password?" on the portal) and nobody is signed in or
  parked behind a challenge; and an ordinary portal password sign-in, through
  `Portal_Auth::verify_credentials()`, writes one success row to the portal login history.
- **Sample data**: `Sample_Data_Seed_Test` — `Sample_Data_Service::seed` (`rsx_app:seed`) fills
  the empty test site with every kind of record its JSON declares (counts derived from the
  JSON, a document of each preview type, every contact on a test address and number, the
  run attributed to the site's first user, the activity log written), and refuses a site
  that already holds data with nothing added.
- **Founder wiring**: `Initial_User_Handler_Test` — this application's
  `user.initial.created` handler (the founder gets the top role), asserted on an account
  the test creates.
- **External API**: `Client_Attachments_Api_Test` — the app-owned half of file attachment
  (claim once, ownership re-verified on delete, retention and share revocation).
- **Declared text types**: `Text_Type_Assignment_Test` — what assignment does at a declared
  column on a real model: a typeless request value reads back as the COLUMN's type (the cast
  disables Eloquent's object caching; this is the test that notices if it comes back), the
  client's claimed type is ignored, the filter runs once on assignment, a bare string is plain
  text escaped into the encoding, a wrong-type value is
  refused not converted, and a typed value refuses to be a string so a lossy concatenation
  cannot reach storage. The framework suite pins the type contract against fixtures; this
  pins the half only a real column can show.
- **Declared text types, the converted columns**: `Text_Type_Descriptions_Test` — the
  `description` columns that moved from plain text to `Rich_Text` and still exist (`tasks`,
  `demo_products`): a bare string reads back typed on each, the filter strips a script on
  each, `to_plain_text()` reduces a stored value for a grid excerpt or a CSV cell, an emptied
  editor is empty by CONTENT, and — the case only a conversion has — the re-encode
  migration's SQL produces exactly what `Rich_Text::from_plain_text()` produces, character for
  character, on every awkward character.

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

## BROWSER TESTS (`playwright/`)

`rsx/tests/playwright/` holds standalone node scripts, NOT PHP test classes. They are not
discovered or run by `rsx:test` — a playwright script drives the ordinary web server in
another process, against the developer database, so it is run directly:

```
node rsx/tests/playwright/modal_enter_accepts.js
```

Each mints its own dev-auth headers through `system/bin/dev-auth.js`, exits 0 on pass and 1
on failure, and prints `PASS:`/`FAIL:` lines.

- `modal_enter_accepts.js` — Enter activates the open dialog's `default: true` button.
  Covers the four cases the behavior has to get right: a simple dialog answers, a
  `Modal.form()` dialog submits *through the modal's own callback* (so `on_success` runs and
  it closes — the failure it exists to catch is Enter reaching the hosted `<Rsx_Form>`
  directly and leaving the dialog open), a textarea keeps Enter as a newline, and a dialog
  with no default button ignores it. Runs against `/dashboard` because `Modal` is
  application code in `rsx/lib/modal/` and is not in the framework's own bundle.

- `password_confirmation_modal.js` — the "confirm it's you" dialog on Password & Security:
  Cancel answers false; three refused passwords close it with the "Not Confirmed" notice and
  answer false, the refusals rendering in the open dialog in between, and the user stays
  signed in. The success path is the framework's (`tests/password_confirmation`).

- `text_type_rendering.js` — declared text types end to end on real screens: interpolating a
  `Rich_Text` mounts `Rich_Text_Display` with real markup and a `Raw_Text` escapes with line
  breaks; the edit form's dynamic tag `<{Project_Model.editor_component_for(...)}>` mounts the editor
  the model names, with no wrapper interposed; and a load-then-save round trip through the
  editor is byte-identical — the assertion that catches a third-party editor silently
  dropping markup it did not author. Waits on the async mounts and the form's populate
  rather than sampling early, because a sleep would be flaky in exactly the direction that
  hides a regression.

A browser test belongs here rather than in the framework suite whenever its subject is
`rsx/` code — the framework suite is guarded against naming application classes and routes.

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
