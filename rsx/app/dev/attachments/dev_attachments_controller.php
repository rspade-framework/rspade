<?php

namespace Rsx\App\Dev\Attachments;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * File attachment showcase - gated to debug sites by the 'dev_tools' check.
 */
#[Auth('dev_tools')]
class Dev_Attachments_Controller extends Rsx_Controller_Abstract
{
    /**
     * Handle attachments feature - supports multiple routes for testing
     *
     * @param Request $request
     * @param array $params
     * @return mixed
     */
    #[Route('/dev/attachments')]
    #[Route('/dev/attachments/:test_id')]
    #[Route('/dev/attachments/:test_id/detail')]
    public static function index(Request $request, array $params = [])
    {
        $data = [
            'test_id' => $params['test_id'] ?? null,
            'route_used' => $request->path(),
        ];

        return rsx_view('Dev_Attachments', $data);
    }
}