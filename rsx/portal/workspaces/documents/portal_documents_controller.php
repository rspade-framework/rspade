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
 * Documents tab. Returns the documents the firm has SHARED with the requested
 * client (workspace).
 *
 * CLIENT-LEVEL SHARING (current model): a document is shared with the CLIENT, not an
 * individual - once shared it is visible to EVERY portal user of that client. A shared
 * document is a 'documents' File_Attachment of the client that has at least one
 * Shared_Item_Model row (item_type='File_Attachment_Model'); the per-contact rows only
 * record who was specifically invited + emailed. The workspace scope (client) is taken
 * from a membership gate, never from a client-supplied user id. End-user applications
 * will likely replace this with a more granular per-document / per-contact model.
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
     * Fail-closed: a caller who is not a member of the requested client gets an empty
     * list. Returns every 'documents' attachment of the client that has been shared
     * with the client (has at least one Shared_Item); uploaded-but-unshared documents
     * are excluded.
     */
    #[Ajax_Endpoint]
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

        // CLIENT-LEVEL SHARING: every 'documents' attachment of this client that has
        // been shared with the client (any Shared_Item exists) is visible to all of the
        // client's portal users. The most recent share supplies the shared-at/message.
        $documents = [];
        foreach ($client->get_attachments('documents') as $attachment) {
            $share = Shared_Item_Model::where('item_type', 'File_Attachment_Model')
                ->where('item_id', $attachment->id)
                ->orderBy('created_at', 'desc')
                ->first();
            if (!$share) {
                continue; // uploaded but not yet shared with the client
            }

            $documents[] = [
                'shared_item_id' => $share->id,
                'name' => $attachment->file_name,
                'thumbnail_url' => $attachment->get_thumbnail_url('fit', 120, 120),
                'download_url' => $attachment->get_download_url(),
                'shared_by' => $shared_by_label,
                'shared_at' => $share->created_at,
                'message' => $share->message,
            ];
        }

        return ['documents' => $documents];
    }
}
