<?php

namespace App\RSpade\Integrations\Scss;

use App\RSpade\Core\Bundle\BundleProcessor_Abstract;

/**
 * ScssProcessor - Compiles SCSS files to CSS using Node.js sass compiler
 * 
 * This processor:
 * 1. Collects all .scss files in bundle order
 * 2. Creates a master file with @import directives
 * 3. Compiles using Node.js sass with source maps
 * 4. Returns compiled CSS for bundle inclusion
 */
class Scss_BundleProcessor extends BundleProcessor_Abstract
{
    /**
     * Temporary directory for SCSS compilation
     */
    protected static $temp_dir = null;
    
    /**
     * Collected SCSS files in order
     */
    protected static $scss_files = [];
    
    /**
     * Get processor name
     */
    public static function get_name(): string
    {
        return 'scss';
    }
    
    /**
     * Get file extensions this processor handles
     */
    public static function get_extensions(): array
    {
        return ['scss', 'sass'];
    }
    /**
     * Process multiple SCSS files in batch
     * Compiles SCSS files and appends the CSS output to the bundle
     */
    public static function process_batch(array &$bundle_files): void
    {
        // Collect all SCSS files from the bundle
        $scss_files = [];

        foreach ($bundle_files as $path) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);

            // Only process SCSS/SASS files
            if (in_array($ext, ['scss', 'sass'])) {
                // Validate the SCSS file for @import directives (unless it's a vendor file)
                static::__validate_scss_file($path);
                $scss_files[] = $path;
            }
        }

        // If no SCSS files, nothing to do
        if (empty($scss_files)) {
            return;
        }

        // NOTE: $scss_files order is deliberately preserved as-is. SCSS files carry
        // cross-file dependencies (variables/mixins defined in earlier files, used by
        // later ones), so they must be compiled in bundle-resolution order. That order
        // is made deterministic upstream, where the bundle resolver sorts each scanned
        // directory's files (explicit includes keep their authored order).

        // Generate cache key based on all SCSS files
        $cache_key = static::_get_scss_cache_key($scss_files);
        $temp_file = storage_path('rsx-tmp/scss_' . $cache_key . '.css');

        // Check if we already have compiled output
        $needs_compile = !file_exists($temp_file);

        // If temp file exists, check if any source is newer
        if (!$needs_compile && file_exists($temp_file)) {
            $temp_mtime = filemtime($temp_file);
            foreach ($scss_files as $source_file) {
                if (filemtime($source_file) > $temp_mtime) {
                    $needs_compile = true;
                    break;
                }
            }
        }

        // Compile if needed
        if ($needs_compile) {
            console_debug('BUNDLE', 'Compiling ' . count($scss_files) . ' SCSS files');

            // Create temp directory for compilation
            $compile_dir = storage_path('rsx-tmp/scss_compile_' . uniqid());
            if (!is_dir($compile_dir)) {
                mkdir($compile_dir, 0755, true);
            }

            // Create master SCSS file with imports in order
            $master_content = static::__create_master_scss($scss_files);
            $master_file = $compile_dir . '/app.scss';
            file_put_contents_safe($master_file, $master_content);

            // Compile SCSS to CSS using Node.js sass
            static::__compile_scss($master_file, $temp_file, [
                'minify' => app()->environment('production'),
                'source_maps' => !app()->environment('production')
            ]);

            // Clean up temp directory
            @unlink($master_file);
            @rmdir($compile_dir);
        } else {
            console_debug('BUNDLE', 'Using cached SCSS compilation: ' . basename($temp_file));
        }

        // Append the compiled CSS file to the bundle
        $bundle_files[] = $temp_file;
    }

    /**
     * Generate cache key for SCSS files
     *
     * Includes ALL files that might be imported by SCSS files.
     * This ensures cache invalidation when ANY dependency changes.
     */
    protected static function _get_scss_cache_key(array $scss_files): string
    {
        $all_files = [];

        // Add the direct SCSS files
        foreach ($scss_files as $file) {
            $all_files[$file] = true;
        }

        // For each SCSS file, scan for potential @import dependencies
        foreach ($scss_files as $file) {
            $dependencies = static::_scan_scss_dependencies($file);
            foreach ($dependencies as $dep) {
                $all_files[$dep] = true;
            }
        }

        // Generate hash from all files (direct + dependencies). Keyed on the
        // checkout-RELATIVE path plus file CONTENT (not absolute path + mtime/size):
        // this temp filename participates in bundle file ordering, so it must be
        // identical across two byte-identical checkouts. Sort for a stable, order-
        // independent key.
        $hashes = [];
        foreach (array_keys($all_files) as $file) {
            if (file_exists($file)) {
                $hashes[] = _rsx_relative_build_path($file) . ':' . md5_file($file);
            }
        }
        sort($hashes);

        return substr(md5(implode('|', $hashes)), 0, 16);
    }

    /**
     * Scan SCSS file for @import dependencies
     *
     * Recursively finds all files that might be imported.
     * Returns array of absolute file paths.
     */
    protected static function _scan_scss_dependencies(string $file, array &$visited = []): array
    {
        // Prevent infinite recursion
        if (isset($visited[$file])) {
            return [];
        }
        $visited[$file] = true;

        if (!file_exists($file)) {
            return [];
        }

        $dependencies = [];
        $content = file_get_contents($file);
        $base_dir = dirname($file);

        // Remove comments to avoid false positives
        $content = static::__remove_scss_comments($content);

        // Match @import statements: @import "file" or @import 'file'
        if (preg_match_all('/@import\s+["\']([^"\']+)["\']/', $content, $matches)) {
            foreach ($matches[1] as $import_path) {
                // Resolve the import path
                $resolved = static::_resolve_scss_import($import_path, $base_dir);

                if ($resolved && file_exists($resolved)) {
                    $dependencies[] = $resolved;

                    // Recursively scan this file's dependencies
                    $nested = static::_scan_scss_dependencies($resolved, $visited);
                    $dependencies = array_merge($dependencies, $nested);
                }
            }
        }

        return $dependencies;
    }

    /**
     * Resolve SCSS @import path to actual file
     *
     * SCSS import resolution rules:
     * - Can omit .scss extension
     * - Can import partials with _ prefix
     * - Searches in load paths
     */
    protected static function _resolve_scss_import(string $import_path, string $base_dir): ?string
    {
        // If it's an absolute path or starts with ~, skip (module import)
        if ($import_path[0] === '/' || $import_path[0] === '~') {
            return null;
        }

        // Possible file variations
        $variations = [];

        // Remove extension if present
        $path_without_ext = preg_replace('/\.(scss|sass)$/', '', $import_path);
        $dirname = dirname($path_without_ext);
        $basename = basename($path_without_ext);

        // Build variations
        $variations[] = "{$base_dir}/{$path_without_ext}.scss";
        $variations[] = "{$base_dir}/{$path_without_ext}.sass";
        $variations[] = "{$base_dir}/{$dirname}/_{$basename}.scss";
        $variations[] = "{$base_dir}/{$dirname}/_{$basename}.sass";

        // Try each variation
        foreach ($variations as $candidate) {
            $normalized = rsxrealpath($candidate);
            if ($normalized) {
                return $normalized;
            }
        }

        return null;
    }
    
    /**
     * Pre-processing hook - prepare temp directory
     */
    public static function before_processing(array $all_files, array $options = []): void
    {
        // Reset collected files
        static::$scss_files = [];
        
        // Create temp directory for compilation
        static::$temp_dir = storage_path('rsx-tmp/scss_' . uniqid());
        if (!is_dir(static::$temp_dir)) {
            mkdir(static::$temp_dir, 0755, true);
        }
    }
    
    /**
     * Post-processing hook - compile all SCSS files together
     */
    public static function after_processing(array $processed_files, array $options = []): array
    {
        if (empty(static::$scss_files)) {
            return [];
        }
        
        // Create master SCSS file with imports in order
        $master_content = static::__create_master_scss(static::$scss_files);
        $master_file = static::$temp_dir . '/app.scss';
        file_put_contents_safe($master_file, $master_content);
        
        // Compile SCSS to CSS using Node.js sass
        $output_file = static::$temp_dir . '/app.css';
        static::__compile_scss($master_file, $output_file, $options);
        
        // Clean up temp directory (except output file)
        @unlink($master_file);
        
        // Return the compiled CSS file to be included in bundle
        return [$output_file];
    }
    
    /**
     * Create master SCSS file with imports
     */
    protected static function __create_master_scss(array $scss_files): string
    {
        $imports = [];
        $imports[] = "// Master SCSS file - Generated by ScssProcessor";
        $imports[] = "// This file imports all SCSS files in the bundle in order";
        $imports[] = "";

        foreach ($scss_files as $file) {
            // Get relative path from project root
            $relative = str_replace(base_path() . '/', '', $file);

            // Add import statement
            // Use the actual file path for better source map support
            $imports[] = "/* ============ START: {$relative} ============ */";
            $imports[] = "@import " . json_encode($file) . ";";
            $imports[] = "/* ============ END: {$relative} ============ */";
            $imports[] = "";
        }

        return implode("\n", $imports);
    }
    
    /**
     * Compile SCSS to CSS using Node.js sass
     */
    protected static function __compile_scss(string $input_file, string $output_file, array $options): void
    {
        $is_production = $options['minify'] ?? false;
        $source_maps = $options['source_maps'] ?? !$is_production;
        
        // Create Node.js script for SCSS compilation
        $script = static::__create_compile_script($input_file, $output_file, $is_production, $source_maps);
        $script_file = dirname($input_file) . '/compile.js';
        file_put_contents_safe($script_file, $script);
        
        // Run the compilation (set working directory to project root for node_modules access)
        $command = 'cd ' . escapeshellarg(base_path()) . ' && node ' . escapeshellarg($script_file) . ' 2>&1';
        $output = shell_exec('bash -c ' . escapeshellarg($command));
        
        // Check for errors
        if (!file_exists($output_file)) {
            throw new \RuntimeException("SCSS compilation failed: " . $output);
        }

        // Clean up script file
        @unlink($script_file);
    }
    
    /**
     * Create Node.js compilation script
     */
    protected static function __create_compile_script(
        string $input_file,
        string $output_file,
        bool $is_production,
        bool $source_maps
    ): string {
        // NOTE: 'sass' is required by ABSOLUTE path. This script is written into
        // storage/rsx-tmp, which lives at the PROJECT ROOT (storage was relocated out of
        // system/), so node's upward node_modules walk from the script no longer reaches
        // system/node_modules where the framework's dependencies live.
        $script = <<<'JS'
const sass = require('%BASEPATH%/node_modules/sass');
const fs = require('fs');
const path = require('path');

const inputFile = process.argv[2] || '%INPUT%';
const outputFile = process.argv[3] || '%OUTPUT%';
const isProduction = %PRODUCTION%;
const enableSourceMaps = %SOURCEMAPS%;
const basePath = '%BASEPATH%';

async function compile() {
    try {
        // Compile SCSS
        const result = sass.compile(inputFile, {
            style: isProduction ? 'compressed' : 'expanded',
            sourceMap: enableSourceMaps,
            sourceMapIncludeSources: true,
            verbose: !isProduction,  // Show all deprecation warnings in dev mode
            silenceDeprecations: ['import', 'if-function', 'global-builtin', 'color-functions'],  // Suppress deprecation warnings until Sass 3.0 migration
            loadPaths: [
                path.dirname(inputFile),
                basePath + '/rsx',
                basePath + '/rsx/styles',
                basePath + '/resources/sass',
                basePath + '/node_modules'
            ]
        });

        let cssContent = result.css;

        // Add inline source map if enabled
        // Using embedded source maps for better debugging experience
        if (enableSourceMaps && result.sourceMap) {
            // Add file boundaries in expanded mode for easier debugging
            if (!isProduction) {
                cssContent = cssContent.replace(/\/\* ============ START: (.+?) ============ \*\//g,
                    '\n/* ======= FILE: $1 ======= */\n');
                cssContent = cssContent.replace(/\/\* ============ END: .+? ============ \*\//g, '');
            }

            // Fix sourcemap paths to be relative to project root and remove file:// protocol
            const sourceMap = result.sourceMap;
            sourceMap.sourceRoot = '';  // Use relative paths

            // Clean up source paths (but don't filter - keep all sources to preserve mapping indices)
            sourceMap.sources = result.sourceMap.sources.map(source => {
                let cleanedSource = source;

                // Remove file:// protocol if present
                // file:///path means file (protocol) + :// (separator) + /path (absolute path)
                // So file:///var/www/html should become /var/www/html
                if (cleanedSource.startsWith('file:///')) {
                    cleanedSource = '/' + cleanedSource.substring(8);  // Remove 'file:///' and add back the leading /
                } else if (cleanedSource.startsWith('file://')) {
                    cleanedSource = cleanedSource.substring(7);  // Remove 'file://' (non-standard)
                }

                // Make paths relative to project root
                if (cleanedSource.startsWith(basePath + '/')) {
                    cleanedSource = cleanedSource.substring(basePath.length + 1);
                } else if (cleanedSource.startsWith(basePath)) {
                    cleanedSource = cleanedSource.substring(basePath.length);
                    if (cleanedSource.startsWith('/')) {
                        cleanedSource = cleanedSource.substring(1);
                    }
                }

                return cleanedSource;
            });

            const sourceMapBase64 = Buffer.from(JSON.stringify(sourceMap)).toString('base64');
            cssContent += '\n/*# sourceMappingURL=data:application/json;charset=utf-8;base64,' + sourceMapBase64 + ' */';
        }
        
        // Write output
        fs.writeFileSync(outputFile, cssContent);
        
        console.log('SCSS compilation successful');
        
        // If production, also run postcss for additional optimization
        if (isProduction) {
            await optimizeWithPostCSS(outputFile);
        }
    } catch (error) {
        console.error('SCSS compilation error:', error.message);
        process.exit(1);
    }
}

async function optimizeWithPostCSS(file) {
    try {
        const postcss = require('postcss');
        const autoprefixer = require('autoprefixer');
        const cssnano = require('cssnano');
        
        const css = fs.readFileSync(file, 'utf8');
        
        const result = await postcss([
            autoprefixer(),
            cssnano({
                preset: ['default', {
                    discardComments: {
                        removeAll: true,
                    },
                }]
            })
        ]).process(css, { 
            from: file,
            to: file,
            map: enableSourceMaps ? { inline: true, sourcesContent: true } : false
        });
        
        fs.writeFileSync(file, result.css);
        console.log('PostCSS optimization complete');
    } catch (error) {
        console.error('PostCSS optimization failed:', error.message);
        // Don't fail the build if postcss fails
    }
}

compile();
JS;
        
        // Replace placeholders
        $script = str_replace('%INPUT%', $input_file, $script);
        $script = str_replace('%OUTPUT%', $output_file, $script);
        $script = str_replace('%PRODUCTION%', $is_production ? 'true' : 'false', $script);
        $script = str_replace('%SOURCEMAPS%', $source_maps ? 'true' : 'false', $script);
        $script = str_replace('%BASEPATH%', base_path(), $script);
        
        return $script;
    }
    
    /**
     * Get processor priority (run early to process SCSS before CSS concatenation)
     */
    public static function get_priority(): int
    {
        return 100; // High priority - process before other CSS processors
    }
    
    /**
     * Validate that non-vendor SCSS files don't contain @import directives
     * 
     * @param string $file_path The path to the SCSS file
     * @throws \RuntimeException If a non-vendor file contains @import directives
     */
    protected static function __validate_scss_file(string $file_path): void
    {
        // Check if this is a vendor file (contains '/vendor/' in the path)
        $relative_path = str_replace(base_path() . '/', '', $file_path);
        $is_vendor_file = str_contains($relative_path, '/vendor/');
        
        // Vendor files are allowed to have @import directives
        if ($is_vendor_file) {
            return;
        }
        
        // Read the file content
        if (!file_exists($file_path)) {
            throw new \RuntimeException("SCSS file not found: {$file_path}");
        }
        
        $content = file_get_contents($file_path);
        
        // Check for @import directives (must handle comments properly)
        // First remove comments to avoid false positives
        $content_without_comments = static::__remove_scss_comments($content);
        
        // Now check for @import directives
        if (preg_match('/@import\s+["\']/', $content_without_comments)) {
            // Extract the actual @import lines for the error message
            preg_match_all('/@import\s+["\'][^"\']+["\']/', $content, $imports);
            $import_list = implode("\n  ", $imports[0]);
            
            throw new \RuntimeException(
                "SCSS file '{$relative_path}' contains @import directives, which are not allowed in non-vendor files.\n\n" .
                "Found imports:\n  {$import_list}\n\n" .
                "Why this is not allowed:\n" .
                "- @import directives bypass the bundle system's dependency management\n" .
                "- Files should be explicitly included in bundle definitions instead\n" .
                "- This ensures proper load order and prevents missing dependencies\n\n" .
                "How to fix:\n" .
                "1. Remove the @import directives from this file\n" .
                "2. Add the imported files directly to your bundle's include list\n" .
                "3. Example: include: ['rsx/styles/variables.scss', 'rsx/app/mymodule/styles.scss']\n\n" .
                "Exception for vendor files:\n" .
                "- Files in 'vendor' directories CAN use @import (e.g., Bootstrap's SCSS)\n" .
                "- These are third-party files that manage their own dependencies\n" .
                "- Include only the main vendor entry file in your bundle"
            );
        }
    }
    
    /**
     * Remove comments from SCSS content to avoid false positives in validation
     * 
     * @param string $content The SCSS content
     * @return string The content without comments
     */
    protected static function __remove_scss_comments(string $content): string
    {
        // Remove single-line comments (// ...)
        $content = preg_replace('#//.*?$#m', '', $content);
        
        // Remove multi-line comments (/* ... */)
        $content = preg_replace('#/\*.*?\*/#s', '', $content);
        
        return $content;
    }
}