<?php

namespace App\RSpade\Core\Portal;

use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Session\User_Agent;

/**
 * Portal_Session - authentication for external portal users.
 *
 * A STATIC FACADE over the PORTAL PROPERTIES of the ONE session RSpade gives a
 * browser. There is no portal session row, no portal cookie and no portal table: a
 * session identifies a BROWSER (one `rsx` cookie, one `_sessions` row, per cookie
 * jar), the row is a property bag, and this class reads and writes the portal-shaped
 * properties on it through the framework-internal seams on Session:
 *
 *     portal_user_id, portal_site_id, impersonator_user_id,
 *     impersonation_started_at, handoff_token, handoff_expires_at
 *
 * The staff facade (Session) owns the row itself and the staff properties
 * (login_user_id, site_id, impersonator_login_user_id). BOTH IDENTITIES SET AT ONCE
 * IS NORMAL - the same human, in the same browser, signed into the staff app and the
 * client portal. Which set a given request consults is decided by the REQUEST's
 * experience (Rsx_Portal::is_portal_request()), never by the row.
 *
 * A dedicated portal domain needs no machinery: per-origin cookie jars mean that
 * browser holds its own row on that origin, with the staff properties simply null.
 *
 * Consequences worth stating outright:
 * - Portal logout, terminating a portal device session and the portal sign-in cap all
 *   CLEAR THE PORTAL PROPERTIES. They never delete or deactivate the row - it is the
 *   browser's session and may carry a staff login too.
 * - The session id, the session token and the CSRF token are SHARED with the staff
 *   facade. get_session_id() / get_csrf_token() here are the same values Session
 *   reports; they are kept as facade methods so portal code never has to know that.
 * - Site-scoped, with no multi-site switching. The APPLICATION declares the portal's
 *   tenant with set_site_id(); the framework never guesses one. get_site_id() throws
 *   when nothing has declared it - see set_site_id() and rsx:man portal.
 * - CLI mints nothing of its own: Rsx_Portal::is_portal_request() is false in CLI, so
 *   nothing can be in portal context. set_site_id() still works there (it is the same
 *   declaration, minus a request), which is how tests and portal-aware commands seed
 *   a site.
 */
class Portal_Session
{
    private static $_site = null;

    private static $_portal_user = null;

    /**
     * The site the APPLICATION declared for this request via set_site_id().
     *
     * Request-scoped in web mode (the declaration belongs to the request, not to
     * the session row); process-scoped in CLI, where it is also the whole CLI site
     * contract. null = nothing has declared a site yet, which makes get_site_id()
     * throw rather than invent one.
     *
     * @var int|null
     */
    private static $_declared_site_id = null;

    // CLI mode properties (static-only, no database)
    private static $_cli_portal_user_id = null;

    private static $_cli_impersonator_user_id = null;

    /**
     * Check if running in CLI mode
     * @return bool
     */
    private static function __is_cli(): bool
    {
        return php_sapi_name() === 'cli';
    }

    /**
     * Resolve the browser's session from its cookie, without creating one.
     *
     * One cookie, one row, one init: this delegates to the staff facade, which owns
     * the row. Kept as a portal-facing method because portal middleware calls it.
     *
     * @return void
     */
    public static function init(): void
    {
        Session::init();
    }

