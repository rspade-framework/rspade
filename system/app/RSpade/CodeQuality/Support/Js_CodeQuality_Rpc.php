<?php

namespace App\RSpade\CodeQuality\Support;

use Symfony\Component\Process\Process;
use App\RSpade\Core\JsParsers\Rpc_Client_Abstract;
use App\RSpade\Core\JsParsers\Rpc_Startup_Diagnostics;
use App\RSpade\Core\Locks\RsxLocks;

/**
 * JavaScript Code Quality RPC Client
 *
 * Manages a persistent Node.js RPC server for JavaScript linting and
 * this-usage analysis. This avoids spawning thousands of Node processes
 * during code quality checks.
 *
 * RPC Methods:
 *   - lint: Check JavaScript syntax using Babel parser
 *   - analyze_this: Analyze 'this' usage patterns using Acorn
 */
class Js_CodeQuality_Rpc extends Rpc_Client_Abstract
{
    /**
     * Node.js RPC server script path
     */
    protected const RPC_SERVER_SCRIPT = 'app/RSpade/CodeQuality/Support/resource/js-code-quality-server.js';

    /**
     * Unix socket path for RPC server
     */
    protected const RPC_SOCKET = 'storage/rsx-tmp/js-code-quality-server.sock';

    /**
     * Human name for startup diagnostics
     */
    protected const RPC_LABEL = 'JS Code Quality';

    /**
     * Request ID counter
     */
    protected static $request_id = 0;

    /**
     * Lint a JavaScript file for syntax errors
     *
     * @param string $file_path Path to the JavaScript file
     * @return array|null Error info array or null if no errors
     */
    public static function lint(string $file_path): ?array
    {
        // Outside the marshaling try/catch below, so a daemon that will not start fails
        // LOUD rather than being reported as "no violations".
        static::ensure_rpc_server();

        return static::_lint_via_rpc($file_path);
    }

    /**
     * Analyze a JavaScript file for 'this' usage violations
     *
     * @param string $file_path Path to the JavaScript file
     * @return array Violations array (may be empty)
     */
    public static function analyze_this(string $file_path): array
    {
        // Outside the marshaling try/catch below, so a daemon that will not start fails
        // LOUD rather than being reported as "no violations".
        static::ensure_rpc_server();

        return static::_analyze_this_via_rpc($file_path);
    }

    /**
     * Lint via RPC server
     */
    protected static function _lint_via_rpc(string $file_path): ?array
    {
        $socket_path = static::_rpc_socket_path();

        try {
            $sock = @stream_socket_client("unix://{$socket_path}", $errno, $errstr, 0.5);
            if (!$sock) {
                throw new \RuntimeException("Failed to connect to RPC server: {$errstr}");
            }

            // Set blocking mode for reliable reads
            stream_set_blocking($sock, true);

            // Send lint request
            $request = [
                'id' => ++static::$request_id,
                'method' => 'lint',
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

            if ($result['status'] === 'success') {
                // Return the error info if present, null if no errors
                return $result['error'];
            }

            // Handle error response
            if ($result['status'] === 'error' && isset($result['error'])) {
                throw new \RuntimeException("Lint error: " . ($result['error']['message'] ?? 'Unknown error'));
            }

            return null;

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "JavaScript lint RPC error for {$file_path}: " . $e->getMessage()
            );
        }
    }

    /**
     * Analyze this-usage via RPC server
     */
    protected static function _analyze_this_via_rpc(string $file_path): array
    {
        $socket_path = static::_rpc_socket_path();

        try {
            $sock = @stream_socket_client("unix://{$socket_path}", $errno, $errstr, 0.5);
            if (!$sock) {
                throw new \RuntimeException("Failed to connect to RPC server: {$errstr}");
            }

            // Set blocking mode for reliable reads
            stream_set_blocking($sock, true);

            // Send analyze_this request
            $request = [
                'id' => ++static::$request_id,
                'method' => 'analyze_this',
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

            if ($result['status'] === 'success') {
                return $result['violations'] ?? [];
            }

            // Handle error response - return empty violations, don't fail
            return [];

        } catch (\Exception $e) {
            // Log error but don't fail the check
            console_debug('JS_CODE_QUALITY', "RPC error for {$file_path}: " . $e->getMessage());
            return [];
        }
    }
}
