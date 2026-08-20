<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Turnstile;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Rsx;
use App\RSpade\Lib\Flash\Flash_Alert;

/**
 * Rsx_Turnstile - server side of the Cloudflare Turnstile integration.
 *
 * Turnstile is the CAPTCHA replacement: the browser widget runs a background challenge
 * and produces a single-use token (valid 300s), which the server exchanges for a verdict
 * at Cloudflare's siteverify endpoint using the secret key. The public site key renders
 * the widget; the secret key never leaves the server.
 *
 * ACTIVITY IS CONFIGURED, NOT DERIVED. config('rsx.turnstile.enabled') is the only input -
 * development mode has no bearing on it. That single switch is what lets an endpoint call
 * validate() unconditionally: the widget ALWAYS posts the fixed field __turnstile, carrying
 * the sentinel 'inactive' when the feature is off and the token when it is on.
 *
 * A failure STOPS EXECUTION IMMEDIATELY (it does not accumulate into the endpoint's field
 * errors) and is shaped for the channel the request arrived on, exactly as Rsx_Csrf::__reject
 * does. The message rides the SUMMARY key (_message) and never a __turnstile-keyed field
 * error, because Form_Utils._normalize_errors() drops field keys starting with an underscore.
 *
 * Network trouble FAILS CLOSED with its own distinct message: an unverifiable token is never
 * an accepted token.
 *
 * The completeness guard below is the other half of the contract: a page that renders the
 * widget but whose endpoint forgot to call validate() would silently accept anything, so a
 * submitted __turnstile value with no validate() call throws at the post-dispatch seam.
 *
 * See: php artisan rsx:man turnstile
 */
class Rsx_Turnstile
{
    /** The fixed POST field the widget always renders. Never renamed - the client mirrors it. */
    const FIELD = '__turnstile';

    /** Sentinel value the widget posts when the feature is disabled. */
    const INACTIVE = 'inactive';

    /** Cloudflare's verification endpoint. */
    const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Token rejected by Cloudflare, wrong hostname, or a stale page posting an unexpected value. */
    const MESSAGE_FAILED = 'Verification failed - reload the page and try again.';

    /** Enabled, but the browser sent no token (widget never solved / not rendered). */
    const MESSAGE_MISSING = 'Please complete the verification challenge.';

    /** siteverify unreachable or erroring - fail closed, distinct from a real rejection. */
    const MESSAGE_UNAVAILABLE = 'Verification is temporarily unavailable - please try again.';

    /**
     * Cloudflare's published TESTING keys (dummy keys), exactly as documented. A site key
     * from this list renders a fake widget on any domain; a secret from this list ignores
     * the token entirely and returns a canned verdict (the 1x secret accepts ANYTHING).
     * Fine in development, meaningless-and-dangerous on a production build - see site_key().
     */
    const TESTING_KEYS = [
        // Site keys
        '1x00000000000000000000AA',   // always passes, visible
        '2x00000000000000000000AB',   // always blocks, visible
        '1x00000000000000000000BB',   // always passes, invisible
        '2x00000000000000000000BB',   // always blocks, invisible
        '3x00000000000000000000FF',   // forces an interactive challenge
        // Secret keys
        '1x0000000000000000000000000000000AA',   // always passes (accepts any token)
        '2x0000000000000000000000000000000AA',   // always fails
        '3x0000000000000000000000000000000AA',   // token already spent
    ];

    /**
     * Test seam: force the siteverify VERDICT for the current process instead of calling
     * Cloudflare (true = verified, false = rejected). null = not forced, make the real call.
     *
     * A process-global static, deliberately - the same shape as
     * Framework_Maintenance::$force_active_for_tests. It exists so a framework test can
     * exercise the enabled path (and the failure/stop shaping) with no network dependency;
     * nothing outside a test run may set it, and a test that sets it must clear it.
     *
     * @var bool|null
     */
    public static ?bool $force_verify_result_for_tests = null;

    /**
     * Request latch: has validate() run during this request/sub-call?
     *
     * Set by validate() BEFORE any verdict is reached (a FAILING validation still counts as
     * "the validator ran"), read by the post-dispatch completeness guard, and reset per
     * Ajax::internal() sub-call so a batch cannot launder one validation across calls.
     */
    private static bool $_checked = false;

