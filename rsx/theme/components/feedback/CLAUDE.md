# rsx/theme/components/feedback — loading and error bodies

## WHAT IS HERE

- `loading_spinner.jqhtml` — `Loading_Spinner`: the centred spinner plus an optional
  `$message`, rendered while a page's `on_load()` is in flight.
- `errors/universal_error_page_component.jqhtml` — `Universal_Error_Page_Component`: the
  router. Given `$error_data` (the object from a `catch`), `$record_type`, `$back_label`
  and `$back_url` — all four required, it throws otherwise — it picks the component that
  matches the error's `Ajax.ERROR_*` code.
- `errors/` also holds the eight bodies it routes to, one per outcome:
  `not_found_error_page_component` (a record that does not exist; `$record_type`,
  `$back_label`, `$back_url`), `unauthorized_error_page_component` (logged in, gate
  denied; `$section`), `auth_required_error_page_component` (not logged in;
  `$redirect_url`), `validation_error_page_component` (`$message`, `$metadata` field
  errors), `server_error_page_component` (HTTP 500+),
  `network_error_page_component` (client could not reach the server),
  `php_exception_error_page_component` (message, file, line, expandable backtrace) and
  `generic_error_page_component` (the fallback for any unrecognised code).
  Two of them carry SCSS (`generic_`, `php_exception_`); the rest are Bootstrap utility
  markup.
- `errors/error_screen_components.js` — `Error_Screen_Components`: registers three of those
  bodies as the SPA's error screens (see HOW IT IS USED).

## HOW IT IS USED

Two callers:

1. **The three-state page pattern** — an action catches into `this.data.error_data` and
   its template renders `<Loading_Spinner>` / `<Universal_Error_Page_Component>` / the
   real content. Worked example:
   `rsx/app/frontend/settings/api_keys/settings_api_keys_action.js` and the pattern write-up
   in `rsx/app/frontend/CLAUDE.md`.
2. **The framework's SPA error screens** — `system/app/RSpade/Core/SPA/Error_Screens.js`
   mounts `Unauthorized_Error_Page_Component`, `Not_Found_Error_Page_Component` and
   `Generic_Error_Page_Component` into the live layout's content area when a gate denies an
   action, no action matches the URL, or an action fails to boot. It mounts them because
   `errors/error_screen_components.js` (`Error_Screen_Components`) REGISTERS them, from a
   static `on_app_modules_define()`, with `Error_Screens.set_components()`. **The bodies are
   app-owned theme code: the framework only resolves the container and mounts what was
   registered** — every bundle that includes `rsx/theme` carries the registration, and one
   that registered nothing throws the first time it needs an error screen.

**The server-rendered twins live in `rsx/app/errors/`** (and `rsx/portal/errors/` for the
portal realm): Blade pages the framework invokes when a request ends on a status before any
SPA exists to mount a component into. The two halves cover the same outcomes — not found,
access denied, something went wrong — from opposite sides of the boot: these components
render INSIDE a layout that is already on screen, those pages ARE the whole document.
**The copy is kept aligned by hand.** Nothing enforces it, and a user who meets both should
not be able to tell that two different systems answered.

## HOW TO CUSTOMIZE

- **Rebrand an error page**: edit that component's `.jqhtml`. Keep the component NAME and
  its argument names — `Universal_Error_Page_Component` mounts by name, and a rename breaks
  that mount. A rename or a different SPA error body is also a one-line change to
  `error_screen_components.js`.
- **Add a new error outcome**: add the body component, then add its branch to
  `universal_error_page_component.jqhtml`; the `Ajax.ERROR_*` codes themselves are the
  framework's (`rspade:ajax-error-handling`).
- **Reword an outcome here, reword its server-rendered twin too** — `rsx/app/errors/` for
  staff, `rsx/portal/errors/` for the portal.
- Keep the four required args of the router required — the loud throw is what stops a
  half-wired page from rendering an error page with no way out.
- `Loading_Spinner` is this app's spinner markup; the framework's registry-based spinner
  (`Rsx.get_default_spinner()`) is the other path. Do not hand-roll a third.
- The bodies deliberately carry no page chrome: they render inside whatever layout is
  already on screen.

## RELATED

App skill `crud-patterns` (the three-state page) · `rsx/app/frontend/CLAUDE.md` ·
`rsx/app/errors/CLAUDE.md` (the server-rendered twins) ·
skills `rspade:ajax-error-handling`, `rspade:auth-gates` (ERROR SCREENS) ·
`rsx:man auth_gates`, `rsx:man crud`, `rsx:man error_pages`
