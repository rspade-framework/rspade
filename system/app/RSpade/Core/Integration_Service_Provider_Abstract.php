<?php

namespace App\RSpade\Core;

use Illuminate\Support\ServiceProvider;
use App\RSpade\Core\Bundle\BundleIntegration_Abstract;
use App\RSpade\Core\ExtensionRegistry;
use App\RSpade\Core\IntegrationRegistry;
use App\RSpade\Core\Kernels\ManifestKernel;

/**
 * Integration_Service_Provider_Abstract - Base class for integration service providers
 * 
 * This abstract class provides the foundation for registering external integrations
 * with the RSX framework. It handles the common registration tasks:
 * - Registering file extensions with ExtensionRegistry
 * - Registering processors with BundleCompiler
 * - Registering manifest modules with ManifestKernel
 * - Bootstrapping the integration
 * 
 * USAGE:
 * class Jqhtml_Service_Provider extends Integration_Service_Provider_Abstract {
 *     protected function get_integration_class(): string {
 *         return Jqhtml_BundleIntegration::class;
 *     }
 * }
 *
 * Then register the provider in config/app.php or a package service provider.
 */
#[Instantiatable]
abstract class Integration_Service_Provider_Abstract extends ServiceProvider
{
    /**
     * The integration instance
     */
    protected ?BundleIntegration_Abstract $integration = null;
    
    /**
     * Get the integration class for this provider
     * 
     * Concrete classes must implement this to return their integration class name.
     * 
     * @return string
     */
    abstract protected function get_integration_class(): string;
    
    /**
     * Register services
     */
    public function register(): void
    {
        $integration_class = $this->get_integration_class();

        // Check if integration is enabled
        if (!$integration_class::is_enabled()) {
            return;
        }

        // Register integration with the IntegrationRegistry
        IntegrationRegistry::register($integration_class);
        
        // Register file extensions
        foreach ($integration_class::get_file_extensions() as $extension) {
            ExtensionRegistry::register_extension(
                $extension,
                $integration_class::get_processor(),
                $integration_class::get_priority()
            );
            
            // Note: Processors are now configured globally in config/rsx.php
            // No need to register them here
        }
        
        // Register manifest module
        $manifest_module = $integration_class::get_manifest_module();
        if ($manifest_module && $this->app->has(ManifestKernel::class)) {
            $this->app->make(ManifestKernel::class)->register($manifest_module);
        }
    }
    
    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        $integration_class = $this->get_integration_class();
        
        // Check if integration is enabled
        if (!$integration_class::is_enabled()) {
            return;
        }
        
        // Register extension handlers with ExtensionRegistry
        $manifest_module = $integration_class::get_manifest_module();
        if ($manifest_module) {
            // Fail loud - let PHP throw error if class doesn't exist
            $module = new $manifest_module();
            
            // Register handler for each extension
            foreach ($integration_class::get_file_extensions() as $extension) {
                ExtensionRegistry::register_handler($extension, function($path, &$metadata) use ($module) {
                    $metadata = $module->process($path, $metadata);
                });
            }
        }
        
        // Bootstrap the integration
        $integration_class::bootstrap();
    }
    
    /**
     * Check dependencies and ensure they're loaded
     * 
     * @throws \RuntimeException if dependencies are not met
     */
    protected function check_dependencies(): void
    {
        $integration_class = $this->get_integration_class();
        $dependencies = $integration_class::get_dependencies();
        
        foreach ($dependencies as $dependency) {
            // This would check if the dependency integration is registered
            // Implementation depends on how we track registered integrations
        }
    }
    
    /**
     * Merge integration config with application config
     */
    protected function merge_config(): void
    {
        $integration_class = $this->get_integration_class();
        $name = $integration_class::get_name();
        $config = $integration_class::get_config();
        
        if (!empty($config)) {
            $this->mergeConfigFrom($config, "rsx.integrations.{$name}");
        }
    }
}