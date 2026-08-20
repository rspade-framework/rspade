<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class ModelBannedRelations_CodeQualityRule extends CodeQualityRule_Abstract
{
    // Banned Laravel relationship methods
    protected $banned_relations = [
        'hasManyThrough',
        'hasOneThrough',
    ];

    public function get_id(): string
    {
        return 'MODEL-BANNED-01';
    }

    public function get_name(): string
    {
        return 'Banned Model Relationships';
    }

    public function get_description(): string
    {
        return 'Models must not use hasManyThrough or hasOneThrough relationships';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check PHP files in /rsx/ directory
        if (!str_contains($file_path, '/rsx/')) {
            return;
        }

        // Get class name from metadata
        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        // Check if this is a model (extends Rsx_Model_Abstract)
        if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            return;
        }

        // Read original file content and remove single-line comments
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $full_path = str_starts_with($file_path, '/') ? $file_path : $base_path . '/' . $file_path;
        $original_contents = file_get_contents($full_path);

        // Remove single-line comments but keep line structure
        $lines = explode("\n", $original_contents);
        $processed_lines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//')) {
                $processed_lines[] = ''; // Keep empty line to preserve line numbers
            } else {
                $processed_lines[] = $line;
            }
        }

        // Check for banned relationship usage
        foreach ($processed_lines as $i => $line) {
            foreach ($this->banned_relations as $banned) {
                if (preg_match('/\$this->' . preg_quote($banned) . '\s*\(/', $line)) {
                    // Find the method name this is in
                    $method_name = 'unknown';
                    for ($j = $i; $j >= 0; $j--) {
                        if (preg_match('/function\s+(\w+)\s*\(/', $processed_lines[$j], $match)) {
                            $method_name = $match[1];
                            break;
                        }
                    }

                    $this->add_violation(
                        $file_path,
                        $i + 1,
                        "Model uses banned relationship '{$banned}' in method '{$method_name}'",
                        $line,
                        'Replace with explicit relationship traversal or simpler patterns. ' .
                        'For example, use $this->relation1->flatMap->relation2 or create a ' .
                        'method that explicitly queries the related data.',
                        'critical'
                    );
                }
            }
        }
    }
}
