---
name: spa
description: Building SPA modules in RSX - the #[SPA] bootstrap controller, @route/@layout/@spa/@auth/@title action decorators, Spa_Layout and on_action(), sublayouts, Spa.dispatch/Spa.redirect navigation, route and query parameters, and the module/feature/submodule file organization. Use when adding a screen to an authenticated area, creating a new SPA module or layout, wiring client-side navigation or redirects, reading route params in an action, deciding SPA vs Blade, or debugging a 404 on an SPA route.
---

# SPA Routing

Client-side routing for authenticated application areas: **ONE PHP bootstrap controller per module**, and MANY JavaScript actions that navigate without page reloads.

**Use SPA for**: authenticated areas, dashboards, admin panels.
**Avoid for**: public pages needing SEO, simple static pages (use Blade - see the `blade-views` skill).

## 1. The bootstrap controller (one per module)

```php
class Frontend_Spa_Controller extends Rsx_Controller_Abstract
{
    #[SPA]
    #[Auth('is_logged_in')]   // MANDATORY; pre_dispatch does NOT do auth
    public static function index(Request $request, array $params = [])
    {
        return rsx_view(SPA);
    }
}
```

One `#[SPA]` per module, at `rsx/app/(module)/(module)_spa_controller::index`. **This segregates code by permission level** - a module's bundle only reaches users who can pass its gate, so a staff-only module's JS never ships to a lesser-privileged session.

Feature controllers inside an SPA module carry **Ajax endpoints only** - no page rendering.

## 2. Actions (many)

```javascript
@route('/contacts')
@layout('Frontend_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')   // MANDATORY on every @route action; build fails without it
@title('Contacts')      // the whole title for a fixed-title page (see Page titles)
class Contacts_Index_Action extends Spa_Action {
    async on_load() {
        this.data.contacts = await Frontend_Contacts_Controller.datagrid_fetch();
    }
}
```

An action is a jqhtml component with a route: same lifecycle, same `this.args`/`this.data`/`this.state` rules (see the `jqhtml` skill). `@route` may be repeated - that is how one action serves both add and edit.

Every `@auth` name resolves in the staff (or portal) check registry; see the auth-gates fragment.

## 3. Layout

```javascript
class Frontend_Layout extends Spa_Layout {
    on_action(url, action_name, args) {
        // Called after the action is created, before its on_ready().
        // this.action is accessible immediately.
        this.update_navigation(url);
    }
}
```

The layout template **must have a `$sid="content"` element** - that is where actions render. The layout instance PERSISTS across navigation within its chain, so anything expensive it builds (nav, sidebar, socket) survives navigation and must not be rebuilt per action.

## 4. Sublayouts

Nested persistent UI (a settings sidebar) is another `Spa_Layout`. Stack `@layout` decorators, **outermost first**:

```javascript
@route('/settings/general')
@layout('Frontend_Layout')       // outer
@layout('Settings_Layout')       // nested - has its own $sid="content"
@spa('Frontend_Spa_Controller::index')
@auth('can_manage_site_settings')
class Settings_General_Action extends Spa_Action { }
```

Each layout in the chain needs its own `$sid="content"`. **Layouts persist when unchanged; only the differing parts of the chain are recreated** - navigating `/settings/general` -> `/settings/users` keeps both layouts and swaps only the action. All layouts in the chain receive `on_action(url, action_name, args)` with the final action's info.

`Spa.layout` is the TOP layout instance; `Spa.action()` is the bottom (current action).

## Navigation

```javascript
Spa.dispatch('/contacts/123');   // navigate - pushes a history entry
Spa.redirect('/internal');       // same, but REPLACES the history entry
Spa.layout                       // current (top) layout instance
Spa.action()                     // current action instance
```

`Spa.dispatch()` routes client-side when the path belongs to the current SPA, and falls back to a full page load when it does not. **`Spa.redirect()` is the one to use for a programmatic redirect inside `on_load()`** - replacing the history entry means the browser Back button does not bounce the user straight back into the redirecting action.

**Link interception**: the SPA only intercepts clicks on links whose href matches a known SPA route. Links to server-side controllers, unknown paths, or external URLs pass through as normal full-page navigations. **No special attributes are needed to bypass SPA routing** - do not invent a `data-no-spa`.

URLs are always generated with `Rsx::Route()` / `Rsx.Route()`, never hardcoded:

```php
Rsx::Route('Contacts_Index_Action')       // /contacts
Rsx::Route('Contacts_View_Action', 123)   // /contacts/123
```

## URL parameters

Route params and query params both land in `this.args`:

```javascript
// URL: /contacts/123?tab=history
@route('/contacts/:id')
@layout('Frontend_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Contacts_View_Action extends Spa_Action {
    on_create() {
        console.log(this.args.id);    // "123"     (route param - always a STRING)
        console.log(this.args.tab);   // "history" (query param)
    }
}
```

