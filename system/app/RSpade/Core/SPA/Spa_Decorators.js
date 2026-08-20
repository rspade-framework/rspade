/**
 * Spa Decorator Functions
 *
 * These decorators are used on Spa Action classes to define their route patterns,
 * layouts, and associated PHP controller methods.
 *
 * Decorators store metadata as static properties on the class, which Spa.js
 * reads during initialization to register routes.
 */

/**
 * @decorator
 * Define route pattern(s) for this action
 *
 * Usage:
 *   @route('/contacts')
 *   @route('/contacts/index')
 *   class Contacts_Index_Action extends Spa_Action { }
 */
function route(pattern) {
    return function (target) {
        // Store route pattern on the class
        if (!target._spa_routes) {
            target._spa_routes = [];
        }
        target._spa_routes.push(pattern);
        return target;
    };
}

/**
 * @decorator
 * Define which layout(s) this action renders within
 *
 * Multiple @layout decorators create a chain of nested layouts (sublayouts).
 * First decorator = outermost layout, subsequent = progressively nested.
 * Each layout persists independently during navigation if unchanged.
 *
 * Usage:
 *   @layout('Frontend_Layout')           // Outermost (header/footer)
 *   @layout('Settings_Layout')           // Nested inside Frontend_Layout
 *   class Settings_Profile_Action extends Spa_Action { }
 */
function layout(layout_name) {
    return function (target) {
        // Store layout names as array for sublayout chain support
        // Use unshift because decorators execute bottom-up, so we prepend
        // each layout to get correct order (outermost first in array)
        if (!target._spa_layouts) {
            target._spa_layouts = [];
        }
        target._spa_layouts.unshift(layout_name);
        return target;
    };
}

/**
 * @decorator
 * Link this Spa action to its PHP controller method
 * Used for generating PHP URLs and understanding the relationship
 *
 * Usage:
 *   @spa('Frontend_Contacts_Controller::index')
 *   class Contacts_Index_Action extends Spa_Action { }
 */
function spa(controller_method) {
    return function (target) {
        // Store controller::method reference on the class
        target._spa_controller_method = controller_method;
        return target;
    };
}

/**
 * @decorator
 * Define the browser page title for this action
 *
 * Usage:
 *   @title('Contacts - RSX')
 *   class Contacts_Index_Action extends Spa_Action { }
 */
function title(page_title) {
    return function (target) {
        // Store page title on the class
        target._spa_title = page_title;
        return target;
    };
}

/**
 * @decorator
 * Declare the authorization gates this action requires
 *
 * ONE decorator, variadic check names, AND semantics: every named check must be
 * granted or Spa.dispatch refuses to construct the action. Names resolve to
 * #[Auth_Check]-marked methods on the realm's Permission class - identical
 * spelling to the PHP #[Auth(...)] attribute - and are answered client-side from
 * the render-time grant map (window.rsxapp.auth).
 *
 * CLIENT GATES ARE UX, NOT SECURITY: the server runs the same named gates on
 * every Ajax/ORM/API call this action makes. See rsx:man auth_gates.
 *
 * Usage:
 *   @auth('is_logged_in', 'can_manage_users')
 *   class Users_Index_Action extends Spa_Action { }
 */
function auth(...check_names) {
    return function (target) {
        // Store check names as an array, order preserved, duplicates dropped
        if (!target._auth_checks) {
            target._auth_checks = [];
        }
        for (const check_name of check_names) {
            if (!target._auth_checks.includes(check_name)) {
                target._auth_checks.push(check_name);
            }
        }
        return target;
    };
}

/**
 * @decorator
 * Link a Portal Spa action to its PHP controller method
 * Used for portal routes which are handled by Portal_Spa_ManifestSupport
 *
 * Usage:
 *   @portal_spa('Portal_Spa_Controller::index')
 *   class Portal_Dashboard_Action extends Spa_Action { }
 */
function portal_spa(controller_method) {
    return function (target) {
        // Store controller::method reference on the class
        target._spa_controller_method = controller_method;
        // Mark as portal SPA action
        target._is_portal_spa = true;
        return target;
    };
}
