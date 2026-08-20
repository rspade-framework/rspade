<?php

namespace Rsx\App\Root;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Rsx;

/**
 * Root console: is_logged_in + is_root_admin (ROLE_ROOT_ADMIN floor). A
 * cross-site console must never be reachable on login alone - the role gate
 * was applied by owner ruling during the auth-gates conversion (2026-08-07).
 */
#[Auth('is_logged_in', 'is_root_admin')]
class Root_Index_Controller extends Rsx_Controller_Abstract
{
    /**
     * Handle root index - redirect to dashboard
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Route('/root')]
    public static function index(Request $request, array $params = [])
    {
        return redirect(Rsx::Route('Root_Dashboard_Controller'));
    }
}
