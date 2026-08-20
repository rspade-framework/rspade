<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use App\RSpade\Core\Auth\Auth_BundleIntegration;
use App\RSpade\Core\Integration_Service_Provider_Abstract;

/**
 * Auth_Service_Provider - Service provider for the auth gates integration
 *
 * Registers Auth_BundleIntegration, which generates the JS Permission /
 * Portal_Permission check mirrors during the manifest build.
 */
class Auth_Service_Provider extends Integration_Service_Provider_Abstract
{
    /**
     * Get the integration class for this provider
     *
     * @return string
     */
    protected function get_integration_class(): string
    {
        return Auth_BundleIntegration::class;
    }
}
