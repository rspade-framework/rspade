/**
 * Portal_Permission - Client-side portal permission checking
 *
 * The portal equivalent of Permission. Provides identity and membership/role
 * checks for portal JavaScript using a pre-resolved portal block from
 * window.rsxapp.portal, assembled server-side.
 *
 * IMPORTANT: This is for HIDING UI AFFORDANCES ONLY. The server is the
 * enforcement boundary - every portal-visible record is gated server-side via
 * portal_fetch()/portal_can_read() and every portal endpoint via a server auth
 * check. Never rely on these client checks for security.
 *
 * window.rsxapp.portal shape:
 *   {
 *     is_auth: true,
 *     user: { id, email, ... },          // current portal user (safe fields)
 *     client_roles: { "<client_id>": <role_id>, ... }  // accessible clients -> role
 *   }
 *
 * Roles mirror Portal_Membership_Model:
 *   ROLE_VIEWER = 1, ROLE_COLLABORATOR = 2 (collaborate => role >= COLLABORATOR)
 *
 * Usage:
 *   if (Portal_Permission.is_logged_in()) { ... }
 *   if (Portal_Permission.has_client_access(client_id)) { ... }
 *   if (Portal_Permission.can_collaborate(client_id)) { ... }
 */
class Portal_Permission {

    static ROLE_VIEWER = 1;
    static ROLE_COLLABORATOR = 2;

    /**
     * Check if a portal user is logged in
     *
     * @returns {boolean}
     */
    static is_logged_in() {
        return window.rsxapp?.portal?.is_auth === true;
    }

    /**
     * Get current portal user object or null
     *
     * @returns {Object|null}
     */
    static current_user() {
        return window.rsxapp?.portal?.user ?? null;
    }

    /**
     * Check if the current portal user has access to a client
     *
     * @param {number} client_id
     * @returns {boolean}
     */
    static has_client_access(client_id) {
        const roles = window.rsxapp?.portal?.client_roles ?? {};
        return Object.prototype.hasOwnProperty.call(roles, String(client_id));
    }

    /**
     * Get the current portal user's role for a client, or null if no access
     *
     * @param {number} client_id
     * @returns {number|null}
     */
    static client_role(client_id) {
        const roles = window.rsxapp?.portal?.client_roles ?? {};
        const role = roles[String(client_id)];
        return isset(role) ? int(role) : null;
    }

    /**
     * Check if the current portal user may collaborate (role >= COLLABORATOR) on a client
     *
     * @param {number} client_id
     * @returns {boolean}
     */
    static can_collaborate(client_id) {
        const role = Portal_Permission.client_role(client_id);
        return role !== null && role >= Portal_Permission.ROLE_COLLABORATOR;
    }

    /**
     * Whether every gate declared on a TARGET portal surface passes for the current
     * portal user
     *
     * The JS twin of PHP Portal_Permission::can_access(), and the portal spelling of
     * the same call. On the SERVER the two twins resolve names against separate
     * realm registries; on the CLIENT there is only ever one realm in play - the
     * page's - and window.rsxapp.auth already carries that realm's grants. So the
     * portal twin delegates to the single implementation rather than duplicating it
     * (dual implementations drift).
     *
     * @param {string} target - Portal SPA action class name or 'Controller::method'
     * @returns {boolean}
     */
    static can_access(target) {
        return Permission.can_access(target);
    }
}
