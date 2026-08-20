<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\App\Frontend;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 */
#[Auth('is_logged_in')]
class Frontend_Spa_Controller extends Rsx_Controller_Abstract
{
    /**
     * SPA entry point for frontend module
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[SPA]
    public static function index(Request $request, array $params = [])
    {
        return rsx_view(SPA, ['bundle' => 'Frontend_Bundle']);
    }
}
