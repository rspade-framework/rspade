<?php

namespace App\RSpade\CodeQuality\Rules;

use App\RSpade\CodeQuality\CodeQuality_Violation;
use App\RSpade\CodeQuality\Support\ViolationCollector;

#[Instantiatable]
abstract class CodeQualityRule_Abstract
{
    protected ViolationCollector $collector;
    protected array $config = [];
    protected bool $enabled = true;
    
    public function __construct(ViolationCollector $collector, array $config = [])
    {
        $this->collector = $collector;
        $this->config = $config;
        $this->enabled = $config['enabled'] ?? true;
    }
    
    /**
     * Get the unique rule identifier (e.g., 'PHP-NAMING-01')
     */
    abstract public function get_id(): string;
    
    /**
     * Get human-readable rule name
     */
    abstract public function get_name(): string;
    
    /**
     * Get rule description
     */
    abstract public function get_description(): string;
    
    /**
     * Get file patterns this rule applies to (e.g., ['*.php', '*.js'])
     */
    abstract public function get_file_patterns(): array;

    /**
     * Check a file for violations
     *
     * @param string $file_path Absolute path to file
     * @param string $contents File contents (may be sanitized)
     * @param array $metadata Additional metadata from manifest
     */
    abstract public function check(string $file_path, string $contents, array $metadata = []): void;

    /**
     * Whether this rule supports checking Console Commands
     *
     * Rules that return true here will be given Console Command files to check
     * when using default paths or when a specific Console Command file is provided.
     * Rules supporting Console Commands MUST NOT rely on manifest metadata as
     * Console Commands are not indexed in the manifest.
     *
     * @return bool
     */
    #[Replaceable]
    public function supports_console_commands(): bool
    {
        return false;
    }
    
    /**
     * Check if this rule is enabled
     */
    #[Replaceable]
    public function is_enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if this rule should be called during manifest scan
     *
     * IMPORTANT: This method should ALWAYS return false unless explicitly requested
     * by the framework developer. Manifest-time checks are reserved for critical
     * framework convention violations that need immediate developer attention.
     *
     * Rules executed during manifest scan will run on every file change in development,
     * potentially impacting performance. Only enable this for rules that:
     * - Enforce critical framework conventions that would break the application
     * - Need to provide immediate feedback before code execution
     * - Have been specifically requested to run at manifest-time by framework maintainers
     *
     * DEFAULT: Always return false unless you have explicit permission to do otherwise.
     *
     * @return bool
     */
    #[Replaceable]
    public function is_called_during_manifest_scan(): bool
    {
        return false;
    }

    /**
     * Whether this rule checks files incrementally or needs cross-file context
     *
     * This method is only relevant for rules where is_called_during_manifest_scan() = true.
     *
     * INCREMENTAL (true - default):
     * - Rule checks each file independently
     * - Only changed files are passed during incremental manifest rebuilds
     * - More efficient for per-file validation rules
     *
     * CROSS-FILE (false):
     * - Rule needs to see relationships between files or check the full manifest
     * - Runs once per manifest build with access to all files via Manifest::get_all()
     * - Use for rules that validate naming across files, check for duplicates, etc.
     *
     * @return bool True for per-file rules, false for cross-file rules
     */
    #[Replaceable]
    public function is_incremental(): bool
    {
        return true;
    }
    
    /**
     * Get default severity for this rule
     */
    #[Replaceable]
    public function get_default_severity(): string
    {
        return 'medium';
    }
    
    /**
     * Add a violation
     */
    protected function add_violation(
        string $file_path,
        int $line_number,
        string $message,
        ?string $code_snippet = null,
        ?string $suggestion = null,
        ?string $severity = null
    ): void {
        $violation = new CodeQuality_Violation(
            rule_id: $this->get_id(),
            file_path: $file_path,
            line_number: $line_number,
            message: $message,
            severity: $severity ?? $this->get_default_severity(),
            code_snippet: $code_snippet,
            suggestion: $suggestion
        );
        
        $this->collector->add($violation);
    }
    
    /**
     * Extract code snippet around a line
     */
    protected function get_code_snippet(array $lines, int $line_index, int $context = 2): string
    {
        $start = max(0, $line_index - $context);
        $end = min(count($lines) - 1, $line_index + $context);

        $snippet = [];
        for ($i = $start; $i <= $end; $i++) {
            $prefix = $i === $line_index ? '>>> ' : '    ';
            $snippet[] = $prefix . ($i + 1) . ': ' . $lines[$i];
        }

        return implode("\n", $snippet);
    }

    /**
     * Get all PHP files in the Console Commands directory
     *
     * This helper allows rules to optionally include Console Commands in their checks
     * without requiring these files to be in the manifest. Rules using this helper
     * MUST NOT rely on manifest metadata since Console Commands are not indexed.
     *
     * @return array Array of absolute file paths to PHP files in app/Console/Commands
     */
    protected static function get_console_command_files(): array
    {
        $commands_dir = base_path('app/Console/Commands');

        if (!is_dir($commands_dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($commands_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Check if a file is a Console Command
     *
     * @param string $file_path The file path to check
     * @return bool True if the file is in app/Console/Commands
     */
    protected static function is_console_command(string $file_path): bool
    {
        $commands_dir = base_path('app/Console/Commands');
        $normalized_path = str_replace('\\', '/', $file_path);
        $normalized_commands_dir = str_replace('\\', '/', $commands_dir);

        return str_starts_with($normalized_path, $normalized_commands_dir);
    }
}