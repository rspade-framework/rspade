<?php
/**
 * Login_Bundle - Bundle for the RSX Login System
 *
 * This bundle includes necessary assets for the login and authentication pages,
 * including Bootstrap 5 CSS framework and JavaScript components.
 */

namespace Rsx\App\Login;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class Login_Bundle extends Rsx_Module_Bundle_Abstract
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
                'rsx/theme/components',         // Form widgets and UI components
                'rsx/lib',                      // Global shared library (Formatters, etc.)
                __DIR__,                        // Login module directory
            ],
        ];
    }
}
