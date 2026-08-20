<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * SpaAttributeMisuseRule - Detects incorrect use of #[SPA] with #[Route] attributes
 *
 * The #[SPA] attribute marks a method as an SPA bootstrap entry point. It is NOT
 * for defining routes - routes in SPA modules are handled entirely by JavaScript
 * actions using the @route() decorator.
 *
 * Common mistake: Adding #[Route('/path')] to an #[SPA] method, thinking it defines
 * the SPA's URL. This is wrong because:
 * 1. #[SPA] methods serve as bootstraps, loading the JavaScript SPA shell
 * 2. All actual routes are defined in JS action classes with @route() decorator
 * 3. The SPA router matches URLs to actions client-side, not server-side
 */
class SpaAttributeMisuse_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'PHP-SPA-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'SPA Attribute Misuse';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Detects incorrect combination of #[SPA] and #[Route] attributes on the same method';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * This rule runs during manifest scan for immediate feedback
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Get default severity for this rule
     */
    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * This is a cross-file rule - needs full manifest context
     */
    public function is_incremental(): bool
    {
        return false;
    }

    /**
     * Check the manifest for SPA + Route attribute combinations
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

        foreach ($files as $file => $file_metadata) {
            // Skip non-PHP files
            if (($file_metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            // Skip if no public static methods
            if (empty($file_metadata['public_static_methods'])) {
                continue;
            }

            // Check each method
            foreach ($file_metadata['public_static_methods'] as $method_name => $method_info) {
                $attributes = $method_info['attributes'] ?? [];

                // Check if method has both #[SPA] and #[Route]
                $has_spa = isset($attributes['SPA']);
                $has_route = isset($attributes['Route']);

                if ($has_spa && $has_route) {
                    $line = $method_info['line'] ?? 1;
                    $class_name = $file_metadata['class'] ?? 'Unknown';

                    $this->add_violation(
                        $file,
                        $line,
                        "Method '{$method_name}' has both #[SPA] and #[Route] attributes. These should not be combined.",
                        "#[SPA]\n#[Route('...')]\npublic static function {$method_name}(...)",
                        $this->build_suggestion($class_name, $method_name),
                        'critical'
                    );
                }
            }
        }
    }

    /**
     * Build detailed suggestion explaining SPA architecture
     */
    private function build_suggestion(string $class_name, string $method_name): string
    {
        $lines = [];
        $lines[] = "The #[SPA] and #[Route] attributes serve different purposes and should not be combined.";
        $lines[] = "";
        $lines[] = "WHAT #[SPA] DOES:";
        $lines[] = "  - Marks a method as an SPA bootstrap entry point";
        $lines[] = "  - Returns rsx_view(SPA) which loads the JavaScript SPA shell";
        $lines[] = "  - Acts as a catch-all for client-side routing";
        $lines[] = "  - Typically ONE per feature/bundle (e.g., Frontend_Spa_Controller::index)";
        $lines[] = "";
        $lines[] = "HOW SPA ROUTING WORKS:";
        $lines[] = "  - Routes are defined in JavaScript action classes with @route() decorator";
        $lines[] = "  - Example: @route('/contacts') on Contacts_Index_Action.js";
        $lines[] = "  - The SPA router matches URLs to actions CLIENT-SIDE";
        $lines[] = "  - Server only provides the bootstrap shell, not individual pages";
        $lines[] = "";
        $lines[] = "TO FIX:";
        $lines[] = "  1. Remove the #[Route] attribute from the #[SPA] method";
        $lines[] = "  2. Create JavaScript action classes for each route you need:";
        $lines[] = "";
        $lines[] = "     // Example: rsx/app/frontend/contacts/Contacts_Index_Action.js";
        $lines[] = "     @route('/contacts')";
        $lines[] = "     @layout('Frontend_Layout')";
        $lines[] = "     @spa('{$class_name}::{$method_name}')";
        $lines[] = "     class Contacts_Index_Action extends Spa_Action { }";
        $lines[] = "";
        $lines[] = "See: php artisan rsx:man spa";

        return implode("\n", $lines);
    }
}
