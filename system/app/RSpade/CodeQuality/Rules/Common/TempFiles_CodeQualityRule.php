<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class TempFiles_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'FILE-TEMP-01';
    }
    
    public function get_name(): string
    {
        return 'Temporary Files Check';
    }
    
    public function get_description(): string
    {
        return 'Check for temporary files ending in -temp that should be removed before commit';
    }
    
    public function get_file_patterns(): array
    {
        return ['*']; // Check all files
    }
    
    public function get_default_severity(): string
    {
        return 'high';
    }
    
    /**
     * Check for files ending in -temp before their extension
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only run during pre-commit tests
        $pre_commit_tests = $this->config['pre_commit_tests'] ?? false;
        if (!$pre_commit_tests) {
            return;
        }
        
        // Check if filename contains -temp before the extension
        $filename = basename($file_path);
        
        // Match files like test-temp.php, module-temp.js, etc.
        if (preg_match('/^(.+)-temp(\.[^.]+)?$/', $filename, $matches)) {
            $this->add_violation(
                $file_path,
                0,
                "Temporary file '{$filename}' detected. Files ending in '-temp' should be removed before commit.",
                $filename,
                "The '-temp' suffix indicates this is a temporary file for testing or development.\n" .
                "These files should not be committed to the repository.\n\n" .
                "Options:\n" .
                "1. Remove the file if it's no longer needed\n" .
                "2. Rename the file without '-temp' if it should be kept\n" .
                "3. Move to a proper test directory if it's a test file",
                'high'
            );
        }
    }
}