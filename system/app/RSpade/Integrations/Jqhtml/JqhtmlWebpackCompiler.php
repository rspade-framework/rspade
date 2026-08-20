<?php

namespace App\RSpade\Integrations\Jqhtml;

use App\RSpade\Core\JsParsers\Rpc_Client_Abstract;
use App\RSpade\Core\JsParsers\Rpc_Startup_Diagnostics;
use App\RSpade\Integrations\Jqhtml\Jqhtml_Exception_ViewException;

/**
 * JqhtmlWebpackCompiler - Compiles JQHTML templates to JavaScript during bundle build
 *
 * This service handles the compilation of .jqhtml template files into JavaScript
 * during the bundle build process. It uses the @jqhtml/parser NPM package to 
 * perform the compilation and caches the results based on file modification times.
 * 
 * Features:
 * - Uses @jqhtml/parser for template compilation
 * - Caches compiled templates based on file mtime
 * - Throws fatal errors on compilation failures (fail loud)
 * - Integrates with the bundle compilation pipeline
 */
#[Instantiatable]
class JqhtmlWebpackCompiler extends Rpc_Client_Abstract
{
    /**
     * RPC server script path
     */
    protected const RPC_SERVER_SCRIPT = 'app/RSpade/Integrations/Jqhtml/resource/jqhtml-compile-server.js';

    /**
     * RPC server socket path
     */
    protected const RPC_SOCKET = 'storage/rsx-tmp/jqhtml-compile-server.sock';

    /**
     * Human name for startup diagnostics
     */
    protected const RPC_LABEL = 'JQHTML Compile';

    /**
     * RPC request ID counter
     */
    protected static $request_id = 0;

    /**
     * Path to jqhtml-compile binary for package validation (RPC server used for actual compilation)
     */
    protected string $compiler_path;

    /**
     * Cache directory for compiled templates
     */
    protected string $cache_dir;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        // Use official jqhtml CLI compiler from npm package
        $this->compiler_path = base_path('node_modules/@jqhtml/parser/bin/jqhtml-compile');
        $this->cache_dir = storage_path('rsx-tmp/jqhtml-cache');

