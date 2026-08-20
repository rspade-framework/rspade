<?php

namespace App\RSpade\Core;

use RuntimeException;
use App\RSpade\Core\Bundle\BundleIntegration_Abstract;

/**
 * IntegrationRegistry - Central registry for framework integrations
 *
 * This class tracks all registered integrations and provides
 * a central access point for the framework to interact with them.
 * Integrations are registered via service providers.
 */
class IntegrationRegistry
{
    /**
     * Registered integrations
     * Format: ['name' => integration_class]
     */
    protected static array $integrations = [];

    /**
     * Register an integration
     *
     * @param string $integration_class The fully qualified class name of the integration
     * @return void
     */
    public static function register(string $integration_class): void
    {
        // Validate the class extends BundleIntegration_Abstract
        if (!is_subclass_of($integration_class, BundleIntegration_Abstract::class)) {
            throw new RuntimeException("Integration class {$integration_class} must extend BundleIntegration_Abstract");
        }

        $name = $integration_class::get_name();
        static::$integrations[$name] = $integration_class;
    }

    /**
     * Get all registered integrations
     *
     * @return array Array of integration class names
     */
    public static function get_all(): array
    {
        return array_values(static::$integrations);
    }

    /**
     * Get an integration by name
     *
     * @param string $name Integration name
     * @return string|null Integration class name or null if not found
     */
    public static function get(string $name): ?string
    {
        return static::$integrations[$name] ?? null;
    }

    /**
     * Check if an integration is registered
     *
     * @param string $name Integration name
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(static::$integrations[$name]);
    }

    /**
     * Clear all registered integrations
     * (Mainly for testing purposes)
     *
     * @return void
     */
    public static function clear(): void
    {
        static::$integrations = [];
    }
}
