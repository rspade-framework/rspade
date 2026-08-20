<?php

/**
 * RSpade Global Helper Functions
 *
 * A collection of utility functions for the RSpade framework and applications.
 * These provide quality of life improvements for common development tasks.
 *
 * This file is automatically loaded via composer.json autoload files.
 */

/**
 * Check if code is running in IDE context (code analysis/completion)
 *
 * @return bool True if running in IDE, false otherwise
 */
function is_ide(): bool
{
    // Only consider IDE if running in CLI mode
    if (PHP_SAPI !== 'cli') {
        return false;
    }

    // Check for known IDE environment variables
    $ide_env_vars = [
        'PHPSTORM_IDE',           // PhpStorm
        'VSCODE_PID',             // VS Code
        'IDEA_INITIAL_DIRECTORY', // IntelliJ IDEA
        'SUBLIME_TEXT',           // Sublime Text
        'ATOM_HOME',              // Atom
        'NVIM_LISTEN_ADDRESS',    // Neovim
        'VIM',                    // Vim
        'EMACS',                  // Emacs
    ];

    foreach ($ide_env_vars as $var) {
        if (getenv($var) !== false) {
            return true;
        }
    }

    // Check if running from "Command line code" (common in IDE analysis)
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
    foreach ($trace as $frame) {
        if (isset($frame['file']) && strpos($frame['file'], 'Command line code') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Debug output helper for development debugging with journal categorization
 *
 * Outputs debug messages with file:line information for tracking code execution.
 * Messages are prefixed with [JOURNAL] category for filtering/categorization.
 * Behavior depends on execution context:
 *
 * CLI Mode:
 * - Outputs to stderr with cyan color formatting
 * - Controlled by SHOW_CONSOLE_DEBUG_CLI environment flag (default: false)
 * - Example: [app/Http/Controllers/TestController.php:42] [DISPATCH] Processing route /login
 *
 * HTTP Mode:
 * - Batches messages and outputs as JavaScript console.log() statements
 * - Messages appear in browser developer console
 * - Controlled by SHOW_CONSOLE_DEBUG_HTTP environment flag (default: true)
 * - Automatically outputs after bundles and on shutdown (even after fatal errors)
 *
 * Environment Flags (set in .env):
 * - SHOW_CONSOLE_DEBUG_CLI=true|false  - Enable/disable CLI output (default: false)
 * - SHOW_CONSOLE_DEBUG_HTTP=true|false - Enable/disable HTTP browser console output (default: true)
 *
 * Never outputs in production mode regardless of flags.
 *
 * @param string $channel Channel category (e.g., "DISPATCH", "AUTH", "DB", "CACHE", "BUNDLE")
 * @param mixed ...$values Values to output - strings are printed directly, other types are var_exported
 * @return void
 */
function console_debug(string $channel, ...$values)
{
    \App\RSpade\Core\Debug\Debugger::console_debug($channel, ...$values);
}


/**
 * Sanity check failure handler
 *
 * This function should be called when a sanity check fails - i.e., when the code
 * encounters a condition that "shouldn't happen" if everything is working correctly.
 * It throws a fatal exception with clear context about where the failure occurred.
 *
 * Use this instead of silently returning or continuing when encountering unexpected conditions.
 *
 * Examples:
 * - After loading files, if a class doesn't exist
 * - When a required file is missing
 * - When a database operation returns unexpected null
 * - When array keys that should exist are missing
 *
 * @param string|null $message Optional specific message about what shouldn't have happened
 * @throws \RuntimeException Always throws with location and context information
 */
function shouldnt_happen(?string $message = null): void
{
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = $backtrace[0] ?? [];

    $file = $caller['file'] ?? 'unknown';
    $line = $caller['line'] ?? 'unknown';

    // Make path relative to project root for cleaner output
    if (str_starts_with($file, base_path())) {
        $file = str_replace(base_path() . '/', '', $file);
    }

    $error_message = "Fatal: shouldnt_happen() was called at {$file}:{$line}\n";
    $error_message .= "This indicates a sanity check failed - the code is not behaving as expected.\n";

    if ($message) {
        $error_message .= "Details: {$message}\n";
    }

    $error_message .= 'Please thoroughly review the related code to determine why this error occurred.';

    throw new \RuntimeException($error_message);
}

/**
 * Recursively delete a directory and all its contents
 *
 * @param string $dir Directory path to delete
 * @param bool $delete_self Whether to delete the directory itself (default: true)
 * @param array $ignore_dirs Directories to skip
 * @return bool Success status
 */
function rmdir_recursive($dir, $delete_self = true, $ignore_dirs = [])
{
    if (!is_dir($dir)) {
        return false;
    }

    // Guardrail: refuse to recursively delete a sealed prod build asset from an
    // unauthorized context. Short-circuits instantly when not sealed.
    \App\RSpade\Core\Prod\Rsx_Prod_Seal::assert_mutable($dir, 'rmdir_recursive');

    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $path = $item->getPathname();

        // Skip ignored directories
        if (in_array($path, $ignore_dirs)) {
            continue;
        }

        if ($item->isFile() || $item->isLink()) {
            unlink($path);
        } elseif ($item->isDir() && !in_array($path, $ignore_dirs)) {
            @rmdir($path);
        }
    }

    if ($delete_self) {
        return @rmdir($dir);
    }

    return true;
}

/**
 * Extract a list of values from a collection of arrays/objects
 *
 * @param array|object $array Collection to pluck from
 * @param string|null $key Key to extract (null for first element)
 * @return array Array of plucked values
 */
function array_pluck($array, $key = null)
{
    $result = [];

    if (is_object($array)) {
        $array = (array) $array;
    }

    foreach ($array as $index => $item) {
        if (is_object($item)) {
            $item = (array) $item;
        }

        if ($key !== null) {
            if (isset($item[$key])) {
                $result[$index] = $item[$key];
            }
        } else {
            // Get first element
            $result[$index] = reset($item);
        }
    }

    return $result;
}

/**
 * Get only specified keys from an array
 *
 * @param array $array Source array
 * @param array $keys Keys to keep
 * @return array Filtered array
 */
function array_only($array, $keys)
{
    return array_intersect_key($array, array_flip($keys));
}

/**
 * Get all except specified keys from an array
 *
 * @param array $array Source array
 * @param array $keys Keys to exclude
 * @return array Filtered array
 */
function array_except($array, $keys)
{
    return array_diff_key($array, array_flip($keys));
}

/**
 * Recursively merge arrays, combining array values instead of replacing
 *
 * Ported from array_intersperse() in RSpade v3.
 *
 * This function performs a deep merge of two arrays. Unlike array_merge_recursive() which
 * creates sub-arrays for duplicate keys, this function intelligently merges values:
 * - For numeric keys: appends unique values only (no duplicates)
 * - For string keys with array values: recursively merges the arrays
 * - For string keys with scalar values: replaces the target value with source value
 *
 * Accepts arrays by reference for efficiency but creates and returns a new merged array.
 * Does not modify the input arrays.
 *
 * @param array &$target Base array (not modified)
 * @param array &$source Array to merge (not modified)
 * @return array The merged result array
 *
 * @example
 * $target = [
 *     'config' => ['debug' => true, 'env' => 'local'],
 *     'modules' => ['jquery', 'bootstrap'],
 *     'version' => '1.0'
 * ];
 * $source = [
 *     'config' => ['api_url' => '/api', 'env' => 'production'],
 *     'modules' => ['jquery', 'vue'],  // jquery won't be duplicated
 *     'version' => '2.0'
 * ];
 * $result = array_merge_deep($target, $source);
 * // Result in $result:
 * // [
 * //     'config' => ['debug' => true, 'env' => 'production', 'api_url' => '/api'],
 * //     'modules' => ['jquery', 'bootstrap', 'vue'],
 * //     'version' => '2.0'
 * // ]
 */
function array_merge_deep(array &$target, array &$source): array
{
    $result = $target;

    foreach ($source as $key => $value) {
        if (is_numeric($key)) {
            // Numeric key - append if not already present
            if (!in_array($value, $result, true)) {
                $result[] = $value;
            }
        } elseif (is_array($value)) {
            if (!isset($result[$key]) || !is_array($result[$key])) {
                $result[$key] = $value;
            } else {
                $result[$key] = array_merge_deep($result[$key], $value);
            }
        } else {
            $result[$key] = $value;
        }
    }

    return $result;
}

/**
 * Check if an array has string keys (is associative)
 *
 * @param array $array Array to check
 * @return bool True if has string keys
 */
function is_associative_array($array)
{
    if (!is_array($array) || empty($array)) {
        return false;
    }

    return count(array_filter(array_keys($array), 'is_string')) > 0;
}

/**
 * Recursively convert objects to arrays
 *
 * @param mixed $object Object or value to convert
 * @return mixed Converted value
 */
function object_to_array_recursive($object)
{
    if (is_object($object)) {
        // Check for to_array method
        if (method_exists($object, 'toArray')) {
            $object = $object->toArray();
        } elseif (method_exists($object, 'to_array')) {
            $object = $object->to_array();
        } else {
            $object = (array) $object;
        }
    }

    if (is_array($object)) {
        foreach ($object as $key => $value) {
            $object[$key] = object_to_array_recursive($value);
        }
    }

    return $object;
}

/**
 * Generate a cryptographically secure random hash
 *
 * @param int $bytes Number of random bytes (default: 32)
 * @return string Hexadecimal hash
 */
function random_hash($bytes = 32)
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Make a string safe for use as a variable/function name
 *
 * @param string $string Input string
 * @param int $max_length Maximum length (default: 64)
 * @return string Safe string
 */
function safe_string($string, $max_length = 64)
{
    // Replace non-alphanumeric with underscores
    $string = preg_replace('/[^a-zA-Z0-9_]+/', '_', $string);

    // Ensure first character is not a number
    if (empty($string) || is_numeric($string[0])) {
        $string = '_' . $string;
    }

    // Trim to max length
    return substr($string, 0, $max_length);
}

/**
 * Get file extension handling double extensions (e.g., .blade.php)
 *
 * @param string $path File path
 * @param bool $double Check for double extensions
 * @return string Extension
 */
function file_extension($path, $double = true)
{
    $filename = basename($path);

    if ($double) {
        // Check for known double extensions
        $double_extensions = ['blade.php', 'spec.js', 'test.js', 'min.js', 'min.css', 'd.ts'];

        foreach ($double_extensions as $ext) {
            if (str_ends_with($filename, '.' . $ext)) {
                return $ext;
            }
        }
    }

    return pathinfo($filename, PATHINFO_EXTENSION);
}

/**
 * Get a view instance by RSX ID (path-agnostic identifier).
 * This allows views to be referenced by their ID instead of file path.
 *
 * @param string $id The ID defined in the view file with @rsx_id directive
 * @param array $data Data to pass to the view
 * @param array $merge_data Data to merge with the view data
 * @return \Illuminate\View\View|\Illuminate\Contracts\View\Factory
 * @throws \Exception if the view is not found
 */
function rsx_view($id, $data = [], $merge_data = [])
{
    // Use manifest to find the view file by ID
    $file_path = \App\RSpade\Core\Manifest\Manifest::find_view($id);

    if (!$file_path) {
        throw new \InvalidArgumentException("RSX view not found: {$id}");
    }

    // Share the ID for use in layouts (for body class)
    \Illuminate\Support\Facades\View::share('rsx_view_id', $id);

    // Share the current view path for bundle validation
    \Illuminate\Support\Facades\View::share('rsx_current_view_path', $file_path);

    // In development mode, validate the view and layout chain
    if (!app()->environment('production')) {
        // Validate the entire layout chain and bundle placement
        \App\RSpade\Core\Validation\LayoutChainValidator::validate_layout_chain($id);

        // Get the metadata for the view
        $metadata = \App\RSpade\Core\Manifest\Manifest::get_file($file_path);

        // Validate the view doesn't have inline styles/scripts
        \App\RSpade\Core\Validation\ViewValidator::validate_view($id, $file_path, $metadata ?? []);

        // If the view extends a layout, validate each layout in the chain has body class
        if (isset($metadata['rsx_extends'])) {
            $current_extends = $metadata['rsx_extends'];
            $visited = [];  // Prevent infinite loops

            while ($current_extends) {
                if (in_array($current_extends, $visited)) {
                    break;  // Circular dependency, let LayoutChainValidator handle it
                }
                $visited[] = $current_extends;

                $layout_path = \App\RSpade\Core\Manifest\Manifest::find_view($current_extends);
                if ($layout_path) {
                    // Validate this layout
                    \App\RSpade\Core\Validation\ViewValidator::validate_layout($layout_path);

                    // Get metadata to find next parent
                    $layout_metadata = \App\RSpade\Core\Manifest\Manifest::get_file($layout_path);
                    $current_extends = $layout_metadata['rsx_extends'] ?? null;
                } else {
                    break;
                }
            }
        }
    }

    // Convert to Laravel view name format
    // Remove .blade.php extension
    $view_name = preg_replace('/\.blade\.php$/', '', $file_path);

    // Handle different view locations
    if (str_starts_with($view_name, 'resources/views/')) {
        $view_name = substr($view_name, strlen('resources/views/'));
    } elseif (str_starts_with($view_name, 'app/RSpade/')) {
        // For framework views, use namespace format
        // The namespace 'rspade' is registered in Rsx_Framework_Provider
        $view_name = 'rspade::' . substr($view_name, strlen('app/RSpade/'));
    } elseif (str_starts_with($view_name, 'rsx/')) {
        // For RSX views, use namespace format
        // The namespace 'rsx' is registered in RsxServiceProvider
        $view_name = str_replace('rsx/', 'rsx::', $view_name);
    }

    // Convert path separators to dots for Laravel
    $view_name = str_replace('/', '.', $view_name);

    return view($view_name, $data, $merge_data);
}

/**
 * Get the Laravel view path for a view by RSX ID.
 * Used internally by RSX Blade directives to resolve view paths.
 *
 * @param string $id The ID to look up
 * @return string|null The Laravel view path in dot notation or null if not found
 */
function rsx_view_path($id)
{
    // Remove quotes if passed from Blade directive
    $id = trim($id, "'\"");

    // Use manifest to find the view file
    $file_path = \App\RSpade\Core\Manifest\Manifest::find_view($id);

    if (!$file_path) {
        return null;
    }

    // Convert to Laravel view path format
    // Remove .blade.php extension
    $view_path = preg_replace('/\.blade\.php$/', '', $file_path);

    // Handle different view locations
    if (str_starts_with($view_path, 'resources/views/')) {
        $view_path = substr($view_path, strlen('resources/views/'));
    } elseif (str_starts_with($view_path, 'rsx/')) {
        // For RSX views, convert to namespace format
        $view_path = str_replace('rsx/', 'rsx::', $view_path);
    }

    // Convert path separators to dots
    return str_replace('/', '.', $view_path);
}

/**
 * Create directory if it doesn't exist
 *
 * @param string $path Directory path
 * @param int $permissions Directory permissions
 * @return bool Success status
 */
function ensure_directory($path, $permissions = 0755)
{
    if (!is_dir($path)) {
        return mkdir($path, $permissions, true);
    }

    return true;
}

/**
 * Internal: determine whether two paths reside on the same filesystem.
 *
 * Compares the device IDs reported by stat(). rename(2) is only atomic within a
 * single filesystem, so file_put_contents_safe() uses this to decide where to
 * stage its temp file. If either path cannot be stat'd, returns true (assume
 * same filesystem) so the caller falls through to its default staging location.
 *
 * @param string $path_a
 * @param string $path_b
 * @return bool
 */
function _paths_on_same_filesystem($path_a, $path_b)
{
    $a = @stat($path_a);
    $b = @stat($path_b);

    if ($a === false || $b === false) {
        return true;
    }

    return $a['dev'] === $b['dev'];
}

/**
 * Atomically write $content to $file.
 *
 * Drop-in replacement for the two-argument form of file_put_contents() that
 * prevents concurrent readers from ever observing a partially written file.
 * The content is written in full to a uniquely named temp file, then renamed
 * over the destination. rename(2) is atomic within a filesystem, so a reader
 * sees either the complete old file or the complete new file - never a
 * truncation.
 *
 * The temp file is staged under storage/rsx-tmp (the temp directory wiped by
 * rsx:clean) when that lives on the same filesystem as the destination. When it
 * does NOT - rename across devices is non-atomic - the temp file is instead
 * staged in a throwaway ".tmp_<10 digits>" directory created alongside the
 * destination (same filesystem, so the rename stays atomic), which is removed
 * recursively afterward. rsx:clean also sweeps any orphaned .tmp_* directories,
 * and the pattern is gitignored.
 *
 * Existing destination permissions are preserved across the replace. Returns
 * the number of bytes written on success, or false on failure - the same
 * contract as file_put_contents(). For full-file writes only; this is NOT a
 * replacement for FILE_APPEND writes.
 *
 * @param string $file Destination path
 * @param string $content Content to write
 * @return int|false Bytes written, or false on failure
 */
function file_put_contents_safe($file, $content)
{
    // Guardrail: refuse to overwrite a sealed prod build asset from an
    // unauthorized context. Short-circuits instantly when not sealed.
    \App\RSpade\Core\Prod\Rsx_Prod_Seal::assert_mutable($file, 'file_put_contents_safe');

    // WRITE THROUGH SYMLINKS: rename(2) over a symlink would REPLACE the link
    // itself with a regular file, silently destroying it. That is never what a
    // content write means - callers writing to system/.env (a healed symlink to
    // the project-root .env) expect the TARGET's content to change and the link
    // to survive (POSIX write-through semantics). Resolve to the final target
    // (chains capped) and stage the rename there instead.
    $link_depth = 0;
    while (is_link($file) && $link_depth < 10) {
        $target = readlink($file);
        if ($target === false) {
            break;
        }
        // A relative link target resolves against the link's own directory.
        if ($target[0] !== '/') {
            $target = dirname($file) . '/' . $target;
        }
        $normalized = rsxrealpath($target);
        $file = (is_string($normalized) && $normalized !== '') ? $normalized : $target;
        $link_depth++;
    }

    $dest_dir = dirname($file);

    // Prefer staging in rsx-tmp (wiped by rsx:clean). If it is on a different
    // filesystem than the destination, rename(2) would be non-atomic - so stage
    // in a throwaway .tmp_<n> directory alongside the destination instead, which
    // is removed recursively once we are done.
    $rsx_tmp = storage_path('rsx-tmp');
    ensure_directory($rsx_tmp);

    $stage_dir = $rsx_tmp;
    $cleanup_dir = null;

    if (!_paths_on_same_filesystem($rsx_tmp, $dest_dir)) {
        $cleanup_dir = $dest_dir . '/.tmp_' . random_int(1000000000, 9999999999);
        ensure_directory($cleanup_dir);
        $stage_dir = $cleanup_dir;
    }

    $temp_file = $stage_dir . '/fpcs_' . random_hash(16) . '.tmp';

    // Write the full content to the temp file first.
    $bytes = file_put_contents($temp_file, $content);
    if ($bytes === false) {
        @unlink($temp_file);
        if ($cleanup_dir !== null) {
            rmdir_recursive($cleanup_dir);
        }

        return false;
    }

    // Preserve the destination's existing permissions across the replace.
    if (file_exists($file)) {
        @chmod($temp_file, fileperms($file) & 0777);
    }

    // Atomically move the temp file over the destination.
    $renamed = @rename($temp_file, $file);

    // On failure, remove the temp file and leave any existing destination
    // untouched. Either way, remove the throwaway staging directory recursively.
    if (!$renamed) {
        @unlink($temp_file);
    }
    if ($cleanup_dir !== null) {
        rmdir_recursive($cleanup_dir);
    }

    // Record framework-authored writes to owned-zone files so the tamper check
    // can subtract legitimate churn by construction. The recorder does its own
    // fast owned-zone guard first (a string prefix on the already-resolved $file),
    // so bundle/manifest/storage writes pay only a no-op call. $file here is the
    // final symlink-resolved target - the correct path to record against.
    if ($renamed) {
        \App\RSpade\Core\Framework\Framework_Mutations::record_write($file, 'file_write');
    }

    return $renamed ? $bytes : false;
}

/**
 * Get relative path from base path
 *
 * Project-logical paths (manifest keys, framework path constants) are base_path()-relative
 * with ONE exception: volatile storage was relocated out of the framework tree to
 * <project>/storage, yet its entries keep the historic 'storage/...' spelling so manifest
 * keys, prod build hashing and every str_starts_with('storage/') classifier stay stable.
 * rsx_project_file_path() is the inverse of this function.
 *
 * @param string $path Full path
 * @param string|null $base Base path (defaults to base_path())
 * @return string Relative path
 */
function relative_path($path, $base = null)
{
    if ($base === null) {
        $base = base_path();

        $storage_root = rtrim(storage_path(), '/') . '/';
        if (str_starts_with($path, $storage_root)) {
            return 'storage/' . substr($path, strlen($storage_root));
        }
    }

    $base = rtrim($base, '/') . '/';

    if (str_starts_with($path, $base)) {
        return substr($path, strlen($base));
    }

    return $path;
}

/**
 * Resolve a project-logical relative path to an absolute filesystem path.
 *
 * The inverse of relative_path(): 'storage/...' resolves against the RELOCATED storage
 * root (storage_path()), everything else against base_path(). Use this - never bare
 * base_path() - for anything under storage/: build artifacts, generated js-stubs, RPC
 * sockets, parser caches. Before the relocation both spellings resolved identically, so
 * this is also correct on a not-yet-migrated environment.
 *
 * @param string $relative_path Project-logical relative path
 * @return string Absolute path
 */
function rsx_project_file_path(string $relative_path): string
{
    if (str_starts_with($relative_path, 'storage/')) {
        return storage_path(substr($relative_path, strlen('storage/')));
    }

    return base_path($relative_path);
}

/**
 * Copy directory recursively
 *
 * @param string $source Source directory
 * @param string $destination Destination directory
 * @param array $ignore Patterns to ignore
 * @return bool Success status
 */
function copy_directory($source, $destination, $ignore = [])
{
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $source_path = $item->getPathname();
        $relative = relative_path($source_path, $source);
        $dest_path = $destination . '/' . $relative;

        // Check ignore patterns
        foreach ($ignore as $pattern) {
            if (fnmatch($pattern, $relative)) {
                continue 2;
            }
        }

        if ($item->isDir()) {
            if (!is_dir($dest_path)) {
                mkdir($dest_path, 0755, true);
            }
        } else {
            copy($source_path, $dest_path);
        }
    }

    return true;
}

/**
 * Recursively sort array by keys
 *
 * @param array $array Array to sort (by reference)
 * @param int $sort_flags Sort flags
 * @return bool Success status
 */
function ksort_recursive(&$array, $sort_flags = SORT_REGULAR)
{
    if (!is_array($array)) {
        return false;
    }

    if (is_associative_array($array)) {
        ksort($array, $sort_flags);
    } else {
        sort($array, $sort_flags);
    }

    foreach ($array as &$value) {
        if (is_array($value)) {
            ksort_recursive($value, $sort_flags);
        }
    }

    return true;
}

/**
 * Convert snake_case to camelCase
 *
 * @param string $string Snake case string
 * @param bool $capitalize_first Whether to capitalize first letter
 * @return string Camel case string
 */
function snake_to_camel($string, $capitalize_first = false)
{
    $result = str_replace('_', '', ucwords($string, '_'));

    if (!$capitalize_first) {
        $result = lcfirst($result);
    }

    return $result;
}

/**
 * Convert camelCase to snake_case
 *
 * @param string $string Camel case string
 * @return string Snake case string
 */
function camel_to_snake($string)
{
    return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($string)));
}

