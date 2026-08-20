<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Portal;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Portal SPA Controller - Bootstrap for portal single-page application
 *
 * This controller serves the SPA shell for all authenticated portal pages.
 * Portal SPA actions reference this controller via the @portal_spa() decorator.
 *
 * Gated on a portal session; the dispatcher seam sends an anonymous visitor to the
 * portal login with the intended URL captured. Public pages (login, register) live
 * in separate controllers.
 */
#[Auth('is_logged_in')]
class Portal_Spa_Controller extends Rsx_Controller_Abstract
{
    /**
     * SPA entry point for portal module
     *
     * This route serves as the bootstrap for all portal SPA actions.
     * The actual page content is rendered client-side based on the URL.
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Portal_Route('/*', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        return rsx_view(SPA, ['bundle' => 'Portal_Bundle']);
    }
}
