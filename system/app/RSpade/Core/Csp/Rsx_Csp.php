<?php

namespace App\RSpade\Core\Csp;

use RuntimeException;
use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Rsx;

/**
 * Rsx_Csp - the framework's Content-Security-Policy composer.
 *
 * ONE POLICY PER REALM, COMPOSED IN ONE PLACE. Every RSX page response gets its header from
 * here (Dispatcher::__transform_response for staff, Portal_Dispatcher::__build_response for
 * the portal), so there is exactly one description of what a page may load and it is derived,
 * never hand-written:
 *
 * - Framework inline scripts carry a NONCE. `'unsafe-inline'` never appears in script-src.
 * - External hosts come from the declarative registry (Rsx_Externals::csp_hosts_for_realm),
 *   so an undeclared resource is a blocked resource and policy cannot drift from code.
 * - The bundle's development-mode CDN assets whitelist themselves as they are emitted (see
 *   note_asset_origin below); a sealed build serves them from /_vendor and needs nothing.
 *
 * THE NONCE IS REQUEST-SCOPED AND MEMOIZED. Everything that emits an inline <script> during a
 * request must stamp the SAME value the header carries, including Debugger's shutdown-time
 * console echo, which has no Response object to consult - hence a static, not a header read.
 *
 * STYLE-SRC CARRIES NO NONCE, DELIBERATELY. A nonce present in style-src makes browsers IGNORE
 * 'unsafe-inline', and RSX (jqhtml, view transitions, every style="" attribute in the tree)
 * depends on inline styles pervasively. Adding one here would break rendering everywhere.
 *
 * ROLLOUT. `csp.report_only` (default true) picks the header NAME: report-only observes and
 * reports without blocking anything, which is how a policy is proven before it is enforced.
 * `csp.enabled` (default true) turns the whole thing off - compose() then returns null and no
 * header is emitted at all.
 *
 * OUT OF SCOPE: responses that never reach the RSX dispatchers (vendor error pages, ignition)
 * carry no policy. AssetHandler's static-HTML responses set their own and are left alone.
 *
 * See: php artisan rsx:man csp
 */
class Rsx_Csp
{
    /** Where violation reports are posted. Served by Csp_Report_Controller. */
    public const REPORT_PATH = '/_csp-report';

    /** The header name when the policy is enforced. */
    public const HEADER_ENFORCE = 'Content-Security-Policy';

    /** The header name while the policy is only observed. */
    public const HEADER_REPORT_ONLY = 'Content-Security-Policy-Report-Only';

    /**
     * Directives a config `additional_sources` entry may never touch.
     *
     * The merge is WIDEN-ONLY: sources are appended, never replaced. That is coherent for a
     * host list and incoherent for a directive whose whole meaning is "nothing" - appending
     * to `object-src 'none'` would LOOSEN the hardening while reading like an addition. So an
     * attempt is refused loudly rather than silently reinterpreted.
     */
    public const UNWIDENABLE_DIRECTIVES = ['object-src'];

    /** The request's nonce, minted once on first use. */
    protected static ?string $_nonce = null;

    /**
     * directive => origins, contributed by asset emission during this request.
     *
     * Development serves bundle CDN assets from their own hosts (a sealed build mirrors them
     * to /_vendor, which is same-origin and needs no whitelist entry). Rather than
     * re-deriving that set by walking every bundle definition at header time, the emitter
     * declares what it emitted: Rsx_Bundle_Abstract calls note_asset_origin() on exactly the
     * branches that write an external URL into the page. The whitelist is therefore EXACTLY
     * what this response loads - never a superset - and costs one array append per asset.
     *
     * Ordering is safe: the view (and so the bundle HTML) is fully rendered before the
     * dispatcher transforms the response and asks for the header.
     */
    protected static array $_asset_origins = [];

    /**
     * The nonce for this request. Stable for the whole request, minted lazily.
     */
    public static function nonce(): string
    {
        if (static::$_nonce === null) {
            static::$_nonce = base64_encode(random_bytes(18));
        }

        return static::$_nonce;
    }

    /**
     * Record an external origin this response actually loads an asset from.
     *
     * @param string $directive 'script-src' or 'style-src'
     * @param string $url the absolute external URL being emitted
     */
    public static function note_asset_origin(string $directive, string $url): void
    {
        static::$_asset_origins[$directive][] = Rsx_Externals::url_origin($url);
    }

    /**
     * Drop the request-scoped state (nonce + noted origins).
     *
     * The testing seam, and the same shape Rsx_Turnstile::_reset_request_state() uses: a PHP
     * test process is one long request, so a test asserting nonce STABILITY and a test
     * asserting a fresh nonce must be able to draw the line themselves.
     */
    public static function _reset_request_state(): void
    {
        static::$_nonce = null;
        static::$_asset_origins = [];
    }

    /**
     * Is a policy being emitted at all?
     */
    public static function is_enabled(): bool
    {
        return (bool) config('rsx.csp.enabled', true);
    }

    /**
     * Observe-only (true) or enforce (false)?
     */
    public static function is_report_only(): bool
    {
        return (bool) config('rsx.csp.report_only', true);
    }

