<?php

namespace App\RSpade\Core\Dependencies;

/**
 * Layered dependency management - shared logic.
 *
 * RSpade curates a committed framework base set (system/vendor, system/node_modules)
 * shipped downstream as plain git. End-user applications layer ADDITIONAL packages at
 * the project root (composer.json -> /vendor, package.json -> /node_modules); the
 * framework autoloader registers first, so the framework wins every overlap.
 *
 * This static class is the single source of truth for the mechanics of that layering:
 * reading the framework's installed sets, generating the composer "replace" map that
 * tells the app-layer solver "these are already supplied", reading/writing the root
 * manifests, exposure checks, provided-package recordings, and the offline reconcile
 * checks. All three consumers - the rsx:composer wrapper, the rsx:npm wrapper, and the
 * post-update reconciler - call into it so no logic is duplicated.
 *
 * The project root is dirname(base_path()) - Laravel runs from system/, the app layer
 * lives one directory up at /var/www/html.
 */
class Dependency_Manager
{
    /**
     * Memoized framework composer installed set (package_name => version).
     */
    private static ?array $installed_composer = null;

    /**
     * Memoized framework npm set (package_name => version range).
     */
    private static ?array $installed_npm = null;

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    */

    /**
     * The application project root - one directory above the Laravel base path.
     */
    public static function project_root(): string
    {
        return dirname(base_path());
    }

    /**
     * Path to the root (app-layer) composer.json.
     */
    public static function root_composer_json_path(): string
    {
        return static::project_root() . '/composer.json';
    }

    /**
     * Path to the root (app-layer) package.json.
     */
    public static function root_package_json_path(): string
    {
        return static::project_root() . '/package.json';
    }

    /*
    |--------------------------------------------------------------------------
    | Framework installed-set readers
    |--------------------------------------------------------------------------
    */

    /**
     * Every composer package physically installed in system/vendor, mapped to its
     * exact version (dev packages included - everything in vendor is single-load
     * reality that an app-layer solve must treat as already supplied).
     *
     * Versions are returned verbatim from installed.json (e.g. "v10.48.29"); composer
     * accepts the leading "v" in a replace constraint, so it is never stripped.
     *
     * The framework's own root meta-package (name matches system/composer.json) is
     * excluded - it is the project itself, not a dependency.
     */
    public static function get_framework_installed_composer(): array
    {
        if (static::$installed_composer !== null) {
            return static::$installed_composer;
        }

        $path = base_path('vendor/composer/installed.json');

        if (!file_exists($path)) {
            throw new \RuntimeException(
                'Framework composer installed set missing: ' . $path
                . ' - the framework vendor tree is not installed.'
            );
        }

        $decoded = json_decode(file_get_contents($path), true);

        if (!is_array($decoded) || !isset($decoded['packages']) || !is_array($decoded['packages'])) {
            throw new \RuntimeException('Corrupt composer installed.json (missing packages array): ' . $path);
        }

        $root_name = static::_framework_root_package_name();

        $map = [];
        foreach ($decoded['packages'] as $package) {
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;

            if ($name === null || $version === null) {
                continue;
            }

            if ($name === $root_name) {
                continue;
            }

            $map[$name] = $version;
        }

        ksort($map);

        static::$installed_composer = $map;

        return $map;
    }

    /**
     * The framework's declared npm set (dependencies + devDependencies) from
     * system/package.json, mapped name => version range.
     */
    public static function get_framework_installed_npm(): array
    {
        if (static::$installed_npm !== null) {
            return static::$installed_npm;
        }

        $path = base_path('package.json');

        if (!file_exists($path)) {
            throw new \RuntimeException(
                'Framework package.json missing: ' . $path
                . ' - the framework npm manifest is not present.'
            );
        }

        $decoded = json_decode(file_get_contents($path), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Corrupt framework package.json (not valid JSON): ' . $path);
        }

        $map = [];
        foreach (['dependencies', 'devDependencies'] as $section) {
            if (isset($decoded[$section]) && is_array($decoded[$section])) {
                foreach ($decoded[$section] as $name => $range) {
                    $map[$name] = $range;
                }
            }
        }

        ksort($map);

        static::$installed_npm = $map;

        return $map;
    }

    /*
    |--------------------------------------------------------------------------
    | Replace map
    |--------------------------------------------------------------------------
    */

    /**
     * The composer "replace" map: every framework-installed package at its exact
     * version, sorted by key. Written verbatim into the root composer.json so the
     * app-layer solver resolves against the framework pins without duplicating any
     * package, and incompatible app demands fail loudly at solve time.
     */
    public static function generate_composer_replace_map(): array
    {
        // get_framework_installed_composer() already excludes the root meta-package
        // and returns a ksorted map, which is exactly the replace-map shape.
        return static::get_framework_installed_composer();
    }

