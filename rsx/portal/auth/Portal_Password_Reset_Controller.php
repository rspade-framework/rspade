<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Auth;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\Emails\Portal_Password_Reset_Email;
use Rsx\Models\Portal_Password_Reset_Model;

/**
 * Portal Password Reset Controller
 *
 * Handles password reset request and reset for portal users.
 */
#[Auth('public')]
class Portal_Password_Reset_Controller extends Rsx_Controller_Abstract
{
    /**
     * Show password reset request form and handle submission
     *
     * User enters their email to receive a password reset link.
     */
    #[Portal_Route('/password/reset', methods: ['GET', 'POST'])]
    public static function request(Request $request, array $params = [])
    {
        // If already logged in, redirect to dashboard
        if (Portal_Session::is_logged_in()) {
            return redirect(Rsx_Portal::Route('Portal_Dashboard_Action'));
        }

        $error = null;
        $success = false;
        $posted_email = null;

        if ($request->isMethod('POST')) {
            // Human verification first: it gates the account lookup and the reset
            // email, and therefore both enumeration and using this form as a mailer.
            Rsx_Turnstile::validate($request);

            $posted_email = $request->input('email');

            // Validate input
            if (empty($posted_email)) {
                $error = 'Email address is required';
            } elseif (!filter_var($posted_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address';
            } else {
                // The site this portal serves (declared by Portal_Main::init()).
                $site_id = Portal_Session::get_site_id();

                // Find portal user by email
                $portal_user = Portal_User_Model::find_by_email($site_id, $posted_email);

                if ($portal_user && $portal_user->can_login()) {
                    // Create password reset token
                    $reset = Portal_Password_Reset_Model::create_for_user($portal_user);

                    // Send password reset email
                    (new Portal_Password_Reset_Email($portal_user, $reset->get_reset_url()))
                        ->to($portal_user)
                        ->send();

                    $success = true;
                } else {
                    // Don't reveal whether email exists - always show success
                    $success = true;
                }
            }
        }

        return rsx_view('Portal_Password_Reset_Request', [
            'error' => $error,
            'success' => $success,
            'posted_email' => $posted_email,
        ]);
    }

    /**
     * Show password reset form and handle submission
     *
     * User arrives here by clicking the link in their password reset email.
     */
    #[Portal_Route('/password/reset/:token', methods: ['GET', 'POST'])]
    public static function reset(Request $request, array $params = [])
    {
        // If already logged in, redirect to dashboard
        if (Portal_Session::is_logged_in()) {
            return redirect(Rsx_Portal::Route('Portal_Dashboard_Action'));
        }

        $token = $params['token'] ?? null;
        $error = null;

        // Validate token
        if (empty($token)) {
            return rsx_view('Portal_Password_Reset_Invalid', [
                'error' => 'No reset token provided. Please use the link from your password reset email.',
            ]);
        }

        $reset = Portal_Password_Reset_Model::find_by_token($token);

        if (!$reset) {
            return rsx_view('Portal_Password_Reset_Invalid', [
                'error' => 'Invalid password reset link. Please request a new one.',
                'show_request_link' => true,
            ]);
        }

        if (!$reset->is_valid()) {
            if ($reset->is_used()) {
                return rsx_view('Portal_Password_Reset_Invalid', [
                    'error' => 'This password reset link has already been used. Please login or request a new link.',
                    'show_login_link' => true,
                    'show_request_link' => true,
                ]);
            }

            if ($reset->is_expired()) {
                return rsx_view('Portal_Password_Reset_Invalid', [
                    'error' => 'This password reset link has expired. Please request a new one.',
                    'show_request_link' => true,
                ]);
            }
        }

        // Get the associated portal user
        $portal_user = $reset->portal_user();
        if (!$portal_user) {
            return rsx_view('Portal_Password_Reset_Invalid', [
                'error' => 'User account not found. Please contact support.',
            ]);
        }

        // Handle form submission
        if ($request->isMethod('POST')) {
            $password = $request->input('password');
            $password_confirm = $request->input('password_confirm');
            $min_length = config('rsx.portal.password_min_length', 8);

            // Validate password
            if (empty($password)) {
                $error = 'Password is required';
            } elseif (strlen($password) < $min_length) {
                $error = "Password must be at least {$min_length} characters";
            } elseif ($password !== $password_confirm) {
                $error = 'Passwords do not match';
            } else {
                // Update password
                $portal_user->set_password($password);
                $portal_user->save();

                // Mark reset as used
                $reset->mark_used();

                Flash_Alert::success('Your password has been reset. Please login with your new password.');

                return redirect(Rsx_Portal::Route('Portal_Login_Controller::index'));
            }
        }

        return rsx_view('Portal_Password_Reset_Reset', [
            'token' => $token,
            'email' => $portal_user->email,
            'error' => $error,
        ]);
    }
}
