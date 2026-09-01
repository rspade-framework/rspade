<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx;

use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Permission_Abstract;
use App\RSpade\Core\Portal\Portal_Session;
use Rsx\Models\Portal_Membership_Model;

/**
 * Portal_Permission - portal authorization facade for RSX applications.
 *
 * The portal mirror of Permission (rsx/permission.php). It is the single basis for
 * portal authorization: identity, client membership/role, and the accessible-client
 * list used to scope queries.
 *
 * Surface-level authorization is DECLARATIVE: a portal surface carries #[Auth(...)]
 * (or @auth(...) on an action) naming checks from the portal realm's registry -
 * 'public' and 'is_logged_in' come from Portal_Permission_Abstract. See
 * php artisan rsx:man auth_gates.
 *
 * What lives here is the RECORD-level layer the gates deliberately cannot express -
 * parameterized membership predicates, called inline in the endpoint body after the
 * gates have passed:
 *
 *   if (!Portal_Permission::has_client_access($client_id)) return response_unauthorized();
 *   if (!Portal_Permission::can_collaborate($client_id)) return response_unauthorized();
 *
 * Membership methods reference app models (Portal_Membership_Model), so this concrete
 * class lives app-side, exactly like staff Permission extends Permission_Abstract.
 */
class Portal_Permission extends Portal_Permission_Abstract
{
    // =========================================================================
    // IDENTITY (wraps Portal_Session)
    // =========================================================================

    /**
     * Whether the current portal session is read-only.
     *
     * The application's "read-only mode": currently true exactly when the session
     * is a staff impersonation ("View as Client"). Mutating portal endpoints MUST
     * guard on this and refuse writes; the read-only experience (banner, disabled
     * controls) is also the app's responsibility. The framework only exposes the
     * impersonation flag (Portal_Session::is_impersonating()). See rsx:man portal.
     *
     * @return bool
     */
    public static function is_read_only(): bool
    {
        return Portal_Session::is_impersonating();
    }

    /**
     * Current portal user model, or null if not logged in.
     *
     * @return Portal_User_Model|null
     */
    public static function current_user(): ?Portal_User_Model
    {
        return Portal_Session::get_portal_user();
    }

    /**
     * Current portal user ID, or null if not logged in.
     *
     * @return int|null
     */
    public static function current_user_id(): ?int
    {
        return Portal_Session::get_portal_user_id();
    }

    /**
     * The site this portal request serves.
     *
     * Never 0 and never guessed: it is the live session's site, or the site the
     * application declared in Portal_Main::init(). Throws if neither exists (a
     * portal that does not know its tenant is misconfigured, not anonymous).
     *
     * @return int
     */
    public static function site_id(): int
    {
        return Portal_Session::get_site_id();
    }

    // =========================================================================
    // MEMBERSHIP / ROLE (wraps Portal_Membership_Model)
    // =========================================================================

    /**
     * Whether the current portal user has any membership for a client.
     *
     * @param int $client_id
     * @return bool
     */
    public static function has_client_access(int $client_id): bool
    {
        $portal_user_id = Portal_Session::get_portal_user_id();
        if (empty($portal_user_id)) {
            return false;
        }

        return Portal_Membership_Model::has_membership($portal_user_id, $client_id);
    }

    /**
     * The current portal user's role for a client, or null if no access.
     *
     * @param int $client_id
     * @return int|null Portal_Membership_Model::ROLE_*
     */
    public static function client_role(int $client_id): ?int
    {
        $portal_user_id = Portal_Session::get_portal_user_id();
        if (empty($portal_user_id)) {
            return null;
        }

        $membership = Portal_Membership_Model::find_for_user_and_client($portal_user_id, $client_id);
        if (!$membership) {
            return null;
        }

        return (int) $membership->role_id;
    }

    /**
     * Whether the current portal user may collaborate (role >= COLLABORATOR) on a client.
     *
     * @param int $client_id
     * @return bool
     */
    public static function can_collaborate(int $client_id): bool
    {
        $role = static::client_role($client_id);

        return $role !== null && $role >= Portal_Membership_Model::ROLE_COLLABORATOR;
    }

    /**
     * Client IDs the current portal user can access (for whereIn() query scoping).
     *
     * @return array<int>
     */
    public static function accessible_client_ids(): array
    {
        $portal_user_id = Portal_Session::get_portal_user_id();
        if (empty($portal_user_id)) {
            return [];
        }

        return Portal_Membership_Model::get_for_user($portal_user_id)
            ->pluck('client_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
