# rsx/handlers — the application's event handlers

## WHAT IS HERE

Three classes, each a plain `public static` class in `Rsx\Handlers` discovered by the
manifest from its `#[OnEvent]` attributes. There is no registration step.

- **`File_Upload_Handlers`** — `#[OnEvent('file.upload.authorize', priority: 10)]`. Returns
  `true` when either realm is signed in (`Session::is_logged_in()` or
  `Portal_Session::is_logged_in()`), a 403 JSON response otherwise. Both realms are allowed
  deliberately: portal users attach files to request threads through the same transport, and
  the per-feature check happens where the file is claimed.
- **`Initial_User_Handlers`** — `#[OnEvent('user.initial.created', priority: 10)]`. Given
  `{user, login_user, site_id, source}` it promotes the founder to `ROLE_ROOT_ADMIN` **only
  if no role was chosen**, creates the site's `Administrators` `User_Group_Model` with
  `deletion_protection` on, and attaches the founder to it.
- **`Portal_File_Access_Handlers`** — `#[OnEvent('file.thumbnail.authorize')]` and
  `#[OnEvent('file.download.authorize')]`, both priority 10, both delegating to one
  fail-closed `_authorize()`. Staff pass outright; a portal user passes only for a client
  document that is shared with a client they belong to, or for an attachment on a request
  thread of such a client. Membership is the boundary, not the individual share row.

## HOW IT IS USED

**The upload gate is mandatory.** Both upload transports THROW when no
`file.upload.authorize` handler is registered — an unhandled gate would be an anonymous
upload endpoint — so `File_Upload_Handlers` is not optional scaffolding. Its payload carries
`request`, `user`, `params`, `file`, `filename`, `size`, `mime_type`, `extension` and
`tmp_path`, so a stricter policy can read the real bytes and reject before anything persists.

**Gate semantics**: every handler must return `true`; the first non-`true` return denies, and
a gate with no handlers is open — which is why the two file-access gates are written
fail-closed rather than relying on absence.

**Handlers run inline, in the request.** Anything slow belongs in `Task::dispatch()`.

`user.initial.created` is a handler and not a migration on purpose: a migration runs once per
database at a fixed point in history, while this event also fires for the first-run setup
screen and for the test-suite baseline seed, so a test may rely on the group existing.

## HOW TO CUSTOMIZE

- **Tighten the upload gate** by editing `File_Upload_Handlers::require_authentication` —
  a size or extension policy belongs there, where it runs before any byte is stored. Never
  delete the handler to "open uploads"; that closes them instead, loudly.
- **Change what the founder gets** in `Initial_User_Handlers` — extra rows for the first
  account go here, never in a migration keyed to user id 1.
- **Add a handler**: a new class or method in this directory with `#[OnEvent('name')]`.
  Match the trigger kind's return contract — action (ignored), filter (return the
  transformed data), gate (`true` to permit), resolve (`null` to decline).
- Keep one concern per class; the file names are the index a reader scans.

## RELATED

`rsx/services/CLAUDE.md` · skills `rspade:event-hooks`, `rspade:file-attachments`,
`rspade:portal-core` · `rsx:man event_hooks`, `rsx:man file_upload`, `rsx:man initial_user`
