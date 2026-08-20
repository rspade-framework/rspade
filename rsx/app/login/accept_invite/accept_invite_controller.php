<?php

namespace Rsx\App\Login\AcceptInvite;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Login\Invite_Helper;

/**
 * Accept_Invite_Controller
 *
 * Handles all invite acceptance workflow for new and existing users
 * Routes:
 * - /accept-invite?code={code} - Main invitation page
 * - /accept-invite/create-account?code={code} - Create account from invitation
 *
 * Public by design: an invitee follows the link before they have an account. The
 * inline Session::is_logged_in() checks below are FLOW logic (which state of the
 * page to render, or which account to attach the invitation to), not gates - the
 * invitation code is the authorization for this surface.
 */
#[Auth('public')]
class Accept_Invite_Controller extends Rsx_Controller_Abstract
{
    /**
     * Display invite acceptance page with appropriate state
     *
     * States:
     * - Invalid/expired invite: Show error message
     * - Valid invite, not logged in: Show links to signup or login
     * - Valid invite, logged in: Show accept button
     * - Already accepted: Show "go to dashboard" link
     */
    #[Route('/accept-invite', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        $code = $params['code'] ?? null;

        if (empty($code)) {
            return rsx_view('Accept_Invite', [
                'state' => 'invalid',
                'message' => 'No invitation code provided',
            ]);
        }

        // Validate invitation using helper
        $validation = Invite_Helper::validate_invitation($code);

        // Handle validation errors
        if (!$validation['valid']) {
            $error_type = $validation['error_type'];

            // Email mismatch - special handling
            if ($error_type === 'email_mismatch') {
                return rsx_view('Accept_Invite', [
                    'state' => 'email_mismatch',
                    'invitation' => $validation['invitation'],
                    'site_name' => $validation['invitation']->site ? $validation['invitation']->site->name : 'the account',
                    'invited_email' => $validation['invited_email'],
                    'current_email' => $validation['current_email'],
                    'code' => $code,
                ]);
            }

            // Already accepted
            if ($error_type === 'already_accepted') {
                $invitation = $validation['invitation'];
                $login_user = Session::get_login_user();
                $is_current_user = $login_user && $login_user->id === $invitation->login_user_id;

                return rsx_view('Accept_Invite', [
                    'state' => 'already_accepted',
                    'invitation' => $invitation,
                    'site_name' => $invitation->site ? $invitation->site->name : 'the account',
                    'is_current_user' => $is_current_user,
                ]);
            }

            // Other errors (not found, expired)
            return rsx_view('Accept_Invite', [
                'state' => $error_type === 'expired' ? 'expired' : 'invalid',
                'message' => $validation['error'],
            ]);
        }

        // Valid invite - check login status
        $invitation = $validation['invitation'];
        $is_logged_in = Session::is_logged_in();

        // Check if login account exists for invited email (auto-detect)
        $login_account_exists = Login_User_Model::where('email', $invitation->email)->exists();

