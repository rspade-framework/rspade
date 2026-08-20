<?php

namespace App\RSpade\Core\Bundle;

use RuntimeException;
use App\RSpade\Core\Manifest\Manifest;

/**
 * CDN Asset Cache
 *
 * Downloads and caches CDN assets locally for inclusion in bundles.
 * Cache is stored in rsx/resource/.cdn-cache/ and committed to git
 * so builds don't depend on CDN availability.
 */
class Cdn_Cache
{
    /**
     * Are we inside the build pipeline right now?
     *
     * Downloading a CDN asset is a BUILD activity, never a request-time one. A sealed
     * build serves every mirrored asset from /_vendor/, so a cache MISS while serving a
     * page means the mirror was never populated (or was deleted) - a broken build, which
     * must fail loud rather than silently curl the internet from a web worker. The build
     * pipeline sets this marker around the steps that legitimately populate the mirror
     * (rsx:prod:build's mirror step and its bundle compilation).
     *
     * CLI is permitted regardless: every CLI entry into the compiler is a build or a
     * developer workflow, and the hole this guard closes is the WEB one.
     */
    public static bool $_build_phase = false;

    /**
     * Cache directory path
     *
     * Located in rsx/resource/.cdn-cache/ so it:
     * - Gets committed to git (not in storage/)
     * - Survives rsx:clean and mode switches
     */
    private static function _get_cache_dir(): string
    {
        return base_path('rsx/resource/.cdn-cache');
    }

    /**
     * Get cached content for a CDN URL
     *
     * Downloads and caches if not already cached.
     *
     * @param string $url The CDN URL
     * @param string $type 'js' or 'css'
     * @return string The file content
     */
    public static function get(string $url, string $type): string
    {
        $cache_path = self::_get_cache_path($url, $type);

        // Return cached content if exists
        if (file_exists($cache_path)) {
            return file_get_contents($cache_path);
        }

        // A miss is only ever resolved by downloading, and downloading is a build activity.
        self::_assert_download_permitted($url, $type);

        // Download and cache
        return self::_download_and_cache($url, $type);
    }

    /**
     * Refuse a request-time download in a mirroring mode.
     *
     * @throws RuntimeException naming the missing mirror file and the remedy.
     */
    private static function _assert_download_permitted(string $url, string $type): void
    {
        $mirroring_mode = Manifest::_should_cache_cdn();

        if (self::_download_is_permitted(PHP_SAPI === 'cli', $mirroring_mode, self::$_build_phase)) {
            return;
        }

        $filename = self::get_cache_filename($url, $type);

        throw new RuntimeException(
            "Missing mirrored external asset: {$filename}\n" .
            "  Source: {$url}\n" .
            "  This build serves external assets from its own /_vendor/ mirror, and this file is\n" .
            "  not in rsx/resource/.cdn-cache/. A sealed build never downloads at request time.\n" .
            '  Remedy: php artisan rsx:prod:refresh'
        );
    }

    /**
     * May a cache miss be resolved by downloading? PURE - the decision, no I/O.
     *
     * @param bool $is_cli      PHP_SAPI === 'cli' - a build or developer workflow
     * @param bool $mirroring_mode Manifest::_should_cache_cdn() - a sealed build's mode
     * @param bool $build_phase the explicit build-pipeline marker
     */
    public static function _download_is_permitted(bool $is_cli, bool $mirroring_mode, bool $build_phase): bool
    {
        return $build_phase || $is_cli || !$mirroring_mode;
    }

    /**
     * Get the local cache path for a URL
     */
    public static function get_cache_path(string $url, string $type): string
    {
        return self::_get_cache_path($url, $type);
    }

    /**
     * Get just the cache filename (without directory) for a URL
     */
    public static function get_cache_filename(string $url, string $type): string
    {
        return basename(self::_get_cache_path($url, $type));
    }

    /**
     * Get the cache directory path (public for route handler)
     */
    public static function get_cache_directory(): string
    {
        return self::_get_cache_dir();
    }

    /**
     * Check if a URL is cached
     */
    public static function is_cached(string $url, string $type): bool
    {
        return file_exists(self::_get_cache_path($url, $type));
    }

    /**
     * Get cache path for a URL
     *
     * Uses URL hash for filename to handle any URL structure.
     */
    private static function _get_cache_path(string $url, string $type): string
    {
        $cache_dir = self::_get_cache_dir();

        // Create hash-based filename
        // Include enough of the original name for human readability
        $url_parts = parse_url($url);
        $path = $url_parts['path'] ?? '';
        $basename = pathinfo($path, PATHINFO_FILENAME);

        // Clean basename for filesystem
        $clean_basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename);
        $clean_basename = substr($clean_basename, 0, 50); // Limit length

