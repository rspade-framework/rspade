<?php

namespace App\RSpade\CodeQuality\Rules\Jqhtml;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * Detects raw datetime/date values interpolated into a .jqhtml template
 *
 * A model's *_at / *_date attributes reach the browser as storage-shaped strings - a
 * datetime as an ISO 8601 UTC string, a date as "YYYY-MM-DD". Printing one of those
 * directly puts the STORAGE representation on screen instead of the value rendered in
 * the viewer's resolved timezone. Display goes through Rsx_Time / Rsx_Date, always.
 *
 * Detection is deliberately shallow: every printing interpolation segment on a line
 * (<%= ... %> and <%!= ... %>, never the <% ... %> statement form) is scanned for an
 * identifier ending in _at or _date, and the segment is flagged when it contains
 * neither "Rsx_Time." nor "Rsx_Date.".
 *
 * KNOWN FALSE NEGATIVE - MIXED EXPRESSIONS. The formatter test is per SEGMENT, not per
 * identifier, so an expression that formats one temporal value and prints another raw
 * passes clean:
 *
 *     <%= Rsx_Time.format_datetime(row.created_at) + ' / ' + row.updated_at %>
 *
 * The alternative (per-identifier argument tracing) needs a real JS expression parse and
 * would fire on every wrapped value written in a shape the tracer did not model. A rule
 * that cries wolf gets ignored, so this one under-reports instead. The prelaunch
 * checklist (ENTRY 7) carries the manual sweep that covers what the regex cannot reach.
 */
