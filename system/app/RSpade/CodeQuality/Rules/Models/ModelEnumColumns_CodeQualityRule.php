<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use Exception;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class ModelEnumColumns_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MODEL-ENUM-01';
    }

    public function get_name(): string
    {
        return 'Model Enum Column Validation';
    }

    public function get_description(): string
    {
        return 'Enum definitions must reference actual database columns';
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

        // Try to load the class to get its properties
        if (!class_exists($class_name)) {
            return;
        }

        try {
            $reflection = new ReflectionClass($class_name);

            // Get the $table property value
            $table_prop = $reflection->getProperty('table');
            $table_prop->setAccessible(true);
            $instance = $reflection->newInstanceWithoutConstructor();
            $table_name = $table_prop->getValue($instance);

            // Get the $enums property if it exists
            if (!$reflection->hasProperty('enums')) {
                return;
            }

            $enums_prop = $reflection->getProperty('enums');
            $enums_prop->setAccessible(true);
            $enums = $enums_prop->getValue();

            if (!is_array($enums)) {
                return;
            }

            // Get table columns from database
            $columns = Schema::getColumnListing($table_name);

            // Check each enum field
            $lines = explode("\n", $contents);
            foreach ($enums as $column => $definitions) {
                if (!in_array($column, $columns)) {
                    // Find the line where this enum is defined
                    $line_number = 1;
                    foreach ($lines as $i => $line) {
                        if (preg_match('/[\'"]' . preg_quote($column) . '[\'"\s]*=>/', $line)) {
                            $line_number = $i + 1;
                            break;
                        }
                    }

                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Enum field '{$column}' does not exist as a column in table '{$table_name}' (Have migrations been run yet?)",
                        $lines[$line_number - 1] ?? '',
                        "Remove the enum definition for '{$column}' or add the column to the database table",
                        'high'
                    );
                }
            }
        } catch (Exception $e) {
            // If we can't check the database schema (e.g., during CI or before migrations),
            // skip this validation
            return;
        }
    }
}
