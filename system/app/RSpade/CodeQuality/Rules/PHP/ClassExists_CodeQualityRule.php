<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class ClassExists_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-CLASS-01';
    }

    public function get_name(): string
    {
        return 'class_exists() Usage Check';
    }

    public function get_description(): string
    {
        return 'Enforces predictable runtime - no conditional class checking except for sanity checks';
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
     * Check PHP file for class_exists() usage
     * Allows exception if followed by Exception( or shouldnt_happen( within 2 lines
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

        // Skip Autoloader.php - it needs to check class existence as part of its core functionality
        if (str_contains($file_path, 'Autoloader.php')) {
            return;
        }

        // Skip Manifest.php - it needs to check if classes are loaded during manifest building
        if (str_contains($file_path, 'Manifest.php')) {
            return;
        }

        // Skip ManifestDumpCommand.php - it checks for optional dependencies
        if (str_contains($file_path, 'ManifestDumpCommand.php')) {
            return;
        }

        // Skip AttributeProcessor.php - attributes may not have backing classes per CLAUDE.md
        if (str_contains($file_path, 'AttributeProcessor.php')) {
            return;
        }

        // Skip Model_ManifestSupport.php - runs during manifest building when models may not be loadable
        if (str_contains($file_path, 'Model_ManifestSupport.php')) {
            return;
        }

        // Skip BundleCompiler.php - checks for bundle module classes
        if (str_contains($file_path, 'BundleCompiler.php')) {
            return;
        }

        // Skip RsxCache.php - it checks whether the Redis extension exists in IDE context.
        // (RsxLocks.php used to need the same exemption; it no longer touches Redis at all.)
        if (str_contains($file_path, 'RsxCache.php')) {
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

            // Check for class_exists( usage
            if (preg_match('/\bclass_exists\s*\(/i', $sanitized_line)) {
                // Check if it's part of a sanity check (has Exception or shouldnt_happen within 2 lines)
                $is_sanity_check = false;

                // Check current line and next 2 lines for Exception( or shouldnt_happen(
                // Also check original lines to see if there's a comment followed by exception
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

                    // Also check original line to see if there's a comment explaining it's a sanity check
                    if (isset($original_lines[$check_line_num])) {
                        $orig_line = $original_lines[$check_line_num];
                        // Check if line contains comment indicating sanity check
                        if (preg_match('/\/\/.*sanity check/i', $orig_line) ||
                            preg_match('/\/\/.*should(n\'t| not) happen/i', $orig_line)) {
                            // Check if next line has exception
                            if (isset($sanitized_lines[$check_line_num + 1])) {
                                $next_line = $sanitized_lines[$check_line_num + 1];
                                if (preg_match('/\b(throw\s+new\s+)?[A-Za-z]*Exception\s*\(/i', $next_line) ||
                                    preg_match('/\bshouldnt_happen\s*\(/i', $next_line)) {
                                    $is_sanity_check = true;
                                    break;
                                }
                            }
                        }
                    }
                }

                if (!$is_sanity_check) {
                    $original_line = $original_lines[$line_num] ?? $sanitized_line;

                    $this->add_violation(
                        $file_path,
                        $line_number,
                        'class_exists() is not allowed. The runtime environment is strict and predictable - all expected classes will exist.',
                        trim($original_line),
                        "Analyze the usage and apply the appropriate fix:\n\n" .
                        "1. SANITY CHECK (most common): If verifying a class that should exist:\n" .
                        "   - Must be followed by exception within 2 lines\n" .
                        "   - Use: if (!class_exists(\$class)) { shouldnt_happen('Class should exist'); }\n" .
                        "   - Or: if (!class_exists(\$class)) { throw new \\Exception('...'); }\n\n" .
                        "2. DEFENSIVE CODING: If checking before using a class:\n" .
                        "   - Remove the check entirely - the class will exist or PHP will fail loudly\n" .
                        "   - Trust the autoloader and framework\n\n" .
                        "3. DISCOVERY/REFLECTION: If dynamically finding classes:\n" .
                        "   - Use: Manifest::php_find_class(\$simple_name) for discovery\n" .
                        "   - Use: Manifest::php_get_extending(\$base_class) for finding subclasses\n\n" .
                        "4. CONDITIONAL BEHAVIOR: If doing different things based on class availability:\n" .
                        "   - Refactor to not depend on class existence\n" .
                        "   - Use configuration or feature flags instead\n\n" .
                        'The framework guarantees all expected classes are available. Defensive class_exists() checks ' .
                        'hide errors that should fail loudly during development.',
                        'high'
                    );
                }
            }
        }
    }
}
