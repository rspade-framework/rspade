/**
 * Permission - Client-side permission checking
 *
 * Provides permission and role checking for JavaScript using pre-resolved
 * permissions from window.rsxapp.user.resolved_permissions. This mirrors the
 * PHP Permission class functionality for client-side UI logic.
 *
 * The resolved_permissions array is computed server-side via User_Model::toArray()
 * by applying:
 * 1. Role default permissions
 * 2. Supplementary GRANTs (added)
 * 3. Supplementary DENYs (removed)
 *
 * This ensures JS permission checks match PHP exactly.
 *
 * Usage:
 *   // Check authentication
 *   if (Permission.is_logged_in()) { ... }
 *
 *   // Check specific permission
 *   if (Permission.has_permission(User_Model.PERM_EDIT_DATA)) { ... }
 *
 *   // Check multiple permissions
 *   if (Permission.has_any_permission([User_Model.PERM_EDIT_DATA, User_Model.PERM_VIEW_DATA])) { ... }
 *   if (Permission.has_all_permissions([User_Model.PERM_MANAGE_SITE_USERS, User_Model.PERM_VIEW_USER_ACTIVITY])) { ... }
 *
 *   // Check role level
 *   if (Permission.has_role(User_Model.ROLE_MANAGER)) { ... }
 *
 *   // Check if can admin a role (for UI showing role assignment options)
 *   if (Permission.can_admin_role(User_Model.ROLE_USER)) { ... }
 */
class Permission {

    /**
     * Check if user is logged in
     *
     * @returns {boolean}
     */
    static is_logged_in() {
        return window.rsxapp?.is_auth === true;
    }

    /**
     * Get current user object or null
     *
     * @returns {Object|null}
     */
    static get_user() {
        return window.rsxapp?.user ?? null;
    }

    /**
     * Check if current user has a specific permission
     *
     * @param {number} permission - Permission constant (e.g., User_Model.PERM_EDIT_DATA)
     * @returns {boolean}
     */
    static has_permission(permission) {
        const permissions = window.rsxapp?.user?.resolved_permissions ?? [];
        return permissions.includes(permission);
    }

    /**
     * Check if current user has ANY of the specified permissions
     *
     * @param {number[]} permissions - Array of permission constants
     * @returns {boolean}
     */
    static has_any_permission(permissions) {
        const resolved = window.rsxapp?.user?.resolved_permissions ?? [];
        return permissions.some(p => resolved.includes(p));
    }

    /**
     * Check if current user has ALL of the specified permissions
     *
     * @param {number[]} permissions - Array of permission constants
     * @returns {boolean}
     */
    static has_all_permissions(permissions) {
        const resolved = window.rsxapp?.user?.resolved_permissions ?? [];
        return permissions.every(p => resolved.includes(p));
    }

    /**
     * Check if current user has at least the specified role level
     *
     * "At least" means same or higher privilege (lower role_id number).
     * Example: has_role(ROLE_MANAGER) returns true for Site Admins and above.
     *
     * @param {number} role_id - Role constant (e.g., User_Model.ROLE_MANAGER)
     * @returns {boolean}
     */
    static has_role(role_id) {
        const user = window.rsxapp?.user;
        if (!user) {
            return false;
        }
        // Lower role_id = higher privilege
        return user.role_id <= role_id;
    }

    /**
     * Check if current user can administer users with the given role
     *
     * Prevents privilege escalation - users can only assign roles
     * at or below their own permission level.
     *
     * @param {number} role_id - Role constant (e.g., User_Model.ROLE_USER)
     * @returns {boolean}
     */
    static can_admin_role(role_id) {
        const user = window.rsxapp?.user;
        if (!user) {
            return false;
        }
        const can_admin = user.role_id__can_admin_roles ?? [];
        return can_admin.includes(role_id);
    }

    /**
     * Get all resolved permissions for current user
     *
     * @returns {number[]} Array of permission IDs
     */
    static get_resolved_permissions() {
        return window.rsxapp?.user?.resolved_permissions ?? [];
    }

    /**
     * Whether every gate declared on a TARGET surface passes for the current user
     *
     * The JS twin of PHP Permission::can_access(). Takes the same spellings
     * Rsx.Route() takes: a SPA action class name, or 'Controller::method' for a PHP
     * route (a bare controller name implies '::index'). NO route params, ever -
     * gates are user-scoped, so a surface is reachable or not regardless of which
     * record's URL you build.
     *
     * This is the one call every conditional link makes:
     *
     *   <% if (Permission.can_access('Contacts_View_Action')) { %> ... <% } %>
     *
     * Resolution:
     *   SPA action target - the @auth check list is compiled into the bundle, so it
     *     is read off the action class and compared to window.rsxapp.auth. Free, no
     *     export required.
     *   PHP route target  - needs the opt-in window.rsxapp.auth_routes export
     *     (config rsx.auth.export_php_route_grants). Disabled by default; when it is
     *     off this logs a console.error naming the setting and returns false.
     *
     * Unknown target returns false: unresolvable and ungranted are one thing on the
     * client, which is what preserves the grants-only property (PHP throws instead,
     * because there a typo must never silently hide a link).
     *
     * REALM: the grant map always carries the ACTIVE realm's grants, so this one
     * implementation serves staff and portal pages; Portal_Permission.can_access()
     * delegates here. See rsx:man auth_gates.
     *
     * @param {string} target - SPA action class name or 'Controller::method'
     * @returns {boolean}
     */
    static can_access(target) {
        let resolved = target;

        if (!resolved.includes('::')) {
            const action_class = Manifest.get_class_by_name(resolved);

            if (action_class) {
                // An action with no @auth passes: closed-by-default enforcement
                // arrives with the annotation pass, and until then an un-gated
                // action is reachable.
                const checks = action_class._auth_checks || [];
                const grants = window.rsxapp.auth || {};
                return checks.every((check_name) => grants[check_name] === 1);
            }

            // Not a known JS class - a bare controller name implies '::index',
            // exactly like Rsx.Route(). Resolve it as a PHP route target.
            resolved = resolved + '::index';
        }

        const routes = window.rsxapp.auth_routes;

        if (!routes) {
            console.error(
                `[Auth] Permission.can_access('${target}'): PHP-route accessibility cannot be ` +
                'checked client-side because rsx.auth.export_php_route_grants is disabled in ' +
                'config/rsx.php, so window.rsxapp.auth_routes was not shipped. Enable that ' +
                'setting to gate links to PHP-routed pages, or target a SPA action instead. ' +
                'Returning false.'
            );
            return false;
        }

        return routes[resolved] === 1;
    }
}
