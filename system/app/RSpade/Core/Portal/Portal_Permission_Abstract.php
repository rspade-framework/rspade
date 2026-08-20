<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Portal;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Portal\Portal_Session;

/**
 * Abstract base class for portal permission classes
 *
 * The portal equivalent of Permission_Abstract, and the root of the PORTAL auth
 * check registry. Portal surfaces (#[Portal_Route], @portal_spa actions,
 * portal_fetch) resolve their #[Auth] check names against this lineage; staff
 * surfaces resolve against Permission. The two registries are separate namespaces
 * and never blur, matching the hard Session/Portal_Session separation.
 *
 * The built-ins below are the portal twins of the staff ones - same names, portal
 * meaning. Realm decides meaning.
 *
 * The concrete Portal_Permission lives app-side (rsx/portal_permission.php) because
 * its membership/role methods reference application models (Portal_Membership_Model).
 * This keeps the framework core free of any core->app dependency, exactly like staff
 * Permission extends this pattern from Permission_Abstract.
 *
 * Example:
 *
 * class Portal_Permission extends Portal_Permission_Abstract
 * {
 *     #[Auth_Check]
 *     public static function can_collaborate_anywhere(): bool
 *     {
 *         return !empty(static::accessible_client_ids());
 *     }
 *
 *     public static function has_client_access(int $client_id): bool
 *     {
 *         return Portal_Membership_Model::has_membership(
 *             Portal_Session::get_portal_user_id(),
 *             $client_id
 *         );
 *     }
 * }
 *
 * Parameterized helpers like has_client_access() are record-scoped and therefore
 * NOT gates - they stay inline in the endpoint body. See rsx:man auth_gates.
 */
abstract class Portal_Permission_Abstract
{
    /**
     * The explicit open marker: always true.
     *
     * The portal twin of Permission::public(). A portal surface that is genuinely
     * public (login, register, invite acceptance) declares #[Auth('public')].
     *
     * @return bool Always true
     */
    #[Auth_Check]
    public static function public(): bool
    {
        return true;
    }

    /**
     * Whether a portal user is currently authenticated.
     *
     * Reads Portal_Session - never the staff Session. Marked #[Replaceable]: an
     * application that redefines its realm identity predicate replaces this body
     * outright rather than extending it.
     *
     * @return bool
     */
    #[Auth_Check]
    #[Replaceable]
    public static function is_logged_in(): bool
    {
        return Portal_Session::is_logged_in();
    }

    /**
     * Whether every gate declared on a TARGET portal surface passes for the
     * current portal user.
     *
     * The portal twin of Permission::can_access(); same target spellings, resolved
     * against the portal realm.
     *
     * @param string $target
     * @return bool
     * @throws \RuntimeException When the target is not a known portal surface
     */
    public static function can_access(string $target): bool
    {
        return Auth_Gates::can_access($target, Auth_Gates::REALM_PORTAL);
    }
}