        return rsx_view('Accept_Invite', [
            'state' => $is_logged_in ? 'logged_in' : 'not_logged_in',
            'invitation' => $invitation,
            'site_name' => $invitation->site ? $invitation->site->name : 'the account',
            'code' => $code,
            'login_account_exists' => $login_account_exists,
        ]);
    }

    /**
     * Show create account form for invited users
     */
    #[Route('/accept-invite/create-account', methods: ['GET'])]
    public static function create_account(Request $request, array $params = [])
    {
        $invite_code = $params['code'] ?? null;

        if (empty($invite_code)) {
            Flash_Alert::error('No invitation code provided.');

            return redirect(Rsx::Route('Login_Controller'));
        }

        // Validate invitation
        $validation = Invite_Helper::validate_invitation($invite_code);

        if (!$validation['valid']) {
            // Check if it's an email mismatch (logged in with wrong email)
            if ($validation['error_type'] === 'email_mismatch') {
                Flash_Alert::error(
                    "This invitation was sent to {$validation['invited_email']} but you are logged in as {$validation['current_email']}. " .
                    'Please logout and create your account with the invited email address.'
                );

                return redirect(Rsx::Route('Login_Controller'));
            }

            // Other validation errors
            Flash_Alert::error($validation['error']);

            return redirect(Rsx::Route('Login_Controller'));
        }

        $invitation = $validation['invitation'];

        // Check if user account already exists for this invitation
        $login_account_exists = Login_User_Model::where('email', $invitation->email)->exists();

        if ($login_account_exists) {
            // Redirect back to main accept invite page to handle already-existing account
            return redirect(Rsx::Route('Accept_Invite_Controller::index', ['code' => $invite_code]));
        }

        return rsx_view('Create_Account', [
            'invite_code' => $invite_code,
            'invitation' => $invitation,
        ]);
    }

    /**
     * Process create account form submission
     */
    #[Ajax_Endpoint]
    public static function create_account_submit(Request $request, array $params = [])
    {
        // Extract form data from POST body
        $email = trim($request->input('email', ''));
        $first_name = trim($request->input('first_name', ''));
        $last_name = trim($request->input('last_name', ''));
        $password = $request->input('password', '');
        $password_confirm = $request->input('password_confirm', '');
        $invite_code = trim($request->input('invite_code', ''));

        // Validation
        $errors = [];

        // Invite code validation (required for this route)
        if (empty($invite_code)) {
            $errors['invite_code'] = 'Invitation code is required';
        } else {
            $validation = Invite_Helper::validate_invitation($invite_code);

            if (!$validation['valid']) {
                // Handle email mismatch specially
                if ($validation['error_type'] === 'email_mismatch') {
                    return response_error(Ajax::ERROR_VALIDATION, [
                        'message' => "This invitation was sent to {$validation['invited_email']} but you are logged in as {$validation['current_email']}. " .
                        'Please logout and create your account with the invited email address.'
                    ]);
                }

                return response_error(Ajax::ERROR_VALIDATION, ['message' => $validation['error']]);
            }

            $invitation = $validation['invitation'];

            // Verify email matches invitation email
            if (!Invite_Helper::can_email_accept_invitation($invitation, $email)) {
                $errors['email'] = "Email must match the invited email address: {$invitation->email}";
            }
        }

        // Email validation
        if (empty($email)) {
            $errors['email'] = 'Email address is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        } else {
            // Check if email already exists
            $existing = Login_User_Model::where('email', $email)->first();
            if ($existing) {
                $errors['email'] = 'An account with this email already exists';
            }
        }

        // Name validation
        if (empty($first_name)) {
            $errors['first_name'] = 'First name is required';
        }

        if (empty($last_name)) {
            $errors['last_name'] = 'Last name is required';
        }

        // Password validation
        if (empty($password)) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        if ($password !== $password_confirm) {
            $errors['password_confirm'] = 'Passwords do not match';
        }

        // Return validation errors
        if (!empty($errors)) {
            return response_error(Ajax::ERROR_VALIDATION, $errors);
        }

        // Re-validate invitation one final time before creating account
        $final_validation = Invite_Helper::validate_invitation($invite_code);
        if (!$final_validation['valid']) {
            return response_error(Ajax::ERROR_VALIDATION, [
                'message' => 'This invitation is no longer active. Please contact the site administrator for a new invitation.'
            ]);
        }

        $invitation = $final_validation['invitation'];

        // Create login_user record
        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make($password);
        $login_user->is_verified = 0; // Unverified until email confirmation
        $login_user->is_activated = 1; // Activated by default
        $login_user->save();

        // Find the user record from the invitation (bypassing site scope since we're not logged in yet)
        $user = User_Model::without_site_scope(function () use ($invitation) {
            return User_Model::where('invite_code', $invitation->invite_code)
                ->where('site_id', $invitation->site_id)
                ->first();
        });

        if (!$user) {
            return response_error(Ajax::ERROR_FATAL, 'User record not found for this invitation. Please contact support.');
        }

        // Update user record with login info and name (bypassing site scope)
        User_Model::without_site_scope(function () use ($user, $login_user, $first_name, $last_name) {
            $user->login_user_id = $login_user->id;
            $user->first_name = $first_name;
            $user->last_name = $last_name;
            $user->invite_accepted_at = now();
            $user->save();
        });

        // Link invitation to new user and mark as accepted
        $invitation->login_user_id = $login_user->id;
        $invitation->invite_accepted_at = now();
        $invitation->save();

        // TODO: In the future, this will redirect to a "verify your email" page
        // For now, auto-login the user and redirect to success page

        // Log in the user and set their site
        Session::set_login_user_id($login_user->id);
        Session::set_site_id($invitation->site_id);

        // Return redirect to success page
        return [
            'redirect' => Rsx::Route('Accept_Invite_Controller::success'),
        ];
    }

    /**
     * Success page after account creation
     */
    #[Route('/accept-invite/success', methods: ['GET'])]
    public static function success(Request $request, array $params = [])
    {
        $site_id = Session::get_site_id();
        $site = \Site_Model::find($site_id);

        return rsx_view('Accept_Invite_Success', [
            'site_name' => $site ? $site->name : 'the site',
        ]);
    }

    /**
     * Accept invitation
     * Links the current logged-in user to the site and marks invitation as accepted
     */
    #[Ajax_Endpoint]
    public static function accept(Request $request, array $params = [])
    {
        $code = $request->input('code');

        if (empty($code)) {
            return response_error(Ajax::ERROR_VALIDATION, ['message' => 'No invitation code provided']);
        }

        // Validate invitation using helper
        $validation = Invite_Helper::validate_invitation($code);

        if (!$validation['valid']) {
            return response_error(Ajax::ERROR_VALIDATION, ['message' => $validation['error']]);
        }

        $invitation = $validation['invitation'];

        // Get current login user
        $login_user = Session::get_login_user();

        if (!$login_user) {
            return response_error(Ajax::ERROR_AUTH_REQUIRED, 'You must be logged in to accept this invitation');
        }

        // Verify email match (double-check security)
        if (!Invite_Helper::can_email_accept_invitation($invitation, $login_user->email)) {
            return response_error(Ajax::ERROR_VALIDATION, [
                'message' => "This invitation was sent to {$invitation->email} but you are logged in as {$login_user->email}. " .
                'Please logout and login with the invited email address.'
            ]);
        }

        // Link login_user_id to the invitation record and mark as accepted
        $invitation->login_user_id = $login_user->id;
        $invitation->invite_accepted_at = now();
        $invitation->save();

        // Set session site to the invitation's site
        Session::set_site_id($invitation->site_id);

        return [
            'redirect_url' => Rsx::Route('Site_Selection_Controller::select', $invitation->site_id),
        ];
    }
}
