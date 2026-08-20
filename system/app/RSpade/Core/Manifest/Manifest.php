<?php

namespace App\RSpade\Core\Manifest;

use Exception;
use Illuminate\Support\Facades\File;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use App\RSpade\Core\ExtensionRegistry;
use App\RSpade\Core\IntegrationRegistry;
use App\RSpade\Core\Kernels\ManifestKernel;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;
use App\RSpade\Core\Manifest\_Manifest_Builder_Helper;
use App\RSpade\Core\Manifest\_Manifest_Cache_Helper;
use App\RSpade\Core\Manifest\_Manifest_Database_Helper;
use App\RSpade\Core\Manifest\_Manifest_JS_Reflection_Helper;
use App\RSpade\Core\Manifest\_Manifest_PHP_Reflection_Helper;
use App\RSpade\Core\Manifest\_Manifest_Quality_Helper;
use App\RSpade\Core\Manifest\_Manifest_Reflection_Helper;
use App\RSpade\Core\Manifest\_Manifest_Scanner_Helper;
use App\RSpade\Core\Rsx;

/**
* Manifest - RSX File Discovery and Metadata Management System
*
* PURPOSE: Discovers, indexes, and caches metadata about all RSX files for fast lookups
*
* PATH HANDLING:
* - All file paths are stored as RELATIVE paths from base_path()
* - Use base_path($relative) to get absolute paths when needed
* - Laravel's base_path() always returns the project root directory
*
* PROCESSING MODEL - 5-PHASE ARCHITECTURE:
*
* When manifest needs updating (scan() or rebuild()):
*   Phase 1: File Discovery - Scan directories and detect changes
*   Phase 2: Parse Metadata - Token parsing for PHP/JS structure
*            - NO reflection used, NO files loaded
*   Phase 3: Load Dependencies - Load PHP classes in dependency order
*            - Ensures all classes are available for reflection
*   Phase 4: Extract Reflection - PHP reflection data extraction
*            - Attributes, methods, parameters, etc.
*   Phase 5: Process Modules - Run manifest support modules, build autoloader
*            - Model_ManifestSupport extracts database metadata
*   Phase 6: Generate Stubs - JavaScript API and model stubs
*   Phase 7: Save & Finalize - Write cache, clear views, run quality checks
*
* When loading from valid cache:
*   - Only loads cached data, NO file scanning or processing
*   - Autoloader handles on-demand class loading
*
* CORE OPERATIONS:
* 1. init() - Ensures manifest is ready (loads cache + scans for updates, or rebuilds if no cache)
* 2. scan() - Incrementally updates manifest for changed files only (called by init())
* 3. rebuild() - Forces complete re-scan of all RSX files
* 4. clear() - Removes all cached manifest data
*
* FILE PROCESSING:
* - Scans /rsx/ directory recursively (excludes vendor, node_modules, etc.)
* - Extracts metadata from PHP files (classes, methods, attributes, namespaces)
* - Parses JavaScript/TypeScript files (imports, exports, classes)
* - Indexes Blade templates and views (sections, extends, view IDs)
* - Uses token parsing for basic info, reflection for detailed metadata
*
* QUERY METHODS:
* - php_find_class() - Locate PHP class by name
* - find_php_fqcn() - Locate PHP class by fully qualified name
* - js_find_class() - Locate JavaScript class
* - find_view() - Locate view template by ID
* - get_extending() - Find all classes extending a parent
* - get_with_attribute() - Find classes/methods with specific attributes
* - get_routes() - Extract all route definitions from attributes
* - get_stats() - Statistical summary of manifest contents
*
* DEBUGGING:
* - Use `php artisan manifest:dump` to view complete manifest contents
* - Supports JSON (default), YAML, and PHP export formats
* - Can filter by path or class name for targeted debugging
*
* DATA STRUCTURE (static::$data):
* [
*   'rsx/path/to/file.php' => [  // Keys are relative to base_path()
*     'file' => 'rsx/path/to/file.php',  // Relative to base_path()
*     'hash' => 'sha1_hash_of_file_contents',
*     'mtime' => unix_timestamp,
*     'size' => bytes,
*     'extension' => 'php',
*
*     // PHP-specific fields:
*     'namespace' => 'App\\Controllers',
*     'class' => 'UserController',
*     'fqcn' => 'App\\Controllers\\UserController',
*     'extends' => 'BaseController',
*     'implements' => ['Interface1', 'Interface2'],
*     'traits' => ['TraitName'],
*     'attributes' => [
*       'Route' => [['pattern' => '/users', 'methods' => ['GET']]],
*       'Cache' => [['ttl' => 3600]]
*     ],
*     'methods' => [
*       'index' => [
*         'name' => 'index',
*         'static' => false,
*         'visibility' => 'public',
*         'attributes' => ['Route' => [['/users', 'GET']]],
*         'parameters' => [
*           ['name' => 'request', 'type' => 'Request', 'nullable' => false]
*         ]
*       ]
*     ],
*     'properties' => [
*       ['name' => 'prop', 'visibility' => 'private', 'static' => false]
*     ],
*
*     // JavaScript-specific fields:
*     'imports' => [['from' => 'react', 'imports' => 'React']],
*     'exports' => ['ComponentName'],
*     'default_export' => 'MainComponent',
*     'public_static_methods' => ['getInstance'],
*     'static_properties' => ['instance'],
*
*     // View-specific fields:
*     'view_id' => 'user-profile',
*     'sections' => ['content', 'sidebar'],
*     'extends' => 'layouts.main'
*   ],
*   // ... more files
* ]
*
* CACHING:
* - Stores as PHP array in /storage/rsx-build/manifest_data.php for fast include()
* - Also exports as JSON for JavaScript tooling compatibility
* - Uses file size + mtime for change detection (fast, avoids unnecessary hashing)
*/
class Manifest
{
    /**
    * Debug options for controlling manifest behavior from commands
    * Set by commands like rsx:manifest:build to control processing
    */
    public static $_debug_options = [];

    /**
    * Special directories that are excluded from manifest
    * @deprecated Use config('rsx.manifest.excluded_dirs') instead
    */
    public const EXCLUDED_DIRS = ['resource', 'public', 'vendor', 'node_modules', '.git', 'storage'];

    /**
    * File extensions to process
    * @deprecated Use ExtensionRegistry::get_all_extensions() instead
    */
    public const PROCESSABLE_EXTENSIONS = ['php', 'js', 'jsx', 'ts', 'tsx', 'phtml', 'scss', 'less', 'css', 'blade.php'];

    /**
    * Path to the cache file, RELATIVE to the storage root (storage_path()).
    */
    public const CACHE_FILE = 'rsx-build/manifest_data.php';

    /**
    * The loaded manifest data structure:
    * [
    *   'generated' => datetime string,
    *   'hash' => sha512 hash of data,
    *   'data' => ['files' => [...file metadata...]]
    * ]
    */
    public static ?array $data = null;

    /**
    * Whether data has been loaded
    */
    public static bool $_has_init = false;

    /**
    * Flag to signal manifest needs to restart due to file rename
    */
    public static bool $_needs_manifest_restart = false;

    // The manifest kernel instance (cached) (???)
    public static ?ManifestKernel $kernel = null;

    public static $_manifest_compile_lock;

    public static $_get_rsx_files_cache = null;

    public static array $_has_changed_cache = [];

    public static bool $_has_manifest_ready = false;

    public static bool $_manifest_is_bad = false;

    // Track if we've already shown the manifest rescan message this page load
    public static bool $__shown_rescan_message = false;

    // Files that changed in the most recent manifest scan (for incremental code quality checks)
    public static array $_changed_files = [];

    // Flag to allow forced rebuilding in production-like modes (used by rsx:prod:build)
    public static bool $_force_build = false;

    // True when this process's init() actually (re)scanned/rebuilt the manifest
    // (incremental update WITH changes, or a no-cache full build). Stays false on a
    // warm boot that loaded a valid cache. Drives the rsx.rebuilt* lifecycle events
    // and is exposed cheaply via rebuild_occurred(). One value per process.
    public static bool $_rebuild_occurred = false;

