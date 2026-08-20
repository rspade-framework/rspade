<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class TypeofCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-TYPEOF-01';
    }
    
    public function get_name(): string
    {
        return 'JavaScript typeof Usage Check';
    }
    
    public function get_description(): string
    {
        return 'Enforces RSpade type checking functions instead of typeof';
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
            
            $original_line = $original_lines[$line_num] ?? $sanitized_line;
            
            // Check for typeof patterns and their replacements
            $typeof_patterns = [
                // typeof something == 'string' or === 'string' (single or double quotes)
                [
                    'pattern' => '/typeof\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*[!=]=+\s*[\'"]string[\'"]/i',
                    'replacement' => 'is_string($1)',
                    'message' => 'Use is_string() instead of typeof for string type checking.'
                ],
                // typeof something == 'object' or === 'object'
                [
                    'pattern' => '/typeof\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*[!=]=+\s*[\'"]object[\'"]/i',
                    'replacement' => 'is_object($1)',
                    'message' => 'Use is_object() instead of typeof for object type checking.'
                ],
                // typeof value != 'undefined' or !== 'undefined'
                [
                    'pattern' => '/typeof\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*!==?\s*[\'"]undefined[\'"]/i',
                    'replacement' => 'isset($1)',
                    'message' => 'Use isset() instead of typeof for undefined checking.'
                ],
                // typeof value == 'undefined' or === 'undefined'
                [
                    'pattern' => '/typeof\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*===?\s*[\'"]undefined[\'"]/i',
                    'replacement' => '!isset($1)',
                    'message' => 'Use !isset() instead of typeof for undefined checking.'
                ],
                // typeof something == 'number' or === 'number'
                [
                    'pattern' => '/typeof\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*[!=]=+\s*[\'"]number[\'"]/i',
                    'replacement' => 'is_numeric($1)',
                    'message' => 'Use is_numeric() instead of typeof for number type checking.'
                ],
                // typeof something == 'boolean' or === 'boolean'
                [
                    'pattern' => '/typeof\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*[!=]=+\s*[\'"]boolean[\'"]/i',
                    'replacement' => 'is_bool($1)',
                    'message' => 'Use is_bool() instead of typeof for boolean type checking.'
                ],
                // typeof something == 'function' or === 'function'
                [
                    'pattern' => '/typeof\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*[!=]=+\s*[\'"]function[\'"]/i',
                    'replacement' => 'is_callable($1)',
                    'message' => 'Use is_callable() instead of typeof for function type checking.'
                ],
            ];
            
            foreach ($typeof_patterns as $check) {
                if (preg_match($check['pattern'], $sanitized_line, $matches)) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        $check['message'],
                        trim($original_line),
                        "Replace with '{$check['replacement']}'. " .
                        "RSpade provides PHP-like type checking functions that should be used instead of native JavaScript typeof. " .
                        "This provides consistency across PHP and JavaScript code.",
                        'medium'
                    );
                    break; // Only report first match per line
                }
            }
        }
    }
}