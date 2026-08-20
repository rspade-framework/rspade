<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Manifest\Manifest;
use Exception;
use Illuminate\Console\Command;

/**
 * Command to list all RSX routes in table format
 */
class Rsx_Routes_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:routes
                            {--type= : Filter by handler type (controller, api, file, custom)}
                            {--method= : Filter by HTTP method (GET, POST, etc.)}
                            {--path= : Filter by path pattern (supports wildcards)}
                            {--class= : Filter by class name}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all registered RSX routes in greppable format';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get routes from manifest
        $routes = Manifest::get_routes();

        if (empty($routes)) {
            $this->warn('No routes found in manifest.');

            return 0;
        }

        // Collect all routes in a flat array
        $all_routes = $this->collect_routes($routes);

        // Apply filters
        $filtered = $this->filter_routes($all_routes);

        if (empty($filtered)) {
            $this->warn('No routes match the specified filters.');

            return 0;
        }

        // Output as JSON if requested
        if ($this->option('json')) {
            $this->line(json_encode($filtered, JSON_PRETTY_PRINT));

            return 0;
        }

        // Display routes in greppable format
        foreach ($filtered as $route) {
            $this->display_route($route);
        }

        return 0;
    }

    /**
     * Collect all routes from manifest into flat array
     *
     * @param array $routes
     * @return array
     */
    protected function collect_routes($routes)
    {
        $all_routes = [];

        // New unified structure: $routes[$pattern] => route_data
        foreach ($routes as $pattern => $route_data) {
            // Each route can support multiple HTTP methods
            $methods = $route_data['methods'] ?? ['GET'];

            foreach ($methods as $method) {
                $all_routes[] = [
                    'type' => $route_data['type'] ?? 'standard',
                    'pattern' => $pattern,
                    'method' => $method,
                    'class' => $route_data['class'] ?? 'N/A',
                    'action' => $route_data['method'] ?? 'N/A',
                    'cache' => $this->get_cache_ttl($route_data),
                    'name' => $route_data['name'] ?? null,
                    'file' => $route_data['file'] ?? null,
                    'js_action_class' => $route_data['js_action_class'] ?? null,
                ];
            }
        }

        // Custom sort: hierarchical path segments with special rules
        usort($all_routes, function ($a, $b) {
            return $this->compare_routes($a['pattern'], $b['pattern']);
        });

        return $all_routes;
    }

    /**
     * Filter routes based on command options
     *
     * @param array $routes
     * @return array
     */
    protected function filter_routes($routes)
    {
        $filtered = $routes;

        // Filter by type
        $type = $this->option('type');
        if ($type) {
            $filtered = array_filter($filtered, function ($route) use ($type) {
                return strcasecmp($route['type'], $type) === 0;
            });
        }

        // Filter by method
        $method = $this->option('method');
        if ($method) {
            $filtered = array_filter($filtered, function ($route) use ($method) {
                return strcasecmp($route['method'], $method) === 0;
            });
        }

        // Filter by path pattern
        $path = $this->option('path');
        if ($path) {
            $pattern = str_replace('*', '.*', $path);
            $filtered = array_filter($filtered, function ($route) use ($pattern) {
                return preg_match('#' . $pattern . '#i', $route['pattern']);
            });
        }

        // Filter by class
        $class = $this->option('class');
        if ($class) {
            $filtered = array_filter($filtered, function ($route) use ($class) {
                return stripos($route['class'], $class) !== false;
            });
        }

        return array_values($filtered);
    }

    /**
     * Compare two route patterns for hierarchical sorting
     *
     * @param string $a First route pattern
     * @param string $b Second route pattern
     * @return int
     */
    protected function compare_routes($a, $b)
    {
        // Split paths into segments
        $segments_a = explode('/', trim($a, '/'));
        $segments_b = explode('/', trim($b, '/'));

        // Compare segment by segment
        $max = max(count($segments_a), count($segments_b));

        for ($i = 0; $i < $max; $i++) {
            $seg_a = $segments_a[$i] ?? '';
            $seg_b = $segments_b[$i] ?? '';

            // If one path ends here (empty segment), it comes first
            if ($seg_a === '' && $seg_b !== '') {
                return -1;
            }
            if ($seg_b === '' && $seg_a !== '') {
                return 1;
            }

            // Both segments exist - check if they're parameters
            $is_param_a = str_starts_with($seg_a, ':');
            $is_param_b = str_starts_with($seg_b, ':');

            // Non-parameters come before parameters
            if (!$is_param_a && $is_param_b) {
                return -1;
            }
            if ($is_param_a && !$is_param_b) {
                return 1;
            }

            // Both same type (both params or both not), compare alphabetically
            $cmp = strcmp($seg_a, $seg_b);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    /**
     * Display a single route in greppable key-value format
     *
     * @param array $route
     */
    protected function display_route($route)
    {
        $parts = [];

        // Build key-value pairs
        $parts[] = 'path=' . $route['pattern'];
        $parts[] = 'method=' . $route['method'];
        $parts[] = 'type=' . $route['type'];
        $parts[] = 'controller=' . class_basename($route['class']);
        $parts[] = 'action=' . $route['action'];

        // File path is now directly in route data
        if (!empty($route['file'])) {
            $parts[] = 'file=' . $route['file'];
        }

        // Add SPA-specific fields
        if (!empty($route['js_action_class'])) {
            $parts[] = 'js_action=' . $route['js_action_class'];
        }

        // Add optional fields if present
        if ($route['cache']) {
            $parts[] = 'cache=' . $route['cache'] . 's';
        }
        if ($route['name']) {
            $parts[] = 'name=' . $route['name'];
        }

        $this->line(implode(' ', $parts));
    }

    /**
     * Get cache TTL from route data
     *
     * @param array $route_data
     * @return int|null
     */
    protected function get_cache_ttl($route_data)
    {
        // Cache info would be in route metadata if it exists
        // Currently not tracked in new structure
        return null;
    }
}
