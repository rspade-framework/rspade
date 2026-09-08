<?php
/**
 * Login_Bundle - Bundle for the RSX Login System
 *
 * This bundle includes necessary assets for the login and authentication pages.
 * It carries the SAME theme set as Frontend_Bundle - variables, responsive, the custom
 * Bootstrap build and the whole rsx/theme tree - so the auth ladder is painted by the
 * staff application's own design system and follows dark mode with it.
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
                'rsx/theme/responsive.scss',    // Responsive mixins and utilities (after variables, before Bootstrap)
                'Bootstrap5_Src_Bundle',        // Bootstrap 5 SCSS source bundle (explicit: has watch dirs)
                'rsx/theme',                    // The whole theme - identical to Frontend_Bundle, so the
                                                // auth pages compile against the same tokens and components
                'rsx/lib',                      // Global shared library (Formatters, etc.)
                __DIR__,                        // Login module directory
            ],
        ];
    }
}
