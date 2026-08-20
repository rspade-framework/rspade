<?php

namespace App\RSpade\Core\Debug;

use Exception;
use JsonSerializable;
use Log;
use stdClass;

/**
 * Debugger - Development and debugging utilities for the RSpade framework
 *
 * Static utility class providing debugging and development tools.
 * All methods are static as per framework convention.
 */
class Debugger
{
    /**
     * POSIX signal USR1 (used to tell nginx to reopen log files)
     */
    public const SIGUSR1 = 10;

    /**
     * Array to batch console debug messages for HTTP output
     */
    protected static array $console_messages = [];

    /**
     * Flag to track if shutdown function has been registered
     */
    protected static bool $shutdown_registered = false;

    /**
     * Flag to disable console HTML output (for AJAX requests)
     */
    protected static bool $console_html_disabled = false;

    /**
     * Request start time for benchmarking
     */
    protected static ?float $request_start_time = null;

    /**
     * Cached console debug configuration
     */
    protected static ?array $console_config = null;

    /**
     * Flag to track if trace mode is enabled
     */
    protected static bool $trace_mode = false;

    /**
     * Rotate all development logs
     *
     * Rotates:
     * - Laravel log (storage/logs/laravel.log)
     * - Nginx access log (/var/log/nginx/access.log)
     * - Nginx error log (/var/log/nginx/error.log)
     *
     * @param int $keep_versions Number of historical versions to keep (default 5)
     * @return void
     */
    public static function logrotate(int $keep_versions = 5): void
    {
        // Do nothing in production mode
        if (env('APP_ENV') === 'production') {
            return;
        }

        console_debug('DEV_MODE', 'Rotating logs');

        // Rotate Laravel log
        static::__rotate_file(storage_path('logs/laravel.log'), $keep_versions);

        // Rotate nginx logs
        static::__rotate_file('/var/log/nginx/access.log', $keep_versions);
        static::__rotate_file('/var/log/nginx/error.log', $keep_versions);

        // Send USR1 signal to nginx to reopen log files
        static::__signal_nginx_reopen_logs();
    }

    /**
     * Rotate a single log file
     *
     * Works like logrotate:
     * - Moves file to file.1
     * - Moves file.1 to file.2, etc.
     * - Keeps only the specified number of historical versions
     *
     * @param string $file_path Path to the file to rotate
     * @param int $keep_versions Number of historical versions to keep
     * @return void
     */
    protected static function __rotate_file(string $file_path, int $keep_versions): void
    {
        // If the file doesn't exist, nothing to rotate
        if (!file_exists($file_path)) {
            return;
        }

        // Delete the oldest log if it exists
        $oldest_log = $file_path . '.' . $keep_versions;
        if (file_exists($oldest_log)) {
            @unlink($oldest_log);
        }

        // Rotate existing numbered logs (move .4 to .5, .3 to .4, etc.)
        for ($i = $keep_versions - 1; $i >= 1; $i--) {
            $old_file = $file_path . '.' . $i;
            $new_file = $file_path . '.' . ($i + 1);

            if (file_exists($old_file)) {
                @rename($old_file, $new_file);
            }
        }

        // Move the current log to .1
        @rename($file_path, $file_path . '.1');

        // Create a new empty log file
        @touch($file_path);
        @chmod($file_path, 0666);
    }

