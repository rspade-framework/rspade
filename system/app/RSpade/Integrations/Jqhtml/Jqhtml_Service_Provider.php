<?php

namespace App\RSpade\Integrations\Jqhtml;

use App\RSpade\Core\Integration_Service_Provider_Abstract;
use App\RSpade\Integrations\Jqhtml\Jqhtml_BundleIntegration;

/**
 * Jqhtml_Service_Provider - Service provider for JQHTML integration
 * 
 * This provider registers the JQHTML integration with the RSX framework.
 * It handles:
 * - Registering file extensions with ExtensionRegistry
 * - Registering the processor with BundleCompiler
 * - Registering the manifest module with ManifestKernel
 * - Bootstrapping the JQHTML runtime
 * 
 * To use this integration, register this provider in config/app.php:
 * App\RSpade\Integrations\Jqhtml\Jqhtml_Service_Provider::class
 * 
 * Or register it conditionally in AppServiceProvider if needed.
 */
class Jqhtml_Service_Provider extends Integration_Service_Provider_Abstract
{
    /**
     * Get the integration class for this provider
     * 
     * @return string
     */
    protected function get_integration_class(): string
    {
        return Jqhtml_BundleIntegration::class;
    }
}