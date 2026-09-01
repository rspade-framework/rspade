# rsx/app/frontend/action_logs — browsing the action log

## WHAT IS HERE

A read-only feature over `Action_Log_Model`: a datagrid of every recorded entry and a
detail page for one of them. There is no create, edit or delete — the log is append-only,
written by `Action_Log::record()` from the feature controllers, never from here.
`action_logs_controller.php` exposes `datagrid_fetch` and nothing else.

This is the BROWSE surface. The per-record activity tabs on the entity view pages are a
different presentation of the same data, mounted as `Feed_Row` — see
`rsx/lib/action_log/CLAUDE.md`.

## Directory Structure

```
action_logs/
├── action_logs_controller.php    # Ajax endpoint (datagrid_fetch only)
├── CLAUDE.md                     # This file
├── list/
│   ├── Action_Logs_Index_Action.js
│   ├── Action_Logs_Index_Action.jqhtml
│   ├── action_logs_datagrid.php
│   └── action_logs_datagrid.jqhtml
└── view/
    ├── Action_Logs_View_Action.js
    └── Action_Logs_View_Action.jqhtml
```

## Routes

- `/action-logs` - List view (DataGrid)
- `/action-logs/view/:id` - Detail view

## Related Files

- `/rsx/models/action_log_model.php` - Main model with type enum
- `/rsx/models/action_log_related_model.php` - Related entities model
- `/rsx/lib/action_log/action_log.php` - Helper class for recording
- `/rsx/lib/action_log/action_log_renderer.php` - Render functions

## Documentation

See `/rsx/resource/man/action_log.txt` for full documentation.

## HOW TO CUSTOMIZE

- **Add a column or filter**: `list/action_logs_datagrid.php` (`$sortable_columns` and
  `build_query()`) plus the matching `<th data-sortby>` in the template — sortability is
  opt-in in both places or it silently falls back to the default.
- **Change what an entry SAYS**: that is the renderer in `rsx/lib/action_log/`, not this
  feature. This feature only displays what the renderer returns.
- **Gate it** if the log should not be readable by everyone: the natural check is
  `can_view_user_activity`, already defined in `rsx/permission.php`.
- Delete the whole feature with the rest of the action-log subsystem if the application
  does not need a narrative history.