    /**
     * End the portal identity on this browser's session.
     *
     * CLEARS THE PORTAL PROPERTIES; the row and the cookie survive, because they are
     * the browser's session and may carry a staff login. The site DECLARATION also
     * survives in web mode: it belongs to the request (the application declared it at
     * the top of this one), not to the session row, and a request that ends a portal
     * identity still has work to do afterwards - a flash alert, a redirect - that
     * needs a site. In CLI the declaration IS the whole state, so a reset clears it;
     * that is what returns the facade to virgin state between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        if (self::__is_cli()) {
            self::$_declared_site_id = null;
            self::$_cli_portal_user_id = null;
            self::$_cli_impersonator_user_id = null;
            self::$_site = null;
            self::$_portal_user = null;

            return;
        }

        Session::_clear_portal_properties();

        self::$_site = null;
        self::$_portal_user = null;
    }

    /**
     * MISUSE GUARD - Portal_Session is NOT a session-variable bag; there is no get($key).
     *
     * Same trap as Session::get(): a bare Portal_Session::get('site_id') READS like
     * Laravel's session()->get() and is always a mistake here.
     *
     * @throws Rsx_Caller_Exception always
     */
    public static function get(...$args): never
    {
        throw new Rsx_Caller_Exception(
            'Portal_Session::get() does not exist - Portal_Session is the portal experience\'s session facade, not a '
            . 'session-variable bag. Use the named accessor instead: get_site_id(), get_portal_user_id(), '
            . 'get_user(), get_portal_user(), get_session_id(), get_csrf_token().'
        );
    }

    /**
     * Declare which site this portal request belongs to.
     *
     * THE FRAMEWORK NEVER RESOLVES THE PORTAL'S SITE. There is no detection, no
     * config default, and no hook: which tenant a portal request serves is an
     * application fact (one site, a host lookup, an invite link, a subdomain
     * table), and every scheme the framework could guess would be wrong for some
     * app. So the application declares it, and does so EARLY - before anything
     * asks. The natural place is Portal_Main::init() (called once per portal
     * request, before dev-auth, CSRF, the #[Auth] gates and every controller); a
     * login flow that resolves the site itself may declare it there instead.
     *
     *   // mono-site app, in Portal_Main::init()
     *   Portal_Session::set_site_id((int) config('rsx.portal.site_id'));
     *
     * Semantics:
     * - Request-scoped, and RECORDED on the session row's portal_site_id when the
     *   browser already has a row (so the row says which tenant its portal identity
     *   belongs to). It creates no session.
     * - Idempotent for the same id; a DIFFERENT id throws. A portal never offers a
     *   site switcher, so two answers in one request means two subsystems disagree
     *   about the tenant - a bug, not a switch. (This is the deliberate difference
     *   from Session::set_site_id(), where staff tenant switching is a feature.)
     * - A session that already carries a portal tenant WINS and cannot be
     *   contradicted: declaring a different one throws.
     * - CLI: the same declaration without a request. It is the whole CLI site
     *   contract (test seeding, portal-aware commands) and, having no request
     *   boundary to protect, may be re-declared; reset() clears it.
     *
     * @param int $site_id A real site id (positive)
     * @return void
     * @throws Rsx_Caller_Exception on a non-positive id
     * @throws \RuntimeException on a conflicting declaration
     */
    public static function set_site_id(int $site_id): void
    {
        if ($site_id <= 0) {
            throw new Rsx_Caller_Exception(
                'Portal_Session::set_site_id() requires a real site id; got ' . $site_id . '. '
                . 'If the site cannot be resolved yet, do not call this - let get_site_id() fail loud instead. '
                . 'See: php artisan rsx:man portal'
            );
        }

        // CLI: no request, no session, no cookie - just the declaration.
        if (self::__is_cli()) {
            self::$_declared_site_id = $site_id;
            self::$_site = null;

            return;
        }

        $row_site_id = Session::_get_portal_site_id();

        // A session that already carries a portal tenant answers the question, and the
        // portal has no site switcher: contradicting it is a bug in the caller.
        if ($row_site_id !== null && $row_site_id !== $site_id) {
            throw new \RuntimeException(
                'Portal_Session::set_site_id(' . $site_id . ') conflicts with the live portal session, which '
                . 'belongs to site ' . $row_site_id . '. A portal session never changes site; '
                . 'end it (logout) before serving a different tenant. See: php artisan rsx:man portal'
            );
        }

        if (self::$_declared_site_id !== null && self::$_declared_site_id !== $site_id) {
            throw new \RuntimeException(
                'Portal site already declared as ' . self::$_declared_site_id . ' for this request; '
                . 'set_site_id(' . $site_id . ') contradicts it. Declare the portal site exactly once per '
                . 'request (Portal_Main::init() is the usual place). See: php artisan rsx:man portal'
            );
        }

        self::$_declared_site_id = $site_id;
        self::$_site = null;

        // Record it on the row when the browser already has one. Creates nothing:
        // declaring a tenant is not asking for a session.
        Session::_set_portal_site_id($site_id);
    }