        // Hash for uniqueness
        $hash = substr(md5($url), 0, 12);

        return "{$cache_dir}/{$clean_basename}_{$hash}.{$type}";
    }

    /**
     * Download a CDN asset and cache it
     */
    private static function _download_and_cache(string $url, string $type): string
    {
        $cache_dir = self::_get_cache_dir();

        // Ensure cache directory exists
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }

        // Download content
        $content = self::_download($url);

        if ($content === false || $content === '') {
            throw new RuntimeException("Failed to download CDN asset: {$url}");
        }

        // For CSS files, inline all url() references (fonts, images, etc.)
        if ($type === 'css') {
            $content = self::_inline_css_urls($content, $url);
        }

        // Add source comment header
        $header = "/* CDN Source: {$url} */\n";
        $content = $header . $content;

        // Ensure content ends with newline for clean concatenation
        if (!str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        // Write to cache
        $cache_path = self::_get_cache_path($url, $type);
        file_put_contents_safe($cache_path, $content);

        return $content;
    }

    /**
     * Inline CSS url() references as base64 data URIs
     *
     * Uses Node.js postcss-url to properly parse CSS and resolve/inline
     * all url() references (fonts, images, etc.) from the CDN.
     *
     * @param string $css_content The raw CSS content
     * @param string $base_url The CDN URL the CSS was downloaded from
     * @return string CSS with all url() references inlined as data URIs
     * @throws RuntimeException if any referenced asset fails to download
     */
    private static function _inline_css_urls(string $css_content, string $base_url): string
    {
        $cache_dir = self::_get_cache_dir();

        // Write CSS to temp file for processing
        $temp_input = $cache_dir . '/temp_css_input_' . md5($base_url) . '.css';
        $temp_output = $cache_dir . '/temp_css_output_' . md5($base_url) . '.css';

        file_put_contents_safe($temp_input, $css_content);

        // Run the inline-css-urls.js script
        $script = base_path('app/RSpade/Core/Bundle/resource/inline-css-urls.js');

        $cmd = sprintf(
            'node %s %s %s %s 2>&1',
            escapeshellarg($script),
            escapeshellarg($base_url),
            escapeshellarg($temp_input),
            escapeshellarg($temp_output)
        );

        // Use shell_exec with exit code capture
        $full_cmd = "({$cmd}); echo \$?";
        $result = shell_exec('bash -c ' . escapeshellarg($full_cmd));

        // Clean up temp input
        @unlink($temp_input);

        // Parse exit code from last line
        $lines = explode("\n", trim($result ?? ''));
        $exit_code = (int) array_pop($lines);
        $output = implode("\n", $lines);

        if ($exit_code !== 0) {
            // Clean up temp output if it exists
            @unlink($temp_output);

            throw new RuntimeException(
                "Failed to inline CSS URLs for {$base_url}:\n{$output}"
            );
        }

        // Read processed CSS
        if (!file_exists($temp_output)) {
            throw new RuntimeException(
                "CSS URL inlining did not produce output for {$base_url}"
            );
        }

        $result = file_get_contents($temp_output);

        // Clean up temp output
        @unlink($temp_output);

        return $result;
    }

    /**
     * Download URL content using curl
     */
    private static function _download(string $url): string|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'RSpade/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || $content === false) {
            return false;
        }

        return $content;
    }

    /**
     * Clear the CDN cache
     */
    public static function clear(): void
    {
        $cache_dir = self::_get_cache_dir();

        if (!is_dir($cache_dir)) {
            return;
        }

        $files = glob("{$cache_dir}/*.js") + glob("{$cache_dir}/*.css");
        foreach ($files as $file) {
            unlink($file);
        }
    }

    /**
     * Get all cached files
     *
     * @return array Array of ['path' => string, 'url' => string, 'type' => string]
     */
    public static function get_cached_files(): array
    {
        $cache_dir = self::_get_cache_dir();

        if (!is_dir($cache_dir)) {
            return [];
        }

        $files = [];
        foreach (glob("{$cache_dir}/*") as $file) {
            if (is_file($file)) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                if (in_array($ext, ['js', 'css'])) {
                    // Try to extract URL from file header
                    $content = file_get_contents($file, false, null, 0, 500);
                    $url = '';
                    if (preg_match('/CDN Source: (.+?) \*/', $content, $matches)) {
                        $url = $matches[1];
                    }
                    $files[] = [
                        'path' => $file,
                        'url' => $url,
                        'type' => $ext,
                    ];
                }
            }
        }

        return $files;
    }
}