class JqhtmlDatetimeFormatting_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Identifier shapes that name a temporal value
     */
    private const TEMPORAL_IDENTIFIER_PATTERN = '/\b[a-z0-9_]*(_at|_date)\b/i';

    /**
     * Printing interpolation segments: <%= expr %> and <%!= expr %>.
     * The bare <% ... %> statement form is logic, not display, and is not matched.
     */
    private const PRINTING_SEGMENT_PATTERN = '/<%!?=(.*?)%>/s';

    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'JQHTML-DATETIME-01';
    }

    /**
     * Get the rule name
     */
    public function get_name(): string
    {
        return 'Jqhtml Datetime Display Formatting';
    }

    /**
     * Get the rule description
     */
    public function get_description(): string
    {
        return 'Detects date/datetime values printed in a jqhtml template without an Rsx_Time / Rsx_Date formatter';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.jqhtml'];
    }

    /**
     * Get default severity
     */
    public function get_default_severity(): string
    {
        return 'medium';
    }

    /**
     * Whether this rule runs during manifest scan
     *
     * Advisory rule - reported by rsx:check, never fatal at build time.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return false;
    }

    /**
     * Run the rule check
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $lines = explode("\n", $contents);

        if (!preg_match_all(self::PRINTING_SEGMENT_PATTERN, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $flagged_lines = [];

        foreach ($matches[1] as $index => $capture) {
            $expression = $capture[0];
            $segment = $matches[0][$index][0];
            $offset = $matches[0][$index][1];

            if (!preg_match(self::TEMPORAL_IDENTIFIER_PATTERN, $expression)) {
                continue;
            }

            if (str_contains($expression, 'Rsx_Time.') || str_contains($expression, 'Rsx_Date.')) {
                continue;
            }

            // Line number of the segment's opening delimiter (1-based)
            $line_number = substr_count($contents, "\n", 0, $offset) + 1;

            // One finding per line keeps a multi-segment line from reading as a pile-up
            if (isset($flagged_lines[$line_number])) {
                continue;
            }

            // Line-level exception: same line, or the line immediately above
            $line_index = $line_number - 1;
            $marker = '@' . $this->get_id() . '-EXCEPTION';
            if (str_contains($lines[$line_index] ?? '', $marker)) {
                continue;
            }
            if ($line_index > 0 && str_contains($lines[$line_index - 1] ?? '', $marker)) {
                continue;
            }

            $flagged_lines[$line_number] = true;

            $this->add_violation(
                $file_path,
                $line_number,
                $this->_get_violation_message(trim($segment)),
                trim($lines[$line_index] ?? $segment),
                $this->_get_resolution_message(),
                $this->get_default_severity()
            );
        }
    }

    /**
     * Get the violation message
     */
    private function _get_violation_message(string $segment): string
    {
        return "A date/datetime value is printed in this template without an Rsx_Time / Rsx_Date formatter:\n\n" .
            "    {$segment}\n\n" .
            "WHAT IS WRONG\n" .
            "  Model attributes are storage-shaped strings by the time they reach JavaScript: a\n" .
            "  DATETIME column yields an ISO 8601 UTC string ('2025-12-24T21:30:00Z') and a DATE\n" .
            "  column yields 'YYYY-MM-DD'. Printing one directly puts that storage string on the\n" .
            "  screen, in UTC, rather than the moment rendered in the VIEWER's resolved timezone\n" .
            "  (user preference -> site default -> config default). Hand-rolled conversion is the\n" .
            "  same defect wearing a suit: new Date(value).toLocaleDateString() renders in the\n" .
            "  BROWSER's zone, not the user's configured one, and on a plain 'YYYY-MM-DD' date it\n" .
            "  parses as UTC midnight and shows the PREVIOUS day for every viewer west of UTC.\n\n" .
            "  Every rendered date and datetime goes through the framework formatters. They are\n" .
            "  the only code that knows the resolved zone.";
    }

    /**
     * Get the resolution message
     */
    private function _get_resolution_message(): string
    {
        return "Wrap the value in the formatter that matches the column type:\n\n" .
            "  A DATETIME value (a real moment - created_at, sent_at, completed_at):\n" .
            "    <%= Rsx_Time.format_datetime(this.data.model.created_at) %>      // 'Dec 24, 2025 3:30 PM'\n" .
            "    <%= Rsx_Time.format_datetime_with_tz(this.data.model.sent_at) %> // '... 3:30 PM CST'\n" .
            "    <%= Rsx_Time.format_date(this.data.model.created_at) %>          // 'Dec 24, 2025'\n" .
            "    <%= Rsx_Time.relative(this.data.model.created_at) %>             // '2 hours ago'\n\n" .
            "  A DATE value (a calendar fact - birth_date, due_date, invoice_date):\n" .
            "    <%= Rsx_Date.format(this.data.model.due_date) %>                 // 'Dec 24, 2025'\n\n" .
            "  Never new Date(...), never toLocaleDateString(), never string slicing. Rsx_Date is\n" .
            "  timezone-FREE by design and is the correct class for a calendar date; passing a date\n" .
            "  to Rsx_Time (or a datetime to Rsx_Date) throws.\n\n" .
            "WHEN THIS FINDING IS A FALSE POSITIVE\n" .
            "  1. The value was already formatted in JavaScript before the template saw it (for\n" .
            "     example a component built row.created_at_display in on_load()).\n" .
            "  2. The identifier is not temporal at all and merely ends in _at or _date - a name\n" .
            "     like format_date, seat_at, or an enum label field.\n" .
            "  Both get a line-level marker with a one-word reason:\n\n" .
            "    <%= row.created_at %>  <!-- @JQHTML-DATETIME-01-EXCEPTION - preformatted -->\n\n" .
            "  This rule reads the marker on the flagged line or the line immediately above it, so\n" .
            "  that is where it belongs - one marker per genuinely exempt line, each with its own\n" .
            "  reason. (The checker's own generic handling is file-granular, so a stray marker\n" .
            "  elsewhere in the file silences the whole file: keep the placement tight.)\n\n" .
            "FIX AUTONOMOUSLY OR ASK\n" .
            "  FIX IT. Wrapping a displayed value in the matching formatter is always safe and\n" .
            "  never changes stored data or an API shape. Ask only when the fix would require\n" .
            "  changing WHERE the value comes from - and never grant the exception marker yourself\n" .
            "  without the programmer's approval (see CodeQuality/CLAUDE.md).\n\n" .
            "  If the value turns out to be a calendar fact stored in a DATETIME column, that is a\n" .
            "  SCHEMA defect, not a display one. Format it correctly here and raise the column: the\n" .
            "  prelaunch checklist ENTRY 7 covers the DATETIME-vs-DATE column audit.\n\n" .
            "See: php artisan rsx:man time\n" .
            "     php artisan rsx:man prelaunch_checklist (ENTRY 7 - the display + column audit)\n" .
            "     MODEL-FETCH-DATE-01 - the server-side twin, which forbids pre-formatting a date\n" .
            "     inside a model's fetch() so that the template stays the one place formatting happens.";
    }
}
