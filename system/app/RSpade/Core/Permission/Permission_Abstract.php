<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Permission;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;

/**
 * Abstract base class for permission classes
 *
 * Provides the shared Permission facade that every RSX application inherits for
 * free. Applications extend this class (as `Rsx\Permission`) and add their own
 * domain-specific helpers (e.g. can_manage_billing(), is_site_owner()) on top of
 * the inherited primitives.
 *
 * Inherited facade methods:
 * - get_user(): current User_Model or null
 * - is_logged_in(): whether a user is authenticated
 * - has_permission(int): check a permission constant (User_Model::PERM_*)
 * - has_role(int): "at least" role check (User_Model::ROLE_*)
 * - can_admin_role(int): privilege-escalation guard
 * - require_permission(int, string): throw AjaxUnauthorizedException if missing
 * - require_role(int, string): throw AjaxUnauthorizedException if missing
 *
 * Use these helpers in your controller auth checks:
 *
 *   if (!Session::is_logged_in()) return response_unauthorized();
 *   if (!Permission::has_permission(User_Model::PERM_EDIT_DATA)) return response_unauthorized();
 *   if (!Permission::has_role(User_Model::ROLE_MANAGER)) return response_unauthorized();
 *
 * Or fail loud from an Ajax endpoint:
 *
 *   Permission::require_role(User_Model::ROLE_MANAGER);
 *
 * AUTH GATES. A method marked #[Auth_Check] is additionally usable as a declarative
 * gate: #[Auth('can_manage_users')] on a route/endpoint, @auth('can_manage_users')
 * on a SPA action. A gate is parameterless, ': bool', and user-scoped; the
 * parameterized primitives above are the building blocks gates are made of and are
 * deliberately NOT marked. See php artisan rsx:man auth_gates.
 */
abstract class Permission_Abstract
{
    /**
     * Get the current logged-in user's site membership (User_Model)
     *
     * @return User_Model|null User model or null if not logged in
     */
    public static function get_user(): ?User_Model
    {
        return Session::get_user();
    }

    /**
     * The explicit open marker: always true.
     *
     * There is no attribute-free spelling of "open" - a public surface declares
     * #[Auth('public')] so reviewing a controller never requires distinguishing
     * "intentionally public" from "someone forgot".
     *
     * @return bool Always true
     */
    #[Auth_Check]
    public static function public(): bool
    {
        return true;
    }

    /**
     * Check if a user is currently authenticated
     *
     * Marked #[Replaceable]: an application that redefines its realm identity
     * predicate replaces this body outright rather than extending it.
     *
     * @return bool True if a user is logged in, false otherwise
     */
    #[Auth_Check]
    #[Replaceable]
    public static function is_logged_in(): bool
    {
        return Session::is_logged_in();
    }

    /**
     * Whether every gate declared on a TARGET surface passes for the current user.
     *
     * Targets use the spellings Rsx::Route() takes: 'Controller::method' (a bare
     * 'Controller' implies '::index') or a SPA action class name. It takes NO route
     * params, ever - gates are user-scoped, so a surface is reachable or not
     * regardless of which record's URL you build.
     *
     * This is the one call every conditional link makes, so authorization for a
     * destination is declared ONCE, on the destination.
     *
     * Not itself an #[Auth_Check]: it takes an argument, and it answers a question
     * about a surface rather than about the user.
     *
     * @param string $target
     * @return bool
     * @throws \RuntimeException When the target is not a known staff surface
     */
    public static function can_access(string $target): bool
    {
        return Auth_Gates::can_access($target, Auth_Gates::REALM_STAFF);
    }

    /**
     * Check if current logged-in user has a specific permission
     *
     * Resolution order:
     * 1. DISABLED role = deny all
     * 2. Supplementary DENY = deny
     * 3. Supplementary GRANT = grant
     * 4. Role default permissions = grant if included
     * 5. Deny
     *
     * @param int $permission Permission constant (User_Model::PERM_*)
     * @return bool True if user has permission, false otherwise
     */
    public static function has_permission(int $permission): bool
    {
        $user = Session::get_user();
        if (!$user) {
            return false;
        }
        return $user->has_permission($permission);
    }

    /**
     * Check if current logged-in user has at least the specified role level
     *
     * "At least" means same or higher privilege (lower role_id number).
     * Example: has_role(ROLE_MANAGER) returns true for Site Admins and above.
     *
     * @param int $role_id Role constant (User_Model::ROLE_*)
     * @return bool True if user has role or higher, false otherwise
     */
    public static function has_role(int $role_id): bool
    {
        $user = Session::get_user();
        if (!$user) {
            return false;
        }
        return $user->has_role($role_id);
    }

    /**
     * Check if current user can administer users with the given role
     *
     * Prevents privilege escalation - users can only assign roles
     * at or below their own permission level.
     *
     * @param int $role_id Role constant (User_Model::ROLE_*)
     * @return bool True if user can admin this role, false otherwise
     */
    public static function can_admin_role(int $role_id): bool
    {
        $user = Session::get_user();
        if (!$user) {
            return false;
        }
        return $user->can_admin_role($role_id);
    }

    /**
     * Require a specific permission, throwing if the current user lacks it
     *
     * Fail-loud guard for Ajax endpoints. Throws AjaxUnauthorizedException
     * (403) when the check fails; returns void when it passes.
     *
     * @param int $permission Permission constant (User_Model::PERM_*)
     * @param string $message Optional message for the thrown exception
     * @throws \App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException
     */
    public static function require_permission(int $permission, string $message = 'Unauthorized'): void
    {
        if (!static::has_permission($permission)) {
            throw new \App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException($message);
        }
    }

    /**
     * Require at least the specified role, throwing if the current user lacks it
     *
     * Fail-loud guard for Ajax endpoints. Throws AjaxUnauthorizedException
     * (403) when the check fails; returns void when it passes.
     *
     * @param int $role_id Role constant (User_Model::ROLE_*)
     * @param string $message Optional message for the thrown exception
     * @throws \App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException
     */
    public static function require_role(int $role_id, string $message = 'Unauthorized'): void
    {
        if (!static::has_role($role_id)) {
            throw new \App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException($message);
        }
    }
}
