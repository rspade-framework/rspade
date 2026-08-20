<?php

namespace App\Providers;

use App\RSpade\Core\Providers\Rsx_Preboot_Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\ViewErrorBag;
use Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Query logging modes
     */
    public const QUERY_LOG_NONE = 0;              // Default - no logging

    public const QUERY_LOG_ALL_STDOUT = 1;        // Log all queries to stdout

    public const QUERY_LOG_DESTRUCTIVE_STDOUT = 2; // Log only destructive queries to stdout

    public const QUERY_LOG_ALL_LARAVEL = 3;       // Log all queries to Laravel log

    public const QUERY_LOG_DESTRUCTIVE_LARAVEL = 4; // Log only destructive queries to Laravel log

    /**
     * Current query logging mode
     *
     * @var int
     */
    protected static $query_log_mode = self::QUERY_LOG_NONE;

    /**
     * Enable query echoing (backwards compatibility - sets ALL_STDOUT mode)
     *
     * @return void
     */
    public static function enable_query_echo()
    {
        self::$query_log_mode = self::QUERY_LOG_ALL_STDOUT;
    }

    /**
     * Disable query echoing
     *
     * @return void
     */
    public static function disable_query_echo()
    {
        self::$query_log_mode = self::QUERY_LOG_NONE;
    }

    /**
     * Set query logging mode
     *
     * @param int $mode One of the QUERY_LOG_* constants
     * @return void
     */
    public static function set_query_log_mode(int $mode)
    {
        self::$query_log_mode = $mode;
    }

    /**
     * Check if a query is destructive (modifies data)
     *
     * @param string $sql
     * @return bool
     */
    protected static function is_destructive_query(string $sql): bool
    {
        $sql_upper = strtoupper(trim($sql));

        // Check for destructive SQL operations
        $destructive_patterns = [
            'INSERT ',
            'UPDATE ',
            'DELETE ',
            'ALTER ',
            'CREATE ',
            'DROP ',
            'TRUNCATE ',
            'REPLACE ',
            'RENAME ',
            'MODIFY ',
            'ADD COLUMN',
            'DROP COLUMN',
            'ADD INDEX',
            'DROP INDEX',
            'ADD CONSTRAINT',
            'DROP CONSTRAINT',
        ];

        foreach ($destructive_patterns as $pattern) {
            if (strpos($sql_upper, $pattern) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Initialize RSpade pre-boot services (debug locks, cache clearing, trace mode)
        Rsx_Preboot_Service::init();

        // Load JQHTML Laravel Bridge from node_modules
        $jqhtmlBridge = base_path('node_modules/@jqhtml/core/laravel-bridge/autoload.php');

        if (file_exists($jqhtmlBridge)) {
            require_once $jqhtmlBridge;
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Override Ignition's exception renderer with our custom one that fixes multi-line display
        if (!app()->environment('production')) {
            $this->app->bind(
                'Illuminate\Contracts\Foundation\ExceptionRenderer',
                fn ($app) => $app->make(\App\RSpade\Integrations\Jqhtml\JqhtmlExceptionRenderer::class)
            );
        }

        // Configure MySQL connection to use custom grammar with millisecond precision
        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql') {
            $connection->setQueryGrammar(new \App\RSpade\Core\Database\Query\Grammars\Query_MySqlGrammar());
            $connection->setSchemaGrammar(new \App\RSpade\Core\Database\Schema\Grammars\Schema_MySqlGrammar());
        }

        // Set up query listener for migration/normalize debugging
        DB::listen(function ($query) {
            if (self::$query_log_mode === self::QUERY_LOG_NONE) {
                return;
            }

            $sql = $query->sql;
            $is_destructive = self::is_destructive_query($sql);

            switch (self::$query_log_mode) {
                case self::QUERY_LOG_ALL_STDOUT:
                    echo $sql . "\n";
                    break;

                case self::QUERY_LOG_DESTRUCTIVE_STDOUT:
                    if ($is_destructive) {
                        echo $sql . "\n";
                    }
                    break;

                case self::QUERY_LOG_ALL_LARAVEL:
                    Log::debug('SQL Query: ' . $sql);
                    break;

                case self::QUERY_LOG_DESTRUCTIVE_LARAVEL:
                    if ($is_destructive) {
                        Log::debug('SQL Query (Destructive): ' . $sql);
                    }
                    break;
            }
        });

        // Share $errors variable with all views for @error directive
        // Disabled for custom session handler
        // View::composer('*', function ($view) {
        //     $view->with('errors', session()->get('errors', new ViewErrorBag));
        // });

        // Register custom database session handler to preserve user_id and site_id columns
        // Disabled for custom session handler
        // Session::extend('database', function ($app) {
        //     $connection = $app['db']->connection($app['config']['session.connection']);
        //     $table = $app['config']['session.table'];
        //     $lifetime = $app['config']['session.lifetime'];
        //
        //     return new \App\RSpade\Core\Session\Custom_DatabaseSessionHandler(
        //         $connection,
        //         $table,
        //         $lifetime,
        //         $app
        //     );
        // });

        // Override cache:clear to integrate RSX clearing
        if ($this->app->runningInConsole()) {
            $this->override_cache_clear();
        }
    }

    /**
     * Override Laravel's cache:clear command to integrate with RSX
     *
     * @return void
     */
    protected function override_cache_clear()
    {
        // Only override cache:clear to also clear RSX caches
        $this->app->extend('command.cache.clear', function ($command, $app) {
            return new \App\Console\Commands\CacheClearCommand();
        });
    }
}
