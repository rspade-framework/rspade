<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class JQuerySubmitUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-JQUERY-SUBMIT-01';
    }

    public function get_name(): string
    {
        return 'jQuery .submit() Usage Check';
    }

    public function get_description(): string
    {
        return "Detects deprecated jQuery .submit() usage and recommends .trigger('submit') or .requestSubmit()";
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'low';
    }

    /**
     * Check JavaScript file for .submit() usage
     * Recommends .trigger('submit') or .requestSubmit() instead
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

        // Remove comments and strings to avoid false positives
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $sanitized = $sanitized_data['content'];

        // Pattern matches:
        // $variable.submit()
        // $(selector).submit()
        // that.$anything.submit()
        // this.$anything.submit()
        $pattern = '/(\$[a-zA-Z_][a-zA-Z0-9_]*|(?:this|that)\.\$[a-zA-Z0-9_.]+|\$\([^)]+\))\.submit\s*\(/';

        preg_match_all($pattern, $sanitized, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($matches[0] as $match) {
            $offset = $match[1];
            $matched_text = $match[0];

            // Find line number from offset
            $line_number = 1;
            $current_offset = 0;
            foreach ($lines as $index => $line) {
                $line_length = strlen($line) + 1; // +1 for newline
                if ($current_offset + $line_length > $offset) {
                    $line_number = $index + 1;
                    break;
                }
                $current_offset += $line_length;
            }

            $code_snippet = trim($lines[$line_number - 1] ?? '');

            $this->add_violation(
                $file_path,
                $line_number,
                "Use .trigger('submit') or .requestSubmit() instead of deprecated .submit()",
                $code_snippet,
                "Replace .submit() with .trigger('submit') for event triggering or .requestSubmit() for actual form submission with validation",
                'low'
            );
        }
    }
}
