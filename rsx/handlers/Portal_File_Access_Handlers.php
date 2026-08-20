<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Handlers;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use Rsx\Models\Portal_Request_Thread_Model;
use Rsx\Models\Shared_Item_Model;
use Rsx\Portal_Permission;

/**
 * Portal_File_Access_Handlers
 *
 * Authorization gates for serving file attachments (`/_thumbnail/...`, `/_inline/...`,
 * `/_download/...`). The File subsystem fires `file.thumbnail.authorize` (thumbnails)
 * and BOTH `file.thumbnail.authorize` + `file.download.authorize` (inline/download)
 * as GATES: every handler must return true, and the first non-true result denies.
 *
 * Two audiences may read a file:
 *   1. STAFF  - any logged-in internal user (the existing behavior; staff manage the
 *               files, so a staff session is sufficient).
 *   2. PORTAL - a logged-in portal user who is a member of the client the file was
 *               shared with. Documents are shared at the CLIENT level: a 'documents'
 *               File_Attachment of a client that has been shared with the client (any
 *               Shared_Item_Model row exists for it) is readable by every portal user
 *               of that client. Membership is the boundary, not an individual share.
 *
 * Anyone else (anonymous, or a portal user who is not a member of the file's client, or
 * a document not shared with the client) is denied with a 403. The same logic is
 * registered on BOTH gates so a member can load the thumbnail AND download/inline.
 *
 * Fail-closed: no session of either kind, no membership, or an unshared document -> deny.
 */
class Portal_File_Access_Handlers
{
    /**
     * Gate thumbnail access (also the first gate checked for inline/download).
     *
     * @param array $data ['attachment' => File_Attachment_Model, 'user' => User|null, 'request' => Request]
     * @return true|\Illuminate\Http\JsonResponse
     */
    #[OnEvent('file.thumbnail.authorize', priority: 10)]
    public static function authorize_thumbnail($data)
    {
        return static::_authorize($data['attachment'] ?? null);
    }

    /**
     * Gate download/inline access (the second gate, after thumbnail).
     *
     * @param array $data ['attachment' => File_Attachment_Model, 'user' => User|null, 'request' => Request]
     * @return true|\Illuminate\Http\JsonResponse
     */
    #[OnEvent('file.download.authorize', priority: 10)]
    public static function authorize_download($data)
    {
        return static::_authorize($data['attachment'] ?? null);
    }

    /**
     * Shared gate logic: allow staff, allow a valid portal share recipient, else deny.
     *
     * @param File_Attachment_Model|null $attachment
     * @return true|\Illuminate\Http\JsonResponse
     */
    private static function _authorize($attachment)
    {
        // A logged-in staff user manages the files - sufficient on its own.
        if (Session::is_logged_in()) {
            return true;
        }

        // Otherwise, only a portal user with a valid claim on the file may read it:
        //   (a) the file was SHARED with them (an unexpired Shared_Item), or
        //   (b) the file is a request THREAD attachment (firm <-> client conversation)
        //       on a thread whose client they are a member of.
        if ($attachment instanceof File_Attachment_Model
            && (static::_portal_user_can_read_client_document($attachment)
                || static::_portal_user_can_read_thread_attachment($attachment))) {
            return true;
        }

        return response()->json([
            'success' => false,
            'error' => 'Not authorized to access this file',
        ], 403);
    }

    /**
     * Whether the current portal user may read this client document under the
     * client-level sharing model: the attachment is a 'documents' File_Attachment of a
     * client the user is a member of, and that document has been shared with the client
     * (at least one Shared_Item exists for it).
     *
     * @param File_Attachment_Model $attachment
     * @return bool
     */
    private static function _portal_user_can_read_client_document(File_Attachment_Model $attachment): bool
    {
        if (!Portal_Session::is_logged_in()) {
            return false;
        }

        // Must be a 'documents' attachment of a Client.
        if ((string) $attachment->fileable_type !== 'Client_Model'
            || (string) $attachment->fileable_category !== 'documents') {
            return false;
        }

        // The portal user must be a member of that client.
        if (!Portal_Permission::has_client_access((int) $attachment->fileable_id)) {
            return false;
        }

        // And the document must have been shared with the client.
        return Shared_Item_Model::where('item_type', 'File_Attachment_Model')
            ->where('item_id', $attachment->id)
            ->exists();
    }

    /**
     * Whether the current portal user may read this request-thread attachment: the file is
     * an 'attachment' File_Attachment of a Portal_Request_Thread_Model on a thread whose
     * client the user is a member of. Request threads are client-level, so any member of
     * the thread's client may read its documents (review state is enforced separately;
     * read access is membership-based).
     *
     * Fail-closed: requires a logged-in portal user, the attachment to be a thread
     * 'attachment', the thread to exist, and membership of that thread's client.
     *
     * @param File_Attachment_Model $attachment
     * @return bool
     */
    private static function _portal_user_can_read_thread_attachment(File_Attachment_Model $attachment): bool
    {
        if (!Portal_Session::is_logged_in()) {
            return false;
        }

        // Must be an 'attachment' file on a Portal_Request_Thread_Model.
        if ((string) $attachment->fileable_type !== 'Portal_Request_Thread_Model'
            || (string) $attachment->fileable_category !== 'attachment') {
            return false;
        }

        $thread = Portal_Request_Thread_Model::find($attachment->fileable_id);
        if (!$thread) {
            return false;
        }

        return Portal_Permission::has_client_access((int) $thread->client_id);
    }
}
