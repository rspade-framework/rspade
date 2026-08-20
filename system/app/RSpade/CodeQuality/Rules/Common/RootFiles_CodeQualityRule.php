<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class RootFiles_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'FILE-ROOT-01';
    }
    
    public function get_name(): string
    {
        return 'Root Files Check';
    }
    
    public function get_description(): string
    {
        return 'Check for unauthorized files in project root - only whitelisted build configuration files should be in root';
    }
    
    public function get_file_patterns(): array
    {
        // This rule works differently - it checks root directory, not individual files
        return [];
    }
    
    public function get_default_severity(): string
    {
        return 'medium';
    }
    
    /**
     * Check for unauthorized PHP/JS files in project root (from line 1602)
     * Only whitelisted build configuration files should be in root
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // This rule needs special handling - it should be called once, not per file
        // We'll handle this in the check_root() method instead
    }
    
    /**
     * Special method to check root files - called once per run
     */
    public function check_root(): void
    {
        $project_root = function_exists('base_path') ? base_path() : '/var/www/html';
        $whitelist = function_exists('config') ? config('rsx.code_quality.root_whitelist', []) : [];
        
        // Get all PHP and JS files in root (not subdirectories)
        $files = glob($project_root . '/*.{php,js}', GLOB_BRACE);
        
        foreach ($files as $file_path) {
            $filename = basename($file_path);
            
            // Skip if whitelisted
            if (in_array($filename, $whitelist)) {
                continue;
            }
            
            $this->add_violation(
                $file_path,
                0,
                "Unauthorized file '{$filename}' found in project root. Only whitelisted build configuration files should exist in the root directory.",
                null,
                "This file appears to be a one-off test script that should be removed before commit. " .
                "LLM agents often create test files in the root directory for testing specific features. " .
                "These should be removed or moved to proper test directories. " .
                "If this file is legitimately needed in the root, add '{$filename}' to the whitelist in config/rsx.php under 'code_quality.root_whitelist'.",
                'medium'
            );
        }
    }
}