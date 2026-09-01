# rsx/app/frontend/clients — the canonical CRUD feature

**This is the feature to copy.** `contacts/`, `projects/` and `tasks/` follow it and
document only their differences; the app skill `crud-patterns` is the how-to depth.

## WHAT IS HERE

```
clients/
├── clients_controller.php      Frontend_Clients_Controller - Ajax endpoints ONLY
├── list/                       Clients_Index_Action + clients_datagrid.{php,js,jqhtml}
├── view/                       Clients_View_Action  (tabs, sidebar, KPIs)
├── edit/                       Clients_Edit_Action  (ONE action, dual @route)
├── history/                    Clients_History_Action (revision timeline)
└── portal/                     the client-portal panel - unique to this feature
    ├── Clients_Portal_Panel.*          membership, invitations, announcements
    ├── add_member/, resend_invite/     modal bodies
    ├── share_document/                 modal body
    └── request_thread/                 Clients_Request_Thread_Action + its 3 modals
```

`Frontend_Clients_Controller` is one class carrying every endpoint this feature needs —
the grid fetch, the tab loads (`client_contacts`, `client_projects`, `client_activity`),
`save`, `delete`, `bulk_delete`, `restore`, `fetch_deleted`, the two exports (each with its
own `#[Auth('can_export_data')]`), and the whole portal surface. It declares no `#[Route]`
at all: routes live on the JS actions' `@route` decorators, which is what makes this an SPA
feature rather than a Blade one.

## HOW IT IS USED

Every action carries the same five decorators — `@route`, `@layout('Frontend_Spa_Layout')`,
`@spa('Frontend_Spa_Controller::index')`, `@title`, `@auth('is_logged_in')` — plus
`scaffolded = true`, because each template composes `<Page_Scaffold>` (see `../CLAUDE.md`).

**One action serves add and edit**, with two `@route` decorators (`/clients/add` and
`/clients/edit/:id`); the presence of `:id` in `this.args` is what distinguishes them.
The form is `<Rsx_Form $controller="Frontend_Clients_Controller" $method="save"
$data=this.data.form_data>` — `$data` present means edit, absent means create, and the
endpoint is named on the tag and nowhere else.

**The view page uses the three-state pattern**: `<Loading_Spinner>` while `on_load()` runs,
`<Universal_Error_Page_Component>` from `this.data.error_data` on failure, content
otherwise. Its distinctive move is the deleted-record fallback — a `NOT_FOUND` from
`Client_Model.fetch()` retries through `fetch_deleted`, so a soft-deleted client still
renders and can be restored. Tabs are a `Tab_Bar` with hash persistence; the sidebar's
`Kpi_Cell`s are `$clickable` and jump to their tab.

**Realtime**: `this.subscribe(Client_Model, id, () => this.refresh())` in `on_create()`.
`refresh()`, never `reload()` — the server saying something changed must not destroy the
user's open tab and scroll position.

## DataGrid Integration

The list screen is `list/clients_datagrid.{php,js,jqhtml}` fed by
`Frontend_Clients_Controller::datagrid_fetch`, with `bulk_delete`, `export_csv` and `export_xlsx`
serving the footer mass actions.

What this module's grid declares:

- **PHP** (`Clients_DataGrid`) - `build_query()` with the free-text search across name/address/
  city/state/phone/website plus the `status_id` and `priority` quick filters; a
  `$sortable_columns` whitelist; `$secondary_sort = 'id'` so paging stays stable under the
  low-cardinality `priority` sort. No join, so column names are unqualified.
- **jqhtml** - `extends="DataGrid_Abstract"` with `$data_source`, `$sort="id"`, `$order="desc"`;
  sortable columns as literal `data-sortby` attributes in `Slot:DG_Table_Header`; the search and
  the two `<select>` quick filters in `Slot:DG_Card_Header`; `Slot:footer_actions` items carrying
  `data-action="export"` / `"export_xlsx"` / `"delete"`, the two export ones gated on
  `PERM_DATA_EXPORT` to mirror the endpoints.
- **JS** (`Clients_DataGrid`) - `allowed_filters`, `record_noun_plural`, the quick-filter widget
  binding, and `on_footer_action()` dispatching to the three endpoints. `whole_set_selection()` is
  public so the page-header Export button in `Clients_Index_Action.js` can export the whole
  filtered set.

**The contracts live in `rsx/theme/components/datagrid/CLAUDE.md`** - sorting, custom filters and
their URL-hash persistence, the selection payload and its server-side resolution, and the gotchas.
Read that before changing any of the three files here.

## URL Generation

Never hardcode a URL. `Rsx.Route('Clients_View_Action', row.id)` in JavaScript,
`Rsx::Route('Clients_View_Action', $id)` in PHP — the ACTION class name, since that is
where the route is declared. Extra array keys become the query string. `rsx:check` flags a
hardcoded path (`URL-HARDCODE-01`), an interpolated one included.

## HOW TO CUSTOMIZE

- **Start a new feature by copying this directory's shape**, not by inventing one: one
  controller of Ajax endpoints at the root, `list/` + `view/` + `edit/`, `history/` if the
  model records revisions.
- **Add an endpoint** to the existing controller rather than creating a second one — a
  feature has one controller, and the gate is declared once at class level.
- **Keep validation on the server.** `save()` validates and returns
  `response_form_error($message, ['field' => 'Message'])`; the form paints it under the
  field. `$required` on a field is an asterisk announcing a server rule, not a check.
- **A blank field is a value, not an omission** — it arrives as `''` and must validate.
  Never "keep the old value when blank": that makes a failed clear look like a success.
- **The `portal/` subtree is optional.** Delete it (and the portal endpoints on the
  controller) if the application has no client portal; nothing else in this feature depends
  on it.
- Page SCSS stays near-empty. If you are writing page-level CSS or repeating markup, the
  answer is a theme component — `rsx/theme/components/CLAUDE.md`.

## RELATED

`../CLAUDE.md` · `../contacts/CLAUDE.md`, `../projects/CLAUDE.md`, `../tasks/CLAUDE.md`
(what each does differently) · `rsx/theme/components/datagrid/CLAUDE.md` ·
`rsx/theme/components/feedback/CLAUDE.md` (the three-state bodies) ·
app skills `crud-patterns`, `form-components`, `modals`, `semantic-components` ·
skills `rspade:spa`, `rspade:model-fetch`, `rspade:ajax-error-handling`, `rspade:realtime` ·
`rsx:man crud`, `rsx:man spa`, `rsx:man view_action_patterns`
