<?php

namespace Rsx\App\Apidocs;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

/**
 * Apidocs_Bundle - standalone Module Bundle for the external REST API documentation page.
 *
 * Deliberately hermetic (no Bootstrap, no shared theme): the midnight look lives entirely in
 * the framework-core Core/Api/components SCSS (self-contained CSS custom properties). It
 * scans app/RSpade/Core/Api, which pulls in the docs jqhtml components, the Api_Docs_Controller
 * Ajax stubs, AND the auto-discovered Api_Docs_Vendor_Bundle (highlight.js).
 * Those CDN assets therefore load ONLY on this page - no other Module Bundle scans that dir.
 */
class Apidocs_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'jquery',                   // required module
                'lodash',                   // required module
                'app/RSpade/Core/Api',      // docs components + controller + vendor asset bundle
                __DIR__,                    // this module (layout + action)
            ],
        ];
    }
}
