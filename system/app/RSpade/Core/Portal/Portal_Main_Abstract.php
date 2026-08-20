<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Portal;

use Illuminate\Http\Request;

/**
 * Portal_Main_Abstract - Base class for portal-wide middleware-style hooks
 *
 * Similar to Main_Abstract but specifically for portal requests.
 * Application extends this via /rsx/portal.php as Portal_Main.
 *
 * Portal_Main provides hooks that run BEFORE individual controller hooks:
 * 1. Portal_Main::init() - Called once during portal bootstrap
 * 2. Portal_Main::pre_dispatch() - Called before any portal route dispatch
 * 3. Portal_Main::unhandled_route() - Called when no portal route matches
 */
abstract class Portal_Main_Abstract
{
    /**
     * Initialize the Portal_Main class
     *
     * Called once during portal bootstrap (when a portal request is detected)
     *
     * @return void
     */
    abstract public static function init();

    /**
     * Pre-dispatch hook for portal requests
     *
     * Called before any portal route dispatch. If a non-null value is returned,
     * dispatch is halted and that value is returned as the response.
     *
     * Typical uses:
     * - Require portal authentication
     * - Load portal user data
     * - Set portal-specific headers
     *
     * @param Request $request The current request
     * @param array $params Combined GET values and URL parameters
     * @return mixed|null Return null to continue, or a response to halt dispatch
     */
    abstract public static function pre_dispatch(Request $request, array $params);

    /**
     * Unhandled route hook for portal requests
     *
     * Called when no portal route matches the request
     *
     * @param Request $request The current request
     * @param array $params Combined GET values and URL parameters
     * @return mixed|null Return null for default 404, or a response to handle
     */
    abstract public static function unhandled_route(Request $request, array $params);
}
