# rsx/app — the staff module index

## WHAT IS HERE

One directory per staff module, each with its own asset bundle (except `apidocs/`, which
has no assets of its own), plus one loose file.

| Module | What it is | Status |
|---|---|---|
| `frontend/` | The main authenticated SPA — one bootstrap controller, one persistent layout, and a feature directory per screen. Own `CLAUDE.md`. | The application. |
| `login/` | The server-rendered auth ladder: login, signup, invite acceptance, site selection, site-unauthorized. Own `CLAUDE.md`. | Live, public by declaration. |
| `api/` | The external bearer REST surface under `/api/vN/`. Own `CLAUDE.md`. | Live. |
| `apidocs/` | A CONTROLLER AND NOTHING ELSE: two methods mounting the framework's API reference console and its OpenAPI document. The console, including its bundle, is framework property - `Rsx_Api_Docs::page()` renders the whole page. Gate is `public`, with `Session::has_api_access()` decided in the body. | Live. |
| `root/` | Cross-site root console (dashboard, sites, email), gated `is_root_admin`. Own `CLAUDE.md`. | **Every page is a placeholder.** |
| `backend/` | A minimal Blade admin shell. Own `CLAUDE.md`. | **Skeleton — deletable.** |
| `dev/` | The framework showcase: modals, flash alerts, ACL, ORM, SPA, attachments, document preview. Own `CLAUDE.md`. | **Ships `#[Auth('closed')]` — reachable by nobody. Deletable.** |
| `ssr_test/` | The server-render smoke page and its session-cookie probes. Own `CLAUDE.md`. | **Harness — deletable.** |
| `index_controller.php` | `/` — redirects to the dashboard when signed in, to login otherwise. | Live. |

Only the API-keys settings screen links out of `frontend/` into another module (a button
to `Apidocs_Controller`, which is why `frontend_bundle.php` includes `rsx/app/apidocs`).
`/root`, `/admin`, `/dev` and `/ssr-test` are URL-only: no nav entry points at any of them.

## HOW IT IS USED

**One bundle per module.** A module's `*_bundle.php` names theme variables and responsive
first, then bootstrap, then whatever shared trees it needs (`rsx/theme`, `rsx/lib`,
`rsx/models`), then `__DIR__`. A module never reaches into another module's directory.

**`#[Auth]` on every dispatchable surface, `@auth` on every `@route` action.** Modules are
closed by default; the manifest build fails on an ungated surface, and `public` and
`closed` are both explicit declarations carrying a written justification.

A feature controller inside an SPA module exposes **Ajax endpoints only** — the route lives
on the JS action. A Blade module puts `#[Route]` on the controller instead.

## HOW TO CUSTOMIZE

- **Add a module**: `php artisan rsx:app:module:create <name>` scaffolds the directory,
  its bundle and its layout; `rsx:app:module:feature:create` adds a feature inside it.
  SPA is the default and the preferred shape — Blade is for public and auth pages.
- **Delete a module**: remove the directory, then check for a nav entry, a cross-module
  bundle include, and any `Rsx::Route()` call naming one of its classes. Each of the four
  deletable modules above says in its own `CLAUDE.md` what deleting it frees.
- **The four request-lifecycle hooks** (`main.php`, `permission.php`, `portal_main.php`,
  `portal_permission.php`) live one level up in `rsx/` and are documented in the project
  `CLAUDE.md`, THE FOUR HOOKS — that is where site declaration and gate vocabulary belong,
  never in a module.

## RELATED

`frontend/CLAUDE.md` · `login/CLAUDE.md` · `api/CLAUDE.md` · `../portal/CLAUDE.md` ·
skills `rspade:spa`, `rspade:blade-views`, `rspade:bundles`, `rspade:auth-gates` ·
`rsx:man spa`, `rsx:man routing`, `rsx:man auth_gates`, `rsx:man bundles`
