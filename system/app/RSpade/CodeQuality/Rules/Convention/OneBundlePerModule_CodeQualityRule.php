<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class OneBundlePerModule_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'CONV-BUNDLE-03';
    }

    public function get_name(): string
    {
        return 'One Bundle Per Module Directory Convention';
    }

    public function get_description(): string
    {
        return 'Module directories should have only one bundle (./rsx/app root can have multiple)';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Whether this rule is called during manifest scan
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * bundle organization is a critical framework convention for module structure.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if not a PHP class file
        if (!isset($metadata['class'])) {
            return;
        }

        // Check if class extends Rsx_Bundle_Abstract
        $extends = $metadata['extends'] ?? '';
        if ($extends !== 'Rsx_Bundle_Abstract' &&
            $extends !== 'App\\RSpade\\Core\\Bundle\\Rsx_Bundle_Abstract') {
            return;
        }

        // Get relative path from base
        $relative_path = str_replace(base_path() . '/', '', $file_path);
        $dir_path = dirname($relative_path);

        // Skip if bundle is directly in rsx/app (they can have multiple)
        if ($dir_path === 'rsx/app') {
            return;
        }

        // Skip if not in rsx/app
        if (!str_starts_with($dir_path, 'rsx/app/')) {
            return;
        }

        // Check the manifest for other bundles in the same directory
        $manifest = Manifest::get_all();
        $bundles_in_same_dir = [];

        foreach ($manifest as $path => $file_metadata) {
            // Check if it's a PHP file in the same directory
            if (dirname($path) !== $dir_path) {
                continue;
            }

            // Check if it's a bundle class
            if (isset($file_metadata['class']) && isset($file_metadata['extends'])) {
                $file_extends = $file_metadata['extends'];
                if ($file_extends === 'Rsx_Bundle_Abstract' ||
                    $file_extends === 'App\\RSpade\\Core\\Bundle\\Rsx_Bundle_Abstract') {
                    $bundles_in_same_dir[] = basename($path, '.php');
                }
            }
        }

        // If there's more than one bundle in this directory, throw an exception
        if (count($bundles_in_same_dir) > 1) {
            $error_message = "Code Quality Violation (CONV-BUNDLE-03) - Multiple Bundles in Same Directory\n\n";
            $error_message .= "Module directory '{$dir_path}' has multiple bundle files:\n";
            foreach ($bundles_in_same_dir as $bundle_name) {
                $error_message .= "  - {$bundle_name}.php\n";
            }
            $error_message .= "\nCRITICAL: Each module directory should have only ONE bundle.\n\n";
            $error_message .= "Resolution:\n";
            $error_message .= "1. Consolidate these bundles into a single bundle file\n";
            $error_message .= "2. OR move extra bundles to their own module directories\n";
            $error_message .= "3. OR move them to ./rsx/app/ (which allows multiple bundles)\n\n";
            $error_message .= "This convention ensures clean module organization and predictable bundle loading.";

            throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                $error_message,
                0,
                null,
                $file_path,
                1
            );
        }
    }
}