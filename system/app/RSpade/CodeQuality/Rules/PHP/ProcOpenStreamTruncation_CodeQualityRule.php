<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class ProcOpenStreamTruncation_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-PROC-01';
    }

    public function get_name(): string
    {
        return 'proc_open() Usage Banned';
    }

    public function get_description(): string
    {
        return 'Bans proc_open() usage due to unfixable pipe buffer truncation bugs - use \exec_safe() instead';
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
     * Check PHP file for ANY proc_open() usage
     *
     * BANNED: proc_open() is completely banned due to unfixable pipe buffer race conditions
     * that cause silent data truncation on large outputs (35KB+).
     *
     * After 10+ attempts to fix this using various patterns (feof() loops, stream_set_blocking,
     * etc.), we've determined proc_open() is fundamentally unreliable for our use cases.
     *
     * We never need asynchronous operations - all our use cases are synchronous command execution.
     *
     * ONE blessed exception exists: \exec_safe() in system/app/RSpade/helpers.php (owner ruling
     * 2026-08-05), which is the framework's single subprocess wrapper and carries a file-level
     * exception annotation for this rule. All other code calls \exec_safe(); the ban stands.
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

        // Get both original and sanitized content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);

        // Get sanitized content with comments and strings removed
        $sanitized_data = FileSanitizer::sanitize_php($contents);
        $sanitized_code = $sanitized_data['content'];

        // BLANKET BAN: Check if code contains ANY proc_open() usage
        if (!preg_match('/\bproc_open\s*\(/i', $sanitized_code)) {
            return;  // No proc_open usage, all clear
        }

        // VIOLATION: Found proc_open() usage - this is banned
        // Find the line number where proc_open appears
        $sanitized_lines = $sanitized_data['lines'];
        foreach ($sanitized_lines as $line_num => $sanitized_line) {
            if (preg_match('/\bproc_open\s*\(/i', $sanitized_line)) {
                $line_number = $line_num + 1;
                $original_line = $original_lines[$line_num] ?? $sanitized_line;

                $this->add_violation(
                    $file_path,
                    $line_number,
                    $this->get_violation_message(),
                    trim($original_line),
                    $this->get_resolution_message(),
                    'critical'
                );

                return; // Only report first occurrence
            }
        }
    }

    private function get_violation_message(): string
    {
        return "CRITICAL: proc_open() is BANNED in this codebase

After 10+ attempts to fix pipe buffer truncation bugs, proc_open() is now completely banned.

WHY THIS IS BANNED:
- Unfixable race conditions with feof() cause silent data loss on large outputs (35KB+)
- Even 'correct' patterns using while (!feof(\$pipes[...])) still have race conditions
- We never need asynchronous operations - all our use cases are synchronous

REAL-WORLD INCIDENTS:
1. JqhtmlWebpackCompiler.php - Compiled template truncated at 8KB, breaking JavaScript
2. Multiple attempts to fix with different buffering strategies all failed
3. Pattern matches known PHP bug reports going back years

THE FUNDAMENTAL PROBLEM:
- Child process writes to pipe
- Parent checks feof() but pipe hasn't been marked EOF yet
- fread() returns empty string
- Loop continues, feof() now returns true prematurely
- Remaining data silently lost

This is NOT a coding error - it's a race condition in a hand-rolled proc_open() drain loop.";
    }

    private function get_resolution_message(): string
    {
        return "REQUIRED ACTION - Replace proc_open() with \\exec_safe():

\\exec_safe() is THE sanctioned framework subprocess wrapper (owner ruling 2026-08-05),
defined in system/app/RSpade/helpers.php. It is the ONE place in the codebase allowed to
call proc_open(), and it is annotated as such. Everything else goes through it.

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

WHY THE WRAPPER IS SAFE WHERE YOUR CODE IS NOT:
- stderr is merged into stdout by the shell, so there is exactly ONE pipe - two
  concurrently-drained pipes are what deadlock
- the pipe is drained by a single blocking stream_get_contents() read to EOF - there
  is no feof()/fread() loop to lose the tail of the output
- the pipe is fully read BEFORE proc_close(), so the child never blocks on a full pipe
- proc_close() returns the real exit status, so no exit code has to be parsed out of text

DO NOT hand-roll another proc_open() with 'better' buffering. That has been tried 10+
times and it does not work. DO NOT hand-roll shell_exec() plus an 'echo \$?' trick to
recover an exit status either - \\exec_safe() exists to remove that seam.
If \\exec_safe() genuinely cannot express what you need, raise it - do not add a second
wrapper.";
    }
}
