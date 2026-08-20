<?php

namespace App\RSpade\Core\Bundle;

use Exception;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use App\RSpade\Core\Auth\Auth_BundleIntegration;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Bundle\Minifier;
use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;
use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;
use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Rsx;

/**
* BundleCompiler - Compiles RSX bundles into JS and CSS files
*
* Process flow:
* 1. Resolve all bundle includes to get flat file list
* 2. In production: check if output files exist, return immediately if yes
* 3. Split all files into vendor/app based on path containing "vendor/"
* 4. Check individual vendor/app bundle caches
* 5. Process files through bundle processors
* 6. Add JS stubs from manifest
* 7. Filter to JS/CSS only
* 8. Add NPM includes to vendor bucket
* 9. Compile vendor JS/CSS, then app JS/CSS
* 10. In production only: concatenate vendor+app into single files
*/
#[Instantiatable]
class BundleCompiler
{
    /**
    * Files organized by vendor/app
    * ['vendor' => [files], 'app' => [files]]
    */
    protected array $bundle_files = [];

    /**
    * Watch files organized by vendor/app
    */
    protected array $watch_files = [];

    /**
    * CDN assets from bundles
    */
    protected array $cdn_assets = ['js' => [], 'css' => []];

    /**
    * Public directory assets (served via AssetHandler with filemtime cache-busting)
    * Format: ['js' => [['url' => '/path/to/file.js', 'full_path' => '/full/filesystem/path']], ...]
    */
    protected array $public_assets = ['js' => [], 'css' => []];

    /**
    * Cache keys for vendor/app
    */
    protected array $cache_keys = [];

    /**
    * NPM includes to add to vendor JS
    */
    protected array $npm_includes = [];

    /**
    * Bundle configuration
    */
    protected array $config = [];

    /**
    * Files already included (deduplication)
    */
    protected array $included_files = [];

    /**
    * Include paths for route extraction
    */
    protected array $include_routes = [];

    /**
    * Bundle name being compiled
    */
    protected string $bundle_name = '';

    /**
    * Whether we're in production mode
    */
    protected bool $is_production = false;

    /**
    * Track resolved includes to prevent duplicates
    * This tracks bundle classes, aliases, and paths that have been processed
    */
    protected array $resolved_includes = [];

    /**
    * The root module bundle class being compiled
    * Used for validation error messages
    */
    protected string $root_bundle_class = '';

    /**
    * Compiled jqhtml files (separated during ordering for special placement)
    */
    protected array $jqhtml_compiled_files = [];

    /**
    * Mapping from babel-transformed files to their original source files
    * ['storage/rsx-tmp/babel_xxx.js' => 'app/RSpade/Core/Js/SomeFile.js']
    */
    protected array $babel_file_mapping = [];

    /**
    * Bundle build lock token (prevents parallel RPC server interference)
    */
    protected ?string $bundle_build_lock = null;

    /**
    * Compile a bundle
    */
    public function compile(string $bundle_class, array $options = []): array
    {
        $this->bundle_name = $this->_get_bundle_name($bundle_class);
        $this->is_production = Rsx::is_production();
        $force_build = $options['force_build'] ?? false;

        console_debug('BUNDLE', "Compiling {$this->bundle_name} (mode: " . Rsx::get_mode() . ')');

        // Step 1: In production-like modes, require pre-built bundles (unless force_build).
        // Auto-rebuild happens only in development (Manifest::_should_auto_rebuild()); any
        // other mode must ship pre-compiled bundles or error.
        if (!Manifest::_should_auto_rebuild() && !$force_build) {
            $existing = $this->_check_production_cache();
            if ($existing) {
                console_debug('BUNDLE', 'Using existing production bundle');

                return $existing;
            }

            // In production-like modes, don't auto-rebuild - error instead
            $rebuild_command = \App\RSpade\Core\Prod\Rsx_Prod_Seal::is_sealed()
                ? 'rsx:prod:refresh'
                : 'rsx:prod:build';
            throw new RuntimeException(
                "Bundle '{$this->bundle_name}' not compiled for production mode. " .
                "Run: php artisan {$rebuild_command}"
            );
        }

        // Step 2: Mark the bundle we're compiling as already resolved
        $this->resolved_includes[$bundle_class] = true;
        $this->root_bundle_class = $bundle_class;

        // Step 3: Process required bundles first
        $this->_process_required_bundles();

        // Step 4: Resolve all bundle includes to get flat file list
        $this->_resolve_bundle($bundle_class);

        // Step 5: Always split into vendor/app
        $this->_split_vendor_app();

        // Step 6: Check individual bundle caches (or force all if force_build)
        if ($force_build) {
            $need_compile = ['vendor', 'app'];
            $this->cache_keys = [];
            foreach ($need_compile as $type) {
                $this->cache_keys[$type] = $this->_get_cache_key($type);
            }
            console_debug('BUNDLE', 'Force build - compiling all types');
        } else {
            $need_compile = $this->_check_bundle_caches();
            console_debug('BUNDLE', 'Need compile: ' . json_encode($need_compile));
        }

        // Step 7-10: Process bundles that need compilation
        if (!empty($need_compile)) {
            console_debug('BUNDLE', 'Acquiring bundle build lock');

            // Get a bundle build lock to prevent parallel RPC server interference
            // SYSTEM lock: compiled bundles are artifacts on THIS box's local disk. Waits
            // forever - a full compile runs for minutes and is held for its whole duration.
            $this->bundle_build_lock = RsxLocks::system_lock(RsxLocks::LOCK_BUNDLE_BUILD);

            console_debug('BUNDLE', 'Bundle build lock acquired, double-checking cache');

            // Double-check cache after acquiring lock (race condition protection)
            // Maybe another process just finished compiling while we were waiting
            $need_compile = $this->_check_bundle_caches();

            if (!empty($need_compile)) {
                console_debug('BUNDLE', 'Processing bundles: ' . json_encode($need_compile));
                $this->_process_bundles($need_compile);
            } else {
                console_debug('BUNDLE', 'Cache updated by another process, using cache');
            }
        } else {
            console_debug('BUNDLE', 'No bundles need processing, using cache');
        }

        // Step 11: Compile final output files for types that need it
        $outputs = $this->_compile_outputs($need_compile);

        // Step 12: Add existing cached outputs for types that don't need compilation
        foreach (['vendor', 'app'] as $type) {
            if (!in_array($type, $need_compile)) {
                // This type doesn't need compilation, get existing files
                $cache_key = $this->cache_keys[$type] ?? null;
                if ($cache_key) {
                    $short_key = substr($cache_key, 0, 8);
                    $bundle_dir = storage_path('rsx-build/bundles');
                    $js_file = "{$this->bundle_name}__{$type}.{$short_key}.js";
                    $css_file = "{$this->bundle_name}__{$type}.{$short_key}.css";
                    if (file_exists("{$bundle_dir}/{$js_file}")) {
                        $outputs["{$type}_js"] = $js_file;
                    }
                    if (file_exists("{$bundle_dir}/{$css_file}")) {
                        $outputs["{$type}_css"] = $css_file;
                    }
                }
            }
        }

        // Step 13: Return data for render() method (not CLI)
        // Include CDN assets and proper file paths for HTML generation
        $result = [];

        // CDN assets are always output as separate tags
        // In development mode: loaded directly from CDN URLs
        // In production-like modes: served from /_vendor/ (cached locally)
        if (!empty($this->cdn_assets['js'])) {
            $result['cdn_js'] = $this->_prepare_cdn_assets($this->cdn_assets['js'], 'js');
        }
        if (!empty($this->cdn_assets['css'])) {
            $result['cdn_css'] = $this->_prepare_cdn_assets($this->cdn_assets['css'], 'css');
        }

        // Add public directory assets
        if (!empty($this->public_assets['js'])) {
            $result['public_js'] = $this->public_assets['js'];
        }
        if (!empty($this->public_assets['css'])) {
            $result['public_css'] = $this->public_assets['css'];
        }

        // Always return vendor/app split paths
        if (isset($outputs['vendor_js'])) {
            $result['vendor_js_bundle_path'] = $outputs['vendor_js'];
        }
        if (isset($outputs['app_js'])) {
            $result['app_js_bundle_path'] = $outputs['app_js'];
        }
        if (isset($outputs['vendor_css'])) {
            $result['vendor_css_bundle_path'] = $outputs['vendor_css'];
        }
        if (isset($outputs['app_css'])) {
            $result['app_css_bundle_path'] = $outputs['app_css'];
        }

        // Add config if present
        if (!empty($this->config)) {
            $result['bundle_data'] = $this->config;
        }

        return $result;
    }

    // ------------------------------------------------------------------------
    // ---- Private / Protected Methods:
    // ------------------------------------------------------------------------

    /**
    * Check for JavaScript naming conflicts within files being bundled
    *
    * This checks for:
    * - Duplicate function names
    * - Duplicate const names
    * - Duplicate class names
    * - Conflicts between functions, consts, and classes
    *
    * Only checks the subset of files being bundled, not the entire manifest.
    * Throws exception on first conflict found to prevent bundle compilation.
    *
    * @param array $types Types being compiled (vendor/app)
    * @throws \App\RSpade\Core\Exceptions\YoureDoingItWrongException
    */
    protected function _check_js_naming_conflicts(array $types): void
    {
        // Collect all JavaScript files from types being compiled
        $js_files = [];
        foreach ($types as $type) {
            $files = $this->bundle_files[$type] ?? [];
            foreach ($files as $file) {
                // Only check JavaScript files
                if (str_ends_with($file, '.js')) {
                    $js_files[] = $file;
                }
            }
        }

        if (empty($js_files)) {
            return; // No JS files to check
        }

        console_debug('BUNDLE', 'Checking JS naming conflicts for ' . count($js_files) . ' files');

        // Get manifest data
        $manifest_files = Manifest::get_all();

        // Track all names across the bundle
        $all_names = []; // name => ['type' => 'function'|'const'|'class', 'file' => path]

        // Process each JS file in the bundle
        foreach ($js_files as $js_file) {
            // Convert absolute path to relative for manifest lookup
            $relative_path = str_replace(base_path() . '/', '', $js_file);

            if (!isset($manifest_files[$relative_path])) {
                continue; // File not in manifest, skip
            }

            $metadata = $manifest_files[$relative_path];

            // Check global functions
            if (!empty($metadata['global_function_names'])) {
                foreach ($metadata['global_function_names'] as $func_name) {
                    if (isset($all_names[$func_name])) {
                        $this->_throw_js_naming_conflict(
                            $func_name,
                            'function',
                            $relative_path,
                            $all_names[$func_name]['type'],
                            $all_names[$func_name]['file']
                        );
                    }
                    $all_names[$func_name] = ['type' => 'function', 'file' => $relative_path];
                }
            }

            // Check global consts
            if (!empty($metadata['global_const_names'])) {
                foreach ($metadata['global_const_names'] as $const_name) {
                    if (isset($all_names[$const_name])) {
                        $this->_throw_js_naming_conflict(
                            $const_name,
                            'const',
                            $relative_path,
                            $all_names[$const_name]['type'],
                            $all_names[$const_name]['file']
                        );
                    }
                    $all_names[$const_name] = ['type' => 'const', 'file' => $relative_path];
                }
            }

            // Check class names (they share namespace with functions and consts)
            if (!empty($metadata['class'])) {
                $class_name = $metadata['class'];
                if (isset($all_names[$class_name])) {
                    $this->_throw_js_naming_conflict(
                        $class_name,
                        'class',
                        $relative_path,
                        $all_names[$class_name]['type'],
                        $all_names[$class_name]['file']
                    );
                }
                $all_names[$class_name] = ['type' => 'class', 'file' => $relative_path];
            }
        }

        console_debug('BUNDLE', 'JS naming check passed - no conflicts found');
    }

    /**
    * Throw exception for JavaScript naming conflict
    */
    protected function _throw_js_naming_conflict(string $name, string $type1, string $file1, string $type2, string $file2): void
    {
        $error_message = '==========================================';
        $error_message .= "\nFATAL: JavaScript naming conflict in bundle";
        $error_message .= "\n==========================================";
        $error_message .= "\n\nBundle: {$this->bundle_name}";
        $error_message .= "\nName: {$name}";
        $error_message .= "\n\nDefined as {$type1} in: {$file1}";
        $error_message .= "\nAlso defined as {$type2} in: {$file2}";
        $error_message .= "\n\nPROBLEM:";
        $error_message .= "\nThe name '{$name}' is used for both a {$type1} and a {$type2}.";
        $error_message .= "\nJavaScript files in the same bundle share the same scope.";

        if ($type1 === $type2) {
            $error_message .= "\nDuplicate {$type1} definitions will conflict.";
        } else {
            $error_message .= "\nClasses, functions and const variables share the same namespace.";
        }

        $error_message .= "\n\nWHY THIS MATTERS:";
        $error_message .= "\n- All files in a bundle are concatenated together";
        $error_message .= "\n- Duplicate names overwrite each other or cause errors";
        $error_message .= "\n- This leads to unpredictable runtime behavior";
        $error_message .= "\n\nFIX OPTIONS:";
        $error_message .= "\n1. Rename one of them to be unique";
        $error_message .= "\n2. Move into a class as a static member";
        $error_message .= "\n3. Remove from bundle if not needed";
        $error_message .= "\n4. Use different bundles for conflicting files";
        $error_message .= "\n==========================================";

        throw new \App\RSpade\Core\Exceptions\YoureDoingItWrongException($error_message);
    }