    // ========================================
    // Query Methods
    // ========================================

    /**
    * Get all manifest data (just the files, not metadata)
    */
    public static function get_all(): array
    {
        static::init();

        return static::$data['data']['files'] ?? [];
    }

    /**
    * Get the autoloader class map for simplified class name resolution
    * @return array Map of simple class names to arrays of FQCNs
    */
    public static function get_autoloader_class_map(): array
    {
        static::init();

        return static::$data['data']['autoloader_class_map'] ?? [];
    }

    /**
    * Get the list of files that changed in the most recent manifest scan
    *
    * Used by code quality rules that need to know which files changed for
    * incremental processing. Returns empty array if manifest was fully rebuilt.
    *
    * @return array Array of relative file paths that changed
    */
    public static function get_changed_files(): array
    {
        return static::$_changed_files;
    }

    /**
    * Whether this process's init() actually (re)built the manifest.
    *
    * True when the scan found changed files (incremental) or performed a no-cache
    * full build; false on a warm boot that loaded a valid cache. Cheap in-memory
    * introspection for callers and tests (mirrors the rsx.ready payload's `rebuilt`).
    */
    public static function rebuild_occurred(): bool
    {
        return static::$_rebuild_occurred;
    }

    /**
    * Get data for a specific file
    */
    public static function get_file(string $file_path): array
    {
        static::init();

        // Normalize path to forward slashes
        $file_path = str_replace('\\', '/', $file_path);

        // Convert to relative path if absolute
        $base_path_normalized = str_replace('\\', '/', base_path());
        if (str_starts_with($file_path, $base_path_normalized)) {
            $file_path = str_replace($base_path_normalized . '/', '', $file_path);
        }

        // Remove leading slash if present
        $file_path = ltrim($file_path, '/');

        if (!isset(static::$data['data']['files'][$file_path])) {
            throw new \RuntimeException("File not found in manifest: {$file_path}");
        }

        return static::$data['data']['files'][$file_path];
    }

    /**
    * Find a PHP class by name
    */
    public static function php_find_class(string $class_name): string
    {
        return _Manifest_PHP_Reflection_Helper::php_find_class($class_name);
    }

    /**
    * Find a PHP class by fully qualified name
    */
    public static function find_php_fqcn(string $fqcn): string
    {
        return _Manifest_PHP_Reflection_Helper::find_php_fqcn($fqcn);
    }

    /**
    * Get manifest metadata by PHP class name
    * This is a convenience method that finds the class and returns its metadata
    */
    public static function php_get_metadata_by_class(string $class_name): array
    {
        return _Manifest_PHP_Reflection_Helper::php_get_metadata_by_class($class_name);
    }

    /**
    * Get manifest metadata by PHP fully qualified class name
    * This is a convenience method that finds the class and returns its metadata
    */
    public static function php_get_metadata_by_fqcn(string $fqcn): array
    {
        return _Manifest_PHP_Reflection_Helper::php_get_metadata_by_fqcn($fqcn);
    }

    /**
    * Find a JavaScript class
    */
    public static function js_find_class(string $class_name): string
    {
        return _Manifest_JS_Reflection_Helper::js_find_class($class_name);
    }

    /**
    * Find a view by ID
    */
    public static function find_view(string $id): string
    {
        return _Manifest_Reflection_Helper::find_view($id);
    }

    /**
    * Find a view by RSX ID (path-agnostic identifier)
    */
    public static function find_view_by_rsx_id(string $id): string
    {
        return _Manifest_Reflection_Helper::find_view_by_rsx_id($id);
    }

    /**
    * Get path for a file by its filename only (quick and dirty lookup)
    *
    * This is a convenience method for finding files when you know the filename is unique.
    * Only works for files in the /rsx directory. Fatal errors if:
    * - File not found in manifest
    * - Multiple files with the same name exist
    * - File is outside /rsx directory
    *
    * @param string $filename Just the filename with extension (e.g., "Counter_Widget.jqhtml")
    * @return string The relative path to the file (e.g., "rsx/app/demo/components/Counter_Widget.jqhtml")
    * @throws RuntimeException If file not found, multiple matches, or outside /rsx
    */
    public static function get_path_by_filename(string $filename): string
    {
        return _Manifest_Reflection_Helper::get_path_by_filename($filename);
    }

    /**
    * Get all classes extending a parent (filters out abstract classes by default)
    * Returns array of class metadata indexed by class name
    */
    public static function php_get_extending(string $parentclass): array
    {
        return _Manifest_PHP_Reflection_Helper::php_get_extending($parentclass);
    }

    /**
    * Get all JavaScript classes extending a parent
    * Returns array of class metadata indexed by class name
    */
    public static function js_get_extending(string $parentclass): array
    {
        return _Manifest_JS_Reflection_Helper::js_get_extending($parentclass);
    }

    /**
    * Check if a class is a subclass of another by traversing the inheritance chain
    *
    * @param string $subclass The child class name (simple name, not FQCN)
    * @param string $superclass The parent class name to check for (simple name, not FQCN)
    * @return bool True if subclass extends superclass (directly or indirectly), false otherwise
    */
    public static function php_is_subclass_of(string $subclass, string $superclass): bool
    {
        return _Manifest_PHP_Reflection_Helper::php_is_subclass_of($subclass, $superclass);
    }

    /**
    * Check if a PHP class is abstract
    *
    * @param string $class_name The class name to check (simple name, not FQCN)
    * @return bool True if the class is abstract, false if concrete or not found
    */
    public static function php_is_abstract(string $class_name): bool
    {
        return _Manifest_PHP_Reflection_Helper::php_is_abstract($class_name);
    }

    /**
    * Get the full inheritance lineage (ancestry) of a PHP class
    *
    * Returns an array of parent class names from immediate parent to top-level ancestor.
    * Example: For class C extends B extends A, returns ['B', 'A']
    *
    * @param string $class_name The class name (FQCN or simple name)
    * @return array Array of parent class simple names in order from immediate parent to root
    */
    public static function php_get_lineage(string $class_name): array
    {
        return _Manifest_PHP_Reflection_Helper::php_get_lineage($class_name);
    }

    /**
    * Check if a class name corresponds to a PHP model class (exists in models index)
    *
    * This is used by the JS model system to recognize PHP model class names that may
    * appear in JS inheritance chains but don't exist as JS classes in the manifest.
    * PHP models like "Project_Model" generate JS stubs during bundle compilation.
    *
    * @param string $class_name The class name to check
    * @return bool True if this is a PHP model class name
    */
    public static function is_php_model_class(string $class_name): bool
    {
        return _Manifest_PHP_Reflection_Helper::is_php_model_class($class_name);
    }

    /**
    * Check if a class is a subclass of another by traversing the inheritance chain
    *
    * @param string $subclass The child class name (simple name, not FQCN)
    * @param string $superclass The parent class name to check for (simple name, not FQCN)
    * @return bool True if subclass extends superclass (directly or indirectly), false otherwise
    */
    public static function js_is_subclass_of(string $subclass, string $superclass): bool
    {
        return _Manifest_JS_Reflection_Helper::js_is_subclass_of($subclass, $superclass);
    }

    /**
    * Get the complete inheritance chain for a JavaScript class
    * Returns array of parent class names in order from immediate parent to root
    *
    * @param string $class_name The class name to get lineage for
    * @return array Array of parent class names (empty if class not found or has no parents)
    * Example: If A extends B and B extends C, js_get_lineage('A') returns ['B', 'C']
    */
    public static function js_get_lineage(string $class_name): array
    {
        return _Manifest_JS_Reflection_Helper::js_get_lineage($class_name);
    }

