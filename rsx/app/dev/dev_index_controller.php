<?php

namespace Rsx\App\Dev;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Developer tool suite index - gated to debug sites by the 'dev_tools' check.
 */
#[Auth('dev_tools')]
class Dev_Index_Controller extends Rsx_Controller_Abstract
{
    /**
     * Handle index page for dev
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Route('/dev')]
    public static function index(Request $request, array $params = [])
    {
        $data = [
            // Add your data here
        ];

        return rsx_view('Dev_Index', $data);
    }
}