    /**
    * Get bundle name from class
    */
    protected function _get_bundle_name(string $bundle_class): string
    {
        $parts = explode('\\', $bundle_class);

        return end($parts);
    }

    /**
     * Get bundle FQCN from simple class name
     */
    protected function _get_bundle_fqcn(string $bundle_name): string
    {
        // If already a FQCN, return as-is
        if (str_contains($bundle_name, '\\')) {
            return $bundle_name;
        }

        // Look up in Manifest
        $metadata = Manifest::php_get_metadata_by_class($bundle_name);

        return $metadata['fqcn'];
    }

    /**
    * Check if production bundle already exists
    */
    protected function _check_production_cache(): ?array
    {
        $bundle_dir = storage_path('rsx-build/bundles');

        // Look for split vendor/app files (the output format)
        $vendor_js_pattern = "{$bundle_dir}/{$this->bundle_name}__vendor.*.js";
        $app_js_pattern = "{$bundle_dir}/{$this->bundle_name}__app.*.js";
        $vendor_css_pattern = "{$bundle_dir}/{$this->bundle_name}__vendor.*.css";
        $app_css_pattern = "{$bundle_dir}/{$this->bundle_name}__app.*.css";

        $vendor_js_files = glob($vendor_js_pattern);
        $app_js_files = glob($app_js_pattern);
        $vendor_css_files = glob($vendor_css_pattern);
        $app_css_files = glob($app_css_pattern);

        // Need at least one app file (JS is typically required)
        if (!empty($app_js_files)) {
            $result = [
                'vendor_js_bundle_path' => !empty($vendor_js_files) ? basename($vendor_js_files[0]) : null,
                'app_js_bundle_path' => !empty($app_js_files) ? basename($app_js_files[0]) : null,
                'vendor_css_bundle_path' => !empty($vendor_css_files) ? basename($vendor_css_files[0]) : null,
                'app_css_bundle_path' => !empty($app_css_files) ? basename($app_css_files[0]) : null,
            ];

            // Resolve CDN assets using the same code path as dev mode
            // This ensures directory scanning, nested bundles, etc. all work the same
            // File collection happens but we ignore it since we use cached bundles
            $bundle_fqcn = $this->_get_bundle_fqcn($this->bundle_name);
            $this->resolved_includes[$bundle_fqcn] = true;
            $this->root_bundle_class = $bundle_fqcn;
            $this->_process_required_bundles();
            $this->_resolve_bundle($bundle_fqcn);

            if (!empty($this->cdn_assets['js'])) {
                $result['cdn_js'] = $this->_prepare_cdn_assets($this->cdn_assets['js'], 'js');
            }
            if (!empty($this->cdn_assets['css'])) {
                $result['cdn_css'] = $this->_prepare_cdn_assets($this->cdn_assets['css'], 'css');
            }

            return $result;
        }

        return null;
    }
    /**
    * Process required bundles (jquery, lodash, jqhtml)
    */
    protected function _process_required_bundles(): void
    {
        $required_bundles = config('rsx.required_bundles', []);
        $bundle_aliases = config('rsx.bundle_aliases', []);

        foreach ($required_bundles as $alias) {
            if (isset($bundle_aliases[$alias])) {
                $this->_process_include_item($bundle_aliases[$alias]);
            }
        }

        // Include custom JS model base class if configured
        // This allows users to define application-wide model functionality
        $js_model_base_class = config('rsx.js_model_base_class');
        if ($js_model_base_class) {
            $this->_include_js_model_base_class($js_model_base_class);
        }
    }

    /**
     * Include the custom JS model base class file in the bundle
     *
     * Finds the JS file by class name in the manifest and adds it to the bundle.
     * Validates that the class extends Rsx_Js_Model.
     */
    protected function _include_js_model_base_class(string $class_name): void
    {
        // Find the JS file in the manifest by class name
        try {
            $file_path = Manifest::js_find_class($class_name);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                "JavaScript model base class '{$class_name}' configured in rsx.js_model_base_class not found in manifest.\n" .
                "Ensure the class is defined in a .js file within your application (e.g., rsx/lib/{$class_name}.js)"
            );
        }

        // Get metadata to verify it extends Rsx_Js_Model
        $metadata = Manifest::get_file($file_path);
        $extends = $metadata['extends'] ?? null;

        if ($extends !== 'Rsx_Js_Model') {
            throw new \RuntimeException(
                "JavaScript model base class '{$class_name}' must extend Rsx_Js_Model.\n" .
                "Found: extends {$extends}\n" .
                "File: {$file_path}"
            );
        }

