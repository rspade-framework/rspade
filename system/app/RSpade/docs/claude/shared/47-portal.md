<!-- single-source: never duplicate into another fragment. -->

## CLIENT PORTAL

A second authenticated experience for external users (clients, vendors), running **parallel** to the staff app with its own dispatcher, routing table and permission facade — framework in `App\RSpade\Core\Portal\` (`Portal_Session`, `Rsx_Portal`, `Portal_Main_Abstract`, `Portal_User_Model`), application in `/rsx/portal/`. Routing is `#[Portal_Route('/path')]` (server-rendered) and `@portal_spa(...)` (SPA screens), resolved in the PORTAL auth realm; URLs come from `Rsx_Portal::Route(...)` / `Rsx_Portal.Route(...)`.

**ONE session per browser** — one cookie, one `_sessions` row, one CSRF token, shared with the staff app; both identities being set at once is NORMAL. **`Portal_Session` is a facade over the portal properties only — never mix the two facades' properties.**

**When app code has to fork, fork on `Rsx_Portal::is_portal_request()` — never on `is_logged_in()`. Identity is not experience.** Framework surfaces serving both experiences resolve it themselves, so portal code calls `Flash_Alert` and `@csrf` exactly like staff code; never hand-roll a portal variant.

**The APP declares the portal's site** — RSpade never resolves it. Call `Portal_Session::set_site_id(int)` before anything asks, normally in `Portal_Main::init()`; `get_site_id()` **throws** when nothing declared one, because a portal that does not know its tenant must not pick one. Every framework site seam reads that declaration, so **portal code never hand-scopes a query**.

**Authorization mirrors staff auth**: `#[Auth]` on every surface, closed by default, `Portal_Main::pre_dispatch` performing NO authorization, and per-client rules living in the record layer (`portal_fetch()` + fail-closed `portal_can_read()`, `PORTAL-MODEL-FETCH-01`). **Multipart uploads MUST go through `Ajax.upload(form_data)`** — a raw `fetch('/_upload')` hits the staff path with no CSRF token.

**"View as Client" impersonation is READ-ONLY, and enforcing that is the APP'S JOB** — the framework only exposes `is_impersonating()`, and since every Ajax endpoint is a POST (reads too), a blanket POST block breaks the portal: guard each MUTATING endpoint. Test with `rsx:debug /path --portal --portal-user=<id|email>`.

Skill `rspade:portal`: routing and site-declaration recipes, the `Portal_Session` API, record-rule patterns, the internal-endpoint channel, impersonation handoff, invite/membership model. Details: `rsx:man portal`.