    /**
     * Get site ID for the current portal request.
     *
     * Resolution order: the session's own portal tenant (stamped when the portal
     * identity was established), then the application's set_site_id() declaration.
     * Nothing else - there is no detection and no default. When neither exists this
     * THROWS, which is the contract: a portal that does not know its tenant must not
     * quietly pick one.
     *
     * @return int
     * @throws \RuntimeException when no site has been declared
     */
    public static function get_site_id(): int
    {
        // CLI mode: the declaration is the whole story (no cookie, no row).
        if (self::__is_cli()) {
            if (self::$_declared_site_id === null) {
                self::__throw_site_unresolved();
            }

            return self::$_declared_site_id;
        }

        $row_site_id = Session::_get_portal_site_id();

        if ($row_site_id !== null) {
            return $row_site_id;
        }

        if (self::$_declared_site_id !== null) {
            return self::$_declared_site_id;
        }

        self::__throw_site_unresolved();
    }

    /**
     * The one refusal, in one place, so every path words it identically.
     *
     * @return never
     * @throws \RuntimeException always
     */
    private static function __throw_site_unresolved(): never
    {
        throw new \RuntimeException(
            'The portal site has not been declared. RSpade does not resolve it for you: the application must '
            . 'call Portal_Session::set_site_id(<site id>) before any portal session work - normally in '
            . 'Portal_Main::init() (mono-site: read your own config key; multi-tenant: look the site up from '
            . 'the request host), or in a login flow that resolves the site itself. '
            . 'See: php artisan rsx:man portal (PORTAL SESSIONS)'
        );
    }

    /**
     * Get site model for current session
     * @return Site_Model|null
     */
    public static function get_site()
    {
        $site_id = self::get_site_id();

        if (empty(self::$_site)) {
            self::$_site = Site_Model::find($site_id);
        }

        return self::$_site;
    }

    /**
     * Get portal user ID for current session
     * In CLI mode: returns static CLI property
     * @return int|null
     */
    public static function get_portal_user_id()
    {
        // CLI mode: return static property
        if (self::__is_cli()) {
            return self::$_cli_portal_user_id;
        }

        return Session::_get_portal_user_id();
    }

    /**
     * Check if portal user is logged in
     * @return bool
     */
    public static function is_logged_in(): bool
    {
        return !empty(self::get_portal_user_id());
    }

    /**
     * Get portal user model for current session
     * @return Portal_User_Model|null
     */
    public static function get_user()
    {
        $portal_user_id = self::get_portal_user_id();

        if (empty($portal_user_id)) {
            return null;
        }

        if (empty(self::$_portal_user)) {
            self::$_portal_user = Portal_User_Model::find($portal_user_id);
        }

        return self::$_portal_user;
    }

    /**
     * Alias for get_user() to match naming convention
     * @return Portal_User_Model|null
     */
    public static function get_portal_user()
    {
        return self::get_user();
    }

    /**
     * Get this browser's session ROW (creates if needed).
     *
     * The SAME row the staff facade returns - there is one session per browser. The
     * portal properties on it are this facade's business; the row is Session's.
     *
     * @return Session
     */
    public static function get_session(): Session
    {
        $session = Session::get_session();

        self::__persist_declared_site();

        return $session;
    }

    /**
     * Get current session ID (creates session if needed).
     *
     * The SAME id the staff facade reports: one browser, one session. Portal code
     * calls it through this facade so it never has to know that.
     *
     * @return int
     */
    public static function get_session_id(): int
    {
        $session_id = Session::get_session_id();

        self::__persist_declared_site();

        return $session_id;
    }

