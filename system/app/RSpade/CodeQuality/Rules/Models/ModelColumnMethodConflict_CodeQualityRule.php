<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use Exception;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class ModelColumnMethodConflict_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MODEL-CONFLICT-01';
    }

    public function get_name(): string
    {
        return 'Model Column/Method Name Conflict';
    }

    public function get_description(): string
    {
        return 'Database column names must not conflict with model method names';
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

        // Try to load the class to get its properties and methods
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

            // Get table columns from database
            $columns = Schema::getColumnListing($table_name);

            // Get all public methods defined in this class (not inherited)
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
            $lines = explode("\n", $contents);

            foreach ($methods as $method) {
                // Only check methods defined in this class, not inherited
                if ($method->class !== $class_name) {
                    continue;
                }

                $method_name = $method->getName();

                // Check if this method name conflicts with a column name
                if (in_array($method_name, $columns)) {
                    $line_number = $method->getStartLine();

                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Method '{$method_name}()' conflicts with database column '{$method_name}' in table '{$table_name}'",
                        $lines[$line_number - 1] ?? '',
                        'Rename the method to avoid conflict with the database column name',
                        'critical'
                    );
                }
            }

            // Also check enum definitions - they shouldn't reference methods
            if ($reflection->hasProperty('enums')) {
                $enums_prop = $reflection->getProperty('enums');
                $enums_prop->setAccessible(true);
                $enums = $enums_prop->getValue();

                if (is_array($enums)) {
                    foreach ($enums as $enum_field => $definitions) {
                        // Check if this enum field name matches a method
                        if (method_exists($class_name, $enum_field)) {
                            // Find where this enum is defined
                            $line_number = 1;
                            foreach ($lines as $i => $line) {
                                if (preg_match('/[\'"]' . preg_quote($enum_field) . '[\'"\s]*=>/', $line)) {
                                    $line_number = $i + 1;
                                    break;
                                }
                            }

                            $this->add_violation(
                                $file_path,
                                $line_number,
                                "Enum field '{$enum_field}' conflicts with method '{$enum_field}()' on the model",
                                $lines[$line_number - 1] ?? '',
                                'Either rename the method or remove the enum definition',
                                'critical'
                            );
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // If we can't check the database schema (e.g., during CI or before migrations),
            // skip this validation
            return;
        }
    }
}