    /**
     * Write the generated replace map into the root composer.json "replace" key.
     * Returns whether the on-disk map actually changed.
     */
    public static function regenerate_replace_map(): bool
    {
        $data = static::read_root_composer_json();
        $map = static::generate_composer_replace_map();

        $current = $data['replace'] ?? [];

        if ($current === $map) {
            return false;
        }

        $data['replace'] = $map;
        static::write_root_composer_json($data);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Root manifest read / write
    |--------------------------------------------------------------------------
    */

    /**
     * Load the root composer.json. It ships with the project - absence or corruption
     * is a broken install, so this fails loud rather than fabricating a default.
     */
    public static function read_root_composer_json(): array
    {
        return static::_read_json_manifest(static::root_composer_json_path(), 'root composer.json');
    }

    /**
     * Persist the root composer.json (4-space pretty print, unescaped slashes,
     * trailing newline). Existing top-level key order is preserved - modified keys
     * keep their position, new keys append - so app author edits are never reordered.
     */
    public static function write_root_composer_json(array $data): void
    {
        static::_write_json_manifest(static::root_composer_json_path(), $data);
    }

    /**
     * Load the root package.json (fails loud if missing/corrupt - it ships too).
     */
    public static function read_root_package_json(): array
    {
        return static::_read_json_manifest(static::root_package_json_path(), 'root package.json');
    }

    /**
     * Persist the root package.json (same formatting contract as composer.json).
     */
    public static function write_root_package_json(array $data): void
    {
        static::_write_json_manifest(static::root_package_json_path(), $data);
    }

    /*
    |--------------------------------------------------------------------------
    | Exposure checks
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a composer package is on the framework's exposed surface (formally
     * requestable as provided-by-framework).
     */
    public static function is_exposed_composer(string $package): bool
    {
        return in_array($package, config('rsx.dependencies.exposed_composer', []), true);
    }

    /**
     * Whether a composer package is physically installed in the framework vendor tree.
     */
    public static function is_framework_installed_composer(string $package): bool
    {
        return array_key_exists($package, static::get_framework_installed_composer());
    }

    /**
     * The framework's installed version of a composer package, or null if absent.
     */
    public static function framework_composer_version(string $package): ?string
    {
        return static::get_framework_installed_composer()[$package] ?? null;
    }

    /**
     * Whether an npm package is on the framework's exposed surface.
     */
    public static function is_exposed_npm(string $package): bool
    {
        return in_array($package, config('rsx.dependencies.exposed_npm', []), true);
    }

    /**
     * Whether an npm package is declared in the framework's package.json.
     */
    public static function is_framework_installed_npm(string $package): bool
    {
        return array_key_exists($package, static::get_framework_installed_npm());
    }

    /**
     * The framework's declared version range for an npm package, or null if absent.
     */
    public static function framework_npm_version(string $package): ?string
    {
        return static::get_framework_installed_npm()[$package] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Provided-package recordings
    |--------------------------------------------------------------------------
    */

    /**
     * Record a framework-provided composer package (extra.rsx.provided) as
     * package => version-at-recording-time. This is a declared coupling consumed by
     * the reconciler; nothing is installed.
     */
    public static function record_provided_composer(string $package, string $version): void
    {
        $data = static::read_root_composer_json();

        $provided = $data['extra']['rsx']['provided'] ?? [];
        $provided[$package] = $version;
        ksort($provided);

        $data['extra']['rsx']['provided'] = $provided;

        static::write_root_composer_json($data);
    }

    /**
     * Remove a composer provided-recording. Returns whether a record was present.
     */
    public static function unrecord_provided_composer(string $package): bool
    {
        $data = static::read_root_composer_json();

        $provided = $data['extra']['rsx']['provided'] ?? [];

        if (!array_key_exists($package, $provided)) {
            return false;
        }

        unset($provided[$package]);
        $data['extra']['rsx']['provided'] = $provided;

        static::write_root_composer_json($data);

        return true;
    }

    /**
     * All recorded composer provided packages (package => recorded version).
     */
    public static function get_recorded_provided_composer(): array
    {
        $data = static::read_root_composer_json();

        return $data['extra']['rsx']['provided'] ?? [];
    }

    /**
     * Record a framework-provided npm package under root package.json rsx.provided.
     */
    public static function record_provided_npm(string $package, string $version): void
    {
        $data = static::read_root_package_json();

        $provided = $data['rsx']['provided'] ?? [];
        $provided[$package] = $version;
        ksort($provided);

        $data['rsx']['provided'] = $provided;

        static::write_root_package_json($data);
    }

    /**
     * Remove an npm provided-recording. Returns whether a record was present.
     */
    public static function unrecord_provided_npm(string $package): bool
    {
        $data = static::read_root_package_json();

        $provided = $data['rsx']['provided'] ?? [];

        if (!array_key_exists($package, $provided)) {
            return false;
        }

        unset($provided[$package]);
        $data['rsx']['provided'] = $provided;

        static::write_root_package_json($data);

        return true;
    }

    /**
     * All recorded npm provided packages (package => recorded version).
     */
    public static function get_recorded_provided_npm(): array
    {
        $data = static::read_root_package_json();

        return $data['rsx']['provided'] ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Reconcile checks (pure, local, no network)
    |--------------------------------------------------------------------------
    */

    /**
     * Cross-check every recorded composer provided package against the current
     * framework installed set. Recordings store the exact version at record time, so
     * a problem is reported when the package is gone entirely, or when the installed
     * version's MAJOR component differs from the recorded one (the only drift a
     * default-tooling-exists-forever posture treats as breaking).
     *
     * Feeds human-readable notices, not automation - deliberately simple and honest.
     *
     * @return array<int, array{package: string, recorded: string, installed: ?string, problem: string}>
     *         problem is 'removed' or 'major_change'.
     */
    public static function check_recorded_composer(): array
    {
        $problems = [];

        foreach (static::get_recorded_provided_composer() as $package => $recorded) {
            $installed = static::framework_composer_version($package);

            if ($installed === null) {
                $problems[] = [
                    'package'   => $package,
                    'recorded'  => $recorded,
                    'installed' => null,
                    'problem'   => 'removed',
                ];
                continue;
            }

            if (static::_major_of($recorded) !== static::_major_of($installed)) {
                $problems[] = [
                    'package'   => $package,
                    'recorded'  => $recorded,
                    'installed' => $installed,
                    'problem'   => 'major_change',
                ];
            }
        }

        return $problems;
    }

    /**
     * The npm mirror of check_recorded_composer(): cross-check every recorded npm
     * provided package against the framework's declared package.json set. A problem is
     * reported when the package is gone entirely ('removed'), or when the declared
     * version's MAJOR component differs from the recorded one ('major_change').
     *
     * Recorded and installed values are both framework version ranges (e.g. "^3.0.0")
     * and pass through the same major-extraction, so the comparison is apples-to-apples.
     *
     * @return array<int, array{package: string, recorded: string, installed: ?string, problem: string}>
     *         problem is 'removed' or 'major_change'.
     */
    public static function check_recorded_npm(): array
    {
        $problems = [];

        foreach (static::get_recorded_provided_npm() as $package => $recorded) {
            $installed = static::framework_npm_version($package);

            if ($installed === null) {
                $problems[] = [
                    'package'   => $package,
                    'recorded'  => $recorded,
                    'installed' => null,
                    'problem'   => 'removed',
                ];
                continue;
            }

            if (static::_major_of($recorded) !== static::_major_of($installed)) {
                $problems[] = [
                    'package'   => $package,
                    'recorded'  => $recorded,
                    'installed' => $installed,
                    'problem'   => 'major_change',
                ];
            }
        }

        return $problems;
    }

    /*
    |--------------------------------------------------------------------------
    | Internal helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The framework's own root composer package name (from system/composer.json),
     * used to exclude the meta-package from the installed set.
     */
    private static function _framework_root_package_name(): ?string
    {
        $path = base_path('composer.json');

        if (!file_exists($path)) {
            return null;
        }

        $decoded = json_decode(file_get_contents($path), true);

        return is_array($decoded) ? ($decoded['name'] ?? null) : null;
    }

    /**
     * The MAJOR version component of a version string, tolerant of a leading "v"
     * (e.g. "v10.48.29" -> "10"). Returns null for an unparseable input.
     */
    private static function _major_of(string $version): ?string
    {
        $trimmed = ltrim($version, 'vV');
        $trimmed = ltrim($trimmed);

        if ($trimmed === '') {
            return null;
        }

        // Major is everything up to the first dot (or the whole string if none).
        $dot = strpos($trimmed, '.');

        return $dot === false ? $trimmed : substr($trimmed, 0, $dot);
    }

    /**
     * Load and JSON-decode a shipped manifest, failing loud if missing or corrupt.
     */
    private static function _read_json_manifest(string $path, string $label): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException(
                "Missing {$label}: {$path} - this file ships with the project; its absence is a broken install."
            );
        }

        $decoded = json_decode(file_get_contents($path), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException("Corrupt {$label} (not valid JSON): {$path}");
        }

        return $decoded;
    }

    /**
     * Pretty-print and write a manifest (4-space indent, unescaped slashes, trailing
     * newline). PHP preserves array insertion order, so existing top-level keys keep
     * their position and only new keys append.
     *
     * Empty containers are emitted as JSON objects ("{}"), not arrays ("[]"):
     * json_decode(assoc=true) collapses an empty JSON object into an empty PHP array,
     * so without this an untouched empty "require": {} would round-trip to "require":
     * []. In composer.json/package.json every mutable container (require, replace,
     * dependencies, extra.rsx.provided, ...) is an object, so this is the correct and
     * lossless shape for these manifests.
     */
    private static function _write_json_manifest(string $path, array $data): void
    {
        $normalized = static::_objectify_empties($data);

        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode manifest JSON for: ' . $path);
        }

        file_put_contents($path, $json . "\n");
    }

    /**
     * Recursively replace empty arrays with empty objects so json_encode emits "{}"
     * for empty containers. Non-empty arrays keep their natural list/map encoding.
     */
    private static function _objectify_empties($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return new \stdClass();
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = static::_objectify_empties($item);
        }

        return $result;
    }
}