/**
 * Check if a value is "blank" (null, empty string, empty array, etc.)
 *
 * @param mixed $value Value to check
 * @return bool True if blank
 */
function is_blank($value)
{
    if (is_null($value)) {
        return true;
    }

    if (is_string($value)) {
        return trim($value) === '';
    }

    if (is_numeric($value) || is_bool($value)) {
        return false;
    }

    if ($value instanceof \Countable) {
        return count($value) === 0;
    }

    return empty($value);
}

/**
 * Get nested array value using "dot" notation
 *
 * @param array $array Source array
 * @param string $key Dot notation key
 * @param mixed $default Default value if not found
 * @return mixed Value or default
 */
function array_get($array, $key, $default = null)
{
    if (is_null($key)) {
        return $array;
    }

    if (isset($array[$key])) {
        return $array[$key];
    }

    foreach (explode('.', $key) as $segment) {
        if (!is_array($array) || !array_key_exists($segment, $array)) {
            return $default;
        }

        $array = $array[$segment];
    }

    return $array;
}

/**
 * Set nested array value using "dot" notation
 *
 * @param array $array Target [by reference]
 * @param string $key Dot notation key
 * @param mixed $value Value to set
 * @return void
 */
function array_set(&$array, $key, $value)
{
    if (is_null($key)) {
        $array = $value;

        return;
    }

    $keys = explode('.', $key);

    while (count($keys) > 1) {
        $key = array_shift($keys);

        if (!isset($array[$key]) || !is_array($array[$key])) {
            $array[$key] = [];
        }

        $array = &$array[$key];
    }

    $array[array_shift($keys)] = $value;
}

