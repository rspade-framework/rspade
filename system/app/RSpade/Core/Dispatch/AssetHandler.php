<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Dispatch;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\Log;

/**
 * AssetHandler serves static files from RSX public directories
 * 
 * Scans for /rsx/{module}/public/ directories and serves files securely
 * with proper cache headers and MIME types
 * 
 * TODO: Unit Tests for Bundle Serving Behavior
 * =============================================
 * When the framework is more complete, create comprehensive unit tests for:
 * 
 * Development Mode (env('APP_ENV') !== 'production'):
 * -------------------------------------------------------------
 * 1. Bundle filenames are predictable: app.(hash).js where hash = substr(sha256(FQCN), 0, 32)
 *    - Example: FrontendBundle -> app.053cded429c46421aea774afff5fbd8b.js
 * 2. Query parameters added for cache-busting: ?v=(manifest-hash)
 *    - Manifest hash is random on each request (bin2hex(random_bytes(16)))
 * 3. Bundles compiled on-demand when requested (compile_bundle_on_demand method)
 * 4. Cache headers set to no-cache: "Cache-Control: no-cache, no-store, must-revalidate"
 * 5. storage/rsx directory cleared on each request EXCEPT during bundle serving
 * 
 * Production Mode (env('APP_ENV') === 'production'):
 * ----------------------------------------------------------
 * 1. Bundle filenames include manifest hash: app.(hash).js where hash = substr(sha256(manifest_hash . '|' . FQCN), 0, 32)
 *    - Changes when manifest OR bundle content changes
 * 2. No query parameters needed (hash in filename handles cache-busting)
 * 3. Bundles pre-compiled via artisan rsx:bundle:compile
 * 4. Aggressive caching: "Cache-Control: public, max-age=31536000, immutable"
 * 5. ETag headers for cache validation
 * 
 * Test Scenarios:
 * ---------------
 * - Verify filename format validation (regex: /^app\.[a-f0-9]{32}\.(js|css)$/)
 * - Test 404 responses for invalid bundle names
 * - Verify correct MIME types (application/javascript, text/css)
 * - Test that bundles regenerate in development when files change
 * - Verify production bundles remain cached between requests
 * - Test security headers (X-Content-Type-Options: nosniff)
 * - Verify /_compiled/ path routing works correctly
 * - Test that non-existent bundles trigger on-demand compilation in debug mode
 * - Verify that compilation errors are logged but don't throw exceptions
 * 
 * Integration Tests:
 * ------------------
 * - Test full page load with bundle URLs in HTML
 * - Verify jQuery loads before other scripts (prioritization)
 * - Test that window.rsxapp data is correctly formatted (pretty in dev, compact in prod)
 * - Verify manifest hash changes trigger new bundle URLs in production
 * - Test that development mode allows immediate visibility of JS/CSS changes
 */
class AssetHandler
{
    /**
     * Cache of discovered public directories
     * 
     * @var array
     */
    protected static $public_directories = [];
    
    /**
     * Allowed file extensions for security
     * 
     * @var array
     */
    protected static $allowed_extensions = [
        'css', 'js', 'json', 'xml',
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'mp3', 'mp4', 'webm', 'ogg', 'wav',
        'pdf', 'zip', 'txt', 'md',
        'html', 'htm'
    ];
    