Route params arrive as strings. A dual-route add/edit action distinguishes its modes with `!!this.args.id`.

## File organization

Pattern: `/rsx/app/(module)/(feature)/`

- **Module** - major functionality (login, frontend, root). One SPA bootstrap, one bundle, one layout.
- **Feature** - a screen within a module (contacts, reports, invoices).
- **Submodule** - a grouping of features (settings), usually with its own sublayout.

```
/rsx/app/frontend/                          # Module
├── Frontend_Spa_Controller.php             # Single SPA bootstrap
├── Frontend_Layout.js
├── Frontend_Layout.jqhtml
└── contacts/                               # Feature
    ├── frontend_contacts_controller.php        # Ajax endpoints only
    ├── Contacts_Index_Action.js                # /contacts
    ├── Contacts_Index_Action.jqhtml
    ├── Contacts_View_Action.js                 # /contacts/:id
    └── Contacts_View_Action.jqhtml
```

Scaffolding commands: `rsx:app:module:create <name>`, `rsx:app:module:feature:create <module> <feature>` (both scaffold SPA by default - bootstrap, layout pair, gated Action + Ajax controller; `--blade` gives the server-rendered ladder instead), `rsx:app:submodule:create <module> <submodule>`, `rsx:app:component:create`.

## View action pattern (data-loading screens)

An action that loads a record uses the three-state pattern - loading, error, content:

```javascript
on_create() {
    this.data.record = { name: '' };   // stub so the template can render safely
    this.data.error_data = null;
    this.data.loading = true;
}
async on_load() {
    try { this.data.record = await Controller.get({id: this.args.id}); }
    catch (e) { this.data.error_data = e; }
    this.data.loading = false;
}
```

Template order: `<Loading_Spinner>` -> `<Universal_Error_Page_Component>` -> content.

**Skip the three-state pattern for**: DataGrid pages (the grid loads itself), static pages, and redirect-only actions. Full treatment: `php artisan rsx:man view_action_patterns`; CRUD module shape: the `crud-patterns` skill.

## Page titles

One ladder, two rungs. **Declare the title once and let the layout paint it.**

**Fixed title - `@title` alone, no override:**

```javascript
@route('/contacts')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
@title('Contacts')
class Contacts_Index_Action extends Spa_Action { }
```

`Spa_Action.page_title()` returns the decorator value (`this.constructor._spa_title`), and
`get_static_title()` hands the SAME value to the layout **synchronously at dispatch** - the
title is on screen before any await. With neither a decorator nor an override the title is
the loud placeholder `'(title not set)'`.

**Data-dependent title - override, and await the load FIRST:**

```javascript
async page_title() {
    await this.await_loaded();                 // FIRST statement - see below
    return `${this.data.contact.first_name} ${this.data.contact.last_name}`.trim();
}
```

`await_loaded()` resolves once `on_load()` has completed. It is needed because **title and
breadcrumb methods are called at dispatch time, before the action finishes loading** - the
layout does not wait for the action. The underlying `'load'` lifecycle event is sticky (a
late registration fires immediately), so calling it any number of times is free.

**`get_static_title()` returns null the moment `page_title()` is overridden**, even when the
class carries a `@title`. An override declares the title is dynamic, so the decorator string
is treated as generic route metadata (`@title('User Details')` on a view action) and is never
painted - a wrong title flashing and then correcting itself is worse than a brief blank.

**Painting is the LAYOUT's job, through one framework method:**

```javascript
async on_action(url, action_name, args) {
    await this.resolve_page_title((title) => { document.title = title; });
}
```

`Spa_Layout.resolve_page_title(paint, placeholder = null)` paints the static title
synchronously when there is one, otherwise the caller's `placeholder` (a layout's own
cached title, if it keeps one), then repaints with the awaited `page_title()` when it differs, and
returns that live title. **Never call `action.page_title()` from a layout directly** - that
throws away the zero-latency rung.

`breadcrumb_label()` defaults to `page_title()`, so a fixed-title action gets its breadcrumb
from `@title` too; a dynamic breadcrumb override awaits `await_loaded()` exactly like the
title does.

## Troubleshooting

- **A route 404s** - the route does not exist. The SPA dispatches by matching the URL against registered `@route` decorators; an unmatched path is not an SPA route at all, so the click falls through to a normal navigation and the server 404s. Check the decorator's spelling and that the file is inside the module's bundle.
- **A link reloads the whole page instead of navigating** - the href does not match a known SPA route (same cause as above), or it points at a different SPA module. Cross-module navigation is a real page load by design.
- **Layout state resets on every navigation** - the layout chain changed. Two actions only share a layout instance when their `@layout` chains are identical up to that point.
- **`this.args.id` is undefined** - the `@route` has no `:id` token, or the action was reached through a different route in a multi-`@route` action.

Details: `php artisan rsx:man spa`.
