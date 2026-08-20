<?php

namespace Rsx\App\Dev;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class Dev_Bundle extends Rsx_Module_Bundle_Abstract
{
    /**
     * Define the bundle configuration
     *
     * @return array Bundle configuration
     */
    public static function define(): array
    {
        return [
            'include' => [
                'jquery',                       // jQuery library (required module)
                'lodash',                       // Lodash utilities (required module)
                'rsx/theme/variables.scss',     // Global SCSS variables (must be first)
                'rsx/theme/responsive.scss',    // Responsive mixins and utilities
                'bootstrap5_src',               // Bootstrap 5 SCSS source bundle
                __DIR__,                        // Module directory
                'rsx/lib',                   // Shared libraries
                'rsx/models',                // Models for JS stub generation
                'rsx/theme',                 // Theme assets (Tom_Select_Bundle auto-discovered here)
            ],
        ];
    }
}
