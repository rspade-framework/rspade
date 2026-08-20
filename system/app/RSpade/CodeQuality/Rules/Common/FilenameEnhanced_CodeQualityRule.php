<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class FilenameEnhanced_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'FILE-ENHANCED-01';
    }
    
    public function get_name(): string
    {
        return 'Enhanced Filename Check';
    }
    
    public function get_description(): string
    {
        return "Detects 'enhanced' in filenames which indicates parallel implementation - a critical code smell";
    }
    
    public function get_file_patterns(): array
    {
        return ['*.*']; // All files
    }
    
    public function get_default_severity(): string
    {
        return 'critical';
    }
    
    /**
     * Check if a filename contains 'enhanced' which indicates parallel implementation (from line 1741)
     * This is a critical code smell indicating technical debt
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Get just the filename without the directory path
        $filename = basename($file_path);
        
        // Check if filename contains 'enhanced' (case insensitive)
        if (stripos($filename, 'enhanced') !== false) {
            // Check if file has exemption marker
            $content = file_get_contents($file_path);
            if (str_contains($content, '//@enhanced_filename_allowed')) {
                return; // File is explicitly exempted
            }
            
            // Extract the base name without 'enhanced' for suggestion
            $suggested = preg_replace('/[_\-]?enhanced[_\-]?/i', '', $filename);
            
            $resolution = "CRITICAL: Filename contains 'enhanced' which indicates parallel implementation of existing functionality.\n\n";
            $resolution .= "INVESTIGATION REQUIRED - Follow this procedure thoroughly (may take several hours):\n\n";
            $resolution .= "1. IDENTIFY THE RELATIONSHIP:\n";
            $resolution .= "   - Search for similarly named files without 'enhanced' (e.g., if this is 'UserEnhanced.php', look for 'User.php')\n";
            $resolution .= "   - Use grep/search to find: '{$suggested}' and variations\n";
            $resolution .= "   - Check git history to understand when and why the 'enhanced' version was created\n\n";
            $resolution .= "2. ANALYZE THE INVOCATION:\n";
            $resolution .= "   - Search the codebase for references to '{$filename}' to see how it's used\n";
            $resolution .= "   - Look for conditional logic, switches, or configuration that chooses between versions\n";
            $resolution .= "   - Identify any fallback patterns where one version is tried before another\n\n";
            $resolution .= "3. COMPARE FUNCTIONALITY:\n";
            $resolution .= "   - Diff the enhanced file against the original (if found)\n";
            $resolution .= "   - Identify what improvements were made in the enhanced version\n";
            $resolution .= "   - Check if the original has any functionality missing from enhanced\n";
            $resolution .= "   - Document the differences and determine completeness of enhanced version\n\n";
            $resolution .= "4. DETERMINE MIGRATION PATH:\n";
            $resolution .= "   If enhanced version is a complete replacement:\n";
            $resolution .= "   - Verify enhanced version has ALL necessary functionality from original\n";
            $resolution .= "   - Remove the old/original file\n";
            $resolution .= "   - Rename enhanced file to the original name (e.g., 'UserEnhanced.php' -> 'User.php')\n";
            $resolution .= "   - Update all references to use the single, renamed file\n";
            $resolution .= "   - Remove any conditional/switching/fallback code\n\n";
            $resolution .= "   If versions serve different purposes:\n";
            $resolution .= "   - Rename the enhanced file to better describe its specific purpose\n";
            $resolution .= "   - Update documentation to clarify the distinct roles\n\n";
            $resolution .= "5. IF UNCLEAR:\n";
            $resolution .= "   Present findings to the user including:\n";
            $resolution .= "   - List of similar files found\n";
            $resolution .= "   - How each version is invoked\n";
            $resolution .= "   - Key differences between versions\n";
            $resolution .= "   - Request guidance on consolidation strategy\n\n";
            $resolution .= "IMPORTANT: The goal is to eliminate dual implementations. Having both 'document_parser.php' and 'enhanced_document_parser.php' creates:\n";
            $resolution .= "- Confusion about which to use\n";
            $resolution .= "- Maintenance burden keeping both in sync\n";
            $resolution .= "- Potential bugs from inconsistent behavior\n";
            $resolution .= "- Technical debt that compounds over time\n\n";
            $resolution .= "To mark legitimate use (extremely rare): Add '//@enhanced_filename_allowed' comment to the file.\n";
            $resolution .= "This should only be done when 'enhanced' is genuinely part of the domain language, not indicating an upgrade.";
            
            $this->add_violation(
                $file_path,
                0,
                "CRITICAL: Filename contains 'enhanced' which indicates parallel implementation of existing functionality.",
                $filename,
                $resolution,
                'critical'
            );
        }
    }
}