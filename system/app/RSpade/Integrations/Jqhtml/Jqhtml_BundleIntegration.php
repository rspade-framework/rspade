<?php

namespace App\RSpade\Integrations\Jqhtml;

use App\RSpade\Core\Bundle\BundleIntegration_Abstract;
use App\RSpade\Integrations\Jqhtml\Jqhtml_BundleProcessor;
use App\RSpade\Integrations\Jqhtml\Jqhtml_ManifestModule;

/**
 * JqhtmlIntegration - JQHTML template system integration
 * 
 * This integration adds support for JQHTML templates to the RSX framework.
 * It provides:
 * - Discovery of .jqhtml template files
 * - Compilation of templates to JavaScript
 * - Runtime library inclusion
 * - Automatic binding between templates and ES6 classes
 * 
 * JQHTML is a jQuery-based component templating system that compiles
 * HTML-like syntax to JavaScript functions for efficient rendering.
 */
class Jqhtml_BundleIntegration extends BundleIntegration_Abstract
{
    /**
     * Get the integration's unique identifier
     * 
     * @return string
     */
    public static function get_name(): string
    {
        return 'jqhtml';
    }
    
    /**
     * Get the ManifestModule class for file discovery
     * 
     * @return string|null
     */
    public static function get_manifest_module(): ?string
    {
        return Jqhtml_ManifestModule::class;
    }
    
    /**
     * Get the BundleModule_Abstract class for asset provision
     *
     * @return string|null
     */
    public static function get_bundle_module(): ?string
    {
        return null;  // BundleModule system is obsolete
    }
    
    /**
     * Get the Processor class for file transformation
     * 
     * @return string|null
     */
    public static function get_processor(): ?string
    {
        return Jqhtml_BundleProcessor::class;
    }
    
    /**
     * Get file extensions handled by this integration
     * 
     * @return array
     */
    public static function get_file_extensions(): array
    {
        return ['jqhtml', 'jqtpl'];
    }
    
    /**
     * Get configuration options for this integration
     * 
     * @return array
     */
    public static function get_config(): array
    {
        return [
            'compiler' => [
                'cache' => env('JQHTML_CACHE', true),
                'cache_ttl' => env('JQHTML_CACHE_TTL', 3600),
                'source_maps' => env('JQHTML_SOURCE_MAPS', !app()->environment('production')),
            ],
            'runtime' => [
                'bundle' => 'node_modules/@jqhtml/core/dist/index.js',
            ],
            'search_patterns' => [
                'rsx/**/*.jqhtml',
                'rsx/**/*.jqtpl',
            ],
        ];
    }
    
    /**
     * Get priority for this integration
     * 
     * @return int
     */
    public static function get_priority(): int
    {
        return 300; // After jQuery (100) and Lodash (90), before most other integrations
    }
    
    /**
     * Bootstrap the integration
     */
    public static function bootstrap(): void
    {
        // JQHTML runtime MUST exist - fail loud if not
        $runtime_path = base_path(static::get_config()['runtime']['bundle']);
        
        if (!file_exists($runtime_path)) {
            throw new \RuntimeException("JQHTML runtime not found at: {$runtime_path}. Run 'npm install' to install @jqhtml packages.");
        }
        
        // Register automatic template-to-class binding
        // This would be implemented to automatically bind templates to matching ES6 classes
    }
    
    /**
     * Get dependencies for this integration
     * 
     * @return array
     */
    public static function get_dependencies(): array
    {
        return ['jquery']; // JQHTML requires jQuery to be loaded first
    }
}