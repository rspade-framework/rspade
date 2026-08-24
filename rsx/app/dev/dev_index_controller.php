<?php

namespace Rsx\App\Dev;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Developer tool suite index.
 *
 * THE WHOLE SUITE UNDER rsx/app/dev/ IS CLOSED - every surface declares
 * #[Auth('closed')], which no identity satisfies in any mode. It is a showcase of
 * framework components written against a since-removed "debug site" concept, and
 * it is kept in the template as a worked reference rather than a running feature.
 *
 * Opening it is a deliberate act: replace 'closed' with a check of your own. Do
 * not reintroduce an environment-only gate without deciding what it should be.
 */
#[Auth('closed')]
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