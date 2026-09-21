<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Rsx_Csrf;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * App_Error_Pages_Test - this application's own error pages actually answer.
 *
 * The framework suite pins the funnel (which page is resolved, what the context
 * carries, what happens when a page fails). This pins the half only the reference
 * application can show: that rsx/app/errors/ and rsx/portal/errors/ are DECLARED in a
 * way the framework finds, that each realm gets its own layout, and that the response
 * carries the failing status rather than the 200 a rendered view answers with.
 *
 * A page's exact wording is not asserted beyond the framework's own title, which is
 * what the page prints - rewording the copy must not break the suite, but deleting the
 * declaration must.
 *
 * The class is App_Error_Pages_Test rather than Error_Pages_Test because the framework
 * suite already declares that name, and an rsx/ class of the same simple name is a CLASS
 * OVERRIDE: it would archive the framework's test and silently stop it running.
 */
class App_Error_Pages_Test extends Rsx_Test_Abstract
{
    /** A staff URL no route matches. */
    const UNMATCHED_URL = '/no-such-url-for-the-app-error-pages-test';

    /** A portal URL no portal route matches. */
    const UNMATCHED_PORTAL_URL = '/_portal/no-such-url-for-the-app-error-pages-test';

    /** A staff route every identity is denied: the whole dev module is #[Auth('closed')]. */
    const CLOSED_URL = '/dev';

    /** A native form POST target - not an /_ajax path, so a CSRF failure is a PAGE. */
    const FORM_URL = '/login';

    /** The baseline identity the runner provisions. */
    const USER_ID = 1;

    public static function teardown()
    {
        Rsx_Portal::set_portal_request(false);
        static::__reset_session();
    }

    /**
     * One page may render one bundle per process (Rsx_Bundle_Abstract enforces it
     * against a page rendering two). A test process renders several pages, so the
     * marker is cleared before each - otherwise the second error page throws, the
     * funnel logs it and substitutes the framework page, and the test reads that as
     * the application having no page at all.
     *
     * @return void
     */
    private static function __begin_page(): void
    {
        Rsx_Bundle_Abstract::$_has_rendered = null;
    }

    /**
     * An unmatched staff URL renders THIS application's 404, not the framework's.
     */
    public static function test_an_unmatched_staff_url_renders_the_application_404_page()
    {
        static::__begin_page();

        // The no-route path reads the AMBIENT request for the Main hooks, so bind one.
        app()->instance('request', Request::create(self::UNMATCHED_URL, 'GET'));

        $response = Dispatcher::dispatch(
            self::UNMATCHED_URL,
            'GET',
            [],
            Request::create(self::UNMATCHED_URL, 'GET')
        );

        static::__assert_equals(404, $response->getStatusCode());

        $content = $response->getContent();
        static::__assert_contains('Errors_Layout', $content, 'the staff error layout renders the 404');
        static::__assert_contains('Page Not Found', $content);
    }

    /**
     * A gate denial for a caller who IS signed in renders the application's 403.
     *
     * An anonymous caller never reaches the page - the framework sends them to login
     * instead - so the identity is what makes this a page rather than a redirect.
     */
    public static function test_a_gate_denial_renders_the_application_403_page()
    {
        static::__begin_page();
        static::__acting_as_user(self::USER_ID);

        app()->instance('request', Request::create(self::CLOSED_URL, 'GET'));

        $response = Dispatcher::dispatch(
            self::CLOSED_URL,
            'GET',
            [],
            Request::create(self::CLOSED_URL, 'GET')
        );

        static::__assert_equals(403, $response->getStatusCode());

        $content = $response->getContent();
        static::__assert_contains('Errors_Layout', $content, 'the staff error layout renders the 403');
        static::__assert_contains('Access Denied', $content);

        static::__reset_session();
    }

    /**
     * A CSRF failure on a native form POST is a 419 PAGE in this application's chrome.
     *
     * A foreign Origin rejects ahead of any session check, so the shape is observable
     * with no session at all.
     */
    public static function test_a_foreign_origin_form_post_renders_the_application_419_page()
    {
        static::__begin_page();
        static::__reset_session();

        $request = Request::create(self::FORM_URL, 'POST', [], [], [], ['HTTP_ORIGIN' => 'http://evil.example']);

        $exception = static::__assert_throws(HttpResponseException::class, function () use ($request) {
            Rsx_Csrf::enforce($request);
        });

        $response = $exception->getResponse();

        static::__assert_equals(419, $response->getStatusCode());

        $content = $response->getContent();
        static::__assert_contains('Errors_Layout', $content, 'the staff error layout renders the 419');
        static::__assert_contains('Page Expired', $content);
    }

    /**
     * A PORTAL failure renders the portal's own page, in the portal's chrome.
     *
     * The realm is chosen by the failing request, and is_portal_request() reads the
     * ambient request URI - so the test declares it rather than hoping the CLI's
     * detection agrees.
     *
     * The unmatched URL is not dispatched here: Portal_Spa_Controller declares
     * #[Portal_Route('/*')], so a signed-in portal user never 404s server-side at all
     * (the SPA answers an unknown path client-side). What reaches this page is an
     * abort(404), a portal record that does not exist, and the anonymous unmatched URL.
     */
    public static function test_a_portal_failure_renders_the_portal_404_page()
    {
        static::__begin_page();
        static::__reset_session();
        Rsx_Portal::set_portal_request(true);

        // A CLI process never ran Portal_Main::init(), so nobody has declared the
        // portal's site yet - and the portal chrome asks for it. The app's own
        // declaration is a config key; this is the same line, in the same order.
        Portal_Session::set_site_id((int) config('rsx.portal.site_id'));

        $response = Error_Screens::not_found(Request::create(self::UNMATCHED_PORTAL_URL, 'GET'));

        static::__assert_equals(404, $response->getStatusCode());

        $content = $response->getContent();
        static::__assert_contains('Portal_Auth_Layout', $content, 'the portal error page wears the portal auth chrome');
        static::__assert_contains('Page Not Found', $content);
        static::__assert_true(
            !str_contains($content, 'Errors_Layout'),
            'a portal failure must not render the staff page'
        );

        Rsx_Portal::set_portal_request(false);
    }

    /**
     * Browsing /error/generic renders the catch-all page as a 500, with the exception
     * block filled in - the region a real crash shows, visible without crashing
     * anything. The preview is development-only, and the suite refuses to run in a
     * production mode, so there is no mode branch to take here.
     */
    public static function test_the_generic_preview_renders_a_500_with_its_detail_block()
    {
        static::__begin_page();

        $url = '/error/generic';
        app()->instance('request', Request::create($url, 'GET'));

        $response = Dispatcher::dispatch($url, 'GET', [], Request::create($url, 'GET'));

        static::__assert_equals(500, $response->getStatusCode());

        $content = $response->getContent();
        static::__assert_contains('Errors_Layout', $content);
        static::__assert_contains('Something Went Wrong', $content);
        static::__assert_contains('Errors_Layout__detail', $content, 'the generic page renders the exception block');
        static::__assert_contains('Errors_Layout__trace', $content, 'and the frames inside it');
    }
}
