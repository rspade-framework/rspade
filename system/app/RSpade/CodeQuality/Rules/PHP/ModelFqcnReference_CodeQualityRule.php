<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * ModelFqcnReferenceRule - Prohibits inline FQCN references to framework models
 *
 * This rule ensures that code uses portable model class references instead of
 * hardcoded fully-qualified class names. This allows developers to override
 * framework models by placing custom versions in /rsx/models/.
 *
 * Prohibited patterns:
 * - \App\RSpade\Core\Models\User_Model::...
 * - \App\RSpade\Core\Models\Site_Model::find(...)
 * - 'App\RSpade\Core\Models\Login_User_Model' (string references)
 *
 * Allowed patterns:
 * - use App\RSpade\Core\Models\User_Model; (use statements are fine)
 * - \User_Model::... (short class name)
 * - User_Model::... (via use statement)
 * - namespace App\RSpade\Core\Models; (namespace declarations)
 *
 * Exception:
 * - Files within /system/app/RSpade/Core/Models/ (the models themselves)
 */
class ModelFqcnReference_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'PHP-FQCN-MODEL-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'Model FQCN Reference Check';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Prohibits inline FQCN references to App\RSpade\Core\Models classes. Use short class names (\\User_Model) to allow model overrides.';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Whether this rule is called during manifest scan
     */
    public function is_called_during_manifest_scan(): bool
    {
        return false; // Only run during rsx:check
    }

    /**
     * Get default severity for this rule
     */
    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check a file for violations
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip files within the Models directory itself (they define the namespace)
        if (str_contains($file_path, '/system/app/RSpade/Core/Models/')) {
            return;
        }

        // Skip archived files
        if (str_contains($file_path, '/archive/') || str_contains($file_path, '/archived/')) {
            return;
        }

        // Check if file-level exception is present
        if (str_contains($contents, '@' . $this->get_id() . '-EXCEPTION')) {
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($lines as $line_number => $line) {
            $actual_line_number = $line_number + 1;

            // Skip if line has exception comment
            if (str_contains($line, '@' . $this->get_id() . '-EXCEPTION')) {
                continue;
            }

            // Skip use statements - those are fine
            if (preg_match('/^\s*use\s+/', $line)) {
                continue;
            }

            // Skip namespace declarations
            if (preg_match('/^\s*namespace\s+/', $line)) {
                continue;
            }

            // Skip comments
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // Look for inline FQCN references to App\RSpade\Core\Models\
            // Pattern 1: \App\RSpade\Core\Models\Something (backslash-prefixed)
            // Pattern 2: 'App\RSpade\Core\Models\Something' or "..." (string references)

            // Check for backslash-prefixed FQCN in code
            if (preg_match('/\\\\App\\\\RSpade\\\\Core\\\\Models\\\\(\w+)/', $line, $matches)) {
                $model_name = $matches[1];
                $this->add_violation(
                    $file_path,
                    $actual_line_number,
                    "Inline FQCN reference to framework model '\\App\\RSpade\\Core\\Models\\{$model_name}' prevents model overrides",
                    trim($line),
                    $this->build_suggestion($model_name),
                    'high'
                );
                continue;
            }

            // Check for string references (for dynamic class usage)
            if (preg_match('/[\'"]App\\\\RSpade\\\\Core\\\\Models\\\\(\w+)[\'"]/', $line, $matches)) {
                $model_name = $matches[1];
                $this->add_violation(
                    $file_path,
                    $actual_line_number,
                    "String FQCN reference to framework model 'App\\RSpade\\Core\\Models\\{$model_name}' prevents model overrides",
                    trim($line),
                    $this->build_suggestion($model_name, true),
                    'high'
                );
            }
        }
    }

    /**
     * Build suggestion for fixing the violation
     */
    private function build_suggestion(string $model_name, bool $is_string = false): string
    {
        $suggestions = [];
        $suggestions[] = "Framework models should be referenced by short class name to allow developer overrides.";
        $suggestions[] = "";

        if ($is_string) {
            $suggestions[] = "Change:";
            $suggestions[] = "    \$class = 'App\\RSpade\\Core\\Models\\{$model_name}';";
            $suggestions[] = "";
            $suggestions[] = "To:";
            $suggestions[] = "    \$class = '{$model_name}';";
        } else {
            $suggestions[] = "Change:";
            $suggestions[] = "    \\App\\RSpade\\Core\\Models\\{$model_name}::method()";
            $suggestions[] = "";
            $suggestions[] = "To:";
            $suggestions[] = "    \\{$model_name}::method()";
        }

        $suggestions[] = "";
        $suggestions[] = "This allows developers to override {$model_name} by creating:";
        $suggestions[] = "    /rsx/models/" . strtolower(str_replace('_Model', '', $model_name)) . "_model.php";
        $suggestions[] = "";
        $suggestions[] = "Note: 'use App\\RSpade\\Core\\Models\\{$model_name};' statements are allowed";
        $suggestions[] = "because PHP resolves them at parse time before the override system.";

        return implode("\n", $suggestions);
    }
}
