<?php

namespace App\RSpade\Bundles;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

/**
 * Bootstrap 5 CDN Bundle
 *
 * Provides Bootstrap 5 CSS and JavaScript via CDN.
 */
class Bootstrap5_Bundle extends Rsx_Asset_Bundle_Abstract
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
                'css' => [
                    [
                        'url' => 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/css/bootstrap.min.css',
                        'integrity' => 'sha512-SbiR/eusphKoMVVXysTKG/7VseWii+Y3FdHrt0EpKgpToZeemhqHeZeLWLhJutz/2ut2Vw1uQEj2MbRF+TVBUA==',
                    ],
                ],
                'js' => [
                    [
                        'url' => 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/js/bootstrap.bundle.min.js',
                        'integrity' => 'sha512-i9cEfJwUwViEPFKdC1enz4ZRGBj8YQo6QByFTF92YXHi7waCqyexvRD75S5NVTsSiTv7rKWqG9Y5eFxmRsOn0A==',
                    ],
                ],
            ],
        ];
    }
}