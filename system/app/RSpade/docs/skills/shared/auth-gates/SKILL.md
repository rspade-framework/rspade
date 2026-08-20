---
name: auth-gates
description: Annotating dispatchable surfaces with #[Auth] and defining the check vocabulary with #[Auth_Check] - covering routes, SPA actions, Ajax endpoints, model fetch, API endpoints, realm declaration with #[Auth_Realm], and can_access()/Auth_Gates::accessible_route() link visibility. Use when adding or gating any #[Route] / #[SPA] / #[Ajax_Endpoint] / #[Ajax_Endpoint_Model_Fetch] / #[Api_Endpoint] / @route action, when the manifest build fails with MISSING GATE / UNKNOWN CHECK / CONTRADICTION, when writing a new Permission check, when a link or nav item must hide itself, or when a portal endpoint is being denied for the wrong realm.
---

# Auth Gates

Every dispatchable surface in RSpade declares who may reach it, as an attribute. The framework evaluates it at the seam that owns the surface, **before any application code runs**. Surfaces are closed by default: one with no gate does not build. The always-on fragment carries the mandate; this skill is how to do the work.

---

## Part A - Annotating a surface

One attribute, variadic check names, **AND** semantics. Class-level covers every surface in the class; a method-level attribute is **additive** - gates can only narrow, never widen.

```php
#[Auth('is_logged_in')]                       // covers every surface below
class Admin_Users_Controller extends Rsx_Controller_Abstract
{
    #[Route('/admin/users')]
    #[Auth('can_manage_users')]               // logged in AND can_manage_users
    public static function index(Request $request, array $params = []) { }

    #[Ajax_Endpoint]
    #[Auth('can_manage_users')]
    public static function save(Request $request, array $params = []) { }
}
```

### Every surface kind

```php
// Server-rendered page / SPA bootstrap
#[Route('/login', methods: ['GET','POST'])]
#[Auth('public')]                              // the explicit open marker
public static function index(Request $request, array $params = []) { }

#[SPA]
#[Auth('is_logged_in')]
public static function index(Request $request, array $params = []) { }

// Ajax endpoint (browser-only transport)
#[Ajax_Endpoint]
#[Auth('can_manage_users')]
public static function save(Request $request, array $params = []) { }

// ORM fetch - the gate is user-scoped; record rules stay in the body
#[Ajax_Endpoint_Model_Fetch]
#[Auth('is_logged_in')]
public static function fetch($id) { }

// External REST API - the bearer key IS a staff identity, so staff-realm names
#[Api_Endpoint('/api/v1/contacts', methods: ['GET'])]
#[Auth('is_logged_in')]
public static function list(Request $request, array $params = []) { }
```

A JS SPA action declares the same vocabulary as a decorator, beside `@route`:

```javascript
/** @decorator */
@route('/admin/users')
@layout('Frontend_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('can_manage_users')
class Users_Index_Action extends Spa_Action { }
```

### Where denial lands

| Surface | Seam | Denied |
|---|---|---|
| `#[Route]` / `#[SPA]` | Dispatcher, after route resolution, **before `Main::pre_dispatch`** | no session -> 302 to the realm's login with the intended URL captured (`Login_Redirect`); authenticated -> the 403 error screen |
| `#[Ajax_Endpoint]` | Ajax dispatch, after CSRF | the standard coded unauthorized Ajax error; the body never runs |
| `#[Ajax_Endpoint_Model_Fetch]` | ORM seam | the same generic "not found" a missing row returns (anti-enumeration) |
| `#[Api_Endpoint]` | API dispatch | 401/403 JSON error |
| `@route` action | Spa gate | `Error_Screens.unauthorized()` rendered into the layout - no navigation, no round trip |

`pre_dispatch()` performs NO authorization anywhere. It is for tenant setup, interstitials and redirects.

---

## Part B - Defining a check

`#[Auth_Check]` on a public static method of the realm's `Permission` class. That is the whole registration - no config, no list to update in the framework.

```php
// rsx/permission.php
class Permission extends Permission_Abstract
{
    #[Auth_Check]
    public static function can_manage_users(): bool
    {
        return static::has_role(User_Model::ROLE_SITE_ADMIN);
    }

    #[Auth_Check]
    public static function can_view_billing(): bool
    {
        return static::has_permission(User_Model::PERM_MANAGE_SITE_BILLING);
    }

    #[Auth_Check]   // composite functional rule - same shape, same home
    public static function can_impersonate(): bool
    {
        return static::has_role(User_Model::ROLE_MANAGER) && !Session::is_impersonating();
    }
}
```

**The rules, each a build-aborting error:**