    /**
     * The header this realm's pages should carry, or null when CSP is disabled.
     *
     * @param string $realm 'staff' or 'portal'
     * @return array{header: string, value: string}|null
     */
    public static function compose(string $realm): ?array
    {
        if (!static::is_enabled()) {
            return null;
        }

        $directives = static::_base_directives();

        // Assets this response emitted from an external host (development CDN assets).
        foreach (static::$_asset_origins as $directive => $origins) {
            static::_add_sources($directives, $directive, $origins);
            static::_add_stylesheet_fonts($directives, $directive, $origins);
        }

        // Declared external resources: asset origins still fetched externally in this mode,
        // plus each entry's runtime csp extras (frames it opens, hosts it calls).
        foreach (Rsx_Externals::csp_hosts_for_realm($realm) as $directive => $sources) {
            static::_add_sources($directives, $directive, $sources);
            static::_add_stylesheet_fonts($directives, $directive, $sources);
        }

        // Config widening. ONLY for transitive externals - a script that loads further
        // scripts of its own, which no declaration can enumerate. A resource the page loads
        // itself belongs in a *.externals.php declaration instead.
        foreach ((array) config('rsx.csp.additional_sources', []) as $directive => $sources) {
            if (in_array($directive, self::UNWIDENABLE_DIRECTIVES, true)) {
                throw new RuntimeException(
                    "Invalid csp.additional_sources directive '{$directive}'\n" .
                    "  This directive is hardening, not a host list: the framework sets it to 'none' and\n" .
                    "  additional_sources can only APPEND, which would loosen it while reading like an addition.\n" .
                    '  Unwidenable directives: ' . implode(', ', self::UNWIDENABLE_DIRECTIVES) . ".\n" .
                    '  See: php artisan rsx:man csp'
                );
            }

            static::_add_sources($directives, $directive, (array) $sources);
        }

        $parts = [];

        foreach ($directives as $directive => $sources) {
            $parts[] = empty($sources) ? $directive : $directive . ' ' . implode(' ', $sources);
        }

        $parts[] = 'report-uri ' . self::REPORT_PATH;

        return [
            'header' => static::is_report_only() ? self::HEADER_REPORT_ONLY : self::HEADER_ENFORCE,
            'value' => implode('; ', $parts),
        ];
    }

    /**
     * Stamp a realm's policy onto a dispatched response.
     *
     * HTML-ONLY, and the rule is deliberate: a policy governs what a DOCUMENT may load, so a
     * JSON envelope, a download or a rendition gains nothing from carrying one and pays for it
     * in bytes on every response. "HTML-ish" means a declared text/html type OR no declared
     * type at all - a plain response($string) has not been through prepare() yet and will
     * default to text/html, while every non-HTML producer in the framework sets its type
     * explicitly. A response already carrying a policy of its own (AssetHandler's static HTML)
     * is left exactly as it is.
     *
     * @param mixed $response anything a dispatcher may be holding; non-responses are ignored
     * @param string $realm 'staff' or 'portal'
     * @return void
     */
    public static function apply_to_response($response, string $realm): void
    {
        if (!$response instanceof \Symfony\Component\HttpFoundation\Response) {
            return;
        }

        if ($response->headers->has(self::HEADER_ENFORCE) || $response->headers->has(self::HEADER_REPORT_ONLY)) {
            return;
        }

        $content_type = $response->headers->get('Content-Type');

        if ($content_type !== null && !str_starts_with(strtolower($content_type), 'text/html')) {
            return;
        }

        $policy = static::compose($realm);

        if ($policy === null) {
            return;
        }

        $response->headers->set($policy['header'], $policy['value']);
    }

    /**
     * The framework's own directives, before anything is merged in.
     */
    protected static function _base_directives(): array
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-" . static::nonce() . "'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        if (Realtime::is_enabled()) {
            // Must match the authority the client actually connects to, port included
            // (see Rsx::get_http_host) - a portless whitelist blocks ws://localhost:8080.
            $host = Rsx::get_http_host();

            // The canonical form the client is handed is wss://. Rsx_Realtime._connect_url
            // downgrades it to ws:// whenever the PAGE itself is plain http (loopback and the
            // rsx:debug harness), which is possible in every mode except strict production -
            // where http is not served at all. Whitelisting ws:// only outside strict
            // production mirrors that downgrade exactly, rather than guessing.
            $directives['connect-src'][] = 'wss://' . $host;

            if (!Rsx::is_production()) {
                $directives['connect-src'][] = 'ws://' . $host;
            }
        }

        return $directives;
    }

    /**
     * An external stylesheet's origin also serves that stylesheet's fonts.
     *
     * DERIVED, not configured: a webfont stylesheet @font-face's against its OWN origin as a
     * matter of course (bootstrap-icons on jsdelivr does exactly this), so an origin already
     * trusted to deliver CSS - which can restyle the entire page - is trusted for the font
     * files that CSS names. Without this every icon font would report a font-src violation
     * that no declaration could ever fix, since nothing in our code names the font URL.
     *
     * @param array $directives
     * @param string $directive the directive the origins were contributed for
     * @param array $sources
     * @return void
     */
    protected static function _add_stylesheet_fonts(array &$directives, string $directive, array $sources): void
    {
        if ($directive !== 'style-src') {
            return;
        }

        static::_add_sources($directives, 'font-src', $sources);
    }

    /**
     * Append sources to a directive, creating it if the base policy did not declare it.
     *
     * A directive the base policy omits (frame-src, for instance) was falling back to
     * `default-src 'self'`, so a created directive is SEEDED with 'self' - otherwise adding
     * one external host would silently narrow the page's own same-origin allowance.
     */
    protected static function _add_sources(array &$directives, string $directive, array $sources): void
    {
        if (!isset($directives[$directive])) {
            $directives[$directive] = ["'self'"];
        }

        foreach ($sources as $source) {
            if (!in_array($source, $directives[$directive], true)) {
                $directives[$directive][] = $source;
            }
        }
    }
}
