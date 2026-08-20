<?php

namespace Rsx\App\Apidocs;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Apidocs_Spa_Controller - SPA bootstrap for the standalone API documentation module.
 *
 * Login-gated (mirrors Frontend_Spa_Controller). The whole docs UI is framework-core
 * (App\RSpade\Core\Api\Components); this thin app controller just boots the SPA with the
 * Apidocs_Bundle assets.
 */
#[Auth('is_logged_in')]
class Apidocs_Spa_Controller extends Rsx_Controller_Abstract
{
    #[SPA]
    public static function index(Request $request, array $params = [])
    {
        return rsx_view(SPA, ['bundle' => 'Apidocs_Bundle']);
    }
}
