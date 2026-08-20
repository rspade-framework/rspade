<?php
/**
 * Quill WYSIWYG Editor CDN Bundle
 *
 * Provides the Quill editor via CDN for rich text editing.
 * Quill is a powerful, modern WYSIWYG editor built for the modern web.
 *
 * Features:
 * - Rich text editing with customizable toolbar
 * - Clean HTML output
 * - Extensive API for customization
 * - Themes: Snow (standard) and Bubble (inline)
 * - Module-based architecture
 */

namespace App\RSpade\Bundles;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

class Quill_Bundle extends Rsx_Asset_Bundle_Abstract
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
                        'url' => 'https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css',
                    ],
                ],
                'js' => [
                    [
                        'url' => 'https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js',
                    ],
                ],
            ],
        ];
    }
}
