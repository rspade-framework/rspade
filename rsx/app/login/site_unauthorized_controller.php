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
 * Handles users trying to access a site they don't have access to
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

        // Get all sites this user has access to
        $user_sites = User_Model::where('login_user_id', $login_user_id)
            ->where('is_enabled', true)
            ->get();

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
