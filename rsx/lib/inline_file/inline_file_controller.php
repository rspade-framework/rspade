<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace Rsx\Lib\InlineFile;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Rsx_File_Gates;
use App\RSpade\Core\Session\Session;
use Rsx\Lib\Attachments\Record_Attachment_List;

/**
 * Inline_File_Controller - the two reads behind a file embedded in rich text.
 *
 * Rich text names an embedded file by attachment id ({{#file:<id>}}, see Rich_Text). The
 * editor and Rich_Text_Display draw each one as a card, and these endpoints answer what a card
 * shows: one Record_Attachment_List::row() (so a click opens the same preview dialog a
 * record's attachment list uses) plus is_image and file_extension.
 *
 * WHO SEES AN EMBED. An embed is visible to whoever may see the file it names, decided the
 * way every file read is decided:
 *   - a file not yet claimed (just uploaded, its record not saved) only to its uploader;
 *   - a claimed file when the file.thumbnail.authorize and file.download.authorize gates
 *     allow it, and its owning record's can_view() does where the record states one.
 * A token copied into another record therefore shows its file only to the people who can see
 * the record that OWNS it; everyone else gets the card's "File not found" placeholder.
 */
#[Auth('is_logged_in')]
class Inline_File_Controller extends Rsx_Controller_Abstract
{
    /**
     * The card of a file this user just uploaded through the editor.
     *
     * @param Request $request
     * @param array $params key - the upload's key, from Ajax.upload()
     * @return array
     */
    #[Ajax_Endpoint]
    public static function resolve_attachment(Request $request, array $params = [])
    {
        $key = $params['key'] ?? null;
        $attachment = is_string($key) ? File_Attachment_Model::find_by_key($key) : null;

        if (!$attachment || !self::__is_own_pending_upload($attachment)) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'File not found');
        }

        return self::__card($attachment);
    }

    /**
     * The cards of the files a piece of rich text embeds, keyed by attachment id. An id this
     * user may not see, or that names no file, is left out: its card shows "File not found"
     * and the rest of the text still renders.
     *
     * @param Request $request
     * @param array $params ids - attachment ids
     * @return array{files: array<int, array>}
     */
    #[Ajax_Endpoint]
    public static function resolve_files(Request $request, array $params = [])
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($params['ids'] ?? [])))));

        // A card per embed in one document; a document embedding more than this is not one
        // a person wrote.
        if (count($ids) > 500) {
            return response_error(Ajax::ERROR_VALIDATION, 'Too many embedded files in one request.');
        }

        $files = [];
        if ($ids) {
            foreach (File_Attachment_Model::whereIn('id', $ids)->get() as $attachment) {
                if (self::__can_see($attachment, $request)) {
                    $files[(int) $attachment->id] = self::__card($attachment);
                }
            }
        }

        return ['files' => (object) $files];
    }

    /**
     * Whether the current user may see this embedded file. See the class docblock.
     */
    private static function __can_see(File_Attachment_Model $attachment, Request $request): bool
    {
        if (!$attachment->fileable_type) {
            return self::__is_own_pending_upload($attachment);
        }

        $gate_data = [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ];
        if (Rsx_File_Gates::authorize(Rsx_File_Gates::THUMBNAIL, $gate_data) !== true
            || Rsx_File_Gates::authorize(Rsx_File_Gates::DOWNLOAD, $gate_data) !== true) {
            return false;
        }

        $owner = $attachment->fileable;
        if (!$owner) {
            return false;
        }

        return method_exists($owner, 'can_view') ? (bool) $owner->can_view() : true;
    }

    /**
     * An unclaimed upload whose created_by pair names the current user.
     */
    private static function __is_own_pending_upload(File_Attachment_Model $attachment): bool
    {
        if ($attachment->fileable_type || $attachment->fileable_id || $attachment->handler_class !== null) {
            return false;
        }

        return $attachment->was_uploaded_by_current_actor();
    }

    /**
     * What one card draws.
     */
    private static function __card(File_Attachment_Model $attachment): array
    {
        return Record_Attachment_List::row($attachment) + [
            'is_image' => (bool) $attachment->is_image(),
            'file_extension' => $attachment->file_extension,
        ];
    }
}
