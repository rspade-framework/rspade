<?php


namespace Rsx\App\Dev\DocumentPreview;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Search\Search_Index_Model;

/**
 * Ajax endpoints for the Document Preview dev demo page: the attachment picker, plus the two
 * controls that make the ASYNC EXTRACTION observable by hand (reset the blob to un-indexed, then
 * run the pass inline) so a human - or Playwright - can watch <Document_Text_Preview> swap out of
 * its "(Extracting Text...)" notice with no reload.
 *
 * The extracted text itself is NOT served from here: <Document_Text_Preview> reads it from the
 * framework endpoint File_Preview_Controller::get_extracted_text, which is the one way to do it.
 *
 * CLOSED: no one can reach it, by declaration.
 */
#[Auth('closed')]
class Dev_Document_Preview_Controller extends Rsx_Controller_Abstract
{
    /**
     * The 50 most recent attachments, newest first, for the demo picker.
     *
     * @param Request $request
     * @param array $params
     * @return array
     */
    #[Ajax_Endpoint]
    public static function list_attachments(Request $request, array $params = [])
    {
        $rows = File_Attachment_Model::orderBy('id', 'desc')
            ->limit(50)
            ->get(['id', 'key', 'file_name', 'mime_type']);

        return [
            'attachments' => $rows->map(function ($a) {
                return [
                    'id' => $a->id,
                    'key' => $a->key,
                    'file_name' => $a->file_name,
                    'mime_type' => $a->mime_type,
                ];
            })->all(),
        ];
    }

    /**
     * Push this attachment's blob back to un-indexed - the "(Extracting Text...)" state - WITHOUT
     * spawning a worker.
     *
     * There is no requeue_extraction() on the model to call: the extraction queue IS
     * _file_storage.is_indexed = 0 with no _search_indexes row, and nothing in the framework ever
     * un-does a completed extraction (rsx:search:reindex re-extracts, it does not reset). This
     * demo page is the one place that wants the un-indexed state back, so it writes it here rather
     * than adding a framework verb with a single dev caller.
     *
     * The no-kick argument is the whole point: a detached worker would extract the document a
     * fraction of a second later and there would be nothing to watch.
     *
     * @param Request $request
     * @param array $params Requires attachment_id.
     * @return array
     */
    #[Ajax_Endpoint]
    public static function reset_extraction(Request $request, array $params = [])
    {
        $attachment = static::__attachment_for($params);

        if (!$attachment instanceof File_Attachment_Model) {
            return $attachment;
        }

        $storage = File_Storage_Model::find($attachment->file_storage_id);
        $index = Search_Index_Model::forModel('File_Storage_Model', $storage->id)->first();
        if ($index) {
            $index->delete();
        }

        $storage->is_indexed = 0;
        $storage->save();

        // The state that changed lives on the BLOB, and _file_storage is not a realtime model (it
        // has no site_id for a frame to carry). Say it on the attachment, exactly the way the
        // worker announces the opposite transition - otherwise every open <Document_Text_Preview>
        // would go on showing text that is no longer indexed.
        $attachment->realtime_emit();

        return ['is_indexed' => (int) $storage->is_indexed];
    }

    /**
     * Run the render/extract pass for this attachment's blob INLINE, in this request.
     *
     * Same unit of work the background worker runs (rendition, text extraction, thumbnail-cache
     * purge, realtime emission on the referencing attachments) - just synchronous, so the caller
     * knows exactly when the frame went out.
     *
     * @param Request $request
     * @param array $params Requires attachment_id.
     * @return array
     */
    #[Ajax_Endpoint]
    public static function run_extraction(Request $request, array $params = [])
    {
        $attachment = static::__attachment_for($params);

        if (!$attachment instanceof File_Attachment_Model) {
            return $attachment;
        }

        $storage = File_Storage_Model::find($attachment->file_storage_id);
        Document_Render_Service::render_storage($storage);

        return ['is_indexed' => (int) File_Storage_Model::find($storage->id)->is_indexed];
    }

    /**
     * The attachment behind an attachment_id param, or the error response to return instead.
     *
     * @param array $params
     * @return File_Attachment_Model|array
     */
    private static function __attachment_for(array $params)
    {
        $attachment_id = $params['attachment_id'] ?? null;
        if (!$attachment_id) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'attachment_id is required');
        }

        $attachment = File_Attachment_Model::find($attachment_id);
        if (!$attachment) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, 'Attachment not found');
        }

        if ($attachment->file_storage_id === null) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_GENERIC, 'Attachment has no resident blob');
        }

        return $attachment;
    }
}
