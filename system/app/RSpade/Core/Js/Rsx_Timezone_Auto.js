/**
 * Rsx_Timezone_Auto - set the signed-in user's timezone from the browser, once.
 *
 * A user who has never chosen a zone renders in the site default, which is wrong
 * for everyone who does not live there. The browser knows the truth, so on the
 * first signed-in page load of a browser session we compare the browser's zone
 * with the zone the page was rendered in, and if they disagree we tell the server
 * and reload into a correctly-rendered page.
 *
 * CONTRACT
 *
 * - IDENTIFIER comparison, NEVER offsets. 'America/Chicago' and 'America/Mexico_City'
 *   share an offset for part of the year and diverge across DST transitions; only the
 *   identifier says what the user's clock will do in six months. Comparing offsets
 *   would set the wrong zone half the year and thrash the other half.
 *
 * - STAFF ONLY. A personal timezone lives on a login_users row. A portal account has
 *   none (Rsx_Time::get_user_timezone), and Rsx_Timezone_Controller is a staff-realm
 *   surface - so a portal page must never call it, and a signed-out page has no
 *   preference to write.
 *
 * - SENTINEL FIRST, and it is a LOOP BELT. The sentinel is written to sessionStorage
 *   BEFORE the endpoint is called, so at most ONE attempt happens per browser session
 *   regardless of outcome. If it were written after a successful call, a server that
 *   kept answering `changed: true` (a zone that will not stick, a broken save, a stale
 *   cache) would reload, attempt again, reload again - an infinite boot loop the user
 *   cannot escape. Writing first converts that failure mode into one wasted request.
 *
 * - RELOAD, do not patch. A timezone change re-renders nothing live: every server-side
 *   formatted datetime already in the DOM, every SSR'd label, every cached page-data
 *   block was produced in the OLD zone. Halting boot (Rsx._rsx_core_boot_stop) before
 *   the app initializes and reloading is the only way to get a page that is wholly
 *   correct, and halting first means no app code ever observes the doomed page.
 *
 * - RAW sessionStorage, not Rsx_Storage. The sentinel is a boot-sequence fact that must
 *   exist before storage abstractions matter, and it must never be auto-scoped or
 *   cleared by application logic - Rsx_Storage scopes keys per session_hash/build_key
 *   and app code may clear its namespace, either of which would silently rearm the loop
 *   belt. Rsx.js's own scroll-restoration key uses raw sessionStorage for the same
 *   reason. Both accesses are wrapped: privacy modes throw on sessionStorage access.
 *
 * - ORDERING: this reads window.rsxapp DIRECTLY and never Rsx_Time._user_timezone.
 *   Rsx_Time populates that field in its OWN _on_framework_core_init, and manifest
 *   order within a phase is not a contract - reading it here would be a race.
 *
 * See `rsx:man time` for the timezone resolution chain and the settings surface.
 */
class Rsx_Timezone_Auto {

    /**
     * sessionStorage key: "this browser session already made its one attempt".
     * @type {string}
     */
    static _SENTINEL_KEY = 'rsx_timezone_auto_attempted';

    /**
     * PURE decision: may we auto-set the user's timezone right now?
     *
     * No side effects, no globals - every input is passed in, so the whole matrix is
     * exercisable from a test or from `rsx:debug --eval`.
     *
     * @param {Object} inputs
     * @param {boolean} inputs.is_auth - a user is signed in
     * @param {boolean} inputs.is_portal - this is a portal request
     * @param {boolean} inputs.timezone_auto - the user's auto-set preference
     * @param {string|null} inputs.browser_timezone - IANA identifier the browser reports
     * @param {string|null} inputs.user_timezone - the zone this page was rendered in
     * @param {boolean} inputs.sentinel_present - an attempt was already made this session
     * @returns {boolean}
     */
    static should_auto_set(inputs) {
        const values = inputs || {};

        if (!values.is_auth) return false;
        if (values.is_portal) return false;
        if (!values.timezone_auto) return false;
        if (values.sentinel_present) return false;

        const browser_timezone = values.browser_timezone;
        if (!is_string(browser_timezone) || browser_timezone === '') return false;

        // Identifier comparison - never offsets.
        if (browser_timezone === values.user_timezone) return false;

        return true;
    }

    /**
     * Framework initialization hook.
     *
     * Gathers the real inputs, asks should_auto_set(), and - when it says yes - marks
     * the sentinel, sets the zone, and (only if the server reports the RESOLVED zone
     * actually moved) halts boot and reloads.
     */
    static async _on_framework_core_init() {
        if (Rsx.is_ssr()) return;

        const rsxapp = window.rsxapp || {};
        const browser_timezone = Rsx_Timezone_Auto._detect_browser_timezone();

        const inputs = {
            is_auth: !!rsxapp.is_auth,
            is_portal: !!rsxapp.is_portal,
            timezone_auto: !!rsxapp.user_timezone_auto,
            browser_timezone: browser_timezone,
            user_timezone: rsxapp.user_timezone || null,
            sentinel_present: Rsx_Timezone_Auto._sentinel_present(),
        };

        if (!Rsx_Timezone_Auto.should_auto_set(inputs)) return;

        // Loop belt: one attempt per browser session, whatever happens next.
        Rsx_Timezone_Auto._set_sentinel();

        let response = null;

        try {
            response = await Rsx_Timezone_Controller.set_timezone({ timezone: browser_timezone });
        } catch (error) {
            // A failed auto-set is not a reason to break the page the user asked for:
            // they simply keep the zone they had. The sentinel is already set, so this
            // is not retried until the next browser session.
            console_debug('TIMEZONE', 'Auto-set failed', browser_timezone, error);
            return;
        }

        // The server compares RESOLVED zones - the browser's identifier can differ from
        // the stored preference while resolving to the same zone, and that is a no-op.
        if (!response || response.changed !== true) return;

        Rsx._rsx_core_boot_stop(`user timezone auto-set to ${browser_timezone}; reloading`);
        document.location.reload();
    }

    /**
     * The browser's IANA timezone identifier, or null when it cannot be determined.
     *
     * @returns {string|null}
     */
    static _detect_browser_timezone() {
        try {
            const resolved = Intl.DateTimeFormat().resolvedOptions().timeZone;
            return is_string(resolved) && resolved !== '' ? resolved : null;
        } catch (error) {
            return null;
        }
    }

    /**
     * @returns {boolean} whether this browser session already made its one attempt
     */
    static _sentinel_present() {
        try {
            return sessionStorage.getItem(Rsx_Timezone_Auto._SENTINEL_KEY) !== null;
        } catch (error) {
            // Storage unavailable (privacy mode). Report "already attempted" so the loop
            // belt fails CLOSED - without storage there is nothing to stop a reload loop.
            return true;
        }
    }

    /**
     * Mark this browser session as having made its attempt.
     */
    static _set_sentinel() {
        try {
            sessionStorage.setItem(Rsx_Timezone_Auto._SENTINEL_KEY, '1');
        } catch (error) {
            console_debug('TIMEZONE', 'Could not write the auto-set sentinel', error);
        }
    }
}
