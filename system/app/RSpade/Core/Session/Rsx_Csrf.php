<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Session;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Csrf - Framework-core CSRF enforcement (server side)
 *
 * TWO layers, applied at the dispatcher seam for POST requests:
 *
 *   1. ORIGIN/REFERER (all POSTs, session or not): a present Origin/Referer must
 *      name this host, else REJECT. A cross-site browser POST always carries an
 *      unforgeable Origin, so this closes login-CSRF on the anonymous auth forms
 *      (which have no session yet to bind a token to). Absent header (non-browser
 *      / internal caller) -> allowed.
 *   2. SYNCHRONIZER TOKEN (session-bearing POSTs only): if the browser HAS a session
 *      -> a CSRF token is REQUIRED and must verify (constant-time hash_equals via
 *      verify_csrf_token()); otherwise REJECT. No session -> ALLOW (no victim session
 *      to forge against; keeps token-less anonymous POSTs working).
 *
 * has_session() is READ-ONLY: it reads the session cookie via init() but never
 * __activate()s a new session, so this check never materializes a session for a
 * token-less anonymous caller.
 *
 * Token transport: request header X-CSRF-Token first, then the POST body field
 * _csrf_token (native forms). The client value is window.rsxapp.csrf.
 *
 * ONE TOKEN, BOTH EXPERIENCES. A browser has one session row and therefore one
 * csrf_token, so there is nothing here to fork on: a staff POST and a portal POST are
 * checked against the same token. (Before the one-session correction there were two
 * rows and two tokens, and this class took an $is_portal argument to pick a facade.
 * Directive #1: one way to do things.)
 *
 * Bearer external-API requests (/api/vN/*) never reach here (branched earlier in
 * the dispatcher) and are cookie-less anyway.
 */
class Rsx_Csrf
{
    /** Request header carrying the token for AJAX + upload transports. */
    const HEADER = 'X-CSRF-Token';

    /** POST body field carrying the token for native form submissions. */
    const FIELD = '_csrf_token';

    /**
     * Enforce CSRF for the current (POST) request.
     *
     * @param Request $request   The incoming request.
     * @return void  Returns cleanly when allowed; throws when rejected.
     *
     * @throws HttpResponseException When the Origin/Referer or token check fails.
     */
    public static function enforce(Request $request): void
    {
        // The CSP violation collector is the one POST a BROWSER makes entirely on its own,
        // with no page, no form and no token to attach - so a token check could only ever
        // reject it. It is exempt because there is nothing to forge: it reads nothing and
        // writes nothing but its own diagnostic log. See Csp_Report_Controller.
        if ('/' . ltrim($request->getPathInfo(), '/') === \App\RSpade\Core\Csp\Rsx_Csp::REPORT_PATH) {
            return;
        }

        // Origin/Referer check - applies to ALL state-changing POSTs (session or
        // not). A cross-site browser POST always carries an Origin header the
        // attacker cannot forge, so this closes login-CSRF on the anonymous auth
        // forms that the session-gated token below cannot cover (there is no
        // session yet to bind a token to). Non-browser callers (curl,
        // server-to-server) send neither header and are allowed.
        if (!self::__origin_ok($request)) {
            static::__reject($request);
        }

        // No session => nothing to forge against => allow.
        if (!Session::has_session()) {
            return;
        }

        // Session present: read the token (header first, then body field).
        $token = $request->header(self::HEADER);
        if ($token === null || $token === '') {
            $token = (string) $request->input(self::FIELD, '');
        }

        // Missing or failing constant-time verification => reject. No silent allow.
        if ($token === '' || !Session::verify_csrf_token($token)) {
            static::__reject($request);
        }
    }

    /**
     * Reject the request as a CSRF failure, rendering a clean, production-safe
     * response DIRECTLY so no exception backtrace can ever leak (the CSRF check
     * runs at the dispatcher seam, outside the Ajax handler's own try/catch, so a
     * thrown app exception would otherwise surface as a fatal-with-backtrace).
     *
     * AJAX (/_ajax*) and upload (/_upload) get the AJAX error contract shape
     * (200 + _success:false + error_code, identical to an endpoint auth failure);
     * native full-page form POSTs get a plain 419. Thrown as HttpResponseException
     * so Laravel returns the response verbatim with no handler embellishment.
     *
     * @param Request $request
     * @return void
     * @throws HttpResponseException
     */
    private static function __reject(Request $request): void
    {
        $path = '/' . ltrim($request->getPathInfo(), '/');
        $is_ajax = str_contains($path, '/_ajax') || str_ends_with($path, '/_upload');

        if ($is_ajax) {
            $response = response()->json([
                '_success'   => false,
                'error_code' => 'unauthorized',
                'reason'     => 'CSRF token mismatch',
            ]);
        } else {
            $response = response('CSRF token mismatch', 419);
        }

        throw new HttpResponseException($response);
    }

    /**
     * Verify the request's Origin (or Referer, when Origin is absent) names this host.
     *
     * Returns true when there is nothing to reject: the header is absent (a
     * non-browser / internal caller) or its host equals the request host.
     * Returns false ONLY when a PRESENT Origin/Referer names a different host -
     * i.e. a genuine cross-site browser POST (which browsers always tag with an
     * unforgeable Origin). Host comparison uses the trusted request host
     * (TrustProxies is in the global middleware), and ignores port/scheme so dev
     * loopback and forwarded hosts compare cleanly.
     *
     * @param Request $request
     * @return bool
     */
    private static function __origin_ok(Request $request): bool
    {
        $expected_host = $request->getHost();

        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== '') {
            return parse_url($origin, PHP_URL_HOST) === $expected_host;
        }

        $referer = $request->header('Referer');
        if ($referer !== null && $referer !== '') {
            return parse_url($referer, PHP_URL_HOST) === $expected_host;
        }

        // Neither header present: not a cross-site browser POST. Allow.
        return true;
    }
}
