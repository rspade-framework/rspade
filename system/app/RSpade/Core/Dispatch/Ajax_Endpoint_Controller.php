<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Dispatch;

use Exception;
use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Internal API route handler
 *
 * Thin wrapper that routes /_ajax/:controller/:action requests to the Ajax class.
 * The actual logic is consolidated in Ajax::handle_browser_request() for better organization.
 */
class Ajax_Endpoint_Controller extends Rsx_Controller_Abstract
{
    /**
     * Handle internal API requests
     *
     * Routes /_ajax/:controller/:action to Ajax::handle_browser_request()
     *
     * ONE HANDLER, TWO CHANNELS. The #[Route] serves the staff channel; the
     * #[Portal_Route] serves the PORTAL channel, which is the same path under the
     * portal's own base (<portal-prefix>/_ajax/... in prefix mode, /_ajax/... on the
     * portal domain). That is what makes a portal page's Ajax a genuine portal
     * request: Rsx_Portal::is_portal_request() is true, so CSRF verifies against the
     * portal session, Auth_Gates::active_realm() is 'portal', and the ORM resolves
     * portal_fetch(). Without it, portal Ajax rode the staff dispatcher and was
     * evaluated in the staff realm. See: php artisan rsx:man portal.
     *
     * Gate is 'public': this is the transport route, and authorization happens one
     * level down - the Ajax seam evaluates the TARGET endpoint's own realm and
     * #[Auth] gates before its body runs.
     */
    #[Route('/_ajax/:controller/:action', methods: ['POST'])]
    #[Portal_Route('/_ajax/:controller/:action', methods: ['POST'])]
    #[Auth('public')]
    public static function dispatch(Request $request, array $params = [])
    {
        // Delegate all logic to the consolidated Ajax class
        return Ajax::handle_browser_request($request, $params);
    }

    /**
     * Set AJAX response mode (backward compatibility)
     * @deprecated Use Ajax::set_ajax_response_mode() instead
     */
    public static function set_ajax_response_mode(bool $enabled): void
    {
        Ajax::set_ajax_response_mode($enabled);
    }

    /**
     * Check if AJAX response mode is enabled (backward compatibility)
     * @deprecated Use Ajax::is_ajax_response_mode() instead
     */
    public static function is_ajax_response_mode(): bool
    {
        return Ajax::is_ajax_response_mode();
    }

}
