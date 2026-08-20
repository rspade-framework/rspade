# Core/Portal — the framework side of the client portal

The portal is a **second authenticated experience** running parallel to the staff
app, with its own dispatcher and routing. This directory is the framework half;
the application half lives in `rsx/portal/` (which has its own CLAUDE.md).

- `Rsx_Portal.php` — the facade. `is_portal_request()` is the seam that decides
  which experience a request is; `Route()`, `internal_url()`.
- `Portal_Session.php` — a static FACADE over the portal-property subset of the
  session row. **Not a model** — no `::where()`, no instances.
- `Portal_Dispatcher.php` — portal request dispatch.
- `Portal_Route_ManifestSupport.php` / `Portal_Spa_ManifestSupport.php` —
  `#[Portal_Route]` and `@portal_spa` indexing. Portal routes live in a
  **separate manifest table** from staff `#[Route]`s.
- `Portal_Main_Abstract.php` — base for the app's `rsx/portal_main.php`.
- `Portal_Permission_Abstract.php` — marker base for the app's
  `Portal_Permission` facade.
- `Portal_Authorizable.php` — the trait supplying the gated `portal_fetch()`.

## Invariants worth stating out loud

- **ONE session per browser.** One `rsx` cookie, one `_sessions` row, shared by
  the staff app and the portal. The row is a PROPERTY BAG; the EXPERIENCE is a
  property of the REQUEST. Both identities set at once is NORMAL. Never mix the
  two facades' properties, and never delete or deactivate the row on portal
  logout — clear the portal properties.
- **Identity is not experience.** App code that must fork forks on
  `Rsx_Portal::is_portal_request()`, never on `is_logged_in()`.
- **RSpade never resolves the portal's site.** The application declares it with
  `Portal_Session::set_site_id(int)`. `get_site_id()` throws when nothing has.
  Do not add detection, a default, or a hook here.
- **Realms are separate namespaces.** `is_logged_in` exists in both and means
  different things; a cross-realm check name is a build error. At the shared
  transports (Ajax, ORM, upload) a realm mismatch DENIES before any check name
  resolves.
- Framework surfaces serving both experiences (flash alerts, CSRF, ORM fetch)
  resolve the experience themselves. **Never hand-roll a portal variant** of one.
- Read-only impersonation is the APP's job; core exposes only
  `is_impersonating()`.

## Pointers

`rsx:man portal` · `rsx:man auth_gates` · `rsx:man session` ·
skill `rspade:portal` · app side: `rsx/portal/CLAUDE.md`