/**
 * Flatten a multi-dimensional array into a single level
 *
 * @param array $array Array to flatten
 * @param int $depth Maximum depth to flatten
 * @return array Flattened array
 */
function array_flatten($array, $depth = INF)
{
    $result = [];

    foreach ($array as $item) {
        if (!is_array($item)) {
            $result[] = $item;
        } elseif ($depth === 1) {
            $result = array_merge($result, array_values($item));
        } else {
            $result = array_merge($result, array_flatten($item, $depth - 1));
        }
    }

    return $result;
}

/**
 * Get the first element of an array that matches a condition
 *
 * @param array $array Source array
 * @param callable|null $callback Optional filter callback
 * @param mixed $default Default value if not found
 * @return mixed First element or default
 */
function array_first($array, $callback = null, $default = null)
{
    if (is_null($callback)) {
        if (empty($array)) {
            return $default;
        }

        foreach ($array as $item) {
            return $item;
        }
    }

    foreach ($array as $key => $value) {
        if ($callback($value, $key)) {
            return $value;
        }
    }

    return $default;
}

/**
 * Get the last element of an array that matches a condition
 *
 * @param array $array Source array
 * @param callable|null $callback Optional filter callback
 * @param mixed $default Default value if not found
 * @return mixed Last element or default
 */
