<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class RsxTestFiles_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'FILE-RSX-01';
    }
    
    public function get_name(): string
    {
        return 'RSX Test Files Check';
    }
    
    public function get_description(): string
    {
        return 'Check for test files in rsx/ directory and rsx/temp - test files should be in proper test directories';
    }
    
    public function get_file_patterns(): array
    {
        // This rule works differently - it checks rsx directory structure, not individual files
        return [];
    }
    
    public function get_default_severity(): string
    {
        return 'medium';
    }
    
    /**
     * Check for test files in rsx/ directory (from line 1636)
     * Test files should be in proper test directories, not loose in rsx/
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // This rule needs special handling - it should be called once, not per file
        // We'll handle this in the check_rsx() method instead
    }
    
    /**
     * Special method to check rsx files - called once per run
     */
    public function check_rsx(): void
    {
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $rsx_dir = $base_path . '/rsx';
        $whitelist = function_exists('config') ? config('rsx.code_quality.rsx_test_whitelist', []) : [];
        
        // Check for temp directories only if pre-commit-tests is enabled
        $pre_commit_tests = $this->config['pre_commit_tests'] ?? false;
        if ($pre_commit_tests) {
            // Check both rsx/temp and app/RSpade/temp
            $temp_dirs = [
                $rsx_dir . '/temp' => 'rsx/temp',
                $base_path . '/app/RSpade/temp' => 'app/RSpade/temp'
            ];
            
            foreach ($temp_dirs as $temp_dir => $temp_name) {
                if (is_dir($temp_dir)) {
                    $temp_files = array_merge(
                        glob($temp_dir . '/*.php'),
                        glob($temp_dir . '/*.js'),
                        glob($temp_dir . '/*')
                    );
                    
                    // Remove duplicates and filter out directories
                    $temp_files = array_unique($temp_files);
                    $temp_files = array_filter($temp_files, 'is_file');
                    
                    if (!empty($temp_files)) {
                        // Files exist in temp directory - report violation
                        foreach ($temp_files as $file) {
                            $this->add_violation(
                                $file,
                                0,
                                "File found in {$temp_name} directory. All files in {$temp_name} should be removed prior to commit.",
                                basename($file),
                                "The {$temp_name} directory is for temporary test files during development. Remove this file before committing.",
                                'high'  // High severity for pre-commit
                            );
                        }
                    } else {
                        // Directory exists but is empty - silently remove it
                        @rmdir($temp_dir);
                    }
                }
            }
        }
        
        // Get all PHP and JS files in rsx/ (not subdirectories)
        $php_files = glob($rsx_dir . '/*.php');
        $js_files = glob($rsx_dir . '/*.js');
        $files = array_merge($php_files, $js_files);
        
        foreach ($files as $file_path) {
            $filename = basename($file_path);
            
            // Check if filename contains 'test' (case insensitive)
            if (stripos($filename, 'test') === false) {
                continue; // Not a test file
            }
            
            // Skip if whitelisted
            if (in_array($filename, $whitelist)) {
                continue;
            }
            
            $this->add_violation(
                $file_path,
                0,
                "Test file '{$filename}' found directly in rsx/ directory. Test files should be organized in proper test subdirectories.",
                $filename,
                "This appears to be a temporary test file that should be removed before commit. " .
                "LLM agents often create test files for verifying specific functionality. " .
                "Move this file to a proper test directory (e.g., rsx/rsx_tests/ or rsx/app/tests/) or remove it. " .
                "If this file is legitimately needed in rsx/, add '{$filename}' to the whitelist in config/rsx.php under 'code_quality.rsx_test_whitelist'.",
                'medium'
            );
        }
    }
}