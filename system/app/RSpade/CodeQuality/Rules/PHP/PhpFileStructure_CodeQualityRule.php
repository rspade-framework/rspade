<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Enforces PHP file structure restrictions for files without classes.
 *
 * PHP files without classes may only contain:
 * 1. Function definitions
 * 2. define() calls for constants
 * 3. namespace declarations
 * 4. use statements
 * 5. declare() directives
 * 6. Comments (single and multi-line)
 *
 * Also checks for duplicate function and constant names across PHP files.
 */
class PhpFileStructure_CodeQualityRule extends CodeQualityRule_Abstract
{
    private array $all_global_functions = [];
    private array $all_global_constants = [];
    private array $all_global_names = []; // Combined for conflict checking

    public function get_id(): string
    {
        return 'PHP-STRUCTURE-01';
    }

    public function get_name(): string
    {
        return 'PHP File Structure Validator';
    }

    public function get_description(): string
    {
        return 'Enforces PHP file structure restrictions and checks for duplicate global names';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Whether this rule runs during manifest scan
     * This rule runs at manifest time to check for structure violations
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // This rule runs at manifest build time
    }

    /**
     * Main check method - called by CodeQualityChecker
     * For manifest-time rules, this aggregates data across all files
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only process during manifest-time when we have all files
        // This rule needs to scan all files to check for duplicates
        static $already_run = false;
        if ($already_run) {
            return;
        }

        // On the first PHP file, process all files
        if (!empty($metadata) && $metadata['extension'] === 'php') {
            $this->process_all_files();
            $already_run = true;
        }
    }

    private function process_all_files(): void
    {
        // Get all files from manifest
        $files = Manifest::get_all();

        foreach ($files as $file_path => $metadata) {
            // Skip non-PHP files
            if (($metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            // Only apply rules to files in ./rsx directory
            if (!str_starts_with($file_path, 'rsx/')) {
                continue;
            }

            // Check for structure violations (applies to both class and classless files)
            if (isset($metadata['structure_violations'])) {
                foreach ($metadata['structure_violations'] as $violation) {
                    $message = $violation['message'];
                    $line = $violation['line'] ?? 0;

                    $this->add_violation(
                        $file_path,
                        $line,
                        $message,
                        '', // code snippet
                        'Remove disallowed construct from global scope or move into a function',
                        'critical'
                    );
                }
            }

            // Collect global functions for duplicate checking
            if (isset($metadata['global_functions'])) {
                foreach ($metadata['global_functions'] as $func_name) {
                    if (isset($this->all_global_functions[$func_name])) {
                        // Duplicate function found
                        $this->throw_duplicate_function($func_name, $this->all_global_functions[$func_name], $file_path);
                    }
                    $this->all_global_functions[$func_name] = $file_path;

                    // Also add to combined names for conflict checking
                    if (isset($this->all_global_names[$func_name])) {
                        $this->throw_name_conflict($func_name, 'function', $file_path, $this->all_global_names[$func_name]);
                    }
                    $this->all_global_names[$func_name] = ['type' => 'function', 'file' => $file_path];
                }
            }

            // Collect global constants for duplicate checking
            if (isset($metadata['global_constants'])) {
                foreach ($metadata['global_constants'] as $const_name) {
                    if (isset($this->all_global_constants[$const_name])) {
                        // Duplicate constant found
                        $this->throw_duplicate_constant($const_name, $this->all_global_constants[$const_name], $file_path);
                    }
                    $this->all_global_constants[$const_name] = $file_path;

                    // Also add to combined names for conflict checking
                    if (isset($this->all_global_names[$const_name])) {
                        $this->throw_name_conflict($const_name, 'constant', $file_path, $this->all_global_names[$const_name]);
                    }
                    $this->all_global_names[$const_name] = ['type' => 'constant', 'file' => $file_path];
                }
            }
        }
    }

    private function throw_duplicate_function(string $name, string $first_file, string $second_file): void
    {
        throw new \RuntimeException(
            "Duplicate global PHP function '{$name}' found:\n" .
            "  First defined in: {$first_file}\n" .
            "  Also defined in: {$second_file}\n" .
            "\nGlobal function names must be unique across all PHP files."
        );
    }

    private function throw_duplicate_constant(string $name, string $first_file, string $second_file): void
    {
        throw new \RuntimeException(
            "Duplicate global PHP constant '{$name}' found:\n" .
            "  First defined in: {$first_file}\n" .
            "  Also defined in: {$second_file}\n" .
            "\nGlobal constant names must be unique across all PHP files."
        );
    }

    private function throw_name_conflict(string $name, string $current_type, string $current_file, array $existing): void
    {
        $existing_type = $existing['type'];
        $existing_file = $existing['file'];

        throw new \RuntimeException(
            "Name conflict: '{$name}' is used as both {$existing_type} and {$current_type}:\n" .
            "  As {$existing_type} in: {$existing_file}\n" .
            "  As {$current_type} in: {$current_file}\n" .
            "\nGlobal names must be unique across functions and constants in PHP files."
        );
    }
}