    /**
     * Send USR1 signal to nginx to reopen log files
     *
     * nginx reopens log files when receiving USR1 signal,
     * which is the standard way to handle log rotation.
     *
     * @return void
     */
    protected static function __signal_nginx_reopen_logs(): void
    {
        // Try to find nginx master process
        $pid_file = '/var/run/nginx.pid';

        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid && is_numeric($pid)) {
                // Send USR1 signal to nginx master process
                @posix_kill((int)$pid, self::SIGUSR1);
            }
        } else {
            // Try to find nginx process via ps
            $output = [];
            @\exec_safe('ps aux | grep "nginx: master" | grep -v grep', $output);
            if (!empty($output)) {
                // Extract PID from ps output
                $parts = preg_split('/\s+/', trim($output[0]));
                if (isset($parts[1]) && is_numeric($parts[1])) {
                    @posix_kill((int)$parts[1], self::SIGUSR1);
                }
            }
        }
    }

    /**
     * Debug output with detailed information
     *
     * Outputs debug information including:
     * - File and line where rsx_dump_die was called
     * - Var_dump of all passed values (with toArray() conversion for objects)
     * - Debug backtrace showing last 10 function calls
     * Then calls die() to halt execution
     *
     * @param mixed ...$values Any number of values to debug
     * @return void (never returns - calls die())
     */
    public static function rsx_dump_die(...$values): void
    {
        // Get backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // Detect if called via helper function
        $caller_index = 0;
        if (isset($trace[0]['function']) && $trace[0]['function'] === 'rsx_dump_die' &&
            isset($trace[0]['file']) && str_ends_with($trace[0]['file'], '/app/RSpade/helpers.php')) {
            // Called via helper, skip one level
            $caller_index = 1;
        }

        // Get the actual caller info
        $caller = $trace[$caller_index] ?? [];
        $file = $caller['file'] ?? 'unknown';
        $line = $caller['line'] ?? 0;

        // Make path relative to project root
        $file = str_replace(base_path() . '/', '', $file);

        // Output header with red color for visibility
        echo "\n";
        // ANSI color codes: \033[31m = red, \033[0m = reset
        echo "\033[31mrsx_dump_die() at {$file}:{$line}\033[0m\n";
        echo str_repeat('-', 80) . "\n";

        // Output values concisely
        foreach ($values as $index => $value) {
            if (count($values) > 1) {
                echo "[{$index}] ";
            }
            echo static::__format_value_concise($value);
            echo "\n";
        }

        // Output minimal backtrace (first 3 relevant calls)
        echo "\nTrace: ";
        $trace_items = [];
        for ($i = $caller_index + 1; $i < min(count($trace), $caller_index + 4); $i++) {
            $item = $trace[$i];
            $file = isset($item['file']) ? basename($item['file']) : '?';
            $line = $item['line'] ?? 0;
            $function = $item['function'] ?? '?';
            $class = $item['class'] ?? '';
            if ($class) {
                // Simplify class name to last component
                $parts = explode('\\', $class);
                $class = end($parts);
                $function = $class . '::' . $function;
            }
            $trace_items[] = "{$function}({$file}:{$line})";
        }
        echo implode(' -> ', $trace_items);
        echo "\n";

        // Die to halt execution
        die();
    }

    /**
     * Format a value concisely for debugging output
     *
     * @param mixed $value The value to format
     * @param int $depth Current recursion depth
     * @return string Formatted string representation
     */
    protected static function __format_value_concise($value, int $depth = 0): string
    {
        // Limit recursion depth
        if ($depth > 3) {
            return '...';
        }

        // Handle scalar values and null
        if (is_null($value)) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            // Truncate long strings and escape special characters
            if (strlen($value) > 100) {
                $value = substr($value, 0, 97) . '...';
            }
            return '"' . addslashes($value) . '"';
        }

        // Handle arrays
        if (is_array($value)) {
            $count = count($value);
            if ($count === 0) {
                return '[]';
            }
            if ($count > 10) {
                // For large arrays, show count and first 10 keys
                $keys = array_slice(array_keys($value), 0, 10);
                $key_str = implode(', ', array_map(function($k) {
                    return is_string($k) ? "'{$k}'" : (string)$k;
                }, $keys));
                return "array({$count}) [{$key_str}, ...]";
            }
            // For small arrays, show compact representation
            $items = [];
            foreach ($value as $k => $v) {
                $formatted = static::__format_value_concise($v, $depth + 1);
                if (is_string($k)) {
                    $items[] = "'{$k}' => {$formatted}";
                } else {
                    $items[] = $formatted;
                }
            }
            return '[' . implode(', ', $items) . ']';
        }

        // Handle objects
        if (is_object($value)) {
            $class = get_class($value);
            // Simplify namespace
            $parts = explode('\\', $class);
            $simple_class = end($parts);

            // Special handling for common objects
            if ($value instanceof \Illuminate\Support\Collection) {
                $count = $value->count();
                return "Collection({$count})";
            }
            if (method_exists($value, 'toArray')) {
                try {
                    $array = $value->toArray();
                    $count = count($array);
                    return "{$simple_class}({$count} props)";
                } catch (\Exception $e) {
                    // Fall through
                }
            }
            if (method_exists($value, 'getKey') && method_exists($value, 'getTable')) {
                // Likely an Eloquent model
                try {
                    $id = $value->getKey();
                    return "{$simple_class}(id={$id})";
                } catch (\Exception $e) {
                    // Fall through
                }
            }

            return "Object({$simple_class})";
        }

        // Resources and other types
        if (is_resource($value)) {
            return 'Resource(' . get_resource_type($value) . ')';
        }

        return gettype($value);
    }

    /**
     * Prepare a value for safe display
     *
     * Attempts to convert objects to arrays if they have toArray() method
     * Prevents infinite recursion and handles exceptions
     *
     * @param mixed $value Value to prepare
     * @return mixed Prepared value
     */
    protected static function __prepare_value_for_display($value)
    {
        // Handle scalar values and null
        if (is_scalar($value) || is_null($value)) {
            return $value;
        }

        // Handle arrays recursively (with depth limit)
        if (is_array($value)) {
            return static::__prepare_array_for_display($value, 0, 10);
        }

        // Handle objects
        if (is_object($value)) {
            // Try toArray() method
            if (method_exists($value, 'toArray')) {
                try {
                    $array = $value->toArray();

                    return ['[' . get_class($value) . '::toArray()]' => $array];
                } catch (Exception $e) {
                    // Fall through to default handling
                }
            }

            // Try jsonSerialize() method (for JsonSerializable objects)
            if ($value instanceof JsonSerializable) {
                try {
                    $data = $value->jsonSerialize();

                    return ['[' . get_class($value) . '::jsonSerialize()]' => $data];
                } catch (Exception $e) {
                    // Fall through to default handling
                }
            }

            // For Eloquent models, try to get attributes
            if (method_exists($value, 'getAttributes')) {
                try {
                    $attributes = $value->getAttributes();

                    return ['[' . get_class($value) . ' Model]' => $attributes];
                } catch (Exception $e) {
                    // Fall through to default handling
                }
            }

            // For collections, convert to array
            if ($value instanceof \Illuminate\Support\Collection) {
                try {
                    return ['[Collection]' => $value->toArray()];
                } catch (Exception $e) {
                    // Fall through to default handling
                }
            }

            // Default object handling - just return class name to avoid recursion
            return '[Object: ' . get_class($value) . ']';
        }

        // Resources and other types
        return $value;
    }

    /**
     * Prepare array for display with recursion protection
     *
     * @param array $array Array to prepare
     * @param int $depth Current depth
     * @param int $max_depth Maximum depth to recurse
     * @return array Prepared array
     */
    protected static function __prepare_array_for_display($array, $depth = 0, $max_depth = 10)
    {
        if ($depth >= $max_depth) {
            return '[Max depth reached]';
        }

        $result = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = static::__prepare_array_for_display($value, $depth + 1, $max_depth);
            } elseif (is_object($value)) {
                $result[$key] = static::__prepare_value_for_display($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Normalize a channel name for consistent output
     * - Convert to uppercase
     * - Replace non-alphanumeric chars with underscore
     * - Collapse multiple underscores
     * - Trim underscores from edges
     * - Strip any brackets that user might have added
     *
     * @param string $channel Raw channel name
     * @return string Normalized channel name
     */
    protected static function __normalize_channel(string $channel): string
    {
        // Strip brackets if present
        $channel = str_replace(['[', ']'], '', $channel);

        // Convert to uppercase
        $channel = strtoupper($channel);

        // Replace non-alphanumeric with underscore
        $channel = preg_replace('/[^A-Z0-9]+/', '_', $channel);

        // Collapse multiple underscores
        $channel = preg_replace('/_+/', '_', $channel);

        // Trim underscores from edges
        $channel = trim($channel, '_');

        return $channel;
    }

    /**
     * Get console debug configuration
     *
     * @return array Configuration array
     */
    protected static function __get_console_config(): array
    {
        if (static::$console_config === null) {
            $defaults = [
                'enabled' => true,
                'outputs' => [
                    'cli' => false,
                    'web' => true,
                    'ajax' => true,
                    'laravel_log' => false,
                ],
                'filter_mode' => 'all', // all, whitelist, blacklist, specific
                'specific_channel' => null,
                'whitelist' => [],
                'blacklist' => [],
                'include_benchmark' => false,
            ];

            // Deep merge config with defaults (config takes precedence)
            $config = array_merge($defaults, config('rsx.console_debug', []));

            // Ensure outputs exists (in case config had partial data)
            if (!isset($config['outputs']) || !is_array($config['outputs'])) {
                $config['outputs'] = $defaults['outputs'];
            }

            // Convert string "true"/"false" to boolean for outputs
            foreach ($config['outputs'] as $key => $value) {
                if ($value === 'true') {
                    $config['outputs'][$key] = true;
                } elseif ($value === 'false') {
                    $config['outputs'][$key] = false;
                }
            }

            // Check for environment variable overrides (use getenv for runtime putenv support)
            if (getenv('CONSOLE_DEBUG_CLI') !== false) {
                $config['outputs']['cli'] = filter_var(getenv('CONSOLE_DEBUG_CLI'), FILTER_VALIDATE_BOOLEAN);
            }
            if (getenv('CONSOLE_DEBUG_WEB') !== false) {
                $config['outputs']['web'] = filter_var(getenv('CONSOLE_DEBUG_WEB'), FILTER_VALIDATE_BOOLEAN);
            }
            if (getenv('CONSOLE_DEBUG_AJAX') !== false) {
                $config['outputs']['ajax'] = filter_var(getenv('CONSOLE_DEBUG_AJAX'), FILTER_VALIDATE_BOOLEAN);
            }

            static::$console_config = $config;
        }

        return static::$console_config;
    }

    /**
     * Reset cached console debug configuration
     *
     * Call this after modifying environment variables like CONSOLE_DEBUG_FILTER
     * or CONSOLE_DEBUG_CLI to force reconfiguration on next console_debug() call.
     *
     * @return void
     */
    public static function reset_console_config(): void
    {
        static::$console_config = null;
    }

    /**
     * Configure console debug based on HTTP headers from rsx:debug
     *
     * This allows the Playwright test runner to pass console debug settings
     * through HTTP headers since environment variables don't propagate through
     * the Playwright -> Chrome -> HTTP request chain.
     *
     * @return void
     */
    public static function configure_from_headers(): void
    {
        // Never process headers in production or from non-loopback IPs
        if (app()->environment('production') || !is_loopback_ip()) {
            return;
        }

        $request = request();
        if (!$request) {
            return;
        }

        // Only apply header configuration if this is a Playwright test request
        if (!$request->hasHeader('X-Playwright-Test')) {
            return;
        }

        // Get base configuration
        $config = static::__get_console_config();

        // Check for console debug filter header
        if ($request->hasHeader('X-Console-Debug-Filter')) {
            $filter = $request->header('X-Console-Debug-Filter');
            if ($filter) {
                $config['filter_mode'] = 'specific';
                $config['specific_channel'] = strtoupper($filter);
            }
        }

        // Check for benchmark header
        if ($request->hasHeader('X-Console-Debug-Benchmark')) {
            $config['include_benchmark'] = true;
        }

        // Check for all channels header
        if ($request->hasHeader('X-Console-Debug-All')) {
            $config['filter_mode'] = 'all';
        }

        // Enable CLI output for Playwright tests if console debugging is enabled
        if ($request->hasHeader('X-Playwright-Console-Debug')) {
            $config['outputs']['cli'] = true;
        }

        // Override the cached configuration
        static::$console_config = $config;
    }

    /**
     * Set request start time for benchmarking
     *
     * @param float|null $time Start time or null to use current time
     * @return void
     */
    public static function set_request_start_time(?float $time = null): void
    {
        static::$request_start_time = $time ?? microtime(true);
    }

    /**
     * Check if console debug is enabled
     *
     * @return bool Whether console debug output is enabled
     */
    public static function is_console_debug_enabled(): bool
    {
        // Get configuration
        $config = static::__get_console_config();

        // Check if completely disabled
        if (!$config['enabled']) {
            return false;
        }

        // Check if any output is enabled
        return $config['outputs']['cli'] ||
               $config['outputs']['web'] ||
               $config['outputs']['ajax'] ||
               $config['outputs']['laravel_log'];
    }

    /**
     * Handle console debug trace mode
     *
     * When enabled via config and ?__trace=1 is in GET parameters, captures all
     * console_debug messages and outputs them as plain text for curl/console viewing.
     */
    public static function _try_enable_trace_mode(): void
    {
        // Check if __trace=1 is in GET parameters
        if (!isset($_GET['__trace']) || !config('rsx.console_debug.enable_get_trace')) {
            return;
        }

        // Import Debugger class
        $debugger = 'App\RSpade\Core\Debug\Debugger';

        // Set trace mode in Debugger
        self::$trace_mode = true;

        // Set request start time for benchmarking
        $debugger::set_request_start_time(defined('LARAVEL_START') ? LARAVEL_START : microtime(true));

        // Force console_debug configuration for trace mode
        config([
            'rsx.console_debug.enabled' => true,
            'rsx.console_debug.filter_mode' => 'all',
            'rsx.console_debug.include_benchmark' => true,
            'rsx.console_debug.outputs.cli' => false,
            'rsx.console_debug.outputs.web' => true,
            'rsx.console_debug.outputs.ajax' => true,
            'rsx.console_debug.outputs.laravel_log' => false,
        ]);

        // Start output buffering to capture all output
        // error_log('OB Level before ob_start: ' . ob_get_level());
        ob_start();
        // error_log('OB Level after ob_start: ' . ob_get_level());

        // Register shutdown handler for trace output
        register_shutdown_function([$debugger, 'dump_trace_output']);

        console_debug('TRACE', 'Trace mode activated - capturing all console_debug messages');
    }

    /**
     * Get elapsed time since request start
     *
     * @return string Formatted time string like "05.1234" or "##.####" for >99 seconds
     */
    protected static function __get_benchmark_prefix(): string
    {
        if (static::$request_start_time === null) {
            static::$request_start_time = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        }

        $elapsed = microtime(true) - static::$request_start_time;

        if ($elapsed >= 99.0) {
            return '[##.####]';
        }

        return sprintf('[%02d.%04d]', floor($elapsed), ($elapsed - floor($elapsed)) * 10000);
    }

    /**
     * Check if a channel should be output based on filter configuration
     *
     * @param string $channel Normalized channel name
     * @param array $config Console configuration
     * @return bool Whether to output this channel
     */
    protected static function __should_output_channel(string $channel, array $config): bool
    {
        // Check environment override (use getenv for runtime putenv support)
        $env_filter = getenv('CONSOLE_DEBUG_FILTER');
        if ($env_filter !== false) {
            // Split comma-separated values and normalize
            $channels = array_map('trim', explode(',', $env_filter));
            $channels = array_map('strtoupper', $channels);

            return in_array($channel, $channels, true);
        }

        switch ($config['filter_mode']) {
            case 'specific':
                $specific = $config['specific_channel'] ?? '';
                if ($specific) {
                    // Split comma-separated values and normalize
                    $channels = array_map('trim', explode(',', $specific));
                    $channels = array_map('strtoupper', $channels);

                    return in_array($channel, $channels, true);
                }

                return false;

            case 'whitelist':
                $whitelist = array_map('strtoupper', $config['whitelist'] ?? []);

                return in_array($channel, $whitelist, true);

            case 'blacklist':
                $blacklist = array_map('strtoupper', $config['blacklist'] ?? []);

                return !in_array($channel, $blacklist, true);

            case 'all':
            default:
                return true;
        }
    }

    /**
     * Console debug output helper with channel categorization
     *
     * Outputs debug information to stderr in CLI mode when SHOW_CONSOLE_DEBUG_CLI is enabled.
     * In HTTP mode, batches messages for browser console output when SHOW_CONSOLE_DEBUG_HTTP is enabled.
     * Only outputs when the appropriate flag is set and not in production.
     * Includes file and line number where console_debug was called from.
     * Messages are prefixed with the journal category in square brackets.
     *
     * @param string $channel Channel category for the debug message (e.g., "DISPATCH", "AUTH", "DB")
     * @param mixed ...$values Values to output - arbitrary arguments like console.log
     * @return void
     */
    /**
     * Whether PHP console_debug() emits in the current RSX_MODE.
     *
     * True in development and debug; false in strict production only. This is the
     * server-side mirror of Manifest::_should_include_debug_info() / the JS gate,
     * and the single named place the build-mode console policy lives for PHP.
     *
     * @return bool
     */
    public static function _console_debug_enabled_for_mode(): bool
    {
        return !(\App\RSpade\Core\Rsx::is_production() && !\App\RSpade\Core\Rsx::is_debug());
    }

    public static function console_debug(string $channel, ...$values): void
    {
        // Suppressed only in strict production (RSX_MODE=production, not debug).
        // Development and debug both emit - mirrors the JS-side gate and the root
        // CLAUDE.md mode table. Authority is RSX_MODE, not APP_ENV.
        if (!static::_console_debug_enabled_for_mode()) {
            return;
        }

        // Handle BUILD_DEBUG_MODE (check both constant and command-line flag)
        $build_debug_mode = (defined('BUILD_DEBUG_MODE') && BUILD_DEBUG_MODE) ||
                           in_array('--build-debug', $_SERVER['argv'] ?? []);

        if ($build_debug_mode) {
            // Force enable CLI output and filter to BUILD channel only
            $channel = static::__normalize_channel($channel);
            if ($channel !== 'BUILD') {
                return;
            }
            // Override config for this call
            $force_cli_output = true;
            $config = static::__get_console_config(); // Still need config for benchmark
        } else {
            $force_cli_output = false;

            // Get configuration
            $config = static::__get_console_config();

            // Check if completely disabled
            if (!$config['enabled']) {
                return;
            }

            // Normalize channel name
            $channel = static::__normalize_channel($channel);

            // Check if this channel should be output
            if (!static::__should_output_channel($channel, $config)) {
                return;
            }
        }

        // Get caller information using debug_backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        // Detect if called via helper function
        $caller_index = 0;
        if (isset($trace[0]['function']) && $trace[0]['function'] === 'console_debug' &&
            isset($trace[0]['file']) && str_ends_with($trace[0]['file'], '/app/RSpade/helpers.php')) {
            // Called via helper, skip one level
            $caller_index = 1;
        }

        $caller = $trace[$caller_index] ?? null;

        // Build location prefix
        $location_prefix = '';
        if ($caller && isset($caller['file']) && isset($caller['line'])) {
            // Get relative path from project root
            $file = str_replace(base_path() . '/', '', $caller['file']);
            $location_prefix = "[{$file}:{$caller['line']}] ";
        }

        // Add benchmark prefix if enabled
        $benchmark_prefix = '';
        if ($config['include_benchmark']) {
            $benchmark_prefix = static::__get_benchmark_prefix() . ' ';
        }

        // Handle based on context
        if (app()->runningInConsole()) {
            // CLI mode - check if CLI output is enabled
            if (!$force_cli_output && !$config['outputs']['cli']) {
                return;
            }

            // Build channel with prefixes
            $prefix = $benchmark_prefix . $location_prefix . "[{$channel}]";

            // ANSI color codes: \033[36m = light cyan, \033[0m = reset
            fwrite(STDERR, "\033[36m" . $prefix . "\033[0m");

            // Output each value separately
            foreach ($values as $value) {
                if (is_string($value) || is_numeric($value) || is_bool($value) || is_null($value)) {
                    // Primitives - output directly
                    $output = is_bool($value) ? ($value ? 'true' : 'false') :
                             (is_null($value) ? 'null' : (string)$value);
                    fwrite(STDERR, ' ' . $output);
                } else {
                    // Complex types - output as pretty JSON
                    $json_value = static::_prepare_value_for_json($value);
                    $json = json_encode($json_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    fwrite(STDERR, "\n" . $json);
                }
            }
            fwrite(STDERR, "\n");
        } else {
            // HTTP mode - check if web output is enabled
            $is_ajax = request()->ajax() || request()->wantsJson();

            if ($is_ajax && !$config['outputs']['ajax']) {
                return;
            }

            if (!$is_ajax && !$config['outputs']['web']) {
                // Check for Playwright header override
                if (!(isset($_SERVER['HTTP_X_PLAYWRIGHT_CONSOLE_DEBUG']) && $_SERVER['HTTP_X_PLAYWRIGHT_CONSOLE_DEBUG'] === '1')) {
                    return;
                }
            }

            // Prepare values for JSON encoding
            $prepared_values = [];
            foreach ($values as $value) {
                $prepared_values[] = static::_prepare_value_for_json($value);
            }

            // Store as structured data: [channel_with_prefixes, [arguments]]
            $channel_with_prefixes = $benchmark_prefix . $location_prefix . "[{$channel}]";
            static::$console_messages[] = [$channel_with_prefixes, $prepared_values];

            // Register shutdown function only once and only in non-production (skip in trace mode)
            if (!static::$shutdown_registered && !app()->environment('production') && !static::$trace_mode) {
                register_shutdown_function([static::class, 'dump_console_debug_messages_to_html']);
                static::$shutdown_registered = true;
            }
        }

        // Also log to Laravel log if enabled
        if ($config['outputs']['laravel_log']) {
            // For Laravel log, we need a string representation
            $log_parts = [$benchmark_prefix . $location_prefix . "[{$channel}]"];
            foreach ($values as $value) {
                if (is_string($value) || is_numeric($value)) {
                    $log_parts[] = $value;
                } else {
                    $log_parts[] = json_encode(static::_prepare_value_for_json($value));
                }
            }
            Log::debug(implode(' ', $log_parts));
        }
    }

    /**
     * Prepare a value for JSON encoding
     *
     * Converts PHP objects to arrays if they have toArray() method.
     * This ensures complex objects are properly serialized for JavaScript consumption.
     *
     * @param mixed $value The value to prepare
     * @return mixed The prepared value
     */
    protected static function _prepare_value_for_json($value)
    {
        // Handle null and scalar types directly
        if (is_null($value) || is_scalar($value)) {
            return $value;
        }

        // Handle arrays recursively
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = static::_prepare_value_for_json($item);
            }

            return $result;
        }

        // Handle objects
        if (is_object($value)) {
            // Check for toArray method
            if (method_exists($value, 'toArray')) {
                return static::_prepare_value_for_json($value->toArray());
            }

            // Handle stdClass
            if ($value instanceof stdClass) {
                return static::_prepare_value_for_json((array)$value);
            }

            // For other objects, convert to array representation
            // This will include public properties
            return static::_prepare_value_for_json(get_object_vars($value));
        }

        // Resources and other types
        return (string)$value;
    }

    /**
     * Dump console debug messages to HTML for browser console output
     *
     * Outputs JavaScript that logs batched messages to the browser console.
     * Clears the message array after output.
     * In production mode, outputs nothing.
     * In trace mode, skips output since trace mode has its own handler.
     * Also registers a shutdown function to output any remaining messages.
     *
     * @return void
     */
    public static function dump_console_debug_messages_to_html(): void
    {
        // Don't output in production mode
        if (app()->environment('production')) {
            return;
        }

        // Don't output if suppressed (e.g., for IDE service requests)
        if (defined('SUPPRESS_CONSOLE_DEBUG_OUTPUT') && SUPPRESS_CONSOLE_DEBUG_OUTPUT) {
            return;
        }

        // Don't output if console HTML is disabled (e.g., for AJAX requests)
        if (static::$console_html_disabled) {
            return;
        }

        // Don't output in trace mode (trace mode has its own output handler)
        if (static::$trace_mode) {
            return;
        }

        // Output any accumulated messages
        if (!empty(static::$console_messages)) {
            // Nonce from the request-scoped static: this runs at SHUTDOWN, with no Response
            // object left to read a header off, and the value must match the one the CSP
            // header already carried.
            echo '<script nonce="' . htmlspecialchars(\App\RSpade\Core\Csp\Rsx_Csp::nonce()) . '">' . "\n";
            foreach (static::$console_messages as $message) {
                // Messages are always [channel, [arguments]] format
                $channel = $message[0];
                $arguments = $message[1];

                // Build console.log call with channel as first arg, then spread the arguments
                $js_args = [json_encode($channel)];
                foreach ($arguments as $arg) {
                    $js_args[] = json_encode($arg);
                }

                echo 'console.log(' . implode(', ', $js_args) . ');' . "\n";
            }
            echo '</script>';

            // Clear the messages after output
            static::$console_messages = [];
        }
    }

    /**
     * Get accumulated console debug messages without outputting them
     *
     * Internal framework method - not for end developer use.
     * Used by exception handler to retrieve messages for error output.
     *
     * @internal
     * @return array Array of console debug messages
     */
    public static function _get_console_messages(): array
    {
        return static::$console_messages;
    }

    /**
     * Register the shutdown function for dumping console messages
     *
     * This is useful for ensuring console messages are output even after fatal errors.
     * Only registers once and only in non-production mode.
     * Skipped in trace mode since trace mode has its own output handler.
     *
     * @return void
     */
    public static function register_console_dump_shutdown(): void
    {
        if (!static::$shutdown_registered && !app()->environment('production') && !static::$trace_mode) {
            register_shutdown_function([static::class, 'dump_console_debug_messages_to_html']);
            static::$shutdown_registered = true;
        }
    }

    /**
     * Disable console HTML output (for AJAX requests)
     *
     * Prevents console.log script blocks from being output to the response.
     * Messages are still collected and can be retrieved with _get_console_messages()
     *
     * @return void
     */
    public static function disable_console_html_output(): void
    {
        static::$console_html_disabled = true;
    }

    /**
     * Enable console HTML output (default mode)
     *
     * Allows console.log script blocks to be output to the response.
     *
     * @return void
     */
    public static function enable_console_html_output(): void
    {
        static::$console_html_disabled = false;
    }

    /**
     * Check if console HTML output is disabled
     *
     * @return bool
     */
    public static function is_console_html_disabled(): bool
    {
        return static::$console_html_disabled;
    }

    /**
     * Ajax endpoint to log console_debug messages from JavaScript
     *
     * @param \Illuminate\Http\Request $request
     * @param array $params
     * @return array
     */
    public static function log_console_messages(\Illuminate\Http\Request $request, array $params = []): array
    {
        // Check if Laravel log output is enabled
        $config = config('rsx.console_debug', []);
        if (!($config['enabled'] ?? true) || !($config['outputs']['laravel_log'] ?? false)) {
            return ['status' => 'disabled'];
        }

        // Get messages from request
        $messages = $request->json('messages', []);
        if (empty($messages)) {
            return ['status' => 'no_messages'];
        }

        // Log each message
        foreach ($messages as $message) {
            if (!isset($message['channel']) || !isset($message['values'])) {
                continue;
            }

            $channel = $message['channel'];
            $values = $message['values'];

            // Build log message
            $log_parts = ["[CONSOLE_DEBUG][JS][{$channel}]"];

            // Add location if provided
            if (!empty($message['location'])) {
                $log_parts[] = "at {$message['location']}";
            }

            // Add values
            foreach ($values as $value) {
                if (is_scalar($value) || is_null($value)) {
                    $log_parts[] = (string)$value;
                } else {
                    $log_parts[] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            // Add backtrace if provided
            if (!empty($message['backtrace'])) {
                $log_parts[] = "\nBacktrace:\n" . implode("\n", $message['backtrace']);
            }

            // Write to Laravel log
            Log::info(implode(' ', $log_parts));
        }

        return ['status' => 'logged', 'count' => count($messages)];
    }

    /**
     * Ajax endpoint to log browser errors from JavaScript
     *
     * @param \Illuminate\Http\Request $request
     * @param array $params
     * @return array
     */
    public static function log_browser_errors(\Illuminate\Http\Request $request, array $params = []): array
    {
        // Check if browser error logging is enabled
        if (!config('rsx.log_browser_errors', false)) {
            return ['status' => 'disabled'];
        }

        // Get errors from request
        $errors = $request->json('errors', []);
        if (empty($errors)) {
            return ['status' => 'no_errors'];
        }

        // Log each error
        foreach ($errors as $error) {
            $log_parts = ['[BROWSER_ERROR]'];

            // Add error type
            if (!empty($error['type'])) {
                $log_parts[] = "[{$error['type']}]";
            }

            // Add message
            if (!empty($error['message'])) {
                $log_parts[] = $error['message'];
            }

            // Add location info
            if (!empty($error['filename']) && !empty($error['lineno'])) {
                $log_parts[] = "at {$error['filename']}:{$error['lineno']}";
                if (!empty($error['colno'])) {
                    $log_parts[] = ":{$error['colno']}";
                }
            }

            // Add URL
            if (!empty($error['url'])) {
                $log_parts[] = "URL: {$error['url']}";
            }

            // Build error message
            $error_message = implode(' ', $log_parts);

            // Add stack trace if available
            if (!empty($error['stack'])) {
                $error_message .= "\nStack trace:\n{$error['stack']}";
            }

            // Add user agent if available
            if (!empty($error['userAgent'])) {
                $error_message .= "\nUser Agent: {$error['userAgent']}";
            }

            // Log as error level
            Log::error($error_message);
        }

        return ['status' => 'logged', 'count' => count($errors)];
    }

    public static function is_trace_output_request()
    {
        return static::$trace_mode;
    }

    /**
     * Dump trace output for console/curl viewing
     *
     * Called as a shutdown handler when trace mode is enabled.
     * Outputs all console_debug messages as plain text with millisecond timing.
     *
     * @return void
     */
    public static function dump_trace_output(): void
    {
        console_debug('TRACE', 'Script execution complete');

        // Debug to verify this is being called
        error_log('DUMP_TRACE_OUTPUT CALLED');

        // Clean all output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Set content type to plain text
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
        }

        // Get request start time
        $start_time = static::$request_start_time ?? (defined('LARAVEL_START') ? LARAVEL_START : $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        // Output header
        echo "====== REQUEST TRACE ======\n";
        echo 'URL: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . "\n";
        echo 'Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') . "\n";
        echo 'Time: ' . date('Y-m-d H:i:s') . "\n";
        echo "===========================\n\n";

        // Output each console_debug message
        if (!empty(static::$console_messages)) {
            foreach (static::$console_messages as $message) {
                // Messages are [channel_with_prefixes, [arguments]]
                $channel_line = $message[0];
                $arguments = $message[1];

                // Extract timing from channel line if present
                $timing_pattern = '/^\[(\d{2}\.\d{4})\]/';
                if (preg_match($timing_pattern, $channel_line, $matches)) {
                    // Format seconds with 3 decimal places
                    $seconds = (float)$matches[1];
                    $formatted_seconds = number_format($seconds, 3, '.', '');
                    $channel_line = preg_replace($timing_pattern, "[{$formatted_seconds}s]", $channel_line);
                }

                // Output the channel line
                echo $channel_line;

                // Output arguments
                foreach ($arguments as $arg) {
                    if (is_scalar($arg) || is_null($arg)) {
                        $output = is_bool($arg) ? ($arg ? 'true' : 'false') :
                                 (is_null($arg) ? 'null' : (string)$arg);
                        echo ' ' . $output;
                    } else {
                        // Complex types - output as JSON on new lines
                        $json = json_encode($arg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        echo "\n" . $json;
                    }
                }
                echo "\n";
            }
        }

        // Calculate and output total request time in seconds
        $total_time = microtime(true) - $start_time;
        $formatted_total = number_format($total_time, 3, '.', '');

        echo "\n===========================\n";
        echo "Total request time: {$formatted_total}s\n";
        echo "===========================\n";
    }
}
