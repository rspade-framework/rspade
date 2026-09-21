# rsx/app/errors — the staff realm's full-page error screens

## WHAT IS HERE

A Blade module, shaped like `login/`: a bundle, a layout, a controller and one blade per
page. Nothing here is a destination — **no link points at these routes, and nothing in the
application calls them.** They are DECLARATIONS: the framework reads the route table,
finds the page for the status the request ended on, and invokes the method itself.

| File | What it is |
|---|---|
| `errors_bundle.php` | `Errors_Bundle` — the same theme set as `Login_Bundle` (variables, responsive, the Bootstrap build, the whole `rsx/theme` tree, `rsx/lib`, this directory). |
| `errors_layout.blade.php` | `Errors_Layout` — the card shell: status chip, heading, content, an action row, and a detail region the generic page fills. |
| `errors_layout.scss` | Its geometry, under `.Errors_Layout` in BEM. No palette: every colour is a runtime token. |
| `errors_controller.php` | `Errors_Controller`, class-level `#[Auth('public')]`, four routes: `/error/404`, `/error/403`, `/error/419`, `/error/generic`. |
| `errors_not_found.blade.php` | `Errors_Not_Found` — 404. |
| `errors_forbidden.blade.php` | `Errors_Forbidden` — 403, plus a "Sign in as a different user" link to `Login_Controller::logout`. |
| `errors_expired.blade.php` | `Errors_Expired` — 419, the CSRF failure on a native form POST. |
| `errors_generic.blade.php` | `Errors_Generic` — every status with no exact page: 400, 500, 429, anything `abort()` raised. Renders `$error->detail` when the context carries one. |

There is no `/error/500`, deliberately: the generic page answers it, which is what makes
the fallback visible in the template rather than theoretical.

The portal realm has its OWN pages, in `rsx/portal/errors/` — a portal failure must render
portal links and the portal bundle, so the two never share a route.

## HOW IT IS USED

**The framework invokes the method directly**, with `($request, ['error' => $error])` and
nothing else. No controller `pre_dispatch` runs (an error page that could be redirected
away would be no error page at all), and the response's status is then FORCED to the
context's status — a page that renders a view cannot answer 200.

**`$error` is an `App\RSpade\Core\Errors\Error_Context`**, and it is everything a page has
to read:

| Field | What it carries |
|---|---|
| `status` | The HTTP status the response will carry. |
| `title` | The framework headline for that status ("Page Not Found"). |
| `message` | The framework sentence, or the reason the failing endpoint gave. |
| `path` / `method` | The failing request. |
| `realm` | Staff or portal. |
| `home_url` | The realm's home — the staff root here. |
| `preview` | True when this is a development browse of `/error/<code>`. |
| `detail` | `{class, message, file, line, frames[]}`, for a 500 only, and NULL in every sealed build (debug and production). |

The blades are passed `['error' => $error]`, and Blade hands the same data to the layout,
which is why `errors_layout.blade.php` can read `$error->home_url` and `$error->status`
without every page re-yielding them.

**The failing request is over.** Its controller never ran, the caller may be anonymous,
and the page is fully inspectable with curl. So a page shows what the context carries and
nothing else: no record lookup, no session chrome, no "you were trying to reach X" beyond
the path the context already holds. `detail` is redacted server-side, which is why
`errors_generic.blade.php` needs no mode check.

**Preview, development only.** Browse `/error/404`, `/error/403`, `/error/419` or
`/error/generic` to see a page against a fabricated context; the generic preview renders as
a 500 with a fabricated detail block. The framework intercepts the path in every mode, so
these URLs are never served as ordinary 200 pages, and a sealed build answers 404 to them.

```bash
php artisan rsx:debug /error/404 --user=1
php artisan rsx:debug /error/generic --user=1
```

**When a page throws, the framework's own page renders instead** and the failure is logged
— the error page for the error page must not be a third error. A page that returns a coded
response (`response_not_found()` and friends) counts as a failure for the same reason.

## HOW TO CUSTOMIZE

- **Reword or restyle a page**: its blade, and `errors_layout.scss` for the chrome.
  Recolouring is the THEME's job — these pages carry no palette of their own and follow
  light and dark with the rest of the application.
- **Add a page for another status**: a method here with `#[Route('/error/<status>')]` and a
  blade beside it. The pattern must be exactly `/error/<400-599>` or `/error/generic`, GET,
  no `:params`, and `#[Auth('public')]` — `ROUTE-ERROR-01` fails the manifest build
  otherwise. Adding one takes that status off the generic page automatically.
- **Delete a page**: remove the method and its blade. The status falls to the generic page,
  and deleting THAT falls to the framework's own page. Nothing breaks; the look changes.
- **Do not put an action on a page that needs a session** — a 403 reaches somebody the gate
  just refused, and a 500 reaches a request whose boot may be what failed. The home link and
  a sign-in link are safe because neither reads anything.
- **Do not add JavaScript** unless a page genuinely needs it. There is no `errors_layout.js`:
  a static page needs no anti-FOUC reveal, and a Blade page's JS would need the usual
  `on_app_ready()` guard (`if (!$('.Errors_Layout').exists()) return;`).
- **Keep the copy aligned with the SPA twins** in `rsx/theme/components/feedback/errors/` —
  those render the same outcomes inside a live layout, and the two are kept in step by hand.

## RELATED

`rsx/portal/errors/` (the portal twins, documented in `rsx/portal/CLAUDE.md`) ·
`rsx/app/login/CLAUDE.md` (the Blade module this one is shaped like) ·
`rsx/theme/components/feedback/CLAUDE.md` (the SPA twins) · `rsx/main.php` (`unhandled_route`) ·
skills `rspade:blade-views`, `rspade:auth-gates` · `rsx:man error_pages`, `rsx:man routing`
