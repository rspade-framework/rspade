<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Ide;

use App\RSpade\Core\Rsx;

/**
 * Ide_Bridge_Token - the local-file grant for the IDE bridge (server side, booted).
 *
 * The IDE bridge (/_ide/service/*) authenticates a caller by proving LOCAL filesystem
 * read access: the framework writes a single high-entropy token file into the bridge
 * directory (which lives OUTSIDE the web docroot), and a co-located IDE reads it from
 * disk and presents its contents as the X-Ide-Token bearer. auth.php verifies it
 * constant-time. No network endpoint ever mints or returns the token.
 *
 * Both the FILENAME and the CONTENT are cryptographically random and unguessable, so
 * neither a directory-listing leak (name exposed) nor a stray content leak alone is
 * sufficient - and the trust chain does NOT depend on file permissions (a `chmod 777`
 * cannot open it): the file is not web-served, and its name/content are unguessable.
 * 0600/0700 are applied best-effort as one more layer, never THE layer.
 *
 * ensure() is idempotent and dev-only; it also drops passive static-serve guards into
 * the directory and clears the retired auth-*.json / domain.txt artifacts.
 */
class Ide_Bridge_Token
{
    /** Glob pattern (and prefix/suffix) for the grant token file. */
    private const TOKEN_GLOB = 'ide-grant-*.token';

    /**
     * The bridge directory absolute path (storage, outside the docroot).
     *
     * @return string
     */
    public static function bridge_dir(): string
    {
        // The configured path is written relative to the directory CONTAINING storage/
        // (historically base_path()); volatile storage now lives at <project>/storage, so
        // resolve against the parent of the RESOLVED storage root instead of base_path().
        return dirname(storage_path()) . '/' . ltrim(config('rsx.ide_integration.bridge_path', 'storage/rsx-ide-bridge'), '/');
    }

    /**
     * Ensure a grant token + passive guards exist (development mode only). Idempotent:
     * creates the token only when none is present, and writes the guard files only when
     * absent. Safe to call on every web-request boot.
     *
     * @return void
     */
    public static function ensure(): void
    {
        // Dev-only. In debug/production the bridge is refused at auth.php anyway, and a
        // sealed build must never carry a token.
        if (!Rsx::is_development()) {
            return;
        }
        if (!config('rsx.ide_integration.enabled', true)) {
            return;
        }

        $dir = self::bridge_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        self::__write_guards($dir);
        self::__clear_retired_artifacts($dir);

        // Create the token only if none exists (persist across requests).
        $existing = glob($dir . '/' . self::TOKEN_GLOB) ?: [];
        if (empty($existing)) {
            $name = 'ide-grant-' . bin2hex(random_bytes(16)) . '.token';
            $secret = bin2hex(random_bytes(32));
            $path = $dir . '/' . $name;
            file_put_contents_safe($path, $secret);
            @chmod($path, 0600);
        }
    }

    /**
     * The current grant token's content, or null if none is established.
     *
     * @return string|null
     */
    public static function current_token(): ?string
    {
        $files = glob(self::bridge_dir() . '/' . self::TOKEN_GLOB) ?: [];
        if (empty($files)) {
            return null;
        }
        $secret = trim((string) file_get_contents($files[0]));
        return $secret !== '' ? $secret : null;
    }

    /**
     * Drop the passive static-serve guards (idempotent). These defend a MISCONFIGURED
     * environment (an operator who serves storage/, or an Apache switch where autoindex
     * is on by default): an index file that 404s, and an Apache deny rule. nginx never
     * autoindexes and funnels unknown paths to the front controller, so these are belt,
     * not the primary defense.
     *
     * @param string $dir
     * @return void
     */
    private static function __write_guards(string $dir): void
    {
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents_safe($index, "<?php http_response_code(404); exit;\n");
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents_safe($htaccess, "Require all denied\nDeny from all\n");
        }
    }

    /**
     * Remove the retired auth scheme's artifacts (the unauthenticated-mint session files
     * and the domain.txt discovery file, both no longer used).
     *
     * @param string $dir
     * @return void
     */
    private static function __clear_retired_artifacts(string $dir): void
    {
        foreach (glob($dir . '/auth-*.json') ?: [] as $stale) {
            @unlink($stale);
        }
        $domain_file = $dir . '/domain.txt';
        if (file_exists($domain_file)) {
            @unlink($domain_file);
        }
    }
}
