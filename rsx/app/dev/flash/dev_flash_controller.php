<?php

namespace Rsx\App\Dev\Flash;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Rsx;
use App\RSpade\Lib\Flash\Flash_Alert;

/**
 * Flash alert showcase - gated to debug sites by the 'dev_tools' check.
 */
#[Auth('dev_tools')]
class Dev_Flash_Controller extends Rsx_Controller_Abstract
{
    /**
     * Main flash alerts testing page
     */
    #[Route('/dev/flash')]
    public static function index(Request $request, array $params = [])
    {
        return rsx_view('Dev_Flash');
    }

    /**
     * Server-side flash alerts (redirect back to test page)
     */
    #[Route('/dev/flash/test-server-success')]
    public static function test_server_success(Request $request, array $params = [])
    {
        Flash_Alert::success('Server-side success flash! Created via Flash::success() then redirected.');
        return redirect(Rsx::Route('Dev_Flash_Controller::index'));
    }

    #[Route('/dev/flash/test-server-error')]
    public static function test_server_error(Request $request, array $params = [])
    {
        Flash_Alert::error('Server-side error flash! Created via Flash::error() then redirected.');
        return redirect(Rsx::Route('Dev_Flash_Controller::index'));
    }

    #[Route('/dev/flash/test-server-info')]
    public static function test_server_info(Request $request, array $params = [])
    {
        Flash_Alert::info('Server-side info flash! Created via Flash::info() then redirected.');
        return redirect(Rsx::Route('Dev_Flash_Controller::index'));
    }

    #[Route('/dev/flash/test-server-warning')]
    public static function test_server_warning(Request $request, array $params = [])
    {
        Flash_Alert::warning('Server-side warning flash! Created via Flash::warning() then redirected.');
        return redirect(Rsx::Route('Dev_Flash_Controller::index'));
    }

    /**
     * Ajax flash alerts (returned in Ajax response)
     */
    #[Ajax_Endpoint]
    public static function test_ajax_success(Request $request, array $params = [])
    {
        Flash_Alert::success('Ajax success flash! Created on server, delivered via response.flash_alerts');
        return ['test' => 'success'];
    }

    #[Ajax_Endpoint]
    public static function test_ajax_error(Request $request, array $params = [])
    {
        Flash_Alert::error('Ajax error flash! Created on server, delivered via response.flash_alerts');
        return ['test' => 'error'];
    }

    #[Ajax_Endpoint]
    public static function test_ajax_info(Request $request, array $params = [])
    {
        Flash_Alert::info('Ajax info flash! Created on server, delivered via response.flash_alerts');
        return ['test' => 'info'];
    }

    #[Ajax_Endpoint]
    public static function test_ajax_warning(Request $request, array $params = [])
    {
        Flash_Alert::warning('Ajax warning flash! Created on server, delivered via response.flash_alerts');
        return ['test' => 'warning'];
    }

    #[Ajax_Endpoint]
    public static function test_ajax_queue(Request $request, array $params = [])
    {
        Flash_Alert::success('Ajax queue test - Message 1 (success)');
        Flash_Alert::error('Ajax queue test - Message 2 (error)');
        Flash_Alert::info('Ajax queue test - Message 3 (info)');
        Flash_Alert::warning('Ajax queue test - Message 4 (warning)');
        return ['queued' => 4];
    }

    /**
     * Test sessionStorage persistence: Ajax response with flash + redirect
     * This tests the primary use case for persistence - flash message queued,
     * then page redirects before message can display
     */
    #[Ajax_Endpoint]
    public static function test_ajax_redirect(Request $request, array $params = [])
    {
        Flash_Alert::success('Persistence test: This flash was queued during Ajax, then page redirected. If you see this, sessionStorage persistence works!');
        return ['redirect' => Rsx::Route('Dev_Flash_Controller::index')];
    }
}
