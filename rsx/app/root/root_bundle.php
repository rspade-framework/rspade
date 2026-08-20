<?php
/**
 * Root_Bundle - Bundle for the RSX Root Module
 *
 * This bundle includes necessary assets for the root administration pages.
 */

namespace Rsx\App\Root;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class Root_Bundle extends Rsx_Module_Bundle_Abstract
{
    /**
     * Define bundle assets
     */
    public static function define(): array
    {
        return [
            // Include all assets using unified include array
            'include' => [
                'rsx/theme/variables.scss',     // Global SCSS variables (must be first)
                'rsx/theme/responsive.scss',    // Responsive mixins and utilities
                'Bootstrap5_Src_Bundle',        // Bootstrap 5 SCSS source bundle
                'rsx/theme',
                'rsx/models',                   // Model PHP files (JS stubs auto-included)
                'rsx/lib',                      // Global shared library
                __DIR__,                        // Root module directory
            ],
        ];
    }
}
