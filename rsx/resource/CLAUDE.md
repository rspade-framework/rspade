@../../system/app/RSpade/docs/claude/app.md

# This application

This is **your** CLAUDE.md - the place to record the facts and conventions of the
custom RSX application you are building.

The import on line 1 pulls in the framework's own body of knowledge - how RSpade
works, what its APIs are, what is forbidden - and the `rspade:*` skills carry the
task-level depth. That knowledge is already present in every session, so **it is
never restated here.** This file is for what the framework cannot know: your
business logic, your UI conventions, your custom scripts and tooling, and any
other convention of this project.

It arrives pre-seeded with the layout of the starter application, the widget
vocabulary it ships with, and how its datagrid works. Everything below is a
starting point - delete what you remove, rewrite what you change.

**Skills.** Task-triggered how-to depth is authored as a skill at
`rsx/resource/skills/<name>/SKILL.md`; the framework links it into `.claude/skills/`
automatically (see `rsx/resource/skills/CLAUDE.md`). **The routing rule:** always-on
facts here, task-triggered how-to depth in a skill, contracts in `rsx/resource/man/`.
This application ships seven of its own: `crud-patterns`, `form-components`, `modals`,
`semantic-components`, `theme`, `portal-app`, `action-log-and-notifications`.

**KEEP THIS FILE CURRENT.** It is only worth reading if it is true. **LLM: whenever
an implemented change alters a convention recorded here, or is complex enough that
the next session would want it written down, SUGGEST an edit to this file** - name
the section and the wording, and let the developer decide.

The same obligation runs the other way through the tree: every `CLAUDE.md` and every
skill under `rsx/` is LIVING DOCUMENTATION of the directory it sits in, updated in
the same pass as the change that dated it - see the framework's LIVING DOCUMENTATION
rule (`rsx:man template_app`, LIVING DOCUMENTATION, for the full statement).

---

## APPLICATION LAYOUT

A multi-tenant B2B SaaS starter: sites, users with roles and per-user ACLs,
clients, contacts, projects, tasks, parties (person/company detail tables),
announcements, notifications, an action log, and a client portal.

**Staff modules** - `rsx/app/`, one directory per module, each with its own bundle:

| Module | What it is |
|---|---|
| `frontend/` | The main authenticated SPA. `Frontend_Spa_Controller` + `Frontend_Spa_Layout` (persistent chrome, primary nav), then one directory per feature: `dashboard`, `clients`, `contacts`, `projects`, `tasks`, `party`, `action_logs`, `notifications`, `calendar`, `reports`, `settings`, `system`. |
| `backend/` | Minimal server-rendered admin shell (Blade). |
| `login/` | Server-rendered auth flows: login, signup, invite acceptance, site selection, site-unauthorized. |
| `api/` | External bearer REST surface, `v1/` (contacts, clients). Every path starts `/api/vN/`. |
| `apidocs/` | The live API documentation and tester SPA. |
| `dev/` | Developer showcase (components, modals, ORM, SPA, attachments, ACL) - shipped CLOSED (`#[Auth('closed')]`), reachable by nobody. Read it as a worked reference; open it by declaring a check of your own. |
| `root/` | Cross-site root console: sites, dashboard, email. |
| `ssr_test/` | Server-render harness pages. |

**Client portal** - `rsx/portal/`: the parallel external experience.
`Portal_Spa_Controller` + `Portal_Layout`, then `auth/` (Blade login, register,
password reset), `dashboard/`, `workspaces/` (overview, documents, request
threads), `invitations/`, `notifications/`, `settings/`.

**Models** - `rsx/models/`, flat, one file per model:

- CRM: `Client_Model`, `Client_Department_Model`, `Contact_Model`, `Project_Model`,
  `Project_Contact_Model`, `Project_User_Model`, `Task_Model`.
- Party (class-table inheritance): `Party_Model` + `Party_Person_Detail_Model` /
  `Party_Company_Detail_Model`.
- Portal: `Portal_Membership_Model`, `Portal_Invitation_Model`,
  `Portal_Password_Reset_Model`, `Portal_Project_Model`, `Shared_Item_Model`, and the
  request-thread set (`Portal_Request_Thread_Model`, `..._Message_Model`,
  `..._Document_Model`, `..._Event_Model`).
- Activity: `Action_Log_Model`, `Action_Log_Related_Model`, `Notification_Model`,
  `Announcement_Model`.
- Misc: `User_Group_Model`, `Demo_Product_Model`.

**Everything else:**

