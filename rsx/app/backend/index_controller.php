<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */


namespace Rsx\App\Backend;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;

/**
 * Backend controller - handles administrative functions
 */
#[Auth('is_logged_in')]
class Backend_Index_Controller extends Rsx_Controller_Abstract
{
    /**
     * Admin dashboard
     */
    #[Route('/admin', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        $user = Session::get_user();

        return rsx_view('Backend_Index', [
            'user' => $user,
            'title' => 'Admin Dashboard',
        ]);
    }
}