    /**
     * Record the application's site declaration on the row once one exists.
     *
     * Called after any activation this facade triggers, so a row minted during a
     * portal request carries the tenant that request serves. A no-op when nothing
     * was declared, when no row exists, or when the value already matches.
     *
     * @return void
     */
    private static function __persist_declared_site(): void
    {
        if (self::__is_cli() || self::$_declared_site_id === null) {
            return;
        }

        Session::_set_portal_site_id(self::$_declared_site_id);
    }

    /**
     * Get CSRF token for current session.
     *
     * ONE token per browser session, shared with the staff experience - the row is
     * shared, so the token on it is too. Proxied here so portal code keeps talking to
     * its own facade.
     *
     * @return string|null
     */
    public static function get_csrf_token(): ?string
    {
        return Session::get_csrf_token();
    }

    /**
     * Verify CSRF token against this browser's session (the one shared token).
     *
     * @param string $token
     * @return bool
     */
    public static function verify_csrf_token(string $token): bool
    {
        return Session::verify_csrf_token($token);
    }

    /**
     * Log the portal user out: clears the portal properties, leaving the browser's
     * session (and any staff login on it) intact.
     *
     * @return void
     */
    public static function logout(): void
    {
        self::set_portal_user_id(null);
    }

    /**
     * Set portal user ID for current session (login/logout)
     * In CLI mode: sets static CLI property only, no database
     *
     * Logging in activates the browser's session (minting the row through THE
     * activation path if this is a fresh browser) and stamps the portal identity onto
     * it, together with the tenant the application declared with set_site_id().
     * Declare the site before calling this (Portal_Main::init or the login flow
     * itself); an undeclared site throws.
     *
     * Logging out CLEARS every portal property. The row survives - it is the
     * browser's session, and may also carry a staff login.
     *
     * @param int|null $portal_user_id Portal user ID, or null to logout
     * @return void
     */
    public static function set_portal_user_id(?int $portal_user_id): void
    {
        // Logout if null/0
        if (empty($portal_user_id)) {
            // CLI mode: clear static property only
            if (self::__is_cli()) {
                self::$_cli_portal_user_id = null;
                self::$_cli_impersonator_user_id = null;
                self::$_portal_user = null;
                self::$_site = null;

                return;
            }

            // has_session() creates nothing: an anonymous visitor logging out has
            // nothing to clear.
            if (Session::has_session()) {
                // Confirmed-different gating: only a real logged-in -> logged-out transition
                // pushes a refresh (an already-anonymous session emits nothing).
                $old_portal_user_id = Session::_get_portal_user_id();
                $session_id = Session::get_session_id();

                Session::_clear_portal_properties();

                if (!empty($old_portal_user_id)) {
                    Realtime::push_session_refresh('portal', $session_id);
                }
            }

            self::$_portal_user = null;
            self::$_site = null;

            return;
        }

        // CLI mode: set static property only
        if (self::__is_cli()) {
            self::$_cli_portal_user_id = $portal_user_id;
            self::$_portal_user = null;
            self::$_site = null;

            return;
        }

        // Throws when the application never declared a site (see set_site_id). Read
        // BEFORE activation so a misconfigured portal fails before it mints anything.
        $site_id = self::get_site_id();

        // Confirmed-different gating for the refresh push: capture prior identity first.
        $old_portal_user_id = Session::_get_portal_user_id();

        // A login / identity change is a PURE RECORD UPDATE. The session_token and
        // csrf_token were minted exactly once, when the browser's session was created,
        // and are IMMUTABLE for its life - they are NEVER regenerated here, and the
        // cookie is NOT re-emitted (owner ruling 2026-07-24; see rsx:man session
        // SECURITY).
        Session::_set_portal_identity($portal_user_id, $site_id);

        // Clear cached portal_user/site
        self::$_portal_user = null;
        self::$_site = null;

        // Identity changed to a confirmed-different value -> push a refresh to every live
        // PORTAL-stamped connection on this session (re-setting the same value emits
        // nothing). Staff tabs on the same session are untouched: the realm stamp on a
        // connection records which EXPERIENCE its page was minted on.
        if ((int) $old_portal_user_id !== (int) $portal_user_id) {
            Realtime::push_session_refresh('portal', Session::get_session_id());
        }

        // Sign-in is the moment this user's session count grows, so it is where the
        // concurrent-session cap is applied.
        self::__enforce_web_session_cap((int) $portal_user_id);

        // Update portal user's last login timestamp
        $portal_user = self::get_user();
        if ($portal_user) {
            $portal_user->last_login = now();
            $portal_user->save();
        }
    }

