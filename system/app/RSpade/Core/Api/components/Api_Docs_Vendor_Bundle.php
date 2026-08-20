<?php

namespace App\RSpade\Core\Api\Components;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

/**
 * Api_Docs_Vendor_Bundle - CDN assets for the API documentation / tester page.
 *
 * Auto-discovered when a Module Bundle scans the Core/Api directory (the app-side
 * Apidocs_Bundle does). Kept OUT of every other module bundle's include paths so these
 * assets never load on the main app.
 *
 * Assets:
 *   - highlight.js (core+common languages, incl. json/bash/javascript/python) + github-dark
 *     theme: read-only syntax highlighting for request previews, responses, and examples.
 *
 * The monospace face is the system ui-monospace stack (see Api_Docs_Page.scss) - a webfont
 * CDN is a page-blocking external dependency and adds nothing a system mono lacks.
 *
 * In development these load from their external URLs; in sealed builds they are cached and
 * served locally via /_vendor/. The midnight theme itself is hand-rolled component SCSS
 * (no third-party CSS framework); icons are inline SVG (no icon-font CDN).
 */
class Api_Docs_Vendor_Bundle extends Rsx_Asset_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [],
            'cdn_assets' => [
                'css' => [
                    ['url' => 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/styles/github-dark.min.css'],
                ],
                'js' => [
                    ['url' => 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/highlight.min.js'],
                    // PowerShell is not in the common bundle; load the language module so the
                    // PowerShell code sample highlights like the rest.
                    ['url' => 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/languages/powershell.min.js'],
                ],
            ],
        ];
    }
}
