<?php


namespace Rsx\Lib\TextTypes\RichText;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;

/**
 * Rich_Text - sanitized HTML from a WYSIWYG editor.
 *
 * The encoding is HTML, filtered on write by sanitize_rich_text_html() (HTMLPurifier).
 * Because the filter runs at the trust boundary, what the column holds is already safe to
 * render:
 * to_html() is the identity and a read costs nothing. That is the whole reason the filter
 * is on the write side - purifying on read would put an HTMLPurifier pass on every row of
 * every list, and would mean the database is knowingly holding unsafe content that only
 * looks safe because every reader remembered to clean it.
 *
 * REPLACING THIS TYPE. Everything here is application code and is meant to be adapted.
 * The usual reason is the allow-list: sanitize_rich_text_html() ships a general-purpose
 * set of tags and attributes, and an application that permits, say, embedded media or a
 * class allow-list of its own changes sanitize_encoded() and nothing else.
 */
class Rich_Text extends Rsx_Text_Abstract
{
    /**
     * The trust boundary. Every value that did not come from the database passes through
     * here exactly once, and what survives is what the column stores.
     *
     * @param string $raw
     * @return string
     */
    public static function sanitize_encoded(string $raw): string
    {
        return sanitize_rich_text_html($raw);
    }

    /**
     * Stored content is already filtered, so the static rendition is the stored form.
     *
     * @return string
     */
    public function to_html(): string
    {
        return $this->raw;
    }

    /**
     * Emptiness by CONTENT, not by string. An emptied WYSIWYG stores <p><br></p> - a
     * non-empty string holding an empty document - so the base class's raw check would
     * call it full. This is the override the base says a wrapping encoding owes.
     *
     * @return bool
     */
    public function is_empty(): bool
    {
        return trim($this->to_plain_text()) === '';
    }

    /**
     * The readable text: markup removed, block boundaries preserved as newlines so a CSV
     * cell or a search index keeps the shape of the original paragraphs instead of running
     * every block together into one line.
     *
     * @return string
     */
    public function to_plain_text(): string
    {
        $with_breaks = preg_replace(
            '#<(br|/p|/div|/li|/h[1-6]|/blockquote|/tr)[^>]*>#i',
            "\n",
            $this->raw
        );

        $stripped = html_entity_decode(strip_tags($with_breaks), ENT_QUOTES | ENT_HTML5);

        // Collapse the runs of blank lines the block boundaries above tend to produce -
        // </li></ul> is two boundaries and one paragraph break.
        return trim(preg_replace("#\n{3,}#", "\n\n", $stripped));
    }

    /**
     * Plain text -> HTML. Every bare string assigned to the column comes through here (an
     * import, a seed, a plain API param), and so does a column upgraded from plain text:
     * the content is escaped (it was never markup) and its line breaks are preserved, so
     * nothing a user typed is reinterpreted as a tag. sanitize_encoded() runs on the result.
     *
     * @param string $plain
     * @return string
     */
    public static function encode_plain_text(string $plain): string
    {
        return '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5)) . '</p>';
    }
}
