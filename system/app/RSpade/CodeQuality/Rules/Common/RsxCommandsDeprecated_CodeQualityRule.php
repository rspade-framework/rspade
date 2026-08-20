<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class RsxCommandsDeprecated_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'RSX-CMD-DEPRECATED-01';
    }
    
    public function get_name(): string
    {
        return 'RSX Commands Deprecated Features Check';
    }
    
    public function get_description(): string
    {
        return 'Checks RSX commands for deprecated features or references';
    }
    
    public function get_file_patterns(): array
    {
        // This rule doesn't use standard file pattern matching
        // It scans its own directory when check_rsx_commands() is called
        return [];
    }
    
    public function get_default_severity(): string
    {
        return 'high';
    }
    
    /**
     * Standard check method - not used for this rule
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // This rule uses check_rsx_commands() instead
        return;
    }
    
    /**
     * Special method to check RSX commands directory
     * Called only when rsx:check is run with default paths
     */
    public function check_rsx_commands(): void
    {
        // Only run when using default paths
        if (!($this->config['using_default_paths'] ?? false)) {
            return;
        }
        
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $commands_dir = $base_path . '/app/RSpade/Commands/Rsx';
        
        // Check if directory exists
        if (!is_dir($commands_dir)) {
            return;
        }
        
        // Scan for PHP files in the RSX commands directory
        $files = glob($commands_dir . '/*.php');
        
        foreach ($files as $file_path) {
            $this->check_file_for_deprecated($file_path);
        }
    }
    
    /**
     * Check a single file for deprecated references
     */
    private function check_file_for_deprecated(string $file_path): void
    {
        $contents = file_get_contents($file_path);
        if ($contents === false) {
            return;
        }
        
        $lines = explode("\n", $contents);
        $filename = basename($file_path);
        
        foreach ($lines as $line_number => $line) {
            // Check for the word 'deprecated' (case insensitive)
            if (stripos($line, 'deprecated') !== false) {
                // Get the actual line number (1-indexed)
                $actual_line = $line_number + 1;
                
                // Extract context around the deprecated reference
                $context_start = max(0, $line_number - 2);
                $context_end = min(count($lines) - 1, $line_number + 2);
                $context_lines = array_slice($lines, $context_start, $context_end - $context_start + 1);
                $context = implode("\n", $context_lines);
                
                $this->add_violation(
                    $file_path,
                    $actual_line,
                    "Command file '{$filename}' contains reference to 'deprecated'",
                    trim($line),
                    $this->get_deprecated_remediation($filename, $line),
                    'high'
                );
            }
        }
    }
    
    /**
     * Get remediation message for deprecated references
     */
    private function get_deprecated_remediation(string $filename, string $line): string
    {
        return "DEPRECATED FEATURE REFERENCE IN RSX COMMAND

File: {$filename}
Line containing 'deprecated': {$line}

RSX commands should not contain deprecated features or references to deprecated functionality.

REQUIRED ACTIONS:
1. Remove the deprecated feature or functionality from the command
2. Remove any help text or documentation mentioning deprecated features
3. Update command logic to use current recommended approaches
4. If the entire command is deprecated, consider removing it

COMMON DEPRECATED PATTERNS TO REMOVE:
- Old command aliases marked as deprecated
- Legacy options or flags no longer in use
- References to deprecated framework features
- Outdated help text mentioning deprecated usage

WHY THIS MATTERS:
- Prevents confusion about which features are current
- Reduces maintenance burden of legacy code
- Ensures commands reflect current best practices
- Maintains clean and consistent command interface

If this is documentation about deprecation for historical context,
consider moving it to separate documentation rather than keeping it
in the active command code.";
    }
}