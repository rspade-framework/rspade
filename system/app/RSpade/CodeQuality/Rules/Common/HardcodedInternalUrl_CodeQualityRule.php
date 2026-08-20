<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 *
 * @ROUTE-EXISTS-01-EXCEPTION - This file generates code templates with placeholder route names
 */

namespace App\RSpade\CodeQuality\Rules\Common;

use Illuminate\Support\Facades\Route;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Manifest\Manifest;

/**
 * HardcodedInternalUrlRule - Detect hardcoded internal URLs in href attributes
 *
 * This rule scans .blade.php and .jqhtml files for href attributes containing
 * hardcoded internal routes (URLs starting with "/" without file extensions)
 * and suggests using the proper route generation methods instead.
 */
class HardcodedInternalUrl_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique identifier for this rule
     *
     * @return string
     */
    public function get_id(): string
    {
        return 'URL-HARDCODE-01';
    }

    /**
     * Get the default severity level
     *
     * @return string One of: critical, high, medium, low, convention
     */
    public function get_default_severity(): string
    {
        return 'medium';
    }

    /**
     * Get the file patterns this rule applies to
     *
     * @return array
     */
    public function get_file_patterns(): array
    {
        return ['*.blade.php', '*.jqhtml'];
    }

    /**
     * Get the display name for this rule
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'Hardcoded Internal URL Detection';
    }

    /**
     * Get the description of what this rule checks
     *
     * @return string
     */
    public function get_description(): string
    {
        return 'Detects hardcoded internal URLs in href attributes and suggests using route generation methods';
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
        // Initialize manifest to ensure routes are available
        try {
            Manifest::init();
        } catch (\Exception $e) {
            // If manifest fails to initialize, we can't check routes
            return;
        }

        $is_jqhtml = str_ends_with($file_path, '.jqhtml');
        $lines = explode("\n", $contents);

        foreach ($lines as $line_num => $line) {
            // Find all href attributes in the line
            // Match href="..." or href='...'
            if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/', $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $match) {
                    $url = $match[0];
                    $position = $match[1];

                    // Check if this is a likely internal route
                    if (!$this->_is_likely_internal_route($url)) {
                        continue;
                    }

                    // Extract base URL and query params
                    $url_parts = parse_url($url);
                    $base_url = $url_parts['path'] ?? '/';
                    $query_string = $url_parts['query'] ?? '';

                    // Try to resolve the URL to a route
                    $route_info = null;
                    try {
                        $route_info = Dispatcher::resolve_url_to_route($base_url, 'GET');
                    } catch (\Exception $e) {
                        // URL doesn't resolve to a known route
                        continue;
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

                        // Merge all params (route params take precedence)
                        $all_params = array_merge($query_params, $route_params);

                        // Extract just the class name without namespace
                        $class_parts = explode('\\', $controller_class);
                        $class_name = end($class_parts);

                        // Generate the suggested replacement code
                        $suggested_code = $this->_generate_suggested_code(
                            $class_name,
                            $method_name,
                            $all_params,
                            $is_jqhtml
                        );
                    } else {
                        // Check if it's a Laravel route
                        $laravel_route = $this->_find_laravel_route($base_url);
                        if ($laravel_route) {
                            $suggested_code = $this->_generate_laravel_suggestion($laravel_route, $query_string, $is_jqhtml);
                        } else {
                            // No route found, skip
                            continue;
                        }
                    }

                    // Add violation
                    $this->add_violation(
                        $line_num + 1,
                        $position,
                        "Hardcoded internal URL detected: {$url}",
                        $line,
                        "Use route generation instead:\n{$suggested_code}"
                    );
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

        // Allow exactly "/" (root/home URL) - common and acceptable
        if ($url === '/') {
            return false;
        }

        // Skip absolute URLs (with protocol)
        if (preg_match('#^//#', $url)) {
            return false;
        }

        // Extract path before query string
        $path = strtok($url, '?');

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
     * Generate suggested replacement code
     *
     * @param string $class_name
     * @param string $method_name
     * @param array $params
     * @param bool $is_jqhtml
     * @return string
     */
    protected function _generate_suggested_code(string $class_name, string $method_name, array $params, bool $is_jqhtml): string
    {
        if ($is_jqhtml) {
            // JavaScript version for .jqhtml files using <%= %> syntax
            if (empty($params)) {
                return "<%= Rsx.Route('{$class_name}::{$method_name}') %>";
            } else {
                $params_json = json_encode($params, JSON_UNESCAPED_SLASHES);
                return "<%= Rsx.Route('{$class_name}::{$method_name}', {$params_json}) %>";
            }
        } else {
            // PHP version for .blade.php files
            if (empty($params)) {
                return "{{ Rsx::Route('{$class_name}::{$method_name}') }}";
            } else {
                $params_str = $this->_format_php_array($params);
                return "{{ Rsx::Route('{$class_name}::{$method_name}', {$params_str}) }}";
            }
        }
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
     * Generate Laravel route suggestion
     *
     * @param string $route_name
     * @param string $query_string
     * @param bool $is_jqhtml
     * @return string
     */
    protected function _generate_laravel_suggestion(string $route_name, string $query_string, bool $is_jqhtml): string
    {
        // If route_name starts with /, it means no named route exists
        if (str_starts_with($route_name, '/')) {
            // Suggest adding a name to the route
            $suggested_name = $this->_suggest_route_name($route_name);

            if ($is_jqhtml) {
                return "<%= '{$route_name}' %> <!-- Add name to route: ->name('{$suggested_name}'), then use route('{$suggested_name}') -->";
            } else {
                return "{{ route('{$suggested_name}') }}\n// First add ->name('{$suggested_name}') to the route definition in routes/web.php";
            }
        }

        // Route has a name, use it
        if ($is_jqhtml) {
            // JavaScript version for .jqhtml files
            if ($query_string) {
                $query_params = [];
                parse_str($query_string, $query_params);
                $params_json = json_encode($query_params, JSON_UNESCAPED_SLASHES);
                // Note: jqhtml would need a custom helper for Laravel routes
                return "<%= route('{$route_name}', {$params_json}) %> <!-- Requires custom route() helper -->";
            } else {
                return "<%= route('{$route_name}') %> <!-- Requires custom route() helper -->";
            }
        } else {
            // PHP version for .blade.php files
            if ($query_string) {
                $query_params = [];
                parse_str($query_string, $query_params);
                $params_str = $this->_format_php_array($query_params);
                return "{{ route('{$route_name}', {$params_str}) }}";
            } else {
                return "{{ route('{$route_name}') }}";
            }
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
}