        // Add the file to the bundle by processing it as a path
        $this->_process_include_item($file_path);
    }

    /**
    * Resolve bundle and all its includes
    *
    * @param string $bundle_class The bundle class to resolve
    * @param bool $discovered_via_scan Whether this bundle was discovered via directory scan
    */
    protected function _resolve_bundle(string $bundle_class, bool $discovered_via_scan = false): void
    {
        // Get bundle definition
        if (!method_exists($bundle_class, 'define')) {
            throw new Exception("Bundle {$bundle_class} missing define() method");
        }

        // Validate module bundle doesn't include another module bundle
        if (Manifest::php_is_subclass_of($bundle_class, 'Rsx_Module_Bundle_Abstract') &&
            $bundle_class !== $this->root_bundle_class) {
            Rsx_Module_Bundle_Abstract::validate_include($bundle_class, $this->root_bundle_class);
        }

        // Validate asset bundles discovered via scan don't have directory paths
        if ($discovered_via_scan && Manifest::php_is_subclass_of($bundle_class, 'Rsx_Asset_Bundle_Abstract')) {
            Rsx_Asset_Bundle_Abstract::validate_no_directory_scanning($bundle_class, $this->root_bundle_class);
        }

        $definition = $bundle_class::define();

        // Process bundle includes
        if (!empty($definition['include'])) {
            foreach ($definition['include'] as $item) {
                $this->_process_include_item($item);
            }
        }

        // Process watch directories
        if (!empty($definition['watch'])) {
            foreach ($definition['watch'] as $dir) {
                $this->_add_watch_directory($dir);
            }
        }

        // Store CDN assets
        if (!empty($definition['cdn_assets'])) {
            if (!empty($definition['cdn_assets']['js'])) {
                $this->cdn_assets['js'] = array_merge($this->cdn_assets['js'], $definition['cdn_assets']['js']);
            }
            if (!empty($definition['cdn_assets']['css'])) {
                $this->cdn_assets['css'] = array_merge($this->cdn_assets['css'], $definition['cdn_assets']['css']);
            }
        }

        // Store NPM includes
        if (!empty($definition['npm'])) {
            $this->npm_includes = array_merge($this->npm_includes, $definition['npm']);
        }

        // Store config
        if (!empty($definition['config'])) {
            $this->config = array_merge($this->config, $definition['config']);
        }

        // Process include_routes directive
        if (!empty($definition['include_routes'])) {
            foreach ($definition['include_routes'] as $route_path) {
                // Normalize the path
                $normalized_path = trim($route_path, '/');
                if (!in_array($normalized_path, $this->include_routes)) {
                    $this->include_routes[] = $normalized_path;
                }
            }
        }
    }

    /**
    * Process a single include item
    */
    protected function _process_include_item($item): void
    {
        // Create a unique key for this include item
        $include_key = is_string($item) ? $item : serialize($item);

        // Skip if already processed
        if (isset($this->resolved_includes[$include_key])) {
            return;
        }
        $this->resolved_includes[$include_key] = true;

        // Check for /public/ prefix - static assets from public directories
        if (is_string($item) && str_starts_with($item, '/public/')) {
            $relative_path = substr($item, 8); // Strip '/public/' prefix

            try {
                // Resolve via AssetHandler (with Redis caching)
                $full_path = \App\RSpade\Core\Dispatch\AssetHandler::find_public_asset($relative_path);

                // Determine file type
                $extension = strtolower(pathinfo($relative_path, PATHINFO_EXTENSION));

                if ($extension === 'js') {
                    $this->public_assets['js'][] = [
                        'url' => '/' . $relative_path,
                        'full_path' => $full_path,
                    ];
                } elseif ($extension === 'css') {
                    $this->public_assets['css'][] = [
                        'url' => '/' . $relative_path,
                        'full_path' => $full_path,
                    ];
                } else {
                    throw new RuntimeException(
                        "Public asset must be .js or .css file: {$item}\n" .
                        'Only JavaScript and CSS files can be included via /public/ prefix.'
                    );
                }

                return;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                throw new RuntimeException(
                    "Failed to resolve public asset: {$item}\n" .
                    $e->getMessage()
                );
            }
        }

        // Check bundle aliases and resolve to actual class
        $bundle_aliases = config('rsx.bundle_aliases', []);
        if (is_string($item) && isset($bundle_aliases[$item])) {
            $item = $bundle_aliases[$item];
            // Also mark the resolved class as processed
            if (isset($this->resolved_includes[$item])) {
                return;
            }
            $this->resolved_includes[$item] = true;
        }

        // If it's a fully qualified class, resolve as bundle
        if (is_string($item) && class_exists($item)) {
            $this->_resolve_bundle($item);

            return;
        }

        // Try to find class by simple name in manifest
        if (is_string($item) && strpos($item, '/') === false && strpos($item, '.') === false) {
            try {
                $manifest = Manifest::get_all();
                foreach ($manifest as $file_info) {
                    if (isset($file_info['class']) && $file_info['class'] === $item) {
                        if (isset($file_info['fqcn']) && class_exists($file_info['fqcn'])) {
                            $this->_resolve_bundle($file_info['fqcn']);

                            return;
                        }
                    }
                }
            } catch (Exception $e) {
                // Fall through to file/directory handling
            }
        }

        // Check if item is an absolute path
        if (str_starts_with($item, '/')) {
            $base_path = str_replace('\\', '/', base_path());

            // Determine project root (parent of system/ if we're in a subdirectory structure)
            $project_root = $base_path;
            if (basename($base_path) === 'system') {
                $project_root = dirname($base_path);
            }

            // If it starts with base_path, convert to relative
            if (str_starts_with($item, $base_path)) {
                // Convert absolute path to relative path (without leading slash)
                $item = substr($item, strlen($base_path) + 1);
            } elseif (str_starts_with($item, $project_root)) {
                // Path is under project root but not under base_path (e.g., symlinked rsx/)
                // Convert to relative from base_path
                $relative_from_project = substr($item, strlen($project_root) + 1);
                $item = $relative_from_project;
            } else {
                throw new RuntimeException(
                    "Bundle include item is an absolute path outside the project directory.\n" .
"Path: {$item}\n" .
"Project directory: {$project_root}\n" .
'Absolute paths must be within the project directory.'
                );
            }
        }

        // Validate that storage directory is not directly included
        if (is_string($item) && (str_starts_with($item, 'storage/') || str_starts_with($item, '/storage/'))) {
            throw new RuntimeException(
                "Direct inclusion of 'storage' directory in bundle is not allowed.\n" .
"Path: {$item}\n\n" .
'Auto-generated files (JS stubs, compiled assets) are automatically included ' .
'when their source files are part of the bundle. Include the source files ' .
"(e.g., 'rsx/models' for model stubs) rather than the generated output."
            );
        }

        // Validate path format (development mode only)
        if (!app()->environment('production') && is_string($item)) {
            if (str_starts_with($item, 'system/app/') || str_starts_with($item, '/system/app/')) {
                throw new RuntimeException(
                    "'system/app/' is not a valid RSpade path. Use 'app/' instead.\n" .
"Invalid path: {$item}\n" .
'Correct path: ' . str_replace('system/app/', 'app/', $item) . "\n\n" .
'From the bundle perspective, framework code is at app/, not system/app/'
                );
            }

            if (str_starts_with($item, 'system/rsx/') || str_starts_with($item, '/system/rsx/')) {
                throw new RuntimeException(
                    "'system/rsx/' is not a valid RSpade path. Use 'rsx/' instead.\n" .
"Invalid path: {$item}\n" .
'Correct path: ' . str_replace('system/rsx/', 'rsx/', $item) . "\n\n" .
'From the bundle perspective, application code is at rsx/, not system/rsx/'
                );
            }
        }

        // Otherwise treat as file/directory path
        $path = base_path($item);

        if (is_file($path)) {
            $this->_add_file($path);

            return;
        }
        if (is_dir($path)) {
            $this->_add_directory($path);

            return;
        }
        // Try with wildcards
        $files = glob($path);
        if (!empty($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    $this->_add_file($file);
                } elseif (is_dir($file)) {
                    $this->_add_directory($file);
                }
            }

            return;
        }

        // If we get here, the item could not be resolved
        throw new RuntimeException("Cannot resolve bundle include item: {$item}. Not found as alias, class, file, or directory.");
    }

    /**
    * Add a file to the bundle
    */
    protected function _add_file(string $path): void
    {
        $normalized = rsxrealpath($path);

        if (!$normalized) {
            return;
        }

        // Deduplicate
        if (isset($this->included_files[$normalized])) {
            return;
        }
        $this->included_files[$normalized] = true;

        // Add to main files (will be split into vendor/app later)
        if (!isset($this->bundle_files['all'])) {
            $this->bundle_files['all'] = [];
        }
        $this->bundle_files['all'][] = $normalized;
    }

    /**
    * Add all files from a directory
    *
    * Also auto-discovers Asset Bundles in the directory and processes them.
    */
    protected function _add_directory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        // Get excluded directories from config
        $excluded_dirs = config('rsx.manifest.excluded_dirs', ['vendor', 'node_modules', 'storage', '.git', 'public', 'resource']);

        // Track discovered asset bundles to process after file collection
        $discovered_bundles = [];

        // Create a recursive directory iterator with filtering
        $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) use ($excluded_dirs) {
            // Check if current directory name is in excluded list
            if ($current->isDir()) {
                $dirname = $current->getBasename();

                return !in_array($dirname, $excluded_dirs);
            }

            // Include all files
            return true;
        });

        $iterator = new RecursiveIteratorIterator(
            $filter,
            RecursiveIteratorIterator::SELF_FIRST
        );

        // Collect scanned files first, then process them in a DETERMINISTIC order.
        // RecursiveIteratorIterator yields in filesystem readdir order, which differs
        // between checkouts/filesystems; without a stable sort the resulting bundle
        // file order (and therefore concatenated JS/compiled-CSS bytes) would vary
        // across two byte-identical checkouts. Explicit file includes are added via
        // _add_file() elsewhere and keep their authored order (e.g. SCSS variable
        // files included before component SCSS); only the SCANNED set is sorted here.
        $scanned_files = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $scanned_files[] = $file->getPathname();
            }
        }
        usort($scanned_files, fn ($a, $b) => strcmp(_rsx_relative_build_path($a), _rsx_relative_build_path($b)));

        foreach ($scanned_files as $filepath) {
            {
                $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

                // For PHP files, check if it's an asset bundle via manifest
                if ($extension === 'php') {
                    $relative_path = str_replace(base_path() . '/', '', $filepath);

                    // Get file metadata from manifest to check if it's an asset bundle
                    try {
                        $file_meta = Manifest::get_file($relative_path);
                        $class_name = $file_meta['class'] ?? null;

                        // Use manifest to check if this PHP class is an asset bundle
                        if ($class_name && Manifest::php_is_subclass_of($class_name, 'Rsx_Asset_Bundle_Abstract')) {
                            $fqcn = $file_meta['fqcn'] ?? null;
                            if ($fqcn && !isset($this->resolved_includes[$fqcn])) {
                                $discovered_bundles[] = $fqcn;
                                console_debug('BUNDLE', "Auto-discovered asset bundle: {$fqcn}");
                            }
                            // Don't add bundle file itself to file list - we'll process it as a bundle
                            continue;
                        }
                    } catch (RuntimeException $e) {
                        // File not in manifest, just add it normally
                    }
                }

                $this->_add_file($filepath);
            }
        }

        // Process discovered asset bundles (marked as discovered via scan)
        foreach ($discovered_bundles as $bundle_fqcn) {
            if (!isset($this->resolved_includes[$bundle_fqcn])) {
                $this->resolved_includes[$bundle_fqcn] = true;
                $this->_resolve_bundle($bundle_fqcn, true); // true = discovered via scan
            }
        }
    }

    /**
    * Add a watch directory
    */
    protected function _add_watch_directory(string $dir): void
    {
        $path = base_path($dir);
        if (!is_dir($path)) {
            return;
        }

        // Get excluded directories from config
        $excluded_dirs = config('rsx.manifest.excluded_dirs', ['vendor', 'node_modules', 'storage', '.git', 'public', 'resource']);

        // Create a custom filter to exclude configured directories
        $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) use ($excluded_dirs) {
            // Skip excluded directories
            if ($current->isDir() && in_array($current->getBasename(), $excluded_dirs)) {
                return false;
            }

            return true;
        });

        $iterator = new RecursiveIteratorIterator(
            $filter,
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $normalized = rsxrealpath($file->getPathname());
                if (!isset($this->included_files[$normalized])) {
                    if (!isset($this->watch_files['all'])) {
                        $this->watch_files['all'] = [];
                    }
                    $this->watch_files['all'][] = $normalized;
                }
            }
        }
    }

    /**
    * Split files into vendor and app buckets
    *
    * IMPORTANT: Files remain as FLAT ARRAYS at this stage.
    * We are extension-agnostic throughout processing.
    * Files could be .php, .blade, .scss, .jqhtml, .xml, .coffee, or ANY future type.
    * Only at final compilation do we filter by .js and .css extensions.
    */
    protected function _split_vendor_app(): void
    {
        $vendor_files = [];
        $app_files = [];

        // Split bundle files - flat array, ALL file types mixed together
        foreach ($this->bundle_files['all'] ?? [] as $file) {
            if (strpos($file, '/vendor/') !== false) {
                $vendor_files[] = $file;
            } else {
                $app_files[] = $file;
            }
        }

        // Store as flat arrays - NOT organized by extension yet
        $this->bundle_files = [
            'vendor' => $vendor_files,  // Flat array of ALL vendor files
            'app' => $app_files,        // Flat array of ALL app files
        ];

        // Split watch files - also flat arrays
        $vendor_watch = [];
        $app_watch = [];

        foreach ($this->watch_files['all'] ?? [] as $file) {
            if (strpos($file, '/vendor/') !== false) {
                $vendor_watch[] = $file;
            } else {
                $app_watch[] = $file;
            }
        }

        $this->watch_files = [
            'vendor' => $vendor_watch,  // Flat array of ALL vendor watch files
            'app' => $app_watch,        // Flat array of ALL app watch files
        ];

        console_debug('BUNDLE', 'Split files - vendor: ' . count($vendor_files) . ', app: ' . count($app_files));
    }

    /**
    * Check bundle caches and return which need compilation
    */
    protected function _check_bundle_caches(): array
    {
        $need_compile = [];
        $this->cache_keys = [];  // Store for use in compile

        foreach (['vendor', 'app'] as $type) {
            $cache_key = $this->_get_cache_key($type);
            $this->cache_keys[$type] = $cache_key;
            $cached = $this->_get_cached_bundle($cache_key, $type);

            if (!$cached || !$this->_is_cache_valid($cached, $type)) {
                $need_compile[] = $type;
            }
        }

        console_debug('BUNDLE', 'Need to compile: ' . implode(', ', $need_compile));

        return $need_compile;
    }

    /**
    * Get cache key for bundle type
    */
    protected function _get_cache_key(string $type): string
    {
        $files_for_hash = array_merge(
            $this->bundle_files[$type] ?? [],
            $this->watch_files[$type] ?? []
        );

        $hashes = [];
        foreach ($files_for_hash as $file) {
            if (!file_exists($file)) {
                shouldnt_happen("Expected file {$file} does not exist in bundle builder");
            }
            $hash = _rsx_file_hash_for_build($file);
            $hashes[] = $hash;
        }

        // NPM declarations are not files, so they must be keyed explicitly or
        // adding/removing/changing an 'npm' entry in an Asset Bundle would never
        // invalidate the compiled outputs that embed npm-derived content:
        // - vendor JS embeds the esbuild-compiled npm modules (_generate_npm_includes)
        // - app JS embeds the "const X = window._rsx_npm.X" shims (_generate_npm_import_declarations)
        if (!empty($this->npm_includes)) {
            // Sort by global name so bundle resolution order doesn't churn keys
            $npm_for_hash = $this->npm_includes;
            ksort($npm_for_hash);
            $hashes[] = md5(serialize($npm_for_hash));

            // Vendor output embeds the compiled package code itself, so it must
            // also rebuild when installed package versions change (lock files).
            if ($type === 'vendor') {
                $hashes[] = md5(serialize($this->_get_package_lock_hashes()));
            }
        }

        // Generated auth check mirrors are embedded in every app bundle but belong to
        // no bundle's file list, so - exactly like the npm declarations above - they
        // must be keyed explicitly or adding an #[Auth_Check] would never invalidate
        // the compiled output that carries its mirror.
        if ($type === 'app') {
            foreach (Auth_BundleIntegration::get_mirror_stub_paths() as $auth_mirror_file) {
                $hashes[] = _rsx_file_hash_for_build($auth_mirror_file);
            }
        }

        // Sort the collected content hashes so the cache key (and therefore the
        // bundle output FILENAME) depends only on the SET of inputs, never on the
        // order in which files were resolved. Bundle file resolution order follows
        // filesystem readdir order, which differs between checkouts/filesystems;
        // without this sort two byte-identical checkouts would emit different bundle
        // filenames. Concatenation order (JS dependency ordering) is handled
        // separately and is unaffected by sorting the key inputs.
        sort($hashes);

        $final_key = md5(serialize($hashes));

        return $final_key;
    }

    /**
    * Hashes of all package-lock.json files that determine installed npm package
    * versions. Used for cache keys of outputs embedding compiled npm code.
    */
    protected function _get_package_lock_hashes(): array
    {
        $lock_files = [
            'package-lock.json',
            'internal-libs/jqhtml/package-lock.json',
            'internal-libs/rsx-scss-lint/package-lock.json',
            // Project-root (app-layer) lock file - app npm installs live here, so
            // it must JIT-invalidate compiled bundles. Distinct key preserves the
            // stability of the base_path()-relative keys above.
            '../package-lock.json',
        ];

        $package_lock_contents = [];
        foreach ($lock_files as $lock_file) {
            $full_path = base_path($lock_file);
            if (file_exists($full_path)) {
                $package_lock_contents[$lock_file] = md5_file($full_path);
            }
        }

        return $package_lock_contents;
    }

    /**
    * Get cached bundle if exists
    */
    protected function _get_cached_bundle(string $cache_key, string $type): ?array
    {
        $bundle_dir = storage_path('rsx-build/bundles');
        // The cache key is used as part of the filename hash
        // Look for files with the specific type and hash
        $short_key = substr($cache_key, 0, 8);
        $js_file = "{$bundle_dir}/{$this->bundle_name}__{$type}.{$short_key}.js";
        $css_file = "{$bundle_dir}/{$this->bundle_name}__{$type}.{$short_key}.css";

        $files = [];
        if (file_exists($js_file)) {
            $files[] = $js_file;
        }
        if (file_exists($css_file)) {
            $files[] = $css_file;
        }

        if (empty($files)) {
            console_debug('BUNDLE', "No cached files found for {$type} with hash {$short_key}");

            return null;
        }

        console_debug('BUNDLE', "Found cached files for {$type} with hash {$short_key}");

        return ['files' => $files, 'key' => $cache_key];
    }

    /**
    * Check if cache is still valid
    */
    protected function _is_cache_valid(array $cached, string $type): bool
    {
        // In production, if files exist, they're valid
        if ($this->is_production) {
            return true;
        }

        // Check if all cached files still exist
        foreach ($cached['files'] as $file) {
            if (!file_exists($file)) {
                return false;
            }
        }

        return true;
    }

    /**
    * Process bundles through processors
    *
    * IMPORTANT: Files remain as FLAT ARRAYS throughout processing.
    * Processors can handle ANY file type: .php, .jqhtml, .scss, .xml, .coffee, etc.
    * We do NOT organize by extension here - only in _compile_outputs.
    */
    protected function _process_bundles(array $types): void
    {
        // Check for JavaScript naming conflicts before processing
        // This only runs when bundles actually need rebuilding
        $this->_check_js_naming_conflicts($types);

        $processor_classes = config('rsx.bundle_processors', []);

        // Sort processors by priority (lower number = higher priority)
        usort($processor_classes, function ($a, $b) {
            $priority_a = method_exists($a, 'get_priority') ? $a::get_priority() : 1000;
            $priority_b = method_exists($b, 'get_priority') ? $b::get_priority() : 1000;

            return $priority_a - $priority_b;
        });

        foreach ($types as $type) {
            // Get flat array of ALL files
            $files = $this->bundle_files[$type] ?? [];

            // Run each processor with ALL files (flat array, passed by reference)
            foreach ($processor_classes as $processor_class) {
                if (!class_exists($processor_class)) {
                    continue;
                }

                console_debug('BUNDLE', "Running {$processor_class} on {$type} files (" . count($files) . ' files)');

                // Process files - processor modifies array directly by reference
                if (method_exists($processor_class, 'process_batch')) {
                    $processor_class::process_batch($files);
                }
            }

            // Update the bundle files - STILL AS FLAT ARRAY
            // Do NOT organize by extension here!
            $this->bundle_files[$type] = array_values($files); // Re-index array

            console_debug('BUNDLE', "Processed {$type} - Total files: " . count($files));
        }
    }

    /**
    * Write temporary file
    */
    protected function _write_temp_file(string $content, string $extension): string
    {
        $hash = substr(md5($content), 0, 8);
        $path = storage_path('rsx-tmp/bundle_' . $this->bundle_name . '_' . $hash . '.' . $extension);
        file_put_contents_safe($path, $content);

        return $path;
    }

    /**
     * Assert a manifest-referenced generated JS stub still exists on disk, or throw.
     *
     * Phase-6 stub outputs (controller js-stubs + model js-model-stubs) are recorded
     * in the manifest's file index (via each file's `js_stub` reference). A stub that
     * is referenced by the manifest but MISSING from disk means Phase-6 output was
     * pruned while the manifest cache stayed fresh (the field bug): compiling would
     * then silently omit that file's entire client-side surface - for models, every
     * Base_* stub - while still reporting success. This is the single choke point for
     * that check so both stub-collection paths fail loud identically.
     *
     * Public+`_` (framework-internal, technically public for cross-class/test access).
     *
     * @param string $stub_relative   project-relative stub path (from manifest js_stub)
     * @param string $source_relative project-relative source file that references it
     * @throws RuntimeException when the stub file does not exist on disk
     */
    public static function _assert_stub_output_present(string $stub_relative, string $source_relative): void
    {
        if (file_exists(rsx_project_file_path($stub_relative))) {
            return;
        }

        throw new RuntimeException(
            "Bundle compile references a generated JS stub that is missing from disk:\n" .
            "  stub:   {$stub_relative}\n" .
            "  source: {$source_relative}\n\n" .
            "The manifest records this stub but its Phase-6 output file does not exist\n" .
            "(the js-stubs / js-model-stubs directory was pruned while the manifest cache\n" .
            "stayed fresh). Compiling would silently omit the client-side model/controller\n" .
            "layer for this bundle.\n\n" .
            "Remedy: php artisan rsx:manifest:build --force"
        );
    }

    /**
    * Get JS stubs for all included files in the bundle
    *
    * IMPORTANT: ANY file type can have a js_stub - not just PHP files.
    * Models, controllers, or even custom file types might generate JS stubs.
    */
    protected function _get_js_stubs(): array
    {
        $stubs = [];
        $manifest = Manifest::get_full_manifest();
        $manifest_files = $manifest['data']['files'] ?? [];

        // Get all files from all bundles (app and vendor)
        $all_files = [];
        foreach ($this->bundle_files as $type => $files) {
            // Remember: bundle_files are flat arrays at this point
            if (is_array($files)) {
                $all_files = array_merge($all_files, $files);
            }
        }

        // Check each file for JS stubs
        foreach ($all_files as $file) {
            $relative = str_replace(base_path() . '/', '', $file);

            // ANY file can have a js_stub - check manifest for all files
            if (isset($manifest_files[$relative]['js_stub'])) {
                $stub_relative = $manifest_files[$relative]['js_stub'];
                // A manifest-referenced stub missing from disk is corruption (Phase-6
                // output pruned under a fresh cache), not a skippable condition - dropping
                // it silently omits that file's client-side surface. Fail loud.
                static::_assert_stub_output_present($stub_relative, $relative);
                $stub_path = rsx_project_file_path($stub_relative);
                console_debug('BUNDLE', "Adding JS stub for {$relative}: {$stub_relative}");
                $stubs[] = $stub_path;
            }
        }

        // Rsx_Realtime is core JS present in EVERY bundle and structurally depends on the
        // Realtime_Controller Ajax endpoints (connection + subscribe token minting). That
        // controller lives in framework core (/system), so its PHP source is never in an
        // app bundle's file set and the loop above would miss its stub — leaving
        // "Realtime_Controller is not defined" at runtime the moment anything subscribes.
        // Always include it so the realtime client can mint tokens in any bundle.
        $realtime_controller_source = 'app/RSpade/Core/Realtime/Realtime_Controller.php';
        if (isset($manifest_files[$realtime_controller_source]['js_stub'])) {
            $rt_stub = rsx_project_file_path($manifest_files[$realtime_controller_source]['js_stub']);
            if (file_exists($rt_stub)) {
                $stubs[] = $rt_stub;
            }
        }

        // Same situation as Realtime_Controller: the Document_Preview core component (in every
        // bundle via Core_Bundle) calls File_Preview_Controller::get_preview_info, but that
        // controller lives in framework core (/system) so its PHP source is never in an app
        // bundle's file set. Force its stub in so Document_Preview resolves in any bundle.
        $preview_controller_source = 'app/RSpade/Core/Files/File_Preview_Controller.php';
        if (isset($manifest_files[$preview_controller_source]['js_stub'])) {
            $preview_stub = rsx_project_file_path($manifest_files[$preview_controller_source]['js_stub']);
            if (file_exists($preview_stub)) {
                $stubs[] = $preview_stub;
            }
        }

        console_debug('BUNDLE', 'Found ' . count($stubs) . ' JS stubs total');

        return array_unique($stubs);
    }

    /**
     * Generate concrete model classes for PHP models in the bundle
     *
     * For each PHP model (subclass of Rsx_Model_Abstract) in the bundle:
     * 1. Check if a user-defined JS class with the same name exists
     * 2. If user-defined class exists:
     *    - Validate it extends Base_{ModelName} directly
     *    - If it exists in manifest but not in bundle, throw error
     * 3. If no user-defined class exists:
     *    - Auto-generate: class ModelName extends Base_ModelName {}
     *
     * @param array $current_js_files JS files already in the bundle (to check for user classes)
     * @return string|null Path to temp file containing generated classes, or null if none needed
     */
    protected function _generate_concrete_model_classes(array $current_js_files): ?string
    {
        $manifest = Manifest::get_full_manifest();
        $manifest_files = $manifest['data']['files'] ?? [];

        // Get all files from all bundles to find PHP models
        $all_bundle_files = [];
        foreach ($this->bundle_files as $type => $files) {
            if (is_array($files)) {
                $all_bundle_files = array_merge($all_bundle_files, $files);
            }
        }

        // Build a set of JS class names currently in the bundle for quick lookup
        $js_classes_in_bundle = [];
        foreach ($current_js_files as $js_file) {
            $relative = str_replace(base_path() . '/', '', $js_file);
            if (isset($manifest_files[$relative]['class'])) {
                $js_classes_in_bundle[$manifest_files[$relative]['class']] = $relative;
            }
        }

        // Find all PHP models in the bundle
        $models_in_bundle = [];
        foreach ($all_bundle_files as $file) {
            // Only consider .php files for PHP models
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $relative = str_replace(base_path() . '/', '', $file);

            // Check if this is a PHP file with a class
            if (!isset($manifest_files[$relative]['class'])) {
                continue;
            }

            $class_name = $manifest_files[$relative]['class'];

            // Check if this class is a subclass of Rsx_Model_Abstract
            if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
                continue;
            }

            // Skip system models (internal framework models not exposed to JavaScript)
            if (Manifest::php_is_subclass_of($class_name, 'Rsx_System_Model_Abstract')) {
                continue;
            }

            // Skip abstract model classes - only concrete models get JS stubs
            if (Manifest::php_is_abstract($class_name)) {
                continue;
            }

            $models_in_bundle[$class_name] = $relative;
        }

        if (empty($models_in_bundle)) {
            return null;
        }

        console_debug('BUNDLE', 'Found ' . count($models_in_bundle) . ' PHP models in bundle: ' . implode(', ', array_keys($models_in_bundle)));

        // Process each model
        $generated_classes = [];
        $base_class_name = config('rsx.js_model_base_class');

        foreach ($models_in_bundle as $model_name => $model_path) {
            $expected_base_class = 'Base_' . $model_name;

            // Check if user has defined a JS class with this model name
            $user_js_class_path = null;
            foreach ($manifest_files as $file_path => $meta) {
                if (isset($meta['class']) && $meta['class'] === $model_name && isset($meta['extension']) && $meta['extension'] === 'js') {
                    // Make sure it's not a generated stub
                    if (!isset($meta['is_model_stub']) && !isset($meta['is_stub'])) {
                        $user_js_class_path = $file_path;
                        break;
                    }
                }
            }

            if ($user_js_class_path) {
                // User has defined a JS class for this model - validate it
                console_debug('BUNDLE', "Found user-defined JS class for {$model_name} at {$user_js_class_path}");

                // Check if it's in the bundle
                if (!isset($js_classes_in_bundle[$model_name])) {
                    throw new RuntimeException(
                        "PHP model '{$model_name}' is included in bundle (at {$model_path}) " .
                        "but its custom JavaScript implementation exists at '{$user_js_class_path}' " .
                        "and is NOT included in the bundle.\n\n" .
                        "Either:\n" .
                        "1. Add the JS file's directory to the bundle's include paths, or\n" .
                        "2. Remove the custom JS implementation to use auto-generated class"
                    );
                }

                // Validate it extends the Base_ class directly
                $user_meta = $manifest_files[$user_js_class_path] ?? [];
                $user_extends = $user_meta['extends'] ?? null;

                if ($user_extends !== $expected_base_class) {
                    throw new RuntimeException(
                        "JavaScript model class '{$model_name}' at '{$user_js_class_path}' " .
                        "must extend '{$expected_base_class}' directly.\n" .
                        "Found: extends " . ($user_extends ?: '(nothing)') . "\n\n" .
                        "Correct usage:\n" .
                        "class {$model_name} extends {$expected_base_class} {\n" .
                        "    // Your custom model methods\n" .
                        "}"
                    );
                }

                console_debug('BUNDLE', "Validated {$model_name} extends {$expected_base_class}");
            } else {
                // No user-defined class - auto-generate one
                console_debug('BUNDLE', "Auto-generating concrete class for {$model_name}");
                $generated_classes[] = "class {$model_name} extends {$expected_base_class} {}";
            }
        }

        if (empty($generated_classes)) {
            return null;
        }

        // Write all generated classes to a single temp file using standard temp file pattern
        $content = "/**\n";
        $content .= " * Auto-generated concrete model classes\n";
        $content .= " * These classes extend the Base_* stubs to provide usable model classes\n";
        $content .= " * when no custom implementation is defined by the developer.\n";
        $content .= " */\n\n";
        $content .= implode("\n\n", $generated_classes) . "\n";

        // Use content hash for idempotent file naming, with recognizable prefix for detection
        $hash = substr(md5($content), 0, 8);
        $temp_file = storage_path('rsx-tmp/bundle_generated_models_' . $this->bundle_name . '_' . $hash . '.js');
        file_put_contents_safe($temp_file, $content);

        console_debug('BUNDLE', 'Generated ' . count($generated_classes) . ' concrete model classes');

        return $temp_file;
    }

    /**
    * Order JavaScript files by class dependency
    *
    * JavaScript file ordering is critical for proper execution because:
    * 1. Decorators are executed at class definition time (not invocation)
    * 2. ES6 class extends clauses are evaluated at definition time
    * 3. Both require their dependencies to be defined first
    *
    * Ordering rules:
    * 1. Non-class files first (functions, constants, utilities)
    * 2. Class files ordered by dependency (parent classes and decorator classes first)
    * 3. Special metadata files (manifest, stubs) - added later by caller
    * 4. Runner stub - added last by caller
    *
    * Dependency types:
    * - Inheritance: class Foo extends Bar requires Bar to be defined first
    * - Decorators: @Foo.bar() requires Foo class to be defined first
    * - Simple decorators: @foobar() requires function foobar to be defined first
    *
    * We trust programmers to order non-class files correctly among themselves.
    * We DO validate and enforce ordering for class dependencies.
    */
    protected function _order_javascript_files_by_dependency(array $js_files): array
    {
        console_debug('BUNDLE_SORT', 'Starting dependency sort with ' . count($js_files) . ' files');

        // ORDER CONTRACT: the incoming list order IS the bundle's declared include
        // order (each define() 'include' entry in authored sequence, directory scans
        // pre-sorted deterministically inside _add_directory). That order is the
        // app's ONLY lever over evaluation order and is load-bearing (e.g. models
        // declared before app code so eval-time references like
        // `static X = Some_Model.CONST` resolve). The DFS below hoists known
        // dependencies (extends/decorators) and PRESERVES input order for everything
        // else - it must never be globally re-sorted here. Determinism across
        // checkouts is guaranteed by the deterministic assembly, not by resorting
        // (a global resort here inverted declared order and TDZ-crashed downstream
        // apps - see docs.dev/external_requests/2026_07_15_bundle_emission_order_tdz.md).

        $manifest = Manifest::get_full_manifest();
        $manifest_files = $manifest['data']['files'] ?? [];

        // Arrays to hold categorized files
        $class_files = [];
        $jqhtml_compiled_files = [];
        $non_class_files = [];
        $class_info = [];

        // Analyze each file for class information
        foreach ($js_files as $file) {
            // Check if this is a compiled jqhtml file
            if (str_contains($file, 'storage/rsx-tmp/jqhtml_')) {
                $jqhtml_compiled_files[] = $file;
                continue;
            }

            // Skip ALL temp files - they won't be in manifest
            // Babel and other transformations should have been applied to original files
            if (str_contains($file, 'storage/rsx-tmp/')) {
                $non_class_files[] = $file;
                continue;
            }

            // Check if this is a JS stub file (not in manifest, needs parsing)
            // Stub files are in storage/rsx-build/js-stubs/ or storage/rsx-build/js-model-stubs/
            if (str_contains($file, 'storage/rsx-build/js-stubs/') || str_contains($file, 'storage/rsx-build/js-model-stubs/')) {
                // Use simple regex extraction - stub files have known format and can't use
                // the strict JS parser (stubs may have code after class declaration)
                $stub_content = file_get_contents($file);
                $stub_metadata = $this->_extract_stub_class_info($stub_content);

                if (!empty($stub_metadata['class'])) {
                    $class_files[] = $file;
                    $class_info[$file] = [
                        'class' => $stub_metadata['class'],
                        'extends' => $stub_metadata['extends'],
                        'decorators' => [],
                        'method_decorators' => [],
                    ];
                    console_debug('BUNDLE_SORT', "Parsed stub file: {$stub_metadata['class']} extends " . ($stub_metadata['extends'] ?? 'nothing'));
                } else {
                    $non_class_files[] = $file;
                }
                continue;
            }

            // Get file info from manifest
            $relative = str_replace(base_path() . '/', '', $file);
            $file_data = $manifest_files[$relative] ?? null;

            // If file has a class defined, add to class_files
            if (!empty($file_data['class'])) {
                $class_files[] = $file;

                // Build comprehensive class info including decorator dependencies
                $class_info[$file] = [
                    'class' => $file_data['class'],
                    'extends' => $file_data['extends'] ?? null,
                    'decorators' => $file_data['decorators'] ?? [],
                    // Extract decorator dependencies from methods
                    'method_decorators' => $this->_extract_method_decorators($file_data),
                ];
            } else {
                // No class info - treat as non-class file
                $non_class_files[] = $file;
            }
        }

        // Order class files by dependency with circular dependency detection
        $ordered_class_files = $this->_topological_sort_classes($class_files, $class_info);

        // Find decorator.js and ensure it's the very first file
        $decorator_file = null;
        $other_non_class_files = [];

        foreach ($non_class_files as $file) {
            // Check if this is the decorator.js file
            if (str_ends_with($file, '/app/RSpade/Core/Js/decorator.js')) {
                $decorator_file = $file;
            } else {
                $other_non_class_files[] = $file;
            }
        }

        // CRITICAL ORDERING:
        // 1. decorator.js MUST be first (defines @decorator)
        // 2. Other non-class files (utilities, decorator functions)
        // 3. Class files (ordered by dependency)
        // NOTE: Compiled jqhtml files are returned separately - will be inserted after JS stubs
        $final_order = [];
        if ($decorator_file) {
            $final_order[] = $decorator_file;
        }
        $final_order = array_merge($final_order, $other_non_class_files, $ordered_class_files);

        // Return both the ordered files and the jqhtml files separately
        // Store jqhtml files in a property for use in _compile_outputs
        $this->jqhtml_compiled_files = $jqhtml_compiled_files;

        return $final_order;
    }

    /**
    * Extract decorator dependencies from method metadata
    *
    * Scans all methods in the file metadata to find decorator usage.
    * Decorators like @Foo.bar() create a dependency on class Foo.
    */
    protected function _extract_method_decorators(array $file_data): array
    {
        $decorators = [];

        // Check static methods for decorators
        if (!empty($file_data['public_static_methods'])) {
            foreach ($file_data['public_static_methods'] as $method) {
                if (!empty($method['decorators'])) {
                    foreach ($method['decorators'] as $decorator) {
                        $decorators[] = $decorator;
                    }
                }
            }
        }

        // Check instance methods for decorators
        if (!empty($file_data['methods'])) {
            foreach ($file_data['methods'] as $method) {
                if (!empty($method['decorators'])) {
                    foreach ($method['decorators'] as $decorator) {
                        $decorators[] = $decorator;
                    }
                }
            }
        }

        // Check properties for decorators
        if (!empty($file_data['static_properties'])) {
            foreach ($file_data['static_properties'] as $property) {
                if (!empty($property['decorators'])) {
                    foreach ($property['decorators'] as $decorator) {
                        $decorators[] = $decorator;
                    }
                }
            }
        }

        return $decorators;
    }

    /**
    * Extract class name and extends from JS stub file content
    *
    * Uses simple regex extraction since stub files have a known format and may
    * have code after the class declaration that the strict JS parser rejects.
    *
    * @param string $content The stub file content
    * @return array ['class' => string|null, 'extends' => string|null]
    */
    protected function _extract_stub_class_info(string $content): array
    {
        // Remove single-line comments
        $content = preg_replace('#//.*$#m', '', $content);

        // Remove multi-line comments (including JSDoc)
        $content = preg_replace('#/\*.*?\*/#s', '', $content);

        // Match: class ClassName or class ClassName extends ParentClass
        // The first match wins - we only care about the class declaration
        if (preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+extends\s+([A-Za-z_][A-Za-z0-9_]*))?/', $content, $matches)) {
            return [
                'class' => $matches[1],
                'extends' => $matches[2] ?? null,
            ];
        }

        return ['class' => null, 'extends' => null];
    }

    /**
    * Topological sort for class dependencies with decorator support
    *
    * This performs a depth-first topological sort to order JavaScript classes
    * such that all dependencies are defined before the classes that use them.
    *
    * Dependency detection:
    * 1. Inheritance: class Foo extends Bar creates dependency on Bar
    * 2. Class decorators: @Baz.method() creates dependency on Baz class
    * 3. Method decorators: Same as class decorators
    *
    * Circular dependency handling:
    * - Detected via temporary visit marking during DFS traversal
    * - Throws exception with clear error message showing the cycle
    * - Prevents infinite loops in dependency resolution
    *
    * Note: We don't validate if decorator classes actually exist in the bundle.
    * If @Foo.bar() is used and Foo doesn't exist, that's fine - it might be
    * defined globally or in a different bundle. We only order known dependencies.
    */
    protected function _topological_sort_classes(array $class_files, array $class_info): array
    {
        $sorted = [];
        $visited = [];
        $temp_visited = [];
        $visit_path = []; // Track the current path for circular dependency reporting

        // Build a map of class names to file paths for quick lookup
        $class_to_file = [];
        foreach ($class_info as $file => $info) {
            if (isset($info['class'])) {
                $class_to_file[$info['class']] = $file;
            }
        }

        /**
        * Extract class dependencies from a decorator name
        *
        * Examples:
        * - @Foo.bar() returns 'Foo'
        * - @Route() returns null (not a class dependency)
        * - @Namespace.Class.method() returns 'Namespace' (first part only)
        */
        $extract_decorator_class = function ($decorator_name) {
            // Check if decorator name contains a dot (class.method pattern)
            if (str_contains($decorator_name, '.')) {
                // Extract the class name (part before first dot)
                $parts = explode('.', $decorator_name);
                // Only return if it starts with uppercase (likely a class)
                if (!empty($parts[0]) && ctype_upper($parts[0][0])) {
                    return $parts[0];
                }
            }

            return null;
        };

        /**
        * Depth-first search for topological sorting
        *
        * This recursive function visits each node (file) and its dependencies,
        * building the sorted list from the bottom up (dependencies first).
        */
        $visit = function ($file) use (
            &$visit,
            &$sorted,
            &$visited,
            &$temp_visited,
            &$visit_path,
            $class_info,
            $class_to_file,
            $extract_decorator_class
        ) {
            // Check for circular dependency
            if (isset($temp_visited[$file])) {
                // Build the cycle path for error reporting
                $cycle_start = array_search($file, $visit_path);
                $cycle = array_slice($visit_path, $cycle_start);
                $cycle[] = $file; // Complete the circle

                // Create detailed error message
                $class_name = $class_info[$file]['class'] ?? 'unknown';
                $cycle_description = array_map(function ($f) use ($class_info) {
                    return $class_info[$f]['class'] ?? basename($f);
                }, $cycle);

                throw new RuntimeException(
                    "Circular dependency detected in JavaScript class dependencies:\n" .
'  ' . implode(' -> ', $cycle_description) . "\n" .
"  Files involved:\n" .
implode("\n", array_map(fn ($f) => '    - ' . str_replace(base_path() . '/', '', $f), $cycle)) . "\n" .
'  Break the circular dependency by refactoring the class relationships.'
                );
            }

            // Already processed this file
            if (isset($visited[$file])) {
                return;
            }

            // Mark as temporarily visited and add to path
            $temp_visited[$file] = true;
            $visit_path[] = $file;

            // Collect all dependencies for this file
            $dependencies = [];

            // 1. Inheritance dependency: extends clause
            if (!empty($class_info[$file]['extends'])) {
                $parent_class = $class_info[$file]['extends'];

                // Find the file containing the parent class
                if (isset($class_to_file[$parent_class])) {
                    $parent_file = $class_to_file[$parent_class];

                    // Only add as dependency if it's a different file
                    if ($parent_file !== $file) {
                        $dependencies[] = $parent_file;
                    }
                }
            }

            // 2. Class decorator dependencies: @Foo.bar() on the class itself
            // Decorators are in compact format: [[name, [args]], ...]
            if (!empty($class_info[$file]['decorators'])) {
                foreach ($class_info[$file]['decorators'] as $decorator) {
                    // Compact format: [name, args]
                    $decorator_name = $decorator[0] ?? null;
                    if (!$decorator_name) {
                        continue;
                    }

                    $decorator_class = $extract_decorator_class($decorator_name);

                    if ($decorator_class && isset($class_to_file[$decorator_class])) {
                        $decorator_file = $class_to_file[$decorator_class];

                        // Only add as dependency if it's a different file
                        if ($decorator_file !== $file && !in_array($decorator_file, $dependencies)) {
                            $dependencies[] = $decorator_file;
                        }
                    }
                }
            }

            // 3. Method decorator dependencies: decorators on methods/properties
            if (!empty($class_info[$file]['method_decorators'])) {
                foreach ($class_info[$file]['method_decorators'] as $decorator) {
                    $decorator_class = $extract_decorator_class($decorator['name']);

                    if ($decorator_class && isset($class_to_file[$decorator_class])) {
                        $decorator_file = $class_to_file[$decorator_class];

                        // Only add as dependency if it's a different file and not already added
                        if ($decorator_file !== $file && !in_array($decorator_file, $dependencies)) {
                            $dependencies[] = $decorator_file;
                        }
                    }
                }
            }

            // Visit all dependencies first (depth-first)
            foreach ($dependencies as $dep_file) {
                $visit($dep_file);
            }

            // Remove from temporary visited and path
            unset($temp_visited[$file]);
            array_pop($visit_path);

            // Mark as permanently visited and add to sorted list
            $visited[$file] = true;
            $sorted[] = $file;
        };

        // Process all class files
        foreach ($class_files as $file) {
            if (!isset($visited[$file])) {
                $visit($file);             //??
            }
        }

        // try {
        //  (code above was here)
        // } catch (RuntimeException $e) {
        //     // Re-throw with bundle context if available
        //     if (!empty($this->bundle_class)) {
        //         throw new RuntimeException(
        //             "Bundle compilation failed for {$this->bundle_class}:\n" . $e->getMessage(),
        //             0,
        //             $e
        //         );
        //     }

        //     throw $e;
        // }

        return $sorted;
    }

    /**
    * Compile final output files
    *
    * THIS IS THE ONLY PLACE where we organize files by extension.
    * Up until now, all files have been in flat arrays regardless of type.
    * Here we:
    * 1. Filter to only .js and .css files
    * 2. Order JS files by class dependency
    * 3. Add framework code:
    *    a. JS stubs (Base_* model classes, controller stubs, etc.)
    *    b. Compiled jqhtml templates
    *    c. Concrete model classes (auto-generated or validated user-defined)
    *    d. Manifest definitions (registers all JS classes)
    *    e. Route definitions
    *    f. Initialization runner (LAST - starts the application)
    * 4. Generate final compiled output
    */
    protected function _compile_outputs(array $types_to_compile = []): array
    {
        $outputs = [];
        $bundle_dir = storage_path('rsx-build/bundles');

        if (!is_dir($bundle_dir)) {
            mkdir($bundle_dir, 0755, true);
        }

        // Only compile types that need it
        // If empty, nothing needs compiling - just return empty
        if (empty($types_to_compile)) {
            return $outputs;
        }

        // Compile requested types
        foreach ($types_to_compile as $type) {
            // Get the flat array of ALL files for this type
            $all_files = $this->bundle_files[$type] ?? [];

            // NOW we finally organize by extension for output
            // This is the FIRST and ONLY time we care about extensions
            $files = ['js' => [], 'css' => []];

            // ORDER CONTRACT: a PHP file's JS stub is emitted AT the PHP file's include
            // position - 'rsx/models' before the app directory in a bundle's include
            // array MUST mean the model stubs (and the concrete aliases spliced in after
            // them below) evaluate before app classes, or an app class referencing a
            // model at eval time (static X = Some_Model.CONST at module scope) dies in
            // the TDZ. See docs.dev/external_requests/2026_07_15_bundle_emission_order_tdz.md.
            $manifest_files_for_stubs = [];
            if ($type === 'app') {
                $manifest_files_for_stubs = Manifest::get_full_manifest()['data']['files'] ?? [];
            }
            $stub_seen = [];

            foreach ($all_files as $file) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                if ($ext === 'js') {
                    $files['js'][] = $file;
                } elseif ($ext === 'css') {
                    $files['css'][] = $file;
                } elseif ($type === 'app') {
                    // Non-js/css file (e.g. a PHP model): if it carries a JS stub,
                    // the stub takes the file's include position.
                    $relative = str_replace(base_path() . '/', '', $file);
                    $stub_relative = $manifest_files_for_stubs[$relative]['js_stub'] ?? null;
                    if ($stub_relative) {
                        // The manifest references this stub for this bundled file, so the
                        // stub file MUST exist on disk. If it does not, Phase-6 output went
                        // missing while the manifest cache stayed fresh (e.g. the
                        // js-model-stubs dir was pruned after a recovery). Silently skipping
                        // it is the exact field bug: the bundle compiles "successfully" with
                        // the entire client-side model layer (every Base_* stub) omitted.
                        static::_assert_stub_output_present($stub_relative, $relative);
                        $stub_path = rsx_project_file_path($stub_relative);
                        if (!isset($stub_seen[$stub_path])) {
                            $stub_seen[$stub_path] = true;
                            $files['js'][] = $stub_path;
                        }
                    }
                }
            }

            // Any remaining stubs not owned by a bundled file (e.g. the always-included
            // Realtime_Controller stub - see _get_js_stubs) append after the positional
            // ones. They depend on Rsx_Js_Model/nothing app-side, so a late position is
            // safe; the dependency sort still hoists their parents.
            if ($type === 'app') {
                foreach ($this->_get_js_stubs() as $stub) {
                    if (!isset($stub_seen[$stub])) {
                        $stub_seen[$stub] = true;
                        $files['js'][] = $stub;
                    }
                }
            }

            // Order JavaScript files by class dependency BEFORE adding other framework code
            if (!empty($files['js'])) {
                $files['js'] = $this->_order_javascript_files_by_dependency($files['js']);
            }

            // Use the cache key hash for filenames
            $hash = isset($this->cache_keys[$type]) ? substr($this->cache_keys[$type], 0, 8) : substr(md5($this->bundle_name . '_' . $type), 0, 8);

            // Clean old files for this type only
            $old_files = glob("{$bundle_dir}/{$this->bundle_name}__{$type}.*");
            foreach ($old_files as $file) {
                // Don't delete files with current hash
                if (strpos($file, ".{$hash}.") === false) {
                    unlink($file);
                }
            }

            // Add NPM includes to vendor JS
            if ($type === 'vendor' && !empty($this->npm_includes)) {
                $npm_bundle_path = $this->_generate_npm_includes();
                if ($npm_bundle_path) {
                    // Add the NPM bundle file path directly
                    array_unshift($files['js'], $npm_bundle_path);
                }
            }

            // Add framework code to app JS
            // Note: JS stubs are already added before dependency ordering above
            if ($type === 'app') {
                // Add NPM import declarations at the very beginning
                if (!empty($this->npm_includes)) {
                    $npm_import_file = $this->_generate_npm_import_declarations();
                    if ($npm_import_file) {
                        array_unshift($files['js'], $npm_import_file);
                    }
                }

                // Generated auth check mirrors (Permission.can_x = function () {...}).
                // These are trailing-statement ATTACHMENTS onto the Permission /
                // Portal_Permission classes in Core/Js, so they must evaluate AFTER
                // those class declarations. They cannot ride the generic js_stub path:
                // _order_javascript_files_by_dependency buckets class-less files as
                // "non-class files" and emits them BEFORE every class, which would run
                // the attachments in the classes' temporal dead zone. Appending them
                // here - after the dependency sort, before the jqhtml templates that
                // call the mirrored checks at render time - is the correct position.
                foreach (Auth_BundleIntegration::get_mirror_stub_paths() as $auth_mirror_file) {
                    $files['js'][] = $auth_mirror_file;
                }

                // Add compiled jqhtml files
                // These are JavaScript files generated from .jqhtml templates
                foreach ($this->jqhtml_compiled_files as $jqhtml_file) {
                    $files['js'][] = $jqhtml_file;
                }

                // Generate concrete model classes for PHP models in the bundle
                // This validates user-defined JS model classes and auto-generates missing ones.
                // ORDER CONTRACT: the aliases (class X_Model extends Base_X_Model {}) must
                // evaluate right after the model stubs they extend - NOT at the bundle tail -
                // so app classes emitted after the models' include position can reference
                // X_Model at eval time (see the stub positional emission above).
                $concrete_models_file = $this->_generate_concrete_model_classes($files['js']);
                if ($concrete_models_file) {
                    $last_stub_index = null;
                    foreach ($files['js'] as $i => $f) {
                        if (str_contains($f, 'storage/rsx-build/js-model-stubs/')) {
                            $last_stub_index = $i;
                        }
                    }
                    if ($last_stub_index !== null) {
                        array_splice($files['js'], $last_stub_index + 1, 0, [$concrete_models_file]);
                    } else {
                        $files['js'][] = $concrete_models_file;
                    }
                }

                // Generate manifest definitions for all JS classes
                $manifest_file = $this->_create_javascript_manifest($files['js'] ?? []);
                if ($manifest_file) {
                    $files['js'][] = $manifest_file;
                }

                // Generate route definitions for JavaScript
                $route_file = $this->_create_javascript_routes();
                if ($route_file) {
                    $files['js'][] = $route_file;
                }

                // Generate portal route definitions for JavaScript
                $portal_route_file = $this->_create_javascript_portal_routes();
                if ($portal_route_file) {
                    $files['js'][] = $portal_route_file;
                }

                // Bake the declared external-resource map for this bundle's realm
                $externals_file = $this->_create_javascript_externals();
                if ($externals_file) {
                    $files['js'][] = $externals_file;
                }

                // Generate initialization runner
                $runner_file = $this->_create_javascript_runner();
                if ($runner_file) {
                    $files['js'][] = $runner_file;
                }
            }

            // Compile JS
            if (!empty($files['js'])) {
                $js_files = $files['js'];

                // CDN assets are served separately via /_vendor/ URLs, not merged into bundle
                // This avoids complex concatenation issues with third-party code

                $js_content = $this->_compile_js_files($js_files);

                // Minify JS in strict production only (strips sourcemaps + console_debug
                // call sites; debug keeps readable code). Policy: Manifest::_should_minify().
                if (Manifest::_should_minify()) {
                    $js_content = Minifier::minify_js(
                        $js_content,
                        "{$this->bundle_name}__{$type}.js",
                        Manifest::_should_strip_console_debug()
                    );
                }

                $js_file = "{$this->bundle_name}__{$type}.{$hash}.js";
                file_put_contents_safe("{$bundle_dir}/{$js_file}", $js_content);
                $outputs["{$type}_js"] = $js_file;
            }

            // Compile CSS
            if (!empty($files['css'])) {
                $css_files = $files['css'];

                // Deterministic concatenation order: CSS files are collected partly
                // from directory scans (readdir order, checkout/filesystem dependent).
                // RSX CSS is component-scoped (each component owns a single wrapper
                // class), so cascade order between components is irrelevant - sorting
                // by the checkout-relative path makes the bundle bytes identical
                // across two byte-identical checkouts.
                usort($css_files, fn ($a, $b) => strcmp(_rsx_relative_build_path($a), _rsx_relative_build_path($b)));

                // CDN assets are served separately via /_vendor/ URLs, not merged into bundle
                // This avoids complex concatenation issues with third-party code

                $css_content = $this->_compile_css_files($css_files);

                // Minify CSS in strict production only (strips sourcemaps, debug keeps them).
                // Policy: Manifest::_should_minify().
                if (Manifest::_should_minify()) {
                    $css_content = Minifier::minify_css($css_content, "{$this->bundle_name}__{$type}.css");
                }

                $css_file = "{$this->bundle_name}__{$type}.{$hash}.css";
                file_put_contents_safe("{$bundle_dir}/{$css_file}", $css_content);
                $outputs["{$type}_css"] = $css_file;
            }
        }

        return $outputs;
    }

    /**
    * Parse an import statement string into components
    *
    * Examples:
    * "import { Component } from '@jqhtml/core'"
    * "import * as React from 'react'"
    * "import lodash from 'lodash'"
    *
    * @throws RuntimeException if statement is invalid
    */
    protected function _parse_import_statement(string $statement): array
    {
        $statement = trim($statement);

        // Must start with "import " and end with single or double quote
        if (!str_starts_with($statement, 'import ')) {
            throw new RuntimeException("Invalid import statement - must start with 'import ': {$statement}");
        }

        if (!str_ends_with($statement, "'") && !str_ends_with($statement, '"')) {
            throw new RuntimeException("Invalid import statement - must end with quote: {$statement}");
        }

        // Extract the package name (everything between quotes)
        if (preg_match("/from\\s+['\"]([^'\"]+)['\"]\\s*$/", $statement, $matches)) {
            $package_name = $matches[1];
        } else {
            throw new RuntimeException("Invalid import statement - cannot extract package: {$statement}");
        }

        // Extract what's being imported (between "import" and "from")
        $import_part = trim(substr($statement, 7)); // Remove "import "
        $from_pos = strrpos($import_part, ' from ');
        if ($from_pos === false) {
            throw new RuntimeException("Invalid import statement - missing 'from': {$statement}");
        }
        $import_spec = trim(substr($import_part, 0, $from_pos));

        // Determine the import type and extract names
        if (str_starts_with($import_spec, '{') && str_ends_with($import_spec, '}')) {
            // Named imports: { Component, Something }
            $type = 'named';
            $names = trim(substr($import_spec, 1, -1));
        } elseif (str_starts_with($import_spec, '* as ')) {
            // Namespace import: * as Something
            $type = 'namespace';
            $names = trim(substr($import_spec, 5));
        } else {
            // Default import: Something
            $type = 'default';
            $names = $import_spec;
        }

        return [
            'statement' => $statement,
            'package' => $package_name,
            'type' => $type,
            'imports' => $names,
        ];
    }

    /**
    * Generate NPM includes using esbuild compilation
    * Returns the path to the generated bundle file, not the content
    */
    protected function _generate_npm_includes(): string
    {
        if (empty($this->npm_includes)) {
            return '';
        }

        // Parse all import statements
        $parsed_imports = [];
        foreach ($this->npm_includes as $global_name => $import_statement) {
            try {
                $parsed = $this->_parse_import_statement($import_statement);
                $parsed['global_name'] = $global_name;
                $parsed_imports[] = $parsed;
            } catch (RuntimeException $e) {
                throw new RuntimeException(
                    "Invalid NPM import for '{$global_name}': " . $e->getMessage()
                );
            }
        }

        // Collect all package-lock.json files for cache key
        $package_lock_contents = $this->_get_package_lock_hashes();

        // Generate cache key from:
        // 1. All package-lock.json file hashes (installed package versions)
        // 2. The npm array itself (sorted for consistency)
        // NOTE: the absolute cwd is deliberately NOT part of the key. The compiled
        // npm output is a pure function of the installed package versions and the
        // npm declarations; keying on getcwd() would make the bundle FILENAME depend
        // on the absolute checkout location, breaking cross-checkout determinism.
        $npm_array_sorted = $this->npm_includes;
        ksort($npm_array_sorted);
        $cache_components = [
            'package_locks' => $package_lock_contents,
            'npm_array' => $npm_array_sorted,
        ];
        $cache_key = md5(serialize($cache_components));

        // Generate bundle filename for this specific bundle
        $bundle_filename = "npm_{$this->bundle_name}_{$cache_key}.js";
        $bundle_path = storage_path("rsx-build/bundles/{$bundle_filename}");

        // Check if bundle already exists
        if (file_exists($bundle_path)) {
            console_debug('BUNDLE', "Using cached NPM bundle: {$bundle_filename}");

            return $bundle_path;
        }

        console_debug('BUNDLE', "Compiling NPM modules for {$this->bundle_name}");

        // Create storage directory if needed
        $bundle_dir = dirname($bundle_path);
        if (!is_dir($bundle_dir)) {
            mkdir($bundle_dir, 0755, true);
        }

        // Generate entry file content with import statements
        $entry_content = "// Auto-generated NPM module exports for {$this->bundle_name}\n";
        $entry_content .= "// Cache key: {$cache_key}\n\n";

        // Initialize the _rsx_npm object
        $entry_content .= "// Initialize RSX NPM module container\n";
        $entry_content .= "window._rsx_npm = window._rsx_npm || {};\n\n";

        foreach ($parsed_imports as $import) {
            $global_name = $import['global_name'];
            $statement = $import['statement'];

            // Add the original import statement
            $entry_content .= "{$statement}\n";

            // Add window._rsx_npm assignment based on import type
            switch ($import['type']) {
                case 'named':
                    // For named imports, handle single vs multiple differently
                    $names = explode(',', $import['imports']);
                    $exports = [];
                    foreach ($names as $name) {
                        $name = trim($name);
                        // Handle "name as alias" syntax
                        if (str_contains($name, ' as ')) {
                            [$original, $alias] = explode(' as ', $name);
                            $exports[] = trim($alias);
                        } else {
                            $exports[] = $name;
                        }
                    }

                    // If there's only one named import, assign it directly (for class constructors)
                    // Otherwise create an object with the named exports
                    if (count($exports) === 1) {
                        $entry_content .= "window._rsx_npm.{$global_name} = {$exports[0]};\n";
                    } else {
                        $entry_content .= "window._rsx_npm.{$global_name} = { " . implode(', ', $exports) . " };\n";
                    }
                    break;

                case 'namespace':
                    // For namespace imports, assign directly
                    $import_name = $import['imports'];
                    $entry_content .= "window._rsx_npm.{$global_name} = {$import_name};\n";
                    break;

                case 'default':
                    // For default imports, assign directly
                    $import_name = $import['imports'];
                    $entry_content .= "window._rsx_npm.{$global_name} = {$import_name};\n";
                    break;
            }
        }

        // Write entry file to temp location
        $temp_dir = storage_path('rsx-tmp/npm-compile');
        if (!is_dir($temp_dir)) {
            mkdir($temp_dir, 0755, true);
        }
        $entry_file = "{$temp_dir}/entry_{$cache_key}.js";
        file_put_contents_safe($entry_file, $entry_content);

        // Use esbuild for bundling
        $esbuild_bin = base_path('node_modules/.bin/esbuild');
        if (!file_exists($esbuild_bin)) {
            throw new RuntimeException('esbuild not found. Please run: npm install --save-dev esbuild');
        }

        // Set working directory to project root for proper module resolution
        $original_cwd = getcwd();
        chdir(base_path());

        // NODE_PATH pins module resolution at the framework's node_modules. esbuild resolves
        // bare imports by walking UP from the ENTRY file, and the entry lives in
        // storage/rsx-tmp/npm-compile - which is at the PROJECT ROOT since storage was
        // relocated out of system/, so that walk never reaches system/node_modules.
        $esbuild_cmd = sprintf(
            'NODE_PATH=%s %s %s --bundle --format=iife --target=es2020 --outfile=%s 2>&1',
            escapeshellarg(base_path('node_modules')),
            escapeshellarg($esbuild_bin),
            escapeshellarg($entry_file),
            escapeshellarg($bundle_path)
        );

        $output = [];
        $return_var = 0;
        \exec_safe($esbuild_cmd, $output, $return_var);

        // Restore working directory
        chdir($original_cwd);

        if ($return_var !== 0) {
            console_debug('BUNDLE', 'ESBuild failed: ' . implode("\n", $output));
            // Clean up temp file on error
            @unlink($entry_file);

            throw new RuntimeException('Failed to compile NPM modules: ' . implode("\n", $output));
        }

        // Clean up temp file
        @unlink($entry_file);

        // Verify bundle was created
        if (!file_exists($bundle_path)) {
            throw new RuntimeException("ESBuild did not produce expected output file: {$bundle_path}");
        }

        $size = filesize($bundle_path);
        console_debug('BUNDLE', "NPM bundle compiled: {$bundle_filename} ({$size} bytes)");

        return $bundle_path;
    }

    /**
    * Generate NPM import declarations file for app bundle
    * This creates const declarations that pull from window._rsx_npm
    */
    protected function _generate_npm_import_declarations(): string
    {
        if (empty($this->npm_includes)) {
            return '';
        }

        // Generate cache key from NPM includes
        $cache_key = md5(serialize($this->npm_includes));
        $filename = "npm_import_declarations_{$cache_key}.js";
        $file_path = storage_path("rsx-tmp/{$filename}");

        // Check if already generated
        if (file_exists($file_path)) {
            return $file_path;
        }

        // Generate content
        $content = "// NPM Import Declarations for App Bundle\n";
        $content .= "// Auto-generated to provide NPM modules to app bundle scope\n";
        $content .= "// Cache key: {$cache_key}\n\n";

        foreach ($this->npm_includes as $global_name => $import_statement) {
            // Generate const declaration
            $content .= "const {$global_name} = window._rsx_npm.{$global_name};\n";
            $content .= "if (!{$global_name}) {\n";
            $content .= "    throw new Error(\n";
            $content .= "        'RSX Framework Error: NPM module \"{$global_name}\" not found.\\n' +\n";
            $content .= "        'Expected window._rsx_npm.{$global_name} to be defined by the vendor bundle.'\n";
            $content .= "    );\n";
            $content .= "}\n\n";
        }

        // Clean up window._rsx_npm to prevent console access
        $content .= "// Clean up NPM container to prevent console access\n";
        $content .= "delete window._rsx_npm;\n";

        // Write file
        file_put_contents_safe($file_path, $content);

        return $file_path;
    }

    /**
    * Compile JS files
    */
    protected function _compile_js_files(array $files): string
    {
        // If we have config, write it to a temp file first
        $files_to_concat = [];

        if (!empty($this->config)) {
            $config_content = ['window.rsxapp = window.rsxapp || {};'];
            foreach ($this->config as $key => $value) {
                $config_content[] = "window.rsxapp.{$key} = " . json_encode($value) . ';';
            }

            // Write config to temp file
            $config_file = storage_path('rsx-tmp/bundle_config_' . $this->bundle_name . '.js');
            file_put_contents_safe($config_file, implode("\n", $config_content) . "\n");
            $files_to_concat[] = $config_file;
        }

        // Transform JavaScript files if Babel is enabled and decorators are used
        $babel_enabled = config('rsx.javascript.babel.transform_enabled', true);
        $decorators_enabled = config('rsx.javascript.decorators', true);

        if ($babel_enabled && $decorators_enabled) {
            // Use the JavaScript Transformer to transpile files with decorators
            // IMPORTANT: We populate $babel_file_mapping but DON'T modify $files array
            // This preserves dependency sort order - we substitute babel versions during concat

            foreach ($files as $file) {
                // Skip temp files, already processed files, and CDN cache files
                // CDN files are third-party production code - don't transform them
                if (str_contains($file, 'storage/rsx-tmp/') ||
                    str_contains($file, 'storage/rsx-build/') ||
                    str_contains($file, '.cdn-cache/')) {
                    continue;
                }

                // Transform the file (will use cache if available)
                try {
                    $transformed_code = \App\RSpade\Core\JsParsers\Js_Transformer::transform($file);

                    // Write transformed code to a temp file
                    $temp_file = storage_path('rsx-tmp/babel_' . md5($file) . '.js');
                    file_put_contents_safe($temp_file, $transformed_code);

                    // Store mapping: original file => babel file
                    // During concatenation we'll use the babel version
                    $this->babel_file_mapping[$file] = $temp_file;
                    console_debug('BUNDLE', 'Transformed ' . str_replace(base_path() . '/', '', $file));
                } catch (Exception $e) {
                    // FAIL LOUD - Never allow untransformed decorators through
                    throw new RuntimeException(
                        'JavaScript transformation failed for ' . str_replace(base_path() . '/', '', $file) .
"\nDecorators require Babel transformation to work in browsers.\n" .
'Error: ' . $e->getMessage() . "\n" .
'Fix: cd ' . base_path('app/RSpade/Core/JavaScript/resource') . ' && npm install'
                    );
                }
            }
        }

        // Add all the JS files
        $files_to_concat = array_merge($files_to_concat, $files);

        // Use Node.js script to concatenate with source map support
        $output_file = storage_path('rsx-tmp/bundle_output_' . $this->bundle_name . '.js');
        $concat_script = base_path('app/RSpade/Core/Bundle/resource/concat-js.js');

        // Build the command
        $cmd_parts = [
            'node',
            escapeshellarg($concat_script),
            escapeshellarg($output_file),
        ];
        foreach ($files_to_concat as $file) {
            // Use babel-transformed version if it exists, otherwise use original
            $file_to_use = $this->babel_file_mapping[$file] ?? $file;

            // If this is a babel-transformed file, pass metadata to concat-js
            // Format: babel_file_path::original_file_path
            if (isset($this->babel_file_mapping[$file])) {
                $cmd_parts[] = escapeshellarg($file_to_use . '::' . $file);
            } else {
                $cmd_parts[] = escapeshellarg($file_to_use);
            }
        }
        $cmd = implode(' ', $cmd_parts);

        // Execute the concatenation
        $output = [];
        $return_var = 0;
        \exec_safe($cmd . ' 2>&1', $output, $return_var);

        if ($return_var !== 0) {
            $error_msg = implode("\n", $output);

            throw new RuntimeException('Failed to concatenate JavaScript files: ' . $error_msg);
        }

        // Log the concatenation output
        if (!empty($output)) {
            foreach ($output as $line) {
                console_debug('BUNDLE', "concat-js: {$line}");
            }
        }

        // Read the concatenated result
        if (!file_exists($output_file)) {
            throw new RuntimeException("Concatenation script did not produce output file: {$output_file}");
        }

        $js = file_get_contents($output_file);

        // Clean up temp files
        if (!empty($this->config) && isset($config_file) && file_exists($config_file)) {
            @unlink($config_file);
        }
        @unlink($output_file);

        return $js;
    }

    /**
    * Compile CSS files with sourcemap support
    */
    protected function _compile_css_files(array $files): string
    {
        // Use Node.js script to concatenate with source map support
        $output_file = storage_path('rsx-tmp/css_bundle_' . $this->bundle_name . '.css');
        $concat_script = base_path('app/RSpade/Core/Bundle/resource/concat-css.js');

        // Build the command
        $cmd_parts = [
            'node',
            escapeshellarg($concat_script),
            escapeshellarg($output_file),
        ];
        foreach ($files as $file) {
            $cmd_parts[] = escapeshellarg($file);
        }
        $cmd = implode(' ', $cmd_parts);

        // Execute the concatenation
        $output = [];
        $return_var = 0;
        \exec_safe($cmd . ' 2>&1', $output, $return_var);

        if ($return_var !== 0) {
            $error_msg = implode("\n", $output);

            throw new RuntimeException('Failed to concatenate CSS files: ' . $error_msg);
        }

        // Log the concatenation output
        if (!empty($output)) {
            foreach ($output as $line) {
                console_debug('BUNDLE', "concat-css: {$line}");
            }
        }

        // Read the concatenated result
        if (!file_exists($output_file)) {
            throw new RuntimeException("CSS concatenation script did not produce output file: {$output_file}");
        }

        $css = file_get_contents($output_file);

        // Clean up temp file
        @unlink($output_file);

        return $css;
    }

    /**
     * Prepare CDN assets for rendering
     *
     * In development mode: returns assets as-is (loaded from CDN URLs)
     * In production-like modes: ensures assets are cached and adds cached_filename
     * so rendering can use /_vendor/{filename} URLs
     *
     * @param array $assets CDN assets array
     * @param string $type 'js' or 'css'
     * @return array Prepared assets with cached_filename in production modes
     */
    protected function _prepare_cdn_assets(array $assets, string $type): array
    {
        // Deduplicate by URL (same asset may be included via multiple bundles)
        $seen_urls = [];
        $deduplicated = [];
        foreach ($assets as $asset) {
            $url = $asset['url'] ?? '';
            if (empty($url) || isset($seen_urls[$url])) {
                continue;
            }
            $seen_urls[$url] = true;
            $deduplicated[] = $asset;
        }

        // Development: return deduplicated (use CDN URLs directly). Production-like
        // modes cache locally and serve from /_vendor/. Policy: _should_cache_cdn().
        if (!Manifest::_should_cache_cdn()) {
            return $deduplicated;
        }

        // In production-like modes, ensure cached and add filename
        $prepared = [];
        foreach ($deduplicated as $asset) {
            $url = $asset['url'];

            // Ensure the asset is cached (downloads if not already)
            Cdn_Cache::get($url, $type);

            // Add cached filename for /_vendor/ URL generation
            $asset['cached_filename'] = Cdn_Cache::get_cache_filename($url, $type);
            $prepared[] = $asset;
        }

        return $prepared;
    }

    /**
     * Get local file paths for cached CDN assets (DEPRECATED)
     *
     * Used in production-like modes to include CDN assets in concat scripts
     * for proper sourcemap handling.
     *
     * @param string $type 'js' or 'css'
     * @return array Array of local file paths to cached CDN files
     * @deprecated CDN assets are now served via /_vendor/ URLs, not merged into bundles
     */
    protected function _get_cdn_cache_file_paths(string $type): array
    {
        $file_paths = [];
        $assets = $this->cdn_assets[$type] ?? [];

        if (empty($assets)) {
            return $file_paths;
        }

        // Sort assets: jQuery first, then others alphabetically
        $jquery_assets = [];
        $other_assets = [];

        foreach ($assets as $asset) {
            $url = $asset['url'] ?? '';
            if (stripos($url, 'jquery') !== false) {
                $jquery_assets[] = $asset;
            } else {
                $other_assets[] = $asset;
            }
        }

        usort($other_assets, function ($a, $b) {
            return strcmp($a['url'] ?? '', $b['url'] ?? '');
        });

        $sorted_assets = array_merge($jquery_assets, $other_assets);

        // Get cache file path for each asset (downloads if not cached)
        // If download fails, let it throw - CDN assets are required
        foreach ($sorted_assets as $asset) {
            $url = $asset['url'] ?? '';
            if (empty($url)) {
                continue;
            }

            // This will download and cache if not already cached
            Cdn_Cache::get($url, $type);

            // Get the cache file path
            $cache_path = Cdn_Cache::get_cache_path($url, $type);
            if (file_exists($cache_path)) {
                $file_paths[] = $cache_path;
            }
        }

        return $file_paths;
    }

    /**
    * Create JavaScript manifest definitions
    */
    protected function _create_javascript_manifest(array $js_files): ?string
    {
        $class_definitions = [];
        $manifest_files = Manifest::get_all();

        // Analyze each JavaScript file for class information
        foreach ($js_files as $file) {
            // Skip most temp files, but handle auto-generated model classes
            if (str_contains($file, 'storage/rsx-tmp/')) {
                // Check if this is the auto-generated model classes file
                if (str_contains($file, 'bundle_generated_models_')) {
                    // Parse simple class declarations: class Foo extends Bar {}
                    $content = file_get_contents($file);
                    if (preg_match_all('/class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+([A-Za-z_][A-Za-z0-9_]*)/', $content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $class_definitions[$match[1]] = [
                                'name' => $match[1],
                                'extends' => $match[2],
                                'decorators' => null,
                            ];
                        }
                    }
                }
                continue;
            }

            // Generated auth check mirrors declare NO class - they are trailing-statement
            // attachments onto the Permission / Portal_Permission classes, which are
            // already registered from their own Core/Js source files. Nothing to define.
            if (str_contains($file, Auth_BundleIntegration::STUB_DIR . '/')) {
                continue;
            }

            // Check if this is a JS stub file (not in PHP manifest, needs direct parsing)
            // Stub files are in storage/rsx-build/js-stubs/ or storage/rsx-build/js-model-stubs/
            if (str_contains($file, 'storage/rsx-build/js-stubs/') || str_contains($file, 'storage/rsx-build/js-model-stubs/')) {
                $stub_content = file_get_contents($file);
                $stub_metadata = $this->_extract_stub_class_info($stub_content);

                if (!empty($stub_metadata['class'])) {
                    $class_definitions[$stub_metadata['class']] = [
                        'name' => $stub_metadata['class'],
                        'extends' => $stub_metadata['extends'],
                        'decorators' => null,  // Stubs don't have method decorators
                    ];
                }
                continue;
            }

            // Get relative path for manifest lookup
            $relative = str_replace(base_path() . '/', '', $file);

            // Get file data from manifest
            $file_data = $manifest_files[$relative] ?? null;

            // All JavaScript files MUST be in manifest
            if ($file_data === null) {
                throw new \RuntimeException(
                    "JavaScript file in bundle but not in manifest (this should never happen):\n" .
                    "File: {$file}\n" .
                    "Relative: {$relative}\n" .
                    "Bundle: {$this->bundle_name}\n" .
                    "Manifest has " . count($manifest_files) . " files total"
                );
            }

            // If file has a class, add to class definitions
            if (!empty($file_data['class'])) {
                $class_name = $file_data['class'];
                $extends_class = $file_data['extends'] ?? null;

                // Skip if extends is same as class (manifest parsing error)
                if ($extends_class === $class_name) {
                    $extends_class = null;
                }

                $class_definitions[$class_name] = [
                    'name' => $class_name,
                    'extends' => $extends_class,
                    'decorators' => $file_data['method_decorators'] ?? null,
                ];
            }
            // Otherwise, it's a standalone function file - no manifest registration needed
        }

        // If no classes found, return null
        if (empty($class_definitions)) {
            return null;
        }

        // Generate JavaScript code for manifest
        $js_items = [];
        foreach ($class_definitions as $class_def) {
            $class_name = $class_def['name'];
            $extends_class = $class_def['extends'];
            $decorators = $class_def['decorators'] ?? null;

            // Skip 'object' as it's not a real class
            if (strtolower($class_name) === 'object') {
                continue;
            }

            // Build the manifest entry parts
            $parts = [];
            $parts[] = $class_name;  // Class object
            $parts[] = "\"{$class_name}\"";  // Class name string
            $parts[] = $extends_class ?: 'null';  // Parent class or null

            // Add decorator data if present
            if (!empty($decorators)) {
                $decorator_json = json_encode($decorators, JSON_UNESCAPED_SLASHES);
                $parts[] = $decorator_json;
            }

            // Generate the array entry
            $js_items[] = '[' . implode(', ', $parts) . ']';
        }

        // Build the JavaScript code
        $js_code = "// JavaScript Manifest - Generated by BundleCompiler\n";
        $js_code .= "// Registers all classes in this bundle for runtime introspection\n";
        $js_code .= "Manifest._define([\n";
        $js_code .= '    ' . implode(",\n    ", $js_items) . "\n";
        $js_code .= "]);\n\n";

        // Write to temporary file
        return $this->_write_temp_file($js_code, 'js');
    }

    /**
    * Create JavaScript runner for automatic class initialization
    */
    protected function _create_javascript_runner(): string
    {
        $js_code = <<<'JS'
$(document).ready(async function() {
try {
console_debug('RSX_INIT', 'Document ready, starting Rsx._rsx_core_boot');
await Rsx._rsx_core_boot();
console_debug('RSX_INIT', 'Initialization complete');
} catch (error) {
console.error('[RSX_INIT] Initialization failed:', error);
console.error('[RSX_INIT] Stack:', error.stack);
throw error;
}
});
JS;

        // Write to temporary file
        return $this->_write_temp_file($js_code, 'js');
    }

    /**
    * Create JavaScript route definitions from PHP controllers
    */
    protected function _create_javascript_routes(): ?string
    {
        // Use Manifest::get_all() which returns the proper file metadata
        $manifest_files = Manifest::get_all();
        $routes = [];

        console_debug('BUNDLE', 'Scanning for routes in ' . count($this->included_files) . ' included files');

        // Scan all included files for controllers
        foreach ($this->included_files as $file_path => $included) {
            // Only check PHP files
            if (!str_ends_with($file_path, '.php')) {
                continue;
            }

            // Get relative path for manifest lookup
            $relative = str_replace(base_path() . '/', '', $file_path);

            // Check if file is in manifest
            if (!isset($manifest_files[$relative])) {
                continue;
            }

            $file_info = $manifest_files[$relative];

            // Skip if no class in file
            if (empty($file_info['fqcn'])) {
                continue;
            }

            $class_name = $file_info['class'];
            $fqcn = $file_info['fqcn'];

            // Check if it extends Rsx_Controller_Abstract
            if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Controller_Abstract')) {
                continue;
            }

            console_debug('BUNDLE', "Found controller: {$class_name}");

            // Process methods with Route attributes
            foreach ($file_info['public_static_methods'] ?? [] as $method_name => $method_data) {
                foreach ($method_data['attributes'] ?? [] as $attr_name => $attr_instances) {
                    if ($attr_name === 'Route') {
                        // Collect all route patterns for this method (supports multiple #[Route] attributes)
                        foreach ($attr_instances as $instance) {
                            $route_pattern = $instance[0] ?? null;
                            if ($route_pattern) {
                                console_debug('BUNDLE', "  Found route: {$class_name}::{$method_name} => {$route_pattern}");
                                // Initialize arrays if needed
                                if (!isset($routes[$class_name])) {
                                    $routes[$class_name] = [];
                                }
                                if (!isset($routes[$class_name][$method_name])) {
                                    $routes[$class_name][$method_name] = [];
                                }
                                // Append route pattern to array
                                $routes[$class_name][$method_name][] = $route_pattern;
                            }
                        }
                    }
                }
            }
        }

        // Also scan include_routes paths if specified
        foreach ($this->include_routes as $route_path) {
            $full_path = base_path($route_path);
            if (!is_dir($full_path)) {
                continue;
            }

            // Scan directory for PHP files
            $php_files = $this->_scan_directory_recursive($full_path, 'php');

            foreach ($php_files as $file) {
                // Get relative path for manifest lookup
                $relative = str_replace(base_path() . '/', '', $file);

                // Check if file is in manifest
                if (!isset($manifest_files[$relative])) {
                    continue;
                }

                $file_info = $manifest_files[$relative];

                // Skip if no class in file
                if (empty($file_info['fqcn'])) {
                    continue;
                }

                $class_name = $file_info['class'];
                $fqcn = $file_info['fqcn'];

                // Check if it extends Rsx_Controller_Abstract
                if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Controller_Abstract')) {
                    continue;
                }

                // Process methods with Route attributes
                foreach ($file_info['public_static_methods'] ?? [] as $method_name => $method_data) {
                    foreach ($method_data['attributes'] ?? [] as $attr_name => $attr_instances) {
                        if ($attr_name === 'Route') {
                            // Collect all route patterns for this method (supports multiple #[Route] attributes)
                            foreach ($attr_instances as $instance) {
                                $route_pattern = $instance[0] ?? null;
                                if ($route_pattern) {
                                    // Initialize arrays if needed
                                    if (!isset($routes[$class_name])) {
                                        $routes[$class_name] = [];
                                    }
                                    if (!isset($routes[$class_name][$method_name])) {
                                        $routes[$class_name][$method_name] = [];
                                    }
                                    // Append route pattern to array
                                    $routes[$class_name][$method_name][] = $route_pattern;
                                }
                            }
                        }
                    }
                }
            }
        }

        // If no routes found, return null
        if (empty($routes)) {
            return null;
        }

        // Deterministic emission order: $routes is keyed in file-iteration order,
        // which is filesystem readdir dependent. Sort so the generated JS is
        // identical across two byte-identical checkouts.
        ksort($routes);
        foreach ($routes as &$_methods) {
            ksort($_methods);
        }
        unset($_methods);

        // Generate JavaScript code
        $js_code = "// RSX Route Definitions - Generated by BundleCompiler\n";
        $js_code .= "// Provides route patterns for type-safe URL generation\n";
        $js_code .= 'Rsx._define_routes(' . json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . ");\n";

        // Write to temporary file
        return $this->_write_temp_file($js_code, 'js');
    }

    /**
     * Generate portal route definitions for JavaScript (Rsx_Portal._define_routes)
     *
     * Scans included files for controllers with #[Portal_Route] attributes
     * and generates JS code to register them with Rsx_Portal._define_routes().
     */
    protected function _create_javascript_portal_routes(): ?string
    {
        $manifest_files = Manifest::get_all();
        $routes = [];

        // Scan all included files for portal controllers
        foreach ($this->included_files as $file_path => $included) {
            if (!str_ends_with($file_path, '.php')) {
                continue;
            }

            $relative = str_replace(base_path() . '/', '', $file_path);

            if (!isset($manifest_files[$relative])) {
                continue;
            }

            $file_info = $manifest_files[$relative];

            if (empty($file_info['fqcn'])) {
                continue;
            }

            $class_name = $file_info['class'];

            if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Controller_Abstract')) {
                continue;
            }

            // Process methods with Portal_Route attributes
            foreach ($file_info['public_static_methods'] ?? [] as $method_name => $method_data) {
                foreach ($method_data['attributes'] ?? [] as $attr_name => $attr_instances) {
                    if ($attr_name === 'Portal_Route') {
                        foreach ($attr_instances as $instance) {
                            $route_pattern = $instance[0] ?? null;
                            if ($route_pattern) {
                                if (!isset($routes[$class_name])) {
                                    $routes[$class_name] = [];
                                }
                                if (!isset($routes[$class_name][$method_name])) {
                                    $routes[$class_name][$method_name] = [];
                                }
                                $routes[$class_name][$method_name][] = $route_pattern;
                            }
                        }
                    }
                }
            }
        }

        if (empty($routes)) {
            return null;
        }

        // Deterministic emission order (see the non-portal route emitter above).
        ksort($routes);
        foreach ($routes as &$_methods) {
            ksort($_methods);
        }
        unset($_methods);

        $js_code = "// RSX Portal Route Definitions - Generated by BundleCompiler\n";
        $js_code .= "// Provides portal route patterns for Rsx_Portal.Route() URL generation\n";
        $js_code .= 'Rsx_Portal._define_routes(' . json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . ");\n";

        return $this->_write_temp_file($js_code, 'js');
    }

    /**
     * Bake the declared external-resource map into the bundle (Rsx_External_Resources._define)
     *
     * The map is the client half of the *.externals.php registry: identifier => resolved
     * urls + integrity + readiness, already mode-resolved by Rsx_Externals (raw external URLs
     * in development, /_vendor/ copies in a sealed build). The loader reaches a resource by
     * identifier only, and the CSP whitelist derives from the same declarations.
     *
     * Realm: see _detect_bundle_realm(). Entries declaring 'both' appear in either realm's map.
     */
    protected function _create_javascript_externals(): ?string
    {
        $realm = $this->_detect_bundle_realm();
        $map = Rsx_Externals::resolved_map_for_realm($realm);

        if (empty($map)) {
            return null;
        }

        // An entry with no declared hashes must still emit a JS OBJECT: an empty PHP array
        // json_encodes as [], and the loader indexes integrity BY URL.
        foreach ($map as $identifier => $entry) {
            $map[$identifier]['integrity'] = (object) $entry['integrity'];
        }

        // Rsx_Externals ksorts the map (and each entry's integrity table) already, so two
        // byte-identical checkouts emit byte-identical JS.
        $js_code = "// RSX External Resource Definitions - Generated by BundleCompiler\n";
        $js_code .= "// Declared in *.externals.php files; loaded by Rsx.load_external(identifier)\n";
        $js_code .= "// Realm: {$realm}\n";
        $js_code .= 'Rsx_External_Resources._define(' . json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . ");\n";

        return $this->_write_temp_file($js_code, 'js');
    }

    /**
     * Which auth realm is this bundle built for - 'portal' or 'staff'?
     *
     * A bundle carries no realm flag, and nothing at COMPILE time can consult the request,
     * so the honest signal is the bundle's own include set: a portal bundle is the one that
     * includes the portal's #[Portal_Route] controllers - exactly the signal
     * _create_javascript_portal_routes() already keys its emission on. A bundle that includes
     * both resolves to 'portal', because that is the experience it is capable of serving.
     */
    protected function _detect_bundle_realm(): string
    {
        $manifest_files = Manifest::get_all();

        foreach ($this->included_files as $file_path => $included) {
            if (!str_ends_with($file_path, '.php')) {
                continue;
            }

            $relative = str_replace(base_path() . '/', '', $file_path);

            if (!isset($manifest_files[$relative])) {
                continue;
            }

            $file_info = $manifest_files[$relative];

            if (empty($file_info['fqcn'])) {
                continue;
            }

            if (!Manifest::php_is_subclass_of($file_info['class'], 'Rsx_Controller_Abstract')) {
                continue;
            }

            foreach ($file_info['public_static_methods'] ?? [] as $method_data) {
                if (!empty($method_data['attributes']['Portal_Route'])) {
                    return 'portal';
                }
            }
        }

        return 'staff';
    }

    /**
     * Recursively scan directory for files with specific extension
     */
    protected function _scan_directory_recursive(string $path, string $extension): array
    {
        $files = [];

        if (!is_dir($path)) {
            return $files;
        }

        $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
