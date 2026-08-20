<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Providers;

use Blade;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Log;
use RuntimeException;
use App\RSpade\Core\Kernels\ManifestKernel;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Rsx_Framework_Provider - RSX Framework Bootstrap and Initialization
 *
 * HOW THIS IS CALLED:
 * File: config/app.php
 * Line: ~212 in 'providers' array
 * Mechanism: Laravel's service provider auto-registration system
 * Entry: App\RSpade\Core\Providers\Rsx_Framework_Provider::class
 *
 * LARAVEL LIFECYCLE:
 * 1. Laravel loads all service providers listed in config/app.php
 * 2. Laravel calls register() method on all providers (including this one)
 * 3. Laravel calls boot() method on all providers (including this one)
 * 4. This is the standard Laravel service provider pattern
 *
 * EXECUTION ORDER:
 * 1. Rsx_Preboot_Service::init() (debug/cache setup - runs first in AppServiceProvider::register())
 * 2. Rsx_Framework_Provider::register() (this class - registers services)
 * 3. Rsx_Framework_Provider::boot() (this class - core framework initialization)
 *
 * WHAT IT DOES:
 * 1. register() method (line ~51):
 *    - Merges rsx.php configuration
 *    - Registers ManifestKernel singleton
 *    - Registers integration service providers
 *
 * 2. boot() method (line ~94): Sets up RSX runtime components:
 *    - Initializes manifest system for file discovery
 *    - Registers RSX autoloader for class loading
 *    - Loads classless PHP utility files from rsx/
 *    - Executes Main_Abstract::init() for application setup
 *    - Registers Route::rsx() macro
 *    - Handles IDE integration domain auto-discovery
 *
 * RSX ROUTING:
 * - RSX routes are handled through the 404 exception handler
 * - When Laravel doesn't find a matching route, it throws a NotFoundHttpException
 * - The exception handler (app/Exceptions/Handler.php) catches this and tries RSX dispatch
 * - This gives Laravel routes priority over RSX routes
 */
