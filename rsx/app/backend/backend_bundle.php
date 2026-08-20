<?php
/**
 * Backend_Bundle - Bundle for the RSX Backend/Admin Module
 *
 * This bundle includes necessary assets for the administrative backend pages.
 */

namespace Rsx\App\Backend;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class Backend_Bundle extends Rsx_Module_Bundle_Abstract
{
    /**
     * Define bundle assets
     */
    public static function define(): array
    {
        return [
            // Include all assets using unified include array
            'include' => [
                // jQuery and Lodash are automatically included as required bundles
                'rsx/theme/variables.scss',     // Global SCSS variables (must be first)
                'rsx/theme/responsive.scss',    // Responsive mixins and utilities
                'bootstrap5',                   // Bootstrap 5 SCSS source bundle
                __DIR__,                       // Backend module directory
            ],
        ];
    }
}
