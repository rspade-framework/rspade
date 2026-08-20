<?php

namespace App\RSpade\CodeQuality\Rules\Blade;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class BladeExtends_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'BLADE-EXTENDS-01';
    }

    public function get_name(): string
    {
        return 'Blade @extends Syntax Check';
    }

    public function get_description(): string
    {
        return "Detects incorrect @extends('rsx:: usage - should use @rsx_extends instead";
    }

    public function get_file_patterns(): array
    {
        return ['*.blade.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check blade files for incorrect @extends('rsx:: usage
     * The correct syntax is @rsx_extends('layout_name'), not @extends('rsx::...)
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in rsx/ directory
        if (!str_contains($file_path, '/rsx/')) {
            return;
        }

        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Check for @extends('rsx:: pattern (with single or double quotes)
            if (preg_match('/@extends\s*\(\s*[\'"]rsx::([^\'"]+)[\'"]/', $line, $matches)) {
                $layout_reference = $matches[1] ?? '';

                // Build suggestion
                $suggestion = "The @extends directive with 'rsx::' namespace is incorrect.\n";
                $suggestion .= "Use the @rsx_extends directive instead:\n";
                $suggestion .= "  Replace: @extends('rsx::{$layout_reference}')\n";
                $suggestion .= "  With: @rsx_extends('{$layout_reference}')\n\n";
                $suggestion .= "The @rsx_extends directive uses the RSX ID system to locate layouts ";
                $suggestion .= "by their RSX ID rather than file paths.";

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Incorrect use of @extends('rsx::...'). Use @rsx_extends instead.",
                    trim($line),
                    $suggestion,
                    'high'
                );
            }
        }
    }
}