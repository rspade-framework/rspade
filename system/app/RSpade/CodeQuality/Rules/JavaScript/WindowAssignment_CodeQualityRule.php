<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class WindowAssignment_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-WINDOW-01';
    }

    public function get_name(): string
    {
        return 'Window Assignment Check';
    }

    public function get_description(): string
    {
        return "Prohibits window.ClassName = ClassName assignments in ALL code (framework and application). RSX uses simple concatenation, not modules - all classes are automatically global when files are concatenated together, just like JavaScript worked in 1998 before module systems existed.";
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check JavaScript files for window.ClassName = ClassName pattern
     * RSX uses concatenation, not modules - all classes become global automatically
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $lines = $sanitized_data['lines'];

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Skip comments
            $trimmed_line = trim($line);
            if (str_starts_with($trimmed_line, '//') || str_starts_with($trimmed_line, '*')) {
                continue;
            }

            // Check for window.ClassName = ClassName pattern
            // This matches window.Anything = Anything
            if (preg_match('/window\.(\w+)\s*=\s*\1\s*;?/', $line, $matches)) {
                $class_name = $matches[1];

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Manual window assignment detected for class '{$class_name}'. RSX framework automatically makes classes global.",
                    trim($line),
                    "Remove the line 'window.{$class_name} = {$class_name}' - the class is already global via the RSX manifest system.",
                    'high'
                );
            }

            // Also check for generic window assignments that might be problematic
            // This catches window.SomeName = OtherName patterns that may also be incorrect
            if (preg_match('/^\s*window\.([A-Z]\w+)\s*=\s*([A-Z]\w+)\s*;?/', $line, $matches)) {
                // Only flag if it's not the same-name pattern (already handled above)
                if ($matches[1] !== $matches[2]) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Manual window assignment detected. Consider if this is necessary - RSX classes are automatically global.",
                        trim($line),
                        "Verify if this manual window assignment is needed. RSX framework classes don't need window assignment.",
                        'medium'
                    );
                }
            }
        }
    }
}