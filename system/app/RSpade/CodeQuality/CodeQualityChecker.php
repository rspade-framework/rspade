<?php

namespace App\RSpade\CodeQuality;

use App\RSpade\CodeQuality\CodeQuality_Violation;
use App\RSpade\CodeQuality\Support\CacheManager;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\CodeQuality\Support\Js_CodeQuality_Rpc;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Manifest\Manifest;

class CodeQualityChecker
{
    protected static ?ViolationCollector $collector = null;
    protected static ?CacheManager $cache_manager = null;
    protected static array $rules = [];
    protected static array $config = [];

    public static function init(array $config = []): void
    {
        static::$collector = new ViolationCollector();
        static::$cache_manager = new CacheManager();
        static::$config = $config;

        // Load all rules via auto-discovery
        static::load_rules();

        // Clean up old NPM bundle files on initialization
        static::_cleanup_old_npm_bundles();
    }

    /**
     * Clean up old NPM bundle files
     * NPM bundles are cached based on package-lock.json + npm array + CWD
     * Old bundles from different cache keys should be removed
     */
    protected static function _cleanup_old_npm_bundles(): void
    {
        $bundle_dir = storage_path('rsx-build/bundles');

        // Skip if directory doesn't exist yet
        if (!is_dir($bundle_dir)) {
            return;
        }

        // Find all npm_*.js files
        $npm_bundles = glob($bundle_dir . '/npm_*.js');

        if (empty($npm_bundles)) {
            return;
        }

        // Keep the most recent 5 npm bundle files per bundle name
        // Group by bundle name (npm_<bundlename>_<hash>.js)
        $bundles_by_name = [];
        foreach ($npm_bundles as $file) {
            $filename = basename($file);
            // Extract bundle name from npm_<bundlename>_<hash>.js
            if (preg_match('/^npm_([^_]+)_[a-f0-9]{32}\.js$/', $filename, $matches)) {
                $bundle_name = $matches[1];
                if (!isset($bundles_by_name[$bundle_name])) {
                    $bundles_by_name[$bundle_name] = [];
                }
                $bundles_by_name[$bundle_name][] = [
                    'file' => $file,
                    'mtime' => filemtime($file)
                ];
            }
        }

        // For each bundle name, keep only the 5 most recent files
        foreach ($bundles_by_name as $bundle_name => $files) {
            // Sort by modification time, newest first
            usort($files, function($a, $b) {
                return $b['mtime'] - $a['mtime'];
            });

            // Delete all but the most recent 5
            $to_keep = 5;
            for ($i = $to_keep; $i < count($files); $i++) {
                @unlink($files[$i]['file']);
            }
        }
    }
    
    /**
     * Load all rules via shared discovery logic
     */
    protected static function load_rules(): void
    {
        // Check if we should exclude manifest-time rules (e.g., when running from rsx:check)
        $exclude_manifest_time_rules = static::$config['exclude_manifest_time_rules'] ?? false;

        // Use shared rule discovery that doesn't require manifest
        static::$rules = Support\RuleDiscovery::discover_rules(
            static::$collector,
            static::$config,
            false, // Get all rules, not just manifest scan ones
            $exclude_manifest_time_rules // Exclude manifest-time rules if requested
        );
    }
    
    /**
     * Check a single file
     */
    public static function check_file(string $file_path): void
    {
        // Get excluded directories from config
        $excluded_dirs = config('rsx.manifest.excluded_dirs', ['vendor', 'node_modules', 'storage', '.git', 'public', 'resource']);

        // Check if file is in any excluded directory
        foreach ($excluded_dirs as $excluded_dir) {
            if (str_contains($file_path, '/' . $excluded_dir . '/')) {
                return;
            }
        }

        // Skip CodeQuality infrastructure files, but allow checking Rules directory
        // This enables meta rules to check other rules for code quality violations
        if (str_contains($file_path, '/app/RSpade/CodeQuality/') &&
            !str_contains($file_path, '/app/RSpade/CodeQuality/Rules/')) {
            return;
        }

        // Get file extension
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);
        
        // Check for syntax errors first
        if ($extension === 'php') {
            if (static::lint_php_file($file_path)) {
                // Syntax error found, don't run other checks
                return;
            }
        } elseif (in_array($extension, ['js', 'jsx', 'ts', 'tsx'])) {
            if (static::lint_javascript_file($file_path)) {
                // Syntax error found, don't run other checks
                return;
            }
        } elseif ($extension === 'json') {
            if (static::lint_json_file($file_path)) {
                // Syntax error found, don't run other checks
                return;
            }
        }
        
        // Get cached sanitized file if available
        $cached_data = static::$cache_manager->get_sanitized_file($file_path);
        
        if ($cached_data === null) {
            // Sanitize the file
            $sanitized_data = FileSanitizer::sanitize($file_path);
            
            // Cache the sanitized data
            static::$cache_manager->set_sanitized_file($file_path, $sanitized_data);
        } else {
            $sanitized_data = $cached_data;
        }
        