    /**
     * MIME type mappings
     * 
     * @var array
     */
    protected static $mime_types = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'otf' => 'font/otf',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'wav' => 'audio/wav',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'html' => 'text/html',
        'htm' => 'text/html'
    ];
    
    /**
     * Whether directories have been discovered
     * @var bool
     */
    protected static $directories_discovered = false;
    
    /**
     * Check if a path is an asset request
     *
     * @param string $path
     * @return bool
     */
    public static function is_asset_request($path)
    {
        // Check if this is a compiled bundle request
        if (str_starts_with($path, '/_compiled/')) {
            // Validate filename format: BundleName__(vendor|app).(8 chars).(js|css) or BundleName__app.(16 chars).(js|css)
            $filename = substr($path, 11); // Remove '/_compiled/'
            return preg_match('/^[A-Za-z0-9_]+__(vendor|app)\.[a-f0-9]{8}\.(js|css)$/', $filename) ||
                   preg_match('/^[A-Za-z0-9_]+__app\.[a-f0-9]{16}\.(js|css)$/', $filename);
        }

        // Check if this is a vendor CDN cache request
        if (str_starts_with($path, '/_vendor/')) {
            // Validate filename format: alphanumeric_hash.(js|css)
            $filename = substr($path, 9); // Remove '/_vendor/'
            return preg_match('/^[A-Za-z0-9_-]+_[a-f0-9]{12}\.(js|css)$/', $filename);
        }

        // Check if path has a file extension
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if (empty($extension)) {
            return false;
        }

        // Check if extension is allowed
        return in_array(strtolower($extension), static::$allowed_extensions);
    }
    
    /**
     * Serve an asset file
     *
     * @param string $path The requested asset path
     * @param Request $request
     * @return Response
     * @throws NotFoundHttpException
     */
    public static function serve($path, Request $request)
    {
        // Handle compiled bundle requests
        if (str_starts_with($path, '/_compiled/')) {
            return static::__serve_compiled_bundle($path, $request);
        }

        // Handle vendor CDN cache requests
        if (str_starts_with($path, '/_vendor/')) {
            return static::__serve_vendor_cdn_cache($path, $request);
        }

        // Ensure directories are discovered
        static::__ensure_directories_discovered();

        // Sanitize path to prevent directory traversal
        $path = static::__sanitize_path($path);

        // Find the file in public directories
        $file_path = static::__find_asset_file($path);

        if (!$file_path) {
            throw new NotFoundHttpException("Asset not found: {$path}");
        }

        // Check if file is within allowed directories
        if (!static::__is_safe_path($file_path)) {
            Log::warning('Attempted directory traversal', [
                'requested' => $path,
                'resolved' => $file_path
            ]);
            throw new NotFoundHttpException("Asset not found: {$path}");
        }

        // Create binary file response
        $response = new BinaryFileResponse($file_path);

        // Set MIME type
        $mime_type = static::__get_mime_type($file_path);
        $response->headers->set('Content-Type', $mime_type);

        // Check if request has cache-busting parameter
        $has_cache_buster = $request->query->has('v');

        // Set cache headers based on cache-busting presence
        static::__set_cache_headers($response, $file_path, $has_cache_buster);

        // Handle conditional requests for non-cache-busted URLs
        if (!$has_cache_buster) {
            // BinaryFileResponse automatically handles If-None-Match and If-Modified-Since
            // and will return 304 Not Modified when appropriate
            $response->isNotModified($request);
        }

        // Set additional security headers
        static::__set_security_headers($response, $mime_type);

        return $response;
    }

    /**
     * Find a public asset by relative path with Redis caching
     *
     * Resolves paths like "sneat/css/demo.css" to full filesystem paths like
     * "rsx/public/sneat/css/demo.css" by scanning all public/ directories.
     *
     * Results are cached in Redis indefinitely. Cached paths are validated
     * before use - if file no longer exists, cache is invalidated and re-scan occurs.
     *
     * @param string $relative_path Relative path like "sneat/css/demo.css"
     * @return string Full filesystem path
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If not found or ambiguous
     */
    public static function find_public_asset(string $relative_path): string
    {
        // Ensure directories are discovered
        static::__ensure_directories_discovered();

        // Sanitize the path
        $relative_path = static::__sanitize_path($relative_path);

        // Check Redis cache first
        $cache_key = 'rspade:public_asset:' . $relative_path;
        $cached_path = Redis::get($cache_key);

        if ($cached_path) {
            // Verify cached file still exists
            if (File::exists($cached_path) && File::isFile($cached_path)) {
                return $cached_path;
            }

            // Stale cache - invalidate and re-scan
            Redis::del($cache_key);
        }

        // NEVER serve PHP files under any circumstances
        $extension = strtolower(pathinfo($relative_path, PATHINFO_EXTENSION));
        if ($extension === 'php') {
            throw new HttpException(403, 'PHP files cannot be served as static assets');
        }

        // Scan all public directories for matches
        $matches = [];

        foreach (static::$public_directories as $module => $directory) {
            $full_path = $directory . '/' . $relative_path;

            if (File::exists($full_path) && File::isFile($full_path)) {
                // Check exclusion rules
                if (static::__is_file_excluded($full_path, $relative_path)) {
                    throw new HttpException(403, 'Access to this file is forbidden');
                }
                $matches[] = $full_path;
            }
        }

        // Check for ambiguous matches
        if (count($matches) > 1) {
            // Show first two matches in error
            $first_two = array_slice($matches, 0, 2);
            throw new HttpException(
                500,
                "Ambiguous public asset request: '{$relative_path}' matches multiple files: '" .
                implode("', '", $first_two) . "'"
            );
        }

        // Check for no matches
        if (count($matches) === 0) {
            throw new NotFoundHttpException("Public asset not found: {$relative_path}");
        }

        // Single match - cache and return
        $resolved_path = $matches[0];
        Redis::set($cache_key, $resolved_path);

        return $resolved_path;
    }

    /**
     * Ensure directories are discovered (lazy initialization)
     */
    protected static function __ensure_directories_discovered()
    {
        if (!static::$directories_discovered) {
            static::__discover_public_directories();
            static::$directories_discovered = true;
        }
    }
    
    /**
     * Discover public directories in RSX modules
     * 
     * @return void
     */
    protected static function __discover_public_directories()
    {
        $rsx_path = base_path('rsx');
        
        if (!File::isDirectory($rsx_path)) {
            return;
        }
        
        // Scan for directories with public subdirectories
        $directories = File::directories($rsx_path);
        
        foreach ($directories as $dir) {
            $public_dir = $dir . '/public';
            
            if (File::isDirectory($public_dir)) {
                $module_name = basename($dir);
                static::$public_directories[$module_name] = $public_dir;
                
                Log::debug('Discovered RSX public directory', [
                    'module' => $module_name,
                    'path' => $public_dir
                ]);
            }
        }
        
        // Also check for a root public directory
        $root_public = $rsx_path . '/public';
        if (File::isDirectory($root_public)) {
            static::$public_directories['_root'] = $root_public;
        }
    }
    
    /**
     * Sanitize path to prevent directory traversal
     * 
     * @param string $path
     * @return string
     */
    protected static function __sanitize_path($path)
    {
        // Remove any .. or ./ sequences
        $path = str_replace(['../', '..\\', './', '.\\'], '', $path);
        
        // Remove duplicate slashes
        $path = preg_replace('#/+#', '/', $path);
        
        // Remove leading slash
        $path = ltrim($path, '/');
        
        return $path;
    }
    
    /**
     * Find asset file in public directories
     *
     * Wrapper around find_public_asset() that returns null instead of throwing
     * NotFoundHttpException for backward compatibility with existing code.
     *
     * @param string $path
     * @return string|null Full file path or null if not found
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException For PHP files, exclusions, or ambiguous matches
     */
    protected static function __find_asset_file($path)
    {
        try {
            return static::find_public_asset($path);
        } catch (NotFoundHttpException $e) {
            // Not found - return null for backward compatibility
            return null;
        }
        // Let other exceptions (403, 500) bubble up
    }
    
    /**
     * Check if a file should be excluded from serving
     *
     * @param string $full_path The full filesystem path to the file
     * @param string $relative_path The relative path requested
     * @return bool True if file should be excluded
     */
    protected static function __is_file_excluded($full_path, $relative_path)
    {
        // Get global exclusion patterns from config
        $global_patterns = config('rsx.public.ignore_patterns', []);

        // Find the public directory this file belongs to
        $public_dir = static::__find_public_directory($full_path);
        if (!$public_dir) {
            return false;
        }

        // Load public_ignore.json if it exists
        $local_patterns = [];
        $ignore_file = $public_dir . '/public_ignore.json';
        if (File::exists($ignore_file)) {
            $json = json_decode(File::get($ignore_file), true);
            if (is_array($json)) {
                $local_patterns = $json;
            }
        }

        // Combine all patterns
        $all_patterns = array_merge($global_patterns, $local_patterns);

        // Check each pattern
        foreach ($all_patterns as $pattern) {
            if (static::__matches_gitignore_pattern($relative_path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find which public directory a file belongs to
     *
     * @param string $full_path
     * @return string|null
     */
    protected static function __find_public_directory($full_path)
    {
        // @REALPATH-EXCEPTION - Security: path traversal prevention requires symlink resolution
        $real_path = realpath($full_path);
        if (!$real_path) {
            return null;
        }

        // Find the public directory that contains this file
        foreach (static::$public_directories as $directory) {
            // @REALPATH-EXCEPTION - Security: path traversal prevention requires symlink resolution
            $real_directory = realpath($directory);
            if ($real_directory && str_starts_with($real_path, $real_directory)) {
                return $real_directory;
            }
        }

        return null;
    }

    /**
     * Check if a path matches a gitignore-style pattern
     *
     * @param string $path The file path to check
     * @param string $pattern The gitignore pattern
     * @return bool
     */
    protected static function __matches_gitignore_pattern($path, $pattern)
    {
        // Normalize path separators
        $path = str_replace('\\', '/', $path);
        $pattern = str_replace('\\', '/', $pattern);

        // Handle negation (not implemented - we only block, never unblock)
        if (str_starts_with($pattern, '!')) {
            return false;
        }

        // Convert gitignore pattern to regex
        $regex = static::__gitignore_to_regex($pattern);

        return (bool) preg_match($regex, $path);
    }

    /**
     * Convert gitignore pattern to regex
     *
     * @param string $pattern
     * @return string
     */
    protected static function __gitignore_to_regex($pattern)
    {
        // Remove leading/trailing whitespace
        $pattern = trim($pattern);

        // Escape special regex characters except * and ?
        $pattern = preg_quote($pattern, '#');

        // Restore * and ? for conversion
        $pattern = str_replace(['\*', '\?'], ['STAR', 'QUESTION'], $pattern);

        // Convert gitignore wildcards to regex
        $pattern = str_replace('STAR', '.*', $pattern);  // * matches anything
        $pattern = str_replace('QUESTION', '.', $pattern);  // ? matches single char

        // If pattern ends with /, it matches directories (any file within)
        if (str_ends_with($pattern, '/')) {
            $pattern = rtrim($pattern, '/');
            $pattern = $pattern . '(?:/.*)?';
        }

        // If pattern doesn't start with /, it can match at any level
        if (!str_starts_with($pattern, '/')) {
            $pattern = '(?:.*/)?'  . $pattern;
        } else {
            $pattern = ltrim($pattern, '/');
        }

        // Anchor pattern
        return '#^' . $pattern . '$#';
    }

    /**
     * Check if path is safe (within allowed directories)
     *
     * @param string $path
     * @return bool
     */
    protected static function __is_safe_path($path)
    {
        // @REALPATH-EXCEPTION - Security: path traversal prevention requires symlink resolution
        $real_path = realpath($path);

        if ($real_path === false) {
            return false;
        }

        // Check if real path is within any allowed directory
        foreach (static::$public_directories as $directory) {
            // @REALPATH-EXCEPTION - Security: path traversal prevention requires symlink resolution
            $real_directory = realpath($directory);

            if (str_starts_with($real_path, $real_directory)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Get MIME type for file
     * 
     * @param string $file_path
     * @return string
     */
    protected static function __get_mime_type($file_path)
    {
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        if (isset(static::$mime_types[$extension])) {
            return static::$mime_types[$extension];
        }
        
        // Try to detect MIME type
        $mime = mime_content_type($file_path);
        
        return $mime ?: 'application/octet-stream';
    }
    
    /**
     * Set cache headers for response
     *
     * @param BinaryFileResponse $response
     * @param string $file_path
     * @param bool $has_cache_buster Whether request has ?v= cache-busting parameter
     * @return void
     */
    protected static function __set_cache_headers(BinaryFileResponse $response, $file_path, $has_cache_buster)
    {
        if ($has_cache_buster) {
            // Aggressive caching - URL has version parameter
            $cache_seconds = 2592000; // 30 days (1 month)

            $response->setMaxAge($cache_seconds);
            $response->setPublic();
            $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $cache_seconds) . ' GMT');
            $response->headers->set('Cache-Control', 'public, max-age=' . $cache_seconds . ', immutable');

            // No revalidation headers needed - file is cache-busted via ?v= parameter
        } else {
            // Conservative caching with revalidation - no version parameter
            $cache_seconds = 300; // 5 minutes

            $response->setMaxAge($cache_seconds);
            $response->setPublic();
            $response->headers->set('Cache-Control', 'public, max-age=' . $cache_seconds . ', must-revalidate');

            // Set ETag and Last-Modified for efficient revalidation
            // BinaryFileResponse automatically sets these based on file mtime and size
            $response->setAutoEtag();
            $response->setAutoLastModified();
        }
    }
    
    /**
     * Set security headers
     * 
     * @param BinaryFileResponse $response
     * @param string $mime_type
     * @return void
     */
    protected static function __set_security_headers(BinaryFileResponse $response, $mime_type)
    {
        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        
        // Set CSP for HTML files
        if (str_starts_with($mime_type, 'text/html')) {
            $response->headers->set('Content-Security-Policy', "default-src 'self'");
        }
        
        // Prevent framing for HTML
        if (str_starts_with($mime_type, 'text/html')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
    }
    
    /**
     * Serve a compiled bundle file
     *
     * @param string $path The requested path (e.g., /_compiled/Demo_Bundle__vendor.abc12345.js)
     * @param Request $request
     * @return Response
     * @throws NotFoundHttpException
     */
    protected static function __serve_compiled_bundle($path, Request $request)
    {
        // Extract filename from path
        $filename = substr($path, 11); // Remove '/_compiled/'

        // Validate filename format: BundleName__(vendor|app).(8 chars).(js|css) or BundleName__app.(16 chars).(js|css)
        if (!preg_match('/^([A-Za-z0-9_]+)__(vendor|app)\.([a-f0-9]{8}|[a-f0-9]{16})\.(js|css)$/', $filename, $matches)) {
            throw new NotFoundHttpException("Invalid bundle filename: {$filename}");
        }

        $bundle_name = $matches[1];
        $type = $matches[2];
        $hash = $matches[3];
        $extension = $matches[4];

        // Build full path to file
        $file_path = storage_path("rsx-build/bundles/{$filename}");

        // In development mode, compile bundle on-the-fly if it doesn't exist
        if (env('APP_ENV') !== 'production' && !file_exists($file_path)) {
            // Try to compile the bundle on-demand
            static::__compile_bundle_on_demand($bundle_name, $type, $hash, $extension);
        }

        // Check if file exists
        if (!file_exists($file_path)) {
            throw new NotFoundHttpException("Bundle not found: {$filename}");
        }
        
        // Create binary file response
        $response = new BinaryFileResponse($file_path);
        
        // Set appropriate content type
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $mime_type = $extension === 'js' ? 'application/javascript' : 'text/css';
        $response->headers->set('Content-Type', $mime_type . '; charset=utf-8');
        
        // Set cache headers - 14 day cache for all compiled bundles (same for dev and prod)
        // Since we use cache-busting in filenames/query strings, we can cache aggressively
        $cache_seconds = 1209600; // 14 days
        $response->headers->set('Cache-Control', 'public, max-age=' . $cache_seconds . ', immutable');
        $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $cache_seconds) . ' GMT');

        // No ETag or revalidation needed - files are cache-busted via filename/query string
        
        // Security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Serve a cached CDN vendor file
     *
     * Serves files from the CDN cache directory (rsx/resource/.cdn-cache/)
     * with strict filename validation to prevent path traversal.
     *
     * @param string $path The requested path (e.g., /_vendor/lodash_abc123456789.js)
     * @param Request $request
     * @return Response
     * @throws NotFoundHttpException
     */
    protected static function __serve_vendor_cdn_cache($path, Request $request)
    {
        // Extract filename from path
        $filename = substr($path, 9); // Remove '/_vendor/'

        // Strict filename validation - only allow safe characters
        // Format: name_hash.ext where name is alphanumeric/underscore/hyphen, hash is 12 hex chars
        if (!preg_match('/^[A-Za-z0-9_-]+_[a-f0-9]{12}\.(js|css)$/', $filename)) {
            throw new NotFoundHttpException("Invalid vendor filename: {$filename}");
        }

        // Additional safety checks - no path traversal characters
        if (str_contains($filename, '..') || str_contains($filename, './') || str_contains($filename, '/')) {
            Log::warning('Attempted path traversal in vendor request', [
                'filename' => $filename
            ]);
            throw new NotFoundHttpException("Invalid vendor filename: {$filename}");
        }

        // Get the CDN cache directory from the Cdn_Cache class
        $cache_dir = \App\RSpade\Core\Bundle\Cdn_Cache::get_cache_directory();

        // Build full path - filename is already validated as safe
        $file_path = $cache_dir . '/' . $filename;

        // Check if file exists.
        //
        // In a mirroring (sealed) mode this is NOT a 404: the page asked for a file the
        // build was supposed to have mirrored, so the mirror is incomplete or was deleted.
        // Fail LOUD naming the file and the remedy - never silently re-download at request
        // time (Cdn_Cache refuses that too), and never let a broken build look like a
        // missing page.
        if (!file_exists($file_path)) {
            if (\App\RSpade\Core\Manifest\Manifest::_should_cache_cdn()) {
                throw new \RuntimeException(
                    "Missing mirrored external asset: {$filename}\n" .
                    "  Expected in: " . \App\RSpade\Core\Bundle\Cdn_Cache::get_cache_directory() . "\n" .
                    "  This build serves external assets from its own /_vendor/ mirror and never\n" .
                    "  downloads at request time.\n" .
                    '  Remedy: php artisan rsx:prod:refresh'
                );
            }

            throw new NotFoundHttpException("Vendor file not found: {$filename}");
        }

        // Verify the file is actually in the cache directory (belt and suspenders)
        // @REALPATH-EXCEPTION - Security: path traversal prevention requires symlink resolution
        $real_file = realpath($file_path);
        $real_cache_dir = realpath($cache_dir);

        if (!$real_file || !$real_cache_dir || !str_starts_with($real_file, $real_cache_dir)) {
            Log::warning('Vendor file path traversal attempt', [
                'filename' => $filename,
                'resolved' => $real_file
            ]);
            throw new NotFoundHttpException("Vendor file not found: {$filename}");
        }

        // Create binary file response
        $response = new BinaryFileResponse($file_path);

        // Set appropriate content type
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $mime_type = $extension === 'js' ? 'application/javascript' : 'text/css';
        $response->headers->set('Content-Type', $mime_type . '; charset=utf-8');

        // Set cache headers - 1 month cache for vendor CDN files
        // These files are versioned by their content hash in the filename
        $cache_seconds = 2592000; // 30 days (1 month)
        $response->headers->set('Cache-Control', 'public, max-age=' . $cache_seconds . ', immutable');
        $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $cache_seconds) . ' GMT');

        // Security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Compile a bundle on-demand in development mode
     *
     * @param string $bundle_name The bundle name from the requested filename
     * @param string $type The bundle type (vendor or app)
     * @param string $hash The hash from the requested filename
     * @param string $extension The file extension (js or css)
     * @return void
     */
    protected static function __compile_bundle_on_demand($bundle_name, $type, $hash, $extension)
    {
        // Find the bundle class matching the bundle name
        try {
            // Try to find by simple class name
            $metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_class($bundle_name);
            if (!isset($metadata['fqcn'])) {
                return;
            }
            $fqcn = $metadata['fqcn'];

            // Compile the bundle
            try {
                $compiler = new \App\RSpade\Core\Bundle\BundleCompiler();
                $compiler->compile($fqcn);
                return;
            } catch (\Exception $e) {
                // Log error but don't throw - let the file not found error happen
                \Illuminate\Support\Facades\Log::error("Failed to compile bundle on-demand: {$fqcn}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } catch (\RuntimeException $e) {
            // Bundle class not found in manifest
            return;
        }
    }
    
    /**
     * Get discovered public directories
     * 
     * @return array
     */
    public static function get_public_directories()
    {
        static::__ensure_directories_discovered();
        return static::$public_directories;
    }
    
    /**
     * Clear and rediscover public directories
     * 
     * @return void
     */
    public static function refresh_directories()
    {
        static::$public_directories = [];
        static::$directories_discovered = false;
        static::__discover_public_directories();
        static::$directories_discovered = true;
    }
}