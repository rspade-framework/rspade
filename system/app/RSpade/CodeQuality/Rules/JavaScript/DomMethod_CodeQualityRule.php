<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class DomMethod_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-DOM-01';
    }
    
    public function get_name(): string
    {
        return 'JavaScript DOM Method Usage Check';
    }
    
    public function get_description(): string
    {
        return 'Enforces jQuery instead of native DOM methods';
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
            
            // Check for document.getElementById
            if (preg_match('/\bdocument\.getElementById\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.getElementById(id)' with '$('#' + id)' or use a jQuery selector directly like $('#myId'). " .
                    "jQuery provides a more consistent and powerful API for DOM manipulation that works across all browsers.",
                    'medium'
                );
            }
            
            // Check for document.createElement
            if (preg_match('/\bdocument\.createElement\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.createElement(tagName)' with '$('<' + tagName + '>')' or use jQuery element creation like $('<div>'). " .
                    "jQuery provides a more fluent API for creating and manipulating DOM elements.",
                    'medium'
                );
            }
            
            // Check for document.getElementsByClassName
            if (preg_match('/\bdocument\.getElementsByClassName\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.getElementsByClassName(className)' with $('.' + className) or use a jQuery class selector directly like $('.myClass'). " .
                    "jQuery provides a more consistent API that returns a jQuery object with many useful methods.",
                    'medium'
                );
            }
            
            // Check for document.getElementsByTagName
            if (preg_match('/\bdocument\.getElementsByTagName\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.getElementsByTagName(tagName)' with $(tagName) or use a jQuery tag selector like $('div'). " .
                    "jQuery provides a unified API for element selection.",
                    'medium'
                );
            }
            
            // Check for document.querySelector
            if (preg_match('/\bdocument\.querySelector\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.querySelector(selector)' with $(selector). " .
                    "jQuery's selector engine is more powerful and consistent across browsers.",
                    'medium'
                );
            }
            
            // Check for document.querySelectorAll
            if (preg_match('/\bdocument\.querySelectorAll\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.querySelectorAll(selector)' with $(selector). " .
                    "jQuery automatically handles collections and provides chainable methods.",
                    'medium'
                );
            }
        }
    }
}