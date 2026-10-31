<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Auth;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Login\Login_Notice;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Invitation_Model;
use Rsx\Models\Shared_Item_Model;

/**
 * Portal Share Link Controller
 *
 * The link a share email and the staff share dialog hand out. Public, because whoever follows
 * it is usually signed out - and it opens nothing by itself: it decides where the visitor goes
 * next, and the documents page's own rules still decide what they see.
 *
 * Portal access is by invitation, so a share is how most clients first meet the portal:
 *   - signed in as the recipient: the shared document's client's documents;
 *   - signed in as somebody else: the dashboard, saying so;
 *   - signed out, with an account: the sign-in page, with a notice;
 *   - signed out, no account, a live invitation: the accept-invite page;
 *   - signed out, no account, no live invitation: the sign-in page, with the invitation error;
 *   - a token that names no live share: the sign-in page, with the share error.
 * The sign-in and accept-invite hops carry this URL as the threaded redirect, so finishing
 * either lands back here, signed in.
 */
#[Auth('public')]
class Portal_Share_Link_Controller extends Rsx_Controller_Abstract
{
    #[Portal_Route('/shared/:token', methods: ['GET'])]
    public static function open(Request $request, array $params = [])
    {
        $share = Shared_Item_Model::find_by_token((string) ($params['token'] ?? ''));
        $contact = ($share && $share->is_valid()) ? Contact_Model::find($share->contact_id) : null;

        if (!$contact) {
            return redirect(Rsx_Portal::Route('Portal_Login_Controller::index', Login_Notice::params('share_expired')));
        }

        if (Portal_Session::is_logged_in()) {
            if ((int) Portal_Session::get_portal_user()->contact_id !== (int) $contact->id) {
                Flash_Alert::error('That link was shared with somebody else.');

                return redirect(Rsx_Portal::Route('Portal_Dashboard_Action'));
            }

            // View as Client is read-only, and a staff member looking is not the client opening it.
            if (!Portal_Session::is_impersonating()) {
                $share->mark_accessed();
            }

            return redirect(Rsx_Portal::Route('Portal_Workspace_Documents_Action', ['id' => $contact->client_id]));
        }

        $back_here = Login_Redirect::capture($request);

        if ($contact->resolve_portal_user()) {
            return redirect(Rsx_Portal::Route('Portal_Login_Controller::index', Login_Notice::params('sign_in_to_view') + $back_here));
        }

        $invitation = Portal_Invitation_Model::find_pending_for_contact_client((int) $contact->id, (int) $contact->client_id);

        if ($invitation) {
            return redirect(Rsx_Portal::Route('Portal_Register_Controller', ['code' => $invitation->invitation_code] + $back_here));
        }

        return redirect(Rsx_Portal::Route('Portal_Login_Controller::index', Login_Notice::params('invitation_expired') + $back_here));
    }
}