function array_last($array, $callback = null, $default = null)
{
    if (is_null($callback)) {
        return empty($array) ? $default : end($array);
    }

    return array_first(array_reverse($array, true), $callback, $default);
}

/**
 * Create a temporary directory
 *
 * @param string $prefix Directory prefix
 * @param string $base Base directory (defaults to sys temp)
 * @return string|false Path to created directory or false on failure
 */
function make_temp_directory($prefix = 'rsx_', $base = null)
{
    if ($base === null) {
        $base = sys_get_temp_dir();
    }

    $attempts = 10;
    while ($attempts-- > 0) {
        $path = $base . '/' . $prefix . random_hash(8);
        if (mkdir($path, 0755)) {
            return $path;
        }
    }

    return false;
}

/**
 * Get the current site or a specific attribute from the current site.
 *
 * @param string|null $key
 * @return Site|mixed|null
 */
// Not yet implemented
// function site($key = null)
// {
//     if (is_null($key)) {
//         return \App\RSpade\Core\Session\Session::get_site();
//     }
//
//     $current_site = \App\RSpade\Core\Session\Session::get_site();
//     return $current_site?->getAttribute($key);
// }

/**
 * Generate a URL for a specific site.
 * This function allows generating cross-site URLs by temporarily switching context.
 *
 * @param int $site_id
 * @param string $route
 * @param array $parameters
 * @param bool $absolute
 * @return string
 */
// Not yet implemented
// function site_route($site_id, $route, $parameters = [], $absolute = true)
// {
//     $original_site_id = \App\RSpade\Core\Session\Session::get_site_id();
//
//     try {
//         // Temporarily switch to target site context
//         \App\RSpade\Core\Session\Session::set_site_id($site_id);
//
//         // Generate the route with the target site context
//         $url = route($route, $parameters, $absolute);
//
//         return $url;
//
//     } finally {
//         // Always restore original site context
//         \App\RSpade\Core\Session\Session::set_site_id($original_site_id);
//     }
// }

/**
 * Execute a shell command with pretty console output formatting.
 * Shows the command in gray with a carat prefix, then the output in default color.
 *
 * @param string $command The shell command to execute
 * @param bool $real_time Whether to show output in real-time (true) or after completion (false)
 * @param bool $throw_on_error Whether to throw an exception on non-zero exit code
 * @return array Returns array with 'output', 'error', and 'exit_code'
 */
function shell_exec_pretty($command, $real_time = true, $throw_on_error = false)
{
    // ANSI color codes
    $gray = "\033[90m";
    $reset = "\033[0m";
    $red = "\033[31m";

    // Display the command being run
    echo $gray . '> ' . $command . $reset . PHP_EOL;

    if ($real_time) {
        // Use passthru() for real-time output without proc_open() pipe buffer issues
        // Redirect to temp file to capture output for return value
        $temp_file = storage_path('rsx-tmp/shell_exec_pretty_' . uniqid() . '.txt');

        // Use script command wrapper to show real-time output AND capture to file
        // passthru() shows output but doesn't capture it, so we use tee to do both
        $full_command = "($command 2>&1) | tee " . escapeshellarg($temp_file);

        // passthru() displays output in real-time and returns the exit code via $exit_code.
        // Explicit bash: passthru() otherwise runs /bin/sh (dash here) - project policy.
        passthru('bash -c ' . escapeshellarg($full_command), $exit_code);

        // Read captured output from file
        $output = '';
        $error = '';
        if (file_exists($temp_file)) {
            $output = file_get_contents($temp_file);
            unlink($temp_file);  // Clean up
        }
    } else {
        // Use shell_exec for simple execution
        $full_command = $command . ' 2>&1';
        $output = shell_exec('bash -c ' . escapeshellarg($full_command));
        $exit_code = 0; // shell_exec doesn't provide exit codes
        $error = '';

        // Display output
        if ($output !== null) {
            echo $output;
        }
    }

    // Check exit code
    if ($exit_code !== 0 && $throw_on_error) {
        $error_msg = "Command failed with exit code $exit_code: $command";
        if ($error) {
            $error_msg .= "\nError output: $error";
        }

        throw new RuntimeException($error_msg);
    }

    return [
        'output' => trim($output),
        'error' => trim($error),
        'exit_code' => $exit_code,
    ];
}

