---
name: permissions-acl
description: Working with RSpade's role hierarchy and per-user ACL layer - users.role_id, user_permissions GRANT/DENY rows, the Permission facade (has_permission/has_role/can_admin_role/require_permission/require_role) and its JS mirror reading window.rsxapp.user.resolved_permissions. Use when adding a permission constant or a role, checking whether a user may do something inside a function body, preventing privilege escalation in a role-assignment UI, granting or denying a supplementary permission, or deciding whether a rule belongs in an ACL or an #[Auth] gate.
---

# Permissions and ACLs

Three layers, and conflating them is the usual mistake:

| Layer | Where it lives | What it is |
|---|---|---|
| **Roles** | `users.role_id` | hierarchical, one per site membership; each grants a default permission set |
| **ACLs** | `user_permissions` rows | per-user GRANT/DENY exceptions to that default; **DENY always wins** |
| **Auth-gate checks** | `#[Auth_Check]` methods | named booleans a surface declares; usually fed by roles and ACLs, but may read any user- or environment-scoped fact |

**A check is where "may this user" is *answered*; roles and ACLs are only the most common *inputs*.** Gating a surface is the `rspade:auth-gates` skill; this one is the input layer and the in-body checks.

---

## Roles

Lower id = higher privilege. IDs are 100-based to leave room for insertion.

| ID | Constant | Label | Can admin roles |
|---|---|---|---|
| 100 | `ROLE_DEVELOPER` | Developer | 200-800 (system-assigned only) |
| 200 | `ROLE_ROOT_ADMIN` | Root Admin | 300-800 (system-assigned only) |
| 300 | `ROLE_SITE_OWNER` | Site Owner | 400-800 |
| 400 | `ROLE_SITE_ADMIN` | Site Admin | 500-800 |
| 500 | `ROLE_MANAGER` | Manager | 600-800 |
| 600 | `ROLE_USER` | User | none |
| 700 | `ROLE_VIEWER` | Viewer | none |
| 800 | `ROLE_DISABLED` | Disabled | none |

`has_role()` is an **"at least" test** - same or higher privilege (lower id). `can_admin_role()` reads the "can admin roles" list and is what **prevents privilege escalation**: a Site Admin cannot create a Site Owner.

Roles are declared as an ordinary model enum on `User_Model`, with two custom properties the ACL layer reads:

```php
public static $enums = ['role_id' => [
    300 => [
        'constant'        => 'ROLE_SITE_OWNER',
        'label'           => 'Site Owner',
        'permissions'     => [2, 3, 4, 5, 6, 7],
        'can_admin_roles' => [400, 500, 600, 700, 800],
    ],
]];
```

Read them through the usual BEM-style magic properties: `$user->role_id__label`, `$user->role_id__permissions`, `$user->role_id__can_admin_roles`.

---

## Permissions

Core permissions, granted by role:

| ID | Constant | Default holders |
|---|---|---|
| 1 | `PERM_MANAGE_SITES_ROOT` | Developer, Root Admin |
| 2 | `PERM_MANAGE_SITE_BILLING` | Site Owner+ |
| 3 | `PERM_MANAGE_SITE_SETTINGS` | Site Admin+ |
| 4 | `PERM_MANAGE_SITE_USERS` | Site Admin+ |
| 5 | `PERM_VIEW_USER_ACTIVITY` | Manager+ |
| 6 | `PERM_EDIT_DATA` | User+ |
| 7 | `PERM_VIEW_DATA` | Viewer+ |

Supplementary permissions, granted by no role by default: `PERM_API_ACCESS` (8), `PERM_DATA_EXPORT` (9).

### Resolution order

1. Role is `ROLE_DISABLED` -> deny everything.
2. Explicit **DENY** in `user_permissions` -> denied.
3. Explicit **GRANT** in `user_permissions` -> granted.
4. In the role's default set -> granted.
5. Otherwise denied.

**DENY always wins** - a user with both a GRANT and a DENY row has the permission denied.

### The supplementary layer

`user_permissions` = `(user_id, permission_id, is_grant)` with a UNIQUE key on the pair. Use it for per-user exceptions: API access for one user regardless of role, export removed from a user who normally has it, temporary elevation during onboarding.

```php
User_Permission_Model::grant($user_id, User_Model::PERM_API_ACCESS);
User_Permission_Model::deny($user_id, User_Model::PERM_DATA_EXPORT);
User_Permission_Model::remove($user_id, User_Model::PERM_API_ACCESS);  // back to role default
$supplementary = User_Permission_Model::for_user($user_id);
```

---

## The Permission facade (PHP)

`App\RSpade\Core\Permission\Permission_Abstract` is the framework base; every app's `rsx/permission.php` extends it and inherits these. **Never redeclare the primitives** - add domain helpers on top.

```php
Permission::get_user(): ?User_Model          // site membership record, null if not logged in
Permission::is_logged_in(): bool
Permission::has_permission(int $permission): bool
Permission::has_role(int $role_id): bool     // "at least this role"
Permission::can_admin_role(int $role_id): bool
Permission::can_access(string $target): bool // every gate on the TARGET surface passes

Permission::require_permission(int $permission, string $message = 'Unauthorized'): void
Permission::require_role(int $role_id, string $message = 'Unauthorized'): void
```

