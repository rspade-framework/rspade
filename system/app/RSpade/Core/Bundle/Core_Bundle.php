<?php

namespace App\RSpade\Core\Bundle;

use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;

/**
 * Core Framework Bundle
 *
 * Provides all core JavaScript framework files that are required for RSX to function.
 * This bundle is automatically included in every project.
 */
class Core_Bundle extends Rsx_Bundle_Abstract
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
                __DIR__,
                'app/RSpade/Core/Js',
                'app/RSpade/Core/Data',
                'app/RSpade/Core/Database',
                'app/RSpade/Core/Time',  // Timezone preference endpoints (Rsx_Timezone_Controller proxy)
                'app/RSpade/Core/Models',  // Framework models (User_Model, Site_Model, etc.)
                'app/RSpade/Core/SPA',
                'app/RSpade/Core/Debug',  // Debug components (JS_Tree_Debug_*)
                'app/RSpade/Core/Preview',  // Document preview components (Document_Preview + viewers)
                'app/RSpade/Core/Authorship',  // Authorship display components (Record_Author)
                'app/RSpade/Core/Turnstile',  // Cloudflare Turnstile widget (Turnstile_Input)
                'app/RSpade/Breadcrumbs',  // Progressive breadcrumb resolution
                'app/RSpade/Lib',
            ],
            'npm' => [
                'DOMPurify' => "import DOMPurify from 'dompurify'",
                'sha1' => "import sha1 from 'js-sha1'",
            ],
        ];
    }
}
