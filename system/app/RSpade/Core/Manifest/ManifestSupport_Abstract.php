<?php

namespace App\RSpade\Core\Manifest;

/**
 * Abstract base class for manifest support modules
 *
 * These modules run after the primary manifest is built to add
 * supplementary metadata that requires the full manifest to be available.
 */
abstract class ManifestSupport_Abstract
{
    /**
     * Process the manifest and add supplementary metadata
     *
     * Called after all ManifestModule processors have run and all files
     * have been loaded. The manifest data array is passed by reference
     * so modifications are made directly to it.
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    abstract public static function process(array &$manifest_data): void;

    /**
     * Get the name of this support module for logging/debugging
     *
     * @return string
     */
    abstract public static function get_name(): string;

    /**
     * Check if this support module should run
     *
     * Allows support modules to skip processing based on environment
     * or configuration. Default implementation always returns true.
     *
     * @return bool
     */
    public static function should_run(): bool
    {
        return true;
    }
}
