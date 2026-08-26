<?php
/**
 * SSR Test Controller
 *
 * Public test page for jqhtml server-side rendering.
 * Renders jqhtml components via the @jqhtml/ssr Node server
 * and returns fully-rendered HTML without client-side JavaScript.
 */


namespace Rsx\App\SsrTest;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\SSR\Rsx_SSR;
use App\RSpade\Core\View\PageData;

/**
 * Public by design: an anonymous-reachable SSR/session smoke page (the session
 * endpoints exist precisely to observe cookie behavior without a login).
 */
#[Auth('public')]
class SSR_Test_Controller extends Rsx_Controller_Abstract
{
    #[FPC]
    #[Route('/ssr-test', methods: ['GET'])]
    public static function index(Request $request, array $params = [])
    {
        $start = microtime(true);

        try {
            $result = Rsx_SSR::render_component('SSR_Test_Page', [], 'SSR_Test_Bundle');
            $total_ms = round((microtime(true) - $start) * 1000, 1);

            // Inject SSR preload data into window.rsxapp.page_data via PageData
            // Jqhtml_Integration.js reads this to seed the preload cache
            if (!empty($result['preload'])) {
                PageData::add([
                    'ssr' => true,
                    'ssr_preload' => $result['preload'],
                ]);
            }

            return rsx_view('SSR_Test_Index', [
                'html' => $result['html'],
                'timing' => $result['timing'],
                'total_ms' => $total_ms,
                'error' => null,
            ]);
        } catch (\Exception $e) {
            $total_ms = round((microtime(true) - $start) * 1000, 1);

            return rsx_view('SSR_Test_Index', [
                'html' => '',
                'timing' => [],
                'total_ms' => $total_ms,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Client-side rendered version of the same page for comparison
     */
    #[Route('/ssr-test-csr', methods: ['GET'])]
    public static function csr(Request $request, array $params = [])
    {
        return rsx_view('SSR_Test_CSR');
    }

    // -----------------------------------------------------------------
    // Session cookie test endpoints
    // -----------------------------------------------------------------

    /**
     * Noop endpoint - no session interaction at all.
     * Should NOT produce a Set-Cookie header.
     */
    #[Route('/ssr-test/session-noop', methods: ['GET'])]
    public static function session_noop(Request $request, array $params = [])
    {
        return response('OK', 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * Calls Session::get_user_id() which uses init() only.
     * Should NOT produce a Set-Cookie header.
     */
    #[Route('/ssr-test/session-get-user-id', methods: ['GET'])]
    public static function session_get_user_id(Request $request, array $params = [])
    {
        \App\RSpade\Core\Session\Session::init();
        $user_id = \App\RSpade\Core\Session\Session::get_user_id();
        return response('user_id=' . ($user_id ?? 'null'), 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * Calls Session::get_session_id() which triggers __activate().
     * SHOULD produce a Set-Cookie header for anonymous users.
     */
    #[Route('/ssr-test/session-get-session-id', methods: ['GET'])]
    public static function session_get_session_id(Request $request, array $params = [])
    {
        $session_id = \App\RSpade\Core\Session\Session::get_session_id();
        return response('session_id=' . $session_id, 200, ['Content-Type' => 'text/plain']);
    }

    // -----------------------------------------------------------------
    // Public data endpoint
    // -----------------------------------------------------------------

    /**
     * Public API endpoint returning page data for SSR test
     */
    #[Ajax_Endpoint]
    public static function get_page_data(Request $request, array $params = [])
    {
        return [
            'stats' => [
                ['value' => '12,847', 'label' => 'Active Users', 'trend' => 'up'],
                ['value' => '99.97%', 'label' => 'Uptime', 'trend' => 'stable'],
                ['value' => '23ms', 'label' => 'Avg Response', 'trend' => 'down'],
                ['value' => '$48.2K', 'label' => 'Monthly Revenue', 'trend' => 'up'],
            ],
            'plans' => [
                [
                    'name' => 'Starter',
                    'price' => 29,
                    'period' => 'mo',
                    'popular' => false,
                    'features' => ['5 team members', '10 GB storage', 'Email support', 'Basic analytics'],
                ],
                [
                    'name' => 'Professional',
                    'price' => 79,
                    'period' => 'mo',
                    'popular' => true,
                    'features' => ['25 team members', '100 GB storage', 'Priority support', 'Advanced analytics', 'API access', 'Custom integrations'],
                ],
                [
                    'name' => 'Enterprise',
                    'price' => 249,
                    'period' => 'mo',
                    'popular' => false,
                    'features' => ['Unlimited members', '1 TB storage', '24/7 phone support', 'Enterprise analytics', 'Full API access', 'Custom integrations', 'Dedicated account manager'],
                ],
            ],
            'team' => [
                ['name' => 'Alice Chen', 'role' => 'CEO & Founder', 'initials' => 'AC'],
                ['name' => 'Bob Martinez', 'role' => 'CTO', 'initials' => 'BM'],
                ['name' => 'Carol Kim', 'role' => 'Head of Design', 'initials' => 'CK'],
                ['name' => 'Dan Okafor', 'role' => 'Lead Engineer', 'initials' => 'DO'],
            ],
        ];
    }
}
