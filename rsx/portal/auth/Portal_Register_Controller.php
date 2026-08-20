<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Auth;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\Models\Portal_Invitation_Model;
use Rsx\Models\Portal_Membership_Model;

/**
 * Portal Register Controller
 *
 * Handles portal user registration via invitation code.
 * Portal registration is invite-only - no public signup.
 */
#[Auth('public')]
class Portal_Register_Controller extends Rsx_Controller_Abstract
{
    /**
     * Show registration form and handle registration
     *
     * Users arrive here by clicking a link in their invitation email.
     * The invitation code is validated, and if valid, they can set their password.
     */
    #[Portal_Route('/register', methods: ['GET', 'POST'])]
    public static function index(Request $request, array $params = [])
    {
        // If already logged in, honor any threaded intended-URL, else the dashboard.
        if (Portal_Session::is_logged_in()) {
            return redirect(Login_Redirect::consume(Rsx_Portal::Route('Portal_Dashboard_Action')));
        }

        $code = $params['code'] ?? $request->input('code');

        // Genuinely bogus links: no code / unknown code.
        if (empty($code)) {
            return rsx_view('Portal_Register_Invalid', [
                'error' => 'No invitation code provided. Please use the link from your invitation email.',
            ]);
        }

        $invitation = Portal_Invitation_Model::find_by_code($code);
        if (!$invitation) {
            return rsx_view('Portal_Register_Invalid', [
                'error' => 'Invalid invitation code. Please contact the firm for a new invitation.',
            ]);
        }

        // An invitation belongs to ONE site, and this portal serves ONE site (the
        // one Portal_Main::init() declared). A code minted for another tenant is
        // not a valid code HERE - registering it would create an account on this
        // site holding another site's membership.
        if ((int) $invitation->site_id !== Portal_Session::get_site_id()) {
            return rsx_view('Portal_Register_Invalid', [
                'error' => 'Invalid invitation code. Please contact the firm for a new invitation.',
            ]);
        }

        $client_id = (int) $invitation->get_metadata('client_id');

        // Revoked is the only hard "cancelled" state (the firm pulled the invite).
        if ($invitation->is_revoked()) {
            return rsx_view('Portal_Register_Cancelled');
        }

        // An account already exists for this email -> route to login (never a dead
        // "invalid" page). Consume a still-live invite so it stops re-appearing.
        $existing_user = Portal_User_Model::find_by_email($invitation->site_id, $invitation->email);
        if ($existing_user) {
            if ($invitation->is_valid()) {
                $invitation->mark_used();
            }
            return static::_redirect_to_login_account_exists($client_id);
        }

        // Consumed with no account (e.g. a declined invite) -> also send to login.
        if ($invitation->is_used()) {
            return static::_redirect_to_login_account_exists($client_id);
        }

        // Expired with no account: offer account-only creation (an EXPIRED invite
        // does NOT grant client access - the admin must re-invite for that). The
        // "expired" page's Create Account button re-enters here with allow_expired.
        $allow_expired = !empty($params['allow_expired']) || $request->boolean('allow_expired');
        if ($invitation->is_expired()) {
            if (!$allow_expired) {
                return rsx_view('Portal_Register_Expired', [
                    'code' => $invitation->invitation_code,
                ]);
            }
        }

        // Live PENDING invite grants the invited client membership; an expired
        // invite completed via the account-only path does not.
        $grant_membership = $invitation->is_valid();

        $error = null;
        if ($request->isMethod('POST')) {
            // Human verification first, covering BOTH completion paths below (the live
            // invite and the expired account-only one) - no account is created, and no
            // invitation consumed, until it passes.
            Rsx_Turnstile::validate($request);

            $password = $request->input('password');
            $password_confirm = $request->input('password_confirm');
            $min_length = config('rsx.portal.password_min_length', 8);

            if (empty($password)) {
                $error = 'Password is required';
            } elseif (strlen($password) < $min_length) {
                $error = "Password must be at least {$min_length} characters";
            } elseif ($password !== $password_confirm) {
                $error = 'Passwords do not match';
            } else {
                $portal_user = new Portal_User_Model();
                $portal_user->site_id = $invitation->site_id;
                $portal_user->email = $invitation->email;
                $portal_user->set_password($password);
                $portal_user->is_verified = true; // Verified through invitation
                $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
                $portal_user->metadata = $invitation->metadata;

                // Link the portal user to its CRM contact (the invitation carries it).
                $contact_id = (int) $invitation->get_metadata('contact_id');
                if ($contact_id) {
                    $portal_user->contact_id = $contact_id;
                }

                $portal_user->save();

                // Only a live invite JOINS the invited client. An expired invite
                // yields an account with no client access (admin re-invites).
                if ($grant_membership && $client_id) {
                    $role_id = (int) ($invitation->get_metadata('role_id')
                        ?: Portal_Membership_Model::ROLE_VIEWER);

                    if (!Portal_Membership_Model::has_membership($portal_user->id, $client_id)) {
                        $membership = new Portal_Membership_Model();
                        $membership->site_id = $invitation->site_id;
                        $membership->portal_user_id = $portal_user->id;
                        $membership->client_id = $client_id;
                        $membership->role_id = $role_id;
                        $membership->save();
                    }
                }

                $invitation->mark_used();

                // No site argument: the session is created for the site already
                // declared for this request (which the invitation matches - checked
                // above).
                Portal_Session::set_portal_user_id($portal_user->id);
                $portal_user->touch_last_login();

                Flash_Alert::success('Your account has been created. Welcome to the Client Portal!');

                // Land on the invited client when we granted access, else dashboard.
                return redirect(static::_post_auth_destination($portal_user, $grant_membership ? $client_id : 0));
            }
        }

        return rsx_view('Portal_Register_Index', [
            'invitation' => $invitation,
            'error' => $error,
            'allow_expired' => $invitation->is_expired(),
        ]);
    }

    /**
     * Redirect a returning contact to the portal login, flagged so the login page
     * shows the "you already have an account" message, and carrying the invited
     * client so a successful login can land there (if they have access).
     */
    private static function _redirect_to_login_account_exists(int $client_id)
    {
        $params = ['message' => 'account_exists'];
        if ($client_id) {
            $params['client'] = $client_id;
        }

        return redirect(Rsx_Portal::Route('Portal_Login_Controller::index', $params));
    }

    /**
     * Post-auth destination: the invited client's workspace when the user has
     * access to it, otherwise the intended-URL threaded through the flow, else the
     * dashboard. Shared shape with the login flow.
     *
     * The flow-owned invited-client destination WINS over the threaded redirect;
     * consume() supplies the default only. See: php artisan rsx:man login_redirect.
     */
    private static function _post_auth_destination(Portal_User_Model $portal_user, int $client_id): string
    {
        if ($client_id && Portal_Membership_Model::has_membership($portal_user->id, $client_id)) {
            return Rsx_Portal::Route('Portal_Workspace_Overview_Action', $client_id);
        }

        return Login_Redirect::consume(Rsx_Portal::Route('Portal_Dashboard_Action'));
    }
}