- **PUBLIC AND STATIC.** A public *instance* method carrying `#[Auth_Check]` is fatal - a gate is evaluated with no object in hand.
- **NO PARAMETERS.** A check answers "may THIS USER use this kind of surface" from identity state alone. This is what makes complete client-side resolution possible: a no-argument check exports as a single boolean. **The moment you want an argument you are writing a record-level check** - it belongs inside the function body.
- **DECLARED `: bool` RETURN.** The seams treat anything not exactly `true` as deny (null, 0, `''`, a forgotten return all refuse), and the declared type catches the sloppiness statically.
- **UNIQUE NAME within the realm.** The registry is built over the whole lineage from `Permission_Abstract` down, so an override of a marked ancestor check that OMITS `#[Auth_Check]` is also fatal - silently un-marking an inherited gate would silently change what every surface naming it means.
- **CHEAP AND PURE.** Read resolved state (Session, the resolved permission set, environment facts); never query per call. Every marked check runs once per page render to build the client export, and results are **memoized per request** - a check executes at most once per request no matter how many surfaces or inline calls consult it.

**Built-ins** (on `Permission_Abstract`, marked, inherited by every app): `public` (always true - the explicit open marker) and `is_logged_in`. `Portal_Permission_Abstract` carries the portal realm's equivalents. **Do NOT re-declare an inherited built-in** on your `Permission` class - the registry already resolves it, and an override that forgets the attribute is a build error.

A check may consider ANY user- or environment-scoped fact - trial vs enterprise plan, CLI vs web, debug site. What it may NOT consider is **the specific record being acted on**.

### The generated JS twin

Marking a check also exports it to `window.rsxapp.auth` and generates `Permission.<name>()` in JavaScript. **Hand-written JS twins are forbidden** - the generated one is authoritative and cannot drift. The export carries **grants only**: a denied check is omitted from the map, never shipped as `false`.

```javascript
if (Permission.can_manage_users()) { ... }     // reads the grants map, no network
```

### Attribute args cannot carry class constants

```php
#[Auth('can_view_billing')]                                   // [OK]
#[Auth(User_Model::PERM_MANAGE_SITE_BILLING)]                 // [NO] - dies during the scan
```

Reflection resolves attribute arguments before the autoloader is ready, so a class constant in an attribute argument kills the manifest scan. **That is why role and permission checks reach surfaces through named one-line helpers** on `Permission` - the constant lives inside the method body, where it resolves normally.

---

## Part C - Gate vs record, worked

The division of labor on any data-serving function:

```
#[Auth(...)] gates   ->  may this user use this surface AT ALL
function body        ->  which RECORDS may they touch, and how
```

**IS a gate**: login state, role floors, permission grants, composite functional rules (manager AND not currently impersonating; debug-site-only dev tools; plan tiers).

**IS NOT a gate** (stays inside the body, after the gates pass): record ownership ("only your own profile"), membership scoping ("only clients you belong to"), record state ("only DRAFT invoices are editable"), cross-record invariants ("cannot delete a client with open work"), plus input validation, CSRF and rate limiting (other subsystems entirely).

```php
#[Ajax_Endpoint_Model_Fetch]
#[Auth('is_logged_in')]                       // the gate: any signed-in staff member
public static function fetch($id)
{
    $invoice = static::find($id);
    if (!$invoice) { return false; }

    // record layer - depends on WHICH row, so it can never be a gate
    if ($invoice->owner_user_id !== Session::get_user_id()
        && !Permission::can_manage_billing()) {
        return false;                          // reads to the client as "not found"
    }

    return ['id' => $invoice->id, 'total' => $invoice->total];
}
```

`require_permission()` / `require_role()` keep their inline role in that second layer - they throw `AjaxUnauthorizedException` (403) rather than returning early.

---

## Part D - Link visibility

**`can_access($target)`** takes the same spellings as `Rsx::Route()` (no route params) and is true when **every gate on the TARGET passes**. Link visibility therefore derives from the destination's own declaration - **a sidebar cannot lie**, and adding a gate to a page tightens every link to it automatically.

```blade
@if (Permission::can_access('Admin_Users_Controller::index'))
    <a href="{{ Rsx::Route('Admin_Users_Controller::index') }}">Manage Users</a>
@endif

{{-- parameterized link: check the surface, param the href --}}
@if (Permission::can_access('Frontend_Controller::view'))
    <a href="{{ Rsx::Route('Frontend_Controller::view', $client->id) }}">{{ $client->name }}</a>
@endif
```

There is deliberately **no `@allowed` directive and no link-emitting helper**: the label is authored content, and one spelling of the check is worth more than three characters of sugar.

In jqhtml the raw form works, but **the sanctioned form for navigation is semantic composition** - a chrome component owns the check so page templates never write the conditional:

```jqhtml
<Nav_Section $title="Administration">
    <Nav_Item $route="Users_Index_Action" $icon="bi bi-people">Users</Nav_Item>
    <Nav_Item $route="Billing_Index_Action" $icon="bi bi-cash">Billing</Nav_Item>
</Nav_Section>
```

`Nav_Item`'s template opens with `<% if (!Permission.can_access(this.args.route)) return; %>`, and `Nav_Section` renders nothing when every child hid itself (no orphaned headings).

