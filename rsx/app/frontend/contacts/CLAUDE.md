# rsx/app/frontend/contacts — CRUD, following the clients pattern

Follows `rsx/app/frontend/clients/CLAUDE.md` and the app skill `crud-patterns`: `list/` + `view/` + `edit/` + `history/`, one controller of Ajax endpoints, one datagrid, a dual-`@route` add/edit action. **What differs here:**

- **Less surface**: no portal subtree, no modals, no SCSS at all; no `delete`/`restore`/`fetch_deleted` (only `bulk_delete`, so the view page has no delete or restore button and no soft-deleted fallback fetch); CSV export only, no XLSX; one quick filter (`priority`), not two.
- **The datagrid joins `clients`** and so implements `map_sort_column()` (`company` -> `company_name`), which the clients grid does not need.
- **Phone numbers are normalised server-side to E.164** in `save()` via libphonenumber — the app's worked example of format validation living only on the server.
- **`edit/form/client_selector_input`** is defined here and reused by `../projects/edit/`.
- **`?client_id=` mode**: arriving from a client repoints the breadcrumb and Back link and swaps the client picker for a `Hidden_Input`.
- **Portal-adjacent without owning the portal**: the view sidebar links to the client's portal tab and, for a contact with a portal user, offers `View as Client (read-only)` through `begin_portal_impersonation` (`#[Auth('can_impersonate')]`).
- The Projects tab does not live-update: `Project_Model` touches the client, not the contact.
- **The `View as Client` button is the app's worked example of definer-scoped slot content.**
  It is rendered inside content handed to `Detail_Sidebar`, wired with a plain
  `@click=this.view_as_client` — content handed to a child resolves against the template
  that wrote it (expressions, `$sid` ids and handler expressions alike), so the action's
  method runs with the action's `this.args.id`.
