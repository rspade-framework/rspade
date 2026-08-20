<?php
/**
 * SSR_Test_Bundle - Bundle for the SSR test page
 *
 * Includes Bootstrap, theme components, and SSR test components.
 * CSS is served via Blade template; JS bundles are loaded by the SSR server.
 */


namespace Rsx\App\SsrTest;

use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

class SSR_Test_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'rsx/theme/variables.scss',
                'rsx/theme/responsive.scss',
                'bootstrap5',
                'rsx/theme/components',
                __DIR__,
            ],
        ];
    }
}
