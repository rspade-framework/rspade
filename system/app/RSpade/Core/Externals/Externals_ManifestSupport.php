<?php

namespace App\RSpade\Core\Externals;

use RuntimeException;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module that consolidates every `*.externals.php` declaration file into one
 * manifest table: data.external_resources, keyed by identifier.
 *
 * A declaration file returns a bare array mapping identifier => spec:
 *
 * ```php
 * return [
 *     'chartjs' => [
 *         'js'        => ['https://cdn.example.com/chart.min.js'],
 *         'css'       => ['https://cdn.example.com/chart.min.css'],
 *         'integrity' => ['https://cdn.example.com/chart.min.js' => 'sha384-...'],
 *         'mirror'    => true,
 *         'realm'     => 'both',
 *         'readiness' => 'onload',
 *         'csp'       => ['connect-src' => ['https://telemetry.example.com']],
 *     ],
 * ];
 * ```
 *
 * Every constraint below is enforced here and fails the manifest build loud, naming the
 * source file and the identifier. There is no permissive path: a malformed declaration
 * would otherwise surface as a missing script tag or a silently-widened CSP.
 *
 * - The file must return an array; every key must be a valid identifier
 *   (lowercase letters, digits, underscores, starting with a letter).
 * - Spec keys are exactly {js, css, integrity, mirror, realm, readiness, csp}.
 * - js/css are lists of absolute https:// URLs; at least one of them must be non-empty.
 * - integrity maps a declared URL to its hash string.
 * - mirror is a bool (default true), realm is staff|portal|both (default both).
 * - readiness is 'onload' (default) or ['callback_param' => '<query param>'].
 * - csp maps a directive name to a list of source strings.
 * - An identifier declared twice is an error naming BOTH files.
 *
 * Specs are stored NORMALIZED (defaults applied) plus a 'file' annotation, and the table
 * is ksorted so the manifest is byte-deterministic.
 *
 * See: php artisan rsx:man external_resources
 */
class Externals_ManifestSupport extends ManifestSupport_Abstract
{
    /** Manifest file kind produced by _Manifest_Scanner_Helper::_process_file(). */
    public const FILE_EXTENSION = 'externals.php';

    /** The only keys a spec may carry. */
    public const ALLOWED_SPEC_KEYS = ['js', 'css', 'integrity', 'mirror', 'realm', 'readiness', 'csp'];

    /** The realms a declaration may target. */
    public const ALLOWED_REALMS = ['staff', 'portal', 'both'];

    /**
     * Declaration files already read in this process, keyed by path + mtime + size.
     *
     * `require` returns int(1) for a file it has already included, so a second manifest
     * build in one process could not re-read the array. The key carries mtime and size,
     * so an edited file is read again rather than served stale.
     */
    private static array $_file_cache = [];

    public static function get_name(): string
    {
        return 'External Resources';
    }

    public static function process(array &$manifest_data): void
    {
        $entries = [];
        $declared_in = [];

        $files = $manifest_data['data']['files'] ?? [];

        // Sort by path so a collision error names the two files in a stable order.
        ksort($files);

        foreach ($files as $file => $metadata) {
            if (($metadata['extension'] ?? null) !== self::FILE_EXTENSION) {
                continue;
            }

            $declarations = static::_read_declaration_file($file);

            foreach ($declarations as $identifier => $spec) {
                static::_validate_identifier($identifier, $file);

                if (isset($declared_in[$identifier])) {
                    throw new RuntimeException(
                        "Duplicate external resource identifier '{$identifier}'\n" .
                        "  Already declared in: {$declared_in[$identifier]}\n" .
                        "  Conflicting declaration: {$file}\n" .
                        '  Identifiers are a single flat namespace; rename one of them.'
                    );
                }

                $entries[$identifier] = static::_normalize_spec($identifier, $spec, $file);
                $declared_in[$identifier] = $file;
            }
        }

        ksort($entries);

        $manifest_data['data']['external_resources'] = $entries;
    }

