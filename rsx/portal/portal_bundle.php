<?php
/**
 * Portal_Bundle - Bundle for the RSX Client Portal
 *
 * This bundle includes assets for the portal pages accessible to external users.
 * It is separate from Frontend_Bundle to ensure internal code is never exposed
 * to portal users.
 */

namespace Rsx\Portal;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class Portal_Bundle extends Rsx_Module_Bundle_Abstract
{
    /**
     * Define bundle assets
     */
    public static function define(): array
    {
        return [
            'include' => [
                // Core styles (must be first)
                'rsx/theme/variables.scss',
                'rsx/theme/responsive.scss',

                // Bootstrap 5 source
                'Bootstrap5_Src_Bundle',

                // Theme components (shared UI elements)
                'rsx/theme',

                // Models (includes Portal_User_Model JS stubs)
                'rsx/models',

                // Shared library
                'rsx/lib',

                // Portal module directory (this directory)
                __DIR__,
            ],
        ];
    }
}