    /**
    * Get all direct subclasses of a given PHP class using the pre-built index
    *
    * @param string $class_name The parent class name (simple name, not FQCN)
    * @param bool $concrete_only Whether to filter out abstract classes (default: true)
    * @return array Array of subclass names, or empty array if class not found or has no children
    */
    public static function php_get_subclasses_of(string $class_name, bool $concrete_only = true): array
    {
        return _Manifest_PHP_Reflection_Helper::php_get_subclasses_of($class_name, $concrete_only);
    }

    /**
    * Get all direct subclasses of a given JavaScript class using the pre-built index
    *
    * @param string $class_name The parent class name
    * @return array Array of subclass names, or empty array if class not found or has no children
    */
    public static function js_get_subclasses_of(string $class_name): array
    {
        return _Manifest_JS_Reflection_Helper::js_get_subclasses_of($class_name);
    }

    /**
    * Get all classes with a specific attribute
    */
    public static function get_with_attribute(string $attribute_class): array
    {
        return _Manifest_Reflection_Helper::get_with_attribute($attribute_class);
    }

    /**
    * Get all routes from the manifest
    *
    * Returns unified route structure: $routes[$pattern] => route_data
    * where route_data contains:
    *   - methods: ['GET', 'POST']
    *   - type: 'spa' | 'standard'
    *   - class: Full class name
    *   - method: Method name
    *   - file: File path
    *   - require: Auth requirements
    *   - js_action_class: (SPA routes only) JavaScript action class
    */
    public static function get_routes(): array
    {
        return _Manifest_Reflection_Helper::get_routes();
    }

    /**
    * Get statistics about the manifest
    */
    public static function get_stats(): array
    {
        static::init();
        $files = static::get_all();

        $stats = [
            'total_files' => count($files),
            'php' => 0,
            'js' => 0,
            'blade' => 0,
            'scss' => 0,
            'css' => 0,
            'other' => 0,
            'classes' => 0,
            'routes' => 0,
        ];

        foreach ($files as $file => $metadata) {
            $ext = $metadata['extension'] ?? '';

            switch ($ext) {
                case 'php':
                    $stats['php']++;
                    break;
                case 'blade.php':
                    $stats['blade']++;
                    break;
                case 'js':
                case 'jsx':
                case 'ts':
                case 'tsx':
                    $stats['js']++;
                    break;
                case 'scss':
                case 'less':
                    $stats['scss']++;
                    break;
                case 'css':
                    $stats['css']++;
                    break;
                default:
                    $stats['other']++;
            }

            if (isset($metadata['class'])) {
                $stats['classes']++;
            }
        }

        $routes = static::get_routes();
        $stats['routes'] = count($routes);

        return $stats;
    }

    /**
    * Check if manifest is built
    */
    public static function is_built(): bool
    {
        return file_exists(static::_get_cache_file_path());
    }

    /**
    * Get files from manifest by directory path
    *
    * @param string $directory Directory path without wildcards (e.g., 'rsx/ui')
    * @return array Array of manifest entries for files in the directory
    */
    public static function get_files_by_dir(string $directory): array
    {
        static::init();

        $files = [];
        // Normalize directory to forward slashes
        $directory = str_replace('\\', '/', $directory);
        $directory = rtrim($directory, '/'); // Remove trailing slash if present

        // Check if we have cached data
        if (empty(static::$data['data']['files'])) {
            return $files;
        }

        // Iterate through all files in the manifest
        foreach (static::$data['data']['files'] as $file_path => $file_data) {
            // Normalize the file path to use forward slashes
            $normalized_path = str_replace('\\', '/', $file_path);

            // Check if the file is in the specified directory
            if (str_starts_with($normalized_path, $directory . '/')) {
                $files[$file_path] = $file_data;
            }
        }

        // Sort alphabetically by filename to ensure deterministic behavior and prevent race condition bugs
        ksort($files);

        return $files;
    }

    /**
    * Get the full manifest structure including metadata
    * Used for debugging and manifest:dump command
    * Returns by reference to avoid copying large array
    */
    public static function &get_full_manifest(): array
    {
        static::init();

        return static::$data;
    }

    /**
     * Check if current CLI command is "safe" - doesn't require manifest to be built
     *
     * These commands can run in production mode without a pre-built manifest because
     * they don't actually use manifest data (e.g., rsx:clean just deletes directories).
     */
    protected static function _is_safe_command(): bool
    {
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];
        if (count($argv) < 2) {
            return false;
        }

        // When a sealed prod build is in force, the ONLY context permitted to
        // bypass the pre-built-manifest requirement is an authorized rebuild
        // (rsx:prod:enable / rsx:prod:refresh, which pass the --authorized
        // invocation flag to the build subprocess). rsx:clean is refused entirely
        // while sealed, and rsx:mode:set delegates to the authorized enable/disable
        // commands - so neither may claim "safe" status here anymore.
        if (\App\RSpade\Core\Prod\Rsx_Prod_Seal::is_sealed()) {
            return \App\RSpade\Core\Prod\Rsx_Prod_Seal::is_authorized();
        }

        // Not sealed: these commands can run without a pre-built manifest because
        // they build it themselves, tear it down, or manage the build lifecycle.
        // The prod-mode lifecycle commands MUST be able to boot even when the
        // manifest is absent (e.g. after an interrupted build, or to recover a
        // prod-ish RSX_MODE back to development).
        $safe_commands = [
            'rsx:clean',
            'rsx:prod:build',    // Builds the manifest itself
            'rsx:mode:set',      // Delegates to the prod-mode commands
            'rsx:prod:enable',   // Runs the build pipeline (may rebuild from scratch)
            'rsx:prod:refresh',  // Rebuilds the sealed assets
            'rsx:prod:disable',  // Returns to development (recovery)
            'rsx:prod:verify',   // Inspects the seal; boots to report drift
        ];

