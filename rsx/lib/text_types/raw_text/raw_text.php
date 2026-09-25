<?php


namespace Rsx\Lib\TextTypes\RawText;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;

/**
 * Raw_Text - a block of user-authored PLAIN text.
 *
 * The simplest possible text type, and the one to declare on a column that holds notes,
 * a description or an address typed into a textarea. The storage form IS the text, so
 * to_plain_text() is the identity and there is nothing to filter.
 *
 * Declaring it rather than leaving the column undeclared is not decoration. It buys:
 *
 *   - an editor that REFUSES a rich-text value, so a column cannot silently start
 *     accepting markup because someone swapped the widget;
 *   - a printer that is chosen by the type instead of by whoever wrote the template;
 *   - a stated intent, so the day the column becomes Rich_Text the diff is one line and
 *     every consumer follows.
 *
 * A column with no declaration at all is still an ordinary string and still works - this
 * type is what you reach for when you want the column to MEAN something.
 */
class Raw_Text extends Rsx_Text_Abstract
{
    /**
     * A PASSTHROUGH, written down on purpose. The framework makes this method required
     * rather than defaulted so that "no filter" is always a declaration with its reason
     * beside it, never something a type inherits by forgetting.
     *
     * The reason it is safe here: plain text is never rendered as markup. The printer
     * escapes it and to_plain_text() is the identity; the type defines no to_html(), so no
     * server-side sink renders it at all. There is no encoding to enforce and nothing an
     * input could contain that the sinks would interpret. If this type ever grew an
     * encoding, this is the first line to change.
     *
     * @param string $raw
     * @return string
     */
    public static function sanitize_encoded(string $raw): string
    {
        return $raw;
    }

    /**
     * Plain text -> plain text: a PASSTHROUGH, written down on purpose for the same reason
     * as sanitize_encoded(). The encoding IS plain text, so a bare string is already in it.
     *
     * @param string $plain
     * @return string
     */
    public static function encode_plain_text(string $plain): string
    {
        return $plain;
    }

    /**
     * The stored form is already the readable text.
     *
     * @return string
     */
    public function to_plain_text(): string
    {
        return $this->raw;
    }
}
