<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */


namespace Rsx\App;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * Frontend controller - handles the main public-facing pages
 *
 * Public by design: '/' is the anonymous entry point. The is_logged_in() call
 * below picks the redirect target; it does not gate the surface.
 *
 */
#[Auth('public')]
class Index_Controller extends Rsx_Controller_Abstract
{
    /**
     * Main frontend page
     */
    #[Route('/', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        if (Session::is_logged_in()) {
            return redirect(Rsx::Route('Dashboard_Index_Action'));
        }

        return redirect(Rsx::Route('Login_Controller'));
    }
}
