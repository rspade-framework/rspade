<?php

namespace Rsx\App\Root\Email\Templates;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Root console: is_logged_in + is_root_admin (ROLE_ROOT_ADMIN floor). A
 * cross-site console must never be reachable on login alone - the role gate
 * was applied by owner ruling during the auth-gates conversion (2026-08-07).
 */
#[Auth('is_logged_in', 'is_root_admin')]
class Root_Email_Templates_Controller extends Rsx_Controller_Abstract
{
    /**
     * Handle index page for email templates
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Route('/root/email/templates')]
    public static function index(Request $request, array $params = [])
    {
        $data = [];

        return rsx_view('Root_Email_Templates', $data);
    }
}
