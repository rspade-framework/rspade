<?php

namespace App\Routing;

use Illuminate\Routing\Router;
use RuntimeException;

/**
 * Restricted Router that prevents resource routes and non-GET/POST methods
 * 
 * This router enforces RSpade's routing restrictions:
 * - No resource routes (they encourage REST patterns we want to avoid)
 * - Only GET and POST methods allowed (keeps forms and APIs simple)
 */
class RestrictedRouter extends Router
{
    /**
     * Allowed HTTP methods in RSpade
     */
    protected const ALLOWED_METHODS = ['GET', 'POST'];

    public function __construct($events, $container = null)
    {
        parent::__construct($events, $container);
    }
    
    /**
     * Override resource registration to throw exception
     *
     * @param string $name
     * @param string $controller
     * @param array $options
     * @throws RuntimeException
     */
    public function resource($name, $controller, array $options = [])
    {
        throw new RuntimeException(
            "Resource routes are not allowed in the RSpade framework.\n" .
            "Resource route attempted: Route::resource('{$name}', '{$controller}')\n\n" .
            "Instead, define explicit routes:\n" .
            "  Route::get('/{$name}', [{$controller}::class, 'index']);\n" .
            "  Route::get('/{$name}/create', [{$controller}::class, 'create']);\n" .
            "  Route::post('/{$name}', [{$controller}::class, 'store']);\n" .
            "  Route::get('/{$name}/{id}', [{$controller}::class, 'show']);\n" .
            "  Route::get('/{$name}/{id}/edit', [{$controller}::class, 'edit']);\n" .
            "  Route::post('/{$name}/{id}', [{$controller}::class, 'update']);\n" .
            "  Route::post('/{$name}/{id}/delete', [{$controller}::class, 'destroy']);\n\n" .
            "Note: Only GET and POST methods are allowed in RSpade."
        );
    }
    
    /**
     * Override apiResource registration to throw exception
     *
     * @param string $name
     * @param string $controller
     * @param array $options
     * @throws RuntimeException
     */
    public function apiResource($name, $controller, array $options = [])
    {
        throw new RuntimeException(
            "API resource routes are not allowed in the RSpade framework.\n" .
            "API resource route attempted: Route::apiResource('{$name}', '{$controller}')\n\n" .
            "Instead, define explicit routes:\n" .
            "  Route::get('/api/{$name}', [{$controller}::class, 'index']);\n" .
            "  Route::post('/api/{$name}', [{$controller}::class, 'store']);\n" .
            "  Route::get('/api/{$name}/{id}', [{$controller}::class, 'show']);\n" .
            "  Route::post('/api/{$name}/{id}', [{$controller}::class, 'update']);\n" .
            "  Route::post('/api/{$name}/{id}/delete', [{$controller}::class, 'destroy']);\n\n" .
            "Note: Only GET and POST methods are allowed in RSpade."
        );
    }
    
    /**
     * Override resources registration to throw exception
     *
     * @param array $resources
     * @param array $options
     * @throws RuntimeException
     */
    public function resources(array $resources, array $options = [])
    {
        throw new RuntimeException(
            "Resource routes are not allowed in the RSpade framework.\n" .
            "Multiple resource routes attempted via Route::resources()\n\n" .
            "Define explicit GET and POST routes instead."
        );
    }
    
    /**
     * Override apiResources registration to throw exception
     *
     * @param array $resources
     * @param array $options
     * @throws RuntimeException
     */
    public function apiResources(array $resources, array $options = [])
    {
        throw new RuntimeException(
            "API resource routes are not allowed in the RSpade framework.\n" .
            "Multiple API resource routes attempted via Route::apiResources()\n\n" .
            "Define explicit GET and POST routes instead."
        );
    }
    
    /**
     * Override singleton resource registration to throw exception
     *
     * @param string $name
     * @param string $controller
     * @param array $options
     * @throws RuntimeException
     */
    public function singleton($name, $controller, array $options = [])
    {
        throw new RuntimeException(
            "Singleton resource routes are not allowed in the RSpade framework.\n" .
            "Singleton route attempted: Route::singleton('{$name}', '{$controller}')\n\n" .
            "Define explicit GET and POST routes instead."
        );
    }
    
    /**
     * Override singletons registration to throw exception
     *
     * @param array $singletons
     * @param array $options
     * @throws RuntimeException
     */
    public function singletons(array $singletons, array $options = [])
    {
        throw new RuntimeException(
            "Singleton resource routes are not allowed in the RSpade framework.\n" .
            "Multiple singleton routes attempted via Route::singletons()\n\n" .
            "Define explicit GET and POST routes instead."
        );
    }
    
