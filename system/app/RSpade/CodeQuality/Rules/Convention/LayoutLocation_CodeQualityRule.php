<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class LayoutLocation_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'CONV-LAYOUT-01';
    }

    public function get_name(): string
    {
        return 'Layout File Location Convention';
    }

    public function get_description(): string
    {
        return 'Layout blade files in ./rsx/ must be within a module directory (./rsx/app/(module)/ or deeper)';
    }

    public function get_file_patterns(): array
    {
        return ['*.blade.php'];
    }

    public function get_default_severity(): string
    {
        return 'convention';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Check if filename ends with layout.blade.php
        if (!preg_match('/_layout\.blade\.php$/', $file_path)) {
            return;
        }

        // Get relative path from base
        $relative_path = str_replace(base_path() . '/', '', $file_path);

        // Only check layouts in rsx/ directory (not app/RSpade)
        if (!str_starts_with($relative_path, 'rsx/')) {
            return;
        }

        // Check if it's in rsx/app directory
        if (!str_starts_with($relative_path, 'rsx/app/')) {
            $this->add_violation(
                $file_path,
                0,
                'Layout file must be within ./rsx/app/ directory',
                null,
                'Move this layout to ./rsx/app/(module)/ or a subdirectory within a module',
                'convention'
            );
            return;
        }

        // Count directory levels after rsx/app/
        $after_app = substr($relative_path, strlen('rsx/app/'));
        $parts = explode('/', $after_app);

        // Layout must be at least 2 levels deep: module/file.php
        if (count($parts) < 2) {
            $this->add_violation(
                $file_path,
                0,
                'Layout file must be within a module directory, not directly in ./rsx/app/',
                null,
                'Move this layout to ./rsx/app/(module)/ or a subdirectory within a module',
                'convention'
            );
        }
    }
}