    /**
     * Read one declaration file and assert it returned a map.
     */
    private static function _read_declaration_file(string $file): array
    {
        $absolute_path = base_path($file);

        $cache_key = $absolute_path . ':' . filemtime($absolute_path) . ':' . filesize($absolute_path);

        if (!isset(self::$_file_cache[$cache_key])) {
            $declarations = require $absolute_path;

            if (!is_array($declarations)) {
                throw new RuntimeException(
                    "Invalid external resource declaration file: {$file}\n" .
                    '  The file must `return` an array of identifier => spec.'
                );
            }

            self::$_file_cache[$cache_key] = $declarations;
        }

        return self::$_file_cache[$cache_key];
    }

    /**
     * An identifier is the name JavaScript loads by; keep it to one spelling.
     */
    private static function _validate_identifier(mixed $identifier, string $file): void
    {
        if (!is_string($identifier) || !preg_match('/^[a-z][a-z0-9_]*$/', $identifier)) {
            $printed = is_string($identifier) ? $identifier : var_export($identifier, true);

            throw new RuntimeException(
                "Invalid external resource identifier '{$printed}' in {$file}\n" .
                '  Identifiers are lowercase_with_underscores and start with a letter.'
            );
        }
    }

    /**
     * Validate one spec and return it with every default applied.
     */
    private static function _normalize_spec(string $identifier, mixed $spec, string $file): array
    {
        $location = "external resource '{$identifier}' in {$file}";

        if (!is_array($spec)) {
            throw new RuntimeException(
                "Invalid {$location}\n" .
                '  A declaration must be an array of spec keys.'
            );
        }

        foreach (array_keys($spec) as $key) {
            if (!in_array($key, self::ALLOWED_SPEC_KEYS, true)) {
                throw new RuntimeException(
                    "Unknown key '{$key}' in {$location}\n" .
                    '  Allowed keys: ' . implode(', ', self::ALLOWED_SPEC_KEYS) . '.'
                );
            }
        }

        $js = static::_normalize_url_list($spec['js'] ?? [], 'js', $location);
        $css = static::_normalize_url_list($spec['css'] ?? [], 'css', $location);

        if (empty($js) && empty($css)) {
            throw new RuntimeException(
                "Nothing declared for {$location}\n" .
                '  At least one of `js` or `css` must list a URL.'
            );
        }

        return [
            'identifier' => $identifier,
            'js' => $js,
            'css' => $css,
            'integrity' => static::_normalize_integrity($spec['integrity'] ?? [], array_merge($js, $css), $location),
            'mirror' => static::_normalize_mirror($spec['mirror'] ?? true, $location),
            'realm' => static::_normalize_realm($spec['realm'] ?? 'both', $location),
            'readiness' => static::_normalize_readiness($spec['readiness'] ?? 'onload', $location),
            'csp' => static::_normalize_csp($spec['csp'] ?? [], $location),
            'file' => $file,
        ];
    }

    /**
     * js/css must be a list of absolute https:// URLs.
     */
    private static function _normalize_url_list(mixed $urls, string $key, string $location): array
    {
        if (!is_array($urls)) {
            throw new RuntimeException(
                "Invalid `{$key}` in {$location}\n" .
                '  Expected a list of https:// URLs.'
            );
        }

        if (!array_is_list($urls)) {
            throw new RuntimeException(
                "Invalid `{$key}` in {$location}\n" .
                '  Expected a plain list, not a keyed array.'
            );
        }

        foreach ($urls as $url) {
            if (!is_string($url) || !str_starts_with($url, 'https://')) {
                $printed = is_string($url) ? $url : var_export($url, true);

                throw new RuntimeException(
                    "Invalid `{$key}` URL '{$printed}' in {$location}\n" .
                    '  External resources are declared as absolute https:// URLs.'
                );
            }
        }

        return $urls;
    }

