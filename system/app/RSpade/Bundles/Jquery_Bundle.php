<?php

namespace App\RSpade\Bundles;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

/**
 * jQuery CDN Bundle
 *
 * Provides jQuery library via CDN. This bundle is automatically included
 * in all other bundles as a required dependency.
 */
class Jquery_Bundle extends Rsx_Asset_Bundle_Abstract
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
                        'url' => 'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js',
                        'integrity' => 'sha512-pumBsjNRGGqkPzKHndZMaAG+bir374sORyzM3uulLV14lN5LyykqNk8eEeUlUkB3U0M4FApyaHraT65ihJhDpQ==',
                    ],
                ],
            ],
        ];
    }
}
