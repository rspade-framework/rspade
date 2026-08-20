<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class JQueryLengthCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-LENGTH-01';
    }
    
    public function get_name(): string
    {
        return 'jQuery .length Existence Check';
    }
    
    public function get_description(): string
    {
        return 'Enforces use of .exists() instead of .length for jQuery existence checks';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.js'];
    }
    
    public function get_default_severity(): string
    {
        return 'medium';
    }
    
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in ./rsx/ directory
        if (!str_contains($file_path, '/rsx/') && !str_starts_with($file_path, 'rsx/')) {
            return;
        }
        
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Get both original and sanitized content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);
        
        // Get sanitized content with comments removed
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $sanitized_lines = $sanitized_data['lines'];
        
        foreach ($sanitized_lines as $line_num => $sanitized_line) {
            $line_number = $line_num + 1;
            
            // Skip if the line is empty in sanitized version
            if (trim($sanitized_line) === '') {
                continue;
            }
            
            // Patterns to detect:
            // if($(selector).length)
            // if(!$(selector).length)
            // if($variable.length)
            // if(!$variable.length)
            // Also within compound conditions
            
            // Check if line contains 'if' and '.length'
            if (str_contains($sanitized_line, 'if') && str_contains($sanitized_line, '.length')) {
                // Multiple patterns to check
                $patterns = [
                    // Direct jQuery selector patterns
                    '/if\s*\(\s*!\s*\$\s*\([^)]+\)\.length/',  // if(!$(selector).length
                    '/if\s*\(\s*\$\s*\([^)]+\)\.length/',      // if($(selector).length
                    // jQuery variable patterns
                    '/if\s*\(\s*!\s*\$[a-zA-Z_][a-zA-Z0-9_]*\.length/', // if(!$variable.length
                    '/if\s*\(\s*\$[a-zA-Z_][a-zA-Z0-9_]*\.length/',     // if($variable.length
                    // Within compound conditions (with && or ||)
                    '/if\s*\([^)]*[&|]{2}[^)]*\$\s*\([^)]+\)\.length/', // compound with $(selector).length
                    '/if\s*\([^)]*\$\s*\([^)]+\)\.length[^)]*[&|]{2}/', // compound with $(selector).length
                    '/if\s*\([^)]*[&|]{2}[^)]*\$[a-zA-Z_][a-zA-Z0-9_]*\.length/', // compound with $variable.length
                    '/if\s*\([^)]*\$[a-zA-Z_][a-zA-Z0-9_]*\.length[^)]*[&|]{2}/', // compound with $variable.length
                ];
                
                $found = false;
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $sanitized_line)) {
                        $found = true;
                        break;
                    }
                }
                
                if ($found) {
                    // Check if .length is followed by comparison or assignment operators
                    // These are valid uses: .length > 1, .length = x, etc.
                    if (preg_match('/\.length\s*([><=!]+|[+\-*\/]=)/', $sanitized_line)) {
                        continue; // Skip - this is a numeric comparison or assignment
                    }

                    $original_line = $original_lines[$line_num] ?? $sanitized_line;

                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Use .exists() instead of .length for jQuery existence checks.",
                        trim($original_line),
                        "Replace .length with .exists() for checking jQuery element existence. " .
                        "For example: use '$(selector).exists()' instead of '$(selector).length', " .
                        "or '\$variable.exists()' instead of '\$variable.length'. " .
                        "The .exists() method is more semantic and clearly indicates the intent of checking for element presence.",
                        'medium'
                    );
                }
            }
        }
    }
}