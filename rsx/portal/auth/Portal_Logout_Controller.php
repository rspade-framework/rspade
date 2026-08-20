<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal\Auth;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;

/**
 * Portal Logout Controller
 *
 * Handles portal user logout.
 */
#[Auth('public')]
class Portal_Logout_Controller extends Rsx_Controller_Abstract
{
    /**
     * Logout portal user
     */
    #[Portal_Route('/logout', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        Portal_Session::logout();

        return redirect(Rsx_Portal::Route('Portal_Login_Controller::index', ['message' => 'logged_out']));
    }
}
