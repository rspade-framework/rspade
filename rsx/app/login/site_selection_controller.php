<?php

namespace Rsx\App\Login;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Flash\Flash_Alert;

/**
 * Site_Selection_Controller
 *
 * The site picker for a signed-in identity that can use more than one site, and the one
 * place that answers which sites those are (usable_sites(), shared with the
 * site-unauthorized page, both rendering the Login_Site_Picker partial).
 * Routes:
 * - /login/select-site - The picker: one button per usable site
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
     * The sites a login identity can USE, for a picker: [{site_id, name}], by name.
     *
     * ->active() is the framework's one definition of a usable membership (the membership and
     * its site both enabled), so a picker never offers a site the framework would refuse.
     * Never restate the columns here (rsx:man session).
     *
     * @param int $login_user_id
     * @return array<int, array{site_id: int, name: string}>
     */
    public static function usable_sites(int $login_user_id): array
    {
        // Across every site: the session may already name one, and the identity's memberships
        // elsewhere are exactly what a picker is for.
        $site_ids = User_Model::without_site_scope(
            fn () => User_Model::where('login_user_id', $login_user_id)
                ->active()
                ->pluck('site_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        $names = Site_Model::whereIn('id', $site_ids)->pluck('name', 'id')->all();

        $sites = [];

        foreach ($site_ids as $site_id) {
            $sites[] = ['site_id' => $site_id, 'name' => (string) ($names[$site_id] ?? 'Site #' . $site_id)];
        }

        usort($sites, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $sites;
    }

    /**
     * The site picker. A visitor who is not signed in goes to the login page; an identity with
     * no usable site is logged out (as the site-unauthorized page does); with exactly one there
     * is nothing to choose, so it is selected.
     */
    #[Route('/login/select-site', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        $login_user_id = Session::get_login_user_id();

        if (!$login_user_id) {
            return redirect(Rsx::Route('Login_Controller'));
        }

        $sites = self::usable_sites((int) $login_user_id);

        if (empty($sites)) {
            return redirect(Rsx::Route('Login_Controller::logout', ['reason' => 'unauthorized']));
        }

        if (count($sites) === 1) {
            return redirect(Rsx::Route('Site_Selection_Controller::select', $sites[0]['site_id']));
        }

        return rsx_view('Site_Selection_Index', [
            'sites' => $sites,
        ]);
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

        // Check if user can use this site.
        //
        // ->active() is the framework's one definition of a usable membership (the membership
        // and its site both enabled). A disabled site or membership is refused HERE with the
        // error card, rather than accepted and then ended by the framework's per-request
        // enforcement (Session::enforce_enabled_membership()), which would sign the identity
        // out of every site. See: php artisan rsx:man session
        // Unscoped: the membership asked about is on the site being CHOSEN, not the one the
        // session may already name.
        $user = User_Model::without_site_scope(
            fn () => User_Model::where('login_user_id', $login_user->id)
                ->where('site_id', $site_id)
                ->active()
                ->first()
        );

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