    /**
     * Override PUT method to throw exception
     *
     * @param string $uri
     * @param mixed $action
     * @throws RuntimeException
     */
    public function put($uri, $action = null)
    {
        throw new RuntimeException(
            "PUT method is not allowed in the RSpade framework.\n" .
            "Route attempted: Route::put('{$uri}', ...)\n\n" .
            "Use POST instead:\n" .
            "  Route::post('{$uri}', ...)\n\n" .
            "Only GET and POST methods are allowed."
        );
    }
    
    /**
     * Override PATCH method to throw exception
     *
     * @param string $uri
     * @param mixed $action
     * @throws RuntimeException
     */
    public function patch($uri, $action = null)
    {
        throw new RuntimeException(
            "PATCH method is not allowed in the RSpade framework.\n" .
            "Route attempted: Route::patch('{$uri}', ...)\n\n" .
            "Use POST instead:\n" .
            "  Route::post('{$uri}', ...)\n\n" .
            "Only GET and POST methods are allowed."
        );
    }
    
    /**
     * Override DELETE method to throw exception
     *
     * @param string $uri
     * @param mixed $action
     * @throws RuntimeException
     */
    public function delete($uri, $action = null)
    {
        throw new RuntimeException(
            "DELETE method is not allowed in the RSpade framework.\n" .
            "Route attempted: Route::delete('{$uri}', ...)\n\n" .
            "Use POST with a delete action instead:\n" .
            "  Route::post('{$uri}/delete', ...)\n\n" .
            "Only GET and POST methods are allowed."
        );
    }
    
    /**
     * Override OPTIONS method to throw exception
     *
     * @param string $uri
     * @param mixed $action
     * @throws RuntimeException
     */
    public function options($uri, $action = null)
    {
        throw new RuntimeException(
            "OPTIONS method is not allowed in the RSpade framework.\n" .
            "Route attempted: Route::options('{$uri}', ...)\n\n" .
            "Only GET and POST methods are allowed."
        );
    }
    
    /**
     * Override ANY method to throw exception
     *
     * @param string $uri
     * @param mixed $action
     * @throws RuntimeException
     */
    public function any($uri, $action = null)
    {
        throw new RuntimeException(
            "Route::any() is not allowed in the RSpade framework.\n" .
            "Route attempted: Route::any('{$uri}', ...)\n\n" .
            "Define explicit routes instead:\n" .
            "  Route::get('{$uri}', ...);\n" .
            "  Route::post('{$uri}', ...);\n\n" .
            "Only GET and POST methods are allowed."
        );
    }
    
    /**
     * Override MATCH method to allow only GET/POST combinations
     *
     * @param array|string $methods
     * @param string $uri
     * @param mixed $action
     * @throws RuntimeException
     */
    public function match($methods, $uri, $action = null)
    {
        $methods = (array) $methods;
        $methods = array_map('strtoupper', $methods);
        
        // Check if all methods are allowed
        $disallowed = array_diff($methods, self::ALLOWED_METHODS);
        
        if (!empty($disallowed)) {
            throw new RuntimeException(
                "Disallowed HTTP methods in Route::match().\n" .
                "Route attempted: Route::match(['" . implode("', '", $methods) . "'], '{$uri}', ...)\n" .
                "Disallowed methods: " . implode(', ', $disallowed) . "\n\n" .
                "Only GET and POST methods are allowed in RSpade.\n" .
                "Use:\n" .
                "  Route::get('{$uri}', ...);\n" .
                "  Route::post('{$uri}', ...);"
            );
        }
        
        // If only allowed methods, pass through to parent
        return parent::match($methods, $uri, $action);
    }
    
    /**
     * Override addRoute to check HTTP methods
     *
     * @param array|string $methods
     * @param string $uri
     * @param mixed $action
     * @return \Illuminate\Routing\Route
     * @throws RuntimeException
     */
    public function addRoute($methods, $uri, $action)
    {
        $methods = (array) $methods;
        $methods = array_map('strtoupper', $methods);

        // HEAD is automatically added by Laravel for GET routes, so allow it
        $methods = array_diff($methods, ['HEAD']);

        // Check if all remaining methods are allowed
        $disallowed = array_diff($methods, self::ALLOWED_METHODS);

        if (!empty($disallowed)) {
            throw new RuntimeException(
                "Disallowed HTTP methods detected.\n" .
                "Methods attempted: " . implode(', ', $disallowed) . "\n" .
                "Route: {$uri}\n\n" .
                "Only GET and POST methods are allowed in RSpade.\n" .
                "- Use GET for retrieving data and displaying pages\n" .
                "- Use POST for submitting forms and modifying data"
            );
        }

        // Re-add HEAD if we had a GET
        if (in_array('GET', $methods)) {
            $methods[] = 'HEAD';
        }

        return parent::addRoute($methods, $uri, $action);
    }
}