        $command = $argv[1] ?? '';
        return in_array($command, $safe_commands, true);
    }

    /**
     * The always-runnable escape hatch: CLI invocations that skip framework/manifest
     * boot entirely, so they still work when a manifest-time code quality violation
     * has poisoned the manifest and every other command aborts.
     *
     * This is the same carve-out rsx:clean has always had (previously a hardcoded
     * string in Rsx_Framework_Provider::boot), widened to the pure-introspection
     * invocations. Membership requires needing NO manifest data whatsoever:
     *
     *   - rsx:clean  - deletes build artifacts; the remediation command itself
     *   - rsx:man    - prints static man page .txt files
     *   - list/help  - Symfony's own command introspection
     *   - bare `php artisan`, --version/-V/-h/--help - Symfony introspection
     *
     * rsx:health is deliberately ABSENT: its check inventory is discovered through
     * manifest attribute scanning (Manifest::get_with_attribute('Health_Check')), so
     * without the manifest it would report an empty or stale check set - a silent
     * under-report, which is worse than refusing to run.
     *
     * The skip is read-only: nothing is loaded, rebuilt, or written, so the poison
     * flag on disk survives and the next real command re-fires the violation.
     */
    public static function __cli_skips_manifest_boot(): bool
    {
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];

        // Bare `php artisan` - Symfony prints the command list
        if (count($argv) < 2) {
            return true;
        }

        $introspection_commands = [
            'rsx:clean',
            'rsx:man',
            'list',
            'help',
            '--version',
            '-V',
            '--help',
            '-h',
        ];

        return in_array($argv[1], $introspection_commands, true);
    }

    /**
     * Boot-safe check for a CLI invocation FLAG by inspecting $_SERVER['argv'].
     *
     * Pre-handler code (Manifest::init, the seal authorization check) runs during
     * framework boot, before any command's handle() executes - so per-invocation
     * intent cannot come from a command handler or $this->option() at that point.
     * Reading argv is the sanctioned channel for boot-time code to see invocation
     * flags, the same technique _is_safe_command() uses to read the command name.
     *
     * Invocation intent is ALWAYS a --flag, never an environment variable: env vars
     * describe the ENVIRONMENT (PATH-like, deployment facts), not the parameters of
     * a single program invocation. This helper is the boot-time replacement for the
     * old KEY=VALUE env prefixes the prod-mode pipeline used to carry.
     */
    public static function __cli_has_flag(string $flag): bool
    {
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        return in_array($flag, $_SERVER['argv'] ?? [], true);
    }

    /**
    * TODO: UPDATE DOCUMENTATION
    * Initialize and ensure manifest is loaded
    * Handles all loading/rebuilding logic internally:
    * - If already initialized, returns immediately
    * - If cache exists, loads it and updates only changed files
    * - If no cache exists, processes all files (equivalent to rebuild)
    */
    public static function init(): void
    {
        // Already initialized, nothing to do
        if (static::$_has_init) {
            return;
        }

        static::$_has_init = true;

        // Scan handles both incremental updates and full rebuilds

        // Load cached data if it exists
        $cache_file_path = self::_get_cache_file_path();
        console_debug('MANIFEST', 'Checking for manifest cache', $cache_file_path);
        $loaded_cache = self::_load_cached_data();

        // In production-like modes (debug/production), require a pre-built manifest.
        // A (re)build is permitted when either:
        //   - $_force_build is set in-process (rsx:prod:build's handler sets it), or
        //   - this is a "safe" lifecycle command that builds/manages the manifest.
        // _is_safe_command() already grants rsx:prod:build boot access (and, when a
        // sealed build is in force, gates that on the `--authorized` invocation flag
        // via Rsx_Prod_Seal::is_authorized()), so the old RSX_FORCE_BUILD env token
        // was redundant with it and has been removed. Invocation intent lives in
        // flags (argv), never in environment variables.
        $force_build = self::$_force_build || self::_is_safe_command();
        if (Rsx::is_production() && !$force_build) {
            if (!$loaded_cache) {
                $rebuild_command = \App\RSpade\Core\Prod\Rsx_Prod_Seal::is_sealed()
                    ? 'rsx:prod:refresh'
                    : 'rsx:prod:build';
                throw new \RuntimeException(
                    "Manifest not built for production mode. Run: php artisan {$rebuild_command}"
                );
            }

            console_debug('MANIFEST', 'Manifest cache loaded (production mode)');
            self::post_init();
            self::__fire_lifecycle_events();

            return;
        }

        // Development mode: validate cache and rebuild if needed
        if ($loaded_cache) {
            console_debug('MANIFEST', 'Manifest cache loaded (development mode), validating...');
            if (self::_validate_cached_data()) {
                console_debug('MANIFEST', 'Manifest is valid');

                self::post_init();
                self::__fire_lifecycle_events();

                return;
            }
            console_debug('MANIFEST', 'Manifest is out of date');
        } else {
            console_debug('MANIFEST', 'Manifest could not be loaded');
        }

        console_debug('MANIFEST', 'Aquiring manifest build lock');

        // Get a manifest build lock
        // SYSTEM lock: the manifest cache is an artifact on THIS box's local disk, so a
        // concurrent build on another server is not a conflict. Waits forever - a build can
        // legitimately take minutes and the process behind it has nothing to do but wait.
        self::$_manifest_compile_lock = RsxLocks::system_lock(RsxLocks::LOCK_MANIFEST_BUILD);

        console_debug('MANIFEST', 'Manifest build lock acquired, checking to see if manifest cache was updated');

        // Maybe the manifest was regenerated again? double check now that we are exclusive
        $cache_loaded = self::_load_cached_data();
        $cache_valid = self::_validate_cached_data();

        if (!$cache_valid) {
            // Log only for full rebuilds (cache doesn't exist)
            // Incremental updates are normal and don't need logging
            $cache_file = self::_get_cache_file_path();
            if (!$cache_loaded) {
                console_debug('MANIFEST', 'Manifest cache does not exist, performing full rebuild', $cache_file);
            }

            // Content changed (or no cache existed): scan/update work begins here.
            // This is the single point BOTH rebuild paths pass through - an incremental
            // update with changes and a no-cache full build - so it is the honest place
            // to record that this process rebuilt (drives the rsx.rebuilt* events).
            static::$_rebuild_occurred = true;

            // zug zug
            self::_refresh_manifest();
            // jobs done
            console_debug('MANIFEST', 'Refreshing manifest *completed*');

            // Verify cache was written successfully
            if (file_exists($cache_file)) {
                $file_size = filesize($cache_file);
                $file_perms = substr(sprintf('%o', fileperms($cache_file)), -4);
                console_debug('MANIFEST', 'Cache file written successfully', [
                    'path' => $cache_file,
                    'size' => $file_size,
                    'permissions' => $file_perms,
                ]);
            } else {
                console_debug('MANIFEST', 'WARNING: Cache file does not exist after rebuild!', $cache_file);
            }
        } else {
            console_debug('MANIFEST', 'Manifest cache is valid, no rebuild needed');
        }

        RsxLocks::release_lock(self::$_manifest_compile_lock);
        console_debug('MANIFEST', 'Released manifest build lock');

        self::post_init();
        self::__fire_lifecycle_events();
    }

    /**
    * Fire the framework's manifest lifecycle events at the tail of init().
    *
    * THIS IS THE "post manifest rebuild" / "manifest ready" extension point. It runs
    * ONCE per process (init() early-returns on $_has_init, so the body runs once and
    * this fires from exactly one of init()'s three exit paths), immediately AFTER all
    * init work (post_init has registered the autoloader and loaded classless files),
    * just before init() returns control.
    *
    * Fired in order:
    *   1. rsx.rebuilt          - only if this process rebuilt (dev: on the next request
    *                             after any source change; prod: once inside the authorized
    *                             rsx:prod:build during enable/refresh). Payload: the changed
    *                             file list under 'files' (see below).
    *   2. rsx.rebuilt.dev|.prod - same condition, immediately after; .prod when
    *                             Rsx::is_production() (ANY prod mode incl. debug), else .dev.
    *   3. rsx.ready            - ALWAYS (every init completion, warm boot included).
    *                             Payload: ['rebuilt' => bool].
    *
    * Handlers run INLINE here, on the request/CLI boot path - heavy work MUST be handed
    * to Task::dispatch(), never done synchronously. A handler may safely call manifest
    * query APIs (init has completed). A handler that WRITES source files does NOT loop
    * this process (the scan already ran); it dirties the tree for the NEXT request only.
    *
    * The 'files' payload is the HONEST minimum the scanner exposes: a flat list of the
    * relative paths that changed (new + modified combined). The scan does not cheaply
    * distinguish added-vs-modified, nor track removed files as a list, so no such split
    * is invented here.
    *
    * public + __ prefix: framework-internal (never call from app code); public only so
    * the lifecycle-event test can drive it as a seam.
    */
    public static function __fire_lifecycle_events(): void
    {
        if (static::$_rebuild_occurred) {
            $payload = ['files' => static::$_changed_files];

            Rsx::trigger_action('rsx.rebuilt', $payload);

            $mode_event = Rsx::is_production() ? 'rsx.rebuilt.prod' : 'rsx.rebuilt.dev';
            Rsx::trigger_action($mode_event, $payload);
        }

        Rsx::trigger_action('rsx.ready', ['rebuilt' => static::$_rebuild_occurred]);
    }

    /**
     * Post-initialization hook called after manifest is fully loaded
     *
     * Called at the end of init() after manifest data is loaded (either from cache
     * or after scanning/rebuilding). At this point, the manifest is complete and
     * ready for queries.
     *
     * Current responsibilities:
     * - Sets $_has_manifest_ready flag to indicate manifest is available
     * - Registers the autoloader (which depends on manifest data)
     * - Loads classless PHP files (helpers, constants, procedural code)
     *
     * This is the appropriate place to perform operations that require the complete
     * manifest to be available, such as loading non-class PHP files that were
     * indexed during the scan.
     */
    public static function post_init() {
        self::$_has_manifest_ready = true;
        \App\RSpade\Core\Autoloader::register();

        // Load classless PHP files (helper functions, constants, etc.)
        $classless_files = self::$data['data']['classless_php_files'] ?? [];
        foreach ($classless_files as $file_path) {
            $full_path = base_path($file_path);
            if (file_exists($full_path)) {
                include_once $full_path;
            }
        }
    }

    /**
    * Clear the manifest cache
    */
    public static function clear(): void
    {
        static::$data = [
            'generated' => date('Y-m-d H:i:s'),
            'hash' => '',
            'data' => [
                'files' => [],
                'autoloader_class_map' => [],
            ],
        ];

        static::$_has_init = false;
        static::$_has_manifest_ready = false;

        $cache_file = static::_get_cache_file_path();
        if (file_exists($cache_file)) {
            unlink($cache_file);
        }
    }

    /**
     * Unlink the manifest cache file only (fast rebuild trigger)
     *
     * This removes only the manifest cache file, preserving all parsed AST data
     * and incremental caches. On next load, the manifest will do a full scan and
     * reindex but will reuse existing parsed metadata where files haven't changed.
     *
     * This is much faster than rsx:clean which wipes all caches including parsed
     * AST data, forcing expensive re-parsing of all PHP/JS files.
     *
     * Use this after database migrations or schema changes that affect model
     * metadata without changing the actual source code.
     */
    public static function _unlink_cache(): void
    {
        _Manifest_Cache_Helper::_unlink_cache();
    }

    /**
    * Signal that manifest needs to restart due to file rename
    * Called by code quality rules when auto-renaming files
    */
    public static function flag_needs_restart(): void
    {
        static::$_needs_manifest_restart = true;
    }

    // =========================================================================
    // Build Mode Semantic Helpers
    // =========================================================================

    /**
     * Check if manifest/bundles should auto-rebuild on file changes
     *
     * Only in development mode.
     */
    public static function _should_auto_rebuild(): bool
    {
        return \App\RSpade\Core\Rsx::is_development();
    }

    /**
     * Check if JS/CSS should be minified
     *
     * Strict production mode ONLY. Debug mode keeps bundles unminified so the
     * shipped code stays readable and its sourcemap comments survive (minification
     * is what strips them). This is the single source of truth for the minify
     * decision - BundleCompiler consults it for both JS and CSS.
     */
    public static function _should_minify(): bool
    {
        return \App\RSpade\Core\Rsx::is_production() && !\App\RSpade\Core\Rsx::is_debug();
    }

    /**
     * Whether inline sourcemaps survive into the shipped bundle.
     *
     * There is no independent sourcemap toggle: the compiler never adds maps, it
     * only removes the inline sourceMappingURL comments carried by the source, and
     * that removal happens inside minification (minify-server strips them). Maps
     * therefore survive exactly when minification does NOT run - i.e. in
     * development and debug, and never in strict production. This helper documents
     * that truth as the logical inverse of _should_minify(); it does not gate a
     * distinct code path.
     */
    public static function _should_inline_sourcemaps(): bool
    {
        return !self::_should_minify();
    }

    /**
     * Check if CDN assets should be cached locally and served from /_vendor/
     *
     * In every production-like mode (debug AND strict production). Development
     * loads CDN assets directly from their external URLs; both sealed-build modes
     * cache them locally so the build is self-contained. This is the single source
     * of truth for the CDN-caching decision (BundleCompiler::_prepare_cdn_assets).
     */
    public static function _should_cache_cdn(): bool
    {
        return \App\RSpade\Core\Rsx::is_production();
    }

    /**
     * Check if console_debug() calls should be stripped from compiled output
     *
     * Strict production mode ONLY. Debug builds keep console_debug working. Feeds
     * the Terser pure_funcs strip flag on the minify RPC request (BundleCompiler).
     */
    public static function _should_strip_console_debug(): bool
    {
        return \App\RSpade\Core\Rsx::is_production() && !\App\RSpade\Core\Rsx::is_debug();
    }

    /**
     * Check if the console_debug config block should be injected into window.rsxapp
     *
     * Development AND debug modes. This is what makes browser console_debug() work
     * in debug mode (matching the root CLAUDE.md mode table). Strict production
     * omits the block entirely - and its call sites are stripped from the bundle
     * by _should_strip_console_debug().
     */
    public static function _should_include_debug_info(): bool
    {
        return !\App\RSpade\Core\Rsx::is_production() || \App\RSpade\Core\Rsx::is_debug();
    }

    /**
     * Normalize class name to simple name (strip namespace qualifiers)
     *
     * Since RSX enforces unique simple class names across the codebase,
     * we normalize all class references to simple names for consistent
     * comparison and storage. FQCNs are only needed at actual class loading time.
     *
     * Examples:
     *   \Rsx\Lib\DataGrid → DataGrid
     *   Rsx\Lib\DataGrid → DataGrid
     *   DataGrid → DataGrid
     *
     * @param string $class_name Class name in any format (with or without namespace)
     * @return string Simple class name without namespace
     */
    public static function _normalize_class_name(string $class_name): string
    {
        return _Manifest_PHP_Reflection_Helper::_normalize_class_name($class_name);
    }

    /**
    * Get the build key for cache prefixing
    * This is the manifest hash that uniquely identifies the current code state
    *
    * IMPORTANT: This will throw a fatal error if called before the manifest is loaded
    * The manifest must have completed loading (either from cache or scan) before this is available
    *
    * @return string The manifest build key (hash)
    * @throws RuntimeException if manifest is not yet loaded
    */
    public static function get_build_key(): string
    {
        // Check if manifest has been loaded
        if (!static::$_has_manifest_ready) {
            shouldnt_happen('Manifest::get_build_key() called before manifest was loaded. The manifest must complete loading before the build key is available.');
        }

        // Also verify we actually have a hash
        if (empty(static::$data['hash'])) {
            shouldnt_happen('Manifest is loaded but has no build hash. This should not happen.');
        }

        return static::$data['hash'];
    }

    // ------------------------------------------------------------------------
    // ---- RSpade Public Internal Methods:
    // ------------------------------------------------------------------------

    public static function _refresh_manifest()
    {
        manifest_start:

        // Reset caches at the beginning of each pass (important for restarts)
        static::$_needs_manifest_restart = false;
        self::$_get_rsx_files_cache = null;

        // Reset manifest structure, retaining only existing files data
        $existing_files = static::$data['data']['files'] ?? [];
        static::$data = [
            'generated' => date('Y-m-d H:i:s'),
            'hash' => '',
            'data' => [
                'files' => $existing_files,
                'autoloader_class_map' => [],
                'routes' => [],
            ],
        ];

        // =======================================================
        // Phase 1: Collect all files in manifest scan directories
        // =======================================================
        $files = static::_get_rsx_files();
        $changes = false;

        // Check if any files have changed
        $files_to_process = [];
        foreach ($files as $file) {
            if (static::_has_changed($file)) {
                $files_to_process[] = $file;
                $changes = true;
            }
        }

        // Store changed files for incremental code quality checks
        static::$_changed_files = $files_to_process;

        console_debug('MANIFEST', 'Phase 1: File Discovery - ' . count($files) . ' files, ' . count($files_to_process) . ' changed');

        // If any files have changed and we're not in production, run auto-reformat
        if ($changes && env('APP_ENV') !== 'production') {
            $formatter_path = base_path('bin/rsx-format');
            // Lets rethink this before we enable iut again
            // if (file_exists($formatter_path)) {
            //     // Run the formatter with the hidden --auto-reformat-periodic flag
            //     // This ensures formatting happens BEFORE any RSX file is loaded/parsed
            //     $command = escapeshellcmd($formatter_path) . ' --auto-reformat-periodic 2>&1';
            //     \exec_safe($command, $output, $return_code);

            //     // Only log errors, not normal operation
            //     if ($return_code !== 0) {
            //         error_log('RSX auto-reformat-periodic failed: ' . implode("\n", $output));
            //     }
            // }
        }

        // =======================================================
        // Phase 2: Parse Metadata - Extract basic metadata via token parsing
        // =======================================================
        console_debug('MANIFEST', 'Phase 2: Parse Metadata - Processing ' . count($files_to_process) . ' files');

        // Filter out storage files from the manifest
        static::$data['data']['files'] = array_filter(
            static::$data['data']['files'],
            function ($key) {
                return !str_starts_with($key, 'storage/');
            },
            ARRAY_FILTER_USE_KEY
        );

        // Remove deleted files from manifest BEFORE processing
        $existing_files = array_flip($files);
        foreach (array_keys(static::$data['data']['files']) as $cached_file) {
            if (!isset($existing_files[$cached_file])) {
                unset(static::$data['data']['files'][$cached_file]);
                $changes = true;
            }
        }

        foreach ($files_to_process as $file) {
            static::$data['data']['files'][$file] = static::_process_file($file);
        }

        // Skip validation message if no changes detected
        if (!$changes && file_exists(static::_get_cache_file_path())) {
            // This case shouldn't happen as it should have been caught earlier
            // but we don't need to log it as it's not an error condition
        }

        // ==================================================================================
        // PHP FIXER INTEGRATION POINT
        // ==================================================================================
        // CRITICAL: Php_Fixer MUST run BEFORE _check_unique_base_class_names() so that
        // when a class override is detected and framework files are renamed to .upstream,
        // all use statements have already been updated to point to the rsx/ location.
        // This prevents autoloader failures when the manifest restarts.
        //
        // WHAT PHP_FIXER DOES:
        // 1. Fixes namespaces to match file paths
        // 2. Redirects use statements to correct FQCN based on manifest (rsx/ takes priority)
        // 3. Replaces FQCNs like \Rsx\Models\User_Model with simple names User_Model
        // 4. Adds #[Relationship] attributes to model ORM methods
        // 5. Removes leading backslashes from attributes: #[\Route] → #[Route]
        //
        // SMART REBUILDING:
        // - Tracks SHA1 hash of all class structures (ClassName:ParentClass)
        // - If structure changed: Fixes ALL files (cascading updates needed)
        // - If structure unchanged: Fixes ONLY $files_to_process (incremental)
        //
        // RE-PARSING LOOP BELOW:
        // - If Php_Fixer modified files, we MUST re-parse them
        // - This updates manifest with corrected namespace/class/FQCN data
        // - Without this, manifest would reference old class locations
        // ==================================================================================

        $php_fixer_modified_files = [];
        if (!app()->environment('production')) {
            $php_fixer_modified_files = static::_run_php_fixer($files_to_process);

            // Re-parse files that Php_Fixer modified to update manifest with corrected metadata
            // This ensures namespace/class/fqcn data matches what's actually in the file
            // CRITICAL: Without this, we'd have stale FQCNs from before Php_Fixer ran
            if (!empty($php_fixer_modified_files)) {
                console_debug('MANIFEST', 'Re-parsing ' . count($php_fixer_modified_files) . ' files modified by Php_Fixer');
                foreach ($php_fixer_modified_files as $file_path) {
                    // Re-extract metadata with corrected namespace
                    $absolute_path = base_path($file_path);
                    $php_metadata = \App\RSpade\Core\PHP\Php_Parser::parse($absolute_path, static::$data);

                    // Update manifest with corrected metadata
                    static::$data['data']['files'][$file_path] = array_merge(
                        static::$data['data']['files'][$file_path],
                        $php_metadata
                    );

                    // Recalculate file hash since file was modified
                    clearstatcache(true, $absolute_path);
                    $updated_stat = stat($absolute_path);
                    static::$data['data']['files'][$file_path]['hash'] = sha1_file($absolute_path);
                    static::$data['data']['files'][$file_path]['mtime'] = $updated_stat['mtime'];
                    static::$data['data']['files'][$file_path]['size'] = $updated_stat['size'];
                }
            }
        }

        // ==================================================================================
        // CLASS OVERRIDE DETECTION
        // ==================================================================================
        // Check for duplicate class names. When rsx/ contains a class that also exists in
        // app/RSpade/, rename the framework file to .upstream and restart the manifest.
        // At this point, Php_Fixer has already updated use statements to point to rsx/.
        // ==================================================================================
        static::_check_unique_base_class_names();

        // If a class override was detected (rsx/ overriding app/RSpade/), restart manifest build
        if (static::$_needs_manifest_restart) {
            console_debug('MANIFEST', 'Class override detected, restarting manifest build');
            goto manifest_start;
        }

        // Override/restore renames are now settled for this rebuild (getting past the
        // restart check above means neither the restore nor the override pass changed
        // anything this iteration). Heal composer's committed classmap if any entry now
        // points at a framework file the override pass renamed to .upstream - otherwise
        // composer would `include` a missing file on future requests (tolerated at
        // runtime by Autoloader's scoped warning carve-out, but the on-disk data is
        // wrong). Rebuild-only cost; idempotent (a clean classmap triggers no dump).
        static::_validate_composer_classmap();

        // Phase 2 complete.  At this point we have a list of all files, and for php and js, their class data

        // =======================================================
        // Phase 3: Load Dependencies - Load PHP files in dependency order
        // =======================================================
        console_debug('MANIFEST', 'Phase 3: Load Dependencies');
        // Only load PHP files that have actually changed
        static::_load_changed_php_files($files_to_process);

        // Process code quality rule metadata extraction
        static::_process_code_quality_metadata($files_to_process);

        // =======================================================
        // Phase 4: Extract Reflection - Extract reflection data from PHP classes
        // =======================================================
        console_debug('MANIFEST', 'Phase 4: Extract Reflection');
        // Extract reflection data for changed PHP files only
        static::_extract_reflection_for_changed_files($files_to_process);

        // Collate files by classes - MUST be called after reflection extraction
        // so that abstract property is available for subclass filtering
        static::_collate_files_by_classes();

        // Check if a class override was detected and framework file renamed
        if (static::$_needs_manifest_restart) {
            console_debug('MANIFEST', 'Class override detected, restarting manifest build');
            goto manifest_start;
        }

        // Build event handler index from attributes
        static::_build_event_handler_index();

        // Build classless PHP files index
        static::_build_classless_php_files_index();

        // =======================================================
        // Phase 5: Process Modules - Run manifest support modules and build autoloader
        // =======================================================
        console_debug('MANIFEST', 'Phase 5: Process Modules');

        // Build autoloader class map
        // The major thing that happens here is this also scans app/RSpade for additional classes which arent on manifest,
        // which is a somewhat expensive operation (50 ms).  This is acceptable for a incremental manifest rebuild
        static::$data['data']['autoloader_class_map'] = static::_build_autoloader_class_map();

        // Register the RSX autoloader before running support modules. Support modules
        // (e.g. Model_ManifestSupport) include_once unchanged model files to reflect on
        // them, which forces PHP to resolve their parent classes. On an incremental
        // rebuild where neither the model nor its abstract parent changed, Phase 3 loads
        // neither, so the parent must resolve via autoload. The RSX autoloader is
        // otherwise only registered in post_init() (after this method returns), leaving
        // only Composer's PSR-4 loader active here, which cannot resolve RSX classes.
        // init() set $_has_init = true at its top, so the re-entrant init() inside
        // register() returns immediately; the autoloader reads php_classes /
        // autoloader_class_map, both built by Phase 4 above.
        \App\RSpade\Core\Autoloader::register();

        // Process manifest support modules
        $support_modules = config('rsx.manifest_support', []);
        foreach ($support_modules as $support_module_class) {
            if (!class_exists($support_module_class)) {
                throw new \RuntimeException("Manifest support module class not found: {$support_module_class}");
            }

            if (!self::php_is_subclass_of($support_module_class, ManifestSupport_Abstract::class)) {
                throw new \RuntimeException("Manifest support module must extend ManifestSupport_Abstract: {$support_module_class}");
            }

            if ($support_module_class::should_run()) {
                $support_module_class::process(static::$data);
            }
        }

        // Note: Validation checks have been moved to code quality rules that run at manifest-time

        // =======================================================
        // Phase 6: Generate Stubs - Generate JavaScript API and model stubs
        // =======================================================
        console_debug('MANIFEST', 'Phase 6: Generate Stubs');
        // Call generate_manifest_stubs on all registered integrations
        foreach (IntegrationRegistry::get_all() as $integration_class) {
            if (method_exists($integration_class, 'generate_manifest_stubs')) {
                $integration_class::generate_manifest_stubs(static::$data);
            }
        }

        // =======================================================
        // Phase 7: Save & Finalize - Save manifest, clear caches, run checks
        // =======================================================
        $php_class_count = count(static::$data['data']['php_classes'] ?? []);
        $js_class_count = count(static::$data['data']['js_classes'] ?? []);
        console_debug('MANIFEST', 'Phase 7: Saving manifest (' . count($files) . " files, {$php_class_count} PHP classes, {$js_class_count} JS classes)");

        static::_generate_vscode_stubs();
        static::_save();

        // Clear view cache when manifest changes to prevent stale @rsx_extends references
        // This ensures that renamed blade files with @rsx_extends are properly recompiled
        \Illuminate\Support\Facades\Artisan::call('view:clear', [], new \Symfony\Component\Console\Output\NullOutput());

        // Run manifest-time code quality checks (development only)
        // Skip during migrations - database may not be provisioned yet
        if (env('APP_ENV') !== 'production' && $changes && !static::_is_migration_context()) {
            static::_verify_database_provisioned();
            static::_run_manifest_time_code_quality_checks($files_to_process);
        }

        // Check if a file was auto-renamed and manifest needs to restart
        if (static::$_needs_manifest_restart) {
            console_debug('MANIFEST', 'File auto-renamed during code quality check, restarting manifest build');
            goto manifest_start;
        }
    }

    /**
    * Load a class and all its parent classes from manifest data
    *
    * This utility method ensures a class and its entire parent hierarchy
    * are loaded before doing reflection or other operations.
    * Classes are loaded in dependency order (parents first).
    * Used by stub generators and reflection extraction.
    *
    * @param string $fqcn Fully qualified class name to load
    * @param array $manifest_data The manifest data array
    * @return void
    * @throws \RuntimeException if class or parent cannot be loaded
    */
    public static function _load_class_hierarchy(string $fqcn, array $manifest_data): void
    {
        _Manifest_PHP_Reflection_Helper::_load_class_hierarchy($fqcn, $manifest_data);
    }

    /**
    * Mark manifest as bad to force rebuild on next load
    */
    public static function _set_manifest_is_bad(): void
    {
        static::$_manifest_is_bad = true;
        static::_save();
    }

    // ------------------------------------------------------------------------
    // ---- Private / Protected Methods:
    // ------------------------------------------------------------------------
    // DEAD CODE REMOVED: _generate_js_api_stubs()
    // Controller stub generation now handled by Controller_BundleIntegration
    // ------------------------------------------------------------------------

    // ------------------------------------------------------------------------
    // DEAD CODE REMOVED: _generate_js_model_stubs()
    // Model stub generation now handled by Database_BundleIntegration
    // ------------------------------------------------------------------------

    /**
    * Get or create the kernel instance
    */
    public static function _get_kernel(): ManifestKernel
    {
        return _Manifest_Cache_Helper::_get_kernel();
    }

    /**
    * Get the full cache file path
    */
    public static function _get_cache_file_path(): string
    {
        return _Manifest_Cache_Helper::_get_cache_file_path();
    }

    // move to lower soon
    public static function _validate_cached_data()
    {
        return _Manifest_Cache_Helper::_validate_cached_data();
    }

    /**
    * Check for duplicate base class names within the same file type
    *
    * This method handles two scenarios:
    * 1. RESTORE: If a .upstream file exists but no active override exists, restore it
    * 2. OVERRIDE: When a class exists in both rsx/ and app/RSpade/, rename framework to .upstream
    *
    * Throws a fatal error if duplicates exist within the same area (both rsx/ or both app/RSpade/)
    */
    public static function _check_unique_base_class_names(): void
    {
        _Manifest_Quality_Helper::_check_unique_base_class_names();
    }

    /**
    * Validate composer's committed classmap against the filesystem and regenerate it
    * (blocking composer dump-autoload) when the class-override rename/restore pass has
    * left an entry pointing at a renamed/removed file. Runs in the rebuild pipeline
    * immediately after the override pass settles. See
    * _Manifest_Quality_Helper::_validate_composer_classmap.
    */
    public static function _validate_composer_classmap(): void
    {
        _Manifest_Quality_Helper::_validate_composer_classmap();
    }

    /**
    * Restore orphaned .upstream files when their override no longer exists
    *
    * Scans all php.upstream files in the manifest. For each one, checks if a .php file
    * with the same class name exists. If not, the override was removed and we should
    * restore the framework file.
    */
    public static function _restore_orphaned_upstream_files(): void
    {
        _Manifest_Scanner_Helper::_restore_orphaned_upstream_files();
    }

    /**
    * Collate files by class names and build inheritance indices
    *
    * This method creates two types of indices for both JavaScript and PHP classes:
    *
    * 1. Class indices (js_classes, php_classes):
    *    Maps class names to their file metadata for O(1) lookups by class name
    *
    * 2. Subclass indices (js_subclass_index, php_subclass_index):
    *    Maps each parent class name to an array of its direct subclasses
    *    Example: Rsx_Controller_Abstract => ['Demo_Index_Controller', 'Backend_Controller', ...]
    *
    * The subclass indices enable efficient inheritance checking without iterating
    * through the entire manifest. Instead of O(n) complexity for finding subclasses,
    * we get O(1) direct subclass lookups.
    *
    * @return void
    */
    public static function _collate_files_by_classes()
    {
        return _Manifest_Builder_Helper::_collate_files_by_classes();
    }

    /**
     * Build event handler index from OnEvent attributes
     *
     * Scans all PHP files for methods with #[OnEvent] attributes and builds
     * an index of event_name => [handlers] for fast event dispatching.
     *
     * Index structure:
     * ['event_handlers'] => [
     *     'event.name' => [
     *         ['class' => 'Class_Name', 'method' => 'method_name', 'priority' => 100],
     *         ['class' => 'Other_Class', 'method' => 'other_method', 'priority' => 200],
     *     ]
     * ]
     *
     * Handlers are sorted by priority (lower numbers execute first).
     *
     * @return void
     */
    public static function _build_event_handler_index()
    {
        return _Manifest_Builder_Helper::_build_event_handler_index();
    }

    /**
     * Build index of classless PHP files
     *
     * Creates a simple array of file paths for all PHP files in the manifest
     * that do not contain a class. These files typically contain helper functions,
     * constants, or other procedural code that needs to be loaded during post_init().
     *
     * Stored in manifest data at: $data['data']['classless_php_files']
     */
    public static function _build_classless_php_files_index()
    {
        return _Manifest_Builder_Helper::_build_classless_php_files_index();
    }

    /**
    * Load changed PHP files and their dependencies
    *
    * This method loads only the PHP files that have changed and ensures
    * their parent class hierarchies are loaded first.
    *
    * @param array $changed_files Array of changed file paths
    * @return void
    */
    public static function _load_changed_php_files(array $changed_files): void
    {
        _Manifest_Scanner_Helper::_load_changed_php_files($changed_files);
    }

    /**
    * Extract reflection data only for changed files
    * Uses caching to avoid re-extracting unchanged files
    */
    public static function _extract_reflection_for_changed_files(array $changed_files): void
    {
        _Manifest_Scanner_Helper::_extract_reflection_for_changed_files($changed_files);
    }

    /**
    * Load all PHP files in dependency order
    * This ensures base classes are loaded before their subclasses
    * Must be called after Phase 2 (basic metadata extraction) completes
    * @deprecated Use _load_changed_php_files() for incremental builds
    */
    /**
    * Run Php_Fixer on all PHP files in rsx/ and app/RSpade/
    * Called before Phase 2 parsing to ensure all files are fixed
    *
    * SMART REBUILD STRATEGY:
    * This method implements an intelligent rebuild strategy to avoid unnecessary file writes:
    *
    * 1. STRUCTURE HASH: Creates SHA1 hash of "ClassName:ParentClass" for ALL classes
    *    - Detects when classes are added, removed, renamed, or inheritance changes
    *
    * 2. FULL REBUILD TRIGGERS:
    *    - New class added (may need new use statements elsewhere)
    *    - Class renamed (all references need updating)
    *    - Inheritance changed (may affect use statement resolution)
    *    → When triggered: Fix ALL PHP files in rsx/ and app/RSpade/
    *
    * 3. INCREMENTAL REBUILD:
    *    - Structure hash unchanged (no new/renamed classes)
    *    - Only fixes files that actually changed on disk
    *    → More efficient, avoids touching unchanged files
    *
    * WHY THIS MATTERS:
    * - use statement management depends on knowing all available classes
    * - FQCN replacement needs to check class name uniqueness
    * - When class structure changes, files referencing those classes need updating
    * - When structure stable, only changed files need processing
    *
    * @param array $changed_files List of changed files from Phase 1
    * @return array List of files that were modified by Php_Fixer
    */
    public static function _run_php_fixer(array $changed_files): array
    {
        return _Manifest_Scanner_Helper::_run_php_fixer($changed_files);
    }

    public static function _load_php_files_in_dependency_order(): void
    {
        _Manifest_Scanner_Helper::_load_php_files_in_dependency_order();
    }

    // /**
    //  * Extract reflection data for all PHP files
    //  * Must be called after Phase 3 (dependency loading) completes
    //  */
    // public static function __extract_all_reflection_data(): void
    // {
    //     if (!isset(static::$data['data']['files'])) {
    //         throw new \RuntimeException(
    //             'Fatal: Manifest::extract_all_reflection_data() called but manifest data structure is not initialized. ' .
    //             "This shouldn't happen - Phase 2 should have populated the files array."
    //         );
    //     }

    //     foreach (static::$data['data']['files'] as $file_path => &$metadata) {
    //         // Only process PHP files with classes
    //         if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
    //             continue;
    //         }

    //         if (!isset($metadata['fqcn'])) {
    //             continue;
    //         }
    //         var_dump($file_path);
    //         // Extract reflection data (class should already be loaded)
    //         static::_extract_reflection_data(base_path($file_path), $metadata['fqcn'], $metadata);
    //     }
    // }

    /**
    * Build the autoloader class map for simplified class name resolution
    * Maps simple class names to their fully qualified class names
    * @return array Map of simple names to arrays of FQCNs
    */
    public static function _build_autoloader_class_map(): array
    {
        return _Manifest_Builder_Helper::_build_autoloader_class_map();
    }

    /**
    * Scan a directory for PHP classes, excluding vendor directories
    * @param string $directory The directory to scan
    * @return array Map of simple class names to FQCNs
    */
    public static function _scan_directory_for_classes(string $directory): array
    {
        return _Manifest_Scanner_Helper::_scan_directory_for_classes($directory);
    }

    /**
    * Process a single file and extract comprehensive metadata
    * @param string $file_path Relative path to file
    */
    public static function _process_file(string $file_path): array
    {
        return _Manifest_Scanner_Helper::_process_file($file_path);
    }

    /**
    * Extract public static methods and their attributes using PHP reflection
    * Note: This is called in Phase 4 after all PHP files have been loaded
    */
    public static function _extract_reflection_data(string $file_path, string $full_class_name, array &$data): void
    {
        _Manifest_Scanner_Helper::_extract_reflection_data($file_path, $full_class_name, $data);
    }

    /**

    /**
    * Extract view information from template files
    */
    public static function _extract_view_info(string $file_path, array &$data): void
    {
        _Manifest_Scanner_Helper::_extract_view_info($file_path, $data);
    }

    /**
    * Check if a file has changed (expects relative path)
    */
    public static function _has_changed(string $file): bool
    {
        return _Manifest_Scanner_Helper::_has_changed($file);
    }

    /**
    * Get all files in configured scan directories (returns relative paths)
    */
    public static function _get_rsx_files(): array
    {
        return _Manifest_Scanner_Helper::_get_rsx_files();
    }

    /**
    * Load cached manifest data
    */
    public static function _load_cached_data()
    {
        return _Manifest_Cache_Helper::_load_cached_data();
    }

    /**
    * Validate manifest data for consistency
    */
    public static function _validate_manifest_data(): void
    {
        _Manifest_Cache_Helper::_validate_manifest_data();
    }

    /**
    * Check if metadata represents a controller class
    * @param array $metadata File metadata
    * @return bool True if class extends Rsx_Controller_Abstract
    */
    public static function _is_controller_class(array $metadata): bool
    {
        return _Manifest_Reflection_Helper::_is_controller_class($metadata);
    }

    /**
    * Generate VS Code IDE helper stubs for attributes and class aliases
    */
    public static function _generate_vscode_stubs(): void
    {
        _Manifest_Quality_Helper::_generate_vscode_stubs();
    }

    /**
    * Save manifest data to cache
    */
    public static function _save(): void
    {
        _Manifest_Cache_Helper::_save();
    }

    /**
    * Process code quality metadata extraction for changed files
    * This runs during Phase 3.5, after PHP classes are loaded but before reflection
    */
    public static function _process_code_quality_metadata(array $changed_files): void
    {
        _Manifest_Quality_Helper::_process_code_quality_metadata($changed_files);
    }

    /**
    * Run code quality checks during manifest scan
    * Only runs in development mode after manifest changes
    * Throws fatal exception on first violation found
    *
    * Supports two types of rules:
    * - Incremental rules (is_incremental() = true): Only check changed files
    * - Cross-file rules (is_incremental() = false): Run once with full manifest context
    *
    * @param array $changed_files Files that changed in this manifest scan
    */
    public static function _run_manifest_time_code_quality_checks(array $changed_files = []): void
    {
        _Manifest_Quality_Helper::_run_manifest_time_code_quality_checks($changed_files);
    }

    /**
     * Check if we're running in a migration context
     *
     * Returns true if running migrate, make:migration, or other DB setup commands.
     * Used to skip code quality checks that depend on database state.
     */
    public static function _is_migration_context(): bool
    {
        return _Manifest_Database_Helper::_is_migration_context();
    }

    /**
     * Verify database has been provisioned before running code quality checks
     *
     * Checks that:
     * 1. The _migrations table exists (created by Laravel migrate)
     * 2. At least one migration has been applied
     *
     * This prevents confusing code quality errors when the real issue is
     * that migrations haven't been run yet.
     */
    public static function _verify_database_provisioned(): void
    {
        _Manifest_Database_Helper::_verify_database_provisioned();
    }

    /**
     * Get list of all database tables from manifest model metadata
     *
     * Returns only tables that have been indexed via Model_ManifestSupport
     * (i.e., tables with corresponding model classes)
     *
     * @return array Array of table names
     */
    public static function db_get_tables(): array
    {
        return _Manifest_Database_Helper::db_get_tables();
    }

    /**
     * Get columns for a specific table with their types from manifest
     *
     * Returns simplified column information extracted during manifest build.
     * Types are from Model_ManifestSupport::__parse_column_type() which simplifies
     * MySQL types (e.g., 'bigint', 'varchar(255)' -> 'integer', 'string')
     *
     * @param string $table Table name
     * @return array Associative array of column_name => type, or empty if table not found
     */
    public static function db_get_table_columns(string $table): array
    {
        return _Manifest_Database_Helper::db_get_table_columns($table);
    }

    /**
     * Get columns of a specific type for a table from manifest
     *
     * Useful for finding all boolean columns (tinyint), integer columns, etc.
     *
     * @param string $table Table name
     * @param string $type Type to filter by (from Model_ManifestSupport simplified types)
     * @return array Array of column names matching the type
     */
    public static function db_get_columns_by_type(string $table, string $type): array
    {
        return _Manifest_Database_Helper::db_get_columns_by_type($table, $type);
    }
}