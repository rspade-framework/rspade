<?php


namespace Rsx\App\Dev\AttachmentThumbnail;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;

/**
 * Ajax endpoints for the Attachment Thumbnail dev demo page.
 *
 * The page's purpose is to make the async render pipeline OBSERVABLE by hand: pick an attachment,
 * push its blob back to PENDING, watch <Attachment_Thumbnail> repaint the extension-icon
 * placeholder, then run the render and watch the real raster swap in over realtime with no reload.
 *
 * Gated to debug sites by the 'dev_tools' check.
 */
#[Auth('dev_tools')]
class Dev_Attachment_Thumbnail_Controller extends Rsx_Controller_Abstract
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
     * Push this attachment's blob back to PENDING - the placeholder state - WITHOUT spawning a
     * render worker.
     *
     * The no-kick argument is the whole point: a detached worker would race the human, render the
     * document a fraction of a second later, and there would be nothing to watch. The row is still
     * queued, so the sweeper remains the backstop if nobody presses Render.
     *
     * @param Request $request
     * @param array $params Requires attachment_id.
     * @return array
     */
    #[Ajax_Endpoint]
    public static function reset_render(Request $request, array $params = [])
    {
        $storage = static::__storage_for($params);

        if (!$storage instanceof File_Storage_Model) {
            return $storage;
        }

        $storage->requeue_render(false);

        // The state that changed lives on the BLOB, and _file_storage is not a realtime model (it
        // has no site_id for a frame to carry). So say it on the attachment, the same way the
        // render worker announces the opposite transition - otherwise every open <Attachment_Thumbnail>
        // would go on showing the raster of a render that no longer exists.
        $attachment = File_Attachment_Model::find($params['attachment_id']);
        $attachment->realtime_emit();

        return ['render_status_id' => (int) $storage->render_status_id];
    }

    /**
     * Run the render for this attachment's blob INLINE, in this request.
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
    public static function run_render(Request $request, array $params = [])
    {
        $storage = static::__storage_for($params);

        if (!$storage instanceof File_Storage_Model) {
            return $storage;
        }

        Document_Render_Service::render_storage($storage);

        $fresh = File_Storage_Model::find($storage->id);

        return [
            'render_status_id' => (int) $fresh->render_status_id,
            'rendered_at' => $fresh->rendered_at,
        ];
    }

    /**
     * The blob behind an attachment_id param, or the error response to return instead.
     *
     * @param array $params
     * @return File_Storage_Model|array
     */
    private static function __storage_for(array $params)
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
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'Attachment has no resident blob');
        }

        return File_Storage_Model::find($attachment->file_storage_id);
    }
}
