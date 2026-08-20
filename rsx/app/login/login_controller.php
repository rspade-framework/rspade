<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Login;

use Illuminate\Http\Request;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Lib\Flash\Flash_Alert;
use Rsx\App\Login\Invite_Helper;

/**
 * Login controller for RSX authentication
 *
 * Handles user authentication, login/logout, and redirects
 *
 * Public by design: these are the routes an anonymous visitor arrives on.
 */
#[Auth('public')]
class Login_Controller extends Rsx_Controller_Abstract
{
    /**
     * Show login form
     */
    #[Route('/login', methods: ['GET', 'POST'])]
    public static function index(Request $request, array $params = [])
    {
        $error = null;
        $posted_email = null;

        if ($request->is_post()) {
            // Human verification first: it gates credential checking, and therefore
            // account enumeration, before any lookup happens.
            Rsx_Turnstile::validate($request);

            $posted_email = $request->input('email');
            $credentials = [
                'email' => $posted_email,
                'password' => $request->input('password'),
            ];

            // Validate email
            if (empty($posted_email)) {
                $error = 'Email address is required';
            } elseif (!filter_var($posted_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address';
            } elseif (empty($request->input('password'))) {
                $error = 'Password is required';
            } elseif (RsxAuth::attempt($credentials)) {
                // Check if there's an invite code to redirect to
                $invite_code = $request->input('code');
                if ($invite_code) {
                    Flash_Alert::success('Login successful! Please complete your account setup.');
                    return redirect(Rsx::Route('Accept_Invite_Controller::index', ['code' => $invite_code]));
                }

                // Check how many sites the user has access to
                $login_user_id = Session::get_login_user_id();
                $user_sites = User_Model::where('login_user_id', $login_user_id)
                    ->where('is_enabled', true)
                    ->get();

                // If user is on multiple sites, don't set site_id yet - show site selector
                if ($user_sites->count() > 1) {
                    Flash_Alert::success('Login successful! Please select your site.');
                    return redirect(Rsx::Route('Site_Selection_Controller'));
                }

                // Single site - set it and go to dashboard
                if ($user_sites->count() === 1) {
                    Session::set_site_id($user_sites->first()->site_id);
                    Flash_Alert::success('Welcome back!');
                    return redirect(Rsx::Route('Dashboard_Index_Action'));
                }

                // No sites - redirect to unauthorized (will handle logout)
                return redirect(Rsx::Route('Site_Unauthorized_Controller'));
            } else {
                $error = 'Invalid email or password. Please try again.';
            }
        }

        // Get invite code and fill preference from GET parameters
        $invite_code = $params['code'] ?? null;
        $fill = $params['fill'] ?? 'true';
        $prefill_email = null;

        // If invite code provided and fill is not explicitly false, validate and get email
        if ($invite_code && $fill !== 'false') {
            $validation = Invite_Helper::validate_invitation($invite_code);
            if ($validation['valid']) {
                $prefill_email = $validation['invitation']->email;
            }
        }

        // Use posted email if present (form was submitted), otherwise use prefill
        $email_value = $posted_email ?? $prefill_email;

        return rsx_view('Login_Index', [
            'invite_code' => $invite_code,
            'prefill_email' => $email_value,
            'error' => $error,
        ]);
    }

    /**
     * Logout user
     *
     * Optional redirect parameter:
     * - If provided and it validates as a local, non-login-flow path (via the
     *   single Login_Redirect sanitizer), redirect there after logout.
     * - Otherwise (absent, hostile, or a login-flow route), redirect to the login
     *   page with the logged_out message.
     *
     * Optional reason parameter:
     * - If provided, passes through to login page (e.g., 'unauthorized')
     */
    #[Route('/logout', methods: ['GET'])]
    public static function logout(Request $request, array $params = [])
    {
        RsxAuth::logout();

        // Check for reason parameter to pass through
        $reason = $params['reason'] ?? null;

        // Build the default destination: the login page with a message/reason.
        $query_params = [];
        if ($reason) {
            $query_params['reason'] = $reason;
        } else {
            $query_params['message'] = 'logged_out';
        }
        $default = Rsx::Route('Login_Controller::index', $query_params);

        // Route the redirect param through the one redirect sanitizer. A valid
        // local target is honored; anything else (hostile, off-host, or a
        // login-flow route) degrades silently to the login default.
        return redirect(Login_Redirect::consume($default));
    }
}
