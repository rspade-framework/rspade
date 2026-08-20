<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Dispatch;

use App\RSpade\Core\Build_Manager;

/**
 * RouteResolver handles route pattern matching and parameter extraction
 *
 * Supports patterns:
 * - /exact/match - Exact URL match
 * - /users/:id - Named parameter
 * - /posts/:year/:month - Multiple parameters
 * - /api/* - Wildcard
 * - /files/:id.:ext - Mixed patterns
 * - /optional/:id? - Optional parameters
 */
class RouteResolver
{
    /**
     * Cache of compiled patterns
     * @var array
     */
    protected static $pattern_cache = [];

    /**
     * Cache key prefix for file-based caching
     */
    const CACHE_PREFIX = 'route_patterns/';

    /**
     * Match a URL against a route pattern
     *
     * @param string $url The URL to match (without query string)
     * @param string $pattern The route pattern
     * @return array|false Array with params on match, false otherwise
     */
    public static function match($url, $pattern)
    {
        // Normalize URL and pattern
        $url = static::__normalize_url($url);
        $pattern = static::__normalize_pattern($pattern);

        // Get compiled regex and parameter names
        $compiled = static::compile_pattern($pattern);

        if (!$compiled) {
            return false;
        }

        // Match URL against regex
        if (preg_match($compiled['regex'], $url, $matches)) {
            // Extract parameters
            $params = static::__extract_params($matches, $compiled['params']);
            return $params;
        }

        return false;
    }

    /**
     * Match URL with query string handling
     *
     * @param string $full_url Full URL including query string
     * @param string $pattern Route pattern
     * @return array|false
     */
    public static function match_with_query($full_url, $pattern)
    {
        // Split URL and query string
        $url_parts = parse_url($full_url);
        $path = $url_parts['path'] ?? '/';

        // Match the path
        $params = static::match($path, $pattern);

        if ($params === false) {
            return false;
        }

        // Parse and merge query string parameters
        // URL route parameters take precedence over GET parameters
        if (isset($url_parts['query'])) {
            parse_str($url_parts['query'], $query_params);
            $params = array_merge($query_params, $params);
        }

        return $params;
    }

    /**
     * Compile a route pattern into regex
     *
     * @param string $pattern The route pattern
     * @return array|null Array with 'regex' and 'params' keys
     */
    public static function compile_pattern($pattern)
    {
        // Check memory cache
        if (isset(self::$pattern_cache[$pattern])) {
            return self::$pattern_cache[$pattern];
        }

        // Check file cache
        $cache_key = self::CACHE_PREFIX . md5($pattern);
        $cached = Build_Manager::get($cache_key, 'cache');

        if ($cached !== null && is_string($cached)) {
            $cached = json_decode($cached, true);
            if ($cached !== null) {
                self::$pattern_cache[$pattern] = $cached;
                return $cached;
            }
        }

        // Extract parameter names
        $param_names = [];

        // First, handle our special syntax before escaping
        // Replace :param and :param? with placeholders
        $placeholder_index = 0;
        $placeholders = [];
        $temp_pattern = preg_replace_callback(
            '/\/:([\\w]+)(\\?)?|:([\\w]+)(\\?)?/',
            function ($matches) use (&$param_names, &$placeholder_index, &$placeholders) {
                // Check if we matched with leading slash or without
                $has_slash = str_starts_with($matches[0], '/');

                if ($has_slash) {
                    // Matched /:param or /:param?
                    $param_name = $matches[1];
                    $is_optional = !empty($matches[2]);
                } else {
                    // Matched :param or :param?
                    $param_name = $matches[3];
                    $is_optional = !empty($matches[4]);
                }

                $param_names[] = $param_name;
                $placeholder = "__PARAM_{$placeholder_index}__";

                if ($is_optional) {
                    // Optional parameter - the slash and parameter are both optional
                    // For /posts/:id? we want to match both /posts and /posts/123
                    $placeholders[$placeholder] = $has_slash ? '(?:/([^/]+))?' : '([^/]*)';
                } else {
                    // Required parameter
                    $placeholders[$placeholder] = $has_slash ? '/([^/]+)' : '([^/]+)';
                }

                $placeholder_index++;
                return $placeholder;
            },
            $pattern,
        );

        // Replace wildcards with placeholders
        $wildcard_index = 0;
        $temp_pattern = preg_replace_callback(
            '/\*/',
            function ($matches) use (&$param_names, &$wildcard_index, &$placeholders) {
                $param_names[] = 'wildcard' . ($wildcard_index > 0 ? $wildcard_index : '');
                $placeholder = "__WILDCARD_{$wildcard_index}__";
                $placeholders[$placeholder] = '(.*)';
                $wildcard_index++;
                return $placeholder;
            },
            $temp_pattern,
        );

        // Now escape regex characters
        $regex = preg_quote($temp_pattern, '#');

        // Replace placeholders with regex patterns
        foreach ($placeholders as $placeholder => $regex_pattern) {
            $regex = str_replace($placeholder, $regex_pattern, $regex);
        }

        // Wrap in delimiters
        $regex = '#^' . $regex . '$#';

        $compiled = [
            'regex' => $regex,
            'params' => $param_names,
            'pattern' => $pattern,
        ];

        // Cache the compiled pattern
        self::$pattern_cache[$pattern] = $compiled;
        Build_Manager::put($cache_key, json_encode($compiled), 'cache');

        return $compiled;
    }

