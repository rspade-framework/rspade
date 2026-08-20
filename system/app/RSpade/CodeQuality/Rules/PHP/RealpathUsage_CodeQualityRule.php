<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class RealpathUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-REALPATH-01';
    }

    public function get_name(): string
    {
        return 'Realpath Usage Check';
    }

    public function get_description(): string
    {
        return 'Prohibits realpath() usage in app/RSpade - enforces rsxrealpath() for symlink-aware path normalization';
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
     * Check for realpath() usage in app/RSpade directory
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in app/RSpade directory
        if (!str_contains($file_path, 'app/RSpade/')) {
            return;
        }

        // Skip vendor and node_modules
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip this rule file itself
        if (str_contains($file_path, 'RealpathUsage_CodeQualityRule.php')) {
            return;
        }

        // Read raw file content for exception checking (not sanitized)
        $raw_contents = file_get_contents($file_path);

        // If file has @REALPATH-EXCEPTION marker anywhere, skip entire file
        if (str_contains($raw_contents, '@REALPATH-EXCEPTION')) {
            return;
        }

        $lines = explode("\n", $contents);

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $line_number = $i + 1;

            // Skip comment lines first (but not inline realpath in code)
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // Look for realpath( usage (function call, not in string)
            if (preg_match('/\brealpath\s*\(/', $line)) {
                // Get code snippet (current line and surrounding context)
                $snippet_start = max(0, $i - 2);
                $snippet_end = min(count($lines) - 1, $i + 2);
                $snippet_lines = array_slice($lines, $snippet_start, $snippet_end - $snippet_start + 1);
                $snippet = implode("\n", $snippet_lines);

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use rsxrealpath() instead of realpath()

RSpade uses symlinks for the /rsx/ directory mapping:
- /var/www/html/system/rsx -> /var/www/html/rsx (symlink)

realpath() resolves symlinks to their physical paths, which breaks path-based comparisons and deduplication when files are accessed via different symlink paths.

rsxrealpath() normalizes paths without resolving symlinks, ensuring consistent path handling throughout the framework.",
                    $snippet,
                    "Replace: realpath(\$path)
With: rsxrealpath(\$path)

If you need symlink resolution for security (path traversal prevention), add:
// @REALPATH-EXCEPTION - Security: path traversal prevention
\$real_path = realpath(\$path);",
                    'high'
                );
            }
        }
    }
}
