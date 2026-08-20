<?php
/**
 * JavaScript and CSS Minifier
 *
 * Uses a persistent RPC server for efficient minification of multiple files.
 * Terser for JavaScript, cssnano for CSS.
 * Only used in production mode - debug mode retains readable code.
 */

namespace App\RSpade\Core\Bundle;

use RuntimeException;
use App\RSpade\Core\JsParsers\Rpc_Client_Abstract;
use App\RSpade\Core\JsParsers\Rpc_Startup_Diagnostics;

class Minifier extends Rpc_Client_Abstract
{
    /**
     * RPC server script path
     */
    protected const RPC_SERVER_SCRIPT = 'app/RSpade/Core/Bundle/resource/minify-server.js';

    /**
     * RPC server socket path
     */
    protected const RPC_SOCKET = 'storage/rsx-tmp/minify-server.sock';

    /**
     * Human name for startup diagnostics
     */
    protected const RPC_LABEL = 'Minify';

    /**
     * RPC request ID counter
     */
    protected static int $request_id = 0;

    /**
     * Minify JavaScript content
     *
     * @param string $content JavaScript content to minify
     * @param string $filename Filename for error reporting
     * @param bool $strip_console_debug When true, Terser drops console_debug() call
     *                                   sites via pure_funcs (strict production only;
     *                                   driven by Manifest::_should_strip_console_debug()).
     * @return string Minified content
     */
    public static function minify_js(string $content, string $filename = 'bundle.js', bool $strip_console_debug = false): string
    {
        return static::_minify_via_rpc($content, 'js', $filename, $strip_console_debug);
    }

    /**
     * Minify CSS content
     *
     * @param string $content CSS content to minify
     * @param string $filename Filename for error reporting
     * @return string Minified content
     */
    public static function minify_css(string $content, string $filename = 'bundle.css'): string
    {
        return static::_minify_via_rpc($content, 'css', $filename);
    }

    /**
     * Minify content via RPC server
     */
    protected static function _minify_via_rpc(string $content, string $type, string $filename, bool $strip_console_debug = false): string
    {
        static::ensure_rpc_server();

        $socket_path = static::_rpc_socket_path();

        // 30s connect budget: this is REQUEST marshaling, not lifecycle - a minify request
        // can queue behind a large one already in flight.
        $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 30);
        if (!$socket) {
            throw new RuntimeException("Failed to connect to minify RPC server: {$errstr}");
        }

        stream_set_blocking($socket, true);

        static::$request_id++;
        $request = json_encode([
            'id' => static::$request_id,
            'method' => 'minify',
            'files' => [
                [
                    'type' => $type,
                    'content' => $content,
                    'filename' => $filename,
                    'strip_console_debug' => $strip_console_debug
                ]
            ]
        ]) . "\n";

        fwrite($socket, $request);

        $response = fgets($socket);
        fclose($socket);

        if (!$response) {
            throw new RuntimeException("No response from minify RPC server");
        }

        $result = json_decode($response, true);

        if (!isset($result['results'][$filename])) {
            throw new RuntimeException("Invalid response from minify RPC server");
        }

        $file_result = $result['results'][$filename];

        if ($file_result['status'] === 'error') {
            $error = $file_result['error'];
            throw new RuntimeException(
                "Minification failed for {$filename}: {$error['message']}"
            );
        }

        return $file_result['result'];
    }

    /**
     * Force a fresh minify daemon.
     *
     * Kept as its own name because the production build calls it explicitly
     * (Prod_Build_Command). The freshness check in ensure_rpc_server() makes this
     * unnecessary for a CHANGED server script; it remains the way to demand a brand new
     * daemon regardless.
     */
    public static function force_restart(): void
    {
        static::stop_rpc_server(force: true);
        static::ensure_rpc_server();
    }
}
