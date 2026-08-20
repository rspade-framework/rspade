<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * Ensures SCSS manifest module has lower priority than modules it depends on
 *
 * The SCSS module needs to run after Blade, JavaScript, and Jqhtml modules
 * because it checks their output to determine if SCSS files should have an ID
 * based on matching class selectors.
 */
class ManifestModulePriority_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MANIFEST-PRIORITY-01';
    }

    public function get_name(): string
    {
        return 'Manifest Module Priority Order';
    }

    public function get_description(): string
    {
        return 'Ensures SCSS manifest module runs after modules it depends on';
    }

    public function get_file_patterns(): array
    {
        // We'll check specific module files
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check the specific manifest module files
        $module_files = [
            'app/RSpade/Modules/Scss_ManifestModule.php',
            'app/RSpade/Modules/Blade_ManifestModule.php',
            'app/RSpade/Modules/JavaScript_ManifestModule.php',
            'app/RSpade/Integrations/Jqhtml/Jqhtml_ManifestModule.php'
        ];

        $is_module_file = false;
        foreach ($module_files as $module_file) {
            if (str_ends_with($file_path, $module_file)) {
                $is_module_file = true;
                break;
            }
        }

        if (!$is_module_file) {
            return;
        }

        // Extract priority from this file
        $current_priority = $this->extract_priority($contents);
        if ($current_priority === null) {
            return; // Can't find priority method
        }

        // If this is the SCSS module, check all others
        if (str_ends_with($file_path, 'Scss_ManifestModule.php')) {
            $this->check_scss_priority($file_path, $current_priority);
        }
    }

    /**
     * Extract the priority value from module contents
     */
    private function extract_priority(string $contents): ?int
    {
        // Look for: public function priority(): int { return NUMBER; }
        if (preg_match('/public\s+function\s+priority\s*\(\s*\)\s*:\s*int\s*\{[^}]*return\s+(\d+)\s*;/s', $contents, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Check that SCSS priority is lower (higher number) than all dependencies
     */
    private function check_scss_priority(string $scss_file_path, int $scss_priority): void
    {
        $base_path = base_path();

        $dependencies = [
            'Blade_ManifestModule' => $base_path . '/app/RSpade/Modules/Blade_ManifestModule.php',
            'JavaScript_ManifestModule' => $base_path . '/app/RSpade/Modules/JavaScript_ManifestModule.php',
            'Jqhtml_ManifestModule' => $base_path . '/app/RSpade/Integrations/Jqhtml/Jqhtml_ManifestModule.php'
        ];

        $violations = [];

        foreach ($dependencies as $name => $path) {
            if (!file_exists($path)) {
                continue; // Module might not exist (e.g., Jqhtml is optional)
            }

            $contents = file_get_contents($path);
            $priority = $this->extract_priority($contents);

            if ($priority === null) {
                continue; // Can't find priority
            }

            // SCSS priority should be higher number (lower priority) than dependencies
            if ($scss_priority <= $priority) {
                $violations[] = "  - {$name}: priority={$priority} (SCSS has {$scss_priority})";
            }
        }

        if (!empty($violations)) {
            $violation_list = implode("\n", $violations);

            $this->add_violation(
                $scss_file_path,
                0,
                "SCSS manifest module priority must be lower than modules it depends on",
                null,
                "The SCSS manifest module depends on output from Blade, JavaScript, and Jqhtml modules\n" .
                "to determine which SCSS files should have an ID based on matching class selectors.\n\n" .
                "Priority violations found:\n" .
                $violation_list . "\n\n" .
                "Fix: Change Scss_ManifestModule priority() to return a value higher than all dependencies.\n" .
                "Remember: Higher number = lower priority (runs later).\n\n" .
                "Example: If Blade=15, JavaScript=20, Jqhtml=25, then SCSS should be >25 (e.g., 100).",
                'critical'
            );
        }
    }
}