<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Auth;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use Rsx\Emails\Portal_Invitation_Email;
use Rsx\Models\Client_Model;
use Rsx\Models\Portal_Invitation_Model;

/**
 * Portal_Request_Access_Controller - self-service "get into the portal" page for people
 * who don't have an account yet. The portal is invite-only, so this does NOT create an
 * account; it looks up the email and routes the visitor to the right next step:
 *
 *   - an ACTIVE invitation exists -> re-send their invitation email ("check your email").
 *   - they already have an account -> tell them to sign in.
 *   - neither                     -> tell them to contact the firm to be added.
 */
#[Auth('public')]
class Portal_Request_Access_Controller extends Rsx_Controller_Abstract
{
    #[Portal_Route('/request-access', methods: ['GET', 'POST'])]
    public static function index(Request $request, array $params = [])
    {
        // Already signed in -> straight to the dashboard.
        if (Portal_Session::is_logged_in()) {
            return redirect(Rsx_Portal::Route('Portal_Dashboard_Action'));
        }

        $brand = config('rsx.name', 'the firm');
        $error = null;
        $outcome = null;          // 'invited' | 'exists' | 'not_found'
        $posted_email = null;

        if ($request->isMethod('POST')) {
            // Human verification first: it gates the invitation/account lookup, and
            // therefore both enumeration and using this form to re-send invite emails.
            Rsx_Turnstile::validate($request);

            $posted_email = trim((string) $request->input('email'));

            if ($posted_email === '') {
                $error = 'Email address is required';
            } elseif (!filter_var($posted_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address';
            } else {
                // The site this portal serves (declared by Portal_Main::init()).
                $site_id = Portal_Session::get_site_id();

                // get_pending_for_email already filters to unused + unexpired invites.
                $invitations = Portal_Invitation_Model::get_pending_for_email($site_id, $posted_email);

                if ($invitations->isNotEmpty()) {
                    foreach ($invitations as $invitation) {
                        static::_resend_invitation($invitation, $brand);
                    }
                    $outcome = 'invited';
                } elseif (Portal_User_Model::find_by_email($site_id, $posted_email)) {
                    $outcome = 'exists';
                } else {
                    $outcome = 'not_found';
                }
            }
        }

        return rsx_view('Portal_Request_Access', [
            'brand' => $brand,
            'error' => $error,
            'outcome' => $outcome,
            'posted_email' => $posted_email,
        ]);
    }

    /**
     * Re-send an active invitation email (mirrors the staff-side new-account invite send).
     * Best-effort: a delivery failure must not leak which emails exist.
     */
    private static function _resend_invitation(Portal_Invitation_Model $invitation, string $brand): void
    {
        // Refresh the window so the re-sent link is comfortably valid.
        $invitation->refresh_expiry();

        $client = Client_Model::find((int) $invitation->get_metadata('client_id'));
        $portal_name = $client ? $client->name : $brand;

        (new Portal_Invitation_Email(
            $invitation->get_invitation_url(),
            $portal_name,
            false,
            $brand
        ))->to($invitation->email)->send();
    }
}
