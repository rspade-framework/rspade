<?php

namespace App\RSpade\Core\Bundle;

use RuntimeException;
use App\RSpade\CodeQuality\RuntimeChecks\BundleErrors;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Csp\Rsx_Csp;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Bundle_Abstract - Abstract base class for user-defined bundles
 *
 * Bundles are high-level asset collections that define what JavaScript, CSS,
 * and other resources should be included in a page. The bundle system uses a
 * unified 'include' array that automatically detects what each item is:
 * - Module aliases (jquery, lodash, bootstrap5)
 * - Bundle aliases (defined in config/rsx.php)
 * - Bundle class names (e.g., Frontend_Bundle)
 * - Bundle file paths (e.g., rsx/theme/bootstrap5_src_bundle.php)
 * - Directory paths (e.g., rsx/app/frontend)
 * - Regular file paths (e.g., rsx/theme/variables.scss)
 *
 * USAGE:
 * 1. Create a bundle class that extends Rsx_Bundle_Abstract
 * 2. Implement the define() method with an 'include' array
 * 3. Call BundleName::render() in Blade views
 * 4. Optional: declare public static load_rsxapp_data(): array to inject
 *    request-time key/value pairs into window.rsxapp.page_data at render
 *    (see rsx:man bundle_api, LOAD_RSXAPP_DATA HOOK)
 *
 * EXAMPLE:
 * class Frontend_Bundle extends Rsx_Bundle_Abstract {
 *     public static function define(): array {
 *         return [
 *             'include' => [
 *                 'jquery',                    // Module alias
 *                 'bootstrap5_src',            // Bundle alias
 *                 Common_Bundle::class,        // Bundle class
 *                 'rsx/theme/variables.scss',  // File
 *                 'rsx/app/frontend',          // Directory
 *             ],
 *         ];
 *     }
 * }
 */
abstract class Rsx_Bundle_Abstract
{
    /**
     * Define the bundle's assets
     *
     * Return an array with the following keys:
     * - include: Array of items to include (auto-detected types)
     * - config: Bundle-specific configuration (optional)
     * - npm_modules: NPM modules to include (optional)
     * - cdn_assets: Direct CDN assets (optional)
     * - local_assets: Local assets not bundled (optional)
     *
     * The 'include' array accepts:
     * - Module aliases: 'jquery', 'lodash', 'bootstrap5', 'jqhtml'
     * - Bundle aliases: Defined in config/rsx.php bundle_aliases
     * - Bundle classes: MyBundle::class or 'MyBundle'
     * - Bundle files: 'path/to/bundle.php'
     * - Directories: 'rsx/app/mymodule'
     * - Files: 'rsx/theme/style.scss'
     *
     * @return array Bundle definition
     */
    abstract public static function define(): array;

    /**
     * Track which bundle has been rendered for this request
     * Format: ['bundle_class' => 'Bundle_Name', 'calling_location' => 'file:line']
     */
    public static ?array $_has_rendered = null;

    /**
     * Render a bundle's HTML output
     *
     * @param array $options Rendering options
     * @return string HTML output with script/link tags
     */
    public static function render(array $options = []): string
    {
        // Get the calling class
        $bundle_class = get_called_class();

        // Fatal if called directly on Rsx_Bundle_Abstract
        if ($bundle_class === __CLASS__ || $bundle_class === 'App\\RSpade\\Core\\Bundle\\Rsx_Bundle_Abstract') {
            // Cannot call abstract class directly
            BundleErrors::abstract_called_directly();
        }

        // Check if another bundle has already been rendered
        if (Rsx_Bundle_Abstract::$_has_rendered !== null) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $backtrace[0] ?? [];
            $current_location = ($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? '?');

            throw new RuntimeException(
                "Multiple bundle render attempted.\n\n" .
                'Already rendered: ' . Rsx_Bundle_Abstract::$_has_rendered['bundle_class'] . "\n" .
                'Called from: ' . Rsx_Bundle_Abstract::$_has_rendered['calling_location'] . "\n\n" .
                'Attempted to render: ' . $bundle_class . "\n" .
                'Called from: ' . $current_location . "\n\n" .
                "Only one bundle is allowed per page. If you need to share code between bundles,\n" .
                "include the shared bundle in your bundle's configuration:\n\n" .
                "class Your_Bundle extends Rsx_Bundle_Abstract {\n" .
                "    public static function define(): array {\n" .
                "        return [\n" .
                "            'include' => [\n" .
                "                Shared_Bundle::class,  // Include another bundle\n" .
                "                'your/specific/files',\n" .
                "            ]\n" .
                "        ];\n" .
                "    }\n" .
                '}'
            );
        }

        // Track this bundle render
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $backtrace[0] ?? [];
        Rsx_Bundle_Abstract::$_has_rendered = [
            'bundle_class' => $bundle_class,
            'calling_location' => ($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? '?'),
        ];

        // Dump any console debug messages before bundle output
        \App\RSpade\Core\Debug\Debugger::dump_console_debug_messages_to_html();

