<?php

namespace App\RSpade\CodeQuality\Rules\Scss;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * DataSidSelectorRule - Prohibits targeting [data-sid] in SCSS/CSS selectors
 *
 * PHILOSOPHY: $sid attributes are internal implementation details of jqhtml components.
 * They exist for programmatic DOM access via this.$sid('name'), not for styling.
 * Styling should use semantic BEM child classes instead.
 */
class DataSidSelector_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'SCSS-SID-01';
    }

    public function get_name(): string
    {
        return 'No data-sid CSS Selectors';
    }

    public function get_description(): string
    {
        return 'Prohibits targeting [data-sid] attributes in CSS selectors. Use BEM child classes instead.';
    }

    public function get_file_patterns(): array
    {
        return ['*.scss', '*.css'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Run at manifest-time for immediate feedback
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Check SCSS/CSS files for [data-sid] selectors
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip minified files
        if (str_ends_with($file_path, '.min.css') || str_ends_with($file_path, '.min.scss')) {
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($lines as $line_num => $line) {
            $actual_line_number = $line_num + 1;
            $trimmed = trim($line);

            // Skip comments
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // Check for exception comment on same line or previous line
            if (str_contains($line, '@' . $this->get_id() . '-EXCEPTION')) {
                continue;
            }
            if ($line_num > 0 && str_contains($lines[$line_num - 1], '@' . $this->get_id() . '-EXCEPTION')) {
                continue;
            }

            // Look for [data-sid= pattern (with or without quotes)
            if (preg_match('/\[data-sid\s*[=~\|\^\$\*]/', $line, $matches)) {
                // Extract the selector context for better error message
                $code_snippet = trim($line);
                if (strlen($code_snippet) > 80) {
                    $code_snippet = substr($code_snippet, 0, 77) . '...';
                }

                // Try to extract the sid name for the suggestion
                $sid_name = '';
                if (preg_match('/\[data-sid\s*=\s*["\']?([^"\'\]]+)/', $line, $sid_match)) {
                    $sid_name = $sid_match[1];
                }

                $suggestion = $this->get_suggestion($sid_name);

                $this->add_violation(
                    $file_path,
                    $actual_line_number,
                    "Targeting [data-sid] in CSS is PROHIBITED",
                    $code_snippet,
                    $suggestion,
                    'critical'
                );
            }
        }
    }

    /**
     * Generate the fix suggestion with example
     */
    private function get_suggestion(string $sid_name): string
    {
        $example_class = $sid_name ? "__$sid_name" : '__element_name';

        return <<<SUGGESTION
\$sid attributes are internal implementation details of jqhtml components.
They exist for programmatic DOM access (this.\$sid('name')), NOT for styling.

WHY THIS IS WRONG:
- \$sid is an internal identifier, not a semantic class name
- Component internals may change without notice
- Breaks encapsulation - styles reach into component implementation

SOLUTION: Use BEM child classes instead.

Before (WRONG):
    [data-sid="$sid_name"] {
        background: transparent;
    }

After (CORRECT):
    &$example_class {
        background: transparent;
    }

Then in the jqhtml template, add the class to the element:
    <button \$sid="$sid_name" class="Component_Name$example_class">

BEM child classes are semantic, documented, and part of the component's public API.
\$sid attributes are internal implementation that should never leak into CSS.
SUGGESTION;
    }
}