The two calling styles, both legitimate inside a function body:

```php
// Branching - return an error response early
if (!Permission::has_permission(User_Model::PERM_MANAGE_SITE_USERS)) {
    return response_error(Ajax::ERROR_UNAUTHORIZED);
}

// Fail-loud - throws AjaxUnauthorizedException (403); the framework formats the response
Permission::require_permission(User_Model::PERM_MANAGE_SITE_USERS);
```

Instance methods on `User_Model` answer for a specific user rather than the session: `$user->has_permission()`, `$user->get_resolved_permissions()`, `$user->can_admin_role()`, `$user->has_supplementary_grant()`, `$user->has_supplementary_deny()`.

### App domain helpers

Your `Permission` subclass is where application vocabulary lives - a one-line method per concept, which is also the shape an `#[Auth_Check]` needs (attribute arguments cannot carry class constants, so the constant has to live inside a method body):

```php
class Permission extends Permission_Abstract
{
    #[Auth_Check]
    public static function can_manage_billing(): bool
    {
        return static::has_permission(User_Model::PERM_MANAGE_SITE_BILLING);
    }
}
```

---

## The Permission mirror (JS)

Reads `window.rsxapp.user.resolved_permissions` - computed server-side by `get_resolved_permissions()`, so role defaults plus grants minus denies, matching PHP exactly. **No network calls.** This is UI affordance hiding only; the server always enforces.

```javascript
Permission.is_logged_in()                    // boolean
Permission.get_user()                        // user object from rsxapp, or null
Permission.has_permission(User_Model.PERM_EDIT_DATA)
Permission.has_any_permission([...])         // ANY of the list
Permission.has_all_permissions([...])        // ALL of the list
Permission.has_role(User_Model.ROLE_MANAGER) // "at least" - lower id = higher privilege
Permission.can_admin_role(User_Model.ROLE_USER)
Permission.get_resolved_permissions()        // number[]
Permission.<check_name>()                    // GENERATED, one per #[Auth_Check]
Permission.can_access('Users_Index_Action')  // every gate on the target passes
```

The generated check methods and `can_access()` read `window.rsxapp.auth` instead - the **grants-only** map of check names that passed for this user (a denied check is absent, never `false`). **Hand-written JS twins of check logic are forbidden**: the composite logic has exactly one implementation, the PHP body, and a hand-rolled twin drifts.

A role-assignment dropdown is the archetypal `can_admin_role()` consumer:

```javascript
const assignable = User_Model.role_id__enum_select()
    .filter(o => Permission.can_admin_role(o.value));
```

---

## Adding a permission

1. Add the constant to `User_Model`: `const PERM_NEW_FEATURE = 10;`
2. Add its id to the `permissions` array of every role that should grant it by default (omit entirely for a supplementary-only permission).
3. `php artisan rsx:constants:regenerate` - regenerates the JS stubs.
4. Consume it: `Permission::has_permission(User_Model::PERM_NEW_FEATURE)`, and wrap it in an `#[Auth_Check]` helper if a surface needs to gate on it.

## Adding a role

1. Add the constant, maintaining hierarchy order: `const ROLE_SUPERVISOR = 450;` (between Admin 400 and Manager 500).
2. Add the enum entry with `constant`, `label`, `permissions`, `can_admin_roles`.
3. **Update `can_admin_roles` on every role above it** - otherwise no one can administer the new role.
4. Migrate `role_id` data if existing rows must move.
5. `php artisan rsx:constants:regenerate`.

---

## Choosing the layer

- Depends only on WHO the user is, and a surface should be closed to them entirely -> an `#[Auth_Check]` gate (`rspade:auth-gates`).
- Depends only on who the user is, but is asked mid-function about part of a response -> `has_permission()`/`has_role()` inline.
- Depends on WHICH RECORD -> record layer, always: ownership, membership scoping, record state. Never a gate, never an ACL permission.
- A one-off exception for one person -> a `user_permissions` GRANT/DENY row, not a new role.

## Troubleshooting

- **A user has the role but not the permission.** Look for a DENY row - `User_Permission_Model::for_user($user_id)`. DENY beats the role, and a GRANT alongside it does not rescue it.
- **JS and PHP disagree about a permission.** They cannot, if both read the resolved set: `window.rsxapp.user.resolved_permissions` IS `get_resolved_permissions()`. A disagreement means the page was rendered before the change - reload. (A hand-written JS twin of a check is the other cause, and is forbidden.)
- **A new constant is invisible to JavaScript.** Run `php artisan rsx:constants:regenerate`.
- **An admin cannot assign a role they hold themselves.** Correct by construction: `can_admin_roles` lists roles strictly below. Peers and superiors are never administrable.
- **Everything is denied for one user.** `ROLE_DISABLED` short-circuits resolution to an empty set before any grant is considered.

Details: `php artisan rsx:man acls`. Related: `rspade:auth-gates`, `rspade:model-enums`.
