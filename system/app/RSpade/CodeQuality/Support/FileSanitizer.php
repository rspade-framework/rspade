<?php

namespace App\RSpade\CodeQuality\Support;

use Symfony\Component\Process\Process;
use App\RSpade\Core\JsParsers\Rpc_Client_Abstract;
use App\RSpade\Core\JsParsers\Rpc_Startup_Diagnostics;
use App\RSpade\Core\Locks\RsxLocks;

class FileSanitizer extends Rpc_Client_Abstract
{
    /**
     * Node.js RPC server script path
     */
    protected const RPC_SERVER_SCRIPT = 'app/RSpade/CodeQuality/Support/resource/js-sanitizer-server.js';

    /**
     * Unix socket path for RPC server
     */
    protected const RPC_SOCKET = 'storage/rsx-tmp/js-sanitizer-server.sock';

    /**
     * Human name for startup diagnostics
     */
    protected const RPC_LABEL = 'JS Sanitizer';

    /**
     * Cache directory for sanitized JavaScript files
     */
    protected const CACHE_DIR = 'storage/rsx-tmp/cache/js-sanitized';

    /**
     * Request ID counter
     */
    protected static $request_id = 0;
    /**
     * Get PHP content with comments removed
     * This ensures we don't match patterns inside comments
     * Uses PHP tokenizer to properly strip comments (from line 711 of monolith)
     */
    public static function sanitize_php(string $content): array
    {
        // Use PHP tokenizer to properly strip comments
        $tokens = token_get_all($content);
        $lines = [];
        $current_line = '';
        
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $token_type = $token[0];
                $token_content = $token[1];
                
                // Skip comment tokens
                if ($token_type === T_COMMENT || $token_type === T_DOC_COMMENT) {
                    // Add empty lines to preserve line numbers
                    $comment_lines = explode("\n", $token_content);
                    foreach ($comment_lines as $idx => $comment_line) {
                        if ($idx === 0 && $current_line !== '') {
                            // First line of comment - complete current line
                            $lines[] = $current_line;
                            $current_line = '';
                        } elseif ($idx > 0) {
                            // Additional comment lines
                            $lines[] = '';
                        }
                    }
                } else {
                    // Add non-comment content
                    $content_parts = explode("\n", $token_content);
                    foreach ($content_parts as $idx => $part) {
                        if ($idx > 0) {
                            $lines[] = $current_line;
                            $current_line = $part;
                        } else {
                            $current_line .= $part;
                        }
                    }
                }
            } else {
                // Single character tokens
                $current_line .= $token;
            }
        }
        
        // Add the last line if any
        if ($current_line !== '' || count($lines) === 0) {
            $lines[] = $current_line;
        }
        
        $sanitized_content = implode("\n", $lines);
        
        return [
            'content' => $sanitized_content,
            'lines' => $lines,
            'original_lines' => explode("\n", $content),
        ];
    }
    
    /**
     * Get sanitized JavaScript content for checking
     * Removes comments and string contents to avoid false positives
     * Uses RPC server for performance
     */
    public static function sanitize_javascript(string $file_path): array
    {
        // Create cache directory if it doesn't exist
        $base_path = base_path();
        $cache_dir = rsx_project_file_path(self::CACHE_DIR);
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }

        // Generate cache path based on relative file path
        $relative_path = str_replace($base_path . '/', '', $file_path);
        $cache_path = $cache_dir . '/' . str_replace('/', '_', $relative_path) . '.sanitized';

        // Check if cache is valid
        if (file_exists($cache_path)) {
            $source_mtime = filemtime($file_path);
            $cache_mtime = filemtime($cache_path);

            if ($cache_mtime >= $source_mtime) {
                // Cache is valid, return cached content
                $sanitized_content = file_get_contents($cache_path);
                return [
                    'content' => $sanitized_content,
                    'lines' => explode("\n", $sanitized_content),
                    'original_lines' => explode("\n", file_get_contents($file_path)),
                ];
            }
        }

        // Sanitize via RPC server
        $sanitized = static::_sanitize_via_rpc($file_path);

        // Save to cache
        file_put_contents_safe($cache_path, $sanitized);

        return [
            'content' => $sanitized,
            'lines' => explode("\n", $sanitized),
            'original_lines' => explode("\n", file_get_contents($file_path)),
        ];
    }

    /**
     * Sanitize via RPC server
     */
    protected static function _sanitize_via_rpc($file_path): string
    {
        // Lazy: a fully cached check never spawns the daemon at all.
        static::ensure_rpc_server();

        $socket_path = static::_rpc_socket_path();

        try {
            $sock = @stream_socket_client("unix://{$socket_path}", $errno, $errstr, 0.5);
            if (!$sock) {
                throw new \RuntimeException("Failed to connect to RPC server: {$errstr}");
            }

            // Send sanitize request
            $request = [
                'id' => ++static::$request_id,
                'method' => 'sanitize',
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

            // Handle sanitize result
            if ($result['status'] === 'success') {
                return $result['sanitized'];
            }

            // Handle error response
            if ($result['status'] === 'error' && isset($result['error'])) {
                $error = $result['error'];
                $message = $error['message'] ?? 'Unknown error';
                throw new \RuntimeException("Sanitization error: " . $message);
            }

            // Unknown response format
            throw new \RuntimeException(
                "JavaScript sanitizer RPC returned unexpected response for {$file_path}:\n" .
                json_encode($result, JSON_PRETTY_PRINT)
            );

        } catch (\Exception $e) {
            // Wrap exceptions
            throw new \RuntimeException(
                "JavaScript sanitizer RPC error for {$file_path}: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Sanitize file based on extension
     * Note: PHP takes content, JavaScript takes file_path (matching monolith behavior)
     */
    public static function sanitize(string $file_path, ?string $content = null): array
    {
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);

        if ($extension === 'php') {
            if ($content === null) {
                $content = file_get_contents($file_path);
            }
            return self::sanitize_php($content);
        } elseif (in_array($extension, ['js', 'jsx', 'ts', 'tsx'])) {
            // JavaScript sanitization needs file path, not content
            return self::sanitize_javascript($file_path);
        } else {
            // For other files, return as-is
            if ($content === null) {
                $content = file_get_contents($file_path);
            }
            return [
                'content' => $content,
                'lines' => explode("\n", $content),
                'original_lines' => explode("\n", $content),
            ];
        }
    }
}
