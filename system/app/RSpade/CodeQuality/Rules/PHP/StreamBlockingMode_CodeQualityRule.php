<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class StreamBlockingMode_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-STREAM-01';
    }

    public function get_name(): string
    {
        return 'Stream Blocking Mode Required';
    }

    public function get_description(): string
    {
        return 'Streams from fsockopen() and network sockets must set blocking mode before reading to prevent data truncation (note: proc_open() is banned - see PHP-PROC-01)';
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
     * Check PHP file for stream operations without explicit blocking mode
     * and dangerous read patterns that truncate data
     *
     * PHP streams from fsockopen(), popen(), and stream_socket_client()
     * default to non-blocking mode in some contexts, which causes incomplete reads
     * and silent data truncation.
     *
     * Note: proc_open() is completely banned by PHP-PROC-01 rule, so it's excluded here.
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

        // Get sanitized content with comments and strings removed
        $sanitized_data = FileSanitizer::sanitize_php($contents);
        $sanitized_code = $sanitized_data['content'];

        // Check for stream sources (proc_open excluded - it's banned by PHP-PROC-01)
        $stream_sources_pattern = '/\b(fsockopen|stream_socket_client|popen)\s*\(/i';
        if (!preg_match($stream_sources_pattern, $sanitized_code)) {
            return;  // No stream sources, skip
        }

        // Check for stream read operations
        $stream_reads_pattern = '/\b(fread|fgets|stream_get_contents|stream_copy_to_stream)\s*\(/i';
        if (!preg_match($stream_reads_pattern, $sanitized_code)) {
            return;  // No stream reads, skip
        }

        $original_lines = explode("\n", file_get_contents($file_path));
        $sanitized_lines = $sanitized_data['lines'];

        // VIOLATION 1: Missing stream_set_blocking()
        if (!preg_match('/\bstream_set_blocking\s*\(/i', $sanitized_code)) {
            foreach ($sanitized_lines as $line_num => $sanitized_line) {
                if (preg_match($stream_sources_pattern, $sanitized_line)) {
                    $this->add_violation(
                        $file_path,
                        $line_num + 1,
                        $this->get_missing_blocking_message(),
                        trim($original_lines[$line_num] ?? $sanitized_line),
                        $this->get_missing_blocking_resolution(),
                        'high'
                    );
                    return; // Only report first occurrence
                }
            }
            return;
        }

        // VIOLATION 2: Dangerous read pattern - breaking on false in fread loop
        // Pattern: while (!feof($stream)) { $chunk = fread(...); if ($chunk === false) { break; } ... }
        // This breaks prematurely and truncates data because fread can return false temporarily
        if (preg_match('/while\s*\([^)]*!feof[^)]*\)[^{]*\{[^}]*fread[^}]*===\s*false[^}]*break/is', $sanitized_code)) {
            foreach ($sanitized_lines as $line_num => $sanitized_line) {
                if (preg_match('/if\s*\([^)]*===\s*false[^)]*\)\s*\{[^}]*break/', $sanitized_line)) {
                    $this->add_violation(
                        $file_path,
                        $line_num + 1,
                        $this->get_dangerous_break_message(),
                        trim($original_lines[$line_num] ?? $sanitized_line),
                        $this->get_dangerous_break_resolution(),
                        'critical'
                    );
                    return;
                }
            }
        }

        // VIOLATION 3: Using stream_get_contents() in blocking mode without checking for false
        // stream_get_contents() has 8192 byte default buffer and will truncate silently
        if (preg_match('/stream_get_contents\s*\([^)]+\)\s*;/i', $sanitized_code) &&
            preg_match('/stream_set_blocking\s*\([^)]+,\s*true\s*\)/i', $sanitized_code)) {
            foreach ($sanitized_lines as $line_num => $sanitized_line) {
                if (preg_match('/stream_get_contents\s*\(/i', $sanitized_line)) {
                    // Check if it's NOT in a proper loop
                    $context_start = max(0, $line_num - 3);
                    $context_end = min(count($sanitized_lines) - 1, $line_num + 3);
                    $context = implode("\n", array_slice($sanitized_lines, $context_start, $context_end - $context_start + 1));

                    // If not in a while loop that handles chunks, flag it
                    if (!preg_match('/while\s*\([^)]*!feof|while\s*\([^)]*!\$.*_done/', $context)) {
                        $this->add_violation(
                            $file_path,
                            $line_num + 1,
                            $this->get_unsafe_stream_get_contents_message(),
                            trim($original_lines[$line_num] ?? $sanitized_line),
                            $this->get_unsafe_stream_get_contents_resolution(),
                            'high'
                        );
                        return;
                    }
                }
            }
        }
    }

    private function get_missing_blocking_message(): string
    {
        return "Stream operations without explicit blocking mode

File uses stream sources (fsockopen/popen/stream_socket_client) with
read operations but does not call stream_set_blocking().

Note: proc_open() is banned entirely - see PHP-PROC-01 rule.

PHP streams default to non-blocking mode in some contexts, which causes:
- Incomplete reads with partial data
- Silent data truncation (no errors or warnings)
- Race conditions depending on when data arrives
- Data integrity issues for network transfers and command output";
    }

    private function get_missing_blocking_resolution(): string
    {
        return "REQUIRED ACTION:

Add stream_set_blocking(\$stream, true) before reading AND use proper read loop:

CORRECT PATTERN (for network sockets):
    \$socket = fsockopen('example.com', 80);

    stream_set_blocking(\$socket, true);

    // Read until EOF
    \$output = '';
    while (!feof(\$socket)) {
        \$chunk = fread(\$socket, 8192);
        if (\$chunk !== false) {
            \$output .= \$chunk;
        }
    }

    fclose(\$socket);

Note: If you're using proc_open(), replace it with file redirection (see PHP-PROC-01).";
    }

    private function get_dangerous_break_message(): string
    {
        return "CRITICAL: Dangerous stream read pattern will truncate data

This fread() loop breaks on false, which causes data truncation because fread()
can return false TEMPORARILY even when more data is available.

Bug impact:
- Large outputs silently truncated at ~8KB
- No error thrown, appears successful
- Production data loss and corruption
- Intermittent failures depending on timing";
    }

    private function get_dangerous_break_resolution(): string
    {
        return "REQUIRED FIX:

Use feof() as the loop condition - do NOT check it inside the loop.

WRONG PATTERN (race condition, will truncate):
    \$done = false;
    while (!\$done) {
        \$chunk = fread(\$stream, 8192);
        if (\$chunk === '') {
            if (feof(\$stream)) { // [ERROR] Checked AFTER empty read
                \$done = true;
            }
        } else {
            \$output .= \$chunk;
        }
    }

CORRECT PATTERN:
    \$output = '';
    while (!feof(\$stream)) {
        \$chunk = fread(\$stream, 8192);
        if (\$chunk !== false) {
            \$output .= \$chunk;
        }
    }";
    }

    private function get_unsafe_stream_get_contents_message(): string
    {
        return "Unsafe stream_get_contents() usage may truncate large outputs

stream_get_contents() uses 8192 byte default buffer. For large outputs (>8KB),
data will be silently truncated.

Even with stream_set_blocking(true), stream_get_contents() may not read all data
in one call for large outputs.";
    }

    private function get_unsafe_stream_get_contents_resolution(): string
    {
        return "Use chunked read loop instead of stream_get_contents():

UNSAFE:
    stream_set_blocking(\$pipe, true);
    \$output = stream_get_contents(\$pipe); // [ERROR] May truncate >8KB

SAFE:
    stream_set_blocking(\$pipe, true);
    \$output = '';
    while (!feof(\$pipe)) {
        \$chunk = fread(\$pipe, 8192);
        if (\$chunk !== false) {
            \$output .= \$chunk;
        }
    }";
    }
}
