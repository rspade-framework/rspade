<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class JQueryVisibilityCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-VISIBLE-01';
    }
    
    public function get_name(): string
    {
        return 'jQuery .is(\':visible\') Check';
    }
    
    public function get_description(): string
    {
        return 'Enforces use of .is_visible() instead of .is(\':visible\') for jQuery visibility checks';
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
        
        // Get original content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);
        
        // Pattern to match .is(':visible') or .is(":visible")
        $pattern = '/\.is\s*\(\s*[\'\"]:visible[\'\"]\s*\)/';
        
        foreach ($original_lines as $line_num => $original_line) {
            $line_number = $line_num + 1;
            
            // Skip empty lines
            if (trim($original_line) === '') {
                continue;
            }
            
            // Check if line contains .is(':visible') pattern
            if (preg_match($pattern, $original_line, $matches, PREG_OFFSET_CAPTURE)) {
                $match_position = $matches[0][1];
                
                // Check if there's a // comment before the match
                $comment_pos = strpos($original_line, '//');
                if ($comment_pos !== false && $comment_pos < $match_position) {
                    // The match is in a comment, skip it
                    continue;
                }
                
                // Check if the match is inside a /* */ comment block (simplified check)
                // This is a basic check - for full accuracy would need to track multi-line comments
                $before_match = substr($original_line, 0, $match_position);
                if (str_contains($before_match, '/*') && !str_contains($before_match, '*/')) {
                    // Likely inside a block comment
                    continue;
                }
                
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use .is_visible() instead of .is(':visible') for jQuery visibility checks.",
                    trim($original_line),
                    "Replace .is(':visible') with .is_visible() for checking jQuery element visibility. " .
                    "IMPORTANT: .is_visible() is a custom jQuery extension implemented in the RSpade framework " .
                    "and is available throughout the codebase. It provides better performance and clearer intent " .
                    "than the :visible selector. Example: use '$(selector).is_visible()' instead of '$(selector).is(':visible')'.",
                    'medium'
                );
            }
        }
    }
}