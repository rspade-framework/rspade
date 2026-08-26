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
 * THE INVARIANT: THIS INCLUDE LIST NAMES NO rsx/ PATH AND NO APP-DEFINED CLASS.
 *
 * The console is framework code that happens to be mounted on an application route, and a
 * framework feature does not get to depend on the application hosting it. An app is free to
 * restyle its theme, redefine its Bootstrap build, or replace its Modal outright - all of
 * which are its own business, and any of which would break a console that had borrowed them.
 * So the console ships its own equivalents: the midnight look is self-contained --api-*
 * custom properties in Core/Api/components, and the one dialog it needs is
 * Api_Confirm_Dialog, beside them. Nothing here reaches into rsx/, and CONV-BUNDLE-04
 * enforces that for every framework-owned bundle.
 *
 * The remaining benefit is mutual: the console cannot disturb - or be disturbed by - the
 * rest of the app, because the two share no stylesheet at all.
 */
class Apidocs_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'jquery',
                'lodash',
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
