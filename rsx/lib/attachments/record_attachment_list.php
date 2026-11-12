<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace Rsx\Lib\Attachments;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Rsx_Duplicate_Upload;

/**
 * Record_Attachment_List - the files attached to one record, for the <Record_Attachments>
 * section of a view page.
 *
 * Any record can carry a list of attachments: its controller exposes one endpoint that
 * returns rows() and one that claims uploads through claim(), and its view page mounts the
 * section. The record's own controller owns the gates and the record lookup; this class owns the
 * category the files are filed under and the row shape the section and its preview
 * dialog read.
 *
 * Worked example: Frontend_Tasks_Controller::task_attachments() / attachments_add().
 */
class Record_Attachment_List
{
    /** The fileable_category every record's attachment list is filed under. */
    const CATEGORY = 'attachments';

    /**
     * Every file attached to the record, oldest first.
     *
     * @return array<int, array> One row() per attachment.
     */
    public static function rows(Rsx_Model_Abstract $record): array
    {
        $rows = [];

        foreach ($record->get_attachments(self::CATEGORY) as $attachment) {
            $rows[] = self::row($attachment);
        }

        usort($rows, fn ($a, $b) => $a['file_attachment_id'] <=> $b['file_attachment_id']);

        return $rows;
    }

    /**
     * Attach uploaded files to the record - the body of a claim endpoint.
     *
     * Each key names a file the browser has already sent through Ajax.upload(). A key that
     * is unknown, already attached to something, or from another site is refused, and then
     * NOTHING is attached - the caller reports the failure and the user retries the batch.
     *
     * A file whose bytes the record already holds (under any name) is asked about first,
     * one question per such file (Rsx_Duplicate_Upload): Upload Again files it, Skip This
     * File leaves it unclaimed, and Cancel Upload never resends the call, so nothing in the
     * batch is attached. Nothing is written until every question is answered.
     *
     * @param array $params The endpoint's $params (the answers ride in _answers)
     * @param string[] $keys
     * @return \App\RSpade\Core\Response\Error_Response|null The response the endpoint returns
     *         instead of succeeding (a refused key, or the duplicate question), or null when
     *         the files were attached.
     */
    public static function claim(array $params, Rsx_Model_Abstract $record, array $keys)
    {
        $uploads = [];

        foreach ($keys as $key) {
            $attachment = is_string($key) ? File_Attachment_Model::find_by_key($key) : null;

            if (!$attachment || !$attachment->can_user_assign_this_file()) {
                return response_error(Ajax::ERROR_VALIDATION, 'A file could not be attached. Please upload it again.');
            }

            $uploads[] = $attachment;
        }

        $screen = Rsx_Duplicate_Upload::screen($params, $record, $uploads);
        if ($screen['question'] !== null) {
            return $screen['question'];
        }

        foreach ($screen['file'] as $attachment) {
            $attachment->add_to($record, self::CATEGORY);
        }

        return null;
    }

    /**
     * One attachment as the section and the preview dialog display it.
     *
     * @return array{file_attachment_id: int, file_name: string, file_type_label: ?string,
     *               size: int, created_at: ?string, created_by_name: ?string,
     *               download_url: string}
     */
    public static function row(File_Attachment_Model $attachment): array
    {
        $author = $attachment->get_created_by_author();

        return [
            'file_attachment_id' => (int) $attachment->id,
            'file_name' => $attachment->file_name,
            'file_type_label' => $attachment->file_type_label,
            'size' => (int) $attachment->get_size(),
            'created_at' => $attachment->created_at,
            'created_by_name' => $author['name'] ?? null,
            'download_url' => $attachment->get_download_url(),
        ];
    }
}
