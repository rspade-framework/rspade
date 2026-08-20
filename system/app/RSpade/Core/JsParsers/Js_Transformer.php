<?php
/**
 * JavaScript Transformer using Babel
 *
 * Transpiles modern JavaScript features (decorators) to compatible code.
 * Private fields (#private) are NOT transpiled - native browser support is used.
 *
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\JsParsers;

use Illuminate\Support\Facades\File;
use RuntimeException;
use App\RSpade\Core\JsParsers\Rpc_Client_Abstract;
use App\RSpade\Core\JsParsers\Rpc_Startup_Diagnostics;
use App\RSpade\Core\Locks\RsxLocks;

class Js_Transformer extends Rpc_Client_Abstract
{
    /**
     * Node.js transformer script path (RPC server)
     */
    protected const RPC_SERVER_SCRIPT = 'app/RSpade/Core/JsParsers/resource/js-transformer-server.js';

    /**
     * Transformer script path for availability checking (RPC server used for actual transformations)
     */
    protected const TRANSFORMER_SCRIPT = 'app/RSpade/Core/JsParsers/resource/js-transformer.js';

    /**
     * Cache directory for transformed JavaScript files
     */
    protected const CACHE_DIR = 'storage/rsx-tmp/babel_cache';

    /**
     * RPC server socket path
     */
    protected const RPC_SOCKET = 'storage/rsx-tmp/js-transformer-server.sock';

    /**
     * Human name for startup diagnostics
     */
    protected const RPC_LABEL = 'JS Transformer';

    /**
     * RPC request ID counter
     */
    protected static $request_id = 0;

    /**
     * Vendored decorator fork bundle (participates in the cache fingerprint)
     */
    protected const DECORATOR_FORK_BUNDLE = 'app/RSpade/Core/JsParsers/resource/babel-plugin-decorators/index.js';

    /**
     * Cached toolchain fingerprint (computed once per process)
     */
    protected static ?string $toolchain_fingerprint = null;

    /**
     * Transform a JavaScript file using Babel
     *
     * @param string $file_path Path to JavaScript file
     * @param string $target Target environment (modern, es6, es5)
     * @return string Transformed JavaScript code
     */
    public static function transform(string $file_path, string $target = 'modern'): string
    {
        // Generate cache key using file hash, target, and toolchain fingerprint.
        // The fingerprint folds in the transformer script, the vendored decorator fork,
        // and the @babel/core version so that swapping any of them invalidates every
        // cache entry automatically (old entries orphan harmlessly).
        $cache_key = _rsx_file_hash_for_build($file_path) . '_' . $target . '_' . static::_toolchain_fingerprint();
        $cache_file = rsx_project_file_path(self::CACHE_DIR . '/' . $cache_key . '.js');

        // Check if cached result exists
        if (file_exists($cache_file)) {
            $mtime_cache = filemtime($cache_file);
            $mtime_source = filemtime($file_path);

            if ($mtime_cache >= $mtime_source) {
                return file_get_contents($cache_file);
            }
        }

        // Transform the file
        $result = static::_transform_without_cache($file_path, $target);

        // Cache the result
        static::_cache_result($cache_key, $result);

        return $result;
    }

    /**
     * Transform a JavaScript string using Babel
     *
     * @param string $js_code JavaScript code to transform
     * @param string $file_path Original file path (for hash generation)
     * @param string $target Target environment (modern, es6, es5)
     * @return string Transformed JavaScript code
     */
    public static function transform_string(string $js_code, string $file_path, string $target = 'modern'): string
    {
        // Create temporary file
        $temp_file = tempnam(sys_get_temp_dir(), 'babel_');
        file_put_contents_safe($temp_file, $js_code);

        try {
            // Transform using temporary file
            $result = static::_transform_without_cache($temp_file, $target, $file_path);
            return $result;
        } finally {
            // Clean up temporary file
            @unlink($temp_file);
        }
    }

    /**
     * Transform without using cache
     *
     * @param string $file_path Path to file to transform
     * @param string $target Target environment
     * @param string|null $original_path Original file path for hash (if using temp file)
     * @return string Transformed code
     */
    protected static function _transform_without_cache(string $file_path, string $target, ?string $original_path = null): string
    {
        // Use RPC server for transformation
        return static::_transform_via_rpc($file_path, $target, $original_path);
    }

    /**
     * Cache the transformer result
     *
     * @param string $cache_key Cache key
     * @param string $result Transformed code
     */
    protected static function _cache_result(string $cache_key, string $result): void
    {
        $cache_dir = rsx_project_file_path(self::CACHE_DIR);

        // Ensure cache directory exists
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }

        $cache_file = $cache_dir . '/' . $cache_key . '.js';
        file_put_contents_safe($cache_file, $result);
    }

    /**
     * Toolchain fingerprint folded into the transform cache key.
     *
     * A short md5 over the pieces of the transform toolchain whose change must invalidate
     * every cached transform:
     *   - the vendored decorator fork bundle (its content hash)
     *   - the @babel/core version string (from its package.json)
     *   - the transformer server script itself (its content hash)
     *
     * Computed once per process (static cache). Files are small / read once, so this is
     * cheap. Missing pieces contribute a marker rather than throwing here -- the transform
     * path itself fails loud downstream if the toolchain is genuinely absent.
     *
     * @return string 12-char hex fingerprint
     */
    protected static function _toolchain_fingerprint(): string
    {
        if (static::$toolchain_fingerprint !== null) {
            return static::$toolchain_fingerprint;
        }

        $fork_bundle = base_path(self::DECORATOR_FORK_BUNDLE);
        $server_script = base_path(self::RPC_SERVER_SCRIPT);
        $babel_core_pkg = base_path('node_modules/@babel/core/package.json');

        $fork_hash = is_file($fork_bundle) ? md5_file($fork_bundle) : 'no-fork';
        $server_hash = is_file($server_script) ? md5_file($server_script) : 'no-server';

        $babel_core_version = 'no-babel-core';
        if (is_file($babel_core_pkg)) {
            $pkg = json_decode(file_get_contents($babel_core_pkg), true);
            $babel_core_version = $pkg['version'] ?? 'unknown';
        }

        static::$toolchain_fingerprint = substr(
            md5($fork_hash . '.' . $babel_core_version . '.' . $server_hash),
            0,
            12
        );

        return static::$toolchain_fingerprint;
    }

    /**
     * Clear the transformation cache
     */
    public static function clear_cache(): void
    {
        $cache_dir = rsx_project_file_path(self::CACHE_DIR);

        if (is_dir($cache_dir)) {
            $files = glob($cache_dir . '/*.js');
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Check if Babel is properly installed
     *
     * @return bool
     */
    public static function is_available(): bool
    {
        $transformer_path = base_path(self::TRANSFORMER_SCRIPT);

        if (!File::exists($transformer_path)) {
            return false;
        }

        // Check if node_modules exists
        $node_modules = dirname($transformer_path) . '/node_modules';
        if (!is_dir($node_modules)) {
            return false;
        }

        // Check for required packages
        $required_packages = [
            '@babel/core',
            '@babel/preset-env',
            '@babel/plugin-proposal-decorators'
        ];

        foreach ($required_packages as $package) {
            if (!is_dir($node_modules . '/' . $package)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get list of required npm packages
     *
     * @return array
     */
    public static function get_required_packages(): array
    {
        return [
            '@babel/core' => '^7.24.0',
            '@babel/preset-env' => '^7.24.0',
            '@babel/plugin-proposal-decorators' => '^7.24.0'
        ];
    }

    /**
     * Transform file via RPC server
     *
     * @param string $file_path Path to file to transform
     * @param string $target Target environment
     * @param string|null $original_path Original file path for hash (if using temp file)
     * @return string Transformed code
     */
    protected static function _transform_via_rpc(string $file_path, string $target, ?string $original_path = null): string
    {
        // Lazy: only a genuine cache MISS needs a daemon at all.
        static::ensure_rpc_server();

        $socket_path = static::_rpc_socket_path();

        // Connect to RPC server
        $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 5);
        if (!$socket) {
            throw new RuntimeException("Failed to connect to JS Transformer RPC server: {$errstr}");
        }

        // Set blocking mode for reliable reads/writes
        stream_set_blocking($socket, true);

        // Use original path for hash generation if provided (for temp files).
        // Reduce it to a checkout-RELATIVE path: the transformer derives a private-
        // function scoping prefix from md5(hash_path). An absolute path would embed
        // the checkout location into every bundle's bytes, so two byte-identical
        // checkouts would emit different bundle CONTENT under the same filename. The
        // relative path is still unique per file (no collisions) but identical across
        // checkouts.
        $hash_path = _rsx_relative_build_path($original_path ?: $file_path);

        // Send transform request
        static::$request_id++;
        $request = json_encode([
            'id' => static::$request_id,
            'method' => 'transform',
            'files' => [
                [
                    'path' => $file_path,
                    'target' => $target,
                    'hash_path' => $hash_path
                ]
            ]
        ]) . "\n";

        fwrite($socket, $request);

        // Read response
        $response = fgets($socket);
        fclose($socket);

        if (!$response) {
            throw new RuntimeException("JS Transformer RPC server returned empty response for {$file_path}");
        }

        $result = json_decode($response, true);

        if (!$result || !isset($result['results'])) {
            throw new RuntimeException(
                "JS Transformer RPC server returned invalid response for {$file_path}:\n" . $response
            );
        }

        $file_result = $result['results'][$file_path] ?? null;

        if (!$file_result) {
            throw new RuntimeException("JS Transformer RPC server did not return result for {$file_path}");
        }

        // Handle error response
        if ($file_result['status'] === 'error' && isset($file_result['error'])) {
            $error = $file_result['error'];
            $message = $error['message'] ?? 'Unknown error';
            $line = $error['line'] ?? null;
            $column = $error['column'] ?? null;
            $suggestion = $error['suggestion'] ?? null;

            // Build error message
            $error_msg = "JavaScript transformation failed";
            if ($line && $column) {
                $error_msg .= " at line {$line}, column {$column}";
            }
            $error_msg .= " in {$file_path}:\n{$message}";

            if ($suggestion) {
                $error_msg .= "\n\n{$suggestion}";
            }

            // Check for specific error types
            if (str_contains($message, 'Cannot find module')) {
                throw new RuntimeException(
                    "Babel packages not installed.\n" .
                    "Run: npm install\n" .
                    "Error: {$message}"
                );
            }

            throw new RuntimeException($error_msg);
        }

        // Success - return transformed code
        if ($file_result['status'] === 'success' && isset($file_result['result'])) {
            return $file_result['result'];
        }

        // Unknown response format
        throw new RuntimeException(
            "JS Transformer RPC server returned unexpected response for {$file_path}:\n" .
            json_encode($file_result, JSON_PRETTY_PRINT)
        );
    }
}