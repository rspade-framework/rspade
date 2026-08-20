<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class VarUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-VAR-01';
    }
    
    public function get_name(): string
    {
        return 'JavaScript var Keyword Check';
    }
    
    public function get_description(): string
    {
        return "Enforces use of 'let' and 'const' instead of 'var'";
    }
    
    public function get_file_patterns(): array
    {
        return ['*.js'];
    }
    
    public function get_default_severity(): string
    {
        return 'medium';
    }
    
    /**
     * Check JavaScript file for 'var' keyword usage (from line 1269)
     * Enforces use of 'let' and 'const' instead of 'var'
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
            
            // Check for 'var' keyword at the beginning of a line or after ( or ;
            // This catches var declarations in for loops and inline declarations
            if (preg_match('/(?:^\s*|[\(;]\s*)var\s+/', $line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use of 'var' keyword is not allowed. Use 'let' or 'const' instead for better scoping and immutability.",
                    trim($line),
                    "Replace 'var' with 'let' for mutable variables or 'const' for immutable values.",
                    'medium'
                );
            }
        }
    }
}