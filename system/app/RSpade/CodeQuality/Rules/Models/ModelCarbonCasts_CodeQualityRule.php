<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class ModelCarbonCasts_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Laravel cast values that use Carbon
     */
    private const CARBON_CASTS = [
        'date',
        'datetime',
        'timestamp',
        'immutable_date',
        'immutable_datetime',
        'immutable_datetime:',  // With format
        'datetime:',            // With format
    ];

    public function get_id(): string
    {
        return 'MODEL-CARBON-01';
    }

    public function get_name(): string
    {
        return 'No Carbon Casts in Models';
    }

    public function get_description(): string
    {
        return 'Models must not use Carbon-based casts. Use Rsx_Date_Cast or Rsx_DateTime_Cast instead.';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Whether this rule is called during manifest scan
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * Carbon casts break the framework's date handling philosophy (strings, not objects).
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check PHP files in /rsx/ directory
        if (!str_contains($file_path, '/rsx/')) {
            return;
        }

        // Get class name from metadata if available
        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        // Skip if not a model
        if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            return;
        }

        // Look for $casts property
        if (!preg_match('/\$casts\s*=\s*\[(.*?)\];/s', $contents, $matches)) {
            return; // No $casts property
        }

        $casts_content = $matches[1];
        $casts_start_line = $this->find_casts_line($contents);

        // Check for Carbon-based cast values
        $violations = [];
        foreach (self::CARBON_CASTS as $carbon_cast) {
            // Match 'column' => 'date', 'column' => 'datetime', etc.
            // Also match with format like 'datetime:Y-m-d'
            $pattern = "/['\"](\w+)['\"]\s*=>\s*['\"](" . preg_quote($carbon_cast, '/') . "[^'\"]*)['\"]|^\s*['\"](" . preg_quote($carbon_cast, '/') . ")['\"]$/m";
            if (preg_match_all($pattern, $casts_content, $cast_matches, PREG_SET_ORDER)) {
                foreach ($cast_matches as $match) {
                    $column = $match[1] ?? 'unknown';
                    $cast_value = $match[2] ?? $match[3] ?? $carbon_cast;
                    $violations[] = "'{$column}' => '{$cast_value}'";
                }
            }
        }

        // Also check for simple patterns like 'date' as value
        foreach (self::CARBON_CASTS as $carbon_cast) {
            $simple_pattern = "/=>\s*['\"]" . preg_quote(rtrim($carbon_cast, ':'), '/') . "(?::[^'\"]*)?['\"]/";
            if (preg_match_all($simple_pattern, $casts_content, $simple_matches)) {
                // Already captured above, but this ensures we catch all
            }
        }

        // Better detection: find all cast values that are Carbon-based
        $violations = [];
        preg_match_all("/['\"](\w+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $casts_content, $all_casts, PREG_SET_ORDER);

        foreach ($all_casts as $cast_match) {
            $column = $cast_match[1];
            $cast_value = $cast_match[2];

            // Check if this cast value is Carbon-based
            foreach (self::CARBON_CASTS as $carbon_cast) {
                $base_cast = rtrim($carbon_cast, ':');
                if ($cast_value === $base_cast || str_starts_with($cast_value, $base_cast . ':')) {
                    $violations[] = "'{$column}' => '{$cast_value}'";
                    break;
                }
            }
        }

        if (empty($violations)) {
            return;
        }

        $error_message = "Code Quality Violation (MODEL-CARBON-01) - Carbon Casts Forbidden\n\n";
        $error_message .= "Model class '{$class_name}' uses Carbon-based casts in \$casts property:\n";
        $error_message .= implode("\n", array_map(fn($v) => "  - {$v}", array_unique($violations))) . "\n\n";
        $error_message .= "File: {$file_path}\n";
        $error_message .= "Line: {$casts_start_line}\n\n";
        $error_message .= "CRITICAL: RSpade uses string-based dates, not Carbon objects.\n\n";
        $error_message .= "Resolution:\n";
        $error_message .= "Remove these entries from \$casts. The framework automatically applies:\n";
        $error_message .= "  - Rsx_Date_Cast for DATE columns (returns 'YYYY-MM-DD' strings)\n";
        $error_message .= "  - Rsx_DateTime_Cast for DATETIME columns (returns ISO 8601 strings)\n\n";
        $error_message .= "If you need the column cast, use the explicit RSpade casts:\n";
        $error_message .= "  'column' => Rsx_Date_Cast::class\n";
        $error_message .= "  'column' => Rsx_DateTime_Cast::class";

        throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
            $error_message,
            0,
            null,
            $file_path,
            $casts_start_line
        );
    }

    private function find_casts_line(string $contents): int
    {
        $lines = explode("\n", $contents);
        foreach ($lines as $i => $line) {
            if (preg_match('/\$casts\s*=/', $line)) {
                return $i + 1;
            }
        }
        return 1;
    }
}
