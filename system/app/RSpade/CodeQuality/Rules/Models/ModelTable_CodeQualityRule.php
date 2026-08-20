<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class ModelTable_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MODEL-TABLE-01';
    }

    public function get_name(): string
    {
        return 'Model Table Property';
    }

    public function get_description(): string
    {
        return 'Models must have a protected $table property set to a string';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
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
        $processed_contents = implode("\n", $processed_lines);

        // Check for protected $table property
        if (!preg_match('/protected\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $processed_contents, $match)) {
            // Find class definition line
            $class_line = 1;
            foreach ($lines as $i => $line) {
                if (preg_match('/\bclass\s+' . preg_quote($class_name) . '\b/', $line)) {
                    $class_line = $i + 1;
                    break;
                }
            }

            $this->add_violation(
                $file_path,
                $class_line,
                "Model {$class_name} is missing protected \$table property",
                $lines[$class_line - 1] ?? '',
                "Add: protected \$table = 'table_name';",
                'high'
            );
        } else {
            // Check if table name is not empty
            $table_name = $match[1];
            if (empty($table_name)) {
                // Lines already split above
                $line_number = 1;
                foreach ($lines as $i => $line) {
                    if (preg_match('/protected\s+\$table/', $line)) {
                        $line_number = $i + 1;
                        break;
                    }
                }

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Model {$class_name} has empty \$table property",
                    $lines[$line_number - 1] ?? '',
                    'Set $table to the appropriate database table name',
                    'high'
                );
            }
        }
    }
}
