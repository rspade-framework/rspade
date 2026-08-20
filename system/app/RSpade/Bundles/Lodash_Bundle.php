<?php

namespace App\RSpade\Bundles;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

/**
 * Lodash CDN Bundle
 *
 * Provides Lodash utility library via CDN. This bundle is automatically included
 * in all other bundles as a required dependency.
 */
class Lodash_Bundle extends Rsx_Asset_Bundle_Abstract
{
    /**
     * Define the bundle configuration
     *
     * @return array Bundle configuration
     */
    public static function define(): array
    {
        return [
            'include' => [],  // No local files
            'cdn_assets' => [
                'js' => [
                    [
                        'url' => 'https://cdnjs.cloudflare.com/ajax/libs/lodash.js/4.17.21/lodash.min.js',
                        'integrity' => 'sha512-WFN04846sdKMIP5LKNphMaWzU7YpMyCU245etK3g/2ARYbPK9Ub18eG+ljU96qKRCWh+quCY7yefSmlkQw1ANQ==',
                    ],
                ],
            ],
        ];
    }
}