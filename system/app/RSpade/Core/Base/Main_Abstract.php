<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Base;

use Illuminate\Http\Request;

/**
 * Main_Abstract - Base class for application-wide middleware-style hooks
 * 
 * Classes extending this abstract can define application-wide behaviors
 * that execute at key points in the request lifecycle.
 */
abstract class Main_Abstract
{
    /**
     * Initialize the Main class
     * 
     * Called once during application bootstrap
     * 
     * @return void
     */
    abstract public static function init();
    
    /**
     * Pre-dispatch hook
     * 
     * Called before any route dispatch. If a non-null value is returned,
     * dispatch is halted and that value is returned as the response.
     * 
     * @param Request $request The current request
     * @param array $params Combined GET values and URL parameters
     * @return mixed|null Return null to continue, or a response to halt dispatch
     */
    abstract public static function pre_dispatch(Request $request, array $params);
    
    /**
     * Unhandled route hook
     * 
     * Called when no route matches the request
     * 
     * @param Request $request The current request
     * @param array $params Combined GET values and URL parameters
     * @return mixed|null Return null for default 404, or a response to handle
     */
    abstract public static function unhandled_route(Request $request, array $params);
}