/**
 * Check if a command exists in the system PATH.
 *
 * @param string $command
 * @return bool
 */
function command_exists($command)
{
    $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
    $result = shell_exec('bash -c ' . escapeshellarg("$which $command 2>&1"));

    return !empty($result) && !str_contains($result, 'not found') && !str_contains($result, 'Could not find');
}

/**
 * THE sanctioned framework subprocess wrapper (owner ruling 2026-08-05).
 *
 * Every place in the framework that needs to run a shell command goes through
 * \exec_safe(). It is the ONE wrapper - application and framework code must not
 * call exec() (banned by PHP-EXEC-01) or proc_open() (banned by PHP-PROC-01)
 * directly. One way to do things: the exit-status channel, the output contract
 * and the environment channel are implemented once, here, correctly.
 *
 * WHY proc_open() IS USED HERE (and only here):
 * PHP-PROC-01 exists because of pipe-buffer truncation caused by hand-rolled
 * feof()/fread() drain loops, and because two concurrently-drained pipes
 * (stdout + stderr) deadlock when either fills. Neither hazard applies to this
 * implementation:
 *   - stderr is merged into stdout by the shell ('2>&1'), so there is exactly
 *     ONE pipe. A single pipe cannot deadlock against a sibling.
 *   - the pipe is drained with a single blocking stream_get_contents() that
 *     reads to EOF. There is no feof() race to lose the tail of the output.
 *   - the pipe is read to completion BEFORE proc_close(), so the child is never
 *     blocked on a full pipe while we wait for it to exit.
 * In exchange proc_close() hands back the REAL exit status of the child, which
 * removes the "parse the exit code out of the text output" seam that the old
 * shell_exec() + "echo $?" implementation depended on (and the fabricated
 * success it produced when shell_exec() returned null).
 *
 * The command string keeps full shell semantics (pipes, redirects, &&, cd) -
 * callers pass shell command lines and always have. The command is wrapped in a
 * subshell group exactly as before, so grouping behaviour is unchanged.
 *
 * THE SHELL IS BASH, EXPLICITLY (project policy). proc_open() with a STRING would
 * run /bin/sh, which is dash on our platforms, and dash's POSIX-minimal parsing has
 * bitten us: POSIX guarantees only single-digit fds in a redirection, so dash reads
 * `exec 11>&-` (emitted by the lock-fd-close wrappers) as a command named 11 and the
 * spawn dies with `exec: 11: not found`. The ARRAY form of proc_open() execs bash
 * directly, so no /bin/sh is involved anywhere in this function.
 *
 * Usage:
 *     \exec_safe('git status 2>&1', $output, $return_var);
 *
 * DETACHING A BACKGROUND PROCESS: a child put in the background inherits the output
 * pipe, so this function keeps reading until that child also exits. Redirect the
 * background child's output away ('nohup thing > /dev/null 2>&1 & echo $!') and the
 * call returns immediately, which is what a daemon launcher wants anyway.
 *
 * Credentials: pass secrets through $env, never on the command line. Anything in
 * the command string is visible to every user on the box via `ps`; the
 * environment of a process is not. Example:
 *     \exec_safe('mysql -u' . escapeshellarg($user) . ' -e "SELECT 1"',
 *                $out, $rc, ['MYSQL_PWD' => $password]);
 *
 * @PHP-PROC-01-EXCEPTION - blessed framework wrapper, owner ruling 2026-08-05. This is the single
 * sanctioned proc_open() site in the codebase; the truncation and deadlock hazards the rule guards
 * against are structurally absent here (one merged pipe, one blocking read to EOF, drained before
 * proc_close()). The ban stays fully in force everywhere else - route new subprocess work through
 * this function instead of asking for another exception. NOTE: the checker matches this annotation
 * against the whole file, so it suppresses PHP-PROC-01 for all of helpers.php.
 *
 * @param string $command Command to execute (shell syntax allowed)
 * @param array &$output Output lines (populated by reference like exec())
 * @param int &$return_var Return code (populated by reference like exec()); -1 when the process could not be started
 * @param array $env Extra environment variables merged OVER the current process environment. Empty = inherit unchanged.
 * @return string|false Last line of output, or false when the process could not be started
 */
function exec_safe(string $command, array &$output = [], int &$return_var = 0, array $env = []): string|false
{
    // Subshell group + merged stderr. The group preserves the exact grouping the
    // callers were written against; the merge gives us a single pipe to drain.
    //
    // The spaces inside the parens are load-bearing under bash: '((' opens an ARITHMETIC
    // evaluation, so a command that itself starts with a subshell ('(exit 3)') would be
    // parsed as arithmetic and report the wrong exit status. A space makes it two grouping
    // parens with no change to what the group means.
    $full_command = '( ' . $command . ' ) 2>&1';

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],  // no stdin - a command that reads it gets EOF, never a hang
        1 => ['pipe', 'w'],               // stdout, with stderr merged into it by the shell
    ];

    // getenv() with no arguments is the reliable full environment: $_ENV depends
    // on variables_order and is commonly empty on CLI.
    $process_env = empty($env) ? null : array_merge(getenv(), $env);

    $pipes = [];

    // Array form: bash is exec'd directly, so /bin/sh (dash) never sees the command.
    $process = proc_open(['bash', '-c', $full_command], $descriptors, $pipes, null, $process_env);

    if (!is_resource($process)) {
        // Could not fork/exec. Fail loud - never report success we did not observe.
        $return_var = -1;
        $output = [];
        return false;
    }

    // Single blocking read to EOF, completed before proc_close().
    $combined = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    // Real exit status straight from the OS.
    $return_var = proc_close($process);

    if ($combined === false) {
        $return_var = -1;
        $output = [];
        return false;
    }

    // Split into lines like exec() does
    $output = $combined ? explode("\n", trim($combined)) : [];

    // Return last line like exec() does
    return empty($output) ? '' : end($output);
}

/**
 * Debug dump and die - Enhanced var_dump/die() replacement
 *
 * Outputs debug information with file/line location, values, and stack trace.
 * This is the preferred method for temporary debugging output.
 *
 * @param mixed ...$values Any number of values to debug
 * @return void (never returns - calls die())
 */
function rsx_dump_die(...$values)
{
    \App\RSpade\Core\Debug\Debugger::rsx_dump_die(...$values);
}

/**
 * Get the RSX ID of the current view for use as a body class
 *
 * This is used in layout files to add the view's RSX ID as a CSS class
 * to the body tag, enabling view-specific styling.
 *
 * @return string The RSX ID or empty string if not set
 */
function rsx_body_class()
{
    return \Illuminate\Support\Facades\View::shared('rsx_view_id', '');
}

/**
 * Create a unified error response
 *
 * @param string $error_code One of Ajax::ERROR_* constants
 * @param string|array|null $metadata Error message (string) or structured data (array)
 * @return \App\RSpade\Core\Response\Error_Response
 */
function response_error(string $error_code, $metadata = null)
{
    return new \App\RSpade\Core\Response\Error_Response($error_code, $metadata);
}

/**
 * Create an unauthorized error response
 *
 * Context-aware response (channel split resolved by the dispatcher, B-31):
 * - Ajax/API requests: Returns JSON {success: false, error_code: 'unauthorized'}
 * - Web requests (not logged in): 302 redirect to the login route, threading the
 *   originally requested URL via Login_Redirect (Dispatcher::__redirect_to_login)
 * - Web requests (logged in): 403 (authenticated but lacking permission)
 *
 * Use this when user is authenticated but lacks permission for an action,
 * OR when user is not authenticated and needs to be.
 *
 * @param string|null $message Custom error message (optional)
 * @return \App\RSpade\Core\Response\Error_Response
 */
