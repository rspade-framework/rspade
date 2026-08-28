<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Login\Signup;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Login\Invite_Helper;

/**
 * Signup Controller - User Registration System
 *
 * GOALS AND REQUIREMENTS:
 *
 * 1. THEME & BUNDLE
 *    - Uses Login_Layout for centered card display
 *    - Part of Login_Bundle
 *    - Lives in /rsx/app/login/signup/ directory
 *
 * 2. ROUTING
 *    - Available at /signup
 *    - Open with no restriction for development
 *    - Togglable via config: open signup vs invite-only
 *
 * 3. INVITE CODE HANDLING
 *    - If invite-only mode, valid invite code must be in URL
 *    - Invite code passed as query parameter: /signup?code=ABC123
 *    - This is NOT the accept invite URL (that's separate)
 *    - Accept invite page will forward to signup with code if user clicks create account
 *    - Accept invite page may detect if user already logged in
 *
 * 4. INVITE CODE VALIDATION (when required)
 *    - Code must exist in user_invitations table
 *    - Must not be expired (invite_expires_at > now)
 *    - Must not be already accepted (invite_accepted_at is null)
 *    - NOTE: No site check - user doesn't have a site until they sign in
 *
 * 5. FORM FIELDS
 *    - Email address (required)
 *    - First name (required)
 *    - Last name (required)
 *    - Phone number (optional)
 *    - Password (required)
 *    - Confirm password (required)
 *
 * 6. SIGNUP ACTION
 *    - Creates login_user record with is_verified = 0 (unverified)
 *    - Uses Rsx_Form pattern (like edit contact CRUD)
 *    - Ajax endpoint for validation and response
 *    - On success: Show alert with created record as JSON string
 *    - Will expand on email verification flow later
 *
 * 7. FORM PATTERN
 *    - Follows edit contact CRUD pattern
 *    - Uses Rsx_Form component with Ajax endpoint
 *    - Validation errors displayed inline
 *    - Uses exception-driven Ajax error handling
 *
 * Public by design: registration is reachable by anonymous visitors (invite-code
 * validation inside the body is the record layer, not a gate).
 */
#[Auth('public')]
class Signup_Controller extends Rsx_Controller_Abstract
{
    /**
     * Show signup form
     */
    #[Route('/signup', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        // Check if registration is enabled
        $signup_mode = config('rsx.auth.signup_mode', 'invite_only');
        $invite_code = $params['code'] ?? null;

        // If signup disabled, reject all registrations
        if ($signup_mode === 'disabled') {
            Flash_Alert::error('Registration is currently disabled.');

            return redirect(Rsx::Route('Login_Controller'));
        }

        // If invite-only mode, require invite code
        if ($signup_mode === 'invite_only' && !$invite_code) {
            Flash_Alert::error('Registration requires an invitation code.');

            return redirect(Rsx::Route('Login_Controller'));
        }

        // If invite code provided, validate it
        $invitation = null;
        if ($invite_code) {
            $validation = Invite_Helper::validate_invitation($invite_code);

            if (!$validation['valid']) {
                // Check if it's an email mismatch (logged in with wrong email)
                if ($validation['error_type'] === 'email_mismatch') {
                    Flash_Alert::error(
                        "This invitation was sent to {$validation['invited_email']} but you are logged in as {$validation['current_email']}. " .
                        'Please logout and signup with the invited email address.'
                    );

                    return redirect(Rsx::Route('Login_Controller'));
                }

                // Other validation errors
                Flash_Alert::error($validation['error']);

                return redirect(Rsx::Route('Login_Controller'));
            }

            $invitation = $validation['invitation'];
        }

        return rsx_view('Signup_Index', [
            'invite_code' => $invite_code,
            'invitation' => $invitation,
        ]);
    }

    /**
     * Process signup form submission
     */
    #[Ajax_Endpoint]
    public static function submit(Request $request, array $params = [])
    {
        // Human verification first: it gates the email-exists lookup below, and
        // therefore account enumeration, before any record work happens. $params is
        // passed because this transport delivers the field there (a batch sub-call
        // carries the envelope, not the field, on the Request).
        Rsx_Turnstile::validate($request, $params);

        // Extract form data
        $email = trim($params['email'] ?? '');
        $first_name = trim($params['first_name'] ?? '');
        $last_name = trim($params['last_name'] ?? '');
        $phone = trim($params['phone'] ?? '');
        $password = $params['password'] ?? '';
        $password_confirm = $params['password_confirm'] ?? '';
        $invite_code = trim($params['invite_code'] ?? '');

        // Validation
        $errors = [];

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

        // Invite code validation (if provided or required)
        $signup_mode = config('rsx.auth.signup_mode', 'invite_only');
        $invitation = null;

        if ($signup_mode === 'invite_only' || $invite_code) {
            if (empty($invite_code)) {
                $errors['invite_code'] = 'Invitation code is required';
            } else {
                $validation = Invite_Helper::validate_invitation($invite_code);

                if (!$validation['valid']) {
                    // Handle email mismatch specially
                    if ($validation['error_type'] === 'email_mismatch') {
                        $errors['_general'] = "This invitation was sent to {$validation['invited_email']} but you are logged in as {$validation['current_email']}. Please logout and signup with the invited email address.";
                    } else {
                        $errors['invite_code'] = $validation['error'];
                    }
                } else {
                    $invitation = $validation['invitation'];

                    // Verify email matches invitation email
                    if (!Invite_Helper::can_email_accept_invitation($invitation, $email)) {
                        $errors['email'] = "Email must match the invited email address: {$invitation->email}";
                    }
                }
            }
        }

        // Return validation errors. response_form_error() carries a summary under
        // _message alongside the field map, so the form's top alert says something
        // even when every message matched a field.
        if (!empty($errors)) {
            return response_form_error('Please correct the errors below.', $errors);
        }

        // Create login_user record
        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make($password);
        $login_user->is_verified = 0; // Unverified until email confirmation
        $login_user->is_activated = 1; // Activated by default
        $login_user->save();

        Flash_Alert::success('Account created successfully! Please check your email to verify your account.');

        // 'redirect' is the one result key the form itself acts on: the browser leaves
        // for the login page, and the flash message is waiting there.
        return [
            'user_id' => $login_user->id,
            'redirect' => Rsx::Route('Login_Controller'),
        ];
    }
}
