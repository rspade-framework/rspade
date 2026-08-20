<?php

namespace App\RSpade\CodeQuality\Rules\Jqhtml;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * JqhtmlHtmlCommentRule - Detects HTML comments used before <Define> in jqhtml files
 *
 * Catches the common mistake of using <!-- --> comments for docblocks instead of
 * the correct <%-- --%> jqhtml comment syntax. HTML comments in jqhtml files
 * still have their contents parsed as JavaScript, causing cryptic errors.
 */
class JqhtmlHtmlComment_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JQHTML-COMMENT-01';
    }

    public function get_name(): string
    {
        return 'Jqhtml HTML Comment Detection';
    }

    public function get_description(): string
    {
        return 'Detects HTML comments (<!-- -->) used before <Define> tag - must use <%-- --%> syntax';
    }

    public function get_file_patterns(): array
    {
        return ['*.jqhtml'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Run during manifest scan for immediate feedback on this common mistake.
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * HTML comments before <Define> cause cryptic JavaScript parsing errors that are
     * extremely difficult to debug. Catching this early prevents developer confusion.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Process file during manifest update to detect HTML comments used as docblocks
     *
     * Only flags HTML comments that appear BEFORE any jqhtml comment starts.
     * HTML comments inside jqhtml comments (<%-- ... --%>) are safe - they're
     * documentation/examples and will be stripped with the jqhtml comment.
     */
    public function on_manifest_file_update(string $file_path, string $contents, array $metadata = []): ?array
    {
        // Find the position of first <Define: tag
        $define_pos = strpos($contents, '<Define:');
        if ($define_pos === false) {
            // No <Define> tag, nothing to check
            return null;
        }

        // Get content before <Define>
        $before_define = substr($contents, 0, $define_pos);

        // Check for HTML comment opening
        $html_comment_pos = strpos($before_define, '<!--');
        if ($html_comment_pos === false) {
            return null;
        }

        // Check for jqhtml comment opening
        $jqhtml_comment_pos = strpos($before_define, '<%--');

        // If jqhtml comment starts BEFORE the HTML comment, then the HTML comment
        // is inside the jqhtml comment block (documentation/example) and is safe
        if ($jqhtml_comment_pos !== false && $jqhtml_comment_pos < $html_comment_pos) {
            return null;
        }

        // Found HTML comment before any jqhtml comment - this is the docblock mistake
        $line_number = substr_count(substr($contents, 0, $html_comment_pos), "\n") + 1;

        // Extract the comment content for the error message
        $comment_end = strpos($contents, '-->', $html_comment_pos);
        $comment_text = $comment_end !== false
            ? substr($contents, $html_comment_pos, min($comment_end - $html_comment_pos + 3, 100))
            : substr($contents, $html_comment_pos, 50);

        return [
            'html_comment_violation' => [
                'line' => $line_number,
                'code' => trim($comment_text),
            ],
        ];
    }

    /**
     * Check jqhtml file for HTML comment violations stored in metadata
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Check for violations in code quality metadata
        if (!isset($metadata['code_quality_metadata']['JQHTML-COMMENT-01']['html_comment_violation'])) {
            return;
        }

        $violation = $metadata['code_quality_metadata']['JQHTML-COMMENT-01']['html_comment_violation'];

        $error_message = "Code Quality Violation (JQHTML-COMMENT-01) - HTML Comment in Jqhtml File\n\n";
        $error_message .= "CRITICAL: HTML comments (<!-- -->) are NOT safe for docblocks in .jqhtml files.\n\n";
        $error_message .= "File: {$file_path}\n";
        $error_message .= "Line: {$violation['line']}\n";
        $error_message .= "Code: {$violation['code']}\n\n";
        $error_message .= $this->get_remediation();

        throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
            $error_message,
            0,
            null,
            base_path($file_path),
            $violation['line']
        );
    }

    /**
     * Get remediation instructions
     */
    private function get_remediation(): string
    {
        return "PROBLEM: HTML comments in jqhtml files still have their contents parsed as JavaScript.
This causes cryptic syntax errors because the template engine processes everything inside <!-- -->.

EXAMPLE OF THE BUG:
    <!-- Component docblock -->
              ^^^^^^^^^^^
    This gets parsed as: Component docblock
    Which is invalid JavaScript, causing: SyntaxError: Unexpected identifier 'docblock'

SOLUTION: Use jqhtml comment syntax instead:

    WRONG:  <!-- This is a docblock for my component -->
    RIGHT:  <%-- This is a docblock for my component --%>

The <%-- --%> syntax is a true comment that is completely stripped before parsing.

QUICK FIX:
    Find:    <!--
    Replace: <%--

    Find:    -->
    Replace: --%>

WHY THIS MATTERS:
- HTML comments are NOT comments in jqhtml - their contents are parsed
- This causes confusing 'Unexpected identifier' or 'Unexpected token' errors
- The error messages don't indicate the real problem (using wrong comment syntax)
- Developers waste time debugging JavaScript when the fix is just changing comment delimiters";
    }
}