    /**
     * Sign this portal user out of their oldest sessions beyond the configured cap.
     *
     * The portal twin of Session::__enforce_web_session_cap(), same trigger and same
     * rsx.sessions.max_web_sessions_per_user setting, with one difference: eviction
     * CLEARS THE PORTAL PROPERTIES instead of retiring the row. An evicted row is
     * some other browser's session and may carry a staff login - ending the portal
     * identity is exactly as much as this is entitled to do.
     *
     * PLAYWRIGHT sessions are excluded from the count and the eviction.
     * Disabled entirely when the setting is 0 or null.
     *
     * @param int $portal_user_id
     * @return void
     */
    private static function __enforce_web_session_cap(int $portal_user_id): void
    {
        $max = config('rsx.sessions.max_web_sessions_per_user');

        if (empty($max)) {
            return;
        }

        // The ids to KEEP - "the N most recent" is exactly the cap, and N is small
        // and config-bounded.
        $keep = self::__device_session_query($portal_user_id)
            ->where('type_id', Session::TYPE_WEB)
            ->orderBy('last_active', 'desc')
            ->orderBy('id', 'desc')
            ->limit((int) $max)
            ->pluck('id')
            ->all();

        $evict = self::__device_session_query($portal_user_id)
            ->where('type_id', Session::TYPE_WEB)
            ->whereNotIn('id', $keep)
            ->pluck('id')
            ->all();

        Session::_clear_portal_properties_on($evict);
    }

    /**
     * The rows that count as this portal user's DEVICE sessions: live browser sessions
     * carrying their portal identity.
     *
     * Excludes the "View as Client" handoff rows (handoff_token IS NOT NULL) - those
     * are single-use transport for a token, not a device anyone is signed in on - and
     * deactivated rows, which cannot be resumed.
     *
     * @param int $portal_user_id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function __device_session_query(int $portal_user_id)
    {
        return Session::_portal_query()
            ->where('portal_user_id', $portal_user_id)
            ->where('active', true)
            ->whereNull('handoff_token');
    }

    /**
     * Check if a session exists.
     *
     * In web mode: whether this browser has a session at all (the shared row) -
     * creates nothing. In CLI: whether a site or portal user has been declared.
     *
     * @return bool
     */
    public static function has_session(): bool
    {
        // CLI mode: check if a site or portal user has been declared
        if (self::__is_cli()) {
            return self::$_declared_site_id !== null || self::$_cli_portal_user_id !== null;
        }

        return Session::has_session();
    }

    /**
     * Find a session by token, narrowed to sessions that actually carry a PORTAL
     * identity. A browser session with no portal login resolves to null here - it is
     * not a portal session, whatever else it may be.
     *
     * @param string $token
     * @return Session|null
     */
    public static function find_by_token(string $token)
    {
        return Session::_portal_query()
            ->where('session_token', $token)
            ->where('active', true)
            ->first();
    }

    // =========================================================================
    // CLI MODE SETTERS
    // =========================================================================

    // NOTE: there is no cli_set_site_id(). Declaring the portal's site is the same
    // act in every mode, so it has ONE spelling: set_site_id(), whose CLI branch is
    // this section's site setter. reset() clears it.

