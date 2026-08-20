---
name: blade-views
description: Creating Blade views and server-rendered pages in RSX. Use when building SEO pages, login/auth pages, server-rendered content, working with @rsx_id, @rsx_page_data, or Blade-specific patterns.
---

# RSX Blade Views

**Note**: SPA pages are the preferred standard. Use Blade only for:
- SEO-critical public pages
- Authentication flows (login, password reset)
- Marketing/landing pages

## Basic View Structure

Every Blade view starts with `@rsx_id`:

```blade
@rsx_id('Frontend_Index')

<body class="{{ rsx_body_class() }}">
    @section('content')
        <h1>Welcome</h1>
    @endsection
</body>
```

- `@rsx_id('View_Name')` - Required first line, identifies the view
- `rsx_body_class()` - a plain helper interpolated into the body tag (`{{ rsx_body_class() }}`), NOT a directive; it adds the view class used for CSS scoping. The layout usually carries it; a validator enforces its presence.

## View Rules

- **NO inline styles** - Use companion `.scss` files
- **NO inline scripts** - Use companion `.js` files
- **NO inline event handlers** - Use `on_app_ready()` pattern
- **jqhtml components** work fully in Blade (but no slots)

---

## JavaScript for Blade Pages

Unlike SPA actions (which use component lifecycle), Blade pages use static `on_app_ready()`:

```javascript
class My_Page {
    static on_app_ready() {
        // Guard required - fires for ALL pages in bundle
        if (!$('.My_Page').exists()) return;

        // Page initialization code
        $('.My_Page .btn').on('click', () => {
            // Handle click
        });
    }
}
```

The guard (`if (!$('.My_Page').exists()) return;`) is essential because `on_app_ready()` fires for every page in the bundle.

---

## Passing Data to JavaScript

Use `@rsx_page_data` for data needed by JavaScript:

```blade
@rsx_page_data(['user_id' => $user->id, 'mode' => 'edit', 'config' => $config])

@section('content')
    <div class="Editor">...</div>
@endsection
```

Access in JavaScript:

```javascript
class Editor {
    static on_app_ready() {
        if (!$('.Editor').exists()) return;

        const user_id = window.rsxapp.page_data.user_id;
        const mode = window.rsxapp.page_data.mode;
        const config = window.rsxapp.page_data.config;
    }
}
```

- Use when data doesn't belong in DOM attributes
- Multiple calls merge together
- Available after page load in `window.rsxapp.page_data`

---

## Using jqhtml Components in Blade

jqhtml components work in Blade templates:

```blade
<Dashboard>
    <Stats_Panel $user_id="{{ $user->id }}" />
    <Recent_Activity $limit="10" />
</Dashboard>
```

**Note**: Blade slots (`@slot`) don't work with jqhtml components. Use component attributes instead.

---

## Controller Pattern for Blade Routes

Use standard #[Route] controllers with Blade templates:

```php
class Login_Controller extends Rsx_Controller_Abstract {
    #[Route('/login', methods: ['GET', 'POST'])]
    #[Auth('public')]   // MANDATORY on every surface; 'public' is the explicit open marker
    public static function index(Request $request, array $params = []) {
        if ($request->is_post()) {
            // Handle form submission
        }
        return rsx_view('Login_Index', ['data' => $data]);
    }
}
```

**Key points**:
- **`#[Auth]` is MANDATORY on every dispatchable surface** - routes are CLOSED BY DEFAULT and the manifest build FAILS without a gate. There is no attribute-free spelling of "open": a public page declares `#[Auth('public')]`. (The old `@auth-exempt` docblock is dead syntax.) `pre_dispatch()` performs NO authorization anywhere. See the auth-gates fragment / `php artisan rsx:man auth_gates`.
- A class-level `#[Auth]` covers every surface in the class; a method-level one is ADDITIVE (gates only narrow).
- GET/POST in same method (`$request->is_post()`); only GET and POST exist
- Returns `rsx_view('Template_Name', $data)`
- jqhtml works but is not server-rendered, so it does nothing for SEO

---

## Converting Blade to SPA

When converting server-rendered Blade pages to SPA actions:

```bash
php artisan rsx:man blade_to_spa
```

The process involves:
1. Creating Action classes with `@route` decorators
2. Converting templates from Blade to jqhtml syntax
3. Moving data loading from controller to `on_load()`

## More Information

Route rules and controller conventions: `php artisan rsx:man routing`. Component syntax used inside Blade templates: `php artisan rsx:man jqhtml` (and the `jqhtml` skill). Bundle wiring for a Blade page's JS/CSS: the `bundles` skill.
