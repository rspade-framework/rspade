<?php
/**
 * Bootstrap 5 Source Bundle
 *
 * Provides the Bootstrap 5 SCSS source files for compilation.
 * This bundle allows customization of Bootstrap variables and selective
 * inclusion of Bootstrap components through SCSS imports.
 *
 * @CONV-BUNDLE-02-EXCEPTION - This bundle intentionally includes only specific vendor files, not the entire theme directory
 */

namespace Rsx\Theme;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

class Bootstrap5_Src_Bundle extends Rsx_Asset_Bundle_Abstract
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
                // Include custom Bootstrap build with variable overrides
                'rsx/theme/vendor/bootstrap_custom.scss',
                // Include Bootstrap JavaScript bundle (includes Popper.js)
                'rsx/theme/vendor/bootstrap5/dist/js/bootstrap.bundle.js',
            ],
            'watch' => [
                // Watch all Bootstrap SCSS files for changes
                'rsx/theme/vendor/bootstrap5/scss',
                'rsx/theme/variables.scss',
            ],
            'config' => [
                // Provide module paths for SCSS imports
                // This allows using @import "~bootstrap5_src/..." in SCSS files
                'module_paths' => [
                    'bootstrap5_src' => 'rsx/theme/vendor/bootstrap5/scss',
                ],
            ],
            'cdn_assets' => [
                'css' => [
                    [
                        'url' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css',
                    ],
                ],
            ],
        ];
    }
}
