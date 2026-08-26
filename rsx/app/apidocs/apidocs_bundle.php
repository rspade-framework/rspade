<?php

namespace Rsx\App\Apidocs;

use App\RSpade\Core\Api\Rsx_Api_Docs;
use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

/**
 * Apidocs_Bundle - assets for this application's API reference console.
 *
 * The bundle belongs to the APPLICATION, not the framework: a bundle must cover the
 * directory of the controller that rendered it, so a framework-owned bundle could never
 * serve an application's route. This is the whole of it - the framework supplies the
 * components (app/RSpade/Core/Api) and the page_data payload.
 *
 * Deliberately hermetic (no Bootstrap, no application theme): the console's midnight look
 * lives entirely in the framework's Core/Api/components SCSS as self-contained CSS custom
 * properties, so mounting it cannot disturb - or be disturbed by - the rest of the app.
 */
class Apidocs_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'jquery',
                'lodash',
                'rsx/theme/variables.scss',
                'rsx/theme/responsive.scss',
                'Bootstrap5_Src_Bundle',
                'rsx/lib/modal',
                'app/RSpade/Core/Api',   // the console components + the vendor asset bundle
                __DIR__,                 // this module's controller
            ],
        ];
    }

    /**
     * The console needs its endpoint catalog baked into the page; the framework builds it.
     */
    public static function load_rsxapp_data(): array
    {
        return Rsx_Api_Docs::rsxapp_data();
    }
}
