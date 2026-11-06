# rsx/app/frontend/contacts — CRUD, following the clients pattern

Follows `rsx/app/frontend/clients/CLAUDE.md` and the app skill `crud-patterns`: `list/` + `view/` + `edit/` + `history/`, one controller of Ajax endpoints, one datagrid, a dual-`@route` add/edit action. **What differs here:**

- **Less surface**: no portal subtree, no modals, no SCSS at all; no `delete`/`restore`/`fetch_deleted` (only `bulk_delete`, so the view page has no delete or restore button and no soft-deleted fallback fetch); CSV export only, no XLSX; one quick filter (`priority`), not two.
- **The datagrid joins `clients`** and so implements `map_sort_column()` (`company` -> `company_name`), which the clients grid does not need.
- **`save()` asks a SERVER-DRIVEN QUESTION**: creating a contact whose email another
  non-deleted contact already holds returns `response_form_question('duplicate_email', ...)`
  - a `confirm` naming the existing contact and its client - and writes nothing until it is
  answered. "Create anyway" (`true`) falls through and creates the second contact; "Open the
  existing contact" (`false`) is an ANSWER, writing nothing and returning a redirect to that
  record; Back leaves the form open. The app's worked example of validate -> ask -> write;
  the dialog itself is `rsx/lib/modal/modal.js`'s registered handler.
- **`is_active` and `priority` are on the form** (a `Checkbox_Input` and an enum select); a new contact is active, and `save()` writes `is_active = 1` for a create that does not send the key. An inactive contact shows an Inactive badge and is not offered for portal invitation.
- **The value rules are `Contact_Model::value_errors()`** (column lengths, a valid secondary email, a declared priority) - run by `save()` and by the external API alike.
- **Phone numbers are normalised server-side to E.164** in `save()` via libphonenumber — the app's worked example of format validation living only on the server.
- **`edit/form/client_selector_input`** is defined here and reused by `../projects/edit/`.
- **`?client_id=` mode**: arriving from a client repoints the breadcrumb and Back link and swaps the client picker for a `Hidden_Input`.
- **The edit action is the app's commented example of the navigation guard** (the tasks
  and projects edit actions carry the same lines). The guard is a toggle that follows the
  form: the action wires the form it was given so its `'dirty'` event - true when the user
  has changed a value (never the fill from the loaded record), false when every value is
  back or the save succeeds - calls `Rsx.set_navigation_guard()` /
  `Rsx.clear_navigation_guard()`; the Cancel link clears it through
  `@click=this.discard_changes` and then navigates as an ordinary link; `on_stop()` clears
  it. While it is on the BROWSER asks - its unload dialog for a link (the header's Back
  link included), refresh or close, its confirm box for Back/Forward - and the page owns
  no dialog. Skill `rspade:navigation-guard`; contract in `rsx:man spa`.
- **Portal-adjacent without owning the portal**: the view sidebar links to the client's portal tab and, for a contact with a portal user, offers `View as Client (read-only)`: an `<a target="_blank">` to the `view_as_client` route (`/contacts/view/:id/view-as-client`, `#[Auth('can_impersonate')]`), which starts the impersonation (`Portal_Session::begin_impersonation_from_staff()`) and 302s the new tab to the URL it returns - the user's own click opens the tab, so no popup blocker refuses it. A GET that acts, so it refuses a navigation another site started (`$request->is_same_origin_navigation()`); it declares no site lock (it writes only the browser's session rows). The link is hidden while the site has no client portal (`window.rsxapp.portal.base` null); a refusal renders as an error page in the new tab (no such contact 404, no portal account or "This site has no client portal." 400, a failed `can_impersonate` re-check 403).
- The Projects tab does not live-update: `Project_Model` touches the client, not the contact.