function response_unauthorized(?string $message = null)
{
    return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED, $message);
}

/**
 * Create a not found error response
 *
 * Context-aware response:
 * - Ajax requests: Returns JSON {success: false, error_code: 'not_found'}
 * - Web requests: Renders 404 error page or throws HttpException
 *
 * Use this when a requested resource does not exist.
 *
 * @param string|null $message Custom error message (optional)
 * @return \App\RSpade\Core\Response\Error_Response
 */
function response_not_found(?string $message = null)
{
    return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, $message);
}

/**
 * Create a form validation error response
 *
 * Use this for validation errors with field-specific messages.
 * The client-side form handling will apply errors to matching fields.
 *
 * @param string $message Summary message for the error
 * @param array $field_errors Field-specific errors as ['field_name' => 'error message']
 * @return \App\RSpade\Core\Response\Error_Response
 */
function response_form_error(string $message, array $field_errors = [])
{
    return response_error(
        \App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION,
        array_merge(['_message' => $message], $field_errors)
    );
}

/**
 * Create an authentication required error response
 *
 * Use this when the user is not logged in and needs to authenticate.
 * Distinct from response_unauthorized() which is for permission denied.
 *
 * @param string|null $message Custom error message (optional)
 * @return \App\RSpade\Core\Response\Error_Response
 */
function response_auth_required(?string $message = null)
{
    return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_AUTH_REQUIRED, $message);
}

/**
 * Create a fatal error response
 *
 * Use this for unrecoverable errors that prevent the operation from completing.
 * These are typically logged and displayed prominently to the user.
 *
 * @param string|null $message Error message
 * @param array $details Additional error details (e.g., file, line, backtrace)
 * @return \App\RSpade\Core\Response\Error_Response
 */
function response_fatal_error(?string $message = null, array $details = [])
{
    $metadata = $details;
    if ($message !== null) {
        $metadata['_message'] = $message;
    }
    return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_FATAL, $metadata ?: $message);
}

/**
 * Check if the current request is from a loopback IP address
 *
 * Returns true only if:
 * - Request is from localhost, 127.0.0.1, or ::1 (IPv6 loopback)
 * - No proxy headers (X-Real-IP, X-Forwarded-For) are present
 *
 * Used to ensure Playwright test headers can only be used from local connections.
 *
 * @return bool True if request is from loopback without proxy headers
 */
function is_loopback_ip(): bool
{
    $request = request();
    if (!$request) {
        return false;
    }

    // Check for proxy headers - if present, not a direct loopback connection
    if ($request->hasHeader('X-Real-IP') ||
        $request->hasHeader('X-Forwarded-For') ||
        $request->hasHeader('X-Forwarded-Host') ||
        $request->hasHeader('X-Forwarded-Proto')) {
        return false;
    }

    // Get the client IP
    $ip = $request->ip();

    // Check for loopback addresses
    // IPv4 loopback: 127.0.0.1
    // IPv6 loopback: ::1
    // Hostname: localhost
    $loopback_addresses = [
        '127.0.0.1',
        '::1',
        'localhost',
    ];

    return in_array($ip, $loopback_addresses, true);
}

/**
 * Sanitize HTML from WYSIWYG editors to prevent XSS attacks
 *
 * Uses HTMLPurifier to filter potentially malicious HTML while preserving
 * safe formatting tags. Suitable for user-generated rich text content.
 *
 * @param string $html The HTML string to sanitize
 * @return string Sanitized HTML safe for display
 */
function safe_html(string $html): string
{
    static $purifier = null;

    if ($purifier === null) {
        require_once base_path('vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php');

        $config = HTMLPurifier_Config::createDefault();

        // Cache serialized definitions for performance
        $cache_dir = storage_path('rsx-tmp/htmlpurifier');
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }
        $config->set('Cache.SerializerPath', $cache_dir);
        $config->set('Cache.SerializerPermissions', null);  // Disable chmod (Docker compatibility)

        // Allow common formatting elements
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,strike,a[href|title|target],ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,pre,code,img[src|alt|title|width|height],table,thead,tbody,tr,th,td,div,span');

        // Allow class attributes for styling
        $config->set('Attr.AllowedClasses', null); // Allow all classes

        // Link handling
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        $purifier = new HTMLPurifier($config);
    }

    return $purifier->purify($html);
}

/**
 * Generate a hash for a file suitable for build/cache invalidation.
 *
 * Strategy is selected by RSX_MODE (via Rsx::is_production()), NOT by APP_ENV:
 *
 * - Development mode: a fast hash of the ABSOLUTE path + size + mtime. This is a
 *   local JIT fast path - it never leaves the machine and only needs to notice
 *   that a file on this disk changed.
 *
 * - Production/debug mode: a content-based hash of the PROJECT-RELATIVE path +
 *   full file contents. This is the DETERMINISM CONTRACT for sealed prod builds:
 *   the resulting hash depends ONLY on where the file sits relative to the
 *   project root and on its bytes - never on the absolute checkout location or
 *   on disk timestamps. Two byte-identical codebases checked out at different
 *   absolute paths therefore produce IDENTICAL hashes, which is what makes the
 *   downstream build_key (and every bundle filename derived from it)
 *   cluster-consistent.
 *
 * @param string $file_path The absolute path to the file
 * @return string A hash string representing the file state
 */
function _rsx_file_hash_for_build($file_path)
{
    if (!file_exists($file_path)) {
        shouldnt_happen("File does not exist for hashing: {$file_path}");
    }

    // Branch on RSX_MODE, not APP_ENV: a prod/debug build must be deterministic
    // regardless of the Laravel environment string.
    if (\App\RSpade\Core\Rsx::is_production()) {
        return _rsx_file_hash_content_based($file_path);
    }

    return _rsx_file_hash_fast($file_path);
}

/**
 * Development fast path: metadata-only hash (absolute path + size + mtime).
 *
 * Intentionally NON-deterministic across machines/checkouts - it exists purely so
 * local JIT rebuilds notice a changed file cheaply without reading its contents.
 *
 * @param string $file_path Absolute path to the file
 * @return string
 */
function _rsx_file_hash_fast(string $file_path): string
{
    return md5(json_encode([
        'path' => $file_path,
        'size' => filesize($file_path),
        'mtime' => filemtime($file_path),
    ]));
}

/**
 * Production/debug content path: deterministic, checkout-location-independent hash.
 *
 * The hash is derived from the PROJECT-RELATIVE path plus the file contents, so it
 * is identical for the same logical file in two different absolute checkouts.
 *
 * @param string $file_path Absolute path to the file
 * @return string
 */
function _rsx_file_hash_content_based(string $file_path): string
{
    return _rsx_content_hash(
        _rsx_relative_build_path($file_path),
        file_get_contents($file_path)
    );
}

/**
 * Pure core of the content-based build hash: hash a (relative path, content) pair.
 *
 * Extracted so tests can prove determinism directly - identical (relative path,
 * content) inputs always yield an identical hash, independent of any absolute
 * staging location the bytes happened to be read from.
 *
 * @param string $relative_path Project-relative path
 * @param string $content File contents
 * @return string
 */
function _rsx_content_hash(string $relative_path, string $content): string
{
    return hash('sha512', json_encode([
        'path' => $relative_path,
        'content' => $content,
    ]));
}

