<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core;

use RuntimeException;
use App\RSpade\Core\Manifest\Manifest;

class Autoloader
{
    // Manifest is now static - no instance needed

    /**
     * Registered class aliases
     */
    protected static $aliases = [];

    /**
     * Whether the autoloader is registered
     */
    protected static $registered = false;

    /**
     * Whether aliases have been loaded
     */
    protected static $aliases_loaded = false;

    /**
     * Cache of the autoloader class map from manifest
     */
    protected static ?array $class_map = null;

    /**
     * The PHP error handler our scoped include-warning tolerance chains to for
     * everything outside its carve-out (normally Laravel's HandleExceptions).
     * @var callable|null
     */
    protected static $previous_error_handler = null;

    /**
     * Whether the scoped include-warning tolerance has been installed.
     */
    protected static bool $error_handler_installed = false;

    /**
     * Register the RSX autoloader
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }

        // Initialize static Manifest and aliases
        Manifest::init();
        static::__load_aliases();

        // Install the scoped include-warning tolerance BEFORE the RSX autoloader is
        // registered, so composer's stale-classmap miss can soft-fail through to us.
        static::__install_classloader_warning_tolerance();

        // Register with SPL after Composer
        spl_autoload_register([static::class, 'load'], true, false);

        self::$registered = true;
        console_debug('AUTOLOADER', 'RSX Autoloader has been registered');
    }

    /**
     * Install a scoped error-handler carve-out that lets composer's stale committed
     * classmap soft-fail through to the RSX autoloader (load(), registered below).
     *
     * WHY THIS EXISTS
     * ---------------
     * The class-override system (rsx:man class_override) renames an overridden
     * framework file to `<Name>.php.upstream` during the manifest rebuild. But
     * composer's COMMITTED classmap (vendor/composer/autoload_classmap.php) still maps
     * the framework FQCN to the now-renamed `.php` path until the rebuild's validator
     * regenerates it (see _Manifest_Quality_Helper::_validate_composer_classmap).
     *
     * Composer's ClassLoader::findFile() returns a classmap entry with NO file_exists()
     * check, and its include closure does a bare `include $file`. A missing-file
     * `include` is natively a PHP E_WARNING that returns false; spl_autoload then
     * proceeds to the next registered loader - THIS class's load() - which resolves the
     * class by simple name via the manifest and class_alias()es the requested FQCN to
     * the override. The system self-heals at runtime.
     *
     * EXCEPT: Laravel's HandleExceptions error handler promotes that E_WARNING to an
     * ErrorException mid-autoload, turning composer's harmless miss into a fatal before
     * the RSX autoloader ever runs (the downstream "overrode File_Attachment_Model" crash).
     *
     * THE CARVE-OUT swallows ONLY the include warning that originates FROM composer's
     * ClassLoader.php (identified precisely by the error's file path - where the bare
     * `include` physically lives). Everything else - every other warning, every other
     * error level, warnings from any other file - keeps existing fail-loud behavior by
     * delegating to the previously registered handler. If the RSX autoloader ALSO cannot
     * resolve the class, PHP's normal "Class not found" fatal still fires, loud and
     * accurate.
     *
     * This is NOT a fail-loud violation: it is a narrowly-scoped tolerance for one
     * well-understood, self-correcting composer behavior - the RSX autoloader+alias layer
     * is the intended resolver. (Owner ruling 2026-07-24, Option B.)
     */
    protected static function __install_classloader_warning_tolerance(): void
    {
        if (self::$error_handler_installed) {
            return;
        }
        self::$error_handler_installed = true;

        // set_error_handler returns the handler it replaced; chain to it for
        // everything outside the carve-out.
        self::$previous_error_handler = set_error_handler([static::class, '_handle_php_error']);
    }

    /**
     * Chained PHP error handler. Public because set_error_handler invokes it as an
     * external callback. Swallows exactly the composer-ClassLoader include warning;
     * delegates everything else to the previous handler.
     *
     * @return bool|null True = handled (suppresses default handling / exception
     *                   promotion); otherwise whatever the previous handler returns.
     */
    public static function _handle_php_error($errno, $errstr, $errfile = '', $errline = 0)
    {
        if (static::_should_tolerate_classloader_warning((int) $errno, (string) $errfile)) {
            // Observable in dev; stripped in production.
            console_debug('AUTOLOAD', "Tolerated composer classmap include miss (stale committed classmap vs .upstream rename): {$errstr}");

            return true; // swallow: composer's miss becomes a silent decline, autoload falls through to Autoloader::load()
        }

        // Not our case - preserve existing behavior by delegating to the handler we
        // replaced (Laravel's HandleExceptions, which promotes reportable warnings to
        // ErrorException). No previous handler -> false lets PHP's internal handler run.
        if (self::$previous_error_handler !== null) {
            return (self::$previous_error_handler)($errno, $errstr, $errfile, $errline);
        }

        return false;
    }

    /**
     * Predicate: is this the specific include/include_once warning emitted by
     * composer's ClassLoader when a classmap entry points at a file the override pass
     * renamed away? Identified by (a) it being a WARNING level and (b) the error's file
     * being composer's ClassLoader.php (where the bare `include` physically lives). An
     * E_ERROR (or anything non-warning) from the same file is NEVER swallowed.
     */
    public static function _should_tolerate_classloader_warning(int $errno, string $errfile): bool
    {
        if ($errno !== E_WARNING) {
            return false;
        }

        $normalized = str_replace('\\', '/', $errfile);

        return str_ends_with($normalized, 'vendor/composer/ClassLoader.php');
    }