**`Auth_Gates::accessible_route($target, $realm, $params)`** is the URL-returning sibling: it returns the URL only when the surface exists in this install AND its gates pass, else `null`. Use it when naming a screen that a downstream app may have removed - it degrades to `null` instead of throwing. (`can_access()` THROWS on an unknown target; that loudness is deliberate for a link check you know exists.) Actor profile links are built on it - see the `rspade:actors-and-authorship` skill.

---

## Part E - Realms

Staff and portal registries are **separate namespaces**: `is_logged_in` exists in both and means different things. A cross-realm check name on a surface is a build error.

```php
#[Auth_Realm('portal')]                       // class-level
class Portal_Documents_Controller extends Rsx_Controller_Abstract { }
```

**Defaulting**: an `#[Ajax_Endpoint]` controller under a portal root (`rsx/portal/`, `app/RSpade/Core/Portal/`) defaults to `portal`; everything else defaults to `staff` (fail-closed). Declare it explicitly when the file lives elsewhere but serves the portal.

At the two shared-transport seams (Ajax, ORM) **a realm mismatch DENIES with one logged warning before any check name resolves** - evaluating a portal endpoint's gate in the staff registry would admit a staff session.

**`'any'` is for framework services that deliberately serve both.** The complete roster:

`Orm_Controller` · `Realtime_Controller` · `Spa_Session_Controller` · `File_Preview_Controller` · `Debugger_Controller` · `Rsx_Reference_Data_Controller`

App controllers rarely need it. (`Realtime_Controller` has no gate of its own by design - a topic class's `can_subscribe()` is the sole enforcement boundary there; see `rspade:realtime`.)

---

## Part F - The build-failure worklist

Enforcement is ONE batched manifest pass that runs **before the manifest is saved**, so a violation aborts the build and writes nothing. Three findings, each reported per surface:

| Finding | Meaning |
|---|---|
| `MISSING GATE` | the surface declares no `#[Auth]`/`@auth`, directly or on its class |
| `UNKNOWN CHECK` | a named check is declared by no `#[Auth_Check]` method in that surface's realm (an `'any'` surface needs it in at least one) |
| `CONTRADICTION` | a member declares `#[Auth('public')]` while its class declares a restricting gate - gates AND, so the member opens nothing |

Separately fatal at scan time, before that pass: an `#[Auth_Check]` that is parameterized, not `: bool`, or on an instance method; a duplicate or silently-unmarked-override check name; a non-string `#[Auth]` argument; a malformed or conflicting `#[Auth_Realm]`; a non-string or empty `@auth` argument.

**The error message IS the worklist.** It opens with the violation count and the closed-by-default rule, then lists per violation: the target, the file, the surface kind and realm, what is wrong, and **the exact line to add**, pre-filled with a real check name from that realm. It closes with AVAILABLE CHECK NAMES - every check currently defined in both realms, under its home file - so resolving a violation is a copy-paste, not a hunt. Fix the list and the build passes.

**There is no off switch.** No config key, no `.env` value, no flag. While the build is failing, artisan is bricked EXCEPT for the always-runnable escape hatch (`rsx:clean`, `rsx:man`, `list`/`help`, `--version`, bare artisan) - which is how you read this page from inside the failure.

**Retired, and dead syntax today**: `@auth-exempt`, `@portal-auth-exempt`, `route_is_exempt()`, authorization logic in `pre_dispatch()`. The heuristic lint rules `PHP-AUTH-01` and `PORTAL-AUTH-01` are **deleted**, not bypassed - they existed to GUESS whether a check was present; authorization is now declared, so there is nothing to guess and no exception tag to grant.

---

## Part G - Troubleshooting

- **Ajax call returns unauthorized but the user clearly has the role.** Check the realm: a controller outside `rsx/portal/` serving portal pages defaults to `staff`, so the mismatch denies before your check name is even looked up. Add `#[Auth_Realm('portal')]`.
- **A `fetch()` returns "not found" for a record that exists.** A gate denial at the ORM seam is deliberately indistinguishable from a missing row. Look at the gate first, the `fetch()` body second.
- **Build error naming a check that exists.** It exists in the *other* realm. Portal surfaces resolve only against `Portal_Permission`; staff surfaces only against `Permission`.
- **A hidden link that should be visible.** `can_access()` reads the DESTINATION's gates - fix the destination's declaration, never the link.
- **`can_access()` throws "unknown target".** The spelling must match `Rsx::Route()` exactly; for a surface that may not exist in this install, use `Auth_Gates::accessible_route()` instead.
- **Denial screens** are `Error_Screens` (PHP `unauthorized()`/`not_found()`/`fatal()`, JS `Error_Screens.unauthorized()`); customize the PHP side by class-overriding `Error_Screens`.

Details: `php artisan rsx:man auth_gates`. Related: `rspade:permissions-acl`, `rspade:portal`, `rspade:session-auth`.
