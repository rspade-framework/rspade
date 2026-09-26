<?php

namespace Rsx\App\Login;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * Site_Unauthorized_Controller
 *
 * The screen for an identity whose session names a site it is not a member of: it offers the
 * sites it IS a member of, and logs it out when there are none.
 *
 * WHEN THIS IS REACHED. The framework ends a session whose users row for the current site is
 * missing or disabled (Session::enforce_enabled_membership(), run before every dispatch), so
 * this screen is not where a revoked membership lands - that lands on the login page. It is
 * here for an application that DECLARES the requested site itself, from the request host or a
 * URL segment, and would rather offer a picker than sign the visitor out. This template is
 * mono-site and routes to it from nowhere; it is the pattern, kept whole.
 *
 * Public by design: part of the login flow. The inline login_user_id check is
 * FLOW logic (send an anonymous visitor back to login), not an authorization
 * gate.
 */
#[Auth('public')]
class Site_Unauthorized_Controller extends Rsx_Controller_Abstract
{
    /**
     * Show unauthorized page
     * If user has access to other sites, show site picker
     * If user has no site access, redirect to logout
     */
    #[Route('/login/site-unauthorized', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        $login_user_id = Session::get_login_user_id();

        if (!$login_user_id) {
            return redirect(Rsx::Route('Login_Controller'));
        }

        // The sites this identity can USE: ->active() is the framework's one definition of a
        // usable membership (enabled, on an enabled site), so a picker never offers a site the
        // framework would refuse. Never restate the columns here (rsx:man session).
        $user_sites = User_Model::where('login_user_id', $login_user_id)->active()->get();

        // If user has no sites, logout with reason
        if ($user_sites->isEmpty()) {
            return redirect(Rsx::Route('Login_Controller::logout', ['reason' => 'unauthorized']));
        }

        // User has access to other sites, show picker
        return rsx_view('Site_Unauthorized_Index', [
            'user_sites' => $user_sites,
        ]);
    }
}
