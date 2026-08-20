<?php
/**
 * Rsx_SSR - Server-side rendering of jqhtml components
 *
 * Manages the SSR Node server lifecycle and communicates via Unix socket.
 * The server is started on-demand and stays running across requests. It is
 * automatically restarted when the manifest build key changes (new code).
 *
 * All SSR operations are serialized via a SYSTEM advisory lock (flock, this box only) so
 * that only one PHP process manages the server lifecycle at a time.
 */

namespace App\RSpade\Core\SSR;

use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Manifest\Manifest;

class Rsx_SSR
{
    /**
     * Unix socket path (relative to base_path)
     */
    private const SOCKET_PATH = 'storage/rsx-tmp/ssr-server.sock';

    /**
     * Build key sidecar file (relative to base_path)
     */
    private const BUILD_KEY_FILE = 'storage/rsx-tmp/ssr-server.build_key';

    /**
     * PID file (relative to base_path)
     */
    private const PID_FILE = 'storage/rsx-tmp/ssr-server.pid';

    /**
     * SSR server script (relative to base_path)
     */
    private const SERVER_SCRIPT = 'bin/ssr-server.js';

    /**
     * Default render timeout (seconds)
     */
    private const DEFAULT_TIMEOUT = 30;

    /**
     * Server startup timeout (milliseconds)
     */
    private const STARTUP_TIMEOUT_MS = 10000;

    /**
     * Server startup poll interval (milliseconds)
     */
    private const STARTUP_POLL_MS = 50;

    /**
     * RPC request ID counter
     */
    private static int $request_id = 0;

    /**
     * Render a jqhtml component to HTML via the SSR server
     *
     * Acquires the SSR system lock, ensures the server is running with
     * current code, sends the render request, and returns the result.
     *
     * @param string $component Component class name (e.g., 'SSR_Test_Page')
     * @param array $args Component arguments (becomes this.args)
     * @param string $bundle_class Bundle class name (e.g., 'SSR_Test_Bundle')
     * @return array ['html' => string, 'timing' => array, 'preload' => array]
     * @throws \RuntimeException on connection or render failure
     */
    public static function render_component(string $component, array $args = [], string $bundle_class = ''): array
    {
        // SYSTEM lock: the SSR server is a node process on THIS box. Waits forever - the
        // render itself is bounded by DEFAULT_TIMEOUT, so the queue always drains.
        $lock_token = RsxLocks::system_lock(RsxLocks::LOCK_SSR_RENDER);

        try {
            // Ensure server is running with current build key
            self::_ensure_server();

            // Build request
            $bundle_paths = self::_get_bundle_paths($bundle_class);

            $vendor_content = file_get_contents($bundle_paths['vendor_js']);
            $app_content = file_get_contents($bundle_paths['app_js']);

            if ($vendor_content === false) {
                throw new \RuntimeException("SSR: Failed to read vendor bundle: {$bundle_paths['vendor_js']}");
            }
            if ($app_content === false) {
                throw new \RuntimeException("SSR: Failed to read app bundle: {$bundle_paths['app_js']}");
            }

            $request = [
                'id' => 'ssr-' . uniqid(),
                'type' => 'render',
                'payload' => [
                    'bundles' => [
                        ['id' => 'vendor', 'content' => $vendor_content],
                        ['id' => 'app', 'content' => $app_content],
                    ],
                    'component' => $component,
                    'args' => empty($args) ? new \stdClass() : $args,
                    'options' => [
                        'baseUrl' => 'http://localhost',
                        'timeout' => self::DEFAULT_TIMEOUT * 1000,
                    ],
                ],
            ];

            $response = self::_send_request($request);

            if ($response['status'] !== 'success') {
                $error = $response['error'] ?? ['code' => 'UNKNOWN', 'message' => 'Unknown error'];
                throw new \RuntimeException(
                    "SSR render failed [{$error['code']}]: {$error['message']}"
                );
            }

            return [
                'html' => $response['payload']['html'],
                'timing' => $response['payload']['timing'] ?? [],
                'preload' => $response['payload']['preload'] ?? [],
            ];
        } finally {
            RsxLocks::release_lock($lock_token);
        }
    }

    /**
     * Ensure the SSR server is running with current code
     *
     * Checks socket existence and build key. Starts or restarts as needed.
     */
    private static function _ensure_server(): void
    {
        $socket_path = rsx_project_file_path(self::SOCKET_PATH);
        $build_key_file = rsx_project_file_path(self::BUILD_KEY_FILE);
        $current_build_key = Manifest::get_build_key();

        if (file_exists($socket_path)) {
            // Check if server has current code
            $stored_build_key = file_exists($build_key_file) ? trim(file_get_contents($build_key_file)) : '';

            if ($stored_build_key === $current_build_key) {
                // Build key matches — verify server is responsive
                if (self::_ping_server()) {
                    return; // Server is running and current
                }
            }

            // Build key mismatch or server unresponsive — restart
            self::_stop_server(force: true);
        }

        // Start fresh server
        self::_start_server();

        // Record the build key
        file_put_contents_safe($build_key_file, $current_build_key);
    }