    /**
     * Extract parameters from regex matches
     *
     * @param array $matches Regex matches
     * @param array $param_names Parameter names
     * @return array Extracted parameters
     */
    protected static function __extract_params($matches, $param_names)
    {
        $params = [];

        // Skip first match (full string)
        array_shift($matches);

        // Map matches to parameter names
        foreach ($param_names as $index => $name) {
            if (isset($matches[$index])) {
                $value = $matches[$index];

                // URL decode the value
                $value = urldecode($value);

                // Don't include empty optional parameters
                if ($value !== '') {
                    $params[$name] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * Normalize a URL for matching
     *
     * @param string $url
     * @return string
     */
    protected static function __normalize_url($url)
    {
        // Remove query string
        $url = strtok($url, '?');

        // Ensure leading slash
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }

        // Remove trailing slash (except for root)
        if ($url !== '/' && str_ends_with($url, '/')) {
            $url = rtrim($url, '/');
        }

        return $url;
    }

    /**
     * Normalize a route pattern
     *
     * @param string $pattern
     * @return string
     */
    protected static function __normalize_pattern($pattern)
    {
        // Ensure leading slash
        if (!str_starts_with($pattern, '/')) {
            $pattern = '/' . $pattern;
        }

        // Remove trailing slash (except for root)
        if ($pattern !== '/' && str_ends_with($pattern, '/')) {
            $pattern = rtrim($pattern, '/');
        }

        return $pattern;
    }

    /**
     * Check if a URL matches any of the given patterns
     *
     * @param string $url URL to check
     * @param array $patterns Array of patterns to match against
     * @return array|false First matching result or false
     */
    public static function match_any($url, $patterns)
    {
        foreach ($patterns as $pattern) {
            $result = static::match($url, $pattern);
            if ($result !== false) {
                return ['pattern' => $pattern, 'params' => $result];
            }
        }

        return false;
    }

    /**
     * Clear pattern cache
     *
     * @param string|null $pattern Specific pattern to clear, or null for all
     */
    public static function clear_cache($pattern = null)
    {
        if ($pattern !== null) {
            unset(self::$pattern_cache[$pattern]);
            // We can't delete individual files easily with BuildManager
            // Just clear from memory cache
        } else {
            self::$pattern_cache = [];
            // Clear all cached patterns by clearing the cache directory
            // This is a bit aggressive but BuildManager doesn't have selective deletion
            // In production, patterns are stable so this shouldn't be called often
        }
    }

    /**
     * Generate a URL from a pattern and parameters
     *
     * @param string $pattern Route pattern
     * @param array $params Parameters to fill in
     * @return string|null Generated URL or null if params don't match
     */
    public static function generate($pattern, $params = [])
    {
        $url = $pattern;

        // Replace named parameters
        $url = preg_replace_callback(
            '/:([\w]+)(\?)?/',
            function ($matches) use (&$params) {
                $param_name = $matches[1];
                $is_optional = isset($matches[2]);

                if (isset($params[$param_name])) {
                    $value = $params[$param_name];
                    unset($params[$param_name]);
                    return urlencode($value);
                } elseif ($is_optional) {
                    return '';
                } else {
                    // Required parameter missing
                    return ':' . $param_name;
                }
            },
            $url,
        );

        // Check if all required params were replaced
        if (strpos($url, ':') !== false) {
            return null; // Still has unreplaced parameters
        }

        // Replace wildcards
        if (strpos($url, '*') !== false) {
            // Use 'wildcard' param if available
            if (isset($params['wildcard'])) {
                $url = str_replace('*', $params['wildcard'], $url);
                unset($params['wildcard']);
            } else {
                $url = str_replace('*', '', $url);
            }
        }

        // Normalize the URL first (before adding query string)
        $url = static::__normalize_url($url);

        // Add remaining params as query string
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /**
     * Get priority score for a pattern (more specific = higher priority)
     *
     * @param string $pattern
     * @return int Priority score
     */
    public static function get_pattern_priority($pattern)
    {
        $score = 0;

        // Exact matches have highest priority
        if (!str_contains($pattern, ':') && !str_contains($pattern, '*')) {
            $score += 1000;
        }

        // Count segments
        $segments = explode('/', trim($pattern, '/'));
        $score += count($segments) * 100;

        // Penalize wildcards
        $score -= substr_count($pattern, '*') * 50;

        // Penalize optional parameters
        $score -= substr_count($pattern, '?') * 10;

        // Penalize required parameters
        $score -= preg_match_all('/:([\w]+)(?!\?)/', $pattern) * 5;

        return $score;
    }

    /**
     * Sort patterns by priority (higher priority first)
     *
     * @param array $patterns
     * @return array Sorted patterns
     */
    public static function sort_by_priority($patterns)
    {
        $scored = [];

        foreach ($patterns as $pattern) {
            $scored[] = [
                'pattern' => $pattern,
                'score' => static::get_pattern_priority($pattern),
            ];
        }

        // Sort by score descending
        usort($scored, function ($a, $b) {
            return $b['score'] - $a['score'];
        });

        // Extract sorted patterns
        return array_column($scored, 'pattern');
    }
}
