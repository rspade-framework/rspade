<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Detects server-side date/datetime formatting in model fetch() methods
 *
 * Dates and datetimes should be passed to the client as-is (ISO strings for datetimes,
 * YYYY-MM-DD for dates) and formatted client-side using Rsx_Time and Rsx_Date JavaScript
 * classes. Server-side formatting creates implicit API contracts and prevents client-side
 * locale customization.
 */
class ModelFetchDateFormatting_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'MODEL-FETCH-DATE-01';
    }

    /**
     * Get the rule name
     */
    public function get_name(): string
    {
        return 'Model Fetch Date Formatting';
    }

    /**
     * Get the rule description
     */
    public function get_description(): string
    {
        return 'Detects server-side date/datetime formatting in model fetch() methods';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Get default severity
     */
    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Whether this rule runs during manifest scan
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Run the rule check
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check PHP files
        if (!str_ends_with($file_path, '.php')) {
            return;
        }

        $class_name = $metadata['class'] ?? null;

        if (!$class_name) {
            return;
        }

        // Skip base abstract classes
        if ($class_name === 'Rsx_Model_Abstract' || $class_name === 'Rsx_Site_Model_Abstract') {
            return;
        }

        // Check if this is a model (extends Rsx_Model_Abstract in the inheritance chain)
        if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            return;
        }

        // Check if fetch() method exists (can be in static_methods or public_static_methods)
        $has_fetch = isset($metadata['static_methods']['fetch'])
            || isset($metadata['public_static_methods']['fetch']);

        if (!$has_fetch) {
            return;
        }

        $lines = explode("\n", $contents);

        // Extract fetch method body
        $fetch_body = $this->_extract_fetch_body($lines);
        if (empty($fetch_body)) {
            return;
        }

        // Patterns that indicate server-side date formatting
        $patterns = [
            // Carbon format() calls - e.g., ->format('M d, Y')
            '/->format\s*\(\s*[\'"]/' => '->format() call',

            // Carbon diffForHumans() calls - e.g., ->diffForHumans()
            '/->diffForHumans\s*\(/' => '->diffForHumans() call',

            // Carbon::parse()->format() chain
            '/Carbon::parse\s*\([^)]+\)\s*->format\s*\(/' => 'Carbon::parse()->format() chain',

            // Rsx_Time formatting (which is fine on its own, but suggests formatted values in response)
            // We still flag these because they shouldn't be in fetch() - format client-side
            '/Rsx_Time::format_date(time)?(_with_tz)?\s*\(/' => 'Rsx_Time formatting call',
            '/Rsx_Time::relative\s*\(/' => 'Rsx_Time::relative() call',

            // Rsx_Date formatting
            '/Rsx_Date::format\s*\(/' => 'Rsx_Date::format() call',
        ];

        foreach ($fetch_body as $relative_line => $line_content) {
            // Check for exception comment
            if (str_contains($line_content, '@' . $this->get_id() . '-EXCEPTION')) {
                continue;
            }

            foreach ($patterns as $pattern => $description) {
                if (preg_match($pattern, $line_content)) {
                    $this->add_violation(
                        $file_path,
                        $relative_line,
                        $this->_get_violation_message($description),
                        trim($line_content),
                        $this->_get_resolution_message(),
                        'high'
                    );
                    break; // Only one violation per line
                }
            }
        }
    }

    /**
     * Extract the fetch() method body with line numbers
     *
     * @param array $lines File lines
     * @return array [line_number => line_content]
     */
    private function _extract_fetch_body(array $lines): array
    {
        $in_fetch = false;
        $brace_count = 0;
        $fetch_body = [];
        $started = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $line_num = $i + 1;

            // Look for fetch method declaration
            if (!$in_fetch) {
                if (preg_match('/\b(public\s+)?static\s+function\s+fetch\s*\(/', $line)) {
                    $in_fetch = true;
                    // Count opening brace if on same line
                    if (str_contains($line, '{')) {
                        $started = true;
                        $brace_count = 1;
                    }
                }
                continue;
            }

            // We're inside fetch method
            if (!$started) {
                // Looking for opening brace
                if (str_contains($line, '{')) {
                    $started = true;
                    $brace_count = 1;
                }
                continue;
            }

            // Track braces to find method end
            $brace_count += substr_count($line, '{') - substr_count($line, '}');

            if ($brace_count <= 0) {
                break; // End of fetch method
            }

            $fetch_body[$line_num] = $line;
        }

        return $fetch_body;
    }

    /**
     * Get the violation message
     */
    private function _get_violation_message(string $pattern_description): string
    {
        return "Server-side date formatting detected in fetch() method ({$pattern_description}).\n\n" .
            "Dates and datetimes should not be pre-formatted for Ajax responses. " .
            "RSpade stores dates as 'YYYY-MM-DD' strings and datetimes as ISO 8601 UTC strings " .
            "(e.g., '2024-12-24T15:30:45.123Z'). These values should be passed directly to the " .
            "client and formatted using JavaScript.\n\n" .
            "Problems with server-side formatting:\n" .
            "  1. Creates implicit API contracts - frontend developers may expect these fields\n" .
            "     to exist on all model responses, leading to confusion\n" .
            "  2. Prevents client-side locale customization\n" .
            "  3. Increases response payload size with redundant data\n" .
            "  4. Violates the principle that fetch() returns model data, not presentation";
    }

    /**
     * Get the resolution message
     */
    private function _get_resolution_message(): string
    {
        return "Remove the formatted date fields from fetch(). Format dates client-side:\n\n" .
            "  JavaScript datetime formatting:\n" .
            "    Rsx_Time.format_datetime(iso_string)     // 'Dec 24, 2024 3:30 PM'\n" .
            "    Rsx_Time.format_datetime_with_tz(iso)    // 'Dec 24, 2024 3:30 PM CST'\n" .
            "    Rsx_Time.relative(iso_string)            // '2 hours ago'\n\n" .
            "  JavaScript date formatting:\n" .
            "    Rsx_Date.format(date_string)             // 'Dec 24, 2024'\n\n" .
            "  Example in jqhtml template:\n" .
            "    <span><%= Rsx_Time.relative(this.data.model.created_at) %></span>\n\n" .
            "JQHTML-DATETIME-01 is this rule's template-side twin: it flags the other half of the " .
            "same contract, a raw *_at / *_date value printed in a .jqhtml template without one of " .
            "these formatters.\n\n" .
            "See: php artisan rsx:man time";
    }
}
