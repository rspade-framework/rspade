<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */


namespace Rsx;

use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Permission\Permission_Abstract;

/**
 * Permission helper class for RSX applications - the staff realm's gate vocabulary
 *
 * The ACL facade primitives - get_user(), is_logged_in(), has_permission(),
 * has_role(), can_admin_role(), require_permission(), require_role() - are
 * inherited from Permission_Abstract, along with the built-in gates 'public' and
 * 'is_logged_in'. This class is where the application's own gates live.
 *
 * AUTH GATES. Every dispatchable surface (#[Route], #[SPA], #[Ajax_Endpoint],
 * #[Ajax_Endpoint_Model_Fetch], #[Api_Endpoint], @route JS actions) declares its
 * authorization with #[Auth('check', ...)] / @auth('check', ...). A check name
 * resolves to an #[Auth_Check]-marked public static method below. Checks are
 * parameterless, ': bool', and USER-SCOPED: they answer "may this user use this
 * kind of surface at all". Record-level questions (ownership, membership,
 * record state) stay inside the function body, after the gates pass.
 *
 *   #[Auth('is_logged_in', 'can_manage_users')]
 *
 * See: php artisan rsx:man auth_gates
 *
 * THIS FILE IS THE LIST OF RECORD. The gates below ARE the template app's
 * permission vocabulary - one terse entry per permission constant in real use,
 * one per role floor, plus environment-scoped composites. Add a gate here (a
 * one-line body over the primitives) rather than spelling a role id or a
 * permission constant at a call site; attribute arguments cannot carry class
 * constants, and the helper name is what makes #[Auth] read like the permission
 * model. Keep this list current - it is what an #[Auth] author copies from.
 *
 * Staff gate vocabulary:
 *
 *   public                    (inherited) always true - the explicit open marker
 *   is_logged_in              (inherited) a staff session is present
 *   can_manage_users          PERM_MANAGE_SITE_USERS
 *   can_manage_site_settings  PERM_MANAGE_SITE_SETTINGS
 *   can_manage_billing        PERM_MANAGE_SITE_BILLING
 *   can_view_user_activity    PERM_VIEW_USER_ACTIVITY
 *   can_edit_data             PERM_EDIT_DATA
 *   can_view_data             PERM_VIEW_DATA
 *   can_export_data           PERM_DATA_EXPORT
 *   can_use_api               PERM_API_ACCESS
 *   can_impersonate           ROLE_MANAGER floor ("View as Client")
 *   is_root_admin             ROLE_ROOT_ADMIN floor
 */
class Permission extends Permission_Abstract
{
    /**
     * May manage the site's user accounts, roles, groups and invitations.
     *
     * Wraps User_Model::PERM_MANAGE_SITE_USERS.
     */
    #[Auth_Check]
    public static function can_manage_users(): bool
    {
        return static::has_permission(User_Model::PERM_MANAGE_SITE_USERS);
    }

    /**
     * May change site-wide configuration.
     *
     * Wraps User_Model::PERM_MANAGE_SITE_SETTINGS.
     */
    #[Auth_Check]
    public static function can_manage_site_settings(): bool
    {
        return static::has_permission(User_Model::PERM_MANAGE_SITE_SETTINGS);
    }

    /**
     * May view and manage billing for the site.
     *
     * Wraps User_Model::PERM_MANAGE_SITE_BILLING.
     */
    #[Auth_Check]
    public static function can_manage_billing(): bool
    {
        return static::has_permission(User_Model::PERM_MANAGE_SITE_BILLING);
    }

    /**
     * May read the activity/audit trail of other users.
     *
     * Wraps User_Model::PERM_VIEW_USER_ACTIVITY.
     */
    #[Auth_Check]
    public static function can_view_user_activity(): bool
    {
        return static::has_permission(User_Model::PERM_VIEW_USER_ACTIVITY);
    }

    /**
     * May create, modify and delete application records.
     *
     * Wraps User_Model::PERM_EDIT_DATA.
     */
    #[Auth_Check]
    public static function can_edit_data(): bool
    {
        return static::has_permission(User_Model::PERM_EDIT_DATA);
    }

    /**
     * May read application records.
     *
     * Wraps User_Model::PERM_VIEW_DATA.
     */
    #[Auth_Check]
    public static function can_view_data(): bool
    {
        return static::has_permission(User_Model::PERM_VIEW_DATA);
    }

    /**
     * May export application data (downloads, report extracts).
     *
     * Wraps User_Model::PERM_DATA_EXPORT.
     */
    #[Auth_Check]
    public static function can_export_data(): bool
    {
        return static::has_permission(User_Model::PERM_DATA_EXPORT);
    }

    /**
     * May use the external REST API.
     *
     * Wraps User_Model::PERM_API_ACCESS. DEFINED but deliberately NOT applied to
     * the template's #[Api_Endpoint] surfaces: API keys minted before this gate
     * existed belong to users who may not carry the permission, and gating them
     * on it would silently break live keys. The template's API endpoints ship
     * with 'is_logged_in' (the bearer key establishes a staff identity); an
     * application that mints keys with the permission in mind names this gate
     * on its own endpoints.
     */
    #[Auth_Check]
    public static function can_use_api(): bool
    {
        return static::has_permission(User_Model::PERM_API_ACCESS);
    }

    /**
     * May impersonate a client-portal user ("View as Client").
     *
     * Wraps the User_Model::ROLE_MANAGER floor - the check that used to sit
     * inline at the top of the impersonation endpoint. The read-only nature of
     * the impersonated session is enforced portal-side; this gate only decides
     * WHO may start one.
     */
    #[Auth_Check]
    public static function can_impersonate(): bool
    {
        return static::has_role(User_Model::ROLE_MANAGER);
    }

    /**
     * Cross-site administration: the root console.
     *
     * Wraps the User_Model::ROLE_ROOT_ADMIN floor ("at least" - developers and
     * root admins pass, site owners and below do not).
     */
    #[Auth_Check]
    public static function is_root_admin(): bool
    {
        return static::has_role(User_Model::ROLE_ROOT_ADMIN);
    }
}
