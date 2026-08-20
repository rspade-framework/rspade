<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Prevents use of Laravel's native enum features in RSpade models
 *
 * RSpade uses its own enum system via public static $enums property
 * instead of Laravel's enum casting system to provide:
 * - Magic properties like $model->field_label
 * - Static methods like Model::field_enum_select()
 * - Auto-generated constants like Model::STATUS_ACTIVE
 * - Consistent behavior across the framework
 */
class NoLaravelEnums_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MODEL-LARAVEL-ENUM-01';
    }

    public function get_name(): string
    {
        return 'No Laravel Native Enums';
    }

    public function get_description(): string
    {
        return 'Models extending Rsx_Model_Abstract must not use Laravel native enum features';
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

        // Read original file content
        $base_path = function_exists('base_path') ? base_path() : '/var/www/html';
        $full_path = str_starts_with($file_path, '/') ? $file_path : $base_path . '/' . $file_path;
        $original_contents = file_get_contents($full_path);
        $lines = explode("\n", $original_contents);

        // Check for Laravel enum imports
        if (preg_match('/use\s+Illuminate\\\\Database\\\\Eloquent\\\\Casts\\\\AsEnum\s*;/i', $original_contents, $match, PREG_OFFSET_CAPTURE)) {
            $line_number = substr_count(substr($original_contents, 0, $match[0][1]), "\n") + 1;

            $this->add_violation(
                $file_path,
                $line_number,
                "Laravel's AsEnum cast is not allowed in RSpade models",
                $lines[$line_number - 1] ?? '',
                "Remove the AsEnum import and use RSpade's enum system instead:\n\n" .
                "public static \$enums = [\n" .
                "    'status_id' => [\n" .
                "        1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active'],\n" .
                "        2 => ['constant' => 'STATUS_INACTIVE', 'label' => 'Inactive']\n" .
                "    ]\n" .
                "];\n\n" .
                'This provides magic properties like $model->status_label and methods like Model::status_enum_select()',
                'high'
            );
        }

        // Check for enum class imports (e.g., use App\Enums\StatusEnum)
        if (preg_match('/use\s+App\\\\Enums\\\\[A-Za-z0-9_]+\s*;/i', $original_contents, $match, PREG_OFFSET_CAPTURE)) {
            $line_number = substr_count(substr($original_contents, 0, $match[0][1]), "\n") + 1;

            $this->add_violation(
                $file_path,
                $line_number,
                'Laravel enum classes are not allowed in RSpade models',
                $lines[$line_number - 1] ?? '',
                "Remove the enum class import and define enums directly in the model using:\n\n" .
                "public static \$enums = [\n" .
                "    'field_id' => [\n" .
                "        1 => ['constant' => 'CONSTANT_NAME', 'label' => 'Display Name']\n" .
                "    ]\n" .
                '];',
                'high'
            );
        }

        // Check for $casts property with enum casting
        if (preg_match('/protected\s+(?:static\s+)?\$casts\s*=\s*\[[^\]]*(?:AsEnum|Enum)::class[^\]]*\]/s', $original_contents, $match, PREG_OFFSET_CAPTURE)) {
            $line_number = substr_count(substr($original_contents, 0, $match[0][1]), "\n") + 1;

            $this->add_violation(
                $file_path,
                $line_number,
                'Laravel enum casting is not allowed in RSpade models',
                $lines[$line_number - 1] ?? '',
                "Remove enum casting from \$casts and use RSpade's \$enums property instead.\n\n" .
                "RSpade's enum system provides automatic type casting and additional features " .
                "like magic properties and dropdown helpers without using Laravel's casting.",
                'high'
            );
        }

        // Check for PHP 8.1 native enum declarations in the same file
        if (preg_match('/^\s*enum\s+[A-Za-z0-9_]+\s*(?::\s*(?:string|int))?\s*\{/m', $original_contents, $match, PREG_OFFSET_CAPTURE)) {
            $line_number = substr_count(substr($original_contents, 0, $match[0][1]), "\n") + 1;

            $this->add_violation(
                $file_path,
                $line_number,
                'PHP native enum declarations are not allowed in RSpade model files',
                $lines[$line_number - 1] ?? '',
                "Move enum definitions to the model's \$enums property:\n\n" .
                "public static \$enums = [\n" .
                "    'field_id' => [\n" .
                "        // Integer keys with constant and label\n" .
                "        1 => ['constant' => 'VALUE_ONE', 'label' => 'Value One'],\n" .
                "        2 => ['constant' => 'VALUE_TWO', 'label' => 'Value Two']\n" .
                "    ]\n" .
                "];\n\n" .
                'Then run: php artisan rsx:constants:regenerate',
                'high'
            );
        }

        // Check for BackedEnum or UnitEnum interface implementations
        if (preg_match('/implements\s+[^{]*(?:BackedEnum|UnitEnum)/i', $original_contents, $match, PREG_OFFSET_CAPTURE)) {
            $line_number = substr_count(substr($original_contents, 0, $match[0][1]), "\n") + 1;

            $this->add_violation(
                $file_path,
                $line_number,
                'PHP enum interfaces are not allowed in RSpade models',
                $lines[$line_number - 1] ?? '',
                'RSpade models should not implement enum interfaces. ' .
                'Use the $enums property for enum functionality.',
                'high'
            );
        }

        // Check for enum() method calls (Laravel's enum validation)
        if (preg_match('/->enum\s*\([^)]*\)/i', $original_contents, $match, PREG_OFFSET_CAPTURE)) {
            $line_number = substr_count(substr($original_contents, 0, $match[0][1]), "\n") + 1;

            // Check if it's in a validation context
            $context_start = max(0, $match[0][1] - 200);
            $context = substr($original_contents, $context_start, 400);

            if (str_contains($context, 'validate') || str_contains($context, 'rules')) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Laravel's enum() validation is not compatible with RSpade enums",
                    $lines[$line_number - 1] ?? '',
                    "Use Rule::in() with the enum IDs instead:\n\n" .
                    "use Illuminate\\Validation\\Rule;\n\n" .
                    "'field_id' => ['required', Rule::in(Model::field_enum_ids())]\n\n" .
                    'This validates against the integer keys defined in your $enums property.',
                    'medium'
                );
            }
        }
    }
}
