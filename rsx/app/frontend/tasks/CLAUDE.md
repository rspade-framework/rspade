# rsx/app/frontend/tasks — CRUD, following the clients pattern

Follows `rsx/app/frontend/clients/CLAUDE.md` and the app skill `crud-patterns`, with the most divergence of the three: `view/` + `edit/` + `history/`, one controller, one datagrid. **What differs here:**

- **`Tasks_Index_Action.{js,jqhtml}` sits at the feature root, not in `list/`** (which holds only the three datagrid files) — the one structural break in the convention.
- **No export and no bulk actions at all**: no `export_csv`, no `export_xlsx`, no `bulk_delete`; the grid has no footer-actions slot, no select-all column and no row checkboxes, and the page header has no Export button. It does have a single-record `delete`, inside an `Action_Menu` in the view sidebar, but no `restore`/`fetch_deleted`.
- **A composite quick-filter value**: the status select carries a literal `open` option beside the enum, expanded by the query builder into `whereIn(PENDING, IN_PROGRESS)`. The key is `status_filter`, not `status`.
- **The only datagrid with `transform_records()`** — it bucket-loads the polymorphic `taskable` parents into a flat `parent_type`/`parent_label` per row, and maps two sort columns across two joins.
- **A derived, read-only field on the edit form**: when the parent chain reaches a project the project picker is disabled, forced to the derived id and annotated. `save()` computes `project_id` from the chain (the chain wins over a submitted value), guards cycles, and cascades to descendants.
- **`edit/form/` owns two inputs**: `Parent_Selector_Input` (a composite `{type, id}` value) and `Ajax_Entity_Select_Input`, which `../projects/edit/` also consumes.
