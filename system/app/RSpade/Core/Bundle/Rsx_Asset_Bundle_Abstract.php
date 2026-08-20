<?php

namespace App\RSpade\Core\Bundle;

use RuntimeException;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;

/**
 * Rsx_Asset_Bundle_Abstract - Base class for dependency declaration bundles
 *
 * Asset bundles declare external dependencies (CDN assets, npm modules) and are
 * automatically discovered when a Module Bundle scans directories containing them.
 * They serve as co-located dependency manifests for components.
 *
 * CAPABILITIES:
 * - CDN asset declarations (cdn_assets)
 * - NPM module declarations (npm)
 * - Watch directories for cache invalidation (watch)
 * - Direct file path includes (no directory scanning)
 * - Can include other Asset Bundles by class name
 * - Config declarations
 *
 * RESTRICTIONS:
 * - NO directory scanning in 'include' array
 * - Never built standalone - metadata consumed by Module Bundles
 * - Cannot include Module Bundles
 *
 * USAGE:
 *     class Tom_Select_Bundle extends Rsx_Asset_Bundle_Abstract {
 *         public static function define(): array {
 *             return [
 *                 'npm' => ['tom-select'],
 *                 'include' => [
 *                     // Direct file paths only, no directories
 *                     'rsx/theme/components/inputs/select/tom_select_config.js',
 *                 ],
 *             ];
 *         }
 *     }
 *
 * For source compilation (like Bootstrap SCSS):
 *     class Bootstrap5_Src_Bundle extends Rsx_Asset_Bundle_Abstract {
 *         public static function define(): array {
 *             return [
 *                 'include' => [
 *                     'rsx/theme/vendor/bootstrap_custom.scss',  // Direct file
 *                     'rsx/theme/vendor/bootstrap5/dist/js/bootstrap.bundle.js',
 *                 ],
 *                 'watch' => [
 *                     'rsx/theme/vendor/bootstrap5/scss',  // Watch for changes
 *                 ],
 *                 'cdn_assets' => [
 *                     'css' => [['url' => 'https://cdn.jsdelivr.net/...']],
 *                 ],
 *             ];
 *         }
 *     }
 *
 * @see Rsx_Module_Bundle_Abstract for top-level compiled bundles
 */
abstract class Rsx_Asset_Bundle_Abstract extends Rsx_Bundle_Abstract
{
    /**
     * Track whether this bundle was discovered via directory scan
     * Used for validation - bundles discovered via scan have stricter rules
     */
    protected static bool $_discovered_via_scan = false;

    /**
     * Validate that this asset bundle's include array contains no directory scans
     *
     * Called by BundleCompiler when this bundle is discovered via directory scan.
     *
     * @param string $bundle_class The asset bundle class being validated
     * @param string $parent_bundle_class The module bundle that discovered it
     * @throws RuntimeException if include array contains directory paths
     */
    public static function validate_no_directory_scanning(string $bundle_class, string $parent_bundle_class): void
    {
        if (!method_exists($bundle_class, 'define')) {
            return;
        }

        $definition = $bundle_class::define();
        $include = $definition['include'] ?? [];

        foreach ($include as $item) {
            if (!is_string($item)) {
                continue;
            }

            // Skip CDN urls, npm: prefixes, /public/ prefixes
            if (str_starts_with($item, 'http://') ||
                str_starts_with($item, 'https://') ||
                str_starts_with($item, 'npm:') ||
                str_starts_with($item, 'cdn:') ||
                str_starts_with($item, '/public/')) {
                continue;
            }

            // Skip bundle class references (contains no path separators or dots before extension)
            if (strpos($item, '/') === false && strpos($item, '.') === false) {
                // Could be a bundle class name - that's allowed
                continue;
            }

            // Check if it's a directory path (not a file)
            $resolved_path = base_path($item);

            // If it resolves to a directory, that's not allowed for discovered asset bundles
            if (is_dir($resolved_path)) {
                throw new RuntimeException(
                    "Asset bundle discovered via directory scan cannot have directory paths in 'include'.\n\n" .
                    "Bundle: {$bundle_class}\n" .
                    "Discovered by: {$parent_bundle_class}\n" .
                    "Invalid include: {$item}\n\n" .
                    "Asset bundles discovered via directory scan can only include:\n" .
                    "  - Direct file paths (e.g., 'rsx/lib/file.js')\n" .
                    "  - CDN assets (cdn_assets key)\n" .
                    "  - NPM modules (npm key)\n" .
                    "  - Watch directories (watch key - for cache invalidation only)\n" .
                    "  - Other Asset Bundles by class name\n\n" .
                    "If this bundle requires directory scanning, include it explicitly\n" .
                    "in the parent module bundle's include array:\n\n" .
                    "    class {$parent_bundle_class} extends Rsx_Module_Bundle_Abstract {\n" .
                    "        public static function define(): array {\n" .
                    "            return [\n" .
                    "                'include' => [\n" .
                    "                    '{$bundle_class}',  // Explicit include\n" .
                    "                    // ... other includes\n" .
                    "                ],\n" .
                    "            ];\n" .
                    "        }\n" .
                    "    }"
                );
            }
        }
    }
}
