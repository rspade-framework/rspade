<?php

namespace Rsx\App\Dev;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * SPA bootstrap for the developer tool suite - CLOSED: no one can reach it, by declaration.
 */
#[Auth('closed')]
class Dev_Spa_Controller extends Rsx_Controller_Abstract
{
    /**
     * SPA bootstrap for dev tools SPA section
     */
    #[SPA]
    public static function index(Request $request, array $params = [])
    {
        return rsx_view(SPA, ['bundle' => 'Dev_Bundle']);
    }
}
