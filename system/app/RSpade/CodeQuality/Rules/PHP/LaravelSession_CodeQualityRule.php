<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class LaravelSession_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-SESSION-01';
    }

    public function get_name(): string
    {
        return 'Laravel Session Usage Check';
    }

    public function get_description(): string
    {
        return 'Prohibits Laravel session() usage in RSpade applications - enforces RSpade Session:: methods';
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
     * Check for session() calls in RSX directory and suggest RSpade alternatives
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in rsx/ directory
        if (!str_contains($file_path, '/rsx/') && !str_starts_with($file_path, 'rsx/')) {
            return;
        }

        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Skip comments and strings to avoid false positives
            $trimmed_line = trim($line);
            if (str_starts_with($trimmed_line, '//') ||
                str_starts_with($trimmed_line, '#') ||
                str_starts_with($trimmed_line, '*') ||
                str_starts_with($trimmed_line, '/*')) {
                continue;
            }

            // Skip lines that contain session() only in strings or comments
            $line_without_strings = preg_replace('/["\'].*?["\']/', '', $line);
            $line_without_comments = preg_replace('/\/\/.*$/', '', $line_without_strings);
            $line_without_comments = preg_replace('/\/\*.*?\*\//', '', $line_without_comments);

            // Look for session() function calls in the cleaned line
            if (preg_match('/\bsession\s*\(/', $line_without_comments)) {
                $code_snippet = trim($line);

                // Determine specific suggestion based on usage pattern
                $suggestion = $this->get_specific_suggestion($line);

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Laravel session() usage is not allowed in RSX applications. Use RSpade Session:: methods instead.",
                    $code_snippet,
                    $suggestion,
                    $this->get_default_severity()
                );
            }
        }
    }

    /**
     * Get specific replacement suggestion based on the session() usage pattern
     */
    private function get_specific_suggestion(string $line): string
    {
        // Check for flash usage specifically
        if (preg_match('/session\(\)\s*->\s*flash\s*\(/', $line)) {
            return "Replace session()->flash() with RSpade flash alert methods:\n" .
                   "- Rsx::flash_success(\$message) - for success messages\n" .
                   "- Rsx::flash_error(\$message) - for error messages\n" .
                   "- Rsx::flash_warning(\$message) - for warning messages\n" .
                   "- Rsx::flash_alert(\$message, \$class) - for custom alerts";
        }

        // Check for common session operations
        if (preg_match('/session\(\)\s*->\s*get\s*\(/', $line)) {
            return "Replace session()->get() with RSpade Session methods:\n" .
                   "- Session::get_user() - get current user\n" .
                   "- Session::get_site() - get current site\n" .
                   "- Session::get_user_id() - get current user ID\n" .
                   "- Session::get_site_id() - get current site ID";
        }

        if (preg_match('/session\(\)\s*->\s*(put|set)\s*\(/', $line)) {
            return "Replace session()->put() with RSpade Session methods:\n" .
                   "- Session::set_login_user_id(\$login_user_id) - set current login user\n" .
                   "- Session::set_site_id(\$site_id) - set current site\n" .
                   "- For other session data, consider if it should be stored in the database instead";
        }

        if (preg_match('/session\(\)\s*->\s*forget\s*\(/', $line)) {
            return "Replace session()->forget() with appropriate RSpade Session methods:\n" .
                   "- Session::logout() - for user logout\n" .
                   "- Session::clear_user() - to clear user data\n" .
                   "- Session::clear_site() - to clear site data";
        }

        // Generic suggestion for other session() usage
        return "Replace session() with RSpade Session methods:\n" .
               "- Session::get_login_user() - get current login user (global)\n" .
               "- Session::get_login_user_id() - get current login user ID\n" .
               "- Session::get_user() - get current site-specific user\n" .
               "- Session::get_user_id() - get current site-specific user ID\n" .
               "- Session::get_site() - get current site\n" .
               "- Session::get_site_id() - get current site ID\n" .
               "- Session::set_login_user_id(\$login_user_id) - set current login user\n" .
               "- Session::set_site_id(\$site_id) - set current site\n" .
               "- Rsx::flash_success/error/warning(\$message) - for flash messages";
    }
}