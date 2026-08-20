# RSpade Spa System

## Overview

The RSpade Spa system enables client-side routing for authenticated areas of applications using the JQHTML component framework.

## Core Classes

### Spa_Action
Base class for Spa pages/routes. Each action represents a distinct page in the application.

**Example:**
```javascript
class Users_View_Action extends Spa_Action {
    static route = '/users/:user_id';
    static layout = 'Frontend_Layout';

    on_create() {
        console.log(this.args.user_id); // URL parameter from route
    }
}
```

### Spa_Layout
Persistent wrapper component containing navigation, header, footer, etc. Layouts have a content area where actions render.

**Example:**
```javascript
class Frontend_Layout extends Spa_Layout {
    on_create() {
        // Initialize layout structure
        // Create navigation, header, footer
        // Define content area for actions
    }
}
```

### Spa
Main Spa application class that initializes the router and manages navigation between actions.

### Spa_Router
Core routing engine adapted from JQHTML framework. Handles URL matching, parameter extraction, and navigation.

## Discovery

Spa actions are discovered during manifest build using:
```php
Manifest::is_js_subclass_of('Spa_Action')
```

No filename conventions required - any class extending `Spa_Action` is automatically registered.

## URL Generation

**PHP:**
```php
$url = Rsx::SpaRoute('Users_View_Action', ['user_id' => 123]);
// Returns: "/users/123"
```

**JavaScript:**
```javascript
const url = Rsx.SpaRoute('Users_View_Action', {user_id: 123, tab: 'posts'});
// Returns: "/users/123?tab=posts"
```

## Architecture

### Bootstrap Flow

1. User requests Spa route (e.g., `/users/123`)
2. Dispatcher detects Spa route in manifest
3. Server renders `spa_bootstrap.blade.php` with bundle
4. Client initializes Spa application
5. Router matches URL to action
6. Layout renders with action inside

### Navigation Flow

1. User clicks link or calls `Rsx.SpaRoute()`
2. Router matches URL to action class
3. Current action destroyed (if exists)
4. New action instantiated with URL parameters
5. Action renders within persistent layout
6. Browser history updated

## Implementation Status

**Phase 1: Foundation Setup** - ✅ COMPLETE
- Directory structure created
- Placeholder files in place
- Router code copied from JQHTML export

**Phase 2+:** See `/var/www/html/docs.dev/Spa_INTEGRATION_PLAN.md`

## Directory Structure

```
/system/app/RSpade/Core/Jqhtml_Spa/
├── CLAUDE.md                        # This file
├── Spa_Bootstrap.php                # Server-side bootstrap handler
├── Spa_Parser.php                   # Parse JS files for action metadata
├── Spa_Manifest.php                 # Manifest integration helper
├── spa_bootstrap.blade.php          # Bootstrap blade layout
├── Spa_Router.js                 # Core router (from JQHTML)
├── Spa.js                           # Spa application base class
├── Spa_Layout.js                    # Layout base class
└── Spa_Action.js                    # Action base class
```

## Notes

- Spa routes are auth-only (no SEO concerns)
- Server does not render Spa pages (client-side only)
- Actions live alongside PHP controllers in feature directories
- Controllers provide Ajax endpoints, actions provide UI
- Layouts persist across action navigation (no re-render)
- Component renamed from `Component` to `Component` (legacy alias maintained)
