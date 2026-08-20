<?php

namespace App\RSpade\CodeQuality\RuntimeChecks;

use RuntimeException;
use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;

/**
 * Developer-facing bundle validation errors
 *
 * These exceptions guide developers to fix bundle configuration issues
 * during development. Each method builds a comprehensive error message
 * explaining the problem and how to fix it.
 */
class BundleErrors
{
    /**
     * Bundle doesn't include the view/layout directory
     */
    public static function view_not_covered(
        string $view_path,
        string $view_dir,
        string $bundle_class,
        array $include_paths
    ): void {
        $bundle_short_name = basename(str_replace('\\', '/', $bundle_class));

        $error_message = "RSX Bundle Path Coverage Error: Bundle doesn't scan the view/layout directory.\n\n";
        $error_message .= "View/Layout file: {$view_path}\n";
        $error_message .= "View directory: {$view_dir}/\n";
        $error_message .= "Bundle: {$bundle_short_name}\n\n";
        $error_message .= "The bundle's scan directories don't include the view's location.\n";
        $error_message .= "Current scan directories:\n";
        foreach ($include_paths as $path) {
            $error_message .= "  - {$path}\n";
        }
        $error_message .= "\n";
        $error_message .= "To fix this, either:\n";
        $error_message .= "1. Add '{$view_dir}' to the bundle's 'include' array\n";
        $error_message .= "2. Use a different bundle that covers this module\n";
        $error_message .= "3. Move the view to a directory covered by this bundle\n\n";
        $error_message .= 'This ensures bundles are properly scoped to the modules they serve.';

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Bundle doesn't include the current controller
     */
    public static function controller_not_covered(
        string $current_controller,
        string $current_action,
        string $controller_path,
        string $controller_dir,
        string $bundle_class,
        array $include_paths
    ): void {
        $bundle_short_name = basename(str_replace('\\', '/', $bundle_class));

        $error_message = "RSX Bundle Controller Coverage Error: Bundle doesn't include the current controller.\n\n";
        $error_message .= "Controller: {$current_controller}::{$current_action}\n";
        $error_message .= "Controller file: {$controller_path}\n";
        $error_message .= "Controller directory: {$controller_dir}/\n";
        $error_message .= "Bundle: {$bundle_short_name}\n\n";
        $error_message .= "The bundle's scan directories don't include the controller's location.\n";
        $error_message .= "Current scan directories:\n";
        foreach ($include_paths as $path) {
            $error_message .= "  - {$path}\n";
        }
        $error_message .= "\n";
        $error_message .= "To fix this, either:\n";
        $error_message .= "1. Add '{$controller_dir}' to the bundle's 'include' array\n";
        $error_message .= "2. Move the controller to a directory covered by this bundle\n";
        $error_message .= "3. Use a bundle that covers both the controller and view\n\n";
        $error_message .= 'This ensures bundles include all code needed for the routes they serve.';

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Cannot determine calling view path for bundle validation
     */
    public static function cannot_determine_view_path(): void
    {
        throw new RuntimeException('Cannot determine calling view path. Ensure rsx_view() helper is used to render views.');
    }

    /**
     * Bundle abstract class called directly
     */
    public static function abstract_called_directly(): void
    {
        throw new RuntimeException(
            'Rsx_Bundle_Abstract::render() cannot be called directly. ' .
            'Call render() on a specific bundle class instead (e.g., Demo_Bundle::render()).'
        );
    }
}