    /**
     * integrity maps a DECLARED url to its hash string.
     */
    private static function _normalize_integrity(mixed $integrity, array $declared_urls, string $location): array
    {
        if (!is_array($integrity)) {
            throw new RuntimeException(
                "Invalid `integrity` in {$location}\n" .
                '  Expected a map of url => hash.'
            );
        }

        foreach ($integrity as $url => $hash) {
            if (!is_string($url) || !in_array($url, $declared_urls, true)) {
                $printed = is_string($url) ? $url : var_export($url, true);

                throw new RuntimeException(
                    "Unknown `integrity` URL '{$printed}' in {$location}\n" .
                    '  Every integrity key must be a URL declared in `js` or `css`.'
                );
            }

            if (!is_string($hash) || $hash === '') {
                throw new RuntimeException(
                    "Invalid `integrity` hash for '{$url}' in {$location}\n" .
                    '  Expected a non-empty subresource-integrity string.'
                );
            }
        }

        ksort($integrity);

        return $integrity;
    }

    private static function _normalize_mirror(mixed $mirror, string $location): bool
    {
        if (!is_bool($mirror)) {
            throw new RuntimeException(
                "Invalid `mirror` in {$location}\n" .
                '  Expected true or false.'
            );
        }

        return $mirror;
    }

    private static function _normalize_realm(mixed $realm, string $location): string
    {
        if (!is_string($realm) || !in_array($realm, self::ALLOWED_REALMS, true)) {
            $printed = is_string($realm) ? $realm : var_export($realm, true);

            throw new RuntimeException(
                "Invalid `realm` '{$printed}' in {$location}\n" .
                '  Expected one of: ' . implode(', ', self::ALLOWED_REALMS) . '.'
            );
        }

        return $realm;
    }

    /**
     * readiness is 'onload', or a named-global handshake declared as
     * ['callback_param' => '<query param the URL passes the global name in>'].
     */
    private static function _normalize_readiness(mixed $readiness, string $location): array|string
    {
        if ($readiness === 'onload') {
            return 'onload';
        }

        if (is_array($readiness) && array_keys($readiness) === ['callback_param']) {
            $param = $readiness['callback_param'];

            if (!is_string($param) || $param === '') {
                throw new RuntimeException(
                    "Invalid `readiness` callback_param in {$location}\n" .
                    '  Expected the non-empty name of the URL query parameter carrying the callback name.'
                );
            }

            return ['callback_param' => $param];
        }

        throw new RuntimeException(
            "Invalid `readiness` in {$location}\n" .
            "  Expected 'onload' or ['callback_param' => '<query param>']."
        );
    }

    /**
     * csp maps a directive name to a list of source strings. These are RUNTIME
     * behaviors of the external script (frames it opens, hosts it calls), not the
     * asset URLs themselves - those derive from js/css.
     */
    private static function _normalize_csp(mixed $csp, string $location): array
    {
        if (!is_array($csp)) {
            throw new RuntimeException(
                "Invalid `csp` in {$location}\n" .
                '  Expected a map of directive => list of sources.'
            );
        }

        foreach ($csp as $directive => $sources) {
            if (!is_string($directive) || !preg_match('/^[a-z][a-z0-9-]*$/', $directive)) {
                $printed = is_string($directive) ? $directive : var_export($directive, true);

                throw new RuntimeException(
                    "Invalid `csp` directive '{$printed}' in {$location}\n" .
                    "  Expected a CSP directive name such as 'frame-src'."
                );
            }

            if (!is_array($sources) || !array_is_list($sources) || empty($sources)) {
                throw new RuntimeException(
                    "Invalid `csp` sources for '{$directive}' in {$location}\n" .
                    '  Expected a non-empty list of source strings.'
                );
            }

            foreach ($sources as $source) {
                if (!is_string($source) || $source === '') {
                    throw new RuntimeException(
                        "Invalid `csp` source for '{$directive}' in {$location}\n" .
                        '  Every source must be a non-empty string.'
                    );
                }
            }

            sort($sources);
            $csp[$directive] = $sources;
        }

        ksort($csp);

        return $csp;
    }
}
