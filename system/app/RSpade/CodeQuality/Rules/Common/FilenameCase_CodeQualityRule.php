<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class FilenameCase_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'FILE-CASE-01';
    }
    
    public function get_name(): string
    {
        return 'Filename Case Check';
    }
    
    public function get_description(): string
    {
        return 'All files in rsx/ should be lowercase';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.*']; // All files
    }
    
    public function get_default_severity(): string
    {
        return 'low';
    }
    
    /**
     * Check if a filename contains uppercase characters (for RSX files) - from line 1706
     * Excludes vendor and resource directories, and .md files
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and resource directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/resource/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Only check files in rsx/ directory
        if (!str_contains($file_path, '/rsx/')) {
            return;
        }
        
        // Get just the filename without the directory path
        $filename = basename($file_path);
        
        // Skip .md files
        if (str_ends_with($filename, '.md')) {
            return;
        }
        
        // Check if filename contains uppercase characters
        if (preg_match('/[A-Z]/', $filename)) {
            // Convert to lowercase for suggestion
            $suggested = strtolower($filename);
            
            $this->add_violation(
                $file_path,
                0,
                "Filename '{$filename}' contains uppercase characters. All files in rsx/ should be lowercase.",
                $filename,
                "Rename file to '{$suggested}'. Remember: class names should still use First_Letter_Uppercase format.",
                'low'
            );
        }
    }
}