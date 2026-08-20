<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL #[Auth] STATEMENT; other fragments carry one-line reminders that point here. -->

## AUTH GATES

**`#[Auth]` is MANDATORY on every dispatchable surface** — `#[Route]`, `#[SPA]`, `#[Ajax_Endpoint]`, `#[Ajax_Endpoint_Model_Fetch]`, `#[Api_Endpoint]`, `@route` JS actions. Surfaces are **CLOSED BY DEFAULT**: one with no gate does not deploy, and the manifest build FAILS with a per-violation worklist. There is no attribute-free spelling of "open" and **no off switch** — a public surface declares `#[Auth('public')]`. One attribute, variadic check names, AND semantics (`#[Auth('is_logged_in','can_view_billing')]`); a class-level attribute covers every surface in the class and a method-level one is ADDITIVE (gates only narrow).

**`pre_dispatch()` performs NO authorization anywhere** — it is for other middleware concerns (tenant setup, interstitials, redirects). `@auth-exempt` is dead syntax; authorization is declared, not detected.

**Gate vs record**: `#[Auth]` answers "may this USER use this surface at all"; the function body answers "which RECORDS may they touch". **A rule whose answer depends on WHICH row is never a gate** — that is where `require_permission()`/`require_role()` and `Session::is_logged_in()` belong.

**Three layers, do not conflate them.** **Roles** = `users.role_id`, hierarchical (lower id = higher privilege). **ACLs** = `user_permissions` GRANT/DENY rows layering per-user exceptions (DENY wins). **Auth-gate checks** = named `#[Auth_Check]` methods on the realm's `Permission` class, resolved to one true/false per session — usually from roles and ACLs, but a check may consider any user- or environment-scoped fact. **A check is where "may this user" is answered; roles and ACLs are only the most common inputs.**

**`can_access($target)`** (both languages, same spellings as `Rsx::Route()`) is true when every gate on the TARGET passes — **so link visibility derives from the destination's own declaration and a sidebar cannot lie.** **Realms**: staff and portal check registries are separate namespaces, so a cross-realm check name is a build error.

Skills: `rspade:auth-gates` (writing checks, the JS export, `#[Auth_Realm]`, the build-failure worklist), `rspade:permissions-acl` (the `Permission` API, roles, GRANT/DENY rows). Details: `rsx:man auth_gates`, `rsx:man acls`.
