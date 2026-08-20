<?php

namespace Rsx\App\Login;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Flash\Flash_Alert;

/**
 * Site_Selection_Controller
 *
 * Handles site selection for users who belong to multiple sites
 * Routes:
 * - /login/select-site - Show site picker for multi-site users
 * - /login/site/:id - Select a specific site
 *
 * Public by design: part of the login flow. The inline get_login_user() /
 * membership checks below are FLOW logic (which site to land on, or back to
 * login), not authorization gates.
 */
#[Auth('public')]
class Site_Selection_Controller extends Rsx_Controller_Abstract
{
    /**
     * Show site selection page (placeholder for multi-site functionality)
     */
    #[Route('/login/select-site', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        return rsx_view('Site_Selection_Index');
    }

    /**
     * Select a site for the current session
     *
     * If user is on the site: set session site_id and redirect to dashboard
     * If user is not on the site: show error card
     */
    #[Route('/login/site/:id', methods: ['GET'])]
    public static function select(Request $request, array $params = [])
    {
        $site_id = $params['id'] ?? null;

        if (empty($site_id)) {
            Flash_Alert::error('No site specified');
            return redirect('/');
        }

        // Get current login user
        $login_user = Session::get_login_user();

        if (!$login_user) {
            Flash_Alert::error('You must be logged in to access a site');
            return redirect(Rsx::Route('Login_Controller'));
        }

        // Check if user has access to this site
        $user = User_Model::where('login_user_id', $login_user->id)
            ->where('site_id', $site_id)
            ->where('is_enabled', true)
            ->first();

        if (!$user) {
            // User is not on this account - show error card
            return rsx_view('Site_Selection_Error_Index', [
                'site_id' => $site_id,
            ]);
        }

        // User has access - set session site and redirect to dashboard
        Session::set_site_id($site_id);

        return redirect(Rsx::Route('Dashboard_Index_Action'));
    }
}
