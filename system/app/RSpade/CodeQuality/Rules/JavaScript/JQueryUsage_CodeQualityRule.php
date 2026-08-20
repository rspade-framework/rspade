<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class JQueryUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-JQUERY-01';
    }
    
    public function get_name(): string
    {
        return 'JavaScript jQuery Usage Check';
    }
    
    public function get_description(): string
    {
        return "Enforces use of '$' shorthand instead of 'jQuery' for consistency";
    }
    
    public function get_file_patterns(): array
    {
        return ['*.js'];
    }
    
    public function get_default_severity(): string
    {
        return 'low';
    }
    
    /**
     * Check JavaScript file for 'jQuery' usage instead of '$' (from line 1307)
     * Enforces use of '$' shorthand for consistency
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $lines = $sanitized_data['lines'];
        
        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;
            
            // Skip comments
            $trimmed_line = trim($line);
            if (str_starts_with($trimmed_line, '//') || str_starts_with($trimmed_line, '*')) {
                continue;
            }
            
            // Check for 'jQuery.' or 'jQuery(' usage
            if (preg_match('/\bjQuery\s*[\.\(]/', $line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use '$' instead of 'jQuery' for consistency and brevity.",
                    trim($line),
                    "Replace 'jQuery' with '$'.",
                    'low'
                );
            }
        }
    }
}