        // In development mode, validate path coverage
        if (Rsx::is_development()) {
            static::__validate_path_coverage($bundle_class);
        }

        $compiler = new BundleCompiler($options);

        $compiled = $compiler->compile($bundle_class);

        // App-supplied rsxapp data hook: if the bundle declares a public static
        // load_rsxapp_data(), call it just prior to render and merge its returned
        // key/value pairs into PageData, so they are printed inside
        // window.rsxapp.page_data below. This lets a bundle ship request-time
        // lookups (e.g. an unread-notification count for a logged-in user) so
        // widgets can paint immediately instead of waiting on an Ajax round trip.
        if (method_exists($bundle_class, 'load_rsxapp_data')) {
            $rsxapp_hook_data = $bundle_class::load_rsxapp_data();

            if (!is_array($rsxapp_hook_data)) {
                throw new RuntimeException(
                    "{$bundle_class}::load_rsxapp_data() must return an array of " .
                    'key/value pairs, got ' . gettype($rsxapp_hook_data)
                );
            }

            if (!empty($rsxapp_hook_data)) {
                \App\RSpade\Core\View\PageData::add($rsxapp_hook_data);
            }
        }

        // Generate HTML output
        return static::__generate_html($compiled, $options);
    }

    /**
     * Get the bundle definition with resolved dependencies
     *
     * @param string $bundle_class Bundle class name
     * @return array Resolved bundle definition
     */
    public static function get_resolved_definition(string $bundle_class): array
    {
        // Get raw definition - verify class exists in Manifest
        try {
            // Check if this is a FQCN (contains backslash) or simple name
            if (strpos($bundle_class, '\\') !== false) {
                // It's a FQCN, use php_get_metadata_by_fqcn
                $metadata = Manifest::php_get_metadata_by_fqcn($bundle_class);
            } else {
                // It's a simple name, use php_get_metadata_by_class
                $metadata = Manifest::php_get_metadata_by_class($bundle_class);
            }
            if (!isset($metadata['extends']) || ($metadata['extends'] !== 'Rsx_Bundle_Abstract' && $metadata['extends'] !== 'RsxBundle')) {
                // Check if it extends a class that extends Rsx_Bundle_Abstract
                $extends_bundle = false;
                $current_extends = $metadata['extends'] ?? null;
                while ($current_extends && !$extends_bundle) {
                    if ($current_extends === 'Rsx_Bundle_Abstract' || $current_extends === 'RsxBundle') {
                        $extends_bundle = true;
                    } else {
                        try {
                            $parent_metadata = Manifest::php_get_metadata_by_class($current_extends);
                            $current_extends = $parent_metadata['extends'] ?? null;
                        } catch (RuntimeException $e) {
                            break;
                        }
                    }
                }
                if (!$extends_bundle) {
                    throw new RuntimeException("Class {$bundle_class} must extend Rsx_Bundle_Abstract");
                }
            }
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found in manifest')) {
                throw new RuntimeException("Bundle class not found: {$bundle_class}");
            }

            throw $e;
        }

        $definition = $bundle_class::define();

        // Base config that all bundles inherit
        $base_config = [
            'debug' => config('app.debug'),
            'build_key' => Manifest::get_build_key(),
        ];

        // Start with base config
        $config = $base_config;

        // Merge in bundle-specific config using array_merge_deep
        if (!empty($definition['config'])) {
            $config = array_merge_deep($config, $definition['config']);
        }

        // Normalize definition structure - simplified with unified 'include' array
        $resolved = [
            'include' => $definition['include'] ?? [],
            'npm_modules' => $definition['npm_modules'] ?? [],  // NPM modules support
            'config' => $config,
            'cdn_assets' => $definition['cdn_assets'] ?? [],
            'local_assets' => $definition['local_assets'] ?? [],
        ];

        return $resolved;
    }

    /**
     * Compute the Rsx_Storage scope key (window.rsxapp.cache_key).
     *
     * Replicates the JS join Rsx.scope_key() used to perform client-side EXACTLY:
     * each ingredient is appended only when truthy, the surviving parts are joined
     * with '_' (empty parts skipped), and the result is md5'd. Kept as a public
     * static so the recipe is unit-testable in isolation. Two different users on
     * the same session row produce different keys (the user id ingredient differs),
     * which is the property that lets Rsx_Storage invalidate one user's scoped keys
     * when another user loads a page on the same session.
     *
     * @param string|null $session_hash HMAC session hash (window.rsxapp.session_hash), or null
     * @param int|string|null $user_id  Current user id, or null/empty when anonymous
     * @param int|string|null $site_id  Current site id, or null/empty when unset
     * @param string $build_key         Manifest build key
     * @return string 32-char md5 hex
     */
    public static function _compute_cache_key(?string $session_hash, $user_id, $site_id, string $build_key): string
    {
        $parts = [];

        // Truthiness gate mirrors the JS `if (window.rsxapp?.x)` guards exactly:
        // null / '' / 0 are all skipped (ids are never 0, so this is safe).
        if (!empty($session_hash)) {
            $parts[] = $session_hash;
        }
        if (!empty($user_id)) {
            $parts[] = $user_id;
        }
        if (!empty($site_id)) {
            $parts[] = $site_id;
        }
        if (!empty($build_key)) {
            $parts[] = $build_key;
        }

        return md5(implode('_', $parts));
    }

    /**
     * Generate HTML output for compiled bundle
     *
     * @param array $compiled Compiled bundle data
     * @param array $options Rendering options
     * @return string HTML output
     */
    protected static function __generate_html(array $compiled, array $options = []): string
    {
        // Validate we have the expected structure
        if (!is_array($compiled)) {
            throw new RuntimeException('BundleCompiler returned non-array: ' . gettype($compiled));
        }

        // Expect vendor/app split files
        if (!isset($compiled['vendor_js_bundle_path']) &&
            !isset($compiled['app_js_bundle_path']) &&
            !isset($compiled['vendor_css_bundle_path']) &&
            !isset($compiled['app_css_bundle_path'])) {
            throw new RuntimeException('BundleCompiler missing expected vendor/app bundle paths. Got keys: ' . implode(', ', array_keys($compiled)));
        }

        $html = [];
        $manifest_hash = Manifest::get_build_key();

        // Consolidate all data into window.rsxapp
        $rsxapp_data = [];

        // Add build_key (always included in both dev and production)
        $rsxapp_data['build_key'] = $manifest_hash;

        // Add session_hash if session exists (hashed for scoping, non-reversible).
        // ONE cookie for the whole site - staff and portal pages hash the same token.
        $session_token = $_COOKIE['rsx'] ?? null;
        $rsxapp_data['session_hash'] = $session_token
            ? hash_hmac('sha256', $session_token, config('app.key'))
            : null;

        // Add runtime data
        $rsxapp_data['debug'] = Rsx::is_development();
        // Use portal-specific methods when in portal context
        $is_portal = Rsx_Portal::is_portal_request();
        $rsxapp_data['is_portal'] = $is_portal;
        $rsxapp_data['current_controller'] = $is_portal ? Rsx_Portal::get_current_controller() : Rsx::get_current_controller();
        $rsxapp_data['current_action'] = $is_portal ? Rsx_Portal::get_current_action() : Rsx::get_current_action();
        $rsxapp_data['is_spa'] = $is_portal ? Rsx_Portal::is_spa() : Rsx::is_spa();

        // Enable ajax batching in debug/production modes, disable in development for easier debugging
        $rsxapp_data['ajax_batching'] = !Rsx::is_development();

        // Add current params (always set to reduce state variations)
        $current_params = \App\RSpade\Core\Rsx::get_current_params();
        $rsxapp_data['params'] = $current_params ?? [];

        // Add user, site, and csrf data from session
        // Use Portal_Session for portal requests, Session for regular requests
        if ($is_portal) {
            $rsxapp_data['is_auth'] = Portal_Session::is_logged_in();
            $rsxapp_data['user'] = Portal_Session::get_portal_user();
            $rsxapp_data['site'] = Portal_Session::get_site();
            $rsxapp_data['csrf'] = Portal_Session::get_csrf_token();

            // Portal authorization block consumed by Portal_Permission.js for hiding
            // UI affordances only (the server is the enforcement boundary). Mirrors
            // how staff resolved permissions reach window.rsxapp.
            $rsxapp_data['portal'] = static::_build_portal_permission_block();
        } else {
            $rsxapp_data['is_auth'] = Session::is_logged_in();
            $rsxapp_data['user'] = Session::get_user();
            $rsxapp_data['site'] = Session::get_site();
            $rsxapp_data['csrf'] = Session::get_csrf_token();

            // Framework-owned impersonation flag (ids only - the app resolves the
            // display name for any banner, since it owns User_Model). null when the
            // session is not impersonating. window.rsxapp.user already reflects the
            // effective (target) user during impersonation.
            $rsxapp_data['impersonation'] = Session::is_impersonating() ? [
                'impersonator_login_user_id' => Session::get_impersonator_login_user_id(),
                'started_at' => Session::get_impersonation_started_at(),
            ] : null;
        }

        // Declarative auth gates for the ACTIVE realm (the same portal/staff split
        // the identity fork above made - active_realm() reads the same predicate).
        // GRANTS ONLY: {name: 1} for every #[Auth_Check] that returned true, denied
        // checks omitted entirely. Evaluation goes through the memoized engine, so a
        // check a dispatch seam already consulted during this request does not run
        // twice. 'public' always passes, so an anonymous render still ships
        // {"public": 1}. Consumed by the generated Permission/Portal_Permission
        // mirrors and by Spa.dispatch's client gate.
        $auth_realm = Auth_Gates::active_realm();
        $rsxapp_data['auth'] = Auth_Gates::export_grants($auth_realm);

        // Reachable PHP page surfaces, for JS Permission.can_access('Controller::method').
        // OPT-IN and OFF by default: when the config is false the key is never
        // defined at all (see config/rsx.php 'auth' for the privacy rationale).
        if (config('rsx.auth.export_php_route_grants') === true) {
            $rsxapp_data['auth_routes'] = Auth_Gates::export_route_grants($auth_realm);
        }

        // Rsx_Storage scope key: the md5 of the exact ingredients Rsx.scope_key()
        // used to join CLIENT-side (session_hash / user id / site id / build_key).
        // Computed here, server-side, so client code never derives storage scope
        // from session_hash. Portal pages scope on portal identity because the
        // is_portal branch above resolved user/site from Portal_Session.
        $rsxapp_data['cache_key'] = static::_compute_cache_key(
            $rsxapp_data['session_hash'],
            $rsxapp_data['user']?->id,
            $rsxapp_data['site']?->id,
            $manifest_hash
        );

        // Add browser error logging flag (enabled in both dev and production)
        if (config('rsx.log_browser_errors', false)) {
            $rsxapp_data['log_browser_errors'] = true;
        }

        // Add page_data if any was set via @rsx_page_data directive
        if (\App\RSpade\Core\View\PageData::has_data()) {
            $rsxapp_data['page_data'] = \App\RSpade\Core\View\PageData::get();
        }

        // Add flash_alerts if any pending for current session
        $flash_messages = \App\RSpade\Lib\Flash\Flash_Alert::get_pending_messages();
        if (!empty($flash_messages)) {
            $rsxapp_data['flash_alerts'] = $flash_messages;
        }

        // Add realtime WebSocket URL if enabled. Derived from the application
        // hostname (in web mode that is the request host), so the browser always
        // connects back to the host it browsed - no separate .env URL to drift.
        if (\App\RSpade\Core\Realtime\Realtime::is_enabled()) {
            // Canonical wss form; the client (Rsx_Realtime._connect_url) downgrades the
            // scheme to ws:// when the PAGE itself is plain http (the rsx:debug harness,
            // and a development container published on plain http) so realtime works there.
            // get_http_host() rather than get_hostname(): the browser must connect to the
            // port it browsed, and a container published on :8080 would otherwise be handed
            // a portless URL pointing at :80.
            $rsxapp_data['realtime_url'] = 'wss://' . Rsx::get_http_host() . '/ws';
            $rsxapp_data['realtime'] = [
                'load_gate_timeout_ms' => config('rsx.realtime.load_gate_timeout_ms', 1500),
                'stale_reload_after_ms' => (int) config('rsx.realtime.stale_reload_after_ms', 3600000),
            ];
        }

        // File limits, ALWAYS present - unlike realtime_url this is not conditional on a
        // feature being enabled, so client code can read it as a constant and never has to
        // guard for its absence.
        //
        // The upload endpoint is the enforcement boundary and always will be; this exists so
        // the client can tell the user the file is too big BEFORE spending minutes pushing it
        // up a slow connection only to be refused. Same name as the config key it comes from
        // (rsx.files.max_file_size) - a client-side alias would just break grep.
        //
        // 0 means "no framework ceiling"; the client must treat that as unlimited rather than
        // as "reject everything", which is what a naive `size > max` would do.
        $rsxapp_data['files'] = [
            'max_file_size' => (int) config('rsx.files.max_file_size', 0),
        ];

        // ORM batch limits, ALWAYS present for the same reason the file block is: the JS
        // ORM batcher reads the cap on every flush to decide where to split a large id
        // set, and a client-side copy of the number would silently disagree with the
        // server the day the config changes. Same name as the config key it comes from
        // (rsx.model_fetch.batch_max_ids).
        $rsxapp_data['model_fetch'] = [
            'batch_max_ids' => (int) config('rsx.model_fetch.batch_max_ids'),
        ];

        // Turnstile site key - CONDITIONAL, realtime-style: absent means the feature is
        // off, and the widget renders its inert placeholder rather than a live challenge.
        // A key that is always present but sometimes empty would make "off" and
        // "misconfigured" indistinguishable to the client. site_key() throws when the
        // feature is enabled with either key missing (owner ruling): a half-configured
        // install fails at render, not silently at the first login attempt.
        if (\App\RSpade\Core\Turnstile\Rsx_Turnstile::is_enabled()) {
            $rsxapp_data['turnstile'] = \App\RSpade\Core\Turnstile\Rsx_Turnstile::site_key();
        }

        // CSP nonce - the SAME value the response header carries (Rsx_Csp memoizes it per
        // request). Every inline <script> the framework emits is stamped with it, and the
        // client loader (Rsx_External_Resources) reads it from here to stamp the <script>
        // tags it appends for a declared external resource.
        $rsxapp_data['csp_nonce'] = Rsx_Csp::nonce();

        // Whether the policy only OBSERVES. The dev violation catcher says so when it
        // explains a violation, so a developer is never told a page was blocked when the
        // browser merely reported it.
        $rsxapp_data['csp'] = [
            'report_only' => Rsx_Csp::is_report_only(),
        ];

        // Add time data for Rsx_Time initialization
        $rsxapp_data['server_time'] = \App\RSpade\Core\Time\Rsx_Time::now_iso();
        $rsxapp_data['user_timezone'] = \App\RSpade\Core\Time\Rsx_Time::get_user_timezone();

        // Whether the browser's detected zone may auto-set this user's preference.
        // A PERSONAL fact, so it exists only when a staff login identity does: null
        // for a portal request or a signed-out page, where no user's preference is
        // being described (the boot hook guards on is_auth anyway, and null reads
        // false at every consumer).
        $login_user = $is_portal ? null : Session::get_login_user();
        $rsxapp_data['user_timezone_auto'] = $login_user ? (bool) $login_user->timezone_auto : null;

        // Add console_debug config in development AND debug modes (strict production
        // omits it; call sites are stripped from the bundle there). Policy helper.
        if (Manifest::_should_include_debug_info()) {
            $console_debug_config = config('rsx.console_debug', []);

            // Build console_debug settings
            $filter_mode = $console_debug_config['filter_mode'] ?? 'all';

            // Get the appropriate channel list based on filter mode
            $filter_channels = [];
            if ($filter_mode === 'whitelist') {
                $filter_channels = $console_debug_config['whitelist'] ?? [];
            } elseif ($filter_mode === 'blacklist') {
                $filter_channels = $console_debug_config['blacklist'] ?? [];
            }

            $console_debug = [
                'enabled' => $console_debug_config['enabled'] ?? true,
                'filter_mode' => $filter_mode,
                'filter_channels' => $filter_channels,
                'specific_channel' => $console_debug_config['specific_channel'] ?? null,
                'include_timestamp' => $console_debug_config['include_timestamp'] ?? false,
                'include_benchmark' => $console_debug_config['include_benchmark'] ?? false,
                'include_location' => $console_debug_config['include_location'] ?? false,
                'include_backtrace' => $console_debug_config['include_backtrace'] ?? false,
                'outputs' => [
                    'browser' => ($console_debug_config['outputs']['web'] ?? true),
                    'laravel_log' => ($console_debug_config['outputs']['laravel_log'] ?? false),
                ],
            ];

            // Check for Playwright test headers that override console_debug settings
            // Only from loopback IPs without proxy headers
            $request = request();
            if ($request && $request->hasHeader('X-Playwright-Test') && is_loopback_ip()) {
                // Check for disable header first
                if ($request->hasHeader('X-Console-Debug-Disable')) {
                    $console_debug['enabled'] = false;
                }

                // Check for console debug filter header
                if ($request->hasHeader('X-Console-Debug-Filter')) {
                    $filter = $request->header('X-Console-Debug-Filter');
                    if ($filter) {
                        $console_debug['filter_mode'] = 'specific';
                        $console_debug['specific_channel'] = strtoupper($filter);
                    }
                }

                // Check for benchmark header
                if ($request->hasHeader('X-Console-Debug-Benchmark')) {
                    $console_debug['include_benchmark'] = true;
                }

                // Check for all channels header
                if ($request->hasHeader('X-Console-Debug-All')) {
                    $console_debug['filter_mode'] = 'all';
                    $console_debug['specific_channel'] = null;
                }
            }

            $rsxapp_data['console_debug'] = $console_debug;
        }

        // Filter out keys starting with single underscore (but allow double underscore like __MODEL)
        $rsxapp_data = static::__filter_underscore_keys($rsxapp_data);

        // Pretty print rsxapp for browser-devtools readability in development and
        // debug (both non-production); compact in strict production (smaller payload,
        // and no need to expose a neatly-indented app-state blob to end users).
        $rsxapp_json_flags = JSON_UNESCAPED_SLASHES;
        if (!Rsx::is_production()) {
            $rsxapp_json_flags |= JSON_PRETTY_PRINT;
        }
        $rsxapp_json = json_encode($rsxapp_data, $rsxapp_json_flags);

        $html[] = '<script nonce="' . htmlspecialchars(Rsx_Csp::nonce()) . '">window.rsxapp = ' . $rsxapp_json . ';</script>';

        // Sort CDN assets: jQuery first, then others in deterministic order
        $cdn_css = $compiled['cdn_css'] ?? [];
        $cdn_js = $compiled['cdn_js'] ?? [];

        // Separate jQuery assets from others
        $jquery_css = [];
        $other_css = [];
        foreach ($cdn_css as $asset) {
            if (stripos($asset['url'], 'jquery') !== false) {
                $jquery_css[] = $asset;
            } else {
                $other_css[] = $asset;
            }
        }

        $jquery_js = [];
        $other_js = [];
        foreach ($cdn_js as $asset) {
            if (stripos($asset['url'], 'jquery') !== false) {
                $jquery_js[] = $asset;
            } else {
                $other_js[] = $asset;
            }
        }

        // Sort other assets by URL for deterministic ordering
        usort($other_css, function ($a, $b) {
            return strcmp($a['url'], $b['url']);
        });
        usort($other_js, function ($a, $b) {
            return strcmp($a['url'], $b['url']);
        });

        // Add CSS: jQuery first, then others
        foreach (array_merge($jquery_css, $other_css) as $asset) {
            // In production modes, use /_vendor/{filename} URL for cached assets
            if (!empty($asset['cached_filename'])) {
                $url = '/_vendor/' . $asset['cached_filename'];
                $html[] = '<link rel="stylesheet" href="' . htmlspecialchars($url) . '">';
            } else {
                // Development mode: use CDN URL directly. Declare the origin so the CSP
                // whitelists exactly what this page actually fetches externally.
                Rsx_Csp::note_asset_origin('style-src', $asset['url']);

                $tag = '<link rel="stylesheet" href="' . htmlspecialchars($asset['url']) . '"';
                if (!empty($asset['integrity'])) {
                    $tag .= ' integrity="' . htmlspecialchars($asset['integrity']) . '"';
                    $tag .= ' crossorigin="anonymous"';
                }
                $tag .= '>';
                $html[] = $tag;
            }
        }

        // Add public directory CSS (with filemtime cache-busting)
        $public_css = $compiled['public_css'] ?? [];
        foreach ($public_css as $asset) {
            $mtime = file_exists($asset['full_path']) ? filemtime($asset['full_path']) : time();
            $html[] = '<link rel="stylesheet" href="' . htmlspecialchars($asset['url']) . '?v=' . $mtime . '">';
        }

        // Add JS: jQuery first, then others
        foreach (array_merge($jquery_js, $other_js) as $asset) {
            // In production modes, use /_vendor/{filename} URL for cached assets
            if (!empty($asset['cached_filename'])) {
                $url = '/_vendor/' . $asset['cached_filename'];
                $html[] = '<script src="' . htmlspecialchars($url) . '" defer></script>';
            } else {
                // Development mode: use CDN URL directly. Declare the origin so the CSP
                // whitelists exactly what this page actually fetches externally.
                Rsx_Csp::note_asset_origin('script-src', $asset['url']);

                $tag = '<script src="' . htmlspecialchars($asset['url']) . '" defer';
                if (!empty($asset['integrity'])) {
                    $tag .= ' integrity="' . htmlspecialchars($asset['integrity']) . '"';
                    $tag .= ' crossorigin="anonymous"';
                }
                $tag .= '></script>';
                $html[] = $tag;
            }
        }

        // Add public directory JS (with filemtime cache-busting and defer)
        $public_js = $compiled['public_js'] ?? [];
        foreach ($public_js as $asset) {
            $mtime = file_exists($asset['full_path']) ? filemtime($asset['full_path']) : time();
            $html[] = '<script src="' . htmlspecialchars($asset['url']) . '?v=' . $mtime . '" defer></script>';
        }

        // Add CSS bundles
        // In development mode with split bundles, add vendor then app
        if (!empty($compiled['vendor_css_bundle_path']) || !empty($compiled['app_css_bundle_path'])) {
            // Split bundles mode
            if (!empty($compiled['vendor_css_bundle_path'])) {
                $vendor_url = static::__get_bundle_url($compiled['vendor_css_bundle_path']);
                $html[] = '<link rel="stylesheet" href="' . htmlspecialchars($vendor_url) . '">';
            }
            if (!empty($compiled['app_css_bundle_path'])) {
                $app_url = static::__get_bundle_url($compiled['app_css_bundle_path']);
                $html[] = '<link rel="stylesheet" href="' . htmlspecialchars($app_url) . '">';
            }
        } elseif (!empty($compiled['css_bundle_path'])) {
            // Single bundle mode (production)
            $url = static::__get_bundle_url($compiled['css_bundle_path']);
            $html[] = '<link rel="stylesheet" href="' . htmlspecialchars($url) . '">';
        }

        // Add JS bundles
        // In development mode with split bundles, add vendor then app
        if (!empty($compiled['vendor_js_bundle_path']) || !empty($compiled['app_js_bundle_path'])) {
            // Split bundles mode - vendor MUST load before app
            if (!empty($compiled['vendor_js_bundle_path'])) {
                $vendor_url = static::__get_bundle_url($compiled['vendor_js_bundle_path']);
                $html[] = '<script src="' . htmlspecialchars($vendor_url) . '" defer></script>';
            }
            if (!empty($compiled['app_js_bundle_path'])) {
                $app_url = static::__get_bundle_url($compiled['app_js_bundle_path']);
                $html[] = '<script src="' . htmlspecialchars($app_url) . '" defer></script>';
            }
        } elseif (!empty($compiled['js_bundle_path'])) {
            // Single bundle mode (production)
            $url = static::__get_bundle_url($compiled['js_bundle_path']);
            $html[] = '<script src="' . htmlspecialchars($url) . '" defer></script>';
        }

        // Inline JS removed - bundles handle their own initialization

        return implode("\n", $html);
    }

    /**
     * Get URL for a bundle file
     *
     * @param string $path Bundle file path
     * @return string Bundle URL
     */
    protected static function __get_bundle_url(string $path): string
    {
        // Extract filename from path
        $filename = basename($path);

        // Validate it's a compiled bundle file
        // Format: BundleName__vendor.[hash].js/css or BundleName__app.[hash].js/css
        if (preg_match('/^\w+__(vendor|app)\.[a-f0-9]{8}\.(js|css)$/', $filename)) {
            // Use the controlled route for compiled assets
            $url = '/_compiled/' . $filename;

            // In development mode, add ?v= parameter for cache-busting
            if (env('APP_ENV') !== 'production') {
                $manifest_hash = Manifest::get_build_key();
                $url .= '?v=' . $manifest_hash;
            }

            return $url;
        }

        // This shouldn't happen with properly compiled bundles
        throw new RuntimeException("Invalid bundle file path: {$path}");
    }

    /**
     * Resolve bundle class name from alias
     *
     * @param string $bundle Bundle class name or alias
     * @return string Fully qualified bundle class name
     */
    protected static function __resolve_bundle_class(string $bundle): string
    {
        // First try to find by exact class name in Manifest
        try {
            $metadata = Manifest::php_get_metadata_by_class($bundle);

            return $metadata['fqcn'];
        } catch (RuntimeException $e) {
            // Not found by simple name
        }

        // Try with common namespace prefixes
        $possible_names = [
            $bundle,
            "App\\Bundles\\{$bundle}",
            "Rsx\\Bundles\\{$bundle}",
        ];

        foreach ($possible_names as $name) {
            try {
                $metadata = Manifest::php_get_metadata_by_fqcn($name);

                return $metadata['fqcn'];
            } catch (RuntimeException $e) {
                // Try next
            }
        }

        // Search manifest for any class ending with the bundle name that extends Rsx_Bundle_Abstract
        $manifest_data = Manifest::get_all();
        foreach ($manifest_data as $file_info) {
            $class_name = $file_info['class'] ?? null;
            if ($class_name &&
                str_ends_with($class_name, $bundle) &&
                Manifest::php_is_subclass_of($class_name, 'Rsx_Bundle_Abstract')) {
                return $file_info['fqcn'];
            }
        }

        throw new RuntimeException("Bundle class not found: {$bundle}");
    }

    /**
     * Validate that the bundle's include paths cover the calling view/layout and controller
     *
     * @param string $bundle_class Fully qualified bundle class name
     * @throws RuntimeException if bundle doesn't cover the calling file's directory or current controller
     */
    protected static function __validate_path_coverage(string $bundle_class): void
    {
        // Try to get the view path from shared data (set by rsx_view helper)
        $view = \Illuminate\Support\Facades\View::shared('rsx_current_view_path', null);

        if (!$view) {
            // View path required for bundle validation
            BundleErrors::cannot_determine_view_path();
        }

        // Normalize the view path
        $view_path = str_replace('\\', '/', $view);
        if (!str_starts_with($view_path, '/')) {
            // Convert to absolute path if needed
            if (file_exists(base_path($view_path))) {
                $view_path = base_path($view_path);
            }
        }

        // Convert absolute path to relative from base_path
        $base_path = str_replace('\\', '/', base_path());
        if (str_starts_with($view_path, $base_path)) {
            $view_path = substr($view_path, strlen($base_path) + 1);
        }

        // Get the bundle's definition to check include paths
        $definition = static::get_resolved_definition($bundle_class);
        $include_paths = $definition['include'] ?? [];

        // If no include paths defined, skip validation (bundle might use explicit files)
        if (empty($include_paths)) {
            return;
        }

        // Convert any absolute paths in include_paths to relative
        $base_path = str_replace('\\', '/', base_path());

        // Determine project root (parent of system/ if we're in a subdirectory structure)
        $project_root = $base_path;
        if (basename($base_path) === 'system') {
            $project_root = dirname($base_path);
        }

        foreach ($include_paths as &$include_path) {
            $include_path = str_replace('\\', '/', $include_path);
            if (str_starts_with($include_path, '/')) {
                // If it starts with base_path, strip it
                if (str_starts_with($include_path, $base_path)) {
                    $include_path = substr($include_path, strlen($base_path) + 1);
                } elseif (str_starts_with($include_path, $project_root)) {
                    // Path is under project root but not under base_path (e.g., symlinked rsx/)
                    $include_path = substr($include_path, strlen($project_root) + 1);
                } else {
                    // If it's an absolute path not under project root, try removing leading slash
                    $include_path = ltrim($include_path, '/');
                }
            }
        }
        unset($include_path); // Clear reference

        // Check if any include path covers the view's directory
        $view_dir = dirname($view_path);
        $is_covered = false;

        foreach ($include_paths as $include_path) {
            // Normalize path for comparison
            $include_path = rtrim($include_path, '/');

            // Check if the include path is a file that matches the view
            if (str_ends_with($include_path, '.php') || str_ends_with($include_path, '.scss') ||
                str_ends_with($include_path, '.css') || str_ends_with($include_path, '.js')) {
                // For files, check if it matches the view file itself
                if ($view_path === $include_path) {
                    $is_covered = true;
                    break;
                }
                continue;
            }

            // For directories, check if view directory starts with or equals the include path
            if ($view_dir === $include_path || str_starts_with($view_dir, $include_path . '/')) {
                $is_covered = true;
                break;
            }
        }

        // Throw error if not covered (skip for framework views in app/RSpade/)
        if (!$is_covered && !str_starts_with($view_path, 'app/RSpade/')) {
            // Bundle doesn't include the view directory
            BundleErrors::view_not_covered($view_path, $view_dir, $bundle_class, $include_paths);
        }

        // Check if current controller (if set during route dispatch) is covered by bundle
        $current_controller = \App\RSpade\Core\Rsx::get_current_controller();
        $current_action = \App\RSpade\Core\Rsx::get_current_action();

        // Only validate if we're in a route dispatch context (controller and action are set)
        if ($current_controller && $current_action) {
            // Look up the controller file in the manifest
            try {
                $controller_metadata = Manifest::php_get_metadata_by_class($current_controller);
                $controller_file = $controller_metadata['file'] ?? null;

                if ($controller_file) {
                    // Normalize controller path
                    $controller_path = str_replace('\\', '/', $controller_file);
                    if (str_starts_with($controller_path, $base_path)) {
                        $controller_path = substr($controller_path, strlen($base_path) + 1);
                    }

                    // Check if controller is covered by any include path
                    $controller_dir = dirname($controller_path);
                    $controller_covered = false;

                    foreach ($include_paths as $include_path) {
                        $include_path = rtrim($include_path, '/');

                        // Check if include path is a file matching the controller
                        if (str_ends_with($include_path, '.php')) {
                            if ($controller_path === $include_path) {
                                $controller_covered = true;
                                break;
                            }
                            continue;
                        }

                        // Check if controller directory is within include path
                        if ($controller_dir === $include_path || str_starts_with($controller_dir, $include_path . '/')) {
                            $controller_covered = true;
                            break;
                        }
                    }

                    // Throw error if controller not covered
                    if (!$controller_covered) {
                        // Bundle doesn't include the controller
                        BundleErrors::controller_not_covered(
                            $current_controller,
                            $current_action,
                            $controller_path,
                            $controller_dir,
                            $bundle_class,
                            $include_paths
                        );
                    }
                }
            } catch (RuntimeException $e) {
                // If we can't find the controller in manifest, skip validation
                // (might be a Laravel controller or other edge case)
                if (!str_contains($e->getMessage(), 'not found in manifest')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Get bundle metadata for manifest
     *
     * @return array Bundle metadata
     */
    public static function get_metadata(): array
    {
        return [
            'type' => 'bundle',
            'class' => static::class,
            'definition' => static::define(),
        ];
    }

    /**
     * Recursively filter out keys starting with single underscore
     * but allow keys starting with double underscore (like __MODEL)
     *
     * @param mixed $data Data to filter
     * @return mixed Filtered data
     */
    private static function __filter_underscore_keys($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $filtered = [];
        foreach ($data as $key => $value) {
            // Skip keys that start with single underscore (but not double underscore)
            if (is_string($key) && str_starts_with($key, '_') && !str_starts_with($key, '__')) {
                continue;
            }

            // Recursively filter nested arrays/objects
            if (is_array($value)) {
                $filtered[$key] = static::__filter_underscore_keys($value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Build the window.rsxapp.portal block for client-side Portal_Permission.
     *
     * Shape: { is_auth, user, client_roles }, where client_roles maps accessible
     * client_id => role_id. This is consumed by Portal_Permission.js to hide UI
     * affordances ONLY - the server is the enforcement boundary.
     *
     * The accessible-client roles are app data (memberships). They are resolved
     * through the application's Portal_Permission facade, located via the manifest
     * autoloader map (the framework's path-agnostic resolution) so the framework
     * core carries no hard-coded dependency on the app class. When the app has not
     * defined a Portal_Permission, the block degrades to identity-only.
     *
     * @return array
     */
    private static function _build_portal_permission_block(): array
    {
        $block = [
            'is_auth' => Portal_Session::is_logged_in(),
            'user' => Portal_Session::get_portal_user(),
            'client_roles' => [],
            'is_impersonating' => Portal_Session::is_impersonating(),
            'impersonator_user_id' => Portal_Session::get_impersonator_user_id(),
            // How the portal is addressed. Rsx_Portal.js derives BOTH portal route
            // URLs and internal-endpoint URLs (Rsx_Portal.internal_url) from these,
            // so the client must read the server's actual configuration rather than
            // assume prefix mode.
            'domain' => Rsx_Portal::get_domain(),
            'prefix' => Rsx_Portal::get_prefix(),
        ];

        if (!$block['is_auth']) {
            return $block;
        }

        $portal_permission_fqcn = null;
        try {
            $portal_permission_fqcn = Manifest::php_get_metadata_by_class('Portal_Permission')['fqcn'] ?? null;
        } catch (\Throwable $e) {
            // App has no Portal_Permission - degrade to identity-only.
            $portal_permission_fqcn = null;
        }

        if ($portal_permission_fqcn && method_exists($portal_permission_fqcn, 'accessible_client_ids')) {
            foreach ($portal_permission_fqcn::accessible_client_ids() as $client_id) {
                $block['client_roles'][(string) $client_id] = $portal_permission_fqcn::client_role((int) $client_id);
            }
        }

        return $block;
    }
}
