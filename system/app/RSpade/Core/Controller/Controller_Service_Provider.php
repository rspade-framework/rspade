<?php

namespace App\RSpade\Core\Controller;

use App\RSpade\Core\Controller\Controller_BundleIntegration;
use App\RSpade\Core\Integration_Service_Provider_Abstract;

/**
 * Controller_Service_Provider - Service provider for controller integration
 *
 * This provider registers the controller integration with the RSX framework.
 * It handles generation of JavaScript stub files for controllers with
 * Ajax_Endpoint methods.
 */
class Controller_Service_Provider extends Integration_Service_Provider_Abstract
{
    /**
     * Get the integration class for this provider
     *
     * @return string
     */
    protected function get_integration_class(): string
    {
        return Controller_BundleIntegration::class;
    }
}