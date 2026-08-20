<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class BundleIncludePath_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'CONV-BUNDLE-02';
    }

    public function get_name(): string
    {
        return 'Bundle Include Path Convention';
    }

    public function get_description(): string
    {
        return 'Bundles should include __DIR__ or their relative path in their includes';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'low';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if not a PHP class file
        if (!isset($metadata['class'])) {
            return;
        }

        // Only check files in ./rsx/ directory, trust framework authors for app/RSpade
        if (!str_starts_with($file_path, base_path() . '/rsx/')) {
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

        // Check if bundle includes its own directory in the include array
        // Look for the define() method
        if (!preg_match('/public\s+static\s+function\s+define\s*\(\s*\)\s*:\s*array\s*\{(.*?)\}/s', $contents, $matches)) {
            return; // Can't find define method
        }

        $define_content = $matches[1];

        // Look for include array
        if (!preg_match("/['\"]include['\"]\s*=>\s*\[(.*?)\]/s", $define_content, $include_matches)) {
            return; // No include array found
        }

        $include_content = $include_matches[1];

        // Check if it references __DIR__ or the directory path
        $has_dir_reference = false;

        // Check for __DIR__ usage
        if (str_contains($include_content, '__DIR__')) {
            $has_dir_reference = true;
        }

        // Check for the relative directory path (e.g., 'rsx/app/demo')
        if (str_contains($include_content, "'" . $dir_path) ||
            str_contains($include_content, '"' . $dir_path)) {
            $has_dir_reference = true;
        }

        if (!$has_dir_reference) {
            // Get bundle class name for better message
            $class_name = $metadata['class'] ?? basename($file_path, '.php');

            $this->add_violation(
                $file_path,
                0,
                "Bundle {$class_name} should include its own directory in the 'include' array",
                null,
                "Add '__DIR__' or '{$dir_path}' to the bundle's include array to ensure all module files are included.\n" .
                "Note: This is a convention rather than a hard requirement. If your bundle intentionally doesn't need " .
                "to include its own directory, add the following comment to grant an exception: @CONV-BUNDLE-02-EXCEPTION",
                $this->get_default_severity()
            );
        }
    }
}