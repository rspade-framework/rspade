<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Kernels\ManifestKernel;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Rsx;

/**
 * _Manifest_Cache_Helper - Persistence, loading, and validation
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Cache_Helper
{
    /**
    * Get or create the kernel instance
    */
    public static function _get_kernel(): ManifestKernel
    {
        if (Manifest::$kernel === null) {
            Manifest::$kernel = app(ManifestKernel::class);
        }

        return Manifest::$kernel;
    }

    /**
    * Get the full cache file path
    */
    public static function _get_cache_file_path(): string
    {
        // Storage-root relative: volatile storage was relocated out of system/ to the
        // project root, so this must never be derived from base_path().
        return storage_path(Manifest::CACHE_FILE);
    }

    // move to lower soon

    /**
    * Load cached manifest data
    */
    public static function _load_cached_data()
    {
        $cache_file = Manifest::_get_cache_file_path();

        if (file_exists($cache_file)) {
            Manifest::$data = include $cache_file;
            // Validate structure
            if (is_array(Manifest::$data) && isset(Manifest::$data['data']['files']) && count(Manifest::$data['data']['files']) > 0) {
                // Check if manifest is marked as bad due to code quality violations
                if (isset(Manifest::$data['data']['manifest_is_bad']) && Manifest::$data['data']['manifest_is_bad']) {
                    // Clear the data to force a rebuild
                    Manifest::$data = [
                        'generated' => date('Y-m-d H:i:s'),
                        'hash' => '',
                        'data' => ['files' => []],
                    ];

                    return false;
                }

                return true;
            }
        }

        // Cache doesn't exist or is invalid - return false without logging
        // Logging happens in init() after we determine we actually need to rebuild
        Manifest::$data = [
            'generated' => date('Y-m-d H:i:s'),
            'hash' => '',
            'data' => ['files' => []],
        ];

        return false;
    }

    /**
    * Save manifest data to cache
    */
    public static function _save(): void
    {
        // If manifest is marked as bad, handle specially
        if (Manifest::$_manifest_is_bad) {
            // Check if manifest has been initialized
            if (!Manifest::$_has_init) {
                return; // Don't save if not initialized
            }

            $cache_file = Manifest::_get_cache_file_path();

            // Check if cache file exists
            if (!file_exists($cache_file)) {
                return; // Don't save if cache doesn't exist yet
            }

            // Check if we've already marked it as bad
            if (isset(Manifest::$data['data']['manifest_is_bad'])) {
                return; // Already marked, don't save again
            }

            // Mark as bad and save
            Manifest::$data['data']['manifest_is_bad'] = true;
            // Fall through to normal save logic below
        }

        // Validate manifest data before saving
        Manifest::_validate_manifest_data();

        $cache_file = Manifest::_get_cache_file_path();

        // Ensure directory exists
        $dir = dirname($cache_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Sort files array by key for predictable output
        if (isset(Manifest::$data['data']['files'])) {
            ksort(Manifest::$data['data']['files']);
        }

        // Strict production seals a byte-stable cache file: the wall-clock
        // 'generated' timestamp is the only non-deterministic input to the file's
        // bytes (the build key already excludes it - see _compute_hash), so we drop
        // it entirely in strict prod and record build time in the seal instead.
        // Dev/debug keep it (harmless, aids debugging).
        $strict_prod = Rsx::is_production() && !Rsx::is_debug();

        if ($strict_prod) {
            unset(Manifest::$data['generated']);
        } else {
            Manifest::$data['generated'] = date('Y-m-d H:i:s');
        }

        // Build key: SHA-256 (truncated to 32 chars) over a NORMALIZED projection of
        // the manifest body that excludes per-file mtime/size and absolute-path
        // prefixes, so two identical checkouts at different absolute paths produce
        // an identical key. mtime/size remain in the cache FILE below for dev
        // change-detection - they just no longer influence the key.
        Manifest::$data['hash'] = self::_compute_hash(Manifest::$data['data']);

        $php_content = "<?php\n\n";
        $php_content .= "// Generated manifest cache - DO NOT EDIT\n";
        if (!$strict_prod) {
            $php_content .= '// Generated: ' . Manifest::$data['generated'] . "\n";
        }
        $php_content .= '// Files: ' . count(Manifest::$data['data']['files']) . "\n";
        $php_content .= '// Hash: ' . Manifest::$data['hash'] . "\n\n";

        // Use compact format in strict production only, pretty format in dev/debug.
        //
        // In strict production the file must be BYTE-STABLE across builds and
        // checkouts (so a cluster/CI can key on its sha), so we also strip per-file
        // mtime/size from the WRITTEN file. Those values vary with local disk state
        // and with the regeneration of the manifest's own generated stub files
        // (js-stubs/js-model-stubs get a fresh mtime every build), yet they are only
        // ever consumed by dev-mode change-detection - which never runs against a
        // prod cache (prod init loads the cache and returns). The build key already
        // excludes them, so stripping them from the file changes no semantics.
        if ($strict_prod) {
            $data_to_write = Manifest::$data;
            if (isset($data_to_write['data']['files']) && is_array($data_to_write['data']['files'])) {
                foreach ($data_to_write['data']['files'] as &$meta) {
                    if (is_array($meta)) {
                        unset($meta['mtime'], $meta['size']);
                    }
                }
                unset($meta);
            }
            $php_content .= 'return ' . self::_compact_var_export($data_to_write) . ";\n";
        } else {
            $php_content .= 'return ' . var_export(Manifest::$data, true) . ";\n";
        }

        file_put_contents_safe($cache_file, $php_content);

        // Write build key to separate file for non-PHP consumers (FPC proxy, etc.)
        file_put_contents_safe(dirname($cache_file) . '/build_key', Manifest::$data['hash']);
    }

    public static function _validate_cached_data()
    {
        // If cache exists, check if anything changed
        $files = Manifest::_get_rsx_files();

        // Check for changed files
        foreach ($files as $file) {
            if (Manifest::_has_changed($file)) {
                return false;
            }
        }

        // Check for deleted files
        $existing_files = array_flip($files);

        foreach (array_keys(Manifest::$data['data']['files']) as $cached_file) {
            // Skip storage files - they're not part of the manifest
            if (str_starts_with($cached_file, 'storage/')) {
                continue;
            }
            if (!isset($existing_files[$cached_file])) {
                // Only show the message once per page load
                if (!Manifest::$__shown_rescan_message) {
                    console_debug('MANIFEST', '* Deleted file ' . $cached_file . ' is triggering manifest rescan *');
                    Manifest::$__shown_rescan_message = true;
                }

                return false;
            }
        }

        // Phase-6 stub outputs (controller js-stubs + model js-model-stubs) live under
        // storage/rsx-build, are recorded as manifest 'files' entries flagged is_stub /
        // is_model_stub, and get a fresh mtime every build - so they are DELIBERATELY
        // exempt from both the mtime staleness sweep and the deletion sweep above (the
        // storage/ skip). That exemption also means their ABSENCE from disk never
        // invalidates the cache on its own: a fresh-and-valid cache whose stub files (or
        // the whole js-model-stubs dir) went missing after a framework-update recovery or
        // a prune would otherwise stay permanently stub-less, killing the client-side
        // model layer app-wide with no self-heal. Prove the recorded outputs still exist;
        // a missing one makes the cache STALE so init() falls through to a full rebuild
        // that regenerates them. Cheap file_exists() sweep, no hashing.
        //
        // DEV-ONLY by construction: init() returns after loading the production cache
        // (the Rsx::is_production() branch) BEFORE ever calling _validate_cached_data(),
        // so this guard only runs on the development change-detection path. Sealed
        // debug/production builds already guarantee their artifacts and fail loud on
        // missing ones, so no auto-rebuild is added there.
        $missing_stub = self::_first_missing_stub_output(Manifest::$data['data']['files']);
        if ($missing_stub !== null) {
            if (!Manifest::$__shown_rescan_message) {
                console_debug('MANIFEST', '* Missing generated stub output ' . $missing_stub . ' is triggering manifest rescan *');
                Manifest::$__shown_rescan_message = true;
            }

            return false;
        }

        return true;
    }

    /**
     * Return the first recorded Phase-6 stub output missing from disk, or null.
     *
     * Scans a manifest files map for generated-stub entries (flagged is_stub for
     * controller js-stubs, is_model_stub for model js-model-stubs) and returns the
     * project-relative path of the first one whose file does not exist. Resolves each
     * stub independently, so it catches both a single deleted stub file and the whole
     * js-stubs / js-model-stubs directory being absent. Returns null when every stub
     * output is present (or when there are none).
     *
     * Pure over its argument (file_exists is the only side channel) so it is unit
     * testable with a synthetic files map.
     *
     * @param array $manifest_files The value of Manifest::$data['data']['files']
     * @return string|null Missing stub's project-relative path, or null if all present
     */
    public static function _first_missing_stub_output(array $manifest_files): ?string
    {
        foreach ($manifest_files as $path => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            if (empty($meta['is_model_stub']) && empty($meta['is_stub'])) {
                continue;
            }
            if (!file_exists(rsx_project_file_path($path))) {
                return $path;
            }
        }

        return null;
    }

    /**
    * Validate manifest data for consistency
    */
    public static function _validate_manifest_data(): void
    {
        if (!isset(Manifest::$data['data']['files'])) {
            throw new \RuntimeException(
                'Fatal: Manifest::validate_manifest_data() called but manifest data structure is not initialized. ' .
"This shouldn't happen - data should be populated before validation."
            );
        }

        // Track unique names by file type
        $php_classes = [];
        $js_classes = [];
        $blade_ids = [];
        $jqhtml_ids = [];

        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            $extension = $metadata['extension'] ?? '';

            // Check PHP class uniqueness
            if ($extension === 'php' && isset($metadata['class'])) {
                $class = $metadata['class'];
                if (isset($php_classes[$class])) {
                    throw new \RuntimeException(
                        "Duplicate PHP class name detected: {$class}\n" .
"Found in:\n" .
"  - {$php_classes[$class]}\n" .
"  - {$file_path}\n\n" .
"PHP class names must be unique across all files.\n\n" .
"To resolve: Add specificity to the class names by prefixing with directory segments.\n" .
"For example, if a class named 'Index_Controller' resides in the 'demo' module directory,\n" .
"rename it to 'Demo_Index_Controller'. Similarly, classes in nested directories like\n" .
"'demo/admin/' could be named 'Demo_Admin_Index_Controller'. After renaming, refactor\n" .
'all usages of the old class name throughout the codebase to use the new, more specific name.'
                    );
                }
                $php_classes[$class] = $file_path;
            }

            // Check JavaScript class uniqueness
            if (in_array($extension, ['js', 'jsx', 'ts', 'tsx']) && isset($metadata['class'])) {
                $class = $metadata['class'];
                if (isset($js_classes[$class])) {
                    throw new \RuntimeException(
                        "Duplicate JavaScript class name detected: {$class}\n" .
"Found in:\n" .
"  - {$js_classes[$class]}\n" .
"  - {$file_path}\n\n" .
"JavaScript class names must be unique across all files.\n\n" .
"To resolve: Add specificity to the class names by prefixing with directory segments.\n" .
"For example, if a class named 'Base' resides in the 'demo' module directory,\n" .
"rename it to 'Demo_Base'. Similarly, classes in nested directories like\n" .
"'demo/admin/' could be named 'Demo_Admin_Base'. After renaming, refactor\n" .
'all usages of the old class name throughout the codebase to use the new, more specific name.'
                    );
                }
                $js_classes[$class] = $file_path;
            }

            // Check Blade ID uniqueness
            if ($extension === 'blade.php' && isset($metadata['id'])) {
                $id = $metadata['id'];
                if (isset($blade_ids[$id])) {
                    throw new \RuntimeException(
                        "Duplicate Blade @rsx_id detected: {$id}\n" .
"Found in:\n" .
"  - {$blade_ids[$id]}\n" .
"  - {$file_path}\n\n" .
"Blade @rsx_id values must be unique across all files.\n\n" .
"To resolve: Add specificity to the @rsx_id by prefixing with directory segments.\n" .
"For example, if a view with @rsx_id('Layout') resides in the 'demo' module directory,\n" .
"change it to @rsx_id('Demo_Layout'). Similarly, views in nested directories like\n" .
"'demo/sections/' could use @rsx_id('Demo_Sections_Layout'). After renaming, refactor\n" .
'all references to the old @rsx_id (in @rsx_extends, @rsx_include, etc.) to use the new name.'
                    );
                }
                $blade_ids[$id] = $file_path;
            }

            // Check Jqhtml component ID uniqueness
            if ($extension === 'jqhtml' && isset($metadata['id'])) {
                $id = $metadata['id'];
                if (isset($jqhtml_ids[$id])) {
                    throw new \RuntimeException(
                        "Duplicate jqhtml component name detected: {$id}\n" .
"Found in:\n" .
"  - {$jqhtml_ids[$id]}\n" .
"  - {$file_path}\n\n" .
"Jqhtml component names (<Define:ComponentName>) must be unique across all files.\n\n" .
"To resolve: Add specificity to the component name by prefixing with directory segments.\n" .
"For example, if a component named 'Card' resides in the 'demo' module directory,\n" .
"rename it to 'Demo_Card' in the <Define:> tag. Similarly, components in nested directories\n" .
"like 'demo/widgets/' could be named 'Demo_Widgets_Card'. After renaming, refactor all\n" .
'usages of the component (in Blade templates and JavaScript) to use the new, more specific name.'
                    );
                }
                $jqhtml_ids[$id] = $file_path;
            }

            // Check that controller actions don't have both Route and Ajax_Endpoint
            if ($extension === 'php' && isset($metadata['extends'])) {
                // Check if this is a controller (extends Rsx_Controller_Abstract)
                $is_controller = Manifest::_is_controller_class($metadata);

                if ($is_controller && isset($metadata['public_static_methods'])) {
                    foreach ($metadata['public_static_methods'] as $method_name => $method_info) {
                        if (!isset($method_info['attributes'])) {
                            continue;
                        }

                        $has_route = false;
                        $has_ajax_endpoint = false;
                        $has_task = false;

                        foreach ($method_info['attributes'] as $attr_name => $attr_instances) {
                            if ($attr_name === 'Route' || str_ends_with($attr_name, '\\Route')) {
                                $has_route = true;
                            }
                            if ($attr_name === 'Ajax_Endpoint' || str_ends_with($attr_name, '\\Ajax_Endpoint')) {
                                $has_ajax_endpoint = true;
                            }
                            if ($attr_name === 'Task' || str_ends_with($attr_name, '\\Task')) {
                                $has_task = true;
                            }
                        }

                        // Check for conflicting attributes
                        $conflicts = [];
                        if ($has_route) {
                            $conflicts[] = 'Route';
                        }
                        if ($has_ajax_endpoint) {
                            $conflicts[] = 'Ajax_Endpoint';
                        }
                        if ($has_task) {
                            $conflicts[] = 'Task';
                        }

                        if (count($conflicts) > 1) {
                            $class_name = $metadata['class'] ?? 'Unknown';

                            throw new \RuntimeException(
                                'Method cannot have multiple execution type attributes: ' . implode(', ', $conflicts) . "\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n" .
'A method must be either a Route, Ajax_Endpoint, OR Task, not multiple types.'
                            );
                        }

                        // Check Ajax_Endpoint methods don't have return types
                        if ($has_ajax_endpoint && isset($method_info['return_type'])) {
                            $class_name = $metadata['class'] ?? 'Unknown';
                            $return_type_info = $method_info['return_type'];

                            // Format return type for error message
                            if (isset($return_type_info['type']) && $return_type_info['type'] === 'union') {
                                $type_display = implode('|', $return_type_info['types']);
                            } else {
                                $type_display = $return_type_info['type'] ?? 'unknown';
                                if (!empty($return_type_info['nullable'])) {
                                    $type_display = '?' . $type_display;
                                }
                            }

                            throw new \RuntimeException(
                                "Ajax endpoint has forbidden return type declaration: {$type_display}\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n\n" .
"Ajax endpoints must NOT declare return types because they need flexibility to return:\n" .
"- Array data (success case)\n" .
"- Form_Error_Response (validation errors)\n" .
"- Redirect_Response (redirects)\n" .
"- Other response types as needed\n\n" .
"Solution: Remove the return type declaration from this method.\n" .
"Change: public static function {$method_name}(...): {$type_display}\n" .
"To:     public static function {$method_name}(...)\n"
                            );
                        }

                        // Check FPC attribute constraints
                        $has_fpc = false;
                        $has_spa = false;

                        foreach ($method_info['attributes'] as $attr_name => $attr_instances) {
                            if ($attr_name === 'FPC' || str_ends_with($attr_name, '\\FPC')) {
                                $has_fpc = true;
                            }
                            if ($attr_name === 'SPA' || str_ends_with($attr_name, '\\SPA')) {
                                $has_spa = true;
                            }
                        }

                        if ($has_fpc) {
                            $class_name = $metadata['class'] ?? 'Unknown';

                            if ($has_ajax_endpoint) {
                                throw new \RuntimeException(
                                    "#[FPC] cannot be used on Ajax endpoints\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n\n" .
"#[FPC] marks a route for full page caching. Ajax endpoints return JSON\n" .
"data, not HTML pages.\n\n" .
"Solution: Remove #[FPC] from this Ajax endpoint."
                                );
                            }

                            if ($has_spa) {
                                throw new \RuntimeException(
                                    "#[FPC] cannot be used on #[SPA] methods\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n\n" .
"SPA bootstrap methods return an empty shell that JavaScript populates.\n" .
"Caching this shell serves the same empty page for all SPA routes.\n\n" .
"Solution: Remove #[FPC] from this SPA method."
                                );
                            }

                            if ($has_task) {
                                throw new \RuntimeException(
                                    "#[FPC] cannot be used on Task methods\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n\n" .
"Solution: Remove #[FPC] from this Task method."
                                );
                            }

                            if (!$has_route) {
                                throw new \RuntimeException(
                                    "#[FPC] requires #[Route] attribute\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n\n" .
"#[FPC] can only be used on methods with a #[Route] attribute.\n\n" .
"Solution: Add #[Route('/path')] to this method, or remove #[FPC]."
                                );
                            }
                        }
                    }
                }
            }
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
        $cache_file = Manifest::_get_cache_file_path();
        if (file_exists($cache_file)) {
            @unlink($cache_file);
        }
    }

    /**
     * Generate compact PHP array export (no whitespace formatting)
     *
     * Produces valid PHP array syntax like var_export() but without
     * newlines and indentation for smaller file size in production.
     */
    public static function _compact_var_export(mixed $value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return var_export($value, true);
        }

        if (is_array($value)) {
            if (empty($value)) {
                return 'array()';
            }

            $parts = [];
            $is_sequential = array_keys($value) === range(0, count($value) - 1);

            foreach ($value as $k => $v) {
                if ($is_sequential) {
                    $parts[] = self::_compact_var_export($v);
                } else {
                    $parts[] = var_export($k, true) . '=>' . self::_compact_var_export($v);
                }
            }

            return 'array(' . implode(',', $parts) . ')';
        }

        // Non-array, non-scalar (objects/other) - defer to var_export.
        return var_export($value, true);
    }

    /**
     * Compute the manifest build key over a deterministic, checkout-independent
     * projection of the manifest body.
     *
     * @param array $manifest_body The value of Manifest::$data['data']
     * @return string 32-char truncated SHA-256
     */
    public static function _compute_hash(array $manifest_body): string
    {
        return substr(hash('sha256', json_encode(self::_normalize_for_hash($manifest_body))), 0, 32);
    }

    /**
     * Build the normalized projection of the manifest body used for the build key.
     *
     * The build key must be identical for two byte-identical checkouts at different
     * absolute paths (the cluster contract). Everything that varies with local disk
     * state or checkout location is stripped:
     *   - per-file mtime/size (local disk state; retained in the cache FILE for dev
     *     change-detection but never allowed to influence the key);
     *   - absolute-path prefixes embedded anywhere in the metadata (e.g. reflected
     *     trait/method 'file' entries report absolute paths) - reduced to
     *     project-relative form.
     * All semantic metadata (per-file sha1 hashes, class maps, relative paths,
     * attributes) is preserved. Receives a copy (by value), so the caller's live
     * Manifest::$data is not mutated.
     *
     * @param array $manifest_body The value of Manifest::$data['data']
     * @return array Normalized projection
     */
    public static function _normalize_for_hash(array $manifest_body): array
    {
        if (isset($manifest_body['files']) && is_array($manifest_body['files'])) {
            // Deterministic key ordering, and drop local disk state from the hash.
            ksort($manifest_body['files']);
            foreach ($manifest_body['files'] as $path => &$meta) {
                if (is_array($meta)) {
                    unset($meta['mtime'], $meta['size']);
                }
            }
            unset($meta);
        }

        return self::_normalize_recursive($manifest_body);
    }

    /**
     * Recursively normalize the manifest body for hashing:
     *   - reduce absolute source paths to project-relative form (checkout
     *     independence);
     *   - sort ASSOCIATIVE maps by key (many manifest maps - class maps, method
     *     maps, model registries - are populated in filesystem readdir order,
     *     which differs between checkouts/filesystems; the build key must not
     *     depend on that order). Sequential/list arrays (e.g. parameter lists)
     *     are left in place because their order is semantic.
     * Runtime data is untouched - this operates on a by-value copy used only to
     * compute the build key.
     */
    protected static function _normalize_recursive(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::_normalize_recursive($v);
            }

            if (is_associative_array($result)) {
                ksort($result);
            }

            return $result;
        }

        if (is_string($value)) {
            return _rsx_relative_build_path($value);
        }

        return $value;
    }

}
