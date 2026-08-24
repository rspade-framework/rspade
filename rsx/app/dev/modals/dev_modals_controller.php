<?php

namespace Rsx\App\Dev\Modals;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Modal showcase - CLOSED: no one can reach it, by declaration.
 */
#[Auth('closed')]
class Dev_Modals_Controller extends Rsx_Controller_Abstract
{
    /**
     * Handle modals feature
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Route('/dev/modals')]
    public static function index(Request $request, array $params = [])
    {
        $data = [
            // Add your data here
        ];

        return rsx_view('Dev_Modals', $data);
    }
}