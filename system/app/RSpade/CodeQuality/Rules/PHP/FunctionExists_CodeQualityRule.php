<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class FunctionExists_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-FUNC-01';
    }
    
    public function get_name(): string
    {
        return 'function_exists() Usage Check';
    }
    
    public function get_description(): string
    {
        return 'Enforces predictable runtime - no conditional function checking';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }
    
    public function get_default_severity(): string
    {
        return 'high';
    }
    
    /**
     * Check PHP file for function_exists() usage (from line 1652)
     * Enforces predictable runtime - no conditional function checking
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Skip InspectCommand.php - it documents what the checks do
        if (str_contains($file_path, 'InspectCommand.php')) {
            return;
        }
        
        // Get both original and sanitized content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);
        
        // Get sanitized content with comments removed
        $sanitized_data = FileSanitizer::sanitize_php($contents);
        $sanitized_lines = $sanitized_data['lines'];
        
        foreach ($sanitized_lines as $line_num => $sanitized_line) {
            $line_number = $line_num + 1;
            
            // Skip if the line is empty in sanitized version (was a comment)
            if (trim($sanitized_line) === '') {
                continue;
            }
            
            // Check for function_exists( usage
            if (preg_match('/\bfunction_exists\s*\(/i', $sanitized_line)) {
                $original_line = $original_lines[$line_num] ?? $sanitized_line;
                
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "function_exists() is not allowed. The runtime environment is strict and predictable - all expected functions will exist.",
                    trim($original_line),
                    "Remove function_exists() checks. Never conditionally define functions or handle situations differently based on what functions are defined. If a function doesn't exist, let PHP throw an error. For example, never check for ImageMagick and fall back to GD - assume ImageMagick exists and let it fail if the environment is misconfigured.",
                    'high'
                );
            }
        }
    }
}