        // Ensure cache directory exists
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }

        // Validate compiler exists - MUST exist
        if (!file_exists($this->compiler_path)) {
            throw new \RuntimeException(
                "Official JQHTML CLI compiler not found at: {$this->compiler_path}. " .
                "Run 'npm install @jqhtml/parser@^2.2.59' to install the official CLI compiler."
            );
        }
    }
    
    /**
     * Compile a single JQHTML template file
     *
     * @param string $file_path Path to .jqhtml file
     * @return string Compiled JavaScript code
     * @throws \RuntimeException On compilation failure
     */
    public function compile_file(string $file_path): string
    {
        if (!file_exists($file_path)) {
            throw new \RuntimeException("JQHTML template not found: {$file_path}");
        }

        // Get file modification time for cache key
        $mtime = filemtime($file_path);
        $cache_key = md5($file_path) . '_' . $mtime;
        $cache_file = $this->cache_dir . '/' . $cache_key . '.js';

        // Check if cached version exists
        if (file_exists($cache_file)) {
            console_debug("JQHTML", "Using cached JQHTML template: {$file_path}");
            return file_get_contents($cache_file);
        }

        console_debug("JQHTML", "Compiling JQHTML template: {$file_path}");

        // Compile via RPC server
        $compiled_js = static::_compile_via_rpc($file_path);

        // Extract template variable name and append registration call
        // CRITICAL: Do NOT add extra newlines - they break sourcemap line offsets
        // The registration is appended AFTER the sourcemap comment so it doesn't affect mappings
        if (preg_match('/var\s+(template_\w+)\s*=/', $compiled_js, $matches)) {
            $template_var = $matches[1];
            // Append registration on same line as end of sourcemap (no extra newlines)
            $compiled_js = rtrim($compiled_js) . "\njqhtml.register_template({$template_var});";
        }

        $wrapped_js = $compiled_js;

        // Ensure exactly one newline at end (no extra)
        $wrapped_js = rtrim($wrapped_js) . "\n";

        // Cache the compiled result
        file_put_contents_safe($cache_file, $wrapped_js);

        // Clean up old cache files for this template
        $this->cleanup_old_cache($file_path, $cache_key);

        return $wrapped_js;
    }
    
    /**
     * Compile multiple JQHTML template files
     * 
     * @param array $files Array of file paths
     * @return array Compiled JavaScript code keyed by file path
     */
    public function compile_files(array $files): array
    {
        $compiled = [];
        
        foreach ($files as $file) {
            try {
                $compiled[$file] = $this->compile_file($file);
            } catch (\Exception $e) {
                // FAIL LOUD - don't continue on error
                throw new \RuntimeException(
                    "Failed to compile JQHTML templates: " . $e->getMessage()
                );
            }
        }
        
        return $compiled;
    }
    
    /**
     * Extract component name from file path
     * 
     * @param string $file_path Path to .jqhtml file
     * @return string Component name
     */
    protected function extract_component_name(string $file_path): string
    {
        // Remove base path and extension
        $relative = str_replace(base_path() . '/', '', $file_path);
        $relative = preg_replace('/\.jqhtml$/i', '', $relative);
        
        // Convert path to component name (e.g., rsx/app/components/MyComponent)
        // to MyComponent or components/MyComponent
        $parts = explode('/', $relative);
        
        // Use the filename as the component name
        return basename($relative);
    }
    
    /**
     * Clean up old cache files for a template
     * 
     * @param string $file_path Original template path
     * @param string $current_cache_key Current cache key to keep
     */
    protected function cleanup_old_cache(string $file_path, string $current_cache_key): void
    {
        $file_hash = md5($file_path);
        $pattern = $this->cache_dir . '/' . $file_hash . '_*.js';
        
        foreach (glob($pattern) as $cache_file) {
            $cache_key = basename($cache_file, '.js');
            if ($cache_key !== $current_cache_key) {
                unlink($cache_file);
            }
        }
    }
    
    /**
     * Clear all cached templates
     */
    public function clear_cache(): void
    {
        $pattern = $this->cache_dir . '/*.js';
        foreach (glob($pattern) as $cache_file) {
            unlink($cache_file);
        }
        
        console_debug("JQHTML", "Cleared JQHTML template cache");
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function get_cache_stats(): array
    {
        $pattern = $this->cache_dir . '/*.js';
        $files = glob($pattern);
        
        $total_size = 0;
        foreach ($files as $file) {
            $total_size += filesize($file);
        }
        
        return [
            'cache_dir' => $this->cache_dir,
            'cached_files' => count($files),
            'total_size' => $total_size,
            'total_size_human' => $this->format_bytes($total_size)
        ];
    }
    
    /**
     * Format bytes to human readable
     *
     * @param int $bytes
     * @return string
     */
    protected function format_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Compile file via RPC server
     *
     * @param string $file_path Path to file to compile
     * @return string Compiled code
     */
    protected static function _compile_via_rpc(string $file_path): string
    {
        // Lazy: only a genuine cache MISS needs a daemon at all.
        static::ensure_rpc_server();

        $socket_path = static::_rpc_socket_path();

        // Connect to RPC server
        $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 5);
        if (!$socket) {
            throw new \RuntimeException("Failed to connect to JQHTML Compile RPC server: {$errstr}");
        }

        // Set blocking mode for reliable reads/writes
        stream_set_blocking($socket, true);

        // Send compile request
        static::$request_id++;
        $request = json_encode([
            'id' => static::$request_id,
            'method' => 'compile',
            'files' => [
                [
                    'path' => $file_path,
                    'format' => 'iife',
                    'sourcemap' => true
                ]
            ]
        ]) . "\n";

        fwrite($socket, $request);

        // Read response
        $response = fgets($socket);
        fclose($socket);

        if (!$response) {
            throw new \RuntimeException("JQHTML Compile RPC server returned empty response for {$file_path}");
        }

        $result = json_decode($response, true);

        if (!$result || !isset($result['results'])) {
            throw new \RuntimeException(
                "JQHTML Compile RPC server returned invalid response for {$file_path}:\n" . $response
            );
        }

        $file_result = $result['results'][$file_path] ?? null;

        if (!$file_result) {
            throw new \RuntimeException("JQHTML Compile RPC server did not return result for {$file_path}");
        }

        // Handle error response
        if ($file_result['status'] === 'error' && isset($file_result['error'])) {
            $error = $file_result['error'];
            $message = $error['message'] ?? 'Unknown error';
            $line = $error['line'] ?? null;
            $column = $error['column'] ?? null;

            // Throw appropriate exception type
            if ($line && $column) {
                throw new Jqhtml_Exception_ViewException(
                    "JQHTML compilation failed:\n{$message}",
                    $file_path,
                    $line,
                    $column
                );
            }

            throw new \RuntimeException(
                "JQHTML compilation failed for {$file_path}:\n{$message}"
            );
        }

        // Success - return compiled code
        if ($file_result['status'] === 'success' && isset($file_result['result'])) {
            return $file_result['result'];
        }

        // Unknown response format
        throw new \RuntimeException(
            "JQHTML Compile RPC server returned unexpected response for {$file_path}:\n" .
            json_encode($file_result, JSON_PRETTY_PRINT)
        );
    }
}