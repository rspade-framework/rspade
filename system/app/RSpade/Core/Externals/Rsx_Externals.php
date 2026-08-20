<?php

namespace App\RSpade\Core\Externals;

use RuntimeException;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Rsx_Externals - the read model over declared external resources.
 *
 * Every external URL a page loads (a CDN library, a vendor widget) is DECLARED in a
 * `*.externals.php` file next to the feature that needs it, consolidated at manifest
 * build time by Externals_ManifestSupport, and reached from here by identifier. Nothing
 * else may inject a `<script>` or `<link>` at an external host: the Content-Security-Policy
 * whitelist DERIVES from these declarations, so an undeclared resource is a blocked
 * resource by construction, and policy can never drift from code.
 *
 * Two questions this class answers, and they are separate:
 *
 * - WHERE does the browser fetch the asset from? `resolve_url()` - the raw external URL in
 *   development, the locally mirrored `/_vendor/{file}` copy in a sealed build (the same
 *   predicate and the same filenames the bundle compiler's CDN assets use). Resolution is
 *   PURE: it never downloads anything. Populating the mirror is a build step.
 * - WHAT must the CSP permit? `csp_hosts_for_realm()` - the external origins still contacted
 *   in that mode, plus each entry's declared runtime `csp` extras.
 *
 * See: php artisan rsx:man external_resources
 */
class Rsx_Externals
{
    /** Realms a caller may query for. 'both' is a DECLARATION value, never a query value. */
    public const QUERYABLE_REALMS = ['staff', 'portal'];

    /**
     * Testing seam: stand in for the manifest-backed entry table.
     *
     * The declared table is whatever the tree declares, so per-realm and per-mirror policy
     * cannot be exercised against it. Tests set this to a table they built by running
     * Externals_ManifestSupport over fixture declarations, and restore it to null.
     */
    public static ?array $_testing_entries = null;

    /**
     * Every declared entry, keyed by identifier (ksorted at build time).
     */
    public static function all(): array
    {
        if (static::$_testing_entries !== null) {
            return static::$_testing_entries;
        }

        $manifest = Manifest::get_full_manifest();

        return $manifest['data']['external_resources'] ?? [];
    }

    /**
     * One entry, fully normalized.
     *
     * @throws RuntimeException when nothing declares the identifier.
     */
    public static function get(string $identifier): array
    {
        $all = static::all();

        if (!isset($all[$identifier])) {
            $known = empty($all) ? '(none declared)' : implode(', ', array_keys($all));

            throw new RuntimeException(
                "Unknown external resource '{$identifier}'\n" .
                "  Declared identifiers: {$known}\n" .
                "  Declare it in a *.externals.php file beside the feature that needs it.\n" .
                '  See: php artisan rsx:man external_resources'
            );
        }

        return $all[$identifier];
    }

    /**
     * Entries visible in one realm: those declaring that realm, plus every 'both'.
     */
    public static function all_for_realm(string $realm): array
    {
        static::_assert_queryable_realm($realm);

        $entries = [];

        foreach (static::all() as $identifier => $entry) {
            if ($entry['realm'] === $realm || $entry['realm'] === 'both') {
                $entries[$identifier] = $entry;
            }
        }

        ksort($entries);

        return $entries;
    }

    /**
     * The URL the browser should actually request.
     *
     * Development serves the raw external URL. Sealed builds (debug and strict production)
     * serve the locally mirrored copy from /_vendor/, so the build is self-contained -
     * exactly the policy Manifest::_should_cache_cdn() states for bundle CDN assets, reusing
     * Cdn_Cache's filenames so both halves name the same file.
     *
     * PURE: no download, no filesystem write. The mirror is populated by the build.
     */
    public static function resolve_url(string $url, bool $mirror): string
    {
        if (!$mirror || !Manifest::_should_cache_cdn()) {
            return $url;
        }

        return '/_vendor/' . Cdn_Cache::get_cache_filename($url, static::url_asset_type($url));
    }

    /**
     * 'css' for a stylesheet URL, 'js' for everything else (the two Cdn_Cache types).
     */
    public static function url_asset_type(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css' ? 'css' : 'js';
    }

    /**
     * The client-facing map baked into a bundle: identifier => what the loader needs.
     *
     * `integrity` survives only for a URL still fetched from its external host - a mirrored
     * copy is served same-origin from our own build and carries no external hash.
     */
    public static function resolved_map_for_realm(string $realm): array
    {
        $map = [];

        foreach (static::all_for_realm($realm) as $identifier => $entry) {
            $js = [];
            $css = [];
            $integrity = [];

            foreach (['js' => &$js, 'css' => &$css] as $key => &$bucket) {
                foreach ($entry[$key] as $url) {
                    $resolved = static::resolve_url($url, $entry['mirror']);
                    $bucket[] = $resolved;

                    if ($resolved === $url && isset($entry['integrity'][$url])) {
                        $integrity[$url] = $entry['integrity'][$url];
                    }
                }
            }
            unset($bucket);

            ksort($integrity);

            $map[$identifier] = [
                'js' => $js,
                'css' => $css,
                'integrity' => $integrity,
                'readiness' => $entry['readiness'],
            ];
        }

        ksort($map);

        return $map;
    }

    /**
     * The CSP sources a realm's policy must permit, as directive => origins.
     *
     * Asset origins are only whitelisted while the browser still fetches from them:
     * in development that is every entry; in a sealed build the mirrored ones collapse
     * to '/_vendor' (same-origin, already covered by 'self') and only mirror:false
     * entries remain. Each entry's declared `csp` extras merge in ALWAYS - they describe
     * what the script does at runtime (frames it opens, hosts it calls), which mirroring
     * the asset does not change.
     */
    public static function csp_hosts_for_realm(string $realm): array
    {
        $directives = [];
        $assets_are_external = !Manifest::_should_cache_cdn();

        foreach (static::all_for_realm($realm) as $entry) {
            if ($assets_are_external || !$entry['mirror']) {
                foreach ($entry['js'] as $url) {
                    $directives['script-src'][] = static::url_origin($url);
                }

                foreach ($entry['css'] as $url) {
                    $directives['style-src'][] = static::url_origin($url);
                }
            }

            foreach ($entry['csp'] as $directive => $sources) {
                foreach ($sources as $source) {
                    $directives[$directive][] = $source;
                }
            }
        }

        foreach ($directives as $directive => $sources) {
            $sources = array_values(array_unique($sources));
            sort($sources);
            $directives[$directive] = $sources;
        }

        ksort($directives);

        return $directives;
    }

    /**
     * scheme://host[:port] - a CSP source never carries a path.
     */
    public static function url_origin(string $url): string
    {
        $parts = parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException(
                "Cannot derive an origin from external resource URL '{$url}'\n" .
                '  A declared URL must be absolute (scheme + host).'
            );
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private static function _assert_queryable_realm(string $realm): void
    {
        if (!in_array($realm, self::QUERYABLE_REALMS, true)) {
            throw new RuntimeException(
                "Invalid realm '{$realm}'\n" .
                '  Query one of: ' . implode(', ', self::QUERYABLE_REALMS) . ".\n" .
                "  'both' is a declaration value meaning 'visible in either realm'; it is not something to query for."
            );
        }
    }
}
