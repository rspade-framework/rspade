# rsx/app/frontend/clients — the canonical CRUD feature

**This is the feature to copy.** `contacts/`, `projects/` and `tasks/` follow it and
document only their differences; the app skill `crud-patterns` is the how-to depth.

## WHAT IS HERE

```
clients/
├── clients_controller.php      Frontend_Clients_Controller - Ajax endpoints ONLY
├── list/                       Clients_Index_Action + clients_datagrid.{php,js,jqhtml}
├── view/                       Clients_View_Action  (tabs, sidebar, KPIs)
│   │                           + its five REGION components, flat in the same
│   │                           directory (see "The view page is decomposed")
│   ├── clients_view_tab_overview.{jqhtml,scss}
│   ├── clients_view_tab_contacts.jqhtml
│   ├── clients_view_tab_projects.jqhtml
│   ├── clients_view_tab_activity.jqhtml
│   └── clients_view_sidebar.{jqhtml,js}
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

**The view page is decomposed into REGIONS**, and it is the worked example of that shape.
`Clients_View_Action.jqhtml` is a shell that reads as a table of contents: one named line
per visible seam, with the region components sitting flat beside it in `view/` (snake_case
file, PascalCase `<Define>`, `_tab_<name>` for a tab body). The rules it demonstrates:

- **Markup and its handlers move together.** `Clients_View_Sidebar` carries its own
  `.js` with the enable/disable-portal, delete and restore handlers, because a split that
  left the shell reaching into the region by `$sid()` would not be a seam at all. When a
  region MUTATES the record it fires a component event (`client_changed`) and the shell
  decides to `reload()` — the one fetch of the record stays in one place.
- **Data is loaded EAGER by the action, in one parallel batch, and passed down.** Every
  tab body takes its payload as an arg, because the tab bar and the sidebar KPIs render
  all four counts before any tab is opened. Only `Clients_Portal_Panel` self-loads: it is
  genuinely LAZY (the tab exists only for a portal-enabled client and may never be opened)
  and owns its own realtime posture.
- **A region gets a `.js` or `.scss` only where behaviour or style exists.** Four of the
  five have neither. `clients_view_tab_overview.scss` exists for one reason: that region
  now stands between `Tab_Panel` and the Sections it stacks, so it inherits `Tab_Panel`'s
  container role for them (R1 — the container owns the gaps, same `--block-gap` token, one
  level down). Its single-Section siblings need no rule.
- **A cross-region interaction stays on the shell.** The `.Kpi_Cell--clickable` delegate
  lives in the action because the cells are in the sidebar and the `Tab_Bar` they drive is
  in the main column.

Total lines went UP (~348 -> ~390 across six files); the win is the shell, not the count.

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
- **Decompose a view template that passes ~325 lines** into regions the way `view/` does
  above. A region is a VISIBLE seam of that page, not a reusable widget: it lives beside
  its page, it is named after its page, and it never migrates to `rsx/theme/`. If two pages
  want the same region, that is the signal it was vocabulary all along — promote it to
  `rsx/theme/components/` instead.
- **A parallel `on_load()` is the default.** Independent calls go under one `Promise.all`;
  every NON-FATAL branch carries its own `.catch(() => <sane default>)` while the record
  the page IS about stays uncaught. Sequence only when the second call's ARGUMENTS come
  from the first call's result — say so in a comment when it is not obvious.
- **Bind delegated handlers namespaced and idempotent** — one `this.$.off('.ns')` at the
  top of `on_ready()`, then `this.$.on('click.ns', ...)`. Never a `if (!this._wired)`
  flag: the flag dies with the instance, the handler lives on the element.
- **Content you hand to a child still resolves against this template** (a `<Slot:>` body
  included) - expressions, `$sid` ids and `@click=this.method` alike, so handlers are
  written directly where the markup is.

## RELATED

`../CLAUDE.md` · `../contacts/CLAUDE.md`, `../projects/CLAUDE.md`, `../tasks/CLAUDE.md`
(what each does differently) · `rsx/theme/components/datagrid/CLAUDE.md` ·
`rsx/theme/components/feedback/CLAUDE.md` (the three-state bodies) ·
app skills `crud-patterns`, `form-components`, `modals`, `semantic-components` ·
skills `rspade:spa`, `rspade:model-fetch`, `rspade:ajax-error-handling`, `rspade:realtime` ·
`rsx:man crud`, `rsx:man spa`, `rsx:man view_action_patterns`
