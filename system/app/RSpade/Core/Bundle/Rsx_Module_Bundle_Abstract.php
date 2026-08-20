<?php

namespace App\RSpade\Core\Bundle;

use RuntimeException;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;

/**
 * Rsx_Module_Bundle_Abstract - Base class for top-level module bundles
 *
 * Module bundles are the entry point bundles that get compiled and rendered
 * on pages. They can scan directories and include other bundles (asset bundles
 * only - not other module bundles).
 *
 * CAPABILITIES:
 * - Directory scanning via 'include' paths
 * - Explicit inclusion of Asset Bundles by class name
 * - Auto-discovery of Asset Bundles in scanned directories
 * - Gets built via rsx:bundle:build
 *
 * RESTRICTIONS:
 * - Cannot include other Module Bundles (fatal error)
 *
 * USAGE:
 *     class Frontend_Bundle extends Rsx_Module_Bundle_Abstract {
 *         public static function define(): array {
 *             return [
 *                 'include' => [
 *                     __DIR__,                     // Directory scan
 *                     'rsx/theme',                 // Directory scan (auto-discovers asset bundles)
 *                     'Bootstrap5_Src_Bundle',     // Explicit asset bundle
 *                 ],
 *             ];
 *         }
 *     }
 *
 * @see Rsx_Asset_Bundle_Abstract for dependency declaration bundles
 */
abstract class Rsx_Module_Bundle_Abstract extends Rsx_Bundle_Abstract
{
    /**
     * Validate that this bundle doesn't include other module bundles
     *
     * Called by BundleCompiler when resolving includes.
     *
     * @param string $included_bundle_class The bundle class being included
     * @param string $parent_bundle_class The module bundle doing the including
     * @throws RuntimeException if trying to include another module bundle
     */
    public static function validate_include(string $included_bundle_class, string $parent_bundle_class): void
    {
        // Check if the included bundle is a module bundle
        if (is_subclass_of($included_bundle_class, self::class)) {
            throw new RuntimeException(
                "Module bundle cannot include another module bundle.\n\n" .
                "Parent bundle: {$parent_bundle_class}\n" .
                "Attempted to include: {$included_bundle_class}\n\n" .
                "Module bundles are top-level entry points and cannot be nested.\n" .
                "If you need shared code between module bundles, create an Asset Bundle\n" .
                "that both module bundles can include.\n\n" .
                "Example:\n" .
                "    class Shared_Assets_Bundle extends Rsx_Asset_Bundle_Abstract {\n" .
                "        public static function define(): array {\n" .
                "            return [\n" .
                "                'cdn_assets' => [...],\n" .
                "                'npm' => [...],\n" .
                "            ];\n" .
                "        }\n" .
                "    }"
            );
        }
    }
}
