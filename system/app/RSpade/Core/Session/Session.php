<?php

namespace App\RSpade\Core\Session;

use RuntimeException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;
use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Rsx_Session_Cookie;
use App\RSpade\Core\Session\User_Agent;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Session model - handles both authentication sessions and static session management
 *
 * This class serves dual purposes:
 * 1. As a Laravel Eloquent model for the sessions table
 * 2. As a static interface for session management (similar to RS3 design)
 *
 * ONE SESSION PER BROWSER. A session identifies a BROWSER - one `rsx` cookie, one
 * _sessions row, per cookie jar - and NOT an authentication realm. The row is a
 * PROPERTY BAG consumed by different parts of the site; no realm owns it. The
 * EXPERIENCE is a property of the REQUEST (Rsx_Portal::is_portal_request()), and all
 * it decides is which of the row's properties get consulted:
 *
 *   staff  properties: login_user_id, site_id, impersonator_login_user_id
 *   portal properties: portal_user_id, portal_site_id, impersonator_user_id,
 *                      handoff_token, handoff_expires_at, impersonation_started_at
 *
 * Both identities set at once on one row is NORMAL - the same human, in the same
 * browser, signed into the staff app and the client portal. This class owns the row
 * and the staff properties; Portal_Session is a facade over the portal-property
 * SUBSET, reaching them through the _*_portal_* seams at the bottom of this class.
 * There is exactly ONE activation path (__activate), so a portal login on a fresh
 * browser mints the row the same way a staff page view would.
 *
 * A dedicated portal domain needs no machinery: per-origin cookie jars mean that
 * browser holds its own row on that origin, with the staff properties simply null.
 *
 * The session represents a unique browser session with persistent authentication.
 * There is no "remember me" option: the cookie is always long-lived and the ROW is
 * what expires. Every session is stamped with a type_id at creation and is deleted
 * once it has gone unused for that type's window (rsx.sessions.*_timeout_minutes,
 * swept hourly by Session_Cleanup_Service) - so an in-use session lives forever and
 * an abandoned one does not accumulate.
 *
 * @FILE-SUBCLASS-01-EXCEPTION Class intentionally named Session instead of Session_Model
 * to maintain compatibility with static method calls like Session::init().  The developer will
 * almost never be interacting with a session orm record directly, so the term Session_Model
 * doesnt have much meaning.
 *
 * @property int $id
 * @property bool $active
 * @property int $site_id
 * @property int $login_user_id
 * @property int $portal_user_id
 * @property int $portal_site_id
 * @property string $session_token
 * @property string $csrf_token
 * @property string $ip_address
 * @property string $user_agent
 * @property \Carbon\Carbon $last_active
 * @property int $version
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _sessions
 *
 * @property int $id
 * @property bool $active
 * @property int $site_id
 * @property int $type_id
 * @property int $login_user_id
 * @property int $portal_user_id
 * @property int $portal_site_id
 * @property int $impersonator_login_user_id
 * @property int $impersonator_user_id
 * @property string $impersonation_started_at
 * @property string $session_token
 * @property string $csrf_token
 * @property string $handoff_token
 * @property string $handoff_expires_at
 * @property string $ip_address
 * @property string $user_agent
 * @property string $last_active
 * @property int $version
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $type_id__label
 * @property-read string $type_id__constant
 *
 * @method static array type_id__enum() Get all enum definitions with full metadata
 * @method static array type_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array type_id__enum_labels() Get simple id => label map
 * @method static array type_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Session extends Rsx_System_Model_Abstract
                     {
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const TYPE_WEB = 1;
    const TYPE_PLAYWRIGHT = 2;
    const TYPE_API = 3;
    const TYPE_CLI = 4;
    /**
     * Session type - stamped by the writer at creation (__classify_session_type),
     * NEVER inferred from the user-agent afterwards. Each type expires against its
     * own inactivity window (rsx.sessions.*_timeout_minutes), swept hourly by
     * Session_Cleanup_Service.
     */
    public static $enums = [
        'type_id' => [
            1 => ['constant' => 'TYPE_WEB', 'label' => 'Web', 'order' => 1],
            2 => ['constant' => 'TYPE_PLAYWRIGHT', 'label' => 'Playwright', 'order' => 2],
            3 => ['constant' => 'TYPE_API', 'label' => 'API', 'order' => 3],
            4 => ['constant' => 'TYPE_CLI', 'label' => 'CLI', 'order' => 4],
        ],
    ];

    /**
     * How long a TYPE_PLAYWRIGHT session may sit unused before any harness run may
     * collect it. Comfortably longer than a single rsx:debug render, so a run in
     * flight is never someone else's garbage.
     */
    private const STALE_PLAYWRIGHT_GRACE_SECONDS = 300;

    // Static session management properties
    private static $_session = null;

    private static $_site = null;

    private static $_login_user = null; // Authentication identity (Login_User_Model)

    private static $_user = null; // Site-specific user (User_Model)

    private static $_session_token = null;

    private static $_has_init = false;

    private static $_has_activate = false;

    private static $_has_set_cookie = false;

    // Request-scoped overrides
    private static $_request_site_id_override = null;

    // Headless API identity (cookie-less). When set: [login_user_id, site_id, user_id].
    // Consulted by the accessors ahead of every other tier; see _set_api_identity().
    private static ?array $_api_identity = null;

    // CLI identity overrides: the DECLARED context of this process (who the command
    // is acting as). Independent of whether a session row has been demanded yet - a
    // command that never asks for a session still gets coherent getters, and mints
    // nothing.
    private static $_cli_site_id = null;

    private static $_cli_login_user_id = null; // Authentication identity ID

    private static $_cli_user_id = null; // Site-specific user ID

    // Whether this process has registered its end-of-process session cleanup yet.
    // Registered once, when the process first mints a CLI session row.
    private static bool $_cli_shutdown_registered = false;

    // Impersonation (main-session, in-place identity swap) CLI/test overrides
    private static $_cli_impersonator_login_user_id = null; // Real principal behind an impersonation

    private static $_cli_impersonation_started_at = null; // ISO string, or null

    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per browser, per device, per automated run - the table this whole discipline
     * came from (62,617 rows for one login user).
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * The table associated with the model
     * @var string
     */
    protected $table = '_sessions';

    /**
     * Session rows are written on essentially every request (last-active bumps, version
     * bumps on identity change). That is per-request infrastructure churn, not user-facing
     * data anyone subscribes to, so it must never kick the realtime emitter engine.
     * @var bool
     */
    public static $realtime_silent = true;

    /**
     * The attributes that should be cast
     * @var array
     */
    protected $casts = [
        'active' => 'boolean',
        'site_id' => 'integer',
        'type_id' => 'integer',
        'login_user_id' => 'integer',
        'portal_user_id' => 'integer',
        'portal_site_id' => 'integer',
        'impersonator_user_id' => 'integer',
        'version' => 'integer',
        'last_active' => 'datetime',
    ];

    /**
     * Columns that should never be exported to JavaScript
     * @var array
     */
    protected $neverExport = [
        'session_token',
        'csrf_token',
        'ip_address',
        'handoff_token',
    ];

    /**
     * Whether the current request is an API request (authenticated via API key).
     * Set only by Session::_set_api_identity().
     */
    public static bool $_is_api_request = false;

    /**
     * Check if the current request is an API request
     */
    public static function is_api_request(): bool
    {
        return static::$_is_api_request;
    }

    /**
     * Establish a headless, cookie-less API identity for the current request.
     *
     * Called exactly once by Api_Dispatcher after a Bearer key authenticates. The
     * identity is served by the accessors ahead of every other tier, and the session
     * cookie machinery is disabled for the rest of the request: no cookie is read, no
     * _sessions row is created, and no Set-Cookie is emitted. Throws on a second call.
     *
     * @param int $login_user_id Authentication identity (login_users.id)
     * @param int $site_id       Tenant scope (users.site_id)
     * @param int $user_id       Site-specific user (users.id)
     */
    public static function _set_api_identity(int $login_user_id, int $site_id, int $user_id): void
    {
        if (self::$_api_identity !== null) {
            shouldnt_happen('Session::_set_api_identity() called twice in one request');
        }

        self::$_api_identity = [
            'login_user_id' => $login_user_id,
            'site_id' => $site_id,
            'user_id' => $user_id,
        ];
        self::$_is_api_request = true;

        // Kill the cookie/session machinery for this request.
        self::$_has_init = true;
        self::$_has_set_cookie = true;
        self::$_session = null;

        // Drop resolved caches so they re-derive from the API identity.
        self::$_login_user = null;
        self::$_user = null;
        self::$_site = null;
    }

    /**
     * Clear the API identity tier and the flags it set. Test support ONLY - a live
     * request establishes the identity once and never tears it down.
     */
    public static function _reset_api_identity(): void
    {
        self::$_api_identity = null;
        self::$_is_api_request = false;
        self::$_has_init = false;
        self::$_has_set_cookie = false;
        self::$_session = null;
        self::$_login_user = null;
        self::$_user = null;
        self::$_site = null;
    }

    /**
     * Check if running in CLI mode
     * @return bool
     */
    private static function __is_cli(): bool
    {
        return php_sapi_name() === 'cli';
    }

    /**
     * Initialize session from cookie or request
     * Loads existing session but does not create new one
     *
     * In CLI mode: nothing to load. A CLI process has no cookie to resume from, so
     * there is never an EXISTING session to find - the process's session (if it ever
     * demands one) is minted by __activate().
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$_has_init) {
            return;
        }
        self::$_has_init = true;

        // CLI mode: no cookie, nothing to resume
        if (self::__is_cli()) {
            return;
        }

        Manifest::init();

        // Try to get session token from cookie or request
        $session_token = $_COOKIE['rsx'] ?? null;

        if (empty($session_token)) {
            self::$_session = null;

            return;
        }

        // One cookie, one row: the token identifies the BROWSER's session, whatever
        // identities it happens to carry.
        $session = static::where('session_token', $session_token)
            ->where('active', true)
            ->first();

        if (!$session) {
            self::$_session = null;

            return;
        }

        // Update last activity (but don't save immediately to avoid version conflicts)
        $session->last_active = now();

        // We'll let the session save happen later if needed (e.g. in set_user)
        // For simple page loads, we can update last_active in a separate query
        static::where('id', $session->id)->update(['last_active' => now()]);

        // Reload the session to ensure we have the latest version
        $session = static::find($session->id);

        self::$_session = $session;

        // Re-emit the SAME token cookie on every response (sliding 365-day expiry).
        // The token STRING is immutable for the life of the session (owner ruling
        // 2026-07-24: "reissued" means given a NEW string - only __activate()'s
        // create-new-session branch ever mints one); re-sending the identical value
        // merely refreshes the browser-side expiry and can never desync from the row.
        self::$_session_token = $session_token;
        self::_set_cookie();
    }

    /**
     * Activate session - creates new one if needed.
     *
     * THE ONE ACTIVATION PATH. A portal login on a fresh browser arrives here too
     * (through Session::_portal_activate()) and mints exactly the row a staff page
     * view would: same cookie, same token machinery, portal properties written
     * afterwards. There is no second creation path and no site argument - a row
     * belongs to a browser, not to a tenant.
     *
     * In CLI mode this mints a REAL _sessions row (TYPE_CLI), held for the life of
     * the process and deleted when the process ends - see __activate_cli().
     *
     * @return void
     */
    private static function __activate(): void
    {
        if (self::$_has_activate) {
            return;
        }
        self::$_has_activate = true;

        // CLI mode: mint a process-scoped session row (no cookie, no headers)
        if (self::__is_cli()) {
            self::__activate_cli();

            return;
        }

        self::init();

        // If no session exists, create one
        if (empty(self::$_session)) {
            // Generate cryptographically secure token
            self::$_session_token = bin2hex(random_bytes(32));

            // Generate CSRF token
            $csrf_token = bin2hex(random_bytes(32));

            $session = new static();
            $session->session_token = self::$_session_token;
            $session->csrf_token = $csrf_token;
            $session->ip_address = self::__get_client_ip();
            $session->user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
            $session->last_active = now();
            $session->active = true;
            $session->site_id = 0;
            $session->type_id = self::__classify_session_type();
            $session->version = 1;

            $session->save();

            self::$_session = $session;
            self::_set_cookie();
        }
    }

    /**
     * Mint this CLI process's session row.
     *
     * get_session_id() means "the id of the session, making one if I have to", and
     * that promise holds in every mode. A CLI process has no cookie to resume from,
     * so the row is minted on first demand and belongs to this process alone:
     *
     *   - a real _sessions row, so anything holding a session_id FK (flash alerts,
     *     and whatever follows) has a genuine parent row to point at;
     *   - a real token, minted and never transmitted - there is no response to put
     *     a cookie on and no header is emitted (see _set_cookie);
     *   - ip_address 'CLI', the existing convention for "no request created this";
     *   - TYPE_CLI, so retention can tell a command's throwaway row apart from a
     *     browser's (rsx.sessions.cli_timeout_minutes);
     *   - seeded from the CLI identity overrides in force at mint time, and kept in
     *     step with them afterwards by __cli_sync_session().
     *
     * The row is DELETED at process end (_cli_end_session, registered here). The
     * TYPE_CLI retention window is the backstop for a process that was killed.
     *
     * Laziness is preserved: nothing here runs until something actually demands a
     * session, so a command that never asks creates nothing.
     *
     * @return void
     */
    private static function __activate_cli(): void
    {
        if (!empty(self::$_session)) {
            return;
        }

        // Minted like any other session token, but never transmitted: CLI emits no
        // headers and sets no cookie. It exists so the row satisfies the column's
        // uniqueness contract and so find_by_token() cannot collide.
        self::$_session_token = bin2hex(random_bytes(32));

        $session = new static();
        $session->session_token = self::$_session_token;
        $session->csrf_token = bin2hex(random_bytes(32));
        $session->ip_address = self::__get_client_ip();
        $session->user_agent = 'CLI';
        $session->last_active = now();
        $session->active = true;
        $session->site_id = self::$_cli_site_id ?? 0;
        $session->type_id = self::TYPE_CLI;
        $session->login_user_id = self::$_cli_login_user_id;
        $session->version = 1;

        $session->save();

        self::$_session = $session;

        self::__register_cli_shutdown();
    }

    /**
     * Push the current CLI identity overrides onto this process's session row, if one
     * has been minted. No-op when nothing has demanded a session yet - keeping the
     * setters lazy is the point.
     *
     * @return void
     */
    private static function __cli_sync_session(): void
    {
        if (empty(self::$_session)) {
            return;
        }

        self::$_session->site_id = self::$_cli_site_id ?? 0;
        self::$_session->login_user_id = self::$_cli_login_user_id;

        if (self::$_session->isDirty()) {
            self::$_session->save();
        }
    }

    /**
     * Register the end-of-process deletion of this process's CLI session row.
     * Registered exactly once, at mint time, so a process that never asks for a
     * session installs no hook at all.
     *
     * @return void
     */
    private static function __register_cli_shutdown(): void
    {
        if (self::$_cli_shutdown_registered) {
            return;
        }
        self::$_cli_shutdown_registered = true;

        register_shutdown_function([self::class, '_cli_end_session']);
    }

    /**
     * Delete this process's CLI session row and forget it.
     *
     * The process IS the session's lifetime: nothing can resume a row nobody was
     * ever given a cookie for, so leaving it behind would only be litter. Runs at
     * shutdown (including after a fatal), and is also the seam the test runner uses
     * to drop the handle between tests - a row minted inside a rolled-back test
     * transaction no longer exists, and static state must not point at it.
     *
     * Safe to call at any time and in any mode: it is a no-op unless this process
     * actually minted a CLI session.
     *
     * @return void
     */
    public static function _cli_end_session(): void
    {
        if (!self::__is_cli() || empty(self::$_session)) {
            return;
        }

        $session_id = (int) self::$_session->id;

        self::$_session = null;
        self::$_session_token = null;
        self::$_has_init = false;
        self::$_has_activate = false;

        // One statement, no lifecycle: the row is this process's own bookkeeping.
        // A row already gone (rolled-back test transaction) simply matches nothing.
        static::where('id', $session_id)->raw_bulk()->delete();
    }

    /**
     * Classify a session being created, at the moment of creation.
     *
     * Type is a WRITER-SIDE fact: what created this row, known here for certain and
     * recorded once. It is deliberately never re-derived from the user-agent later -
     * a UA is attacker-controlled and, as a retention input, was what let 62k harness
     * sessions masquerade as browser sessions until a page tried to list them.
     *
     * TYPE_API has no writer today: a Bearer-authenticated request runs a cookie-less
     * headless identity and mints no row at all (see _set_api_identity). The branch
     * keeps the classifier total, and covers the day API handling grows a real session.
     *
     * TYPE_CLI is not classified here - a CLI session is minted by __activate_cli(),
     * which knows exactly what it is making and stamps the type itself.
     *
     * @return int Session::TYPE_*
     */
    private static function __classify_session_type(): int
    {
        if (self::$_api_identity !== null) {
            return self::TYPE_API;
        }

        // The rsx:debug / Playwright harness drives a real browser and stamps
        // X-Playwright-Test on every request it makes to us. Those rows belong to a
        // test run, not to a person: the request deletes its own at shutdown
        // (delete_on_shutdown) and the playwright window backstops the rest.
        if (!empty($_SERVER['HTTP_X_PLAYWRIGHT_TEST'])) {
            return self::TYPE_PLAYWRIGHT;
        }

        return self::TYPE_WEB;
    }

    /**
     * Set the session cookie with security flags
     *
     * In CLI mode: emits nothing. A CLI process has a real session row but no
     * response to attach a cookie to and no headers to send; its token is never
     * transmitted and the process is the session's whole lifetime.
     *
     * @return void
     */
    private static function _set_cookie(): void
    {
        if (self::$_has_set_cookie) {
            return;
        }
        self::$_has_set_cookie = true;

        // CLI mode: no response, no headers, no cookie
        if (self::__is_cli()) {
            return;
        }

        // ONE cookie for the whole site - staff pages and portal pages alike.
        setcookie('rsx', self::$_session_token, Rsx_Session_Cookie::options(time() + (365 * 86400)));
    }

    /**
     * The requesting client's IP address as the framework sees it - the ONE place the proxy
     * header chain is interpreted. Reads no session state and creates nothing, so it is safe
     * on any code path (including ones that must not mint a session).
     *
     * Returns null when there is no request context at all (CLI, tasks, programmatic work).
     * A caller that needs a non-null string supplies its own placeholder.
     *
     * @return string|null Client IP, or null outside a request
     */
    public static function get_client_ip(): ?string
    {
        if (self::__is_cli()) {
            return null;
        }

        // Check for forwarded IP (when behind proxy/CDN)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);

            return trim($ips[0]);
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get client IP address for a session row, handling proxies
     * In CLI mode: returns "CLI" (the column is NOT NULL and records what created the row)
     * @return string
     */
    private static function __get_client_ip(): string
    {
        return self::get_client_ip() ?? 'CLI';
    }

    /**
     * Reset/logout the current session.
     *
     * Retires the BROWSER's session wholesale: the row is deactivated and the `rsx`
     * cookie cleared, so any portal identity riding the same row goes with it. That
     * is the correct reading of "reset the session" under one-session-per-browser -
     * a staff sign-out that must leave the portal identity alone is logout()
     * (set_login_user_id(null)), which clears only the staff properties.
     *
     * In CLI the session row IS the process's own bookkeeping and nothing else can
     * ever resume it, so the hard exit deletes it outright rather than deactivating
     * it. No cookie is cleared (none was ever set).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::init();

        if (self::__is_cli()) {
            self::_cli_end_session();

            // Reset is the hard exit - impersonation cannot span it
            self::$_cli_impersonator_login_user_id = null;
            self::$_cli_impersonation_started_at = null;

            self::$_site = null;
            self::$_user = null;

            return;
        }

        if (!empty(self::$_session)) {
            self::$_session->active = false;
            // reset()/logout() is the hard exit - impersonation cannot span it
            self::$_session->impersonator_login_user_id = null;
            self::$_session->impersonation_started_at = null;
            self::$_session->save();
        }

        // Clear impersonation CLI overrides (hard teardown, both modes)
        self::$_cli_impersonator_login_user_id = null;
        self::$_cli_impersonation_started_at = null;

        self::$_session = null;
        self::$_site = null;
        self::$_user = null;
        self::$_has_init = false;
        self::$_has_activate = false;
        self::$_has_set_cookie = false;

        // Clear cookie
        setcookie('rsx', '', Rsx_Session_Cookie::options(time() - 3600));
    }

    /**
     * Get site ID for current session
     * Respects request-scoped override first (for subdomain enforcement)
     * In CLI mode: returns static CLI property
     * @return int
     */
    public static function get_site_id(): int
    {
        // API request identity (headless) takes precedence over every other tier
        if (self::$_api_identity !== null) {
            return self::$_api_identity['site_id'];
        }

        // Request override takes precedence (subdomain enforcement)
        if (self::$_request_site_id_override !== null) {
            return self::$_request_site_id_override;
        }

        // CLI mode: return static property
        if (self::__is_cli()) {
            return self::$_cli_site_id ?? 0;
        }

        self::init();

        if (empty(self::$_session)) {
            return 0;
        }

        return self::$_session->site_id ?? 0;
    }

    /**
     * MISUSE GUARD - Session is NOT a session-variable bag; there is no get($key).
     *
     * Session extends the Eloquent model for the _sessions table, so a bare
     * Session::get('site_id') - which READS like Laravel's session()->get() - would
     * otherwise fall through static magic to Eloquent's Model::get(): a FULL-TABLE
     * SELECT hydrating every _sessions row (observed OOM at ~54k rows), returning a
     * Collection, never the value. This shadow intercepts exactly that misuse shape
     * and fails loud at the call site. Legitimate query-builder reads are unaffected:
     * Session::where(...)->get() / Session::query()->get() go through the Builder
     * instance, not this static.
     *
     * @throws Rsx_Caller_Exception always
     */
    public static function get(...$args): never
    {
        throw new Rsx_Caller_Exception(
            'Session::get() does not exist - Session is the _sessions Eloquent model, not a session-variable bag. '
            . 'Use the named accessor instead: get_site_id(), get_user_id(), get_login_user_id(), get_session_id(), '
            . 'get_user(), get_site(), get_csrf_token(). For a query, use Session::where(...)->get().'
        );
    }

    /**
     * Get site model for current session
     * @return Site_Model|null
     */
    public static function get_site()
    {
        $site_id = self::get_site_id();

        if ($site_id === 0) {
            return null;
        }

        if (empty(self::$_site)) {
            self::$_site = Site_Model::find($site_id);
        }

        return self::$_site;
    }

    /**
     * Get login user ID (authentication identity) for current session
     * In CLI mode: returns static CLI property
     * @return int|null
     */
    public static function get_login_user_id()
    {
        // API request identity (headless) takes precedence
        if (self::$_api_identity !== null) {
            return self::$_api_identity['login_user_id'];
        }

        // CLI mode: return static property
        if (self::__is_cli()) {
            return self::$_cli_login_user_id;
        }

        self::init();

        if (empty(self::$_session)) {
            return null;
        }

        return self::$_session->login_user_id;
    }

    /**
     * Get site-specific user ID for current session
     * This is the users.id (site-specific), not login_users.id
     * In CLI mode: returns static CLI property
     * @return int|null
     */
    public static function get_user_id()
    {
        // API request identity (headless) takes precedence
        if (self::$_api_identity !== null) {
            return self::$_api_identity['user_id'];
        }

        // CLI mode: return static property
        if (self::__is_cli()) {
            return self::$_cli_user_id;
        }

        $user = self::get_user();

        return $user ? $user->id : null;
    }

    /**
     * Check if user is logged in
     * @return bool
     */
    public static function is_logged_in(): bool
    {
        return !empty(self::get_login_user_id());
    }

    /**
     * Get login user model (authentication identity) for current session
     * @return Login_User_Model|null
     */
    public static function get_login_user()
    {
        $login_user_id = self::get_login_user_id();

        if (empty($login_user_id)) {
            return null;
        }

        if (empty(self::$_login_user)) {
            self::$_login_user = Login_User_Model::find($login_user_id);
        }

        return self::$_login_user;
    }

    /**
     * Get site-specific user model for current session
     * @return User_Model|null
     */
    public static function get_user()
    {
        $login_user_id = self::get_login_user_id();
        $site_id = self::get_site_id();

        if (empty($login_user_id) || empty($site_id)) {
            return null;
        }

        if (empty(self::$_user)) {
            self::$_user = User_Model::where('login_user_id', $login_user_id)
                ->where('site_id', $site_id)
                ->first();
        }

        return self::$_user;
    }

    /**
     * Get current session model (creates if needed)
     * @return Session
     */
    public static function get_session(): Session
    {
        // No session row backs an API request (headless, cookie-less)
        if (self::$_api_identity !== null) {
            shouldnt_happen('Session::get_session() is unavailable in an API request');
        }

        self::__activate();

        return self::$_session;
    }

    /**
     * Get current session ID (creates session if needed)
     *
     * Always yields a real session, in every mode. In CLI that means minting a
     * TYPE_CLI row on first demand and holding it for the process (deleted at
     * process end); see __activate_cli().
     *
     * The ONE exception is a Bearer-authenticated API request, which today runs a
     * cookie-less headless identity backed by no row at all and returns 0. That is
     * a placeholder: when the public API grows a real identity session it will be
     * resolved from the bearer token here, and this branch goes away. Until then,
     * has_session() is how a caller asks whether a session exists.
     *
     * @return int
     */
    public static function get_session_id(): int
    {
        // API request: no session row exists yet (headless, cookie-less)
        if (self::$_api_identity !== null) {
            return 0;
        }

        self::__activate();

        return self::$_session->id;
    }

    /**
     * Get CSRF token for current session
     * @return string|null
     */
    public static function get_csrf_token(): ?string
    {
        // API request: no session, no CSRF token
        if (self::$_api_identity !== null) {
            return null;
        }

        self::init();

        if (empty(self::$_session)) {
            return null;
        }

        return self::$_session->csrf_token;
    }

    /**
     * Verify CSRF token
     * @param string $token
     * @return bool
     */
    public static function verify_csrf_token(string $token): bool
    {
        self::init();

        if (empty(self::$_session)) {
            return false;
        }

        // Use constant-time comparison
        return hash_equals(self::$_session->csrf_token, $token);
    }

    /**
     * Logout current user
     * @return void
     */
    public static function logout(): void
    {
        self::set_login_user_id(null);
    }

    /**
     * Set login user ID for current session (login/logout)
     * In CLI mode: sets static CLI property only, no database
     * @param int|null $login_user_id Login user ID, or null to logout
     * @param bool $touch_last_login Whether to bump the login user's last_login
     *        timestamp. Real logins pass true (default); an impersonation identity
     *        swap passes false (impersonation is not a real login).
     * @return void
     */
    public static function set_login_user_id(?int $login_user_id, bool $touch_last_login = true): void
    {
        // API request identity is immutable - endpoints must not log in/out
        if (self::$_api_identity !== null) {
            shouldnt_happen('Session::set_login_user_id() cannot mutate identity in an API request');
        }

        // Logout if null/0
        if (empty($login_user_id)) {
            // CLI mode: clear the identity overrides; the process's session row (if
            // one has been minted) follows them.
            if (self::__is_cli()) {
                self::$_cli_login_user_id = null;
                self::$_cli_user_id = null;
                // Logout is a hard exit - impersonation cannot span it
                self::$_cli_impersonator_login_user_id = null;
                self::$_cli_impersonation_started_at = null;
                self::$_login_user = null;
                self::$_user = null;
                self::$_site = null;

                self::__cli_sync_session();

                return;
            }

            self::__activate();
            // Confirmed-different gating: only a real logged-in -> logged-out transition
            // pushes a refresh (an already-anonymous session emits nothing).
            $old_login_user_id = self::$_session->login_user_id;
            self::$_session->login_user_id = null;
            // Logout is a hard exit - impersonation cannot span it
            self::$_session->impersonator_login_user_id = null;
            self::$_session->impersonation_started_at = null;
            self::$_session->save();

            self::$_login_user = null;
            self::$_user = null;
            self::$_site = null;

            // Push every live connection holding this session to reload (it just lost auth).
            if (!empty($old_login_user_id)) {
                Realtime::push_session_refresh('staff', (int) self::$_session->id);
            }

            return;
        }

        // CLI mode: set the identity override; the process's session row (if one has
        // been minted) follows it. Deliberately does NOT mint one - a command that
        // declares who it is acting as has not asked for a session.
        if (self::__is_cli()) {
            self::$_cli_login_user_id = $login_user_id;
            self::$_cli_user_id = null;
            self::$_login_user = null;
            self::$_user = null;
            self::$_site = null;

            self::__cli_sync_session();

            return;
        }

        self::__activate();

        // Confirmed-different gating for the refresh push (impersonation begin/stop delegate
        // here and inherit it): capture the prior identity before overwriting.
        $old_login_user_id = self::$_session->login_user_id;

        // A login / identity change is a PURE RECORD UPDATE. The session_token and
        // csrf_token were minted exactly once, at session creation (__activate),
        // and are IMMUTABLE for the life of the session - they are NEVER
        // regenerated here, and the cookie is NOT re-emitted. Token rotation at
        // login was deliberately removed (owner ruling 2026-07-24): RSX tokens are
        // server-minted only and never adopted from the client, so the classic
        // session-fixation vector (a planted cookie the victim keeps) does not
        // apply, and rotating on an in-place identity swap caused a cookie-desync
        // race. See rsx:man session (SECURITY). self::$_session_token is NOT touched.
        self::$_session->login_user_id = $login_user_id;
        self::$_session->save();

        // Clear cached login_user/user/site
        self::$_login_user = null;
        self::$_user = null;
        self::$_site = null;

        // Identity changed to a confirmed-different value -> push a refresh to every live
        // connection on this session (re-setting the same login_user_id emits nothing).
        if ((int) $old_login_user_id !== (int) $login_user_id) {
            Realtime::push_session_refresh('staff', (int) self::$_session->id);
        }

        // Sign-in is the moment this user's session count grows, so it is where the
        // concurrent-session cap is applied.
        self::__enforce_web_session_cap((int) $login_user_id);

        // Update login user's last login timestamp (skipped for impersonation swaps)
        if ($touch_last_login) {
            $login_user_record = self::get_login_user();
            if ($login_user_record) {
                $login_user_record->last_login = now();
                $login_user_record->save();
            }
        }
    }

    /**
     * Sign out this user's oldest WEB sessions beyond the configured cap.
     *
     * Called from the sign-in path (set_login_user_id), because sign-in is the only
     * moment a user's session count grows. The N most recently active web sessions
     * survive; everything older is deactivated, which ends it - init() only loads a
     * row with active = true.
     *
     * PLAYWRIGHT and API sessions are excluded from both the count and the eviction:
     * a test run must never sign a developer out of their own browser, and machine
     * sessions have their own (much shorter) retention.
     *
     * Disabled entirely when rsx.sessions.max_web_sessions_per_user is 0 or null.
     *
     * DELIBERATE DIVERGENCE from the termination family: cap eviction does NOT route
     * through _deactivate_sessions_for_user(), takes no authorization (there is no actor
     * terminating anyone - the user is signing THEMSELVES in), and fires no refresh push
     * or 'session.terminated' event. It is capacity management, not termination; the
     * evicted browser learns at its next request, which is existing behavior.
     *
     * @param int $login_user_id
     * @return void
     */
    private static function __enforce_web_session_cap(int $login_user_id): void
    {
        $max = config('rsx.sessions.max_web_sessions_per_user');

        if (empty($max)) {
            return;
        }

        // The ids to KEEP. This LIMIT is the cap itself - "the N most recent" is
        // precisely what the caller asked for - and N is small and config-bounded.
        $keep = static::where('login_user_id', $login_user_id)
            ->where('type_id', self::TYPE_WEB)
            ->where('active', true)
            ->orderBy('last_active', 'desc')
            ->orderBy('id', 'desc')
            ->limit((int) $max)
            ->pluck('id')
            ->all();

        static::where('login_user_id', $login_user_id)
            ->where('type_id', self::TYPE_WEB)
            ->where('active', true)
            ->whereNotIn('id', $keep)
            ->raw_bulk()
            ->update(['active' => false]);
    }

    /**
     * Set site for current session
     * In CLI mode: sets static CLI property only, no database
     * @param Site_Model|int $site Site model or site ID
     * @return void
     */
    /**
     * Set site ID
     * In CLI mode: sets static CLI property only
     * @param int $site_id
     * @return void
     */
    public static function set_site_id(int $site_id): void
    {
        // API request identity is immutable - endpoints must not switch tenant
        if (self::$_api_identity !== null) {
            shouldnt_happen('Session::set_site_id() cannot mutate identity in an API request');
        }

        // CLI mode: set the tenant override; the process's session row (if one has
        // been minted) follows it. Mints nothing - same laziness as the web branch
        // below, which stores a request-scoped override rather than creating a row.
        if (self::__is_cli()) {
            self::$_cli_site_id = $site_id;
            self::$_site = null;

            self::__cli_sync_session();

            return;
        }

        self::init();

        // If no session exists (anonymous visitor), store as request-scoped
        // override without creating a session. The site_id will be available
        // via get_site_id() for the duration of this request.
        if (empty(self::$_session)) {
            self::$_request_site_id_override = $site_id;
            self::$_site = null;

            return;
        }

        // Skip if already set (this guard IS the confirmed-different gate for the push below)
        if (self::get_site_id() === $site_id) {
            return;
        }

        self::$_session->site_id = $site_id;
        self::$_session->save();

        // Clear cached site
        self::$_site = null;

        // Tenant switched -> push a refresh to every live connection on this session.
        Realtime::push_session_refresh('staff', (int) self::$_session->id);
    }

    /**
     * Check if a session exists - the question get_session_id() does NOT answer,
     * because asking it creates one. Creates nothing itself, in any mode.
     *
     * In CLI mode: true once this process has minted its session row, and also true
     * when a site_id or user_id override declares a context (a command acting as a
     * user has a session context whether or not a row was ever demanded).
     * In web mode: returns true if session record exists
     * @return bool
     */
    public static function has_session(): bool
    {
        // API request: identity is not backed by a session row
        if (self::$_api_identity !== null) {
            return false;
        }

        // CLI mode: a minted row, or a declared identity context
        if (self::__is_cli()) {
            return !empty(self::$_session)
                || self::$_cli_site_id !== null
                || self::$_cli_user_id !== null;
        }

        // Web mode: init and check if session exists
        self::init();

        return !empty(self::$_session);
    }

    /**
     * Get session by token (for API/external access).
     *
     * The token identifies the BROWSER's session row, whatever identities it carries.
     * Portal_Session::find_by_token() is the same lookup narrowed to rows that
     * actually hold a portal identity.
     *
     * @param string $token
     * @return Session|null
     */
    public static function find_by_token(string $token)
    {
        return static::where('session_token', $token)
            ->where('active', true)
            ->first();
    }

    /**
     * Clean up expired sessions (garbage collection) - MANUAL helper.
     *
     * A blunt single-cutoff delete (no type distinction, no chunking) for
     * administrative and test use. The OPERATIONAL retention mechanism is
     * Session_Cleanup_Service (one hourly per-type sweep, chunked deletes) - do not
     * add a second scheduled caller here.
     *
     * @param int $days_until_expiry
     * @return int Number of sessions deleted
     */
    public static function cleanup_expired(int $days_until_expiry = 365): int
    {
        return static::where('last_active', '<', now()->subDays($days_until_expiry))
            ->delete();
    }

    /**
     * Delete the TYPE_PLAYWRIGHT sessions a harness run created.
     * Called by rsx:debug as the RUN exits.
     *
     * Deliberately run-scoped, not request-scoped. A per-request shutdown hook was
     * tried and reverted (2026-08-08): the dev-auth HMAC is computed over the
     * NAVIGATION url, so it only ever authenticates the first request of a run -
     * every XHR the page then fires authenticates purely by the session cookie that
     * first request established. Dropping the row at the end of the first request
     * therefore signs the page out of its own Ajax, and every SPA screen fails with
     * "You do not have permission to perform this action". The session must outlive
     * the request; the run is its real lifetime.
     *
     * Two predicates, both narrowed to TYPE_PLAYWRIGHT so a real browser session is
     * never in reach:
     *   - created during this run, which is the run's own droppings; and
     *   - idle longer than STALE_PLAYWRIGHT_GRACE_SECONDS, which collects the odd row
     *     that lands just after the sweep (a request the server was still finishing
     *     when the browser closed) on the NEXT run rather than making it wait out the
     *     playwright window. A live concurrent run is seconds old and untouched.
     *
     * A harness run's staff and portal browsing share one row, so TYPE_PLAYWRIGHT is
     * the whole predicate - and it is what keeps a real browser session out of reach.
     *
     * @param string $created_since Timestamp the run began (any Rsx_Time-parseable value)
     * @return int Rows deleted
     */
    public static function purge_playwright_sessions(string $created_since): int
    {
        $stale_before = Rsx_Time::to_database(
            Rsx_Time::subtract(Rsx_Time::now(), self::STALE_PLAYWRIGHT_GRACE_SECONDS)
        );

        return static::where('type_id', self::TYPE_PLAYWRIGHT)
            ->where(function ($query) use ($created_since, $stale_before) {
                $query->where('created_at', '>=', $created_since)
                      ->orWhere('last_active', '<', $stale_before);
            })
            ->raw_bulk()
            ->delete();
    }

    /**
     * Override save to increment version on updates
     * @param array $options
     * @return bool
     */
    public function save(array $options = []): bool
    {
        // Increment version on updates (but don't check for conflicts since sessions
        // are single-user and shouldn't have real concurrent modifications)
        if ($this->exists && $this->isDirty()) {
            $this->version = ($this->version ?? 1) + 1;
        }

        return parent::save($options);
    }

    /**
     * Relationship: Login User (authentication identity)
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function login_user()
    {
        return $this->belongsTo(Login_User_Model::class, 'login_user_id');
    }

    /**
     * Relationship: Site
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function site()
    {
        return $this->belongsTo(Site_Model::class, 'site_id');
    }

    /**
     * Relationship: Site-specific User (composite key relationship)
     * Returns the User_Model that matches both login_user_id and site_id
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function user()
    {
        return $this->hasOne(User_Model::class, 'login_user_id', 'login_user_id')
            ->where('users.site_id', '=', $this->site_id);
    }

    /**
     * Impersonate a full session context for CLI/testing. Sets the site, login
     * identity and site-specific user as static overrides, so the Session getters
     * return these values and site-scoped models / audit attribution see the
     * impersonated context.
     *
     * Creates no session row of its own (declaring a context is not demanding a
     * session), but if this process has already minted one, the row is updated to
     * match the new context.
     *
     * Only available in CLI mode (artisan / test runner) - there is no notion
     * of a static override in a web request.
     *
     * @param int $site_id
     * @param int|null $login_user_id Authentication identity (login_users.id)
     * @param int|null $user_id Site-specific user (users.id)
     * @return void
     */
    public static function impersonate(int $site_id, ?int $login_user_id, ?int $user_id): void
    {
        if (!self::__is_cli()) {
            shouldnt_happen('Session::impersonate() is only available in CLI/test context');
        }

        self::$_cli_site_id = $site_id;
        self::$_cli_login_user_id = $login_user_id;
        self::$_cli_user_id = $user_id;

        // Clear cached resolutions so the new context is reloaded on next access
        self::$_site = null;
        self::$_user = null;
        self::$_login_user = null;

        self::__cli_sync_session();
    }

    /**
     * Clear all impersonated CLI/test context (site, login identity, user).
     * Returns the static session state to a clean slate.
     *
     * A clean slate includes the process's session row: it was minted for the
     * context being torn down, so it is deleted and forgotten here. The next
     * demand for a session mints a fresh one against the new context.
     *
     * Only available in CLI mode.
     * @return void
     */
    public static function reset_impersonation(): void
    {
        if (!self::__is_cli()) {
            shouldnt_happen('Session::reset_impersonation() is only available in CLI/test context');
        }

        self::_cli_end_session();

        self::$_cli_site_id = null;
        self::$_cli_login_user_id = null;
        self::$_cli_user_id = null;

        self::$_site = null;
        self::$_user = null;
        self::$_login_user = null;
    }

    // =========================================================================
    // WEB IMPERSONATION ("Log in as user") - same-cookie, in-place identity swap
    //
    // Distinct from the CLI-only impersonate()/reset_impersonation() above (which
    // set static overrides for test setup). These persist on the live _sessions
    // row: they remember the real principal's login_user_id, swap login_user_id
    // to the target in place, and swap back on stop. Staff impersonation is full
    // read/write; whether to restrict anything is entirely the app's call (core
    // exposes only the is_impersonating() flag).
    // =========================================================================

    /**
     * Begin impersonating the given login user from the CURRENT session.
     * Records the current login_user_id as the impersonator and swaps login_user_id
     * to $target_login_user_id in place - a PURE RECORD UPDATE. The session_token +
     * csrf are stable for the life of the session (minted once at creation, never
     * rotated) and the cookie is not re-emitted. Keeps site_id. Does NOT bump the
     * target's last_login.
     *
     * Non-recursive: throws if already impersonating. Throws if there is no active
     * session to impersonate from, or if target === the current login_user_id.
     *
     * @param int $target_login_user_id
     * @return void
     */
    public static function begin_impersonation(int $target_login_user_id): void
    {
        // Must have an active principal to impersonate FROM
        if (!self::is_logged_in()) {
            shouldnt_happen('begin_impersonation requires an active logged-in session');
        }

        // Non-recursive - one level only
        if (self::is_impersonating()) {
            shouldnt_happen('begin_impersonation: already impersonating (nesting is not allowed)');
        }

        $impersonator_login_user_id = self::get_login_user_id();

        // Self-impersonation is a no-op that would only confuse restore semantics
        if ($target_login_user_id === $impersonator_login_user_id) {
            shouldnt_happen('begin_impersonation: cannot impersonate the current login user');
        }

        // CLI mode: record on static overrides, then swap identity
        if (self::__is_cli()) {
            self::$_cli_impersonator_login_user_id = $impersonator_login_user_id;
            self::$_cli_impersonation_started_at = Rsx_Time::now_iso();
            self::set_login_user_id($target_login_user_id, false);

            return;
        }

        self::__activate();

        // Stamp the impersonation columns on the live row; set_login_user_id()
        // persists them in the same save() when it swaps login_user_id (the token
        // is stable - no rotation, no cookie re-emission).
        self::$_session->impersonator_login_user_id = $impersonator_login_user_id;
        self::$_session->impersonation_started_at = now();
        self::set_login_user_id($target_login_user_id, false);
    }

    /**
     * End the current impersonation, restoring the real principal's login_user_id.
     * Clears the two impersonation columns and swaps login_user_id back - a PURE
     * RECORD UPDATE. The session_token + csrf are stable (never rotated) and the
     * cookie is not re-emitted. Returns false (no-op) when not impersonating. Does
     * NOT bump last_login on restore.
     *
     * @return bool True if an impersonation was ended, false if none was active
     */
    public static function stop_impersonation(): bool
    {
        if (!self::is_impersonating()) {
            return false;
        }

        $impersonator_login_user_id = self::get_impersonator_login_user_id();

        // CLI mode: clear static overrides, then swap identity back
        if (self::__is_cli()) {
            self::$_cli_impersonator_login_user_id = null;
            self::$_cli_impersonation_started_at = null;
            self::set_login_user_id($impersonator_login_user_id, false);

            return true;
        }

        self::__activate();

        // Clear the impersonation columns; set_login_user_id() persists them in the
        // same save() when it restores the principal (the token is stable - no
        // rotation, no cookie re-emission).
        self::$_session->impersonator_login_user_id = null;
        self::$_session->impersonation_started_at = null;
        self::set_login_user_id($impersonator_login_user_id, false);

        return true;
    }

    /**
     * Is the current main session an impersonation session?
     * In CLI mode: reads the static override.
     *
     * @return bool
     */
    public static function is_impersonating(): bool
    {
        if (self::__is_cli()) {
            return !empty(self::$_cli_impersonator_login_user_id);
        }

        self::init();

        if (empty(self::$_session)) {
            return false;
        }

        return !empty(self::$_session->impersonator_login_user_id);
    }

    /**
     * The real principal's login_user_id behind the impersonation, or null.
     * In CLI mode: reads the static override.
     *
     * @return int|null
     */
    public static function get_impersonator_login_user_id(): ?int
    {
        if (self::__is_cli()) {
            return self::$_cli_impersonator_login_user_id;
        }

        self::init();

        if (empty(self::$_session) || empty(self::$_session->impersonator_login_user_id)) {
            return null;
        }

        return (int) self::$_session->impersonator_login_user_id;
    }

    /**
     * The real principal as a Login_User_Model, or null when not impersonating.
     *
     * @return Login_User_Model|null
     */
    public static function get_impersonator_login_user(): ?Login_User_Model
    {
        $impersonator_login_user_id = self::get_impersonator_login_user_id();

        if (empty($impersonator_login_user_id)) {
            return null;
        }

        return Login_User_Model::find($impersonator_login_user_id);
    }

    /**
     * When the current impersonation started (ISO 8601 string), or null.
     * In CLI mode: reads the static override.
     *
     * @return string|null
     */
    public static function get_impersonation_started_at(): ?string
    {
        if (self::__is_cli()) {
            return self::$_cli_impersonation_started_at;
        }

        self::init();

        if (empty(self::$_session) || empty(self::$_session->impersonation_started_at)) {
            return null;
        }

        return Rsx_Time::to_iso(self::$_session->impersonation_started_at);
    }

    /**
     * Set the impersonator login_user_id in CLI mode (test seeding; mirrors
     * cli_set_* on Portal_Session). Raw setter, no side effects.
     *
     * @param int|null $login_user_id
     * @return void
     */
    public static function cli_set_impersonator_login_user_id(?int $login_user_id): void
    {
        self::$_cli_impersonator_login_user_id = $login_user_id;
    }

    /**
     * Set site_id override for current request (subdomain enforcement)
     * This overrides the database session site_id for THIS REQUEST ONLY
     * Does NOT modify the session record
     * Use case: User visits subdomain assigned to a tenant, enforce that tenant
     * @param int $site_id
     * @return void
     */
    public static function set_request_site_id_override(int $site_id): void
    {
        self::$_request_site_id_override = $site_id;
        self::$_site = null; // Clear cached site
    }

    /**
     * Clear site_id override (return to normal session-based site_id)
     * @return void
     */
    public static function clear_request_site_id_override(): void
    {
        self::$_request_site_id_override = null;
        self::$_site = null; // Clear cached site
    }

    /**
     * Check if request has site_id override active
     * @return bool
     */
    public static function has_request_site_id_override(): bool
    {
        return self::$_request_site_id_override !== null;
    }

    // =========================================================================
    // SESSION MANAGEMENT METHODS
    // =========================================================================

    /**
     * Get all ACTIVE sessions for a login user, most recently active first,
     * formatted for display (device/location parsing included).
     *
     * Deliberately returns the WHOLE set. This backs a "where you're signed in"
     * security screen, and a user cannot terminate a session they were not shown -
     * so truncating it would defeat the screen's only purpose.
     *
     * Two things keep the set small, neither of them a cap on this query:
     *   - rsx.sessions.max_web_sessions_per_user (default 25) signs out the oldest
     *     web sessions on every sign-in, so the live count per user has a ceiling;
     *   - retention expires idle rows by type (rsx.sessions.*_timeout_minutes,
     *     Session_Cleanup_Service).
     * Deactivated rows are excluded here - they are signed out, and offering to
     * terminate an already-terminated session is noise.
     *
     * The 62,617-row incident that prompted this review was harness sessions
     * nothing ever expired - now TYPE_PLAYWRIGHT, deleted as each request ends -
     * and was a retention bug wearing a query bug's clothes.
     *
     * @param int|null $login_user_id If null, uses current logged-in user
     * @return array Array of session info
     */
    public static function get_sessions_for_user(?int $login_user_id = null): array
    {
        if ($login_user_id === null) {
            $login_user_id = self::get_login_user_id();
        }

        if (empty($login_user_id)) {
            return [];
        }

        $current_session_id = self::has_session() ? self::$_session?->id : null;

        return static::where('login_user_id', $login_user_id)
            ->where('active', true)
            ->orderBy('last_active', 'desc')
            ->get()
            ->map(function ($session) use ($current_session_id) {
                $parsed_ua = User_Agent::parse($session->user_agent);

                return [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'user_agent_parsed' => $parsed_ua,
                    'device_summary' => $parsed_ua['summary'],
                    'location' => self::_format_session_location($session),
                    'last_active' => $session->last_active,
                    'created_at' => $session->created_at,
                    'is_current' => $session->id === $current_session_id,
                ];
            })
            ->toArray();
    }

    /**
     * Authorize the CURRENT actor to terminate sessions belonging to $target_login_user_id.
     * Returns void when permitted; THROWS AjaxUnauthorizedException (403 on the Ajax
     * channel) when not. Called first by every PUBLIC termination function that can name
     * a user other than the caller.
     *
     * THE RULE - two ways to be authorized, and only two:
     *   1. The target IS the actor. Self-management of one's own device list is always
     *      allowed and needs no role at all.
     *   2. The actor's role may ADMINISTER the target's role - Permission::can_admin_role()
     *      on the target's per-site users.role_id. That list is strictly "roles BELOW mine"
     *      (User_Model::$enums role_id.can_admin_roles), so a PEER refuses and a SUPERIOR
     *      refuses, for free and by construction. There is no "same role may terminate each
     *      other" concession: two Site Admins cannot sign each other out.
     * A target with no users row on the acting site is likewise refused - the actor's site
     * cannot see that identity, so there is nothing here to authorize against.
     *
     * REFUSAL THROWS; ABSENCE RETURNS FALSE. The two outcomes must never be conflated. A
     * caller that lacks authority gets an exception it cannot mistake for "nothing happened";
     * a caller with authority naming a session id that does not exist (or is already inactive)
     * gets a plain false. This is what surfaces a wiring bug the moment it is written rather
     * than in an audit months later - the shape the 2026-08-12 CR asked for, after
     * terminate_session()'s single overloaded false had hidden an admin-terminate feature
     * that never worked at all.
     *
     * SINGLE-TENANT ASSUMPTION - READ THIS BEFORE REUSING THE RULE.
     * A session belongs to a login_users row, which is CROSS-SITE; a role lives on a
     * users row, which is PER-SITE. This guard resolves the target's role in the ACTOR'S
     * OWN site and terminates sessions that are not scoped to any site at all. On a
     * deployment with ONE site those are the same population and the rule is exactly right.
     * On a genuinely multi-tenant deployment they are not: the target may hold a lower role
     * on the actor's site and a higher one elsewhere, and terminating the shared login
     * session reaches that other tenant too. The multi-tenant model (membership-scoped
     * termination, per-site session affinity) is BACKLOG B-92 and is deliberately NOT
     * approximated here. Do not "improve" this into a half-tenant-aware rule.
     *
     * VOCABULARY: framework only. can_admin_role() is a framework primitive on the app's
     * Permission class (Permission_Abstract::can_admin_role, resolved by simple name through
     * the autoloader - same spelling as Rsx_Formdata_Generator_Controller). An #[Auth_Check]
     * name is APPLICATION vocabulary and must never appear in core; a check name that exists
     * in one app and not another would make this function undefined behavior downstream.
     *
     * PORTAL: a _sessions row is ONE browser's session and may carry a portal identity
     * alongside the staff one. Terminating it deactivates the WHOLE row, portal identity
     * included. That is INTENDED (owner ruling 2026-08-12) and consistent with the staff
     * session-cap precedent, which evicts the same way. The portal twins
     * (Portal_Session::terminate_*) are a separate realm with membership-based authorization
     * and are out of scope here.
     *
     * @param int $target_login_user_id login_users.id whose sessions are to be terminated
     * @throws AjaxUnauthorizedException when the actor may not terminate this user's sessions
     */
    private static function __authorize_session_termination(int $target_login_user_id): void
    {
        self::init();

        $actor_login_user_id = self::get_login_user_id();

        if (empty($actor_login_user_id)) {
            throw new AjaxUnauthorizedException('Session termination requires a logged-in actor.');
        }

        // Rule 1 - self-management.
        if ((int) $actor_login_user_id === $target_login_user_id) {
            return;
        }

        // Rule 2 - the target's role, as it exists on the acting site.
        $target_user = User_Model::where('login_user_id', $target_login_user_id)
            ->where('site_id', self::get_site_id())
            ->first();

        if (empty($target_user)) {
            throw new AjaxUnauthorizedException(
                'Not authorized to terminate sessions for this user: no such user on the acting site.'
            );
        }

        if (!Permission::can_admin_role((int) $target_user->role_id)) {
            throw new AjaxUnauthorizedException('Not authorized to terminate sessions for this user.');
        }
    }

    /**
     * Announce one deactivated session: push the browser holding it to reload into
     * anonymity, then fire the audit event. Called once per row by every termination
     * path, which is why it exists as a function rather than four copies.
     *
     * @param int|null $actor_login_user_id Who performed it; null for the unchecked internal path
     * @param int $target_login_user_id Whose session it was
     * @param int $session_id The deactivated _sessions row
     * @param string $scope 'self' | 'admin' | 'internal'
     */
    private static function __announce_session_terminated(
        ?int $actor_login_user_id,
        int $target_login_user_id,
        int $session_id,
        string $scope
    ): void {
        // The row is already inactive, so the connection is authenticating against nothing -
        // a refresh lands it on the login screen instead of leaving a live-looking dead page.
        Realtime::push_session_refresh('staff', $session_id);

        Rsx::trigger_action('session.terminated', [
            'actor_login_user_id'  => $actor_login_user_id,
            'target_login_user_id' => $target_login_user_id,
            'session_id'           => $session_id,
            'scope'                => $scope,
        ]);
    }

    /**
     * Terminate one of the CURRENT login user's own sessions by ID.
     * Cannot terminate the current session (use logout() instead).
     *
     * Fail-closed: the owning-user predicate is part of the statement, so a session id
     * belonging to anyone else matches zero rows and returns false. Cross-user (admin)
     * termination is deliberately NOT supported here - it is a different question with a
     * different answer shape (refusal throws), and it lives in terminate_session_for_user().
     * This function's self-only contract is unchanged.
     *
     * @param int $session_id
     * @return bool True if the caller's own session was terminated, false otherwise
     */
    public static function terminate_session(int $session_id): bool
    {
        self::init();

        // Don't allow terminating current session
        if (self::$_session && self::$_session->id === $session_id) {
            return false;
        }

        $login_user_id = self::get_login_user_id();
        if (empty($login_user_id)) {
            return false;
        }

        $affected = static::where('id', $session_id)
            ->where('login_user_id', $login_user_id)
            ->where('active', true)
            ->update(['active' => false]);

        if ($affected > 0) {
            self::__announce_session_terminated(
                (int) $login_user_id,
                (int) $login_user_id,
                $session_id,
                'self'
            );
        }

        return $affected > 0;
    }

    /**
     * Terminate ONE session belonging to ANOTHER (or the same) login user - the admin
     * primitive. Authorization first: see __authorize_session_termination() for the rule,
     * the single-tenant assumption, and the throw-vs-false contract.
     *
     * A refusal THROWS. A false return means one thing only: no matching active row
     * (wrong id, wrong owner, or already terminated).
     *
     * The actor's OWN current session is refused with false, exactly as terminate_session()
     * refuses it - signing yourself out is logout()'s job, and doing it from an admin screen
     * would be an accident every time.
     *
     * @param int $login_user_id login_users.id that owns the session
     * @param int $session_id _sessions.id to deactivate
     * @return bool True if a row was deactivated; false if no such active row existed
     * @throws AjaxUnauthorizedException when the actor may not terminate this user's sessions
     */
    public static function terminate_session_for_user(int $login_user_id, int $session_id): bool
    {
        self::__authorize_session_termination($login_user_id);

        // Never let an admin screen sign the operator out of the browser they are using.
        if (self::$_session && (int) self::$_session->id === $session_id) {
            return false;
        }

        $affected = static::where('id', $session_id)
            ->where('login_user_id', $login_user_id)
            ->where('active', true)
            ->update(['active' => false]);

        if ($affected > 0) {
            $actor_login_user_id = (int) self::get_login_user_id();

            self::__announce_session_terminated(
                $actor_login_user_id,
                $login_user_id,
                $session_id,
                $actor_login_user_id === $login_user_id ? 'self' : 'admin'
            );
        }

        return $affected > 0;
    }

    /**
     * Terminate all sessions for the current user except the current one
     *
     * @return int Number of sessions terminated
     */
    public static function terminate_all_other_sessions(): int
    {
        self::init();

        $login_user_id = self::get_login_user_id();
        if (empty($login_user_id)) {
            return 0;
        }

        $query = static::where('login_user_id', $login_user_id)
            ->where('active', true);

        // Exclude current session if we have one
        if (self::$_session) {
            $query->where('id', '!=', self::$_session->id);
        }

        // The ids are plucked BEFORE the UPDATE because each one gets a refresh push and an
        // audit event afterward, and an UPDATE reports only a count. Bounded by the
        // concurrent-session cap (rsx.sessions.max_web_sessions_per_user, ~25).
        $session_ids = $query->pluck('id')->all();

        if (empty($session_ids)) {
            return 0;
        }

        $affected = static::whereIn('id', $session_ids)
            ->update(['active' => false]);

        foreach ($session_ids as $session_id) {
            self::__announce_session_terminated(
                (int) $login_user_id,
                (int) $login_user_id,
                (int) $session_id,
                'self'
            );
        }

        return $affected;
    }

    /**
     * Terminate ALL sessions for a specific login user (optionally sparing one) - the
     * bulk admin primitive, e.g. "sign this user out everywhere" or a password change.
     *
     * Authorization first: see __authorize_session_termination() for the rule, the
     * single-tenant assumption, and the throw-vs-false contract. A refusal THROWS; a
     * return of 0 means only that the user had no active sessions to end.
     *
     * Framework code with NO acting user (CLI maintenance, an app-internal flow that
     * carries its own authorization) calls _deactivate_sessions_for_user() instead.
     *
     * @param int $login_user_id
     * @param int|null $except_session_id Optional session ID to exclude
     * @return int Number of sessions terminated
     * @throws AjaxUnauthorizedException when the actor may not terminate this user's sessions
     */
    public static function terminate_all_sessions_for_user(int $login_user_id, ?int $except_session_id = null): int
    {
        self::init();
        self::__authorize_session_termination($login_user_id);

        $actor_login_user_id = (int) self::get_login_user_id();

        return self::_deactivate_sessions_for_user(
            $login_user_id,
            $except_session_id,
            $actor_login_user_id === $login_user_id ? 'self' : 'admin',
            $actor_login_user_id
        );
    }

    /**
     * FRAMEWORK-INTERNAL, NO AUTHORIZATION - deactivate every active session of a login
     * user (optionally sparing one).
     *
     * For callers that have NO acting user to authorize against: CLI maintenance, and
     * app-internal flows that have already applied their own authorization and are simply
     * executing the consequence. PUBLIC callers - anything driven by a signed-in operator -
     * use the guarded functions (terminate_session_for_user / terminate_all_sessions_for_user),
     * which throw on refusal.
     *
     * Fires the same refresh push and 'session.terminated' event per row as the guarded
     * paths; the default scope 'internal' with a null actor is what an audit reads as
     * "the framework did this, no operator was involved".
     *
     * @param int $login_user_id
     * @param int|null $except_session_id Optional session ID to exclude
     * @param string $scope Event scope: 'internal' (default), or the guarded wrapper's 'self'/'admin'
     * @param int|null $actor_login_user_id Event actor; null for a genuinely unattributed call
     * @return int Number of sessions terminated
     */
    public static function _deactivate_sessions_for_user(
        int $login_user_id,
        ?int $except_session_id = null,
        string $scope = 'internal',
        ?int $actor_login_user_id = null
    ): int {
        $query = static::where('login_user_id', $login_user_id)
            ->where('active', true);

        if ($except_session_id !== null) {
            $query->where('id', '!=', $except_session_id);
        }

        // Plucked before the UPDATE - see terminate_all_other_sessions() for why.
        $session_ids = $query->pluck('id')->all();

        if (empty($session_ids)) {
            return 0;
        }

        $affected = static::whereIn('id', $session_ids)
            ->update(['active' => false]);

        foreach ($session_ids as $session_id) {
            self::__announce_session_terminated(
                $actor_login_user_id,
                $login_user_id,
                (int) $session_id,
                $scope
            );
        }

        return $affected;
    }

    /**
     * Get information about the current session
     * Returns formatted info with device/location parsing
     *
     * @return array|null Session info or null if not logged in
     */
    public static function get_current_session_info(): ?array
    {
        self::init();

        if (empty(self::$_session)) {
            return null;
        }

        $parsed_ua = User_Agent::parse(self::$_session->user_agent);

        return [
            'id' => self::$_session->id,
            'ip_address' => self::$_session->ip_address,
            'user_agent' => self::$_session->user_agent,
            'user_agent_parsed' => $parsed_ua,
            'device_summary' => $parsed_ua['summary'],
            'location' => self::_format_session_location(self::$_session),
            'last_active' => self::$_session->last_active,
            'created_at' => self::$_session->created_at,
            'is_current' => true,
        ];
    }

    /**
     * Format location string from session (placeholder for future geo lookup)
     *
     * @param Session $session
     * @return string|null
     */
    private static function _format_session_location($session): ?string
    {
        // Future: Implement geo lookup based on IP
        // For now, return null (location not available)
        return null;
    }

    // =========================================================================
    // PORTAL PROPERTY SEAMS - FRAMEWORK INTERNAL
    //
    // NOT application API. These exist for exactly one caller,
    // App\RSpade\Core\Portal\Portal_Session, which is the facade over the PORTAL
    // PROPERTY SUBSET of the row this class owns. There is no portal row and no
    // portal table: the browser has ONE session, and these seams read and write
    // the portal-shaped columns on it.
    //
    // Application code wanting portal session state calls Portal_Session's public
    // accessors (get_portal_user_id(), get_sessions_for_user(), ...). Nothing
    // outside the framework should name these.
    // =========================================================================

    /**
     * The current session row if one already exists, WITHOUT creating it.
     *
     * @return static|null
     */
    private static function __row_if_any(): ?self
    {
        self::init();

        return self::$_session;
    }

    /**
     * A query builder over every row that CARRIES A PORTAL IDENTITY.
     *
     * There is no realm column to filter on any more. What makes a row "a portal
     * session" is that its portal_user_id is set - and such a row may equally carry
     * a staff login, because it is one browser's session. So every bulk portal
     * operation (the device list, the sign-in cap, termination) narrows on the
     * identity column and NEVER deletes or deactivates: it clears portal properties.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function _portal_query()
    {
        return static::query()->whereNotNull('portal_user_id');
    }

    /**
     * A new, UNSAVED row for the staff "View as Client" HANDOFF, and nothing else.
     *
     * This is the one portal row no browser holds a cookie for: pure transport for a
     * single-use token, carrying the impersonation payload (target portal user, site,
     * impersonator) until the claiming browser copies those properties onto its OWN
     * session row - at which point this row is deleted. It is excluded from the
     * device-session list (handoff_token IS NOT NULL) precisely because it is not a
     * device.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return static
     */
    public static function _portal_new_handoff_row(): self
    {
        $session = new static();
        $session->active = true;
        $session->version = 1;

        return $session;
    }

    /**
     * Ensure this browser has a session row, minting one through THE activation path
     * if it does not. A portal login on a fresh browser lands here.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return void
     */
    public static function _portal_activate(): void
    {
        self::__activate();
    }

    /**
     * The portal identity on this browser's session, or null. Creates nothing.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return int|null
     */
    public static function _get_portal_user_id(): ?int
    {
        $row = self::__row_if_any();

        return $row && $row->portal_user_id ? (int) $row->portal_user_id : null;
    }

    /**
     * The tenant the portal identity on this browser's session belongs to, or null.
     * Distinct from site_id, which is the STAFF experience's tenant: one browser can
     * legitimately be looking at a different site in each experience.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return int|null
     */
    public static function _get_portal_site_id(): ?int
    {
        $row = self::__row_if_any();

        return $row && $row->portal_site_id ? (int) $row->portal_site_id : null;
    }

    /**
     * The staff users.id impersonating through the portal on this session, or null.
     * A different id space from impersonator_login_user_id (login_users.id), which is
     * why both columns exist.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return int|null
     */
    public static function _get_portal_impersonator_user_id(): ?int
    {
        $row = self::__row_if_any();

        return $row && $row->impersonator_user_id ? (int) $row->impersonator_user_id : null;
    }

    /**
     * Write the portal identity onto this browser's session, activating it if needed
     * (a portal login is exactly the moment a session becomes necessary).
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @param int $portal_user_id
     * @param int $portal_site_id
     * @return void
     */
    public static function _set_portal_identity(int $portal_user_id, int $portal_site_id): void
    {
        self::__activate();

        self::$_session->portal_user_id = $portal_user_id;
        self::$_session->portal_site_id = $portal_site_id;
        self::$_session->save();
    }

    /**
     * Record the portal tenant on this browser's session, when a row already exists.
     * The application's per-request declaration (Portal_Session::set_site_id) is what
     * calls this; it creates nothing, because declaring a tenant is not asking for a
     * session.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @param int $portal_site_id
     * @return void
     */
    public static function _set_portal_site_id(int $portal_site_id): void
    {
        $row = self::__row_if_any();

        if (!$row || (int) $row->portal_site_id === $portal_site_id) {
            return;
        }

        $row->portal_site_id = $portal_site_id;
        $row->save();
    }

    /**
     * Stamp (or clear) the portal impersonation properties on this browser's session.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @param int|null $impersonator_user_id Staff users.id, or null to clear
     * @param string|null $started_at        Timestamp, or null to clear
     * @return void
     */
    public static function _set_portal_impersonation(?int $impersonator_user_id, ?string $started_at): void
    {
        $row = self::__row_if_any();

        if (!$row) {
            return;
        }

        $row->impersonator_user_id = $impersonator_user_id;
        $row->impersonation_started_at = $started_at;
        $row->save();
    }

    /**
     * Clear EVERY portal property on this browser's session - the portal's logout,
     * device-termination and sign-in-cap primitive.
     *
     * The row SURVIVES. It may also carry a staff login, and it is in any case the
     * browser's session; only the portal identity is being ended.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return void
     */
    public static function _clear_portal_properties(): void
    {
        $row = self::__row_if_any();

        if (!$row) {
            return;
        }

        $row->portal_user_id = null;
        $row->portal_site_id = null;
        $row->impersonator_user_id = null;
        $row->impersonation_started_at = null;
        $row->handoff_token = null;
        $row->handoff_expires_at = null;
        $row->save();
    }

    /**
     * Clear every portal property on the given rows, in one statement.
     *
     * raw_bulk() is deliberate: this is session bookkeeping on a $realtime_silent
     * infrastructure model with no lifecycle surface, so per-record hydration would
     * buy nothing.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @param array $session_ids
     * @return int Rows affected
     */
    public static function _clear_portal_properties_on(array $session_ids): int
    {
        if (empty($session_ids)) {
            return 0;
        }

        return static::whereIn('id', $session_ids)
            ->raw_bulk()
            ->update([
                'portal_user_id' => null,
                'portal_site_id' => null,
                'impersonator_user_id' => null,
                'impersonation_started_at' => null,
                'handoff_token' => null,
                'handoff_expires_at' => null,
            ]);
    }
}
