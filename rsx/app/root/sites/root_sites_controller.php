<?php

namespace Rsx\App\Root\Sites;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Root console: is_logged_in + is_root_admin (ROLE_ROOT_ADMIN floor). A
 * cross-site console must never be reachable on login alone - the role gate
 * was applied by owner ruling during the auth-gates conversion (2026-08-07).
 */
#[Auth('is_logged_in', 'is_root_admin')]
class Root_Sites_Controller extends Rsx_Controller_Abstract
{
    /**
     * Handle index page for sites
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Route('/root/sites')]
    public static function index(Request $request, array $params = [])
    {
        $data = [];

        return rsx_view('Root_Sites', $data);
    }
}