| Path | Contents |
|---|---|
| `rsx/theme/` | The component library (`components/`), SCSS variables, composition tokens, badges, responsive mixins, Bootstrap overrides. Each component group carries its own `CLAUDE.md`. |
| `rsx/lib/` | App utilities: `action_log/`, `notification/`, `modal/`, `topics/`, `analytics/`, `formatters.{php,js}`. |
| `rsx/services/` | `Rsx_Service_Abstract` background work: `portal_invitation_service.php`, `seeder_service.php`. A `#[Task]` here becomes an artisan command by adding `#[Command]` - see `rsx_app:seed`. |
| `rsx/handlers/` | Event handlers: `File_Upload_Handlers` (the mandatory upload gate), `Portal_File_Access_Handlers`. |
| `rsx/emails/` | Email classes (`X_Email extends Rsx_Email_Abstract`) beside their blade templates and `email.scss`. |
| `rsx/tests/` | The application test suite (`php artisan rsx:test`). |
| `rsx/resource/` | Framework-ignored. `config/` (config overrides), `man/` (project man pages), `docs/`, `skills/`, `audits/prelaunch_checklist.md`, `conventions/`, `migrations/` (app-owned migrations). |

---

## THE FOUR HOOKS

Four files sit at the root of `rsx/`. They are the application's entry points into the
framework's request lifecycle and its authorization vocabulary - small files, edited
rarely, and load-bearing every time.

**`main.php`** (`Main extends Main_Abstract`) - the STAFF request lifecycle. `init()`
runs once per process, before anything asks a question of the session, and is where
this application DECLARES ITS SITE (`Session::set_site_id(1)` - mono-site; a
multi-tenant app resolves the site from the host or the signed-in user here instead).
`pre_dispatch()` runs before every staff route and is where cross-cutting request work
lives (this app checks that the signed-in identity actually belongs to the current
site and bounces it to `Site_Unauthorized_Controller` otherwise, and bounces one an
administrator flagged `users.is_2fa_required` with no second factor enrolled to the
forced-enrollment interstitial); `unhandled_route()`
is the 404 hook. Edit `init()` when tenancy changes; edit `pre_dispatch()` for an
interstitial, a redirect or per-request setup.

**`permission.php`** (`Permission extends Permission_Abstract`) - the STAFF gate
vocabulary. Every `#[Auth_Check]` method here is a name that `#[Auth('...')]` /
`@auth('...')` may use, and this file is the LIST OF RECORD for them (the AUTH CHECKS
table below mirrors it). Edit it whenever a gate is added, renamed or removed - a
one-line body over `has_permission()` / `has_role()`, never a role id or permission
constant spelled at a call site.

**`portal_main.php`** (`Portal_Main extends Portal_Main_Abstract`) - the PORTAL
request lifecycle, the exact mirror of `main.php` for `rsx/portal/`. Its `init()` is
the earliest application code in a portal request, which is why the portal's own site
declaration lives there (`Portal_Session::set_site_id(...)`); the framework resolves
no portal site and `get_site_id()` throws if nothing declared one. Its
`pre_dispatch()` stamps portal activity on the member clients. Edit it when portal
tenancy changes or the portal needs per-request work.

**`portal_permission.php`** (`Portal_Permission extends Portal_Permission_Abstract`) -
the PORTAL authorization facade. It defines no `#[Auth_Check]` of its own: portal
surfaces gate on the framework's `public` / `is_logged_in` in the portal realm, and
every per-client rule is a RECORD-level predicate called inline in the endpoint body
after the gates pass (`has_client_access()`, `can_collaborate()`, `client_role()`,
`accessible_client_ids()`, `is_read_only()`). Edit it when the membership model
changes or a new record-level predicate is needed.

**The rule that governs both `pre_dispatch()` hooks: they perform NO authorization.**
The declarative `#[Auth]` gates are evaluated by the dispatcher BEFORE the hook runs,
so a check written here is both too late and invisible to `can_access()`. `pre_dispatch()`
is for other middleware concerns - tenant setup, interstitials, redirects. See
`rsx:man auth_gates` and `rsx:man portal`.

---

## AVAILABLE WIDGETS

The app's own semantic component vocabulary, all in `rsx/theme/components/`. The
living index is **`rsx/resource/conventions/semantic_component_registry.md`** - read
it for each component's arguments, gotchas and "used on" evidence, and update it as
part of every UI change. Run **`php artisan rsx:jqhtml:glossary --missing`** before
building any new UI element.

**Page scaffold** - `Page_Scaffold` (view-page shell: `<Slot:main>` + optional
`<Slot:sidebar>`, `$ratio`), `Page`, `Page_Header`, `Page_Header_Left`,
`Page_Header_Right`, `Page_Title`, `Page_Subtitle`, `Page_Section`, `Breadcrumb_Nav`,
`Breadcrumb`, `Breadcrumb_Item`.

**Section / card chrome** - `Section` (the workhorse: icon/title/count header +
padded body, `$flush`), `View_Section_Abstract` (chrome base, re-parented with
`extends=`), `Card_Widget` (headerless page card), `Detail_Sidebar` (entity sidebar
stack), `Section_Columns` (nested 2:1 split), and the older `Card` / `Card_Header` /
`Card_Header_Right` / `Card_Title` / `Card_Footer` set the datagrid still uses.

