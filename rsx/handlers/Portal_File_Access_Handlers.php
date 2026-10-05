<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Handlers;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Rsx_Portal;
use Rsx\Models\Portal_Request_Thread_Model;
use Rsx\Models\Shared_Item_Model;
use Rsx\Portal_Permission;

/**
 * Portal_File_Access_Handlers
 *
 * Authorization gates for serving file attachments (`/_thumbnail/...`, `/_inline/...`,
 * `/_download/...`, previews, extracted text). The File subsystem fires
 * `file.thumbnail.authorize` (thumbnails) and BOTH `file.thumbnail.authorize` +
 * `file.download.authorize` (inline/download) as GATES: every handler must return true,
 * and the first non-true result denies. The gates FAIL CLOSED when nothing is registered
 * (Rsx_File_Gates), so this class is what makes files readable at all.
 *
 * THE REALM OF THE REQUEST DECIDES WHO IS ASKING, never whichever identity happens to be
 * on the session. One browser can hold a staff identity and a portal identity on one
 * session row, so a portal-realm file URL (`/_portal/_download/...`) is answered for the
 * PORTAL identity only, and a staff-realm URL for the STAFF identity only. The identity
 * comes from the gate's own `user` key, which the framework fills realm-honestly.
 *
 *   1. STAFF realm  - an ACTIVE staff membership (User_Model::is_active(): the membership
 *                     and its site enabled and not deleted). Staff manage the files.
 *   2. PORTAL realm - the signed-in portal user, and only for:
 *        (a) a client 'documents' file SHARED WITH THEIR OWN CONTACT by an unexpired
 *            share (Shared_Item_Model::find_valid_share), of a client they hold a LIVE
 *            membership of (portal enabled, client not deleted); or
 *        (b) an 'attachment' on a request thread of a client they hold a live
 *            membership of.
 *
 * Anyone else - anonymous, a disabled staff member, a staff session on a portal URL, a
 * portal user outside the client, a document shared with somebody else, an expired
 * share - is denied with a 403. The same logic is registered on BOTH gates so a reader
 * can load the thumbnail AND download/inline.
 */
class Portal_File_Access_Handlers
{
    /**
     * Gate thumbnail access (also the first gate checked for inline/download).
     *
     * @param array $data ['attachment' => File_Attachment_Model, 'user' => User_Model|Portal_User_Model|null, 'request' => Request]
     * @return true|\Illuminate\Http\JsonResponse
     */
    #[OnEvent('file.thumbnail.authorize', priority: 10)]
    public static function authorize_thumbnail($data)
    {
        return static::_authorize($data);
    }

    /**
     * Gate download/inline access (the second gate, after thumbnail).
     *
     * @param array $data ['attachment' => File_Attachment_Model, 'user' => User_Model|Portal_User_Model|null, 'request' => Request]
     * @return true|\Illuminate\Http\JsonResponse
     */
    #[OnEvent('file.download.authorize', priority: 10)]
    public static function authorize_download($data)
    {
        return static::_authorize($data);
    }

    /**
     * Shared gate logic, forked on the realm of the request.
     *
     * @param array $data The gate payload.
     * @return true|\Illuminate\Http\JsonResponse
     */
    private static function _authorize(array $data)
    {
        $attachment = $data['attachment'] ?? null;
        $user = $data['user'] ?? null;

        if (Rsx_Portal::is_portal_request()) {
            if ($user instanceof Portal_User_Model
                && $attachment instanceof File_Attachment_Model
                && (static::_portal_user_can_read_client_document($user, $attachment)
                    || static::_portal_user_can_read_thread_attachment($attachment))) {
                return true;
            }
        } elseif ($user instanceof User_Model && $user->is_active()) {
            // An active staff membership manages the files - sufficient on its own.
            return true;
        }

        return response()->json([
            'success' => false,
            'error' => 'Not authorized to access this file',
        ], 403);
    }

    /**
     * Whether this portal user may read this client document: a 'documents' attachment of a
     * client they hold a live membership of, shared with THEIR contact by a share that has
     * not expired.
     *
     * @param Portal_User_Model $portal_user
     * @param File_Attachment_Model $attachment
     * @return bool
     */
    private static function _portal_user_can_read_client_document(Portal_User_Model $portal_user, File_Attachment_Model $attachment): bool
    {
        // Must be a 'documents' attachment of a Client.
        if ((string) $attachment->fileable_type !== 'Client_Model'
            || (string) $attachment->fileable_category !== 'documents') {
            return false;
        }

        // The portal user must hold a live membership of that client.
        if (!Portal_Permission::has_client_access((int) $attachment->fileable_id)) {
            return false;
        }

        // And the document must be shared with this user's own contact, unexpired.
        return Shared_Item_Model::find_valid_share(
            'File_Attachment_Model',
            (int) $attachment->id,
            (int) $portal_user->contact_id
        ) !== null;
    }

    /**
     * Whether the current portal user may read this request-thread attachment: the file is
     * an 'attachment' File_Attachment of a Portal_Request_Thread_Model on a thread whose
     * client the user holds a live membership of. Request threads are client-level, so any
     * member of the thread's client may read its documents (review state is enforced
     * separately; read access is membership-based).
     *
     * @param File_Attachment_Model $attachment
     * @return bool
     */
    private static function _portal_user_can_read_thread_attachment(File_Attachment_Model $attachment): bool
    {
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
