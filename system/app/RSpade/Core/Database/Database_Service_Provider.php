<?php

namespace App\RSpade\Core\Database;

use App\RSpade\Core\Database\Database_BundleIntegration;
use App\RSpade\Core\Integration_Service_Provider_Abstract;

/**
 * Database_Service_Provider - Service provider for database integration
 *
 * This provider registers the database integration with the RSX framework.
 * It handles generation of JavaScript stub files for ORM models.
 */
class Database_Service_Provider extends Integration_Service_Provider_Abstract
{
    /**
     * Get the integration class for this provider
     *
     * @return string
     */
    protected function get_integration_class(): string
    {
        return Database_BundleIntegration::class;
    }
}