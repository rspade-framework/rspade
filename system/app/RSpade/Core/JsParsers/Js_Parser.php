<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\JsParsers;

use App\RSpade\Core\JsParsers\Js_Exception;
use App\RSpade\Core\JsParsers\Rpc_Client_Abstract;
use App\RSpade\Core\JsParsers\Rpc_Startup_Diagnostics;
use App\RSpade\Core\Locks\RsxLocks;

class Js_Parser extends Rpc_Client_Abstract
{
    /**
     * Node.js RPC server script path
     */
    protected const RPC_SERVER_SCRIPT = 'app/RSpade/Core/JsParsers/resource/js-parser-server.js';

    /**
     * Unix socket path for RPC server
     */
    protected const RPC_SOCKET = 'storage/rsx-tmp/js-parser-server.sock';

    /**
     * Human name for startup diagnostics
     */
    protected const RPC_LABEL = 'JS Parser';

    /**
     * Cache directory for parsed JavaScript files
     */
    protected const CACHE_DIR = 'storage/rsx-tmp/persistent/js_parser';

    /**
     * Request ID counter
     */
    protected static $request_id = 0;

    /**
     * Parse a JavaScript file using Node.js AST parser with caching
     */
    public static function parse($file_path)
    {
        // Generate cache key using the file hash
        $cache_key = _rsx_file_hash_for_build($file_path);
        $cache_file = rsx_project_file_path(self::CACHE_DIR . '/' . $cache_key . '.json');

        // Check if cached result exists
        if (file_exists($cache_file)) {
            $cached_data = file_get_contents($cache_file);
            $parsed_data = json_decode($cached_data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $parsed_data;
            }
            // Cache is corrupt, delete it and continue to parse
            @unlink($cache_file);
        }

        // Parse the file (original logic continues below)
        $result = static::_parse_without_cache($file_path);

        // Cache the result
        static::_cache_result($cache_key, $result);

        return $result;
    }

    /**
     * Parse without using cache
     */
    protected static function _parse_without_cache($file_path)
    {
        // Always use RPC server - if not running, that's a fatal error
        return static::_parse_via_rpc($file_path);
    }

    /**
     * Parse via RPC server
     */
    protected static function _parse_via_rpc($file_path)
    {
        // Lazy: only a genuine cache MISS needs a daemon at all.
        static::ensure_rpc_server();

        $socket_path = static::_rpc_socket_path();

        try {
            $sock = @stream_socket_client("unix://{$socket_path}", $errno, $errstr, 0.5);
            if (!$sock) {
                throw new \RuntimeException("Failed to connect to RPC server: {$errstr}");
            }

            // Set blocking mode for reliable reads
            stream_set_blocking($sock, true);

            // Send parse request
            $request = [
                'id' => ++static::$request_id,
                'method' => 'parse',
                'files' => [$file_path]
            ];

            fwrite($sock, json_encode($request) . "\n");

            // Read response
            $response = fgets($sock);
            fclose($sock);

            if (!$response) {
                throw new \RuntimeException("No response from RPC server");
            }

            $data = json_decode($response, true);

            if (!$data || !is_array($data)) {
                throw new \RuntimeException("Invalid JSON response from RPC server");
            }

            if (isset($data['error'])) {
                throw new \RuntimeException("RPC error: " . $data['error']);
            }

            if (!isset($data['results'][$file_path])) {
                throw new \RuntimeException("No result for file in RPC response");
            }

            $result = $data['results'][$file_path];

            // Handle parse result
            if ($result['status'] === 'success') {
                return $result['result'];
            }

            // Handle error response
            if ($result['status'] === 'error' && isset($result['error'])) {
                $error = $result['error'];
                $error_type = $error['type'] ?? 'Unknown';
                $message = $error['message'] ?? 'Unknown error';
                $line = $error['line'] ?? 0;
                $column = $error['column'] ?? 0;
                $code = $error['code'] ?? null;
                $suggestion = $error['suggestion'] ?? null;

                // Handle specific error types from structure validation
                switch ($error_type) {
                    case 'ModuleExportsFound':
                        throw new Js_Exception(
                            "Module exports detected. JavaScript files are concatenated, use direct class references.",
                            $file_path,
                            $line
                        );

                    case 'CodeOutsideAllowed':
                        $error_msg = "JavaScript files without classes may only contain function declarations, const variables with static values, and comments.";
                        if ($code) {
                            $error_msg .= "\nFound: {$code}";
                        }
                        throw new Js_Exception(
                            $error_msg,
                            $file_path,
                            $line
                        );

                    case 'CodeOutsideClass':
                        $error_msg = "JavaScript files with classes may only contain one class declaration and comments.";
                        if ($code) {
                            $error_msg .= "\nFound: {$code}";
                        }
                        throw new Js_Exception(
                            $error_msg,
                            $file_path,
                            $line
                        );

                    case 'InstanceMethodDecorator':
                        throw new Js_Exception(
                            "Decorators only allowed on static methods. Instance methods cannot have decorators.",
                            $file_path,
                            $line
                        );

                    case 'FileReadError':
                        throw new \RuntimeException("File read error: " . $message);

                    default:
                        // Clean up the message - remove redundant file path info
                        $message = preg_replace('/^Parse error:\s*/', '', $message);

                        // Create Js_Exception with line/column info
                        $exception = new Js_Exception(
                            $message,
                            $file_path,
                            $line,
                            $column
                        );

                        if ($suggestion) {
                            $exception->setSuggestion($suggestion);
                        }

                        throw $exception;
                }
            }

            // Unknown response format
            throw new \RuntimeException(
                "JavaScript parser RPC returned unexpected response for {$file_path}:\n" .
                json_encode($result, JSON_PRETTY_PRINT)
            );

        } catch (Js_Exception $e) {
            // Re-throw JavaScript exceptions
            throw $e;
        } catch (\Exception $e) {
            // Wrap other exceptions
            throw new \RuntimeException(
                "JavaScript parser RPC error for {$file_path}: " . $e->getMessage()
            );
        }
    }