**Content vocabulary** - one component per data shape:

| Component | Shape |
|---|---|
| `Entity_Header` | Entity identity header: title + chips + subtitle + meta row. |
| `Entity_Link` | A reference to another record: type icon + name + route. |
| `Status_Badge` | One filled status pill read from a model enum. |
| `Count_Pill` | Tiny neutral count pill (section headers, tabs). |
| `View_Fields` / `View_Field` | Label/value fact grid. |
| `Sidebar_Kpi_Group` / `Kpi_Cell` | "At a glance" telemetry cells (sidebar); `Stat_Group` is the dashboard strip of the same cell. |
| `Stat_Row` | One money/numeric `label: value` line (monospaced, right-aligned). |
| `Record_Table` | Compact record list; `<tr data-href>` gives whole-row navigation. |
| `Feed_Row` | One activity/timeline event line: icon tile + summary + relative time. |
| `Revision_History` | The change timeline of one record (`$record_type` + `$record_id`), grouped by transaction with the field diffs under each. |
| `Author_Meta_Row` | The shared "who + when" byline (avatar + author + time). |
| `Person_Avatar` | A profile image or a deterministic initials disc. |
| `People_List` | A calm vertical list of people; fires `person_click` / `person_remove`. |
| `Widget_Grid` | 2-up grid of independent widget cards (overview pages). |
| `Empty_State` | Empty LIST region (icon + title + body + CTA). |
| `Empty_Value` | The one muted em-dash for a single empty cell. |
| `Callout` | Inline alert banner (`danger` / `warning`). |
| `Placeholder_Card` | Coming-soon panel standing in for an unbuilt feature. |
| `Action_Menu` | Overflow "..." dropdown - destructive actions live inside it, never as a peer red button. |
| `External_Link`, `Textbox_Click_To_Copy` | Outbound link; click-to-copy field. |

**Tabs** - `Tab_Bar` + `Tab_Panels` / `Tab_Panel` for view pages (URL-hash
persistence, `tab_change` event). `Rsx_Tabs` / `Rsx_Tab` are the separate FORM tab
components (validation-error integration) - do not mix the two.

**Data** - `DataGrid_Abstract`, `DataGrid_Body`, `Pagination_Controls`,
`Pagination_Info` (see below).

**Forms and inputs** - `Rsx_Form` + `Form_Errors` (framework core; every form places
exactly one `<Form_Errors />` where its layout wants the failure feedback),
`Form_Field`, and the inputs: `Text_Input`, `Select_Input`, `Select_Ajax_Input`,
`Select_Country_Input`, `Select_State_Input`, `Select_With_Description_Input`,
`Checkbox_Input`, `Checkbox_Multiselect_Input`,
`Hidden_Input`, `Profile_Photo_Input`, `Repeater_Simple_Input`, `Tag_List_Input`,
`Pin_Input`, `Wysiwyg_Input`.

