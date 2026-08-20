<?php

namespace Jqhtml\LaravelBridge;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class JqhtmlServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register()
    {
        // Register the error formatter as a singleton
        $this->app->singleton(JqhtmlErrorFormatter::class, function ($app) {
            return new JqhtmlErrorFormatter(
                $app['config']->get('jqhtml.source_maps_path', storage_path('jqhtml-sourcemaps')),
                $app['config']->get('jqhtml.show_source_context', true)
            );
        });

        // Register the exception renderer
        $this->app->singleton(JqhtmlExceptionRenderer::class, function ($app) {
            return new JqhtmlExceptionRenderer(
                $app->make(JqhtmlErrorFormatter::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/jqhtml.php' => config_path('jqhtml.php'),
        ], 'jqhtml-config');

        // Extend Laravel's exception handler
        $this->extendExceptionHandler();
    }

    /**
     * Extend Laravel's exception handler to handle JQHTML exceptions
     */
    protected function extendExceptionHandler()
    {
        $this->app->extend(ExceptionHandler::class, function ($handler, $app) {
            // Hook into the exception rendering process
            $handler->reportable(function (JqhtmlException $e) {
                // Custom reporting logic if needed
            });

            $handler->renderable(function (JqhtmlException $e, $request) use ($app) {
                if ($request->expectsJson()) {
                    return response()->json(
                        $app->make(JqhtmlErrorFormatter::class)->formatForJson($e),
                        500
                    );
                }

                // For web requests, let Laravel's default HTML handler display it
                // with our enhanced error information
                return null;
            });

            return $handler;
        });
    }
}