    /**
     * Cache the parser result
     */
    protected static function _cache_result($cache_key, $result)
    {
        $cache_dir = rsx_project_file_path(self::CACHE_DIR);

        // Ensure cache directory exists
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }

        $cache_file = $cache_dir . '/' . $cache_key . '.json';
        $json_data = json_encode($result);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Don't cache if JSON encoding failed
            return;
        }

        file_put_contents_safe($cache_file, $json_data);
    }

    /**
     * Extract JavaScript metadata in manifest-ready format
     * This is the high-level method that Manifest should call
     *
     * @param string $file_path Path to JavaScript file
     * @return array Manifest-ready metadata
     */
    public static function extract_metadata(string $file_path): array
    {
        $data = [];
        // Use static parser to get raw parsed data
        $parsed = static::parse($file_path);

        if (!empty($parsed['classes'])) {
            $first_class = reset($parsed['classes']);
            $data['class'] = $first_class['name'];

            if ($first_class['extends']) {
                // Check for period in extends clause
                if (str_contains($first_class['extends'], '.')) {
                    \App\RSpade\Core\Manifest\ManifestErrors::js_extends_with_period($file_path, $first_class['name'], $first_class['extends']);
                }
                $data['extends'] = $first_class['extends'];
            }

            // For JS files, we use consistent naming with PHP
            $data['public_instance_methods'] = $first_class['public_instance_methods'] ?? [];
            $data['public_static_methods'] = $first_class['public_static_methods'] ?? [];
            $data['static_properties'] = $first_class['staticProperties'] ?? [];

            // Store decorators if present
            // JS decorators now use same compact format as PHP: [[name, [args]], ...]
            if (!empty($first_class['decorators'])) {
                $data['decorators'] = $first_class['decorators'];
            }

            // Extract method decorators in compact format
            // Note: js-parser.js already returns decorators in compact format [[name, [args]], ...]
            // so we don't need to call compact_decorators() here
            $method_decorators = [];

            // Process regular methods
            if (!empty($first_class['public_instance_methods'])) {
                foreach ($first_class['public_instance_methods'] as $method_name => $method_info) {
                    if (!empty($method_info['decorators'])) {
                        $method_decorators[$method_name] = $method_info['decorators'];
                    }
                }
            }

            // Process static methods
            if (!empty($first_class['public_static_methods'])) {
                foreach ($first_class['public_static_methods'] as $method_name => $method_info) {
                    if (!empty($method_info['decorators'])) {
                        $method_decorators[$method_name] = $method_info['decorators'];
                    }
                }
            }

            // Store method decorators if any found
            if (!empty($method_decorators)) {
                $data['method_decorators'] = $method_decorators;
            }

            // Store duplicate methods if any found (methods defined more than once)
            if (!empty($first_class['duplicateMethods'])) {
                $data['duplicate_methods'] = $first_class['duplicateMethods'];
            }
        }

        if (!empty($parsed['imports'])) {
            $data['imports'] = $parsed['imports'];
        }
        if (!empty($parsed['exports'])) {
            $data['exports'] = $parsed['exports'];
        }

        // Store global function names for uniqueness checking
        if (!empty($parsed['globalFunctions'])) {
            $data['global_function_names'] = $parsed['globalFunctions'];
        }

        // Store global const names for uniqueness checking
        if (!empty($parsed['globalConstants'])) {
            $data['global_const_names'] = $parsed['globalConstants'];
        }

        // Store global functions that have decorators
        if (!empty($parsed['functionsWithDecorators'])) {
            $data['global_functions_with_decorators'] = $parsed['functionsWithDecorators'];
        }

        return $data;
    }
}