**Navigation and chrome** - `Sidebar_Nav`, `Search_Bar`, `Search_Input`,
`Search_Button`, `Notification_Dropdown`, `Realtime_Status_Badge`,
`Loading_Spinner` (a page-level "loading..." block - the framework's own
`<Spinner />` renders whatever `Rsx.set_default_spinner()` registered, and is
what the form loading overlay hosts), and the `*_Error_Page_Component` family
(rendered by the framework's error screens - not composed by hand).

**The success test for a page: its own SCSS file is near-empty.** If you are
copy-pasting markup or writing page-level CSS, extract or reuse a component instead.

---

## HOW THE DATAGRID WORKS

**The datagrid is APPLICATION code, not framework code** - it lives in
`rsx/theme/components/datagrid/` and is yours to change. Worked example:
`rsx/app/frontend/clients/list/` (`clients_datagrid.php`, `clients_datagrid.jqhtml`,
`Clients_Index_Action.jqhtml`) plus `Frontend_Clients_Controller::datagrid_fetch`.

A grid is three pieces:

1. **A PHP class extending `DataGrid_Abstract`** (`Rsx\Theme\Components\Datagrid`).
   It declares `$sortable_columns` (a whitelist - an unlisted sort falls back to
   `$default_sort`), optionally `$default_sort` / `$default_order` /
   `$default_per_page` (15) / `$max_per_page` (100), and implements
   `build_query(array $params): Builder` - the whole query, including the
   `$params['filter']` search. Optional overrides: `map_sort_column()` (frontend
   column name -> DB column/expression, for joins and computed fields) and
   `transform_records()` (post-SQL computed fields). `fetch()` does the rest:
   validates params, clones the query for the count, orders, offsets/limits, and
   returns `{records, page, per_page, total, total_pages, sort, order}`.
2. **An `#[Ajax_Endpoint]`** on the feature controller that is one line -
   `return Clients_DataGrid::fetch($params);` - carrying the mandatory `#[Auth]`.
3. **A jqhtml component with `extends="DataGrid_Abstract"`**, whose `$data_source`
   names that endpoint (`Frontend_Clients_Controller.datagrid_fetch`) and whose
   `$sort` / `$order` / `$per_page` seed the initial state. It fills three slots:
   `DG_Card_Header` (title, `Search_Input $sid="filter_input"`), `DG_Table_Header`
   (a `<tr>` of `<th>`; **`data-sortby="column"` is what makes a header sortable**),
   and `row` (the `<tr>` for one record, with `row.*` fields).

**A list page then mounts it and nothing else**: `<Page_Scaffold><Slot:main><Clients_DataGrid /></Slot:main></Page_Scaffold>`.

**Where the moving parts live.** `DataGrid_Abstract` (JS) keeps ALL mutable state in
`this.state` (page, per_page, sort, order, filter, total) and has no `on_load()`; the
child `DataGrid_Body` does the fetching in its own `on_load()` from the args the
parent passes down, so a state change re-args the child and calls
`body_component.reload()`. Sorting is wired by rewriting each `th[data-sortby]` into
a clickable header with an arrow; the search input is debounced into `state.filter`;
pagination is rendered by `Pagination_Controls` / `Pagination_Info` from
`state.total_pages`. `grid.reload()` is the public refresh from outside.

Gotchas seen in the code:

- **Sortability is opt-in twice**: a `<th data-sortby="x">` that is not also in the
  PHP `$sortable_columns` whitelist silently sorts by the default instead.
- **Row navigation is `<tr data-href="...">`** with `Rsx.Route(...)` - never a
  hand-built URL, and never a page-local click handler.
- The abstract's `Card_Footer` still carries a **stub "Actions" dropdown** (Export /
  Delete / Mark as Active) that is not wired to anything. Wire it or remove it.

---

## AUTH CHECKS

**KEEP CURRENT** - every `#[Auth_Check]` this app defines, name + what it checks.
Add a row whenever a gate is added, renamed or removed. These are the names that may
appear in `#[Auth('...')]` / `@auth('...')`.

**Staff realm** - `rsx/permission.php` (`Permission extends Permission_Abstract`):

| Check | Passes when |
|---|---|
| `can_manage_users` | `PERM_MANAGE_SITE_USERS` - accounts, roles, groups, invitations. |
| `can_manage_site_settings` | `PERM_MANAGE_SITE_SETTINGS` - site-wide configuration. |
| `can_manage_billing` | `PERM_MANAGE_SITE_BILLING`. |
| `can_view_user_activity` | `PERM_VIEW_USER_ACTIVITY` - other users' audit trail. |
| `can_edit_data` | `PERM_EDIT_DATA` - create/modify/delete records. |
| `can_view_data` | `PERM_VIEW_DATA` - read records. |
| `can_export_data` | `PERM_DATA_EXPORT` - downloads, report extracts. |
| `can_use_api` | `PERM_API_ACCESS`. Defined but deliberately NOT applied to the template's `#[Api_Endpoint]` surfaces (pre-existing keys would break); name it on your own endpoints. |
| `can_impersonate` | Role floor `ROLE_MANAGER` - may start "View as Client". |
| `is_root_admin` | Role floor `ROLE_ROOT_ADMIN` - the cross-site root console. |
| `closed` | Framework built-in: always false. Gates `rsx/app/dev/`, which ships unreachable. The counterpart to `public`. |

Plus the framework-supplied `public` and `is_logged_in`.

**Portal realm** - `rsx/portal_permission.php` defines **no `#[Auth_Check]` of its
own**: portal surfaces gate on the framework's `public` / `is_logged_in` (portal
realm), and every per-client rule is a RECORD-level predicate called inline in the
endpoint body after the gates pass - `Portal_Permission::has_client_access($client_id)`,
`can_collaborate($client_id)`, `client_role($client_id)`, `accessible_client_ids()`,
`is_read_only()` (true during staff impersonation; **every mutating portal endpoint
must guard on it**).

---

## UI CONVENTIONS

### Bootstrap card styling

**Avoid the `shadow` and `border-0` classes.** Apply only the base classes:

```html
<!-- GOOD -->
<div class="card card-body mb-4">
  <h2 class="h5 mb-4">Section Title</h2>
</div>

<!-- BAD -->
<div class="card card-body border-0 shadow mb-4">
  <h2 class="h5 mb-4">Section Title</h2>
</div>
```

Rationale: keep visual styling minimal and consistent. The default card styling is
sufficient - no extra shadow effects, no border manipulation.
