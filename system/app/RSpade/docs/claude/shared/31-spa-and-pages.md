<!-- single-source: never duplicate into another fragment. -->

## SPA, PAGES & URLS

Client-side routing for authenticated areas: **ONE PHP bootstrap controller per module** (`#[SPA]`, returning `rsx_view(SPA)`) plus MANY JavaScript actions that navigate without page reloads. Feature controllers inside an SPA module are **Ajax endpoints only**.

```javascript
@route('/contacts')                     // repeat for a dual-route add/edit action
@layout('Frontend_Layout')              // outermost first; repeat for sublayouts
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')                   // MANDATORY on every @route action
@title('Contacts')                      // the whole title for a fixed-title page
class Contacts_Index_Action extends Spa_Action { async on_load() { ... } }
```

**A fixed title is `@title` and nothing else** — `page_title()` returns it, and the layout paints it synchronously at dispatch. **Override `page_title()` only for a data-dependent title, and start the override with `await this.await_loaded()`** before reading `this.data` (it is called before the load finishes); an override means the class's `@title`, if any, is route metadata and is never painted.

A **layout** extends `Spa_Layout` and its template MUST contain a `$sid="content"` element — that is where actions render, and it persists across navigation. **Navigation**: `Spa.dispatch('/path')` pushes history; **`Spa.redirect('/path')` REPLACES it and is the one for a programmatic redirect in `on_load()`**. Route and query params both land in `this.args`. **Link interception is automatic** — only known SPA routes are intercepted, so no attribute is needed to bypass it; do not invent a `data-no-spa`.

**SPA pages are the preferred standard.** Use Blade only for SEO-critical public pages and authentication flows (jqhtml works in Blade but is not server-rendered). Blade page JS is a static `on_app_ready()` and **needs a page guard** (`if (!$('.My_Page').exists()) return;`) because it fires for every page in the bundle. A CRUD feature splits into `list/` (Index + DataGrid), `view/` and `edit/` with one dual-`@route` add/edit action and a three-state loading pattern.

**Routing rules**: **only GET and POST** (no PUT/PATCH/DELETE), no resource routes, `:param` path params, signature always `(Request $request, array $params = [])` with both verbs in the SAME method (`$request->is_post()`); file responses use `Response::download()`/`file()`.

**All URLs are generated with `Rsx::Route()` / `Rsx.Route()` — hardcoded URLs are forbidden** and `rsx:check` flags them: `Rsx::Route('Frontend_Controller::view', $id)`, with extra array keys becoming a query string.

**Modules and bundles**: scaffold with `rsx:app:module:create` / `:module:feature:create` (SPA by default; `--blade` scaffolds the server-rendered ladder for public/SEO pages) / `:submodule:create` / `rsx:app:component:create`. **One bundle per module**, compiled JIT on web request — never a manual build step.

Skills: `rspade:spa`, `rspade:crud-patterns`, `rspade:blade-views`, `rspade:bundles`. Details: `rsx:man spa`, `rsx:man crud`, `rsx:man routing`.
