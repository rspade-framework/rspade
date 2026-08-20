<?php

namespace App\RSpade\CodeQuality\Rules\Blade;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class LayoutLocalAssets_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'BLADE-LAYOUT-ASSETS-01';
    }

    public function get_name(): string
    {
        return 'Layout Local Asset Includes';
    }

    public function get_description(): string
    {
        return 'Enforces that local CSS and JavaScript files in layout files are included via bundle definitions, not hardcoded <link rel="stylesheet"> or <script src> tags';
    }

    public function get_file_patterns(): array
    {
        return ['*.blade.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * This rule should run during manifest scan to provide immediate feedback
     *
     * IMPORTANT: This method should ALWAYS return false unless explicitly requested
     * by the framework developer. Manifest-time checks are reserved for critical
     * framework convention violations that need immediate developer attention.
     *
     * Rules executed during manifest scan will run on every file change in development,
     * potentially impacting performance. Only enable this for rules that:
     * - Enforce critical framework conventions that would break the application
     * - Need to provide immediate feedback before code execution
     * - Have been specifically requested to run at manifest-time by framework maintainers
     *
     * DEFAULT: Always return false unless you have explicit permission to do otherwise.
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * hardcoded local asset includes in layouts bypass the bundle system and break
     * cache-busting and asset management conventions.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    /**
     * Process file during manifest update to extract local asset violations
     */
    public function on_manifest_file_update(string $file_path, string $contents, array $metadata = []): ?array
    {
        // Only check files that contain <html> (layouts)
        if (!str_contains($contents, '<html')) {
            return null;
        }

        $lines = explode("\n", $contents);
        $violations = [];

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Check for <link rel="stylesheet" with local href (CSS only, not favicons or other link types)
            if (preg_match('/<link\s+[^>]*rel=["\']stylesheet["\'][^>]*href=["\'](\/[^"\']*)["\'][^>]*>/i', $line, $matches)) {
                $href = $matches[1];

                // Skip if it's a CDN/external URL (contains http)
                if (str_contains(strtolower($line), 'http')) {
                    continue;
                }

                $violations[] = [
                    'type' => 'local_css',
                    'line' => $line_number,
                    'code' => trim($line),
                    'path' => $href
                ];
            }

            // Also check reversed attribute order: href before rel
            if (preg_match('/<link\s+[^>]*href=["\'](\/[^"\']*)["\'][^>]*rel=["\']stylesheet["\'][^>]*>/i', $line, $matches)) {
                $href = $matches[1];

                // Skip if it's a CDN/external URL (contains http)
                if (str_contains(strtolower($line), 'http')) {
                    continue;
                }

                $violations[] = [
                    'type' => 'local_css',
                    'line' => $line_number,
                    'code' => trim($line),
                    'path' => $href
                ];
            }

            // Check for <script src= with local src
            if (preg_match('/<script\s+[^>]*src=["\'](\/[^"\']*)["\'][^>]*>/i', $line, $matches)) {
                $src = $matches[1];

                // Skip if it's a CDN/external URL (contains http)
                if (str_contains(strtolower($line), 'http')) {
                    continue;
                }

                $violations[] = [
                    'type' => 'local_js',
                    'line' => $line_number,
                    'code' => trim($line),
                    'path' => $src
                ];
            }
        }

        if (!empty($violations)) {
            return ['local_asset_violations' => $violations];
        }

        return null;
    }

    /**
     * Check blade layout file for local asset violations stored in metadata
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check layouts
        if (!str_contains($contents, '<html')) {
            return;
        }

        // Check for violations in code quality metadata
        if (isset($metadata['code_quality_metadata']['BLADE-LAYOUT-ASSETS-01']['local_asset_violations'])) {
            $violations = $metadata['code_quality_metadata']['BLADE-LAYOUT-ASSETS-01']['local_asset_violations'];

            // Throw on first violation
            foreach ($violations as $violation) {
                $asset_type = $violation['type'] === 'local_css' ? 'CSS' : 'JavaScript';

                $error_message = "Code Quality Violation (BLADE-LAYOUT-ASSETS-01) - Local {$asset_type} Asset in Layout\n\n";
                $error_message .= "Local asset files should be included via bundle definitions, not hardcoded in layout files.\n\n";
                $error_message .= "File: {$file_path}\n";
                $error_message .= "Line: {$violation['line']}\n";
                $error_message .= "Path: {$violation['path']}\n";
                $error_message .= "Code: {$violation['code']}\n\n";
                $error_message .= $this->get_detailed_remediation($file_path, $violation);

                throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                    $error_message,
                    0,
                    null,
                    base_path($file_path),
                    $violation['line']
                );
            }
        }
    }

    /**
     * Get detailed remediation instructions
     */
    private function get_detailed_remediation(string $file_path, array $violation): string
    {
        $path = $violation['path'];
        $is_css = $violation['type'] === 'local_css';
        $tag_type = $is_css ? '<link>' : '<script>';

        // Determine bundle file name from layout path
        $path_parts = pathinfo($file_path);
        $dir_name = basename(dirname($file_path));
        $bundle_name = ucfirst($dir_name) . '_Bundle';
        $bundle_file = dirname($file_path) . '/' . strtolower($dir_name) . '_bundle.php';

        return "FRAMEWORK CONVENTION: Local assets must be included via bundle definitions.

WHY THIS MATTERS:
- Bundle system provides automatic cache-busting
- Assets are properly ordered with dependencies
- Development/production builds are optimized
- All assets are tracked and validated

REQUIRED STEPS:

1. Remove the hardcoded {$tag_type} tag from {$file_path}:
   DELETE: {$violation['code']}

2. Add the asset to your bundle definition in {$bundle_file}:

   class {$bundle_name} extends Rsx_Bundle_Abstract
   {
       public static function define(): array
       {
           return [
               'include' => [
                   'jquery',
                   'lodash',
                   '/public{$path}',  // Add this line
                   'rsx/app/{$dir_name}',
               ],
           ];
       }
   }

3. The bundle system will automatically generate:
   " . ($is_css
       ? "<link rel=\"stylesheet\" href=\"{$path}?v=<?php echo filemtime('FULL_PATH'); ?>\">"
       : "<script src=\"{$path}?v=<?php echo filemtime('FULL_PATH'); ?>\" defer></script>") . "

KEY BENEFITS:
- Automatic filemtime() cache-busting on every page load
- Proper asset ordering (CDN assets -> Public assets -> Compiled bundles)
- Redis-cached path resolution for performance
- Ambiguity detection prevents multiple files with same path

BUNDLE INCLUDE SYNTAX:
- Prefix with /public/ for static assets from public/ directories
- Path after /public/ is searched across ALL public/ directories in rsx/
- Example: '/public/vendor/css/core.css' resolves to 'rsx/public/vendor/css/core.css'

CACHE-BUSTING:
- Bundle generates tags with <?php echo filemtime('...'); ?> for fresh timestamps
- No need to manually manage version parameters
- Updates automatically when file changes

For complete documentation:
    php artisan rsx:man bundle_api
    (See PUBLIC ASSET INCLUDES section)";
    }
}