/**
 * Reduce an absolute source path to a project-relative, checkout-independent path.
 *
 * A source file under /rsx can be reached two ways in framework code:
 *   - via the base_path()/rsx symlink  -> base_path() . '/rsx/...'
 *   - via the real project-root mount  -> dirname(base_path()) . '/rsx/...'
 * Both must map to the SAME relative string ("rsx/...") or the build key would
 * depend on which mount a file was reached through. We therefore try base_path()
 * first, then the project root (dirname(base_path())). This is purely string
 * based (no realpath / symlink resolution), so it never leaks a shared absolute
 * location into the hash. A string not under either base is returned unchanged.
 *
 * @param string $path Absolute (or already-relative) path
 * @return string Project-relative path when resolvable, else the input unchanged
 */
function _rsx_relative_build_path(string $path): string
{
    $relative = relative_path($path, base_path());
    if ($relative === $path) {
        // Not under base_path() - try the project root so the real /rsx mount and
        // the base_path()/rsx symlink converge on the same relative string.
        $relative = relative_path($path, dirname(base_path()));
    }

    return $relative;
}

/**
 * Convert text to HTML preserving whitespace and indentation
 *
 * Converts plain text to HTML that displays with proper formatting:
 * - HTML special characters are escaped
 * - Leading spaces on each line are converted to &nbsp;
 * - Newlines are converted to <br> tags
 * - Trailing whitespace on lines is trimmed
 *
 * This is useful for displaying source code or formatted text in HTML
 * where you want to preserve the indentation and line breaks.
 *
 * @param string $text The plain text to convert
 * @return string HTML-formatted text
 */
function text_to_html_with_whitespace(string $text): string
{
    // First, escape HTML special characters to prevent XSS
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Split into lines
    $lines = explode("\n", $text);

    // Process each line
    $processed_lines = [];
    foreach ($lines as $line) {
        // Trim trailing whitespace only (preserve leading spaces)
        $line = rtrim($line);

        // Count leading spaces
        $leading_spaces = strlen($line) - strlen(ltrim($line));

        if ($leading_spaces > 0) {
            // Replace leading spaces with &nbsp;
            $spaces_html = str_repeat('&nbsp;', $leading_spaces);
            $line = $spaces_html . substr($line, $leading_spaces);
        }

        $processed_lines[] = $line;
    }

    // Join lines with <br>\n
    return implode("<br>\n", $processed_lines);
}

/**
 * Normalize a path resolving . and .. components without resolving symlinks
 *
 * Unlike PHP's realpath(), this function normalizes paths by resolving . and ..
 * components but does NOT follow symlinks. This is important when working with
 * symlinked directories where you need the logical path, not the physical path.
 *
 * Behavior:
 * - Resolves . (current directory) and .. (parent directory) components
 * - Does NOT resolve symlinks (unlike realpath())
 * - Converts relative paths to absolute by prepending base_path()
 * - Returns normalized absolute path, or false if file doesn't exist
 *
 * Examples:
 * - rsxrealpath('/var/www/html/system/rsx/foo/../bar')
 *   => '/var/www/html/system/rsx/bar'
 *
 * - rsxrealpath('rsx/foo/../bar')
 *   => '/var/www/html/rsx/foo/../bar' (after base_path prepend)
 *   => '/var/www/html/rsx/bar'
 *
 * - If /var/www/html/system/rsx is a symlink to /var/www/html/rsx:
 *   rsxrealpath('/var/www/html/system/rsx/bar')
 *   => '/var/www/html/system/rsx/bar' (keeps symlink path, unlike realpath)
 *
 * @param string $path The path to normalize
 * @return string|false The normalized absolute path, or false if file doesn't exist
 */
function rsxrealpath(string $path): string|false
{
    // Convert relative path to absolute
    if (!str_starts_with($path, '/')) {
        $path = base_path() . '/' . $path;
    }

    // Split path into components
    $parts = explode('/', $path);
    $result = [];

    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            // Skip empty parts and current directory references
            continue;
        } elseif ($part === '..') {
            // Go up one directory (remove last component)
            if (!empty($result)) {
                array_pop($result);
            }
        } else {
            // Regular path component
            $result[] = $part;
        }
    }

    // Rebuild path with leading slash
    $normalized = '/' . implode('/', $result);

    // Check if path exists (like realpath does)
    if (!file_exists($normalized)) {
        return false;
    }

    return $normalized;
}

/**
 * Convert bytes to human-readable format
 *
 * Converts a number of bytes into a human-readable string with appropriate unit suffix.
 *
 * @param int $bytes The number of bytes
 * @param int $precision Number of decimal places (default: 2)
 * @return string Formatted string (e.g., "1.5 MB", "342 B")
 */
