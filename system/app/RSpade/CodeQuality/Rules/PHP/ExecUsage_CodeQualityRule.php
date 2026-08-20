<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class ExecUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-EXEC-01';
    }

    public function get_name(): string
    {
        return 'exec() Usage Check';
    }

    public function get_description(): string
    {
        return 'Bans exec() function entirely due to unfixable output truncation - use \exec_safe() instead';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Check PHP file for exec() usage
     *
     * exec() has a critical limitation: it reads command output line-by-line into an array,
     * which can cause silent truncation for large outputs or hit memory/buffer limits.
     *
     * This causes catastrophic failures where:
     * - Compilation output gets truncated mid-line
     * - Error messages are incomplete
     * - No error/exception is thrown - the truncation is SILENT
     *
     * exec() is completely banned - use \exec_safe() instead.
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Skip InspectCommand.php - it documents what the checks do
        if (str_contains($file_path, 'InspectCommand.php')) {
            return;
        }

        // Get both original and sanitized content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);

        // Get sanitized content with comments and strings removed
        $sanitized_data = FileSanitizer::sanitize_php($contents);
        $sanitized_lines = $sanitized_data['lines'];

        foreach ($sanitized_lines as $line_num => $sanitized_line) {
            $line_number = $line_num + 1;

            // Skip if the line is empty in sanitized version (was a comment)
            if (trim($sanitized_line) === '') {
                continue;
            }

            // Check for exec( usage - word boundary ensures we don't match "execute(" etc.
            if (preg_match('/\bexec\s*\(/i', $sanitized_line)) {
                $original_line = $original_lines[$line_num] ?? $sanitized_line;

                $violation_message = "CRITICAL: exec() is BANNED - use \\exec_safe() instead

exec() has an unfixable flaw: it reads command output LINE-BY-LINE into an array, which:
- Hits memory/buffer limits on large outputs (>1MB typical)
- Silently truncates output without throwing errors or exceptions
- Causes catastrophic failures in compilation, bundling, and error reporting
- Makes debugging impossible (partial output with no indication of truncation)

Real-world example from this codebase:
- jqhtml compilation truncated at row 4 (mid-line) - output was 4KB instead of 35KB
- No error thrown, no indication of failure
- Took hours to diagnose because the truncation was SILENT

exec() is completely banned with NO EXCEPTIONS. Use \\exec_safe() instead.";

                $resolution = "REQUIRED ACTION - Replace exec() with \\exec_safe():

\\exec_safe() is THE sanctioned framework subprocess wrapper (owner ruling 2026-08-05),
defined in system/app/RSpade/helpers.php. It has exec()'s exact signature plus an
environment channel, and it captures unlimited output with a real exit status.

    \\exec_safe(\$command, \$output, \$return_var);

    if (\$return_var !== 0) {
        throw new \\RuntimeException('Command failed: ' . implode(\"\\n\", \$output));
    }

SIGNATURE:
    \\exec_safe(string \$command, array &\$output = [], int &\$return_var = 0, array \$env = []): string|false

    \$command     shell command line (pipes, redirects, && all work)
    \$output      output lines, stderr merged into stdout
    \$return_var  REAL exit status of the child; -1 if the process could not be started
    \$env         extra environment variables merged over the current environment
    returns      last output line, or false if the process could not be started

PASSING SECRETS:
Never put a credential in the command string - it is visible to every user via `ps`.
Pass it through \$env instead:

    \\exec_safe('mysql -u' . escapeshellarg(\$user) . ' -e \"SELECT 1\"', \$out, \$rc,
               ['MYSQL_PWD' => \$password]);

WHY THIS WORKS:
- Output is captured in full - no line-by-line buffering, no truncation
- The exit status comes from the OS, not from parsing text output
- One wrapper, implemented correctly once, used everywhere

IMPORTANT NOTES:
- Do NOT use proc_open() directly - it is banned (see PHP-PROC-01). \\exec_safe() is
  the single blessed proc_open() site and is annotated as such.
- Do NOT hand-roll shell_exec() + 'echo \$?' to recover an exit code - that seam is
  exactly what \\exec_safe() exists to remove.
- \\exec_safe() is the ONLY approved way to execute shell commands";

                $this->add_violation(
                    $file_path,
                    $line_number,
                    $violation_message,
                    trim($original_line),
                    $resolution,
                    'critical'
                );
            }
        }
    }
}