    /**
     * Is Turnstile active? Config, and nothing else.
     */
    public static function is_enabled(): bool
    {
        return (bool) config('rsx.turnstile.enabled', false);
    }

    /**
     * The PUBLIC site key, for the widget / the window.rsxapp export.
     *
     * Enabled with either key missing is a half-configured install: the widget would render
     * against nothing, or every token would fail verification, and both look like a bug in
     * the form rather than a bug in the .env. It throws instead (owner ruling).
     *
     * A Cloudflare TESTING key on a strictly-production build throws too (owner ruling):
     * the 1x testing secret accepts ANY token, so a prod site running it has no human
     * verification at all while every form says it does. Debug mode is exempt - it is a
     * sealed LOCAL test build, exactly where the dummy keys belong.
     *
     * @throws RuntimeException When enabled and either key is empty, or a testing key is
     *                          configured on a strictly-production build.
     */
    public static function site_key(): string
    {
        [$site_key, $secret_key] = static::__require_keys();

        if (Rsx::is_production() && !Rsx::is_debug()) {
            foreach ([$site_key, $secret_key] as $key) {
                if (in_array($key, self::TESTING_KEYS, true)) {
                    throw new RuntimeException(
                        'Turnstile is configured with a Cloudflare TESTING key (' . $key . ')'
                        . ' on a production build. Testing keys verify nothing - the always-pass'
                        . ' secret accepts any token - so this install would render a fake widget'
                        . ' and perform no human verification. Set real keys from the Cloudflare'
                        . ' dashboard or disable the feature. See: php artisan rsx:man turnstile'
                    );
                }
            }
        }

        return $site_key;
    }

