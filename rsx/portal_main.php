<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx;

use Illuminate\Http\Request;
use App\RSpade\Core\Portal\Portal_Main_Abstract;
use App\RSpade\Core\Portal\Portal_Session;
use Rsx\Models\Client_Model;
use Rsx\Portal_Permission;

/**
 * Portal_Main - Portal-wide middleware hooks
 *
 * Provides portal-wide initialization and request lifecycle hooks.
 * This is the portal equivalent of Main (main.php).
 *
 * Hook execution order:
 * 1. Portal_Main::init() - Called once during portal bootstrap
 * 2. Portal_Main::pre_dispatch() - Called before every portal route
 * 3. Controller::pre_dispatch() - Called if controller has pre_dispatch
 * 4. Controller::action() - The actual route handler
 */
class Portal_Main extends Portal_Main_Abstract
{
    /**
     * Initialize the Portal_Main class
     *
     * Called once during portal bootstrap when a portal request is detected -
     * before dev auth, CSRF, the #[Auth] gates and every controller. That is why
     * the portal's SITE is declared here: it is the earliest application code in a
     * portal request, and everything downstream (session creation, flash alerts,
     * the rsxapp payload, Portal_Permission) needs the answer.
     *
     * @return void
     */
    public static function init()
    {
        // WHICH SITE DOES THIS PORTAL SERVE?
        //
        // The framework does not resolve this - there is no detection and no
        // default (see rsx:man portal, PORTAL SESSIONS). Every app answers it its
        // own way; this app is MONO-SITE, so the answer is a config key.
        Portal_Session::set_site_id((int) config('rsx.portal.site_id'));

        // A MULTI-TENANT portal would resolve the site from the request instead -
        // same call, different source:
        //
        //     $site = Site_Model::where('portal_domain', Rsx::get_hostname())->first();
        //     if (!$site) {
        //         return response('Unknown portal host', 404);   // via pre_dispatch
        //     }
        //     Portal_Session::set_site_id((int) $site->id);
        //
        // An app whose site is only knowable at login (an invite code, a chosen
        // workspace) declares it in the login flow instead of here; the rule is
        // only that it is declared before anything asks.

        // Initialize portal session
        Portal_Session::init();

        console_debug('PORTAL', 'Portal_Main::init() called');
    }

    /**
     * Pre-dispatch hook for portal requests
     *
     * Called before any portal route dispatch, AFTER the dispatcher has evaluated the
     * route's declarative #[Auth] gates - so authorization is never done here. Common
     * uses: load portal-specific data, set portal-specific response headers,
     * interstitials. See: php artisan rsx:man auth_gates.
     *
     * @param Request $request The current request
     * @param array $params Combined GET values and URL parameters
     * @return mixed|null Return null to continue, or a response to halt dispatch
     */
    public static function pre_dispatch(Request $request, array $params)
    {
        // Stamp client portal activity (resolves framework essential #12 and the
        // previously-dead clients.portal_last_activity_at column). Marks "now" on each
        // client the current portal user is a member of; an anonymous request (a public
        // page) has no memberships and does no work. clients is an APP table, so this
        // lives app-side; the core notification primitive stays clean.
        static::__stamp_portal_activity();

        return null;
    }

    /**
     * Mark portal activity on every client the current portal user belongs to.
     *
     * One bulk UPDATE keyed on the member client ids - no per-row save, and no
     * query at all when the user has no memberships (a base-account user just
     * viewing shared items). Site scoping is handled by the model's global scope.
     *
     * @return void
     */
    private static function __stamp_portal_activity(): void
    {
        $client_ids = Portal_Permission::accessible_client_ids();

        if (empty($client_ids)) {
            return;
        }

        // raw_bulk(): this stamp runs on EVERY portal request - one raw UPDATE statement,
        // no per-record side effects. Emitting a Client_Model change (or running per-record
        // hooks) for a low-value timestamp bump would churn every open staff client view on
        // every portal click. Deliberate suppression.
        Client_Model::whereIn('id', $client_ids)
            ->raw_bulk()
            ->update(['portal_last_activity_at' => now()]);
    }

    /**
     * Unhandled route hook for portal requests
     *
     * Called when no portal route matches the request.
     * Use this for custom 404 handling in the portal context.
     *
     * @param Request $request The current request
     * @param array $params Combined GET values and URL parameters
     * @return mixed|null Return null for default 404, or a response to handle
     */
    public static function unhandled_route(Request $request, array $params)
    {
        // Default: return null to use standard 404 handling
        // Can be customized to return a portal-specific 404 page
        return null;
    }
}
