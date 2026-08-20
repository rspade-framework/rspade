<?php

namespace App\RSpade\Core\Bundle;

/**
 * Bundle_Integration_Abstract - Base class for external integrations into the RSX framework
 * 
 * This abstract class defines the contract for integrations (plugins) that extend
 * the framework's capabilities. Each integration can provide:
 * - File type handlers for the Manifest system
 * - Asset modules for the Bundle system
 * - File processors for transformation during bundling
 * - Custom file extensions to be discovered
 * 
 * EXAMPLE IMPLEMENTATIONS:
 * - Jqhtml_BundleIntegration: Adds .jqhtml template support
 * - TypeScriptIntegration: Adds TypeScript compilation
 * - VueIntegration: Adds .vue single-file component support
 * 
 * REGISTRATION:
 * Integrations are registered via service providers that call the appropriate
 * registration methods on ExtensionRegistry, BundleCompiler, etc.
 */
abstract class BundleIntegration_Abstract
{
    /**
     * Get the integration's unique identifier
     * 
     * This should be a short, lowercase string that uniquely identifies
     * the integration (e.g., 'jqhtml', 'typescript', 'vue')
     * 
     * @return string Integration identifier
     */
    abstract public static function get_name(): string;
    
    /**
     * Get file extensions handled by this integration
     * 
     * Return an array of file extensions (without dots) that this
     * integration handles. These will be registered with the ExtensionRegistry
     * for discovery and processing.
     * 
     * @return array File extensions (e.g., ['jqhtml', 'jqtpl'])
     */
    abstract public static function get_file_extensions(): array;
    
    /**
     * Get the ManifestModule class for file discovery (default: null)
     * 
     * Return the fully qualified class name of a ManifestModule that
     * handles file discovery and metadata extraction for this integration's
     * file types. Return null if no manifest processing is needed.
     * 
     * @return string|null ManifestModule class name or null
     */
    #[Replaceable]
    public static function get_manifest_module(): ?string
    {
        return null;
    }
    
    /**
     * @deprecated BundleModule system is obsolete - use BundleProcessor instead
     *
     * This method is retained for backwards compatibility but always returns null.
     * The BundleModule system has been replaced by BundleProcessor for all
     * compilation and asset handling needs.
     *
     * @return null Always returns null
     */
    #[Replaceable]
    public static function get_bundle_module(): ?string
    {
        return null;  // BundleModule system is obsolete
    }
    
    /**
     * Get the Processor class for file transformation (default: null)
     * 
     * Return the fully qualified class name of a BundleProcessor that
     * transforms this integration's file types during bundle compilation.
     * Return null if no processing is needed.
     * 
     * @return string|null BundleProcessor class name or null
     */
    #[Replaceable]
    public static function get_processor(): ?string
    {
        return null;
    }
    
    /**
     * Get configuration options for this integration (default: empty array)
     * 
     * Return an array of configuration options that can be customized
     * in config files or at runtime. These might include compiler options,
     * feature flags, or other settings.
     * 
     * @return array Configuration options with defaults
     */
    #[Replaceable]
    public static function get_config(): array
    {
        return [];
    }
    
    /**
     * Get priority for this integration (default: 1000)
     * 
     * Lower values mean higher priority. This affects the order in which
     * integrations are loaded and their processors are run.
     * 
     * @return int Priority (default 1000)
     */
    #[Replaceable]
    public static function get_priority(): int
    {
        return 1000;
    }
    
    /**
     * Check if this integration is enabled (default: true)
     * 
     * Return whether this integration should be active. This might check
     * configuration settings, environment variables, or other conditions.
     * 
     * @return bool True if enabled
     */
    public static function is_enabled(): bool
    {
        return true;
    }
    
    /**
     * Bootstrap the integration (default: no-op)
     * 
     * Called when the integration is registered. Use this to perform any
     * one-time setup, register additional services, or configure the environment.
     * This is called after all core services are available.
     */
    #[Replaceable]
    public static function bootstrap(): void
    {
        // Override in subclasses if needed
    }
    
    /**
     * Get dependencies for this integration (default: empty array)
     *
     * Return an array of other integration names that must be loaded
     * before this one. The framework will ensure proper loading order.
     *
     * @return array Integration names this depends on
     */
    #[Replaceable]
    public static function get_dependencies(): array
    {
        return [];
    }

    /**
     * Generate JavaScript stub files for manifest entries (default: no-op)
     *
     * Called during manifest building (Phase 5) to generate JavaScript
     * stub files that provide IDE autocomplete and runtime functionality
     * for PHP classes. Integrations can use this to create JS equivalents
     * of controllers, models, or other PHP classes.
     *
     * @param array &$manifest_data The complete manifest data (passed by reference)
     * @return void
     */
    #[Replaceable]
    public static function generate_manifest_stubs(array &$manifest_data): void
    {
        // Override in subclasses to generate stubs
    }
}