    /**
     * rsx:health inventory row.
     *
     * Reports the configured state without throwing: a half-configured install is exactly
     * what an operator wants to learn from a health report, and site_key() would abort the
     * check rather than describe it. Disabled is INFO, not a warning - off is a legitimate
     * and default state, and rsx:health's exit code must not turn on a feature nobody
     * asked for.
     *
     * @return array<string, mixed>
     */
    #[Health_Check('Turnstile')]
    public static function _health_check(): array
    {
        if (!static::is_enabled()) {
            return [
                'status' => 'INFO',
                'detail' => 'disabled (rsx.turnstile.enabled) - forms post the "inactive" sentinel',
            ];
        }

        $missing = [];
        if ((string) config('rsx.turnstile.site_key', '') === '') {
            $missing[] = 'TURNSTILE_SITE_KEY';
        }
        if ((string) config('rsx.turnstile.secret_key', '') === '') {
            $missing[] = 'TURNSTILE_SECRET_KEY';
        }

        if (!empty($missing)) {
            return [
                'status' => 'FAIL',
                'detail' => 'enabled but ' . implode(' and ', $missing) . ' not set',
                'remediation' => 'set both keys in .env, or set TURNSTILE_ENABLED=false',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => 'enabled, keys set',
        ];
    }

    /**
     * Verify the request's Turnstile field. THE call an endpoint makes.
     *
     * Place it at the TOP of the POST/submit handler, before any credential, email, or
     * record work - it must gate enumeration too. Returns cleanly when the request may
     * proceed; otherwise it stops execution and never returns.
     *
     * @param Request $request The incoming request.
     * @param array|null $params The endpoint's $params, when the transport delivers the
     *                           field there (Ajax/batch). Plain #[Route] POSTs do not merge
     *                           the body into $params, so the Request is read directly.
     * @return void
     *
     * @throws RuntimeException When enabled and half-configured (a 500, not a form error).
     * @throws AjaxFormErrorException|HttpResponseException On a verification failure.
     */
    public static function validate(Request $request, ?array $params = null): void
    {
        // Latch FIRST: the completeness guard asks "did the endpoint run the validator",
        // and a validator that ran and rejected did run.
        static::$_checked = true;

        $token = $params[self::FIELD] ?? $request->input(self::FIELD);

        if (!static::is_enabled()) {
            // Disabled: the field is still mandatory and must carry the sentinel. A stale
            // page rendered while the feature was ENABLED posts a real token and lands
            // here; a reload heals it (config-flip staleness is not accommodated).
            if ($token === self::INACTIVE) {
                return;
            }

            static::__reject($request, self::MESSAGE_FAILED);
        }

        [, $secret_key] = static::__require_keys();

        if ($token === null || $token === '' || $token === self::INACTIVE) {
            static::__reject($request, self::MESSAGE_MISSING);
        }

        if (static::$force_verify_result_for_tests !== null) {
            if (static::$force_verify_result_for_tests === true) {
                return;
            }

            static::__reject($request, self::MESSAGE_FAILED);
        }

        // Only the outbound call is guarded - an unreachable third party is an EXPECTED
        // failure. The verdict handling below is not in the try: a rejection must never be
        // reported as "temporarily unavailable".
        try {
            $response = Http::asForm()
                ->timeout((int) config('rsx.turnstile.verify_timeout_seconds', 30))
                ->post(self::SITEVERIFY_URL, [
                    'secret' => $secret_key,
                    'response' => (string) $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (Throwable $e) {
            Log::warning('Turnstile siteverify request failed: ' . $e->getMessage());

            static::__reject($request, self::MESSAGE_UNAVAILABLE);
        }

        if (!$response->successful()) {
            Log::warning('Turnstile siteverify returned HTTP ' . $response->status());

            static::__reject($request, self::MESSAGE_UNAVAILABLE);
        }

        $body = $response->json();
        if (!is_array($body)) {
            Log::warning('Turnstile siteverify returned a non-JSON body');

            static::__reject($request, self::MESSAGE_UNAVAILABLE);
        }

        if (($body['success'] ?? null) !== true) {
            $codes = $body['error-codes'] ?? [];
            Log::warning('Turnstile verification rejected the token. error-codes: '
                . (is_array($codes) ? implode(',', $codes) : (string) $codes));

            static::__reject($request, self::MESSAGE_FAILED);
        }

        // Cloudflare reports the hostname the token was minted on. No config names it -
        // the running application already knows who it is.
        //
        // EXCEPT for a verdict Cloudflare itself marks as produced by a TESTING key
        // (metadata.result_with_testing_key): the published dummy keys always report
        // hostname 'example.com' whatever host actually rendered the widget, so comparing
        // it would make the documented dev/test recipe unusable. The exemption reads
        // Cloudflare's own marker rather than a hardcoded list of dummy keys, and it costs
        // nothing in production: a real secret never sets it, and an install running a
        // testing secret in production is already accepting every token by design.
        $hostname = $body['hostname'] ?? null;
        $is_testing_key = ($body['metadata']['result_with_testing_key'] ?? null) === true;

        if (!$is_testing_key && is_string($hostname) && $hostname !== '') {
            $expected = Rsx::get_hostname();

            if ($hostname !== $expected) {
                Log::warning("Turnstile hostname mismatch: token issued for '{$hostname}', expected '{$expected}'");

                static::__reject($request, self::MESSAGE_FAILED);
            }
        }
    }

    /**
     * Clear the request latch. Called at the start of each Ajax::internal() invocation so a
     * batch sub-call cannot inherit a sibling's validation.
     */
    public static function _reset_request_state(): void
    {
        static::_set_request_checked(false);
    }

    /**
     * Set the request latch directly. The narrow seam Ajax::internal() uses to RESTORE the
     * calling scope's latch after a sub-call reset it - the sub-call gets a fresh latch, but
     * the outer request's own validation is not erased by a nested call it happened to make.
     */
    public static function _set_request_checked(bool $checked): void
    {
        static::$_checked = $checked;
    }

    /**
     * Has validate() run since the last reset?
     */
    public static function _was_checked(): bool
    {
        return static::$_checked;
    }

    /**
     * Did this request submit a __turnstile value at all?
     *
     * POST only. $request->input() reads a JSON body as well as form fields, which is what
     * makes the field visible at the Dispatcher seam for an Ajax transport request even
     * though the body was never merged into the route $params.
     */
    public static function _token_submitted(Request $request, ?array $params = null): bool
    {
        if (!$request->isMethod('POST')) {
            return false;
        }

        if ($params !== null && array_key_exists(self::FIELD, $params) && $params[self::FIELD] !== null) {
            return true;
        }

        return $request->input(self::FIELD) !== null;
    }

    /**
     * Completeness guard - the reason the post-dispatch event exists.
     *
     * A form carrying the widget posts __turnstile to an endpoint that must call validate().
     * If the endpoint never did, the request was accepted with NO verification at all and
     * nothing anywhere would say so. This throws on the first such submit, in development,
     * before the mistake can ship.
     *
     * Idempotent (it only reads state), which matters because the event fires at nested
     * seams - the Ajax handler AND the Dispatcher transport route around it.
     *
     * @param array $payload {request: Request, params: array|null, result: mixed}
     * @return void
     */
    #[OnEvent('rsx.post_dispatch')]
    public static function _guard_unvalidated_token(array $payload): void
    {
        $request = $payload['request'] ?? null;
        if (!$request instanceof Request) {
            shouldnt_happen('rsx.post_dispatch fired without a Request in its payload');
        }

        $params = $payload['params'] ?? null;
        if (!is_array($params)) {
            $params = null;
        }

        if (!static::_token_submitted($request, $params) || static::_was_checked()) {
            return;
        }

        $handler = $params['_handler'] ?? null;
        $where = is_string($handler) && $handler !== '' ? $handler : $request->getPathInfo();

        throw new RuntimeException(
            'Turnstile implementation incomplete: this request submitted a ' . self::FIELD
            . ' value but the endpoint never called Rsx_Turnstile::validate(). Every endpoint'
            . ' receiving a Turnstile_Input form must validate it. Handler: ' . $where
            . '. See: php artisan rsx:man turnstile'
        );
    }

    /**
     * Resolve both configured keys, failing loud when the feature is enabled but either is
     * empty. Returns [site_key, secret_key].
     *
     * @return array{0: string, 1: string}
     * @throws RuntimeException
     */
    private static function __require_keys(): array
    {
        $site_key = (string) config('rsx.turnstile.site_key', '');
        $secret_key = (string) config('rsx.turnstile.secret_key', '');

        if ($site_key === '' || $secret_key === '') {
            $missing = [];
            if ($site_key === '') {
                $missing[] = 'TURNSTILE_SITE_KEY';
            }
            if ($secret_key === '') {
                $missing[] = 'TURNSTILE_SECRET_KEY';
            }

            throw new RuntimeException(
                'Turnstile is enabled (TURNSTILE_ENABLED=true) but half-configured: '
                . implode(' and ', $missing) . ' is not set. Set both keys or disable the'
                . ' feature. See: php artisan rsx:man turnstile'
            );
        }

        return [$site_key, $secret_key];
    }

    /**
     * Stop the request as a verification failure, rendering the response DIRECTLY for the
     * channel it arrived on (the shape and the rationale are Rsx_Csrf::__reject's):
     *
     *   - inside Ajax::internal() (a batch sub-call, or a programmatic call): the coded
     *     form-error exception, which Ajax_Batch_Controller's per-call catch already maps
     *     to that one call's slot in the batch response;
     *   - /_ajax* and /_upload: the AJAX error contract (200 + _success:false + a
     *     'validation' error_code), identical in shape to an endpoint validation failure,
     *     thrown as HttpResponseException so no exception backtrace can leak;
     *   - a native full-page POST (staff or portal): flash the message and redirect back to
     *     the same URL as a GET - the same shape __handle_special_response() gives a
     *     validation error on a POST.
     *
     * @return never
     */
    private static function __reject(Request $request, string $message): never
    {
        if (Ajax::_is_internal_call()) {
            throw new AjaxFormErrorException($message);
        }

        $path = '/' . ltrim($request->getPathInfo(), '/');
        $is_ajax = str_contains($path, '/_ajax') || str_ends_with($path, '/_upload');

        if ($is_ajax) {
            throw new HttpResponseException(response()->json([
                '_success' => false,
                'error_code' => Ajax::ERROR_VALIDATION,
                'reason' => $message,
                'metadata' => ['_message' => $message],
            ]));
        }

        Flash_Alert::error($message);

        throw new HttpResponseException(redirect($request->url()));
    }
}