        // Get metadata from manifest if available
        try {
            $metadata = Manifest::get_file($file_path) ?? [];
        } catch (\Exception $e) {
            $metadata = [];
        }
        
        // Check if this is a Console Command file
        $is_console_command = str_contains($file_path, '/app/Console/Commands/');

        // Run each rule on the file
        foreach (static::$rules as $rule) {
            // If this is a Console Command, only run rules that support them
            if ($is_console_command && !$rule->supports_console_commands()) {
                continue;
            }

            // Check if this rule applies to this file type
            $applies = false;
            foreach ($rule->get_file_patterns() as $pattern) {
                if (static::matches_pattern($file_path, $pattern)) {
                    $applies = true;
                    break;
                }
            }

            if (!$applies) {
                continue;
            }
            
            // Check for rule-specific exception comment in original file content
            $rule_id = $rule->get_id();
            $exception_pattern = '@' . $rule_id . '-EXCEPTION';
            $original_content = file_get_contents($file_path);
            if (str_contains($original_content, $exception_pattern)) {
                // Skip this rule for this file
                continue;
            }
            
            // Run the rule
            $rule->check($file_path, $sanitized_data['content'], $metadata);
        }
    }
    
    /**
     * Check multiple files
     */
    public static function check_files(array $file_paths): void
    {
        // First run special directory-level checks for rules that need them
        foreach (static::$rules as $rule) {
            // Check for special check_root method (RootFilesRule)
            if (method_exists($rule, 'check_root')) {
                $rule->check_root();
            }
            
            // Check for special check_rsx method (RsxTestFilesRule)
            if (method_exists($rule, 'check_rsx')) {
                $rule->check_rsx();
            }
            
            // Check for special check_required_models method (RequiredModelsRule)
            if (method_exists($rule, 'check_required_models')) {
                $rule->check_required_models();
            }
            
            // Check for special check_rsx_commands method (RsxCommandsDeprecatedRule)
            if (method_exists($rule, 'check_rsx_commands')) {
                $rule->check_rsx_commands();
            }
            
            // Check for special check_commands method (CommandOrganizationRule)
            if (method_exists($rule, 'check_commands')) {
                $rule->check_commands();
            }
        }
        
        // Then check individual files
        foreach ($file_paths as $file_path) {
            static::check_file($file_path);
        }
    }
    
    /**
     * Check all files in a directory
     */
    public static function check_directory(string $directory, bool $recursive = true): void
    {
        // First run special directory-level checks for rules that need them
        foreach (static::$rules as $rule) {
            // Check for special check_root method (RootFilesRule)
            if (method_exists($rule, 'check_root')) {
                $rule->check_root();
            }
            
            // Check for special check_rsx method (RsxTestFilesRule)
            if (method_exists($rule, 'check_rsx')) {
                $rule->check_rsx();
            }
            
            // Check for special check_required_models method (RequiredModelsRule)
            if (method_exists($rule, 'check_required_models')) {
                $rule->check_required_models();
            }
        }
        
        // Get all files - let rules filter by extension
        $files = [];

        if ($recursive) {
            // Use RecursiveIteratorIterator for recursive scanning
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        } else {
            // Non-recursive - just scan immediate directory
            $items = glob($directory . '/*');
            $files = array_filter($items, 'is_file');
        }

        foreach ($files as $file) {
            static::check_file($file);
        }
    }
    
    /**
     * Get the violation collector
     */
    public static function get_collector(): ViolationCollector
    {
        return static::$collector;
    }
    
    /**
     * Get all violations
     */
    public static function get_violations(): array
    {
        return static::$collector->get_violations_as_arrays();
    }
    
    /**
     * Clear cache
     */
    public static function clear_cache(): void
    {
        static::$cache_manager->clear();
    }
    
    /**
     * Check if a file path matches a pattern
     */
    protected static function matches_pattern(string $file_path, string $pattern): bool
    {
        // Simple glob matching for file patterns like *.php, *.js
        if (strpos($pattern, '*') === 0) {
            // Pattern like *.php - check file extension
            $extension = substr($pattern, 1); // Remove the *
            return str_ends_with($file_path, $extension);
        }
        
        // For more complex patterns, use fnmatch if available
        if (function_exists('fnmatch')) {
            return fnmatch($pattern, basename($file_path));
        }
        
        // Fallback to simple string matching
        return str_contains($file_path, $pattern);
    }
    
    /**
     * Lint PHP file (from monolith line 536)
     * Returns true if syntax error found
     */
    protected static function lint_php_file(string $file_path): bool
    {
        // Get excluded directories from config
        $excluded_dirs = config('rsx.manifest.excluded_dirs', ['vendor', 'node_modules', 'storage', '.git', 'public', 'resource']);

        // Check if file is in any excluded directory
        foreach ($excluded_dirs as $excluded_dir) {
            if (str_contains($file_path, '/' . $excluded_dir . '/')) {
                return false;
            }
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return false;
        }
        
        // Create cache directory for lint flags
        $cache_dir = function_exists('storage_path') ? storage_path('rsx-tmp/cache/php-lint-passed') : '/var/www/html/storage/rsx-tmp/cache/php-lint-passed';
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }
        
        // Generate flag file path (no .php extension to avoid IDE detection)
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $relative_path = str_replace($base_path . '/', '', $file_path);
        $flag_path = $cache_dir . '/' . str_replace('/', '_', $relative_path) . '.lintpass';
        
        // Check if lint was already passed
        if (file_exists($flag_path)) {
            $source_mtime = filemtime($file_path);
            $flag_mtime = filemtime($flag_path);
            
            if ($flag_mtime >= $source_mtime) {
                // File hasn't changed since last successful lint
                return false; // No errors
            }
        }
        
        // Run PHP lint check
        $command = sprintf('php -l %s 2>&1', escapeshellarg($file_path));
        $output = shell_exec('bash -c ' . escapeshellarg($command));
        
        // Check if there's a syntax error
        if (!str_contains($output, 'No syntax errors detected')) {
            // Delete flag file if it exists (file now has errors)
            if (file_exists($flag_path)) {
                unlink($flag_path);
            }
            
            // Just capture the error as-is
            static::$collector->add(
                new CodeQuality_Violation(
                    'PHP-SYNTAX',
                    $file_path,
                    0,
                    trim($output),
                    'critical',
                    null,
                    'Fix the PHP syntax error before running other checks.'
                )
            );
            
            return true; // Error found
        }
        
        // Create flag file to indicate successful lint
        touch($flag_path);
        
        return false; // No errors
    }
    
    /**
     * Lint JavaScript file using RPC server
     * Returns true if syntax error found
     */
    protected static function lint_javascript_file(string $file_path): bool
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return false;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return false;
        }

        // Skip VS Code extension directory
        if (str_contains($file_path, '/resource/vscode_extension/')) {
            return false;
        }

        // Create cache directory for lint flags
        $cache_dir = function_exists('storage_path') ? storage_path('rsx-tmp/cache/js-lint-passed') : '/var/www/html/storage/rsx-tmp/cache/js-lint-passed';
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }

        // Generate flag file path (no .js extension to avoid IDE detection)
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $relative_path = str_replace($base_path . '/', '', $file_path);
        $flag_path = $cache_dir . '/' . str_replace('/', '_', $relative_path) . '.lintpass';

        // Check if lint was already passed
        if (file_exists($flag_path)) {
            $source_mtime = filemtime($file_path);
            $flag_mtime = filemtime($flag_path);

            if ($flag_mtime >= $source_mtime) {
                // File hasn't changed since last successful lint
                return false; // No errors
            }
        }

        // Lint via RPC server (lazy starts if not running)
        $error = Js_CodeQuality_Rpc::lint($file_path);

        // Check if there's a syntax error
        if ($error !== null) {
            // Delete flag file if it exists (file now has errors)
            if (file_exists($flag_path)) {
                unlink($flag_path);
            }

            $line_number = $error['line'] ?? 0;
            $message = $error['message'] ?? 'Unknown syntax error';

            static::$collector->add(
                new CodeQuality_Violation(
                    'JS-SYNTAX',
                    $file_path,
                    $line_number,
                    $message,
                    'critical',
                    null,
                    'Fix the JavaScript syntax error before running other checks.'
                )
            );

            return true; // Error found
        }

        // Create flag file to indicate successful lint
        touch($flag_path);

        return false; // No errors
    }
    
    /**
     * Lint JSON file (from monolith line 684)
     * Returns true if syntax error found
     */
    protected static function lint_json_file(string $file_path): bool
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return false;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return false;
        }

        // Skip VS Code extension directory
        if (str_contains($file_path, '/resource/vscode_extension/')) {
            return false;
        }
        
        $content = file_get_contents($file_path);
        
        // Try to decode the JSON
        json_decode($content);
        
        // Check for JSON errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = json_last_error_msg();
            
            // Try to find line number for common errors
            $line_number = 0;
            if (str_contains($error_message, 'Syntax error')) {
                // Count lines up to the error position if possible
                $lines = explode("\n", $content);
                $line_number = count($lines); // Default to last line
            }
            
            static::$collector->add(
                new CodeQuality_Violation(
                    'JSON-SYNTAX',
                    $file_path,
                    $line_number,
                    "JSON parse error: {$error_message}",
                    'critical',
                    null,
                    'Fix the JSON syntax error. Common issues: missing commas, trailing commas, unquoted keys.'
                )
            );
            
            return true; // Error found
        }
        
        return false; // No errors
    }
}