<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Env;

use RuntimeException;
use App\RSpade\Core\Rsx;

/**
 * APP_URL boot-time resolver: the single source of the application hostname.
 *
 * RSpade collapses every hostname-bearing .env key onto APP_URL. Two boot-time
 * transforms make that one key carry its weight:
 *
 *   1. `$HOSTNAME` token substitution. `APP_URL=https://$HOSTNAME` in .env resolves
 *      at runtime to the OS hostname (PHP gethostname() - a libc/uname syscall, no
 *      DNS, no I/O). Every dev/docker box then ships the SAME .env line and each
 *      instance answers under its own name. patch_environment() rewrites $_ENV /
 *      $_SERVER / putenv BEFORE Laravel's LoadConfiguration reads env('APP_URL'),
 *      so every consumer - config('app.url'), url(), filesystem disk 'url', OAuth
 *      redirects - sees the resolved value. The literal tokens `$HOSTNAME` and
 *      `${HOSTNAME}` are both accepted; the .env spelling MUST be the unquoted
 *      no-brace form (`https://$HOSTNAME`) because phpdotenv interpolates the
 *      braces form itself when the OS exports a HOSTNAME env var (and empties it
 *      under php-fpm clear_env) - only the no-brace form reliably reaches here as
 *      a literal.
 *
 *   2. Scheme enforcement. Outside development RSpade assumes upstream SSL
 *      termination (Caddy or a similar reverse proxy) and that every generated URL
 *      is https, so a non-https (or missing) APP_URL is a fatal misconfiguration
 *      thrown loud at boot. DEVELOPMENT mode also accepts http, because a local
 *      container has no terminator in front of it and requiring one would make
 *      "clone it and run it" impossible - see enforce_scheme() for why the line is
 *      drawn at development and not at debug.
 *
 * The two transforms run at DIFFERENT boot phases on purpose. Substitution must
 * land in afterLoadingEnvironment (before LoadConfiguration reads env('APP_URL')) -
 * but that phase is BEFORE the HandleExceptions bootstrapper, so a throw there is
 * unreadable (no exception handler, config not yet loaded to even log it). So
 * enforcement is deferred to Rsx_Framework_Provider::boot() (via
 * enforce_scheme_from_env()), which runs after HandleExceptions and fails loud with
 * a readable message.
 *
 * CONSTRAINT: config caching would freeze the resolved value at cache-build time.
 * RSpade drops config caching in optimize:cache by design (routes + events only,
 * never config), so the resolution stays live per boot - but the constraint is
 * recorded here so it is never reintroduced.
 *
 * The transform methods (resolve / enforce_scheme) are pure and unit-tested;
 * patch_environment() is the impure boot seam that reads and writes the process
 * environment.
 */
class Rsx_App_Url
{
    /**
     * Substitute the `$HOSTNAME` / `${HOSTNAME}` token in a raw APP_URL with the OS
     * hostname and strip any trailing slash. Pure: no token means the value passes
     * through unchanged (idempotent - resolving an already-resolved value is a
     * no-op besides the trailing-slash trim).
     */
    public static function resolve(string $raw_app_url, string $os_hostname): string
    {
        // Braces form first so the no-brace pattern cannot partially match it.
        $resolved = str_replace('${HOSTNAME}', $os_hostname, $raw_app_url);
        $resolved = str_replace('$HOSTNAME', $os_hostname, $resolved);

        return rtrim($resolved, '/');
    }

    /**
     * Fail loud when APP_URL carries a scheme this mode does not accept.
     *
     * EMPTY is the one value whose meaning depends on the mode: in development it
     * is the un-configured first-run state that the setup screen exists to resolve,
     * and anywhere else it is a deployment nobody finished.
     *
     * https is accepted in every mode. http is accepted ONLY when $allow_http, which
     * the boot seam sets from Rsx::is_development().
     *
     * WHY THE LINE IS AT DEVELOPMENT, NOT DEBUG. A development box may be a local
     * container reached at http://localhost:8080 with nothing terminating TLS in
     * front of it, and the framework already follows the request scheme there: the
     * session cookie drops its Secure attribute in development (Rsx_Session_Cookie::
     * is_secure) precisely so a plain-http page can hold a session. In debug and
     * production that flag is unconditionally true, so an http page in those modes
     * would emit a Secure cookie the browser discards - every request silently
     * unauthenticated. Allowing http there would hand somebody a broken app instead
     * of an error, so those modes keep the hard https requirement.
     */
    public static function enforce_scheme(string $app_url, bool $allow_http): void
    {
        // EMPTY IN DEVELOPMENT IS THE FIRST-RUN STATE, not a misconfiguration.
        //
        // A fresh install ships APP_URL blank on purpose: a container cannot know
        // which host port was mapped to it, so the first-run setup screen asks the
        // browser instead. Throwing here would make every artisan command fail
        // before anyone could reach that screen - including the container
        // entrypoint's own migrate, which is what has to run before the site can
        // answer at all.
        //
        // Outside development there is no such screen and no such excuse: a blank
        // APP_URL there is a deployment nobody finished configuring.
        if (trim($app_url) === '' && $allow_http) {
            return;
        }

        $scheme = strtolower((string) parse_url($app_url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && $allow_http) {
            return;
        }

        if ($scheme === 'http') {
            throw new RuntimeException(
                'APP_URL must be https outside development mode. http is accepted only'
                . ' in development (RSX_MODE=development), where a local container may'
                . ' have no SSL terminator in front of it; debug and production assume'
                . ' upstream SSL termination and emit Secure session cookies that a'
                . ' plain-http page would discard. Use Caddy or a similar reverse proxy'
                . ' for SSL termination and set APP_URL=https://... in .env.'
                . ' Current value: "' . $app_url . '".'
            );
        }

        throw new RuntimeException(
            'APP_URL must be an http:// or https:// URL. Set it in .env (the $HOSTNAME'
            . ' token resolves to the OS hostname, e.g. APP_URL=https://$HOSTNAME;'
            . ' a local development container typically uses'
            . ' APP_URL=http://localhost:8080). Current value: "' . $app_url . '".'
        );
    }

    /**
     * Boot seam (substitution only): read the raw APP_URL, resolve the $HOSTNAME
     * token, and write the resolved value back into $_ENV / $_SERVER / putenv so
     * env() and every config file that reads env('APP_URL') see the resolved
     * hostname. Scheme enforcement is NOT done here - see enforce_scheme_from_env().
     *
     * Registered from bootstrap/app.php via afterLoadingEnvironment(), which runs
     * after phpdotenv has loaded .env and before LoadConfiguration reads it.
     */
    public static function patch_environment(): void
    {
        static $os_hostname = null;
        if ($os_hostname === null) {
            $os_hostname = (string) gethostname();
        }

        $raw = $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? getenv('APP_URL');
        $raw = is_string($raw) ? $raw : '';

        $resolved = self::resolve($raw, $os_hostname);

        $_ENV['APP_URL'] = $resolved;
        $_SERVER['APP_URL'] = $resolved;
        putenv('APP_URL=' . $resolved);
    }

    /**
     * Enforcement seam: fail loud when the (already resolved) APP_URL carries a
     * scheme this mode does not accept. Called from Rsx_Framework_Provider::boot() -
     * after HandleExceptions has bootstrapped, so the RuntimeException surfaces with
     * a readable message on both the web and CLI channels (a throw during the
     * earlier substitution phase renders unreadably - config is not loaded yet to
     * even log it).
     */
    public static function enforce_scheme_from_env(): void
    {
        self::enforce_scheme((string) env('APP_URL'), Rsx::is_development());
    }
}
