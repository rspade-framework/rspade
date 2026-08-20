<?php

/**
 * RSX Framework Configuration
 *
 * Framework defaults and core operational settings. User customizations belong
 * in /rsx/resource/config/rsx.php which is merged with this file.
 *
 * For extended documentation: php artisan rsx:man config_rsx
 *
 * ---
 *
 * MOVED TO USER CONFIG (/rsx/resource/config/rsx.php):
 *
 * The following configuration keys were moved from this file to user config
 * because they represent user preferences rather than framework requirements:
 *
 * - development.auto_rename_files - IDE preference for file auto-renaming
 *   Reason: VS Code extension user preference
 *
 * - development.ignore_filename_convention - Disable filename checks globally
 *   Reason: Developer choice for convention enforcement
 *
 * - manifest.excluded_dirs - Additional directories to exclude from scanning
 *   Reason: Users may want custom exclusions (framework exclusions are hardcoded)
 *
 * - code_quality.generic_suffix_replacements - Required suffix specificity rules
 *   Reason: Users define their own naming patterns (Handler, Service, etc.)
 *
 * - console_debug.outputs - Debug output destinations (cli, web, ajax, laravel_log)
 *   Reason: User preference for debug visibility
 *
 * - console_debug.filter_mode - Filter mode (all, whitelist, blacklist, specific)
 *   Reason: User debugging workflow preference
 *
 * - console_debug.specific_channel - Specific channel to show
 *   Reason: User debugging focus
 *
 * - console_debug.whitelist - Channels to show in whitelist mode
 *   Reason: Users add custom application debug channels
 *
 * - console_debug.blacklist - Channels to hide in blacklist mode
 *   Reason: User preference for hiding noisy channels
 *
 * - console_debug.include_benchmark - Include timing in debug output
 *   Reason: User preference for performance analysis
 *
 * - console_debug.include_location - Include file/line in debug output
 *   Reason: User debugging preference
 *
 * - console_debug.include_backtrace - Include call stack in debug output
 *   Reason: User debugging preference
 *
 * - console_debug.enable_get_trace - Enable ?__trace=1 for plain text output
 *   Reason: User debugging preference
 *
 * - log_browser_errors - Log JavaScript errors to Laravel log
 *   Reason: User preference for client-side error tracking
 *
 * - csp.report_only - Observe violations vs enforce the policy
 *   Reason: Rollout decision - an app flips this once its violation log is clean
 *
 * - csp.additional_sources - Extra CSP sources for TRANSITIVE externals
 *   Reason: Application-specific; a script whose own further loads only the app knows
 *
 * - csp.enabled - Emit a policy at all
 *   Reason: Application-specific escape hatch; the framework default is on
 *
 * - response.default_view - Default view when controller doesn't specify
 *   Reason: Application-specific default
 *
 * - response.error_views - Custom error view templates (404, 500)
 *   Reason: Application-specific error pages
 *
 * - response.cors - CORS settings for API responses
 *   Reason: Application-specific API configuration
 *
 * REMOVED (DEAD CODE):
 *
 * - middleware - RSX middleware configuration (UNUSED - no code references)
 * - response.asset_cache - Asset caching settings (UNUSED - no code references)
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Application Mode
    |--------------------------------------------------------------------------
    |
    | RSX_MODE is the authoritative source of truth for application mode.
    | Valid values: development, debug, production
    |
    | - development: Auto-rebuild, full debugging, sourcemaps
    | - debug: Production optimizations with sourcemaps for debugging
    | - production: Full optimization, minification, merging, CDN bundling
    |
    | See: php artisan rsx:man app_mode
    |
    */

    'mode' => env('RSX_MODE', 'development'),

    /*
    |--------------------------------------------------------------------------
    | External API
    |--------------------------------------------------------------------------
    |
    | Settings for the external REST API (#[Api_Endpoint] controllers).
    |
    | - log_retention_days: how many days of _api_request_log rows to keep. Consumed by
    |   Api_Cleanup_Service::cleanup_request_log (daily 3am, #[Exclusive]); older rows pruned.
    |
    */

    'api' => [
        'log_retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Manifest Modules
    |--------------------------------------------------------------------------
    |
    | These modules process files during manifest scanning. Each module
    | handles specific file types and extracts metadata. Modules are
    | processed in priority order (lower number = higher priority).
    |
    */

    'manifest_modules' => [
        // Core modules (can be copied and modified)
        // PHP extraction is handled directly in Manifest to avoid DRY violations
        // JavaScript is a first-class citizen, handled directly in Manifest.php
        \App\RSpade\Core\Manifest\Modules\Blade_ManifestModule::class,
        \App\RSpade\Integrations\Scss\Scss_ManifestModule::class,

        // Custom modules
        // \App\RSpade\Modules\Custom\MyCustomModule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Manifest Support Modules
    |--------------------------------------------------------------------------
    |
    | These modules run AFTER the primary manifest is built to add
    | supplementary metadata that requires the full manifest to be available.
    | Order matters - modules are processed in the order listed.
    |
    */

    'manifest_support' => [
        \App\RSpade\Core\Dispatch\Route_ManifestSupport::class,
        \App\RSpade\Core\Portal\Portal_Route_ManifestSupport::class,
        \App\RSpade\Core\Portal\Portal_Spa_ManifestSupport::class,
        \App\RSpade\Core\Manifest\Modules\Model_ManifestSupport::class,
        \App\RSpade\Integrations\Jqhtml\Jqhtml_ManifestSupport::class,
        \App\RSpade\Core\SPA\Spa_ManifestSupport::class,
        \App\RSpade\Core\Api\Api_Endpoint_ManifestSupport::class,
        \App\RSpade\Core\Externals\Externals_ManifestSupport::class,
        // Auth gates run LAST: the consolidated #[Auth] index covers every surface
        // kind the modules above register.
        \App\RSpade\Core\Auth\Auth_ManifestSupport::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    |
    | External integrations that extend the framework's capabilities.
    | Each integration can provide file discovery, processing, and bundling.
    |
    */

    'integrations' => [
        // Register integration service providers here
        // These will be loaded automatically if enabled
        'providers' => [
            \App\RSpade\Integrations\Jqhtml\Jqhtml_Service_Provider::class,
            \App\RSpade\Core\Controller\Controller_Service_Provider::class,
            \App\RSpade\Core\Database\Database_Service_Provider::class,
            \App\RSpade\Core\Auth\Auth_Service_Provider::class,
        ],

        // Integration-specific configuration
        'jqhtml' => [
            'compiler' => [
                'cache' => true,
                'cache_ttl' => 3600,
                'source_maps' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bundle Processors
    |--------------------------------------------------------------------------
    |
    | Configure global processors that transform files during bundle compilation.
    | These processors are automatically applied to all bundles based on file
    | extensions. Order matters - processors with lower priority run first.
    |
    */

    'bundle_processors' => [
        // SCSS/Sass processor
        \App\RSpade\Integrations\Scss\Scss_BundleProcessor::class,

        // JQHTML processor
        \App\RSpade\Integrations\Jqhtml\Jqhtml_BundleProcessor::class,

        // Add custom processors here
        // \App\RSpade\Processors\MyCustomProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Required Bundles
    |--------------------------------------------------------------------------
    |
    | These bundles are automatically prepended to every bundle's include list.
    | They provide core functionality that all bundles need.
    |
    */
    'required_bundles' => [
        'jquery',   // jQuery library - foundation for many components
        'lodash',   // Lodash utility library - common utilities
        'core',     // Core framework JS - Manifest, Rsx, cache, etc.
        'jqhtml',   // Jqhtml library - client side templating library
    ],

    /*
    |--------------------------------------------------------------------------
    | Bundle Aliases
    |--------------------------------------------------------------------------
    |
    | Map bundle aliases to their corresponding bundle classes.
    | These aliases can be used in bundle 'include' arrays for convenience.
    |
    */
    'bundle_aliases' => [
        'core' => \App\RSpade\Core\Bundle\Core_Bundle::class,
        'jquery' => \App\RSpade\Bundles\Jquery_Bundle::class,
        'lodash' => \App\RSpade\Bundles\Lodash_Bundle::class,
        'bootstrap5' => \App\RSpade\Bundles\Bootstrap5_Bundle::class,
        'jqhtml' => \App\RSpade\Integrations\Jqhtml\Jqhtml_Bundle::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | RSX Routing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how RSX handles routing, caching, and request dispatch.
    |
    */
    'routing' => [
        // Directories to check for static assets
        'asset_dirs' => ['public', 'assets', 'static', 'dist', 'build'],

        // Handler type priorities (lower number = higher priority)
        'handler_priority' => [
            'controller' => 1,
            'file' => 2,
            'asset' => 3,
            'custom' => 4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Middleware (application additions)
    |--------------------------------------------------------------------------
    |
    | app/Http/Kernel.php is a FRAMEWORK-OWNED file - it is hard-synced by every
    | framework update and a local edit is refused by the tamper gate. This block
    | is the sanctioned seam for an application's own HTTP middleware.
    |
    | Shape:
    |   'global'  => [My_Middleware::class]          appended to the global stack
    |   'web'     => [...]                           appended to a middleware GROUP
    |   'api'     => [...]                           (any group the kernel declares)
    |   'aliases' => ['my_alias' => Class::class]    route-middleware aliases
    |
    | APPEND-ONLY. Declared middleware runs AFTER the framework stack; nothing here
    | can reorder or remove framework middleware. If you genuinely need that, file a
    | framework change request - do not edit the kernel.
    |
    | Validation is loud: an unknown group key, a class that does not exist, and an
    | alias already bound to a different class each throw at bootstrap. Declaring
    | something already present is a silent no-op.
    |
    | The framework itself declares none; the block ships empty for apps to extend.
    |
    | See: php artisan rsx:man config_rsx
    |
    */
    'middleware' => [
        'global' => [],
        'web' => [],
        'api' => [],
        'aliases' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Login Redirect (intended-URL threading)
    |--------------------------------------------------------------------------
    |
    | Login_Redirect threads a validated ?redirect= target through a multi-hop
    | login flow so a user who hit a protected deep URL while logged out lands
    | back on it after authenticating. excluded_prefixes are login-flow routes
    | that a redirect target may never point at (loop prevention) - a redirect
    | to any of these is treated as absent. Apps merge their own login-flow
    | route prefixes here (2FA, site selection, etc.).
    |
    | portal_excluded_prefixes is the same loop-prevention list for the client
    | portal's login flow, applied when the request is a portal request. It is
    | expressed in PORTAL-NAMESPACE terms (unprefixed): in prefix mode the portal
    | prefix is stripped before the comparison, in domain mode it matches
    | directly. The default covers the framework's shipped portal auth routes;
    | apps add their own portal login-flow routes here.
    |
    | See: php artisan rsx:man login_redirect
    |
    */
    'login_redirect' => [
        'excluded_prefixes' => ['/login', '/logout'],

        'portal_excluded_prefixes' => [
            '/login', '/logout', '/register', '/password/reset', '/request-access', '/impersonate',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Portal
    |--------------------------------------------------------------------------
    |
    | Framework defaults for the client portal - a second authenticated
    | experience for external users (customers, clients, vendors) served by
    | Portal_Dispatcher / Portal_Session, parallel to the main app.
    |
    | An application merges its own overrides on top of these (see
    | rsx/resource/config/rsx.php), e.g. to wire PORTAL_DOMAIN from .env or add
    | app-specific knobs. These defaults let the portal subsystem run sanely on
    | their own.
    |
    | URL strategy:
    | - Set 'domain' to serve the portal from a dedicated host (production).
    | - Leave 'domain' null to serve it under the 'prefix' path (development).
    |
    */
    'portal' => [
        // Dedicated portal host (e.g. 'portal.example.com'); null = use prefix.
        'domain' => null,

        // URL prefix used when no dedicated domain is configured.
        'prefix' => '/_portal',

        // Portal session lifetime, in days.
        'session_lifetime_days' => 30,

        // NOTE: there is deliberately NO portal site key here. Which site a portal
        // request serves is an APPLICATION fact, declared at runtime with
        // Portal_Session::set_site_id() (see rsx:man portal). A mono-site app reads
        // its own config key for it; a multi-tenant app looks it up from the host.
        // The framework does not guess, and does not ship a default that would.

        // How long a portal invitation code stays valid, in days.
        'invitation_expiry_days' => 14,

        // Minimum length enforced for portal user passwords.
        'password_min_length' => 8,

        // How long a portal password-reset token stays valid, in hours.
        'password_reset_expiry_hours' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Directory Configuration
    |--------------------------------------------------------------------------
    |
    | Configure security and access control for the /rsx/public/ directory.
    | Files in this directory are served at the root URL of the application.
    |
    */
    'public' => [
        // Global patterns blocked from HTTP access for security
        // These patterns are always blocked regardless of public_ignore.json
        'ignore_patterns' => [
            'public_ignore.json',
            '.git',
            '.gitignore',
            '.gitattributes',
            '*.php',
            '.env',
            '.env.*',
            '*.sh',
            '*.py',
            '*.rb',
            '*.conf',
            '*.ini',
            '*.sql',
            '*.bak',
            '*.tmp',
            '*~',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | RSX Manifest Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how RSX discovers and indexes classes, routes, and attributes.
    |
    */
    'manifest' => [
        // Base directories to scan (relative to base_path())
        // Can be directories (will be scanned recursively) or individual files
        'scan_directories' => [
            'rsx',                         // Main RSX application directory (symlinked to ../rsx)
            'app/RSpade/Core',             // Core framework classes (runtime essentials)
            'app/RSpade/Integrations',     // Integration modules (Jqhtml, Scss, etc.)
            'app/RSpade/Bundles',          // Third-party bundles
            'app/RSpade/Breadcrumbs',      // Progressive breadcrumb resolution
            'app/RSpade/CodeQuality',      // Code quality rules and checks
            'app/RSpade/Lib',              // UI features (Flash alerts, etc.)
            'app/RSpade/temp',             // Framework developer testing directory
            'app/RSpade/tests',            // Framework tests (php/cli/asset dirs are PHP; see excluded_dirs)
        ],

        // Specific filenames to exclude from manifest scanning (anywhere in tree)
        'excluded_files' => [
            'CLAUDE.md',      // Documentation for AI assistants
            '.placeholder',   // Empty directory markers
            '.DS_Store',      // macOS folder metadata
            'Thumbs.db',      // Windows thumbnail cache
            'desktop.ini',    // Windows folder settings
            '.gitkeep',       // Git empty directory markers
            '.gitattributes', // Git attributes config
            '._rsx_helper.php', // IDE helper stubs (auto-generated)
        ],

        // Directories to exclude from code quality checks (relative path segments)
        // These are matched via str_contains, so 'Core/Manifest' matches 'app/RSpade/Core/Manifest/'
        'excluded_dirs' => [
            'vendor',
            'node_modules',
            'storage',
            '.git',
            'public',
            'resource',
            'Core/Manifest',  // Manifest builder uses reflection - can't use Manifest API
            'playwright',      // Test type dir: browser tests (.js) - not PHP, never indexed
            '_archive',        // Retired tests parked for reference - not discovered/run
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | RSX Development Settings
    |--------------------------------------------------------------------------
    |
    | Diagnostic flags for debugging. Override in user config for preferences.
    |
    */
    'development' => [
        // Show detailed error messages
        'debug' => env('RSX_DEBUG', env('APP_DEBUG', false)),

        // Log all dispatches for debugging
        'log_dispatches' => env('RSX_LOG_DISPATCHES', false),

        // Show route matching details in error pages
        'show_route_details' => env('RSX_SHOW_ROUTE_DETAILS', env('APP_DEBUG', false)),

        // The hostname suffix that marks a DEBUG SITE - a host you control and
        // where developer conveniences (login credential auto-fill) are allowed.
        // Rsx::is_debug_site() is true when the browsed host equals this value or
        // ends with '.' . this value.
        //
        // EMPTY IS THE SECURE DEFAULT AND THE SHIPPED ONE: with no suffix declared
        // NO host is ever a debug site, so a public install can never auto-fill a
        // credential no matter what else is configured. Declare it only for hosts
        // you own (RSPADE_DEBUG_DOMAIN_SUFFIX in .env) - never a public hostname.
        'debug_domain_suffix' => env('RSPADE_DEBUG_DOMAIN_SUFFIX', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Credentials
    |--------------------------------------------------------------------------
    |
    | The credentials of the first user, created by the framework migration
    | create_admin_test_user, and the pair a debug site may auto-fill into its
    | login form.
    |
    | BOTH ARE EMPTY BY DEFAULT AND MUST BE SET IN .env. They are deliberately
    | not given fallback values: a framework with a known default password is a
    | framework where every install shares one. The migration THROWS when either
    | is blank rather than inventing one, so a new project makes a deliberate
    | choice before it has a login at all. See .env.README.
    |
    | Auto-fill additionally requires the request host to match
    | rsx.development.debug_domain_suffix - credentials existing is not enough.
    |
    */

    'default_user' => [
        'email' => env('RSPADE_DEFAULT_EMAIL', ''),
        'password' => env('RSPADE_DEFAULT_PASSWORD', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Code Quality Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for code quality checks and validation rules.
    |
    */
    'code_quality' => [
        // Framework developer mode - enables additional testing capabilities
        // When true, allows testing rules in app/RSpade/temp directory
        'is_framework_developer' => env('IS_FRAMEWORK_DEVELOPER', false),

        // Whitelisted PHP/JS files allowed in project root directory
        // These are typically build configuration files and IDE helpers
        'root_whitelist' => [
            'vite.config.js',
            'webpack.config.js',
            'webpack.mix.js',
            '_ide_helper.php',          // Laravel IDE Helper
            '._rsx_helper.php',         // RSX IDE Helper
            '.phpstorm.meta.php',       // PhpStorm metadata
        ],

        // Whitelisted test files allowed in rsx/ directory (not subdirectories)
        // Test files should normally be in proper test directories, not loose in rsx/
        'rsx_test_whitelist' => [
            // Currently no test files should exist directly in rsx/
        ],

        // Classes exempt from suffix inheritance rules
        // Children of these classes can use any naming pattern
        'suffix_exempt_classes' => [
            'Component',            // JQHTML components can have flexible naming
            'Rsx_System_Model_Abstract',   // System models (e.g., Session) have special naming
            // The RPC lifecycle base: its children's identities are their JOBS
            // (Js_Parser, Minifier, FileSanitizer, ...) - being an RPC client is
            // an implementation detail, not what the class IS. Renaming six
            // widely-referenced core classes to *_Client would trade real churn
            // for a suffix.
            'Rpc_Client_Abstract',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IDE Integration Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for VS Code extension and IDE bridge communication.
    |
    */
    'ide_integration' => [
        // Path where the IDE bridge local-file grant token + passive guards live.
        // This directory is OUTSIDE the web docroot and must stay that way. The IDE now
        // reads the server URL straight from APP_URL in .env (no domain.txt discovery).
        'bridge_path' => 'storage/rsx-ide-bridge',

        // Master switch for the IDE bridge (dev-only by default; hard-off in production
        // unless explicitly opted in). Governs whether the grant token is created. The
        // pre-boot auth gate (auth.php) also refuses the bridge outright when
        // RSX_IDE_SERVICES_ENABLED=false, and in production without =true.
        'enabled' => env('RSX_IDE_SERVICES_ENABLED', env('APP_ENV', 'local') !== 'production'),
    ],

    /*
    |--------------------------------------------------------------------------
    | JavaScript Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for JavaScript parsing and transformation.
    |
    */
    'javascript' => [
        'babel' => [
            // Enable Babel transformation for decorators
            'transform_enabled' => env('BABEL_TRANSFORM', true),

            // Target environment for transformation (modern, es6, es5)
            'target' => env('BABEL_TARGET', 'modern'),

            // Cache directory for transformed files
            'cache_dir' => 'storage/rsx-tmp/babel_cache',
        ],

        // Enable decorator support (parsed and optionally transformed)
        'decorators' => true,

        // Note: Private fields (#private) use native browser support, not transpiled

        // How long to wait for a spawned node RPC helper to answer its first ping.
        //
        // This bounds a wait on an EXTERNAL party (a process that may never come up)
        // and degrades to a loud, evidence-carrying error - the one shape of deadline
        // the no-timeout mandate sanctions. It is NOT a cap on the helper's work: once
        // it answers, every RPC call runs to completion with no deadline at all.
        //
        // 10s is generous for a cold node start and was the hardcoded value in all six
        // helpers. It is a key rather than a literal because a loaded box legitimately
        // takes longer - a downstream update failed here mid-backup (2026-08-11) - and
        // the honest fix for a slow machine is a bigger number, not a retry storm.
        'rpc_server_ready_wait_ms' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Console Debug Configuration
    |--------------------------------------------------------------------------
    |
    | Core debug system settings. Override outputs and filters in user config.
    |
    | Per-mode behavior (RSX_MODE):
    | - development: config block injected into window.rsxapp; console_debug()
    |   works in the browser (JS) and PHP; nothing stripped from bundles.
    | - debug: SAME as development - the config block is injected and
    |   console_debug() works (Manifest::_should_include_debug_info() is true for
    |   both). Debug builds are minified but keep console_debug intact.
    | - production (strict): config block is NOT injected, the PHP gate returns
    |   early, and every console_debug() call site is stripped from the compiled
    |   bundle by Terser pure_funcs (Manifest::_should_strip_console_debug()).
    |
    | Channels are the taxonomy (uppercased tags like DISPATCH, AUTH, DB). Level
    | is inferred from the channel-name substring (ERROR/WARN/INFO). The sane
    | defaults below - enabled, filter_mode 'all' - mean every channel is emitted
    | wherever console_debug is active; narrow via filter_mode + whitelist/blacklist.
    |
    */
    'console_debug' => [
        // Master switch to enable/disable all console debug output
        'enabled' => env('CONSOLE_DEBUG_ENABLED', true),

        // Default filter: emit every channel. Set to whitelist/blacklist/specific
        // (with the matching whitelist/blacklist/specific_channel key) to narrow.
        'filter_mode' => 'all',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception Handlers
    |--------------------------------------------------------------------------
    |
    | Exception handlers are executed in priority order when exceptions occur.
    | Each handler can choose to handle the exception (return a response) or
    | pass it to the next handler (return null). Handlers are sorted by priority
    | with lower numbers running first.
    |
    | Handler Priority Ranges:
    | - 1-50: Critical/environment-specific (CLI, AJAX, Playwright)
    | - 51-100: Standard handlers
    | - 101-500: Low priority handlers
    | - 501+: Fallback* or catch-all handlers (RSX dispatch bootstrapper)
    |
    | Users can add custom handlers or reorder existing ones by modifying this array.
    |
    */
    'exception_handlers' => [
        \App\RSpade\Core\Exceptions\Cli_Exception_Handler::class,              // Priority 10
        \App\RSpade\Core\Exceptions\Ajax_Exception_Handler::class,             // Priority 20
        \App\RSpade\Core\Api\Api_Exception_Handler::class,                     // Priority 25
        \App\RSpade\Core\Debug\Playwright_Exception_Handler::class,            // Priority 30
        \App\RSpade\Core\Providers\Rsx_Dispatch_Bootstrapper_Handler::class,   // Priority 1000
        \App\RSpade\Core\Exceptions\Web_Exception_Handler::class,              // Priority 1100
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnail Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the two-tier thumbnail caching system with named presets
    | (developer-defined) and dynamic thumbnails (ad-hoc sizes).
    |
    | Storage Structure:
    | - storage/rsx-thumbnails/preset/  - Named preset thumbnails
    | - storage/rsx-thumbnails/dynamic/ - Dynamic ad-hoc thumbnails
    |
    | Quota Management:
    | - Preset thumbnails: Enforced via scheduled rsx:thumbnails:clean task
    | - Dynamic thumbnails: Enforced synchronously after each new thumbnail
    | - Both use LRU eviction (oldest mtime deleted first)
    |
    | LRU Tracking:
    | - Both preset and dynamic thumbnails have mtime touched on cache hit
    | - Touch only occurs if mtime is older than touch_interval (default 10 min)
    | - Prevents excessive filesystem writes while maintaining LRU accuracy
    |
    | Commands:
    | - php artisan rsx:thumbnails:clean [--preset] [--dynamic]
    | - php artisan rsx:thumbnails:generate [--preset=name]
    | - php artisan rsx:thumbnails:stats
    |
    */

    /*
    |--------------------------------------------------------------------------
    | File Attachments
    |--------------------------------------------------------------------------
    |
    | External byte-residency handlers (WP-A). An attachment may carry a
    | handler_class naming where its bytes live (OneDrive, S3, a URL, ...). The
    | framework materializes bytes on demand via the handler's fetch().
    |
    | SECURITY ALLOWLIST: the framework NEVER instantiates a handler class named
    | in the database unless its simple class name appears in this array. An
    | attachment whose handler_class is non-null but unregistered is a hard error
    | (fail loud, do not serve). Apps register their handlers here (overriding
    | this key in rsx/resource/config/rsx.php).
    |
    | Format: array of simple class-name strings, e.g.
    |   'handlers' => ['Onedrive_Attachment_Handler', 'S3_Attachment_Handler'],
    |
    */
    'attachments' => [
        'handlers' => [],

        // How long a minted multi-file ZIP download request (_zip_download_requests) stays
        // valid, in hours. Expiry is enforced at serve time (Zip_Download_Request_Model::
        // is_expired()) AND by the periodic cleanup task (Zip_Download_Cleanup_Service).
        'zip_request_retention_hours' => 24,

        // How long an UNATTACHED upload stays claimable, in hours. A file uploaded to
        // /_upload is reachable only by its 64-char random key and may be attached to a
        // record exactly once (File_Attachment_Model::can_user_assign_this_file()); this
        // window bounds how long that key remains useful. Past it,
        // File_Disposal_Service::sweep_unclaimed_uploads (every 6 hours) SOFT-deletes the
        // orphan, which enters the normal retention window (still recoverable, blob still
        // pinned) rather than being erased. Handler-backed (external) attachments are never
        // swept - they are created attached-or-soon-attached by trusted app code.
        // 0 or null disables the sweep entirely.
        'unattached_claim_window_hours' => 24,

        // When an uploaded image's BYTES cannot be parsed by ImageMagick (a content-parse
        // failure - NOT a missing binary, which always fatals): false (default) = accept and
        // DEGRADE it to a generic, non-previewable file; true = REJECT the upload outright
        // (process_file() throws Unparseable_Upload_Exception for the endpoint to handle).
        'reject_unparseable_images' => false,

        // Developer-callable upload extension allowlist consulted by
        // File_Attachment_Model::is_allowed_extension() (PROVIDE ONLY - the framework does not
        // auto-enforce it at /_upload). Empty array = allow all extensions. Case-insensitive;
        // leading dots optional (e.g. 'png' or '.png').
        'allowed_extensions' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Subsystem Storage Root (INTERNAL - test isolation only)
    |--------------------------------------------------------------------------
    |
    | Absolute path that ROOTS the entire file subsystem - the content-addressed
    | blob store, the thumbnail cache, and the rendition cache - when set. All of
    | those paths resolve through App\RSpade\Core\Files\Rsx_File_Paths, which reads
    | this key.
    |
    | Normal deployments NEVER set this: it defaults to null and Rsx_File_Paths
    | falls back to storage_path(), so default-mode paths are byte-identical to the
    | historic layout. It exists solely so the test runner (Rsx_Test_Command) can
    | point file writes at storage/rsx-tmp/test-storage during a run - otherwise a
    | test-DB attachment delete could unlink a blob shared with the developer
    | database (backlog B-38). Runner-set via config(), not an env var.
    |
    */
    'files' => [
        'storage_root' => null,

        /*
        | Maximum size of a single uploaded file, IN BYTES. Enforced by
        | File_Attachment_Controller::upload() (the /_upload endpoint every attachment goes
        | through), before the file.upload.authorize gate runs.
        |
        | THE DEFAULT IS DERIVED FROM PHP'S OWN LIMITS, not invented. PHP rejects an
        | oversized upload before userland ever sees it, so a framework ceiling ABOVE the ini
        | cannot be enforced - it only moves the rejection somewhere with a worse message.
        | Deriving it means the shipped default is already correct on a box whose php.ini was
        | tuned, with nothing to keep in sync.
        |
        | The two ini values are read and the SMALLER wins, because that is the one that
        | actually binds: upload_max_filesize caps the file, post_max_size caps the whole
        | request body that carries it, and a file cannot exceed either. Both are read from
        | the RUNNING SAPI, so php-fpm and CLI each see their own php.ini - which is right,
        | since the limit is whatever this process can accept.
        |
        | If neither is set, or either reads as unlimited (both yield 0 - see ini_bytes),
        | fall back to 100 MB: comfortably above the documents, images and media a business
        | app attaches, and far below the point where one multipart request is a
        | denial-of-service by itself.
        |
        | Override in rsx/resource/config/rsx.php to set a deliberate app-level ceiling; 0
        | disables the framework check entirely, leaving only PHP's.
        */
        'max_file_size' => (static function () {
            $upload = ini_bytes(ini_get('upload_max_filesize'));
            $post = ini_bytes(ini_get('post_max_size'));

            $limits = array_filter([$upload, $post]);

            return empty($limits) ? 100 * 1024 * 1024 : min($limits);
        })(),

        /*
        | File disposal & retention lifecycle (File_Disposal_Service). Deleting an
        | attachment SOFT-deletes it into a recoverable retention window; a scheduled task
        | permanently destroys it after the window and releases the blob once no live or
        | retained attachment still pins it. See rsx:man file_disposal.
        */
        'deleted_retention_days'   => 30,   // recoverable window before permanent destruction
        'disposal_lookback_days'   => 60,   // blob-release pass window for the daily task
        'disk_orphan_min_age_days' => 14,   // age guard for the monthly disk/unassigned sweep

        /*
        | Pipeline-type map: extension => canonical mime, for the DOCUMENT family.
        |
        | This drives PROCESSING/ROUTING only (viewer, PDF-rendition, text-extractor, and
        | thumbnail registries + the coarse file_type_id bucket), NOT serving. It is consulted
        | by File_Attachment_Model::resolve_pipeline_mime(): for a document extension the mapped
        | mime wins UNCONDITIONALLY over the byte sniff.
        |
        | Rationale: libmagic's OOXML content sniff is heuristic (it inspects early zip member
        | names) and demonstrably per-file flaky - a valid .docx whose second zip member is
        | word/styles.xml can sniff as the generic container application/zip, which silently
        | disables its preview, text extraction, and thumbnail. The file extension is a
        | deliberate authored claim; like a desktop OS, we trust it for documents so two files
        | with the same extension always take the same pipeline path. If the bytes do not match
        | the claim the correct outcome is a loud conversion/parse failure, not a silent misroute.
        |
        | Serving (download/inline Content-Type) intentionally keeps the raw SNIFFED mime as a
        | security boundary - see File_Attachment_Controller::download_file()/inline().
        |
        | Apps may override/extend this map in rsx/resource/config/rsx.php.
        */
        'document_mime_by_extension' => [
            'pdf' => 'application/pdf',

            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',

            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',

            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',

            'rtf' => 'application/rtf',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | LibreOffice Integration (document thumbnail rendering)
    |--------------------------------------------------------------------------
    |
    | Libreoffice_Thumbnail_Renderer shells out to headless LibreOffice to render
    | Office documents (docx/xlsx/pptx/odt/...) to a raster preview for the thumbnail
    | pipeline. RSpade dev Docker images ship LibreOffice preinstalled.
    |
    | - enabled: master switch. When FALSE, document thumbnails silently use the generic
    |   extension icon (no error, no soffice call). When TRUE, a conversion failure logs an
    |   error and the pipeline falls back to the extension icon.
    | - binary_path: absolute path to the `soffice` binary. NULL = auto-detect on PATH and
    |   common install locations.
    | - max_concurrent: max simultaneous LibreOffice renders, enforced cluster-wide via an
    |   RSpade semaphore (RsxLocks::acquire_semaphore). 0 = unlimited. LibreOffice is heavy;
    |   the default keeps a small ceiling.
    | - timeout: hard cap (seconds) on a single render, measured from when the task acquires
    |   its concurrency slot and actually starts rendering. Exceeding it fails the render (then
    |   the extension icon is used).
    | - slot_wait_timeout: max seconds to wait for a free concurrency slot before giving up
    |   (degrading to the icon) rather than piling up under load.
    |
    */
    'libreoffice' => [
        'enabled' => env('LIBREOFFICE_ENABLED', true),
        'binary_path' => env('LIBREOFFICE_BINARY_PATH', null),
        'max_concurrent' => 2,
        'timeout' => 30,
        'slot_wait_timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Text Extraction / Full-text Search
    |--------------------------------------------------------------------------
    |
    | The background extraction pipeline pulls text out of documents (PDF, Office,
    | plain text) into _search_indexes, keyed on the deduplicated _file_storage blob
    | so identical bytes are extracted exactly once. Search_Index_Service drives off
    | the _file_storage.is_indexed flag, one blob per iteration.
    |
    | - enabled: master switch. When FALSE, no extraction is kicked on upload/
    |   materialization and the cron does nothing. Existing rows are untouched.
    | - extractor_version: the current pipeline generation. Bump this when an extractor
    |   changes in a way that should re-produce existing text; then
    |   `rsx:search:reindex --below-version=N` re-queues everything older.
    | - extractors: ordered fnmatch map of mime pattern (glob) => extractor class (simple,
    |   manifest-resolved name). First match wins. A mime matching nothing -> UNSUPPORTED
    |   (not FAILED). Apps may override/extend this key in rsx/resource/config/rsx.php, or
    |   intercept per-file with an #[OnEvent('document.extract_text')] handler.
    | - pdftotext_binary_path: absolute path to `pdftotext` (poppler-utils). NULL = auto-
    |   detect on PATH and common install locations.
    | - timeout: hard cap (seconds) on a single extractor invocation.
    | - max_text_bytes: extracted text is capped at this many bytes. Over-cap extraction is
    |   TRUNCATED and RECORDED (index-row metadata truncated=true + original_bytes), never
    |   silently dropped and never FAILED - a partial index is discoverable, not a wrong answer.
    |
    */
    'search' => [
        'enabled' => env('SEARCH_ENABLED', true),
        'extractor_version' => 2,

        'extractors' => [
            'application/pdf' => 'Pdftotext_Text_Extractor',

            'application/msword' => 'Libreoffice_Text_Extractor',
            'application/vnd.ms-excel' => 'Libreoffice_Text_Extractor',
            'application/vnd.ms-powerpoint' => 'Libreoffice_Text_Extractor',
            'application/vnd.openxmlformats-officedocument.*' => 'Libreoffice_Text_Extractor',
            'application/vnd.oasis.opendocument.*' => 'Libreoffice_Text_Extractor',
            'application/rtf' => 'Libreoffice_Text_Extractor',

            'text/*' => 'Plain_Text_Extractor',
            'application/csv' => 'Plain_Text_Extractor',
            'application/json' => 'Plain_Text_Extractor',
        ],

        'pdftotext_binary_path' => env('PDFTOTEXT_BINARY_PATH', null),
        'timeout' => 30,
        'max_text_bytes' => 2 * 1024 * 1024,  // 2MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Preview (Document_Preview component + rendition endpoint)
    |--------------------------------------------------------------------------
    |
    | Server-side viewer resolution + the cached soffice->PDF rendition pipeline for
    | the Document_Preview component. get_preview_info() resolves a viewer by mime; the
    | /_preview/pdf/:key route serves a PDF rendition of the attachment (the blob itself
    | when it is already a PDF, else a cached soffice conversion of an Office document).
    |
    | - viewers: ordered fnmatch map of mime pattern (glob) => viewer component (simple
    |   name). First match wins; the trailing '*' entry is the terminal Icon_Viewer so
    |   every mime resolves to something. Apps may override/extend in
    |   rsx/resource/config/rsx.php. Viewer components ship in Batch 4.
    | - convertible: mimes the rendition endpoint will convert to PDF via headless
    |   LibreOffice (fnmatch globs). A convertible mime is only converted when
    |   rsx.libreoffice.enabled is true; otherwise the rendition endpoint returns 415.
    | - quota_max_bytes: LRU cap (bytes) on storage/rsx-renditions, enforced by the
    |   File_Rendition_Service scheduled cleanup task (oldest mtime evicted first).
    | - rendition_timeout: hard cap (seconds) on a single soffice->PDF conversion,
    |   measured from when the conversion acquires its concurrency slot.
    |
    */
    'preview' => [
        'viewers' => [
            'application/pdf' => 'Pdf_Viewer',
            'application/msword' => 'Pdf_Viewer',
            'application/vnd.ms-excel' => 'Pdf_Viewer',
            'application/vnd.ms-powerpoint' => 'Pdf_Viewer',
            'application/vnd.openxmlformats-officedocument.*' => 'Pdf_Viewer',
            'application/vnd.oasis.opendocument.*' => 'Pdf_Viewer',
            'application/rtf' => 'Pdf_Viewer',

            'image/*' => 'Image_Viewer',

            '*' => 'Icon_Viewer',
        ],

        'convertible' => [
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.*',
            'application/vnd.oasis.opendocument.*',
            'application/rtf',
        ],

        'quota_max_bytes' => 200 * 1024 * 1024,  // 200MB (enforced via scheduled LRU cleanup)
        'rendition_timeout' => 60,
    ],

    'thumbnails' => [
        // Named preset definitions
        // Format: 'name' => ['type' => 'cover'|'fit', 'width' => int, 'height' => int]
        'presets' => [
            'profile' => ['type' => 'cover', 'width' => 200, 'height' => 200],
            'gallery' => ['type' => 'fit', 'width' => 400, 'height' => 300],
            'icon_small' => ['type' => 'cover', 'width' => 32, 'height' => 32],
            'icon_large' => ['type' => 'cover', 'width' => 64, 'height' => 64],
        ],

        // Thumbnail renderer registry: the "produce a raster from bytes" step of the
        // thumbnail pipeline, pluggable by mime type. Ordered map of mime pattern (fnmatch
        // glob) => renderer class (simple, manifest-resolved name). First match wins.
        // A mime with no registered renderer falls back to a generic extension icon.
        // Apps may override/extend this key in rsx/resource/config/rsx.php.
        //
        // Imagick_Thumbnail_Renderer: raster images (byte-identical to the historic path)
        //   and PDFs (first page, requires Imagick + Ghostscript).
        // Libreoffice_Thumbnail_Renderer: Office documents via `soffice --headless`.
        'renderers' => [
            'image/*' => 'Imagick_Thumbnail_Renderer',
            'application/pdf' => 'Imagick_Thumbnail_Renderer',
            'application/msword' => 'Libreoffice_Thumbnail_Renderer',
            'application/vnd.ms-excel' => 'Libreoffice_Thumbnail_Renderer',
            'application/vnd.ms-powerpoint' => 'Libreoffice_Thumbnail_Renderer',
            'application/vnd.openxmlformats-officedocument.*' => 'Libreoffice_Thumbnail_Renderer',
            'application/vnd.oasis.opendocument.*' => 'Libreoffice_Thumbnail_Renderer',
            'application/rtf' => 'Libreoffice_Thumbnail_Renderer',
        ],

        // Skip the renderer (use the extension icon) for source blobs larger than this,
        // to bound render cost. null = no cap.
        'renderer_max_bytes' => 50 * 1024 * 1024,  // 50MB

        // Storage quotas in bytes (both enforced via LRU eviction)
        'quotas' => [
            'preset_max_bytes' => 100 * 1024 * 1024,  // 100MB (enforced via scheduled task)
            'dynamic_max_bytes' => 50 * 1024 * 1024,  // 50MB (enforced synchronously)
        ],

        // Maximum dimension limit for dynamic thumbnails (base resolution before 2x scaling)
        // This value is doubled during generation (800 becomes 1600x1600 after 2x scaling)
        // Preset thumbnails have no enforced maximum (developer-controlled)
        // NOTE: Application configuration - not overridable via environment variable
        'max_dynamic_size' => 800,

        // Touch mtime on cache hit to update LRU tracking (both preset and dynamic)
        'touch_on_read' => env('THUMBNAILS_TOUCH_ON_READ', true),

        // Only touch if mtime is older than this many seconds (prevents excessive filesystem writes)
        'touch_interval' => env('THUMBNAILS_TOUCH_INTERVAL', 600),  // 10 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Task System
    |--------------------------------------------------------------------------
    |
    | Configuration for the unified task execution system supporting:
    | - Immediate CLI execution
    | - Scheduled tasks (cron-based)
    | - Queued async tasks with worker management
    |
    | Worker Concurrency:
    | - global_max_workers: Maximum total workers across all queues
    | - Per-queue max_workers: Maximum concurrent workers for specific queue
    | - Workers are spawned up to configured limits
    |
    | Task Lifecycle:
    | - Tasks start as "pending" in database
    | - Worker process marks as "running" and updates heartbeat
    | - Completes as "completed" or "failed"
    | - Stuck tasks detected via timeout + heartbeat + PID checking
    |
    | Queues:
    | - default: General purpose task queue
    | - scheduled: Auto-created tasks from #[Schedule] attributes
    | - Custom queues: Define as needed (video, export, email, etc.)
    |
    | Commands:
    | - php artisan rsx:task:process (run via cron every minute)
    | - php artisan rsx:task:run Service method [params]
    | - php artisan rsx:task:list
    |
    */
    'tasks' => [
        // Maximum concurrent workers in the single worker pool (fixed per environment).
        // The Redis worker-slot registry (Task_Worker_Registry) enforces this: a spawned
        // worker self-admits iff live workers < this cap, else it exits cleanly.
        'global_max_workers' => env('RSX_TASK_MAX_WORKERS', 3),

        // Seconds a worker slot survives without a heartbeat before it is pruned as dead
        // (covers SIGKILLed workers). Long tasks call $task->heartbeat() to stay alive.
        'worker_heartbeat_ttl' => 90,

        // Execution cap for a task that does not carry its own timeout (seconds).
        // Enforced by the rsx:task:process reaper: each cron tick, a RUNNING task whose
        // worker is still alive past its cap is killed (SIGTERM -> 5s -> SIGKILL) and
        // settled KILLED, or recycled to PENDING if it is a recurring cron tracker.
        // Granularity is therefore the cron tick interval (one minute), not the second.
        // Set to 0 to run uncapped tasks unbounded (a row's own timeout still applies).
        'default_timeout' => 1800,  // 30 minutes

        // How long before a task is considered stuck (seconds)
        'cleanup_stuck_after' => 1800,  // 30 minutes

        // Default TTL for task temp directories (seconds)
        'temp_directory_default_ttl' => 3600,  // 1 hour

        // How long to keep completed/failed task records (days)
        'task_retention_days' => 30,

        // Consecutive failed runs before rsx:health WARNs about a #[Schedule] tracker.
        // A tracker is never terminal - a run that throws recycles it to pending and the
        // schedule retries next cadence - so this is the line between "retrying" and
        // "broken every run". A reporting threshold only: the schedule keeps running
        // whatever this is set to.
        'failing_schedule_warn_after' => 3,

        // Queue-specific configuration
        'queues' => [
            'default' => [
                'max_workers' => 1,
            ],
            'scheduled' => [
                'max_workers' => 1,
            ],
            // Future queues can be added here:
            // 'video' => ['max_workers' => 2],
            // 'export' => ['max_workers' => 1],
            // 'email' => ['max_workers' => 5],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime (WebSocket) Configuration
    |--------------------------------------------------------------------------
    |
    | PHP publishes via Redis; system/bin/realtime-server.js (Node) relays to
    | the browser. ws_port is also read directly from .env by that Node script
    | (it has no access to Laravel's config cache) - keep it in sync if you change
    | it here. The client's connect URL is DERIVED (wss://{Rsx::get_hostname()}/ws),
    | not configured. See: php artisan rsx:man realtime
    |
    */
    'realtime' => [
        'enabled' => env('REALTIME_ENABLED', false),
        'ws_port' => env('REALTIME_WS_PORT', 6200),

        // Max time (ms) a component's FIRST load waits for its realtime subscription to
        // establish before proceeding anyway (fail-soft when the relay is slow/down).
        // Application behavior, not deployment-specific -> config, not .env. Surfaced to
        // JS via window.rsxapp.realtime.load_gate_timeout_ms.
        'load_gate_timeout_ms' => 1500,

        // How long (ms) a tab may be disconnected or suspended before a successful
        // reconnect forces a full page reload instead of relying on resync. Resync brings
        // the DATA current; it cannot fix stale CODE (an open tab keeps its bundle forever,
        // so a deploy during the gap leaves it running dead JavaScript). The reload reuses
        // the refresh-push machinery: >=500ms floor, >=5s user-idle, suppressed while the
        // tab is navigating away, plus up to 10s of herd jitter. Set 0 to disable.
        // Surfaced to JS via window.rsxapp.realtime.stale_reload_after_ms.
        'stale_reload_after_ms' => 3600000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Locks (rsx-lockd)
    |--------------------------------------------------------------------------
    |
    | Web-cluster locks are served by rsx-lockd (system/bin/rsx-lockd), the lock
    | daemon whose CONNECTION IS THE LOCK - no lease, no TTL, no renewal. System
    | locks are flock() on this box and are not configured here.
    |
    | server_host/server_port must match the daemon's own lockd.conf: the Node
    | daemon reads that JSON file directly and has no access to Laravel's config,
    | so the two are kept in sync by hand. The shipped daemon defaults are
    | 0.0.0.0:6210 (dialed as 127.0.0.1); change both sides together.
    |
    | See: php artisan rsx:man locks
    |
    */
    'locks' => [
        'server_host' => env('LOCK_SERVER_HOST', '127.0.0.1'),
        'server_port' => env('LOCK_SERVER_PORT', 6210),

        // Connect attempts before a cluster-lock request throws, and the pause
        // between them. The overwhelmingly common failure is a daemon in the
        // middle of a supervisor restart, which is over in about a second; a
        // rejected hello is a wrong key and is never retried.
        // Application behavior, not deployment-specific -> config, not .env.
        'connect_retries' => 3,
        'connect_retry_delay_ms' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth Gates
    |--------------------------------------------------------------------------
    |
    | Declarative authorization gates: #[Auth('check')] / @auth('check') on every
    | dispatchable surface, #[Auth_Check] on the realm's Permission methods. At
    | page render the active realm's marked checks are evaluated once each and the
    | GRANTED names ship as window.rsxapp.auth = {name: 1}. Denied checks are
    | omitted, never listed as false. See: php artisan rsx:man auth_gates
    |
    */
    'auth' => [
        // Also ship window.rsxapp.auth_routes = {'Controller::method': 1} - the
        // #[Route]/#[SPA] PHP surfaces this user's grants satisfy - so JS
        // Permission.can_access('Controller::method') can answer for a PHP-routed
        // target. When false (the default) the key is never defined at all and
        // can_access() on a '::' target console.errors naming this setting.
        //
        // PRIVACY DEFAULT. SPA pages link almost exclusively to other SPA actions
        // (whose @auth lists are already in the bundle, so they check for free) or
        // to specialized endpoints (downloads, logout) that need no affordance
        // gating. A manifest of every PHP page a user may reach is more information
        // than a page's source needs to carry, so it stays opt-in. Enable it only
        // if the application genuinely renders conditional links to PHP-routed
        // pages from the SPA.
        //
        // Application behavior, not deployment-specific -> config, not .env.
        'export_php_route_grants' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Fetch
    |--------------------------------------------------------------------------
    |
    | Limits on the generic ORM fetch endpoint (Model.fetch() / lazy relationship
    | accessors in JS). See: php artisan rsx:man model_fetch
    |
    */
    'model_fetch' => [
        // Most records a lazy plural relationship (`await record.things()`) may
        // return. Every record costs its own gated fetch() round trip, so this is a
        // fan-out ceiling, not a page size: exceeding it THROWS rather than
        // truncating, because a caller handed a silent partial set cannot tell.
        // A relation that legitimately grows past this wants its own paginated
        // #[Ajax_Endpoint].
        'max_relationship_records' => 500,

        // Most ids one ORM fetch request may carry. The JS ORM batches every lookup
        // issued in a single turn into one request and SPLITS a larger set across
        // several requests, so this is a request-size ceiling rather than a limit on
        // how much a page may fetch. The server REJECTS an oversized request instead
        // of truncating it: a silently-partial records map is indistinguishable from
        // "those records do not exist", which is precisely what per-id absence means
        // on this endpoint.
        'batch_max_ids' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | See the "Do The Whole Job" section of CLAUDE.md for the result-set rules this
    | supports, and rsx:man database for Rsx_Result_Set.
    |
    */
    'database' => [
        // DEVELOPMENT TRIPWIRE. When a single ->get() returns more than this many rows,
        // log one warning naming the count, the model and the call site. It never
        // throws and never truncates - it just tells you, while you are building the
        // feature, that a query you assumed was small is not.
        //
        // This is the empirical counterpart to the $unbounded declaration: the
        // declaration is a static claim about a table, this fires on what actually
        // happened. Development and debug modes only; production never pays for it.
        //
        // Set to 0 or null to disable.
        'result_set_warn_threshold' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Flash Alerts
    |--------------------------------------------------------------------------
    |
    | Server-to-client messages delivered through the session. See the Flash
    | system docs in app/RSpade/Lib/Flash/CLAUDE.md.
    |
    */
    'flash' => [
        // Most pending alerts one session may hold. Enforced at the WRITER, because
        // the reader hands the whole set to the browser and deletes it - a limit on
        // the READ would silently drop alerts the user was meant to see. When a
        // session exceeds this, the OLDEST overflow is dropped (the newest alerts
        // describe what the user just did). Set to 0 or null to disable.
        'max_alerts_per_session' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Retention
    |--------------------------------------------------------------------------
    |
    | How long a session row survives without being used. Every session is stamped
    | with a TYPE at creation (Session::TYPE_WEB / TYPE_CLI / TYPE_API) and expires
    | against its own type's window; Session_Cleanup_Service sweeps hourly.
    |
    | These are INACTIVITY windows measured from last_active, not absolute
    | lifetimes: an in-use browser session never expires. See: rsx:man session
    |
    */
    'sessions' => [
        // Minutes of inactivity before a WEB (browser) session that carries an
        // IDENTITY expires - a staff login, a portal login, or both, since one row
        // serves both experiences. Default: 3 months.
        'web_timeout_minutes' => 131400,

        // Minutes of inactivity before an API session expires. Default: 7 days.
        'api_timeout_minutes' => 10080,

        // Minutes of inactivity before a PLAYWRIGHT session expires. Default: 1 day.
        // The rsx:debug harness deletes its own session as each request ends, so
        // this window only mops up requests that died before their shutdown hook.
        'playwright_timeout_minutes' => 1440,

        // Minutes of inactivity before a CLI session expires. Default: 1 day.
        // A CLI process deletes its own session row when it ends, so this window
        // only mops up after a process that was killed before its shutdown hook ran.
        'cli_timeout_minutes' => 1440,

        // Minutes of inactivity before a WEB session that never carried ANY identity
        // (neither login_user_id nor portal_user_id) expires. Default: 30 days.
        // Anonymous rows are minted by any visitor a CSRF token is issued to,
        // including crawlers, so they are swept sooner than a signed-in session.
        'anonymous_timeout_minutes' => 43200,

        // How many concurrent WEB sessions one user may hold. On every sign-in the
        // user's older web sessions beyond this count (by last_active) are signed
        // out - the staff cap deactivates the row, the portal cap clears the portal
        // properties off it (the row is that browser's session and may carry a staff
        // login). Bounds the "one row per login forever" growth pattern at its source
        // and limits how many places a stolen-then-abandoned session can persist.
        //
        // Set to 0 or null to DISABLE the feature entirely (no session is ever
        // signed out on login). PLAYWRIGHT and API sessions are never counted or
        // evicted - a harness run must not sign a developer out of their browser.
        'max_web_sessions_per_user' => 25,

        // Window over which failed login attempts are counted, in minutes.
        // Login_History::record_failure() writes no database row: it increments a
        // per-email and a per-IP counter in Redis carrying this value as their TTL
        // (owner-approved 2026-08-12 CR design), and the counts read back by
        // get_failed_attempts_count*() are bounded by it. The window is FIXED, not
        // sliding - it runs from the first failure, then the counter disappears.
        //
        // Application behavior, not a deployment fact, so it lives here rather than
        // in .env. Under maintenance mode Redis is stopped, the increments drop and
        // the counts read 0: throttling built on them fails OPEN, deliberately - a
        // cache outage must never lock users out.
        'login_failure_window_minutes' => 15,

        // Days a `_login_history` row (successes only) is kept before the hourly
        // Session_Cleanup_Service prunes it. Default: 1 year. Set to 0 or null to
        // disable the prune entirely (rows are then kept forever).
        'login_history_retention_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Full Page Cache (FPC) Configuration
    |--------------------------------------------------------------------------
    |
    | The FPC is a Node.js reverse proxy (system/bin/fpc-proxy.js) that caches
    | #[FPC]-marked pages in Redis (DB 0). When disabled, Rsx_FPC::clear() /
    | clear_url() are clean no-ops. When enabled, an unreachable/unauthenticated
    | Redis fails loud (a developer purge must never silently claim success).
    | The proxy reads FPC_* / REDIS_* directly from .env (no Laravel config
    | cache). See: php artisan rsx:man fpc
    |
    */
    'fpc' => [
        'enabled' => env('SSR_FPC_ENABLED', false),

        // Entry TTL in minutes. 0 = never expire (entries persist until the
        // build key rotates, an explicit clear, or rsx:clean). Read directly
        // from .env by the Node proxy on the Redis SET - keep it in sync.
        'ttl_mins' => env('FPC_TTL_MINS', 0),

        // Proxy listen port (advisory - the Node proxy reads FPC_PROXY_PORT
        // from .env itself; this mirrors it for the rsx:health liveness probe).
        'proxy_port' => env('FPC_PROXY_PORT', 3200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail Configuration
    |--------------------------------------------------------------------------
    |
    | Framework defaults for the email queue (Email_Queue_Model is core). The
    | queue records every send; actual outbound delivery is not yet wired - the
    | send task marks processed emails HELD_BACK. See: php artisan rsx:man email
    |
    */
    'mail' => [
        // Retry policy for the (future) real-send loop. Held-back emails do not retry.
        'retry_max' => 6,
        'retry_backoff_minutes' => [2, 4, 8, 15, 30, 60],

        // From address/name for outbound mail.
        'from_address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'from_name' => null,

        // Secret used to sign unsubscribe links (falls back to app.key).
        'unsubscribe_secret' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Configuration
    |--------------------------------------------------------------------------
    |
    | Framework defaults for the SMS queue (Sms_Queue_Model is core). Mirrors the
    | mail config. Real outbound delivery is not yet wired - Sms_Queue_Service marks
    | processed messages HELD_BACK. Dev safety via SMS_DEV_* env (catchall/whitelist/
    | suppress), like EMAIL_DEV_*.
    |
    */
    'sms' => [
        // Sending service that will actually deliver SMS once real send is wired
        // (see backlog). NOT used yet - Sms_Queue_Service currently marks messages
        // HELD_BACK regardless. Common providers:
        //   'twilio'      - Twilio
        //   'aws_sns'     - Amazon SNS (AWS)                 (also Amazon Pinpoint)
        //   'vonage'      - Vonage (formerly Nexmo)
        //   'messagebird' - MessageBird (now Bird)
        //   'plivo'       - Plivo
        //   'sinch'       - Sinch
        //   'telnyx'      - Telnyx
        //   'infobip'     - Infobip
        //   'bandwidth'   - Bandwidth
        // Leave null (or 'log') to keep the held-back / no-delivery behavior.
        'provider' => env('SMS_PROVIDER', null),

        // Sending number / sender id for outbound SMS.
        'from_number' => env('SMS_FROM_NUMBER', null),

        // Provider auth credentials (used once real send is wired). Provider-specific:
        // set only the ones your SMS_PROVIDER needs. Keep secrets in .env, never here.
        'credentials' => [
            // Twilio
            'twilio_account_sid' => env('SMS_TWILIO_ACCOUNT_SID'),
            'twilio_auth_token' => env('SMS_TWILIO_AUTH_TOKEN'),

            // Amazon SNS / AWS (or reuse the app's shared AWS credentials)
            'aws_key' => env('SMS_AWS_KEY'),
            'aws_secret' => env('SMS_AWS_SECRET'),
            'aws_region' => env('SMS_AWS_REGION', 'us-east-1'),

            // Vonage (Nexmo)
            'vonage_api_key' => env('SMS_VONAGE_API_KEY'),
            'vonage_api_secret' => env('SMS_VONAGE_API_SECRET'),

            // Generic API key/secret + token for the others
            // (MessageBird/Plivo/Sinch/Telnyx/Infobip/Bandwidth).
            'api_key' => env('SMS_API_KEY'),
            'api_secret' => env('SMS_API_SECRET'),
            'auth_token' => env('SMS_AUTH_TOKEN'),
        ],

        // Retry policy for the (future) real-send loop. Held-back messages do not retry.
        'retry_max' => 6,
        'retry_backoff_minutes' => [2, 4, 8, 15, 30, 60],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile
    |--------------------------------------------------------------------------
    |
    | The CAPTCHA replacement: a background browser challenge that mints a
    | single-use token, verified server-side against Cloudflare's siteverify
    | service. ACTIVITY IS CONFIGURED, NOT DERIVED - development mode has no
    | bearing on it; only 'enabled' does.
    |
    | The site key is PUBLIC and ships to the browser as window.rsxapp.turnstile
    | (only when enabled). The secret key is server-only and is never exported.
    | Enabling with either key missing is a half-configured install and throws.
    | See: php artisan rsx:man turnstile
    |
    */
    'turnstile' => [
        'enabled' => env('TURNSTILE_ENABLED', false),

        // Public - rendered into the widget by the browser.
        'site_key' => env('TURNSTILE_SITE_KEY'),

        // Server-only - the siteverify credential. Never exported to the client.
        'secret_key' => env('TURNSTILE_SECRET_KEY'),

        // siteverify HTTP timeout, in seconds. A SANCTIONED timeout, owner-approved
        // 2026-08-12: expiry degrades to FAIL CLOSED (the verification is rejected
        // and the user retries), never to an accepted-without-verification pass.
        'verify_timeout_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | A Content-Security-Policy tells the browser which origins a page may load
    | code, styles, images, fonts and connections from, and refuses everything
    | else. It is the last line of defense against an injected <script>: even a
    | successful XSS has nowhere to load its payload from.
    |
    | RSpade composes ONE policy PER REALM (staff, portal) in Rsx_Csp and stamps
    | it on every dispatched HTML response. The policy is DERIVED, never
    | hand-written:
    |
    | - Framework inline scripts carry a per-request NONCE. 'unsafe-inline' never
    |   appears in script-src, in any mode.
    | - Every external host comes from a *.externals.php declaration beside the
    |   feature that needs it, so an undeclared resource is a blocked resource and
    |   policy cannot drift from code.
    | - style-src keeps 'unsafe-inline' and carries NO nonce (a nonce present makes
    |   browsers ignore 'unsafe-inline', which would break every style="" in the
    |   tree). This is deliberate, not an oversight.
    |
    | Per-mode behavior (RSX_MODE):
    | - development: declared external assets are fetched from their own origins,
    |   so those origins are whitelisted; dev bundle CDN assets whitelist
    |   themselves as they are emitted; ws:// is permitted alongside wss:// to
    |   match the realtime client's plain-http downgrade.
    | - debug / production (sealed): mirrorable external assets are served from
    |   this build's own /_vendor/ copy, so their origins LEAVE the whitelist and
    |   collapse into 'self'. Only mirror:false entries (a widget that must run
    |   from its vendor's origin) stay named. Strict production drops ws://.
    |
    | Each declaration's own `csp` extras (frames it opens, hosts it calls at
    | runtime) apply in EVERY mode - mirroring an asset does not change what the
    | script does once it runs.
    |
    | See: php artisan rsx:man csp
    | See: php artisan rsx:man external_resources
    |
    */
    'csp' => [
        // Emit a policy at all. False = no header on any response, anywhere.
        'enabled' => true,

        // True = the header is Content-Security-Policy-Report-Only: the browser
        // REPORTS violations to /_csp-report and blocks nothing.
        //
        // ROLLOUT: ship report-only, run the app, then read
        // storage/logs/csp_violations.log. Every line is something a real page
        // tried to load that the policy would have blocked. Triage each one -
        // either declare it in a *.externals.php file (a resource the page loads
        // itself) or add it to additional_sources (a transitive load, below) -
        // and when the log stays clean, flip this to false to ENFORCE.
        //
        // Report-only is a silent state: nothing breaks and nothing tells you the
        // policy is not actually protecting anything. That flip is a launch task.
        'report_only' => true,

        // directive => [sources] merged into the composed policy.
        //
        // THIS EXISTS ONLY FOR TRANSITIVE EXTERNALS: a declared script that goes
        // on to load FURTHER scripts, from origins the loader cannot know because
        // the vendor chooses them at runtime (an analytics or tag-manager loader
        // is the classic case). Nothing else belongs here.
        //
        // A resource the PAGE loads directly belongs in a *.externals.php
        // declaration instead, where the CSP whitelist derives from it
        // automatically and the loader can fetch it by identifier.
        //
        // WIDEN-ONLY: sources are APPENDED - they can never remove or replace the
        // framework's own hardening. Downstream config deep-merges ADDITIVELY on
        // top of this, so an app's array widens the policy and never replaces it.
        // object-src is refused outright: it is set to 'none' as hardening, and
        // appending to "nothing" would loosen it while reading like an addition.
        'additional_sources' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Datetime Configuration
    |--------------------------------------------------------------------------
    |
    | Configure date and time handling across the application.
    | See: php artisan rsx:man time
    |
    */
    'datetime' => [
        // Default timezone (IANA identifier) when user has no preference
        // Resolution order: login_users.timezone → this default → 'America/Chicago'
        'default_timezone' => env('RSX_DEFAULT_TIMEZONE', 'America/Chicago'),

        // Time dropdown interval in minutes (for Schedule_Input component)
        'time_interval' => 15,

        // Default duration for new events in minutes (for Schedule_Input component)
        'default_duration' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dependency Layer (rsx:composer / rsx:npm)
    |--------------------------------------------------------------------------
    |
    | Downstream applications layer their own packages at the project root
    | (composer.json -> /vendor, package.json -> /node_modules); the framework
    | wins all overlaps. The lists below name framework-installed packages that
    | are formally exposed to application code: requiring one via rsx:composer/
    | rsx:npm records it as provided-by-framework instead of installing a
    | duplicate. Exposure is a standing commitment - breaking changes to an
    | exposed package ship with an upstream_changes document (Category 2).
    |
    | See: php artisan rsx:man dependencies
    |
    */

    'dependencies' => [
        // Framework packages formally exposed to application code. Requiring one
        // via rsx:composer/rsx:npm records it as provided-by-framework instead of
        // installing a duplicate. Exposure is a commitment: breaking changes to
        // these ship with an upstream_changes document (Category 2).
        'exposed_composer' => [
            'laravel/framework',
            'guzzlehttp/guzzle',
            'giggsey/libphonenumber-for-php',
            'ezyang/htmlpurifier',
            'sokil/php-isocodes',
            'nikic/php-parser',
        ],
        'exposed_npm' => [
            'dompurify',
            'google-libphonenumber',
        ],
    ],
];
