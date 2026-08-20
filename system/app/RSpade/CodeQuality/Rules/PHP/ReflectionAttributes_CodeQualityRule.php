<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class ReflectionAttributes_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Files that are whitelisted because they are part of the manifest building process
     * or core reflection utilities
     */
    private const WHITELISTED_FILES = [
        'Manifest.php',
        'Php_ManifestModule.php',
        'RsxReflection.php',  // Core reflection utility that may be used before manifest is available
    ];
    
    public function get_id(): string
    {
        return 'PHP-ATTR-01';
    }
    
    public function get_name(): string
    {
        return 'Reflection getAttributes() Usage Check';
    }
    
    public function get_description(): string
    {
        return 'Prohibits direct use of reflection getAttributes() - use Manifest API instead';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }
    
    public function get_default_severity(): string
    {
        return 'high';
    }
    
    /**
     * Check PHP file for $reflection->getAttributes() usage
     * Code should use the Manifest API instead of direct reflection
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Check if file is whitelisted (manifest building files)
        foreach (self::WHITELISTED_FILES as $whitelisted) {
            if (str_ends_with($file_path, $whitelisted)) {
                return;
            }
        }
        
        // Only check files in /app/RSpade and /rsx directories
        if (!str_contains($file_path, '/app/RSpade/') && !str_contains($file_path, '/rsx/')) {
            return;
        }

        // If in app/RSpade, check if it's in an allowed subdirectory
        if (str_contains($file_path, '/app/RSpade/') && !$this->is_in_allowed_rspade_directory($file_path)) {
            return;
        }
        
        // Get both original and sanitized content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);
        
        // Get sanitized content with comments removed
        $sanitized_data = FileSanitizer::sanitize_php($contents);
        $sanitized_lines = $sanitized_data['lines'];
        
        foreach ($sanitized_lines as $line_num => $sanitized_line) {
            $line_number = $line_num + 1;
            
            // Skip if the line is empty in sanitized version (was a comment)
            if (trim($sanitized_line) === '') {
                continue;
            }
            
            // Check for getAttributes() usage on reflection objects
            // This pattern catches $reflection->getAttributes(), $method->getAttributes(), etc.
            if (preg_match('/->getAttributes\s*\(/', $sanitized_line)) {
                // Skip if it's getAttributes on an Eloquent model (different method)
                if (preg_match('/\$\w+->getAttributes\(\)/', $sanitized_line) && 
                    !preg_match('/\$reflection|\$method|\$property|\$class/', $sanitized_line)) {
                    // Likely an Eloquent model method, skip
                    continue;
                }
                
                $original_line = $original_lines[$line_num] ?? $sanitized_line;
                
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Direct use of reflection getAttributes() is not allowed. Use the Manifest API instead.",
                    trim($original_line),
                    "Use Manifest API methods to access attribute information:\n" .
                    "- Manifest::get_all() - Returns full manifest with all metadata\n" .
                    "- Manifest::php_get_metadata_by_class(\$class_name) - Get metadata for a specific class\n" .
                    "- Manifest::get_with_attribute(\$attribute_class) - Find classes/methods with specific attributes\n" .
                    "Manifest structure: Each file in manifest contains 'methods' array with 'attributes' for each method.\n" .
                    "Example: \$manifest[\$file]['methods'][\$method_name]['attributes'] contains all attributes.",
                    'high'
                );
            }
        }
    }

    /**
     * Check if a file in /app/RSpade/ is in an allowed subdirectory
     * Based on scan_directories configuration
     */
    private function is_in_allowed_rspade_directory(string $file_path): bool
    {
        // Get allowed subdirectories from config
        $scan_directories = config('rsx.manifest.scan_directories', []);

        // Extract allowed RSpade subdirectories
        $allowed_subdirs = [];
        foreach ($scan_directories as $scan_dir) {
            if (str_starts_with($scan_dir, 'app/RSpade/')) {
                $subdir = substr($scan_dir, strlen('app/RSpade/'));
                if ($subdir) {
                    $allowed_subdirs[] = $subdir;
                }
            }
        }

        // Check if file is in any allowed subdirectory
        foreach ($allowed_subdirs as $subdir) {
            if (str_contains($file_path, '/app/RSpade/' . $subdir . '/') ||
                str_contains($file_path, '/app/RSpade/' . $subdir)) {
                return true;
            }
        }

        return false;
    }
}