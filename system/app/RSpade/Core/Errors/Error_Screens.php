<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Errors;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * Error_Screens - what a terminal request outcome RENDERS (full-page, server side)
 *
 * One renderer per outcome. The dispatchers route their terminal outcomes here so
 * the decision "what does a denial / a missing page / a crash look like" lives in
 * exactly one place instead of being spread across abort() calls and Laravel's
 * unthemed default blades:
 *
 *     unauthorized()  - a gate denial or any coded unauthorized/auth-required
 *     not_found()     - no route matched (after Main::unhandled_route declined)
 *     fatal()         - an uncaught exception reached the top of the request
 *
 * THE UNAUTHORIZED SPLIT LIVES HERE. A caller with no session cannot fix a denial
 * by being shown a 403 - it is sent to the login route with the originally
 * requested URL threaded through Login_Redirect. An authenticated caller gets a
 * genuine 403: re-authenticating changes nothing. The split is realm-aware, so a
 * portal request consults the PORTAL session and lands on the PORTAL login route.
 *
 * PRODUCTION REDACTION. fatal() renders exception detail (class, message, file,
 * line, trace) only when Rsx::is_production() is false - the same predicate
 * Ajax_Exception_Handler uses for the JSON channel, so both channels redact
 * together. The detail must never leave the server in production: an error page
 * is fully inspectable with curl.
 *
 * DEPENDENCY-LIGHT BY DESIGN. These pages render when the application is broken,
 * so they use a standalone Blade with inline styles - no bundle, no manifest view
 * lookup, no SPA runtime, no session chrome.
 *
 * CUSTOMIZING: copy-replace this class through the standard class-override pattern
 * (rsx:man class_override), keep the signatures, own the markup. Framework callers
 * reference the class by short name through a use statement, which the manifest
 * rewrites to the override - never by an inline fully-qualified name.
 *
 * The SPA has its own same-named JS twin (Core/SPA/Error_Screens.js) that renders
 * the app-owned theme error components into the live layout. See rsx:man auth_gates
 * (ERROR SCREENS).
 */
class Error_Screens
{
    /**
     * The standalone Blade every screen renders through.
     *
     * Lives in resources/views (NOT under app/RSpade), so it is a plain Laravel
     * view: no @rsx_id, no manifest lookup, no layout-chain validation - it still
     * renders when the manifest is the thing that broke.
     */
    protected const VIEW = 'errors.rsx_error';

    /**
     * A denial: login redirect for an unidentified caller, themed 403 otherwise.
     *
     * The realm decides which session is consulted and which login route the
     * unidentified caller lands on. A caller that KNOWS its realm says so
     * (Portal_Dispatcher does); everyone else omits it and the active realm is
     * detected from the request context.
     *
     * @param Request $request The request being denied
     * @param string|null $realm Auth_Gates::REALM_STAFF / REALM_PORTAL, or null to detect
     * @return Response 302 to the realm's login route, or a 403 page
     */
    public static function unauthorized(Request $request, ?string $realm = null): Response
    {
        $is_portal = $realm === null
            ? Rsx_Portal::is_portal_request()
            : $realm === Auth_Gates::REALM_PORTAL;

        $is_authenticated = $is_portal ? Portal_Session::is_logged_in() : Session::is_logged_in();

        if (!$is_authenticated) {
            // Login_Redirect::capture() silently degrades a hostile, non-page or
            // out-of-context target to [], so a captured target only survives when
            // it is a safe in-context page GET.
            $capture = Login_Redirect::capture($request);

            $login_url = $is_portal
                ? Rsx_Portal::Route('Portal_Login_Controller', $capture)
                : Rsx::Route('Login_Controller', $capture);

            return redirect($login_url);
        }

        return static::__render(
            403,
            'Access Denied',
            'You do not have permission to view this page.'
        );
    }

    /**
     * No route matched this URL.
     *
     * @param Request $request The unmatched request
     * @return Response A 404 page
     */
    public static function not_found(Request $request): Response
    {
        return static::__render(
            404,
            'Page Not Found',
            'The page you requested does not exist.'
        );
    }

    /**
     * An uncaught exception reached the top of the request.
     *
     * @param Request $request The failed request
     * @param Throwable|null $e The exception, when one is available
     * @return Response A 500 page (detail included only outside production)
     */
    public static function fatal(Request $request, ?Throwable $e = null): Response
    {
        return static::__render(
            500,
            'Something Went Wrong',
            'The server could not complete this request.',
            static::__exception_detail($e)
        );
    }

    /**
     * Exception detail for the page, or null when it must not be rendered.
     *
     * Production (strict or debug-mode sealed builds) renders NOTHING about the
     * exception - not the message, not the file, not one trace frame.
     *
     * @param Throwable|null $e
     * @return array|null
     */
    protected static function __exception_detail(?Throwable $e): ?array
    {
        if ($e === null || Rsx::is_production()) {
            return null;
        }

        $frames = [];
        foreach ($e->getTrace() as $index => $frame) {
            if ($index >= 10) {
                break;
            }

            $function = $frame['function'] ?? 'unknown';
            if (isset($frame['class'])) {
                $function = $frame['class'] . ($frame['type'] ?? '::') . $function;
            }

            $frames[] = sprintf(
                '%s:%d  %s()',
                static::__relative_file($frame['file'] ?? 'unknown'),
                $frame['line'] ?? 0,
                $function
            );
        }

        return [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => static::__relative_file($e->getFile()),
            'line' => $e->getLine(),
            'frames' => $frames,
        ];
    }

    /**
     * Project-relative path for display (absolute paths leak the deployment layout).
     */
    protected static function __relative_file(string $file): string
    {
        return str_replace(base_path() . '/', '', $file);
    }

    /**
     * Render one screen.
     *
     * @param int $status HTTP status
     * @param string $heading Short headline
     * @param string $message One-sentence explanation
     * @param array|null $detail Exception detail block (non-production only)
     * @return Response
     */
    protected static function __render(int $status, string $heading, string $message, ?array $detail = null): Response
    {
        return response()->view(static::VIEW, [
            'status' => $status,
            'heading' => $heading,
            'message' => $message,
            'detail' => $detail,
            'home_url' => url('/'),
        ], $status);
    }
}
