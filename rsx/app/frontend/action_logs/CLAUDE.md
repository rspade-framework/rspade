# Action Logs Module

View-only CRUD module for displaying user action history.

## Features

- DataGrid with action log entries
- Detail view page for individual actions
- No create/edit/delete functionality (append-only)

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