    /**
     * Set portal user ID in CLI mode
     * @param int $portal_user_id
     * @return void
     */
    public static function cli_set_portal_user_id(int $portal_user_id): void
    {
        self::$_cli_portal_user_id = $portal_user_id;
        self::$_portal_user = null;
    }

    // =========================================================================
    // SESSION MANAGEMENT METHODS
    // =========================================================================

    /**
     * Get all sessions carrying this portal user's identity, most recently active
     * first, formatted for display (device parsing included).
     *
     * Returns the WHOLE set, for the same reason as the staff twin: a user cannot
     * terminate a session they were not shown.
     *
     * What keeps the set small: rsx.sessions.max_web_sessions_per_user (default 25)
     * on every sign-in, plus retention on idle rows.
     * See Session::get_sessions_for_user().
     *
     * @param int|null $portal_user_id If null, uses current logged-in user
     * @return array Array of session info
     */
    public static function get_sessions_for_user(?int $portal_user_id = null): array
    {
        if ($portal_user_id === null) {
            $portal_user_id = self::get_portal_user_id();
        }

        if (empty($portal_user_id)) {
            return [];
        }

        $current_session_id = self::has_session() && !self::__is_cli() ? Session::get_session_id() : null;

        return self::__device_session_query((int) $portal_user_id)
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
                    'last_active' => $session->last_active,
                    'created_at' => $session->created_at,
                    'is_current' => $session->id === $current_session_id,
                ];
            })
            ->toArray();
    }

    /**
     * Sign one of the CURRENT portal user's own devices out of the portal, by session ID.
     * Cannot terminate the current session (use logout() instead).
     *
     * CLEARS THE PORTAL PROPERTIES on that row; the row itself survives, because it is
     * that browser's session and may carry a staff login. Ending someone's portal
     * access is not license to end their staff session.
     *
     * Fail-closed: the owning-user predicate is part of the statement, so a session id
     * belonging to anyone else matches zero rows and returns false. Cross-user (admin)
     * termination is deliberately NOT supported here - see terminate_all_sessions_for_user().
     *
     * @param int $session_id
     * @return bool True if the caller's own portal session was ended, false otherwise
     */
    public static function terminate_session(int $session_id): bool
    {
        // Don't allow terminating current session
        if (!self::__is_cli() && Session::has_session() && Session::get_session_id() === $session_id) {
            return false;
        }

        $portal_user_id = self::get_portal_user_id();
        if (empty($portal_user_id)) {
            return false;
        }

        $ids = self::__device_session_query((int) $portal_user_id)
            ->where('id', $session_id)
            ->pluck('id')
            ->all();

        return Session::_clear_portal_properties_on($ids) > 0;
    }

    /**
     * End the portal identity on every session of the current user except this one.
     *
     * @return int Number of sessions ended
     */
    public static function terminate_all_other_sessions(): int
    {
        $portal_user_id = self::get_portal_user_id();
        if (empty($portal_user_id)) {
            return 0;
        }

        $query = self::__device_session_query((int) $portal_user_id);

        // Exclude current session if we have one
        if (!self::__is_cli() && Session::has_session()) {
            $query->where('id', '!=', Session::get_session_id());
        }

        return Session::_clear_portal_properties_on($query->pluck('id')->all());
    }

    /**
     * End the portal identity on every session of a specific portal user.
     * Useful for admin actions or password changes.
     *
     * @param int $portal_user_id
     * @param int|null $except_session_id Optional session ID to exclude
     * @return int Number of sessions ended
     */
    public static function terminate_all_sessions_for_user(int $portal_user_id, ?int $except_session_id = null): int
    {
        $query = self::__device_session_query($portal_user_id);

        if ($except_session_id !== null) {
            $query->where('id', '!=', $except_session_id);
        }

        return Session::_clear_portal_properties_on($query->pluck('id')->all());
    }

    // =========================================================================
    // IMPERSONATION (staff "View as Client")
    //
    // A staff member creates a single-use HANDOFF carrying a target portal user, the
    // site, and the staff user's id. The handoff is opened in a new browser tab; the
    // portal claim route consumes the token and stamps those PROPERTIES onto THE
    // CLAIMING BROWSER'S OWN SESSION - which is the whole cross-domain point (the
    // claiming browser may be on the portal domain, with its own cookie jar and its
    // own session row).
    //
    // READ-ONLY BEHAVIOR IS THE APPLICATION'S RESPONSIBILITY: the framework only
    // exposes is_impersonating(); the app must gate writes and render the read-only
    // experience. See: php artisan rsx:man portal.
    //
    // impersonator_user_id is a STAFF users.id, a different id space from the staff
    // experience's own impersonator_login_user_id (login_users.id) - which is exactly
    // why the row keeps both columns instead of collapsing them.
    // =========================================================================

    // Lifetime of the single-use handoff token, in seconds.
    const IMPERSONATION_HANDOFF_TTL_SECONDS = 60;

    /**
     * Create an impersonation handoff for a target portal user and return its
     * single-use token (claimed by the portal claim route via claim_impersonation()).
     * Sets no cookie, touches no browser session, and does NOT touch the target
     * user's last_login - the contact's real login history stays clean.
     *
     * @param int $portal_user_id       Target portal user to impersonate
     * @param int $impersonator_user_id Staff User_Model id initiating it
     * @param int $site_id              Site scope for the impersonated portal identity
     * @return string The handoff token
     */
    public static function create_impersonation_session(int $portal_user_id, int $impersonator_user_id, int $site_id): string
    {
        if (empty($portal_user_id) || empty($impersonator_user_id) || empty($site_id)) {
            shouldnt_happen('create_impersonation_session requires portal_user_id, impersonator_user_id, and site_id');
        }

        // Collect handoffs nobody ever claimed. They live 60 seconds and are pure
        // transport, so an expired one is litter, not history.
        Session::query()
            ->whereNotNull('handoff_token')
            ->where('handoff_expires_at', '<', now())
            ->raw_bulk()
            ->delete();

        $handoff_token = bin2hex(random_bytes(32));

        $session = Session::_portal_new_handoff_row();
        $session->portal_user_id = $portal_user_id;
        $session->portal_site_id = $site_id;
        $session->impersonator_user_id = $impersonator_user_id;
        $session->impersonation_started_at = now();
        // Never transmitted: no browser ever holds this row's cookie. It exists so the
        // column's NOT NULL / UNIQUE contract is satisfied.
        $session->session_token = bin2hex(random_bytes(32));
        $session->csrf_token = bin2hex(random_bytes(32));
        $session->ip_address = Session::get_client_ip() ?? 'CLI';
        $session->user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'impersonation', 0, 255);
        $session->last_active = now();
        $session->type_id = self::__classify_session_type();
        $session->handoff_token = $handoff_token;
        $session->handoff_expires_at = now()->addSeconds(self::IMPERSONATION_HANDOFF_TTL_SECONDS);
        $session->save();

        return $handoff_token;
    }

    /**
     * Classify a handoff row at the moment of creation.
     *
     * Shares the staff type vocabulary (Session::TYPE_*), which is what lets one
     * retention sweep cover the whole table.
     *
     * @return int Session::TYPE_*
     */
    private static function __classify_session_type(): int
    {
        if (!empty($_SERVER['HTTP_X_PLAYWRIGHT_TEST'])) {
            return Session::TYPE_PLAYWRIGHT;
        }

        return Session::TYPE_WEB;
    }

    /**
     * Resolve a live (non-expired) impersonation handoff by its token, return its
     * payload, and BURN it by deleting the row (single use). Pure DB; no cookie side
     * effects. Returns null when the token is missing, already used, or expired.
     * Split out so the claim logic is unit-testable without a browser/cookie.
     *
     * @param string $handoff_token
     * @return array|null ['portal_user_id', 'portal_site_id', 'impersonator_user_id', 'impersonation_started_at']
     */
    private static function _resolve_and_burn_handoff(string $handoff_token): ?array
    {
        if ($handoff_token === '') {
            return null;
        }

        $session = Session::query()
            ->where('handoff_token', $handoff_token)
            ->where('handoff_expires_at', '>=', now())
            ->first();

        if (!$session) {
            return null;
        }

        $payload = [
            'portal_user_id' => (int) $session->portal_user_id,
            'portal_site_id' => (int) $session->portal_site_id,
            'impersonator_user_id' => (int) $session->impersonator_user_id,
            'impersonation_started_at' => $session->impersonation_started_at,
        ];

        // Single use - the transport row is consumed.
        $session->delete();

        return $payload;
    }

    /**
     * Claim an impersonation handoff: validate + burn the token, then stamp the
     * impersonated portal identity onto THIS BROWSER'S OWN session (minting it if the
     * browser has none). Any portal identity previously in this browser is replaced;
     * the staff login on the same session, if any, is untouched. Returns false when
     * the token is invalid/expired. Called by the portal claim route.
     *
     * @param string $handoff_token
     * @return bool
     */
    public static function claim_impersonation(string $handoff_token): bool
    {
        $payload = self::_resolve_and_burn_handoff($handoff_token);

        if (!$payload) {
            return false;
        }

        if (self::__is_cli()) {
            // No request, no cookie: the CLI overrides ARE the portal state. Assigned
            // directly rather than through set_site_id(), which guards a request
            // boundary this path does not have.
            self::$_declared_site_id = $payload['portal_site_id'];
            self::$_cli_portal_user_id = $payload['portal_user_id'];
            self::$_cli_impersonator_user_id = $payload['impersonator_user_id'];
        } else {
            Session::_portal_activate();
            Session::_set_portal_identity($payload['portal_user_id'], $payload['portal_site_id']);
            Session::_set_portal_impersonation(
                $payload['impersonator_user_id'],
                $payload['impersonation_started_at']
            );
        }

        self::$_portal_user = null;
        self::$_site = null;

        return true;
    }

    /**
     * Whether the current portal identity is a staff impersonation. The APPLICATION
     * is responsible for enforcing read-only behavior based on this flag (gate writes,
     * render a read-only UI) - see rsx:man portal.
     *
     * @return bool
     */
    public static function is_impersonating(): bool
    {
        if (self::__is_cli()) {
            return !empty(self::$_cli_impersonator_user_id);
        }

        return !empty(Session::_get_portal_impersonator_user_id());
    }

    /**
     * The staff User_Model id impersonating in the current session, or null.
     *
     * @return int|null
     */
    public static function get_impersonator_user_id(): ?int
    {
        if (self::__is_cli()) {
            return self::$_cli_impersonator_user_id;
        }

        return Session::_get_portal_impersonator_user_id();
    }

    /**
     * End the current impersonation: clear the portal properties on this browser's
     * session. The staff member's own staff login on that same session is untouched -
     * it lives in different columns. No-op when not impersonating.
     *
     * @return void
     */
    public static function stop_impersonation(): void
    {
        if (self::__is_cli()) {
            self::$_cli_impersonator_user_id = null;
            self::$_cli_portal_user_id = null;
            self::$_portal_user = null;
            self::$_site = null;

            return;
        }

        if (empty(Session::_get_portal_impersonator_user_id())) {
            return;
        }

        Session::_clear_portal_properties();

        self::$_portal_user = null;
        self::$_site = null;
    }

    /**
     * Set impersonator user id in CLI mode (test seeding; mirrors
     * cli_set_portal_user_id).
     *
     * @param int|null $impersonator_user_id
     * @return void
     */
    public static function cli_set_impersonator_user_id(?int $impersonator_user_id): void
    {
        self::$_cli_impersonator_user_id = $impersonator_user_id;
    }
}
