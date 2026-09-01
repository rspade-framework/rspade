# rsx/app/frontend/projects — CRUD, following the clients pattern

Follows `rsx/app/frontend/clients/CLAUDE.md` and the app skill `crud-patterns`: `list/` + `view/` + `edit/`, one controller of Ajax endpoints, one datagrid, a dual-`@route` add/edit action. **What differs here:**

- **No `history/`** — the only one of the four CRUD features with no revision-history action or History link. No portal subtree, no modals, no SCSS; no `delete`/`restore` (so no delete button and no `Action_Menu` in the sidebar); CSV export only.
- **The datagrid joins `clients`** (`map_sort_column()`: `client` -> `client_name`) and **boots filtered** — `static default_filters = { status: Project_Model.STATUS_ACTIVE }`.
- **A projects-only `subprojects` tab**: the model is self-referencing, and `save()` runs a cycle guard on `parent_project_id` through `Project_Model::self_and_descendant_ids()`.
- **Computed values in the view template** (open tasks, overdue tasks, an `$alert` KPI when any task is overdue) and **two `People_List` mounts** in Overview — the only consumer of that component.
- **Pivot-table save**: `save()` syncs `Project_Contact_Model` and `Project_User_Model` in a transaction on the rule "absent key = leave untouched, present-even-empty = replace"; the edit page loads its option lists over Ajax on both add and edit.
- **Borrows two inputs it does not own**: `Client_Selector_Input` (`../contacts/`) and `Ajax_Entity_Select_Input` (`../tasks/`). Also the only edit form using `Wysiwyg_Input` and `Checkbox_Multiselect_Input`.
