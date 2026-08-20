<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 *
 * @ROUTE-EXISTS-01-EXCEPTION - This file generates code templates with placeholder route names
 */

namespace App\RSpade\CodeQuality\Rules\PHP;

use Illuminate\Support\Facades\Route;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Manifest\Manifest;

/**
 * HardcodedUrlInRedirectRule - Detect hardcoded URLs in redirect responses
 *
 * This rule scans PHP controller files for redirect() calls with hardcoded
 * internal URLs and suggests using proper route generation methods instead.
 */
class HardcodedUrlInRedirect_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique identifier for this rule
     *
     * @return string
     */
    public function get_id(): string
    {
        return 'PHP-REDIRECT-01';
    }

    /**
     * Get the default severity level
     *
     * @return string One of: critical, high, medium, low, convention
     */
    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Get the file patterns this rule applies to
     *
     * @return array
     */
    public function get_file_patterns(): array
    {
        return ['*_controller.php', '*_Controller.php', '*Controller.php'];
    }

    /**
     * Get the display name for this rule
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'Hardcoded URL in Redirect';
    }

    /**
     * Get the description of what this rule checks
     *
     * @return string
     */
    public function get_description(): string
    {
        return 'Detects hardcoded URLs in redirect() responses and suggests using route generation';
    }

    /**
     * Check the file contents for violations
     *
     * @param string $file_path The path to the file being checked
     * @param string $contents The contents of the file
     * @param array $metadata Additional metadata about the file
     * @return void
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check PHP controller files
        if (!str_ends_with($file_path, '.php')) {
            return;
        }

        // Initialize manifest to ensure routes are available
        try {
            Manifest::init();
        } catch (\Exception $e) {
            // If manifest fails to initialize, we can't check routes
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($lines as $line_num => $line) {
            // Skip commented lines
            if (preg_match('/^\s*\/\//', $line) || preg_match('/^\s*\*/', $line)) {
                continue;
            }

            // Look for redirect patterns with hardcoded URLs
            // Patterns to match:
            // redirect('/path')
            // redirect()->to('/path')
            // Redirect::to('/path')
            // redirect()->route('/path') - incorrect usage
            $patterns = [
                '/\bredirect\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
                '/\bredirect\s*\(\s*\)\s*->\s*to\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
                '/\bRedirect\s*::\s*to\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
                '/\bredirect\s*\(\s*\)\s*->\s*route\s*\(\s*[\'"]\/([^\'"]+)[\'"]\s*\)/', // Catch misuse of route()
            ];

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[1] as $match) {
                        $url = $match[0];
                        $position = $match[1];

                        // For the route() misuse pattern, add back the leading /
                        if (strpos($pattern, '->route') !== false) {
                            $url = '/' . $url;
                        }

                        // Check if this is a likely internal route
                        if (!$this->_is_likely_internal_route($url)) {
                            continue;
                        }

                        // Extract base URL and query params
                        $url_parts = parse_url($url);
                        $base_url = $url_parts['path'] ?? '/';
                        $query_string = $url_parts['query'] ?? '';

                        // Try to resolve to RSX route first
                        $route_info = null;
                        try {
                            $route_info = Dispatcher::resolve_url_to_route($base_url, 'GET');
                        } catch (\Exception $e) {
                            // URL doesn't resolve to an RSX route
                        }

                        $suggested_code = '';

                        if ($route_info) {
                            // Found RSX route
                            $controller_class = $route_info['class'] ?? '';
                            $method_name = $route_info['method'] ?? '';
                            $route_params = $route_info['params'] ?? [];

                            // Parse query string params
                            $query_params = [];
                            if ($query_string) {
                                parse_str($query_string, $query_params);
                            }

                            // Merge all params
                            $all_params = array_merge($query_params, $route_params);

                            // Extract just the class name without namespace
                            $class_parts = explode('\\', $controller_class);
                            $class_name = end($class_parts);

                            $suggested_code = $this->_generate_rsx_suggestion($class_name, $method_name, $all_params);
                        } else {
                            // Check if it's a Laravel route
                            $laravel_route = $this->_find_laravel_route($base_url);
                            if ($laravel_route) {
                                $suggested_code = $this->_generate_laravel_suggestion($laravel_route, $query_string);
                            } else {
                                // No route found, skip
                                continue;
                            }
                        }

                        // Add violation
                        $this->add_violation(
                            $line_num + 1,
                            $position,
                            "Hardcoded URL in redirect: {$url}",
                            $line,
                            "Use route generation instead:\n{$suggested_code}"
                        );
                    }
                }
            }
        }
    }

    /**
     * Check if a URL is likely an internal route
     *
     * @param string $url
     * @return bool
     */
    protected function _is_likely_internal_route(string $url): bool
    {
        // Must start with /
        if (!str_starts_with($url, '/')) {
            return false;
        }

        // Skip absolute URLs (with protocol)
        if (preg_match('#^//#', $url)) {
            return false;
        }

        // Extract path before query string
        $path = strtok($url, '?');

        // Allow root path "/" - common for "go to homepage"
        if ($path === '/') {
            return false;
        }

        // Get the last segment of the path
        $segments = explode('/', trim($path, '/'));
        $last_segment = end($segments);

        // If last segment has a dot (file extension), it's likely a file not a route
        if ($last_segment && str_contains($last_segment, '.')) {
            return false;
        }

        // Skip common static asset paths
        $static_prefixes = ['/assets/', '/css/', '/js/', '/images/', '/img/', '/fonts/', '/storage/'];
        foreach ($static_prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find Laravel route by URL
     *
     * @param string $url
     * @return string|null Route name if found
     */
    protected function _find_laravel_route(string $url): ?string
    {
        // Get all Laravel routes
        $routes = Route::getRoutes();

        foreach ($routes as $route) {
            // Check if URL matches this route's URI
            if ($route->uri() === ltrim($url, '/')) {
                // Get the route name if it has one
                $name = $route->getName();
                if ($name) {
                    return $name;
                }
                // No name, but route exists - return the URI for direct use
                return $url;
            }
        }

        return null;
    }

    /**
     * Generate RSX route suggestion
     *
     * @param string $class_name
     * @param string $method_name
     * @param array $params
     * @return string
     */
    protected function _generate_rsx_suggestion(string $class_name, string $method_name, array $params): string
    {
        if (empty($params)) {
            return "return redirect(Rsx::Route('{$class_name}::{$method_name}'));";
        } else {
            $params_str = $this->_format_php_array($params);
            return "return redirect(Rsx::Route('{$class_name}::{$method_name}', {$params_str}));";
        }
    }

    /**
     * Generate Laravel route suggestion
     *
     * @param string $route_name
     * @param string $query_string
     * @return string
     */
    protected function _generate_laravel_suggestion(string $route_name, string $query_string): string
    {
        // If route_name starts with /, it means no named route exists
        if (str_starts_with($route_name, '/')) {
            // Suggest adding a name to the route
            $suggested_name = $this->_suggest_route_name($route_name);
            return "return redirect(route('{$suggested_name}'));\n// First add ->name('{$suggested_name}') to the route definition in routes/web.php";
        }

        // Route has a name, use it
        if ($query_string) {
            $query_params = [];
            parse_str($query_string, $query_params);
            $params_str = $this->_format_php_array($query_params);
            return "return redirect(route('{$route_name}', {$params_str}));";
        } else {
            return "return redirect(route('{$route_name}'));";
        }
    }

    /**
     * Suggest a route name based on the URL path
     *
     * @param string $url
     * @return string
     */
    protected function _suggest_route_name(string $url): string
    {
        // Remove leading slash and convert to dot notation
        $path = ltrim($url, '/');

        // Convert path segments to route name
        // /test-bundle-facade => test.bundle.facade
        // /_idehelper => idehelper
        $path = str_replace('_', '', $path); // Remove leading underscores
        $path = str_replace('-', '.', $path); // Convert dashes to dots
        $path = str_replace('/', '.', $path); // Convert slashes to dots

        return $path ?: 'home';
    }

    /**
     * Format a PHP array for display
     *
     * @param array $params
     * @return string
     */
    protected function _format_php_array(array $params): string
    {
        $items = [];
        foreach ($params as $key => $value) {
            $key_str = var_export($key, true);
            $value_str = var_export($value, true);
            $items[] = "{$key_str} => {$value_str}";
        }
        return '[' . implode(', ', $items) . ']';
    }
}