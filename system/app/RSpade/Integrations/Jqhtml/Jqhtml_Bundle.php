<?php

namespace App\RSpade\Integrations\Jqhtml;

use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;

/**
 * JQHTML Runtime Bundle
 *
 * Provides the JQHTML runtime JavaScript files needed for template rendering.
 * The Jqhtml_BundleProcessor handles compilation of .jqhtml files separately.
 */
class Jqhtml_Bundle extends Rsx_Bundle_Abstract
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
            ],
            'npm' => [
                'jqhtml' => "import jqhtml from '@jqhtml/core'",
                '_Base_Jqhtml_Component' => "import { Jqhtml_Component } from '@jqhtml/core'",
            ],
        ];
    }
}