    /**
     * Start the SSR Node server
     *
     * Spawns a daemonized Node process listening on the Unix socket.
     * The process runs independently of PHP (survives FPM worker recycling).
     * Polls until the server responds to ping, or throws on timeout.
     */
    private static function _start_server(): void
    {
        $socket_path = rsx_project_file_path(self::SOCKET_PATH);
        $pid_file = rsx_project_file_path(self::PID_FILE);
        $server_script = base_path(self::SERVER_SCRIPT);

        if (!file_exists($server_script)) {
            throw new \RuntimeException("SSR server script not found at {$server_script}");
        }

        // Ensure socket directory exists
        $socket_dir = dirname($socket_path);
        if (!is_dir($socket_dir)) {
            mkdir($socket_dir, 0755, true);
        }

        // Clean up stale socket file
        if (file_exists($socket_path)) {
            @unlink($socket_path);
        }

        // Launch as daemon — detached from PHP process tree
        $cmd = sprintf(
            'cd %s && nohup node %s --socket=%s > /dev/null 2>&1 & echo $!',
            escapeshellarg(base_path()),
            escapeshellarg($server_script),
            escapeshellarg($socket_path)
        );

        $pid = (int) trim(shell_exec('bash -c ' . escapeshellarg($cmd)));

        if ($pid > 0) {
            file_put_contents_safe($pid_file, (string) $pid);
        }

        // Poll for server readiness
        $iterations = self::STARTUP_TIMEOUT_MS / self::STARTUP_POLL_MS;

        for ($i = 0; $i < $iterations; $i++) {
            usleep(self::STARTUP_POLL_MS * 1000);

            if (self::_ping_server()) {
                return;
            }
        }

        throw new \RuntimeException(
            "SSR server failed to start within " . self::STARTUP_TIMEOUT_MS . "ms"
        );
    }

    /**
     * Stop the SSR server
     *
     * @param bool $force If true, forcefully kill after graceful attempt
     */
    private static function _stop_server(bool $force = false): void
    {
        $socket_path = rsx_project_file_path(self::SOCKET_PATH);
        $pid_file = rsx_project_file_path(self::PID_FILE);

        if (file_exists($socket_path)) {
            // Send shutdown command via socket
            try {
                $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 1);
                if ($socket) {
                    stream_set_blocking($socket, true);

                    self::$request_id++;
                    $request = json_encode([
                        'id' => 'shutdown-' . self::$request_id,
                        'type' => 'shutdown',
                    ]) . "\n";

                    fwrite($socket, $request);
                    fclose($socket);
                }
            } catch (\Exception $e) {
                // Ignore errors during shutdown
            }

            if ($force) {
                usleep(100000); // 100ms grace period
                @unlink($socket_path);
            }
        }

        // Kill process by PID if still running
        if (file_exists($pid_file)) {
            $pid = (int) trim(file_get_contents($pid_file));
            if ($pid > 0 && posix_kill($pid, 0)) {
                posix_kill($pid, SIGTERM);
            }
            @unlink($pid_file);
        }
    }

    /**
     * Ping the SSR server to check if it's alive
     *
     * @return bool True if server responds, false otherwise
     */
    private static function _ping_server(): bool
    {
        $socket_path = rsx_project_file_path(self::SOCKET_PATH);

        if (!file_exists($socket_path)) {
            return false;
        }

        try {
            $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 1);
            if (!$socket) {
                return false;
            }

            stream_set_blocking($socket, true);
            stream_set_timeout($socket, 2);

            self::$request_id++;
            $request = json_encode([
                'id' => 'ping-' . self::$request_id,
                'type' => 'ping',
            ]) . "\n";

            fwrite($socket, $request);

            $response = fgets($socket);
            fclose($socket);

            if (!$response) {
                return false;
            }

            $result = json_decode($response, true);
            return isset($result['status']) && $result['status'] === 'success';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get compiled bundle file paths for a bundle class
     *
     * @param string $bundle_class Bundle class name
     * @return array ['vendor_js' => string, 'app_js' => string]
     * @throws \RuntimeException if bundle files not found
     */
    private static function _get_bundle_paths(string $bundle_class): array
    {
        // Validate bundle class exists in manifest as a real bundle
        if (!Manifest::php_is_subclass_of($bundle_class, \App\RSpade\Core\Bundle\Rsx_Bundle_Abstract::class)) {
            throw new \RuntimeException("SSR: Invalid bundle class '{$bundle_class}' - not a registered bundle");
        }

        $build_dir = storage_path('rsx-build/bundles');

        $vendor_files = glob("{$build_dir}/{$bundle_class}__vendor.*.js");
        $app_files = glob("{$build_dir}/{$bundle_class}__app.*.js");

        if (empty($vendor_files)) {
            throw new \RuntimeException("SSR: Vendor bundle not found for {$bundle_class}. Run php artisan rsx:bundle:compile");
        }

        if (empty($app_files)) {
            throw new \RuntimeException("SSR: App bundle not found for {$bundle_class}. Run php artisan rsx:bundle:compile");
        }

        return [
            'vendor_js' => $vendor_files[0],
            'app_js' => $app_files[0],
        ];
    }

    /**
     * Send a request to the SSR server and return the response
     *
     * @param array $request Request data
     * @return array Parsed response
     * @throws \RuntimeException on connection or protocol failure
     */
    private static function _send_request(array $request): array
    {
        $socket_path = rsx_project_file_path(self::SOCKET_PATH);
        $timeout = self::DEFAULT_TIMEOUT + 5;

        $socket = @stream_socket_client(
            'unix://' . $socket_path,
            $errno,
            $errstr,
            5
        );

        if (!$socket) {
            throw new \RuntimeException(
                "SSR: Cannot connect to SSR server at {$socket_path} - {$errstr}"
            );
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, $timeout);

        $request_json = json_encode($request) . "\n";
        fwrite($socket, $request_json);

        $response_line = fgets($socket);
        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if ($response_line === false) {
            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException("SSR: Request timed out after {$timeout}s");
            }
            throw new \RuntimeException("SSR: Empty response from server");
        }

        $response = json_decode(trim($response_line), true);

        if ($response === null) {
            throw new \RuntimeException("SSR: Invalid JSON response from server");
        }

        return $response;
    }
}
