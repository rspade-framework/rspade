<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\Core\Manifest\Manifest;

class JsRspadeInternalMethod_CodeQualityRule extends CodeQualityRule_Abstract
{
    protected static $rspade_classes = null;
    
    public function get_id(): string
    {
        return 'JS-INTERNAL-01';
    }
    
    public function get_name(): string
    {
        return 'JavaScript RSpade Internal Method Usage Check';
    }
    
    public function get_description(): string
    {
        return 'Prohibits calling internal RSpade methods (starting with _) from application code';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.js'];
    }
    
    public function get_default_severity(): string
    {
        return 'high';
    }
    
    /**
     * Get list of RSpade JavaScript classes from manifest
     */
    protected function get_rspade_classes(): array
    {
        if (static::$rspade_classes === null) {
            static::$rspade_classes = [];
            
            // Get all files from manifest
            $all_files = Manifest::get_all();
            
            foreach ($all_files as $file_path => $metadata) {
                // Check if it's a JavaScript file with a class
                $extension = $metadata['extension'] ?? '';
                if (in_array($extension, ['*.js']) && isset($metadata['class'])) {
                    // Handle Windows backslash issue - normalize to forward slashes
                    $normalized_path = str_replace('\\', '/', $file_path);
                    
                    // Check if the file is in app/RSpade directory
                    if (str_contains($normalized_path, 'app/RSpade/')) {
                        static::$rspade_classes[] = $metadata['class'];
                    }
                }
            }
        }
        
        return static::$rspade_classes;
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
        
        // Get RSpade classes
        $rspade_classes = $this->get_rspade_classes();
        
        if (empty($rspade_classes)) {
            return; // No RSpade JS classes found
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
            
            // Check for each RSpade class
            foreach ($rspade_classes as $class_name) {
                // Pattern to match ClassName._method
                // Must be preceded by non-alphanumeric or beginning of line
                $pattern = '/(?:^|[^a-zA-Z0-9_])(' . preg_quote($class_name, '/') . ')\._[a-zA-Z0-9_]+\s*\(/';
                
                if (preg_match($pattern, $sanitized_line, $matches)) {
                    $original_line = $original_lines[$line_num] ?? $sanitized_line;
                    
                    // Extract the method call for better error message
                    preg_match('/' . preg_quote($class_name, '/') . '\._[a-zA-Z0-9_]+/', $sanitized_line, $method_match);
                    $method_call = $method_match[0] ?? $class_name . '._method';
                    
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Calling internal RSpade method. Methods starting with _ are for framework internal use only.",
                        trim($original_line),
                        "Do not call methods starting with underscore on RSpade framework classes. " .
                        "The method '{$method_call}()' is internal to the framework and may change without notice in updates. " .
                        "Use only public API methods (those not starting with underscore).",
                        'high'
                    );
                }
            }
        }
    }
}