function bytes_to_human($bytes, $precision = 2)
{
    if (!is_numeric($bytes)) {
        return "---";
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * A PHP ini size value, in BYTES.
 *
 * ini_get() returns PHP's shorthand notation verbatim - "8M", "1250M", "1G" - which is a
 * STRING, not a number. Comparing it to a byte count without conversion silently compares
 * "1250M" to an integer and produces confident nonsense, which is exactly how a size limit
 * ends up reporting the wrong thing. There is one parser and this is it.
 *
 * "-1" (unlimited) and "" both return 0, meaning "no ceiling here" - the caller decides what
 * that implies, because "unlimited" and "unset" are the same answer to "what is the limit?"
 * and neither one is a number you can compare against.
 *
 * @param string|false|null $value Raw ini value, e.g. ini_get('post_max_size')
 * @return int Bytes, or 0 for unlimited/unset/unparseable
 */
function ini_bytes($value)
{
    $value = trim((string) $value);

    if ($value === '' || $value === '-1') {
        return 0;
    }

    $number = (int) $value;
    if ($number <= 0) {
        return 0;
    }

    switch (strtolower(substr($value, -1))) {
        case 'g':
            return $number * 1024 * 1024 * 1024;
        case 'm':
            return $number * 1024 * 1024;
        case 'k':
            return $number * 1024;
    }

    return $number;
}

/**
 * Converts a duration from seconds to a human-readable format.
 *
 * @param int $seconds The duration in seconds.
 * @param bool $round_to_whole_value If true, returns only the largest unit
 * @return string The formatted duration.
 *
 * The function returns:
 * - "X seconds" if the duration is less than 60 seconds,
 * - "X minutes and Y seconds" if less than 10 minutes,
 * - "X minutes" if less than 1 hour,
 * - "X hours and Y minutes" otherwise.
 */
function duration_to_human($seconds, $round_to_whole_value = false)
{
    if (!is_numeric($seconds)) {
        return "---";
    }

    $years = intdiv($seconds, 31536000); // 365 days
    $months = intdiv($seconds % 31536000, 2592000); // 30 days
    $weeks = intdiv($seconds % 2592000, 604800); // 7 days
    $days = intdiv($seconds % 604800, 86400); // 24 hours
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining_seconds = $seconds % 60;

    $parts = [];
    if ($years > 0) {
        $parts[] = $years . " year" . ($years != 1 ? "s" : "");
    }
    if ($months > 0) {
        $parts[] = $months . " month" . ($months != 1 ? "s" : "");
    }
    if ($weeks > 0) {
        $parts[] = $weeks . " week" . ($weeks != 1 ? "s" : "");
    }
    if ($days > 0) {
        $parts[] = $days . " day" . ($days != 1 ? "s" : "");
    }
    if ($hours > 0) {
        $parts[] = $hours . " hour" . ($hours != 1 ? "s" : "");
    }
    if ($minutes > 0) {
        $parts[] = $minutes . " minute" . ($minutes != 1 ? "s" : "");
    }
    if ($remaining_seconds > 0) {
        $parts[] = $remaining_seconds . " second" . ($remaining_seconds != 1 ? "s" : "");
    }

    if ($round_to_whole_value) {
        return count($parts) > 0 ? $parts[0] : "less than a second";
    } else {
        return count($parts) > 1 ? $parts[0] . " and " . $parts[1] : (count($parts) > 0 ? $parts[0] : "less than a second");
    }
}

/**
 * Convert a full URL to short URL by removing protocol
 *
 * Strips http:// or https:// from the beginning of the URL if present.
 * Leaves the URL alone if it doesn't start with either protocol.
 * Removes trailing slash if there is no path.
 *
 * @param string|null $url URL to convert
 * @return string|null Short URL without protocol
 */
function full_url_to_short_url(?string $url): ?string
{
    if ($url === null || $url === '') {
        return $url;
    }

    // Remove http:// or https:// from the beginning
    if (stripos($url, 'http://') === 0) {
        $url = substr($url, 7);
    } elseif (stripos($url, 'https://') === 0) {
        $url = substr($url, 8);
    }

    // Remove trailing slash if there is no path (just domain)
    // Check if URL is just domain with trailing slash (no path after slash)
    if (substr($url, -1) === '/' && substr_count($url, '/') === 1) {
        $url = rtrim($url, '/');
    }

    return $url;
}

/**
 * Convert a short URL to full URL by adding protocol
 *
 * Adds http:// to the beginning of the URL if it lacks a protocol.
 * Leaves URLs with existing http:// or https:// unchanged.
 * Adds trailing slash if there is no path.
 *
 * @param string|null $url URL to convert
 * @return string|null Full URL with protocol
 */
function short_url_to_full_url(?string $url): ?string
{
    if ($url === null || $url === '') {
        return $url;
    }

    // Check if URL already has a protocol
    if (stripos($url, 'http://') === 0 || stripos($url, 'https://') === 0) {
        $full_url = $url;
    } else {
        // Add http:// protocol
        $full_url = 'http://' . $url;
    }

    // Add trailing slash if there is no path (just domain)
    // Check if URL has no slash after the domain
    $without_protocol = preg_replace('#^https?://#i', '', $full_url);
    if (strpos($without_protocol, '/') === false) {
        $full_url .= '/';
    }

    return $full_url;
}

/**
 * Validate a short URL format
 *
 * Validates that a URL (without protocol) looks like a valid domain.
 * Requirements:
 * - Must contain at least one dot (.)
 * - Must not contain spaces
 * - Empty strings are considered valid (optional field)
 *
 * @param string|null $url Short URL to validate
 * @return bool True if valid, false otherwise
 */
function validate_short_url(?string $url): bool
{
    // Empty strings are valid (optional field)
    if ($url === null || $url === '') {
        return true;
    }

    // Must not contain spaces
    if (strpos($url, ' ') !== false) {
        return false;
    }

    // Must contain at least one dot (domain.extension)
    if (strpos($url, '.') === false) {
        return false;
    }

    return true;
}

/**
 * Escape HTML and convert newlines to <br>
 *
 * Combines htmlspecialchars() and nl2br() for displaying user-generated
 * plain text as HTML with preserved line breaks.
 *
 * @param string|null $str String to process
 * @return string HTML-escaped string with line breaks
 */
function htmlbr(?string $str): string
{
    if ($str === null || $str === '') {
        return '';
    }

    return nl2br(htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Common TLDs for domain detection in linkify functions
 */
define('LINKIFY_TLDS', 'com|org|net|edu|gov|io|co|me|info|biz|us|uk|ca|au|de|fr|es|it|nl|ru|jp|cn|in|br|mx|app|dev|xyz|online|site|tech|store|blog|shop');

/**
 * Convert plain text to HTML with URLs converted to hyperlinks
 *
 * First escapes the text to HTML, then converts URLs (with protocols) and
 * domain-like text (with known TLDs) into clickable hyperlinks.
 *
 * @param string|null $content Plain text content
 * @param bool $new_window Whether to add target="_blank" to links
 * @return string HTML with clickable links
 */
function linkify_text(?string $content, bool $new_window = true): string
{
    if ($content === null || $content === '') {
        return '';
    }

    // First escape HTML
    $html = htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return _linkify_content($html, $new_window);
}

/**
 * Convert URLs in HTML to hyperlinks, preserving existing links
 *
 * Converts URLs (with protocols) and domain-like text (with known TLDs)
 * into clickable hyperlinks, but only for text not already inside <a> tags.
 *
 * @param string|null $content HTML content
 * @param bool $new_window Whether to add target="_blank" to links
 * @return string HTML with clickable links
 */
function linkify_html(?string $content, bool $new_window = true): string
{
    if ($content === null || $content === '') {
        return '';
    }

    // Split content into segments: inside <a> tags and outside
    // Pattern matches <a ...>...</a> including nested content
    $pattern = '/(<a\s[^>]*>.*?<\/a>)/is';
    $segments = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);

    $result = '';
    foreach ($segments as $segment) {
        // Check if this segment is an <a> tag (starts with <a and contains </a>)
        if (preg_match('/^<a\s/i', $segment)) {
            // Already a link, keep as-is
            $result .= $segment;
        } else {
            // Not inside a link, linkify it
            $result .= _linkify_content($segment, $new_window);
        }
    }

    return $result;
}

/**
 * Internal helper to convert URLs/domains to links in content
 *
 * @param string $content Content to process (should not contain <a> tags to linkify)
 * @param bool $new_window Whether to add target="_blank"
 * @return string Content with URLs converted to links
 */
function _linkify_content(string $content, bool $new_window): string
{
    $target = $new_window ? ' target="_blank" rel="noopener noreferrer"' : '';
    $tlds = LINKIFY_TLDS;

    // Pattern for URLs with protocol
    $url_pattern = '/(https?:\/\/[^\s<>\[\]()]+)/i';

    // Pattern for domain-like text (domain.tld or subdomain.domain.tld with optional path)
    $domain_pattern = '/\b((?:[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+(' . $tlds . ')(?:\/[^\s<>\[\]()]*)?)\b/i';

    // First, replace URLs with protocol
    $content = preg_replace_callback($url_pattern, function ($matches) use ($target) {
        $url = $matches[1];
        // Clean trailing punctuation that's likely not part of URL
        $url = rtrim($url, '.,;:!?)\'\"');
        return '<a href="' . $url . '"' . $target . '>' . $url . '</a>';
    }, $content);

    // Then, replace domain-like text only in segments NOT inside <a> tags
    // (the URL replacement above may have created <a> tags)
    $link_pattern = '/(<a\s[^>]*>.*?<\/a>)/is';
    $segments = preg_split($link_pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);

    $result = '';
    foreach ($segments as $segment) {
        // Skip segments that are already links
        if (preg_match('/^<a\s/i', $segment)) {
            $result .= $segment;
        } else {
            // Apply domain pattern to non-link segments
            $result .= preg_replace_callback($domain_pattern, function ($matches) use ($target) {
                $domain = $matches[1];
                // Clean trailing punctuation
                $domain = rtrim($domain, '.,;:!?)\'\"');
                return '<a href="https://' . $domain . '"' . $target . '>' . $domain . '</a>';
            }, $segment);
        }
    }

    return $result;
}

/**
 * Convert a relative URL path to an absolute URL using the application hostname.
 *
 * Always https: RSpade assumes upstream SSL termination and generates every URL as
 * https (APP_URL is https-enforced at boot). For same-origin links in HTML, use
 * relative URLs instead. Use this only when an absolute URL is required (emails,
 * external APIs, etc.).
 *
 * @param string $path Relative URL path (e.g., "/_download/abc123")
 * @return string Absolute URL (e.g., "https://myapp.example.com/_download/abc123")
 */
function rsx_absolute_url(string $path): string
{
    // Ensure path starts with /
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    return 'https://' . \App\RSpade\Core\Rsx::get_hostname() . $path;
}
