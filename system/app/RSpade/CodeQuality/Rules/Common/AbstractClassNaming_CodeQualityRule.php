<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class AbstractClassNaming_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'ABSTRACT-CLASS-01';
    }
    
    public function get_name(): string
    {
        return 'Abstract Class Naming Convention';
    }
    
    public function get_description(): string
    {
        return 'Ensures abstract classes follow RSX naming conventions with _Abstract suffix';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.php', '*.js'];
    }
    
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip -temp files
        if (str_contains($file_path, '-temp.')) {
            return;
        }
        
        // Only check files in ./rsx and ./app/RSpade
        $is_rsx = str_contains($file_path, '/rsx/');
        $is_rspade = str_contains($file_path, '/app/RSpade/');

        if (!$is_rsx && !$is_rspade) {
            return;
        }

        // If in app/RSpade, check if it's in an allowed subdirectory
        if ($is_rspade && !$this->is_in_allowed_rspade_directory($file_path)) {
            return;
        }
        
        $filename = basename($file_path);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        // Handle PHP files
        if ($extension === 'php') {
            $this->check_php_abstract_class($file_path, $contents, $metadata);
        }
        // Handle JavaScript files
        elseif ($extension === 'js') {
            $this->check_js_abstract_class($file_path, $contents, $metadata);
        }
    }
    
    private function check_php_abstract_class(string $file_path, string $contents, array $metadata): void
    {
        // Check if this is an abstract class
        if (!preg_match('/^\s*abstract\s+class\s+(\w+)/m', $contents, $matches)) {
            return; // Not an abstract class
        }
        
        $class_name = $matches[1];
        $filename = basename($file_path);
        $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        
        // Check class name ends with _Abstract
        if (!str_ends_with($class_name, '_Abstract')) {
            $this->add_violation(
                $file_path,
                0,
                "Abstract class '$class_name' must end with '_Abstract'",
                "abstract class $class_name",
                $this->get_abstract_class_remediation($class_name, $filename, true),
                'high'
            );
            return; // Don't check filename if class name is wrong
        }
        
        // Check filename ends with _abstract.php or Abstract.php
        if (!str_ends_with($filename, '_abstract.php') && !str_ends_with($filename, 'Abstract.php')) {
            $this->add_violation(
                $file_path,
                0,
                "Abstract class file '$filename' must end with '_abstract.php' or 'Abstract.php'",
                "File: $filename",
                $this->get_abstract_filename_remediation($class_name, $filename),
                'medium'
            );
        }
    }
    
    private function check_js_abstract_class(string $file_path, string $contents, array $metadata): void
    {
        // Check for classes ending with _Abstract
        if (!isset($metadata['class'])) {
            return;
        }
        
        $class_name = $metadata['class'];
        
        // Only check classes that end with _Abstract
        if (!str_ends_with($class_name, '_Abstract')) {
            return;
        }
        
        $filename = basename($file_path);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        // Check filename ends with _abstract.js or Abstract.js
        $valid_endings = ["_abstract.$extension", "Abstract.$extension"];
        $valid = false;
        foreach ($valid_endings as $ending) {
            if (str_ends_with($filename, $ending)) {
                $valid = true;
                break;
            }
        }
        
        if (!$valid) {
            $this->add_violation(
                $file_path,
                0,
                "Abstract class file '$filename' must end with '_abstract.$extension' or 'Abstract.$extension'",
                "File: $filename",
                $this->get_abstract_filename_remediation($class_name, $filename),
                'medium'
            );
        }
    }
    
    private function get_abstract_class_remediation(string $class_name, string $filename, bool $is_php): string
    {
        // Determine suggested class name based on current naming
        $suggested_class = $this->suggest_abstract_class_name($class_name);
        $is_rsx = str_contains($filename, '_');
        
        return "ABSTRACT CLASS NAMING CONVENTION

Abstract classes must follow RSX naming patterns:
- Class name must end with '_Abstract'
- Filename must end with '_abstract.php' or 'Abstract.php'

CURRENT CLASS: $class_name
SUGGESTED CLASS: $suggested_class

SUGGESTED FIXES:
1. Rename class from '$class_name' to '$suggested_class'
2. Rename file to match:
   - For /rsx: Use lowercase convention (e.g., " . strtolower(str_replace('_', '_', $suggested_class)) . ".php)
   - For /app/RSpade: Use exact match (e.g., $suggested_class.php)
3. Update all references to this class

WHY THIS MATTERS:
- Makes abstract classes immediately identifiable
- Enables framework introspection and auto-discovery
- Maintains consistent naming patterns across the codebase";
    }
    
    private function get_abstract_filename_remediation(string $class_name, string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $lowercase_suggestion = strtolower(str_replace('_', '_', $class_name)) . ".$extension";
        $exact_suggestion = $class_name . ".$extension";
        
        return "ABSTRACT CLASS FILENAME CONVENTION

Abstract class files must end with '_abstract.$extension' or 'Abstract.$extension'

CURRENT FILE: $filename
CLASS NAME: $class_name

VALID FILENAME PATTERNS:
- Lowercase with underscore: *_abstract.$extension
- Uppercase suffix: *Abstract.$extension

SUGGESTED FILENAMES:
- For /rsx: $lowercase_suggestion
- For /app/RSpade: $exact_suggestion

Note: Both patterns are valid in either directory.";
    }
    
    private function suggest_abstract_class_name(string $current_name): string
    {
        // If class name contains 'Abstract' but not at the end
        if (stripos($current_name, 'Abstract') !== false && !str_ends_with($current_name, '_Abstract') && !str_ends_with($current_name, 'Abstract')) {
            // Remove 'Abstract' from wherever it is
            $without_abstract = preg_replace('/Abstract/i', '', $current_name);
            $without_abstract = trim($without_abstract, '_');
            
            // If the class has underscores, add _Abstract
            if (str_contains($without_abstract, '_')) {
                return $without_abstract . '_Abstract';
            } else {
                // For non-underscore classes, add Abstract at the end
                return $without_abstract . 'Abstract';
            }
        }
        
        // If class doesn't contain 'Abstract' at all
        if (str_contains($current_name, '_')) {
            return $current_name . '_Abstract';
        } else {
            return $current_name . 'Abstract';
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