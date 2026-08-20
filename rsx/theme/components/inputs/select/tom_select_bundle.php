<?php
/**
 * Tom Select Asset Bundle
 *
 * Provides the Tom Select enhanced select box library via CDN.
 * Auto-discovered when module bundles scan directories containing select components.
 *
 * Tom Select is a lightweight, vanilla JS library for enhanced select boxes
 * with search, ajax, tagging, and accessibility features.
 */

namespace Rsx\Theme\Components\Inputs\Select;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

class Tom_Select_Bundle extends Rsx_Asset_Bundle_Abstract
{
    /**
     * Define the bundle configuration
     *
     * @return array Bundle configuration
     */
    public static function define(): array
    {
        return [
            'cdn_assets' => [
                'css' => [
                    [
                        'url' => 'https://cdn.jsdelivr.net/npm/tom-select@2.4.3/dist/css/tom-select.default.min.css',
                    ],
                ],
                'js' => [
                    [
                        'url' => 'https://cdn.jsdelivr.net/npm/tom-select@2.4.3/dist/js/tom-select.complete.min.js',
                    ],
                ],
            ],
        ];
    }
}
