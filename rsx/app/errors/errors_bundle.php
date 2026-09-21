<?php
/**
 * Errors_Bundle - Bundle for the staff error pages
 *
 * The SAME theme set as Login_Bundle and Frontend_Bundle - variables, responsive, the
 * custom Bootstrap build and the whole rsx/theme tree - so a 404 is painted by the
 * staff application's own design system and follows dark mode with the rest of it.
 *
 * An error page is the last thing a broken request renders, so this bundle names the
 * theme and this directory and nothing else: no models, no feature module.
 */

namespace Rsx\App\Errors;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class Errors_Bundle extends Rsx_Module_Bundle_Abstract
{
    /**
     * Define bundle assets
     */
    public static function define(): array
    {
        return [
            'include' => [
                // jQuery and Lodash are automatically included as required bundles
                'rsx/theme/variables.scss',     // Global SCSS variables (must be first)
                'rsx/theme/responsive.scss',    // Responsive mixins and utilities (after variables, before Bootstrap)
                'Bootstrap5_Src_Bundle',        // Bootstrap 5 SCSS source bundle (explicit: has watch dirs)
                'rsx/theme',                    // The whole theme - identical to Frontend_Bundle
                'rsx/lib',                      // Global shared library (Formatters, etc.)
                __DIR__,                        // Errors module directory
            ],
        ];
    }
}