#[Instantiatable]
class Rsx_Framework_Provider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Register services
     *
     * @return void
     */
    public function register()
    {
        // Sanity check: .env symlink configuration (temporarily disabled)
        // The system/.env must be a symlink to ../.env for proper configuration sharing
        // $this->check_env_symlink();

        // Merge framework config defaults
        $package_config = base_path('config/rsx.php');
        $this->mergeConfigFrom($package_config, 'rsx');

        // Merge user config overrides from /rsx/resource/config/rsx.php
        $user_config_path = base_path('rsx/resource/config/rsx.php');
        if (file_exists($user_config_path)) {
            $user_config = require $user_config_path;
            $current_config = config('rsx', []);
            $merged_config = array_merge_deep($current_config, $user_config);
            config(['rsx' => $merged_config]);
        }

        // Merge additional config from RSX_ADDITIONAL_CONFIG env variable
        // Used by test runner to include test directory in manifest
        $additional_config_path = env('RSX_ADDITIONAL_CONFIG');
        if ($additional_config_path && file_exists($additional_config_path)) {
            $additional_config = require $additional_config_path;
            $current_config = config('rsx', []);
            $merged_config = array_merge_deep($current_config, $additional_config);
            config(['rsx' => $merged_config]);
        }

        // Remove .claude/CLAUDE.md symlink if in framework developer mode
        // This allows dual CLAUDE.md files: one for framework dev, one for distribution
        // Only runs in specific development environment to avoid affecting other setups
        if (config('rsx.code_quality.is_framework_developer', false)) {
            // Only run in the specific development environment
            if (base_path() === '/var/www/html') {
                // Check if supervisord is running (indicates dev environment)
                $output = [];
                $return = 0;
                \exec_safe('pgrep -x supervisord > /dev/null 2>&1', $output, $return);
                if ($return === 0) {
                    $claude_symlink = base_path('.claude/CLAUDE.md');
                    if (is_link($claude_symlink)) {
                        unlink($claude_symlink);
                    }
                }
            }
        } else {
            // CLEANUP of older configured RSpade dev containers - REMOVE POST FINAL RELEASE.
            //
            // Earlier dev-container provisioning distributed the framework's knowledge file by
            // symlinking /root/.claude/CLAUDE.md at the retired system/docs/CLAUDE.dist.md, and
            // this provider used to RECREATE that symlink on every boot. CLAUDE.dist.md is gone
            // (knowledge restructure 2026-08-17; delivery is now the rsx/resource/CLAUDE.md
            // import line + the .claude/skills/rspade plugin symlink, installed by environment
            // update 060_claude_docs.sh), so the recreation left a dangling symlink that made
            // the global CLAUDE.md silently resolve to nothing - and it re-fired forever,
            // fighting 060's own retirement of the link.
            //
            // Now this block only REMOVES the stale link, under three deliberate guards:
            // dev mode only, running as root only, and only when /root/CLAUDE-adjacent path
            // ($HOME/.claude/CLAUDE.md) is a SYMLINK whose target points into the framework
            // tree - a regular file there is the developer's own and is never touched.
            if (!app()->environment('production') && posix_getuid() === 0) {
                $claude_symlink = $_SERVER['HOME'] . '/.claude/CLAUDE.md';
                if (is_link($claude_symlink)) {
                    $target = readlink($claude_symlink);
                    if ($target !== false && str_starts_with($target, '/var/www/html/system/')) {
                        unlink($claude_symlink);
                    }
                }
            }
        }

        // Register ManifestKernel as singleton
        $this->app->singleton(ManifestKernel::class, function ($app) {
            $kernel = new ManifestKernel();

            // Load manifest modules from config
            $modules = config('rsx.manifest_modules', []);
            foreach ($modules as $module_class) {
                if (!class_exists($module_class)) {
                    throw new RuntimeException("Manifest module class not found: {$module_class}. Check your config/rsx.php file.");
                }
                $kernel->register($module_class);
            }

            return $kernel;
        });

        // Register alias for ManifestKernel
        $this->app->alias(ManifestKernel::class, 'rsx.manifest_kernel');

        // Register integration service providers
        $integrations = config('rsx.integrations.providers', []);
        foreach ($integrations as $provider_class) {
            if (!class_exists($provider_class)) {
                throw new RuntimeException("Integration provider class not found: {$provider_class}. Check your config/rsx.php file.");
            }
            $this->app->register($provider_class);
        }

        // Hide Laravel's own down/up commands from `php artisan list`.
        //
        // RSpade has ONE maintenance mode (rsx:maintenance:enable / :disable - flag + reason,
        // task kill, service quiesce, allow-most CLI gate). Laravel's down/up drive a
        // completely different mechanism (storage/framework/maintenance.php, checked further
        // down public/index.php) and offering both in the listing invites an operator to reach
        // for the one that does almost nothing here. They stay RUNNABLE by name - hidden, not
        // blocked - so nothing that already calls them breaks.
        foreach ([
            \Illuminate\Foundation\Console\DownCommand::class,
            \Illuminate\Foundation\Console\UpCommand::class,
        ] as $hidden_command) {
            $this->app->extend($hidden_command, function ($command) {
                $command->setHidden(true);

                return $command;
            });
        }

        // Note: All other RSX core classes are now static and don't need registration
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot()
    {
        // Fail loud on an APP_URL whose scheme this mode does not accept (https always;
        // http in development only), or a missing one. Deferred to here from the
        // afterLoadingEnvironment substitution phase so the error is readable: this
        // runs after HandleExceptions has bootstrapped. See Rsx_App_Url.
        \App\RSpade\Core\Env\Rsx_App_Url::enforce_scheme_from_env();

        if (getcwd() != base_path()) {
            chdir(base_path());
        }

        if (!app()->environment('production')) {
            $this->check_for_test_files_in_root();
            $this->block_laravel_vscode_extension();
        }

        // Publish config files
        $package_config = base_path('config/rsx.php');

        $this->publishes(
            [
                $package_config => config_path('rsx.php'),
            ],
            'rsx-config',
        );

        // Skip framework initialization for the always-runnable escape hatch
        // (rsx:clean, rsx:man, and pure Symfony introspection). The membership list
        // and its rationale live in Manifest::__cli_skips_manifest_boot().
        $command = $_SERVER['argv'][1] ?? null;
        if (Manifest::__cli_skips_manifest_boot()) {
            return;
        }

        // Handle rsx:manifest:build --clean specially
        if ($command === 'rsx:manifest:build') {
            // Check if --clean flag is present
            $has_clean = in_array('--clean', $_SERVER['argv'] ?? []);
            if ($has_clean) {
                // Run rsx:clean first (caches only - build tooling never touches git
                // state), then manifest:build
                $clean_exit = \App\RSpade\Core\Console\Rsx_Artisan::passthru(
                    'rsx:clean',
                    ['--_no-system-reset']
                );
                if ($clean_exit !== 0) {
                    exit($clean_exit);
                }
                // Forward the internal notice-suppression flag: it was stripped from argv
                // pre-boot, so the inner build would otherwise print the pending-migration
                // notice this invocation was told to skip.
                $suppress = \App\RSpade\Core\Console\Rsx_Internal_Flags::has(
                    \App\RSpade\Commands\Rsx\Manifest_Build_Command::FLAG_NO_SCHEMA_CHECK
                ) ? [\App\RSpade\Commands\Rsx\Manifest_Build_Command::FLAG_NO_SCHEMA_CHECK] : [];
                $build_exit = \App\RSpade\Core\Console\Rsx_Artisan::passthru('rsx:manifest:build', $suppress);
                exit($build_exit);
            }
        }

        // Initialize manifest (handles rebuild logic internally)
        Manifest::init();

        // Register polymorphic type references with Laravel's morph map, under BOTH the
        // simple class name and the type-ref integer id. The integer alias is what lets
        // stock Eloquent morph relations resolve the raw BIGINT discriminator they read;
        // alias order is load-bearing (see Type_Ref_Registry::register_morph_map).
        \App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry::register_morph_map();

        // Register RSX autoloader
        \App\RSpade\Core\Autoloader::register();

        // Load all classless PHP files from rsx/ directory
        // These files contain global functions and constants that should be available everywhere
        $all_files = Manifest::get_all();
        foreach ($all_files as $file_path => $metadata) {
            // Only process PHP files in rsx/ directory
            if (!str_starts_with($file_path, 'rsx/')) {
                continue;
            }

            // Only process PHP files
            if (($metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            // Skip files that have classes (we only want classless utility files)
            if (isset($metadata['class'])) {
                continue;
            }

            // Load the classless PHP file
            require_once base_path($file_path);
        }

        // Initialize Main_Abstract extending class
        $main_classes = Manifest::php_get_extending('Main_Abstract');

        // Sanity check: There should be exactly one Main_Abstract class (like main() in C++)
        if (count($main_classes) !== 1) {
            $class_list = array_map(function ($c) {
                return $c['fqcn'] . ' (' . $c['file'] . ')';
            }, $main_classes);

            throw new RuntimeException(
                'Only one class should extend Main_Abstract. This is the entry point to the RSX application, ' .
                'analogous to main() in C++. Found ' . count($main_classes) . " classes:\n" .
                implode("\n", $class_list)
            );
        }

        $main_class = array_pop($main_classes);

        if (isset($main_class['fqcn']) && $main_class['fqcn']) {
            $class_name = $main_class['fqcn'];
            // This is a sanity check - class from manifest should exist
            if (!class_exists($class_name)) {
                shouldnt_happen("Main_Abstract extending class from manifest not found: {$class_name}");
            }
            if (method_exists($class_name, 'init')) {
                $class_name::init();
            }
        }

        // Register RSX view namespace for path-agnostic views
        $this->app['view']->addNamespace('rsx', base_path('rsx'));

        // Register RSpade framework view namespace
        $this->app['view']->addNamespace('rspade', base_path('app/RSpade'));

        // Register RSX route macro on Route facade
        if (!Route::hasMacro('rsx')) {
            Route::macro('rsx', function ($target, $params = []) {
                return \App\RSpade\Core\RsxReflection::route($target, $params);
            });
        }

        // Register Request helper macros
        if (!Request::hasMacro('is_post')) {
            Request::macro('is_post', function () {
                return $this->isMethod('post');
            });
        }

        // Ensure the IDE bridge local-file grant token + passive guards exist (dev only).
        $this->ensure_ide_bridge_token();
    }

    /**
     * Get the services provided by the provider
     *
     * @return array
     */
    public function provides()
    {
        return [ManifestKernel::class, 'rsx.manifest_kernel'];
    }

    /**
     * Check for test files in the root directory that contain classes
     * These should be moved to rsx/temp for proper manifest detection
     *
     * @return void
     * @throws RuntimeException
     */
    protected function check_for_test_files_in_root()
    {
        $root_path = base_path();
        $files = scandir($root_path);

        foreach ($files as $file) {
            // Skip directories and non-PHP files
            if (is_dir($root_path . '/' . $file) || !str_ends_with($file, '.php')) {
                continue;
            }

            // Check if filename contains 'test'
            if (str_contains(strtolower($file), 'test')) {
                // Read the file to check for class definition
                $content = file_get_contents($root_path . '/' . $file);

                // Check if file contains a class definition
                if (preg_match('/^\s*class\s+/m', $content)) {
                    throw new RuntimeException(
                        "One-off class test scripts should not be in the project root directory.\n" .
                        "File: {$file}\n" .
                        "These files won't be detected by the RSX manifest and won't be visible to most of the project functionality.\n" .
                        'Please move the class test to rsx/temp/ (create the directory if it does not exist).'
                    );
                }
            }
        }
    }

    /**
     * Ensure the IDE bridge local-file grant token (and passive static-serve guards)
     * exist. Delegated to Ide_Bridge_Token::ensure(), which is idempotent and dev-only.
     * The IDE now reads the server URL from .env directly, so there is no domain.txt
     * discovery any more; ensure() also clears that retired artifact.
     *
     * @return void
     */
    protected function ensure_ide_bridge_token()
    {
        // Web requests only (a CLI invocation shouldn't materialize dev grants).
        if (php_sapi_name() === 'cli') {
            return;
        }

        \App\RSpade\Core\Ide\Ide_Bridge_Token::ensure();
    }

    /**
     * Block Laravel VSCode extension from creating discovery scripts
     *
     * The official Laravel VSCode extension creates a vendor/_laravel_ide/ directory
     * with discovery scripts that bootstrap the application. This conflicts with
     * RSpade framework initialization. We prevent this by ensuring _laravel_ide
     * is a file instead of a directory.
     *
     * @return void
     */
    private function block_laravel_vscode_extension()
    {
        $laravel_ide_path = base_path('vendor/_laravel_ide');

        // If it exists as a directory, remove it and replace with a file
        if (is_dir($laravel_ide_path)) {
            // Recursively remove the directory
            $this->recursive_remove_directory($laravel_ide_path);
        }

        // Create as a file if it doesn't exist
        if (!file_exists($laravel_ide_path)) {
            touch($laravel_ide_path);
            chmod($laravel_ide_path, 0777);
        }
    }

    /**
     * Recursively remove a directory and its contents
     *
     * @param string $dir Directory to remove
     * @return void
     */
    private function recursive_remove_directory(string $dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->recursive_remove_directory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Check that .env is properly symlinked
     *
     * The system/.env file must be a symlink to ../.env for proper configuration sharing.
     * This ensures both the project root and Laravel see the same .env file.
     */
    private function check_env_symlink(): void
    {
        // Only check if we're in the standard /var/www/html/system location
        if (base_path() !== '/var/www/html/system') {
            return;
        }

        $project_env = '/var/www/html/.env';
        $system_env = '/var/www/html/system/.env';

        // Both files must exist for this check to apply
        if (!file_exists($project_env) || !file_exists($system_env)) {
            return;
        }

        // system/.env must be a symlink
        if (!is_link($system_env)) {
            $this->fatal_env_symlink_error();
        }

        // The symlink must point to ../.env (relative path)
        $link_target = readlink($system_env);
        if ($link_target !== '../.env') {
            $this->fatal_env_symlink_error();
        }
    }

    /**
     * Display fatal error for .env symlink misconfiguration
     */
    private function fatal_env_symlink_error(): void
    {
        $message = <<<'ERROR'

================================================================================
FATAL ERROR: .env Symlink Misconfiguration
================================================================================

The file /var/www/html/system/.env must be a symlink to ../.env

This ensures both the project root and the Laravel framework share the same
environment configuration. Without this symlink, configuration changes in one
location won't be reflected in the other.

TO FIX THIS ISSUE:

    cd /var/www/html/system
    rm .env
    ln -s ../.env .env

After creating the symlink, your .env setup should look like:

    /var/www/html/.env           (the actual .env file)
    /var/www/html/system/.env -> ../.env (symlink)

================================================================================

ERROR;

        // Use echo + die for maximum visibility (before any error handlers are set up)
        echo $message;
        die(1);
    }
}
