<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class SubclassCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-SUBCLASS-01';
    }

    public function get_name(): string
    {
        return 'php_is_subclass_of() Usage Check';
    }

    public function get_description(): string
    {
        return 'Enforces using Manifest for inheritance checks instead of runtime reflection';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check PHP file for php_is_subclass_of() usage
     * Should use Manifest::php_get_extending() or similar instead
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }

        // Skip CodeQuality and SchemaQuality directories - they check rule classes exist
        if (str_contains($file_path, '/CodeQuality/') || str_contains($file_path, '/SchemaQuality/')) {
            return;
        }

        // Skip InspectCommand.php - it documents what the checks do
        if (str_contains($file_path, 'InspectCommand.php')) {
            return;
        }

        // Skip Manifest.php - it may need to check inheritance during building
        if (str_contains($file_path, 'Manifest.php')) {
            return;
        }

        // Get both original and sanitized content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);

        // Get sanitized content with comments removed
        $sanitized_data = FileSanitizer::sanitize_php($contents);
        $sanitized_lines = $sanitized_data['lines'];

        foreach ($sanitized_lines as $line_num => $sanitized_line) {
            $line_number = $line_num + 1;

            // Skip if the line is empty in sanitized version (was a comment)
            if (trim($sanitized_line) === '') {
                continue;
            }

            // Check for php_is_subclass_of( usage (but not Manifest::is_subclass_of)
            if (preg_match('/\bis_subclass_of\s*\(/i', $sanitized_line) && !preg_match('/Manifest\s*::\s*is_subclass_of\s*\(/i', $sanitized_line)) {
                // Check if it's part of a sanity check (has Exception or shouldnt_happen within 2 lines)
                $is_sanity_check = false;

                // Check current line and next 2 lines for Exception( or shouldnt_happen(
                for ($i = 0; $i <= 2; $i++) {
                    $check_line_num = $line_num + $i;

                    // Check sanitized line for exception patterns
                    if (isset($sanitized_lines[$check_line_num])) {
                        $check_line = $sanitized_lines[$check_line_num];
                        if (preg_match('/\b(throw\s+new\s+)?[A-Za-z]*Exception\s*\(/i', $check_line) ||
                            preg_match('/\bshouldnt_happen\s*\(/i', $check_line)) {
                            $is_sanity_check = true;
                            break;
                        }
                    }
                }

                if (!$is_sanity_check) {
                    $original_line = $original_lines[$line_num] ?? $sanitized_line;

                    $this->add_violation(
                        $file_path,
                        $line_number,
                        'is_subclass_of() is not allowed. Use Manifest for inheritance checks.',
                        trim($original_line),
                        "Use Manifest methods for inheritance checks:\n\n" .
                        "1. To check if a class extends another:\n" .
                        "   - Use: \$metadata = Manifest::php_get_metadata_by_class(\$class);\n" .
                        "   - Check: \$metadata['extends'] === 'BaseClass'\n\n" .
                        "2. To find all classes extending a base:\n" .
                        "   - Use: Manifest::php_get_extending('BaseClass')\n\n" .
                        "3. For sanity checks (if this truly shouldn't happen):\n" .
                        "   - Must be followed by exception within 2 lines\n" .
                        "   - Use: if (!is_subclass_of(\$class, \$base)) { shouldnt_happen('...'); }\n\n" .
                        'The Manifest contains all inheritance information at build time. ' .
                        'Runtime reflection is unnecessary and slower.',
                        'high'
                    );
                }
            }
        }
    }
}
