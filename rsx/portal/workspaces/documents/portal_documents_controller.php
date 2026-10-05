<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Workspaces\Documents;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use Rsx\Models\Client_Model;
use Rsx\Models\Shared_Item_Model;
use Rsx\Portal_Permission;

/**
 * Portal_Documents_Controller - the portal-authed surface for the workspace
 * Documents tab. Returns the documents the firm has SHARED with the caller, within the
 * requested client (workspace).
 *
 * PER-CONTACT SHARING: a share is a Shared_Item_Model row for one document and one
 * contact, with an expiry. A portal user sees a document only while an UNEXPIRED share
 * names THEIR contact (Shared_Item_Model::find_valid_share) - the same rule the file gate
 * (rsx/handlers/Portal_File_Access_Handlers.php) applies to the bytes, so the list never
 * offers a link the download would refuse. The workspace scope (client) is taken from a
 * live-membership gate, never from a client-supplied user id.
 *
 * Authorization: the class-level #[Auth('is_logged_in')] gate (portal realm) admits
 * only a logged-in portal user. list() additionally fails closed for a client the
 * caller is not a member of via Portal_Permission::has_client_access().
 */
#[Auth('is_logged_in')]
class Portal_Documents_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax endpoint: the client's shared documents for this workspace.
     *
     * Fail-closed: a caller who is not a live member of the requested client is refused.
     * Returns every 'documents' attachment of the client shared with the caller's own
     * contact by an unexpired share; everything else is excluded.
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function list(Request $request, array $params = [])
    {
        $client_id = isset($params['client_id']) ? (int) $params['client_id'] : 0;
        if ($client_id <= 0) {
            return response_error(Ajax::ERROR_VALIDATION, 'Client ID is required');
        }

        // Membership gate (fail-closed).
        if (!Portal_Permission::has_client_access($client_id)) {
            return response_unauthorized();
        }

        $client = Client_Model::find($client_id);
        if (!$client) {
            return ['documents' => []];
        }
        // Firm label the recipient sees as the sharer (the workspace's own name).
        $shared_by_label = $client->name;

        // PER-CONTACT SHARING: a 'documents' attachment of this client is listed only
        // while an unexpired share names the caller's own contact. That share supplies
        // the shared-at/message.
        $contact_id = (int) (Portal_Permission::current_user()->contact_id ?? 0);

        $documents = [];
        foreach ($client->get_attachments('documents') as $attachment) {
            $share = Shared_Item_Model::find_valid_share('File_Attachment_Model', (int) $attachment->id, $contact_id);
            if (!$share) {
                continue; // not shared with this contact, or the share has expired
            }

            $documents[] = [
                'shared_item_id' => $share->id,
                'name' => $attachment->file_name,
                'attachment_id' => (int) $attachment->id,
                'download_url' => $attachment->get_download_url(),
                'shared_by' => $shared_by_label,
                'shared_at' => $share->created_at,
                'message' => $share->message,
            ];
        }

        return ['documents' => $documents];
    }
}