    /**
     * Load a class
     */
    public static function load($class)
    {
        // Remove leading backslash
        $requested_class = ltrim($class, '\\');

        // Extract simple class name (after last backslash)
        $simple_name = substr(strrchr($requested_class, '\\'), 1) ?: $requested_class;

        // Try to find the simple class name in the manifest
        try {
            $metadata = Manifest::php_get_metadata_by_class($simple_name);

            // Load the file
            $file_path = str_replace('\\', '/', $metadata['file']);
            $absolute_path = base_path($file_path);
            if (file_exists($absolute_path)) {
                require_once $absolute_path;

                // If the requested FQCN still doesn't exist but the actual class does,
                // create an alias
                $actual_fqcn = $metadata['fqcn'];
                if (!class_exists($requested_class, false) &&
                    class_exists($actual_fqcn, false) &&
                    $requested_class !== $actual_fqcn) {
                    class_alias($actual_fqcn, $requested_class);
                }

                return true;
            }
        } catch (\RuntimeException $e) {
            // Class not found in manifest by simple name
        }

        // Check for special case class patterns that need custom handling

        // Check if it's an RSX class by namespace
        if (strpos($requested_class, 'Rsx\\') === 0) {
            return static::__load_rsx_namespaced_class($requested_class);
        }

        // Check if it's a non-namespaced RSX class (like Rsx_Controller_Abstract)
        if (strpos($requested_class, 'Rsx_') === 0) {
            return static::__load_rsx_base_class($requested_class);
        }

        // Check aliases
        if (isset(static::$aliases[$requested_class])) {
            return static::__load_aliased_class($requested_class);
        }

        // Check for non-namespaced classes in the autoloader class map
        if (strpos($requested_class, '\\') === false) {
            return static::__load_from_class_map($requested_class);
        }

        // Not our responsibility
        return false;
    }

    /**
     * Load an RSX namespaced class
     */
    protected static function __load_rsx_namespaced_class($class)
    {
        // First, try to find the class in the manifest by full class name
        $manifest_data = Manifest::get_all();

        // Search through all PHP files in the manifest
        foreach ($manifest_data as $file_path => $file_info) {
            if (!isset($file_info['namespace']) || !isset($file_info['class'])) {
                continue;
            }

            // Check if this is the class we're looking for
            $full_class_name = $file_info['namespace'] . '\\' . $file_info['class'];
            if ($full_class_name === $class) {
                // Found it! Load the file (convert relative to absolute)
                // Convert backslashes to forward slashes for path
                $file_path = str_replace('\\', '/', $file_path);
                $absolute_path = base_path($file_path);
                if (file_exists($absolute_path)) {
                    require_once $absolute_path;

                    return true;
                }
            }
        }

        // Class not found in manifest - return false to let other autoloaders try
        return false;
    }

    /**
     * Load RSX base classes
     */
    protected static function __load_rsx_base_class($class)
    {
        // Map of base classes to their locations
        $base_classes = [
            'Rsx_Controller_Abstract' => 'app/RSpade/Core/Base/Rsx_Controller_Abstract.php',
        ];

        if (isset($base_classes[$class])) {
            $file = base_path($base_classes[$class]);
            if (file_exists($file)) {
                require_once $file;

                return true;
            }
        }

        return false;
    }

    /**
     * Load an aliased class
     */
    protected static function __load_aliased_class($class)
    {
        $actual_class = static::$aliases[$class];

        // Try to load the actual class
        if (class_exists($actual_class, true)) {
            class_alias($actual_class, $class);

            return true;
        }

        return false;
    }

    /**
     * Load a class from the autoloader class map
     * @param string $class Simple class name without namespace
     */
    protected static function __load_from_class_map($class)
    {
        // Load class map from manifest if not cached
        if (static::$class_map === null) {
            static::$class_map = Manifest::get_autoloader_class_map();
        }

        // Check if the class exists in the map
        if (!isset(static::$class_map[$class])) {
            return false;
        }

        $fqcns = static::$class_map[$class];

        // If multiple FQCNs exist for this simple name, throw an error
        if (count($fqcns) > 1) {
            $error_msg = "Fatal error: Ambiguous class name '{$class}'. Multiple classes found:\n";
            foreach ($fqcns as $fqcn) {
                $error_msg .= "  - {$fqcn}\n";
            }
            $error_msg .= 'Please use the fully qualified class name (FQCN) to resolve ambiguity.';

            throw new RuntimeException($error_msg);
        }

        // Single FQCN found, try to load it
        $fqcn = $fqcns[0];

        // Try to autoload the actual class
        if (class_exists($fqcn, true) || interface_exists($fqcn, true) || trait_exists($fqcn, true)) {
            // Create an alias for the simple name
            class_alias($fqcn, $class);

            return true;
        }

        return false;
    }

    /**
     * Load class aliases from configuration
     */
    protected static function __load_aliases()
    {
        if (static::$aliases_loaded) {
            return;
        }

        // Future: Load from config/rsx.php
        static::$aliases = [
            // Example: 'UserModel' => 'Rsx\Models\User_Model'
        ];

        static::$aliases_loaded = true;
    }

    /**
     * Refresh the manifest and retry loading
     */
    public static function refresh_and_retry($class)
    {
        // // In development mode, rebuild manifest if class not found
        // if (config('app.env') === 'local') {
        //     Manifest::rebuild();
        //     return static::load($class);
        // }

        return false;
    }
}
