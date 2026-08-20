<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Providers;

use Illuminate\Support\ServiceProvider;
use App\Routing\RestrictedRouter;

/**
 * Rsx_Restricted_Routing_Provider - Replaces Laravel router with restricted version
 *
 * REGISTERED IN: config/app.php line ~206 in 'providers' array
 * CRITICAL: MUST be registered BEFORE RouteServiceProvider
 *
 * WHAT IT DOES:
 * Overrides Laravel's default router with RestrictedRouter that enforces:
 * - ONLY GET and POST HTTP methods allowed
 * - PUT, PATCH, DELETE throw exceptions
 * - Route::resource() throws exception (encourages explicit routing)
 *
 * HOW IT WORKS:
 * Uses app()->extend('router') to replace the router binding while preserving
 * existing RouteCollection, ensuring Laravel routes still work.
 *
 * RATIONALE:
 * - Enforces simple, explicit routing patterns
 * - Prevents REST API-style routing conventions that don't fit RSX architecture
 * - Makes route definitions clearer and more maintainable
 */
#[Instantiatable]
class Rsx_Restricted_Routing_Provider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Override the router binding to use our restricted version
        // We need to use extend to modify the existing singleton
        $this->app->extend('router', function ($router, $app) {
            // Get the existing RouteCollection from the original router
            $existingRoutes = $router->getRoutes();

            // Create our restricted router
            $restrictedRouter = new RestrictedRouter($app['events'], $app);

            // Set the existing RouteCollection on our new router
            $restrictedRouter->setRoutes($existingRoutes);

            return $restrictedRouter;
        });
    }
    
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Nothing to bootstrap
    }
}