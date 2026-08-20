<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * RsxControllerInheritanceRule - Enforces proper controller inheritance in RSX
 *
 * RSX controller classes must extend Rsx_Controller_Abstract or one of its subclasses,
 * not Laravel's base Controller class. This ensures RSX controllers have access to
 * RSX-specific functionality and follow the framework's conventions.
 */
class RsxControllerInheritance_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'MANIFEST-CTRL-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'RSX Controller Inheritance Validation';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Enforces that RSX controllers extend Rsx_Controller_Abstract, not Laravel\'s Controller';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * This rule runs during manifest scan
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * This is a cross-file rule - needs full manifest context
     */
    public function is_incremental(): bool
    {
        return false;
    }

    /**
     * Check for RSX controllers extending Laravel's Controller
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;

        // Only check once per manifest build
        if ($already_checked) {
            return;
        }
        $already_checked = true;

        // Get all manifest files
        $files = \App\RSpade\Core\Manifest\Manifest::get_all();
        if (empty($files)) {
            return;
        }

        // Check each PHP class in ./rsx directory
        foreach ($files as $file => $file_metadata) {
            // Only check PHP files with classes in ./rsx directory
            if (!isset($file_metadata['class'])) {
                continue;
            }

            if (!str_starts_with($file, 'rsx/')) {
                continue;
            }

            // Check if this class directly extends "Controller"
            $extends = $file_metadata['extends'] ?? null;
            if ($extends !== 'Controller') {
                continue;
            }

            // Found violation - RSX controller extending Laravel's Controller
            // Find line number of class declaration
            $line = $this->find_class_line($file, $file_metadata['class']);

            $this->add_violation(
                $file,
                $line,
                "RSX controller class '{$file_metadata['class']}' extends Laravel's Controller class. " .
                "RSX controllers must extend Rsx_Controller_Abstract or one of its subclasses to access " .
                "RSX-specific functionality and follow framework conventions.",
                "class {$file_metadata['class']} extends Controller",
                "Change the parent class from 'Controller' to 'Rsx_Controller_Abstract':\n" .
                "  class {$file_metadata['class']} extends Rsx_Controller_Abstract",
                'high'
            );
        }
    }

    /**
     * Find the line number where a class is declared
     */
    private function find_class_line(string $file_path, string $class_name): int
    {
        $absolute_path = base_path($file_path);
        if (!file_exists($absolute_path)) {
            return 1;
        }

        $contents = file_get_contents($absolute_path);
        $lines = explode("\n", $contents);

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:abstract\s+)?class\s+' . preg_quote($class_name) . '\b/', $line)) {
                return $index + 1;
            }
        }

        return 1;
    }
}
