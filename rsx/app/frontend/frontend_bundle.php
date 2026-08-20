<?php
/**
 * Frontend_Bundle - Bundle for the RSX Frontend Module
 *
 * This bundle includes necessary assets for the authenticated frontend pages.
 */

namespace Rsx\App\Frontend;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class Frontend_Bundle extends Rsx_Module_Bundle_Abstract
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
                'Quill_Bundle',                 // Quill WYSIWYG editor (explicit: CDN-only, no local files)
                'rsx/theme',                    // Theme assets (Tom_Select_Bundle auto-discovered here)
                'rsx/models',                   // Model PHP files (JS stubs auto-included)
                'rsx/lib',                      // Global shared library
                __DIR__,                        // Frontend module directory
            ],
            // Additional route paths for Rsx.Route() generation (not bundled, just route extraction)
            'include_routes' => [
                'rsx/app/login',                // Login module routes (logout, etc.)
            ],
        ];
    }

    /**
     * Request-time values this module's JavaScript needs in window.rsxapp.page_data.
     *
     * analytics_measurement_id is exported ONLY when this install configured one, so
     * Analytics (rsx/lib/analytics/) stays completely inert by default - see that
     * directory for the template's worked example of the external-resources pattern.
     */
    public static function load_rsxapp_data(): array
    {
        $data = [];

        $measurement_id = config('rsx.analytics.measurement_id');

        if (!empty($measurement_id)) {
            $data['analytics_measurement_id'] = $measurement_id;
        }

        return $data;
    }
}
