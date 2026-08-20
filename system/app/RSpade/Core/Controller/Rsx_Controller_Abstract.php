<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Controller;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\View;

/**
 * Base controller class for all RSX controllers
 *
 * This extends Laravel's base controller and provides RSX-specific functionality.
 * All RSX controllers should extend this class and use standard OOP patterns.
 * Routes are defined using PHP 8 attributes on the controller and methods.
 */
#[Monoprogenic]
abstract class Rsx_Controller_Abstract extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Pre-dispatch hook called before any action
     * Override in child classes to add pre-action logic
     *
     * @param Request $request The current request
     * @param array $params Combined GET values and URL parameters
     * @return mixed|null Return null to continue, or a response to halt dispatch
     */
    #[Replaceable]
    public static function pre_dispatch(Request $request, array $params = [])
    {
        // Default implementation does nothing
        // Override in child classes to add authentication, logging, etc.
        return null;
    }

    /**
     * Get a parameter value with optional default
     *
     * @param array $params Parameters array
     * @param string $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    protected static function __param($params, $key, $default = null)
    {
        return $params[$key] ?? $default;
    }

    /**
     * Check if a parameter exists
     *
     * @param array $params Parameters array
     * @param string $key Parameter key
     * @return bool
     */
    protected static function __has_param($params, $key)
    {
        return isset($params[$key]);
    }
}
