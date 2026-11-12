<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Auth;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Lib\Portal\Portal_Session;
use App\RSpade\Lib\Portal\Portal_User_Model;
use App\RSpade\Lib\Portal\Rsx_Portal;
use App\RSpade\Lib\SignIn\Redirect\Login_Notice;
use App\RSpade\Lib\SignIn\Redirect\Login_Redirect;
use App\RSpade\Lib\SignIn\Turnstile\Rsx_Turnstile;
use App\RSpade\Lib\Ui\Flash\Flash_Alert;
use Rsx\Models\Portal_Invitation_Model;
use Rsx\Models\Portal_Membership_Model;
use Rsx\Portal\Auth\Portal_Login_Controller;

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
     * The invitation code is validated, and if valid, they can set their password. A code
     * that cannot be used goes to the sign-in page with the invitation error: there is no way
     * to create an account without a live invitation.
     */
    #[Portal_Route('/register', methods: ['GET', 'POST'])]
    public static function index(Request $request, array $params = [])
    {
        // If already logged in, honor any threaded intended-URL, else the dashboard.
        if (Portal_Session::is_logged_in()) {
            return redirect(Portal_Login_Controller::destination(Portal_Session::get_portal_user(), 0));
        }

        $code = $params['code'] ?? $request->input('code');
        $invitation = empty($code) ? null : Portal_Invitation_Model::find_by_code($code);

        // No code, an unknown one, or one minted for another tenant: an invitation belongs to
        // ONE site, and this portal serves ONE site (the one its URL names) - registering it
        // would create an account on this site holding another site's membership.
        if (!$invitation || (int) $invitation->site_id !== Portal_Session::get_site_id()) {
            return static::_redirect_to_login_invitation_invalid();
        }

        $client_id = (int) $invitation->get_metadata('client_id');

        // Revoked: the firm pulled the invite. Nothing to offer, whoever followed it.
        if ($invitation->is_revoked()) {
            return static::_redirect_to_login_invitation_invalid();
        }

        // An account already exists for this email -> route to login, and LEAVE THE INVITATION
        // PENDING. This is a GET (a mail scanner prefetching the link reaches it too), and
        // following a link is not accepting it: once signed in, the dashboard lists every
        // pending invitation addressed to the account and its Accept creates the membership
        // (Portal_Invitations_Controller::accept). Consuming it here would leave the person
        // with neither the membership nor the card that grants it.
        $existing_user = Portal_User_Model::find_by_email($invitation->site_id, $invitation->email);
        if ($existing_user) {
            return static::_redirect_to_login_account_exists($client_id);
        }

        // Used or expired with no account. Portal access is by invitation, so a dead one
        // offers no way to create an account: the firm issues a new one.
        if (!$invitation->is_valid()) {
            return static::_redirect_to_login_invitation_invalid();
        }

        $error = null;
        if ($request->isMethod('POST')) {
            // Human verification first: no account is created, and no invitation consumed,
            // until it passes.
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
                // The account, its membership, the used invitation and the sign-in are ONE
                // write section on the portal's site: all of them or none. This page route takes
                // no automatic lock; complete_sign_in() runs inside the section and reuses it.
                $destination = Site_Lock::write((int) $invitation->site_id, function () use ($invitation, $password, $client_id) {
                    $portal_user = new Portal_User_Model();
                    $portal_user->site_id = $invitation->site_id;
                    $portal_user->email = $invitation->email;
                    $portal_user->set_password($password);
                    // VERIFIED: the invitation reached this address, and its code came back.
                    $portal_user->is_verified = true;
                    $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
                    $portal_user->metadata = $invitation->metadata;

                    // Link the portal user to its CRM contact (the invitation carries it).
                    $contact_id = (int) $invitation->get_metadata('contact_id');
                    if ($contact_id) {
                        $portal_user->contact_id = $contact_id;
                    }

                    $portal_user->save();

                    if ($client_id) {
                        $role_id = (int) ($invitation->get_metadata('role_id')
                            ?: Portal_Membership_Model::ROLE_VIEWER);

                        if (!Portal_Membership_Model::has_membership_row($portal_user->id, $client_id)) {
                            $membership = new Portal_Membership_Model();
                            $membership->site_id = $invitation->site_id;
                            $membership->portal_user_id = $portal_user->id;
                            $membership->client_id = $client_id;
                            $membership->role_id = $role_id;
                            $membership->save();
                        }
                    }

                    $invitation->mark_used();

                    // Signed in through the one completion function every portal entrance
                    // finishes in: the session is created for the site already declared for this
                    // request (which the invitation matches - checked above), last_login is
                    // stamped and the success recorded. It lands on a threaded redirect (the shared
                    // document this invitation came from), else the invited client. No emailed sign-in code is asked for
                    // here even when rsx.portal.emailed_sign_in_codes is on: the invitation that
                    // brought them here already proved the address.
                    Flash_Alert::success('Your account has been created.');

                    return Portal_Login_Controller::complete_sign_in(
                        $portal_user,
                        sign_in: true,
                        client_id: $client_id
                    );
                }, 'Registered a portal account from an invitation');

                return redirect($destination);
            }
        }

        return rsx_view('Portal_Register_Index', [
            'invitation' => $invitation,
            'error' => $error,
        ]);
    }

    /**
     * Redirect a returning contact to the portal login, flagged so the login page
     * shows the "you already have an account" message, and carrying the invited
     * client so a successful login can land there (if they have access). The invitation
     * itself is untouched: it waits on the dashboard for the signed-in account to accept.
     */
    private static function _redirect_to_login_account_exists(int $client_id)
    {
        $params = Login_Notice::params('account_exists') + Login_Redirect::params();
        if ($client_id) {
            $params['client'] = $client_id;
        }

        return redirect(Rsx_Portal::Route('Portal_Login_Controller::index', $params));
    }

    /**
     * Send the follower of an invitation that cannot be used - unknown, revoked, used or
     * expired - to the portal login with the invitation error.
     */
    private static function _redirect_to_login_invitation_invalid()
    {
        return redirect(Rsx_Portal::Route(
            'Portal_Login_Controller::index',
            Login_Notice::params('invitation_expired') + Login_Redirect::params()
        ));
    }
}
