<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class BundleLocation_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'CONV-BUNDLE-01';
    }

    public function get_name(): string
    {
        return 'Bundle Location Convention';
    }

    public function get_description(): string
    {
        return 'Bundles should be in ./rsx/app or ./rsx/app/(module)/ but not deeper';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'convention';
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

        // Check if it's in rsx/app directory
        if (!str_starts_with($relative_path, 'rsx/app/')) {
            return; // Bundle is not in rsx/app, that's ok (could be in rsx/lib etc)
        }

        // Count directory levels after rsx/app/
        $after_app = substr($relative_path, strlen('rsx/app/'));
        $parts = explode('/', $after_app);

        // If more than 2 levels deep (module/feature/file.php), it's a violation
        if (count($parts) > 2) {
            $this->add_violation(
                $file_path,
                0,
                'Bundle class should be in ./rsx/app/ or ./rsx/app/(module)/ but not in feature subdirectories',
                null,
                'Move this bundle to ./rsx/app/ if used globally, or to ./rsx/app/' . $parts[0] . '/ if module-specific',
                'convention'
            );
        }
    }
}