<?php

namespace App\RSpade\Core\Debug;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Debug\Debugger;

/**
 * Debugger_Controller - API endpoints for debugging features
 *
 * Handles AJAX requests from JavaScript for console_debug and error logging.
 * Delegates to the Debugger utility class for actual implementation.
 *
 * AUTHORIZATION: the class gate is 'public' - this is a dev tool for logging
 * client-side debug output, and it must work in unauthenticated states (login-page
 * debugging is exactly when it is needed). What actually restricts it is the
 * Debugger config flags checked inside the delegate: that is ENVIRONMENT gating
 * (is this install collecting client debug output at all), not user authorization,
 * so it stays inline and is not expressible as a gate.
 */
#[Auth('public')]
#[Auth_Realm('any')]
class Debugger_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint to log console_debug messages from JavaScript
     *
     * @param \Illuminate\Http\Request $request
     * @param array $params
     */
    #[Ajax_Endpoint]
    public static function log_console_messages(Request $request, array $params = [])
    {
        return Debugger::log_console_messages($request, $params);
    }

    /**
     * Ajax endpoint to log browser errors from JavaScript
     *
     * @param \Illuminate\Http\Request $request
     * @param array $params
     */
    #[Ajax_Endpoint]
    public static function log_browser_errors(Request $request, array $params = [])
    {
        return Debugger::log_browser_errors($request, $params);
    }
}