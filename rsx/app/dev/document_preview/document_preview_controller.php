<?php


namespace Rsx\App\Dev\DocumentPreview;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Search\Search_Index_Model;

/**
 * Ajax endpoints for the Document Preview dev demo page. Lists recent attachments to pick from and
 * exposes the extracted document text so the demo can show the extraction pipeline output next to
 * the rendered preview.
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
     * The extracted text + extraction status for an attachment. status is a label string (or
     * 'pending' when the document has not been indexed yet).
     *
     * @param Request $request
     * @param array $params Requires attachment_id.
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_extracted_text(Request $request, array $params = [])
    {
        $attachment_id = $params['attachment_id'] ?? null;
        if (!$attachment_id) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'attachment_id is required');
        }

        $attachment = File_Attachment_Model::find($attachment_id);
        if (!$attachment) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, 'Attachment not found');
        }

        $status_id = $attachment->get_extraction_status();
        $status_label = $status_id === null
            ? 'pending'
            : (Search_Index_Model::status_id__enum_labels()[$status_id] ?? 'unknown');

        return [
            'text' => $attachment->get_extracted_text() ?? '',
            'status' => $status_label,
        ];
    }
}
