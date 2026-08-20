<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class NativeFunction_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-NATIVE-01';
    }
    
    public function get_name(): string
    {
        return 'JavaScript Native Function Usage Check';
    }
    
    public function get_description(): string
    {
        return 'Enforces RSpade functions instead of native JavaScript functions';
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
            
            // Native function patterns and their replacements
            $function_patterns = [
                // Array.isArray()
                [
                    'pattern' => '/(?:^|[^a-zA-Z0-9_])Array\.isArray\s*\(/i',
                    'native' => 'Array.isArray()',
                    'replacement' => 'is_array()',
                    'message' => 'Use is_array() instead of Array.isArray().'
                ],
                // parseFloat()
                [
                    'pattern' => '/(?:^|[^a-zA-Z0-9_])parseFloat\s*\(/i',
                    'native' => 'parseFloat()',
                    'replacement' => 'float()',
                    'message' => 'Use float() instead of parseFloat().'
                ],
                // parseInt()
                [
                    'pattern' => '/(?:^|[^a-zA-Z0-9_])parseInt\s*\(/i',
                    'native' => 'parseInt()',
                    'replacement' => 'int()',
                    'message' => 'Use int() instead of parseInt().'
                ],
                // String() constructor
                [
                    'pattern' => '/(?:^|[^a-zA-Z0-9_])String\s*\(/i',
                    'native' => 'String()',
                    'replacement' => 'str()',
                    'message' => 'Use str() instead of String().'
                ],
                // encodeURIComponent()
                [
                    'pattern' => '/(?:^|[^a-zA-Z0-9_])encodeURIComponent\s*\(/i',
                    'native' => 'encodeURIComponent()',
                    'replacement' => 'urlencode()',
                    'message' => 'Use urlencode() instead of encodeURIComponent().'
                ],
                // decodeURIComponent()
                [
                    'pattern' => '/(?:^|[^a-zA-Z0-9_])decodeURIComponent\s*\(/i',
                    'native' => 'decodeURIComponent()',
                    'replacement' => 'urldecode()',
                    'message' => 'Use urldecode() instead of decodeURIComponent().'
                ],
                // JSON.stringify()
                [
                    'pattern' => '/(?:^|[^a-zA-Z0-9_])JSON\.stringify\s*\(/i',
                    'native' => 'JSON.stringify()',
                    'replacement' => 'json_encode()',
                    'message' => 'Use json_encode() instead of JSON.stringify().'
                ],
                // JSON.parse()
                [
                    'pattern' => '/(?:^|[^a-zA-Z0-9_])JSON\.parse\s*\(/i',
                    'native' => 'JSON.parse()',
                    'replacement' => 'json_decode()',
                    'message' => 'Use json_decode() instead of JSON.parse().'
                ],
            ];
            
            foreach ($function_patterns as $check) {
                if (preg_match($check['pattern'], $sanitized_line)) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        $check['message'],
                        trim($original_line),
                        "Replace '{$check['native']}' with '{$check['replacement']}'. " .
                        "RSpade provides PHP-like functions that should be used instead of native JavaScript functions. " .
                        "This provides consistency across PHP and JavaScript code and ensures predictable behavior.",
                        'medium'
                    );
                    break; // Only report first match per line
                }
            }
        }
    }
}