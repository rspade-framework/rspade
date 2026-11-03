<?php


namespace Rsx\Lib\TextTypes\RichText;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Embed_References;

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
 * FILE EMBEDS. A file dropped into the editor is stored in the prose as the plain-text token
 * {{#file:<attachment id>}} - no HTML metacharacter, so it survives the filter as text. The
 * token is the whole stored form; the editor and Rich_Text_Display expand it into a file card
 * (rsx/lib/inline_file/). Saving a record makes it OWN each file its text embeds:
 * after_model_saved() below claims them (File_Attachment_Model::claim_for_embed(), category
 * inline_embed) and keeps the framework's embed references in step, so an embedded file is
 * never swept as an abandoned upload. Removing a token keeps the file and its owner -
 * a restored revision of the old text still shows it.
 *
 * REPLACING THIS TYPE. Everything here is application code and is meant to be adapted.
 * The usual reason is the allow-list: sanitize_rich_text_html() ships a general-purpose
 * set of tags and attributes, and an application that permits, say, embedded media or a
 * class allow-list of its own changes sanitize_encoded() and nothing else.
 */
class Rich_Text extends Rsx_Text_Abstract
{
    /**
     * Every {{#...}} token. A file embed is {{#file:<attachment id>}}.
     */
    const FILE_TOKEN_PATTERN = '/\{\{#[^}]+\}\}/';

    /**
     * One file embed, capturing the attachment id.
     */
    const FILE_EMBED_PATTERN = '/\{\{#file:(\d+)\}\}/';

    /**
     * The attachment ids this value embeds, in order of first appearance.
     *
     * @return array<int>
     */
    public function file_ids(): array
    {
        preg_match_all(self::FILE_EMBED_PATTERN, $this->raw, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * The record now owns each file its text embeds, and the embed references match the text
     * - both limited to attachments on the record's own site.
     * Runs for every model that declares a Rich_Text column, on every save that changes it.
     *
     * @param \App\RSpade\Core\Database\Models\Rsx_Model_Abstract $model
     * @param string $column
     * @param Rsx_Text_Abstract|null $old
     * @return void
     */
    public static function after_model_saved($model, string $column, ?Rsx_Text_Abstract $old): void
    {
        $value = $model->$column;
        $ids = $value instanceof self ? $value->file_ids() : [];

        // The ids the text names that are attachments on the OWNER's site. Only these are
        // claimed and only these are referenced: the text is user-authored and may name any
        // id, and a reference to another site's attachment would hold that tenant's upload
        // (and its blob) out of the disposal sweep.
        $site_ids = [];

        if ($ids) {
            // The owner's site, explicitly: a save can run in a task or command with no site
            // declared, and the claim's write must be on the attachment's own site.
            File_Attachment_Model::without_site_scope(function () use ($model, $ids, &$site_ids) {
                $query = File_Attachment_Model::withTrashed()->whereIn('id', $ids);
                if (isset($model->site_id)) {
                    $query->where('site_id', $model->site_id);
                }
                foreach ($query->get() as $attachment) {
                    $site_ids[] = (int) $attachment->id;
                    $attachment->claim_for_embed($model);
                }
            });
        }

        File_Embed_References::sync($model, $column, $site_ids);
    }

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
     * call it full. This is the override the base says a wrapping encoding owes. A body
     * holding only a file embed has content.
     *
     * @return bool
     */
    public function is_empty(): bool
    {
        return trim($this->to_plain_text()) === '' && $this->file_ids() === [];
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

        // A file embed is a picture of a file, not words: it has no plain-text form.
        $with_breaks = preg_replace(self::FILE_TOKEN_PATTERN, '', $with_breaks);

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
        return '<p>' . nl2brhtml($plain) . '</p>';
    }
}
