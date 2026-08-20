<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class PackageJson_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PKG-JSON-01';
    }

    public function get_name(): string
    {
        return 'Package.json devDependencies Check';
    }

    public function get_description(): string
    {
        return 'Ensures package.json files only use dependencies, not devDependencies';
    }

    public function get_file_patterns(): array
    {
        return ['package.json'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check package.json files for devDependencies
     * RSpade standard: All packages should be in dependencies, not devDependencies
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check package.json files
        if (basename($file_path) !== 'package.json') {
            return;
        }

        // Skip node_modules
        if (str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Parse JSON
        $json = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Invalid JSON - skip check (other rules will catch this)
            return;
        }

        // Check for devDependencies
        if (isset($json['devDependencies']) && !empty($json['devDependencies'])) {
            $dev_count = count($json['devDependencies']);
            $packages = array_keys($json['devDependencies']);
            $packages_list = implode(', ', array_slice($packages, 0, 5));

            if ($dev_count > 5) {
                $packages_list .= ', and ' . ($dev_count - 5) . ' more';
            }

            $this->add_violation(
                $file_path,
                0, // JSON files don't have meaningful line numbers for this check
                "RSpade Standard Violation: package.json contains {$dev_count} devDependencies. " .
                "In RSpade, all packages should be in 'dependencies' to ensure consistent installations. " .
                "Found packages: {$packages_list}",
                '"devDependencies": { ... }',
                "Move all packages from 'devDependencies' to 'dependencies' and remove the 'devDependencies' key entirely. " .
                "RSpade makes no distinction between dev and production packages - all software needed for the project should be installed.",
                'high'
            );
        }
    }
}