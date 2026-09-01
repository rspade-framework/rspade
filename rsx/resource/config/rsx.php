<?php

/**
 * RSX User Configuration
 *
 * This file overrides framework defaults from /config/rsx.php using deep array merge.
 * Arrays are combined, scalar values are replaced. Your values take precedence.
 *
 * Framework defaults are in /config/rsx.php - review that file for available options.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Application Identity
    |--------------------------------------------------------------------------
    |
    | Core application identification used in layouts, authentication, and UI.
    |
    */

    'name' => env('RSPADE_NAME', 'RSpade'),
    'description' => env('RSPADE_DESCRIPTION', 'Rapid Single Page Application Development Environment'),

    /*
    |--------------------------------------------------------------------------
    | Development Test Credentials
    |--------------------------------------------------------------------------
    |
    | DEFINED BY THE FRAMEWORK, not here - 'rsx.default_user' reads
    | RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD from .env, and both are
    | empty unless you set them. Redeclaring the block here would just be a
    | second definition of one value.
    |
    | Set them in .env, and see .env.README for what they do. Auto-fill on a
    | login form additionally requires RSPADE_LOGIN_AUTOFILL to be on.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Manifest Processing
    |--------------------------------------------------------------------------
    |
    | Add custom file type processors to the manifest system.
    | Example: TypeScript, LESS, CoffeeScript processors.
    |
    | Framework ships with:
    | - Blade_ManifestModule (Blade templates)
    | - Scss_ManifestModule (SCSS/Sass)
    |
    */

    'manifest_modules' => [
        // Add custom manifest modules here
        // \Rsx\Integrations\TypeScript\TypeScript_ManifestModule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Manifest Excluded Directories & Files
    |--------------------------------------------------------------------------
    |
    | Additional directories and files to exclude from manifest scanning.
    |
    | Framework already excludes directories:
    | vendor, node_modules, storage, .git, public, resource, archived
    |
    | Framework already excludes files:
    | CLAUDE.md, .placeholder, .DS_Store, Thumbs.db, desktop.ini,
    | .gitkeep, .gitattributes
    |
    | Uncomment and add custom exclusions only when needed:
    |
    | 'manifest' => [
    |     'excluded_dirs' => [
    |         'old_code',
    |         'backups',
    |     ],
    |     'excluded_files' => [
    |         'my-custom-ignore.txt',
    |         'temp-data.json',
    |     ],
    | ],
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Bundle Processors
    |--------------------------------------------------------------------------
    |
    | Add custom file processors for bundle compilation.
    | Processors transform files during bundling based on extension.
    |
    | Framework ships with:
    | - Scss_BundleProcessor (SCSS → CSS)
    | - Jqhtml_BundleProcessor (jqhtml templates)
    |
    */

    'bundle_processors' => [
        // Add custom processors here
        // \Rsx\Processors\TypeScript_BundleProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Required Bundles
    |--------------------------------------------------------------------------
    |
    | Bundles automatically prepended to every bundle's include list.
    | Override to change order or remove bundles.
    |
    | Framework defaults: ['jquery', 'lodash', 'core', 'jqhtml']
    |
    */

    // 'required_bundles' => [
    //     'jquery',
    //     'lodash',
    //     'core',
    //     'jqhtml',
    //     'my-global-bundle',  // Add your global bundle
    // ],

    /*
    |--------------------------------------------------------------------------
    | Bundle Aliases
    |--------------------------------------------------------------------------
    |
    | Map short names to bundle classes for use in include arrays.
    |
    | Framework provides: core, jquery, lodash, bootstrap5, jqhtml
    |
    */

    'bundle_aliases' => [
        // Add your application bundles
        // 'my-app' => \Rsx\App\Bundles\My_App_Bundle::class,
        // 'admin-theme' => \Rsx\Theme\Admin_Bundle::class,
        'bootstrap5_src' => \Rsx\Theme\Bootstrap5_Src_Bundle::class,
        'portal' => \Rsx\Portal\Portal_Bundle::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | JavaScript ORM Model Base Class
    |--------------------------------------------------------------------------
    |
    | Specify a custom JavaScript class to serve as the base for all generated
    | model stubs. This allows you to add application-wide model functionality
    | (custom methods, event hooks, caching behavior, etc.) that all models
    | will inherit.
    |
    | The inheritance chain becomes:
    |   Rsx_Js_Model (framework)
    |       └── Your_Model_Abstract (your custom base - defined here)
    |               └── Base_Project_Model (generated stub with enums, etc.)
    |                       └── Project_Model (concrete class)
    |
    | Requirements for the custom class:
    | - Must extend Rsx_Js_Model
    | - Will be automatically included in every bundle
    | - Should be defined in your application code (e.g., rsx/lib/)
    |
    | Example:
    |   'js_model_base_class' => 'App_Model_Abstract',
    |
    | Then create rsx/lib/App_Model_Abstract.js:
    |   class App_Model_Abstract extends Rsx_Js_Model {
    |       // Your custom model methods here
    |   }
    |
    */

    'js_model_base_class' => null,

    /*
    |--------------------------------------------------------------------------
    | Public Directory Security
    |--------------------------------------------------------------------------
    |
    | Additional file patterns to block from HTTP access in /rsx/public/.
    | Uses gitignore-style wildcards.
    |
    | Framework already blocks:
    | public_ignore.json, .git*, *.php, .env*, *.sh, *.py, *.rb,
    | *.conf, *.ini, *.sql, *.bak, *.tmp, *~
    |
    */

    'public' => [
        'ignore_patterns' => [
            // Add application-specific patterns
            // '*.key',
            // 'secrets/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Preferences
    |--------------------------------------------------------------------------
    |
    | IDE and development experience settings.
    |
    */

    'development' => [
        // Auto-rename files to match class names (VS Code extension)
        // Files renamed when they don't match contained class/@rsx_id/<Define:>
        // 'auto_rename_files' => true,

        // Disable filename convention enforcement globally
        // Use @FILENAME-CONVENTION-EXCEPTION in individual files for granular control
        // 'ignore_filename_convention' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Code Quality
    |--------------------------------------------------------------------------
    |
    | Custom rules and whitelists for code quality checks.
    |
    */

    'code_quality' => [
        // Allow additional root-level files (build configs, IDE helpers)
        // Framework allows: vite.config.js, webpack.*.js, _ide_helper.php, etc.
        'root_whitelist' => [
            // 'my-build-script.js',
            // 'custom-helper.php',
        ],

        // Classes exempt from suffix inheritance rules
        // Framework exempts: Component, Component, Rsx_System_Model_Abstract
        'suffix_exempt_classes' => [
            // 'My_Custom_Base_Class',
        ],

        // Define additional generic suffixes requiring specificity
        // Framework defines: Module → ManifestModule/BundleModule
        //                   Rule → CodeQualityRule/ValidationRule
        'generic_suffix_replacements' => [
            // 'Handler' => ['CommandHandler', 'EventHandler', 'RequestHandler'],
            // 'Service' => ['ApiService', 'DataService', 'AuthService'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IDE Integration
    |--------------------------------------------------------------------------
    |
    | VS Code extension and IDE bridge settings.
    |
    */

    'ide_integration' => [
        // Application domain: 'auto' or specific URL
        // Auto-discovery from first web request (non-localhost)
        // 'application_domain' => env('RSX_APPLICATION_DOMAIN', 'auto'),
        // 'application_domain' => 'https://myapp.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Console Debug
    |--------------------------------------------------------------------------
    |
    | Customize debug output destinations, filters, and channels.
    |
    */

    'console_debug' => [
        // Master switch - disable all console_debug output
        'enabled' => env('CONSOLE_DEBUG_ENABLED', true),

        // Output destinations
        'outputs' => [
            'cli' => env('CONSOLE_DEBUG_CLI', false),          // stderr in CLI
            'web' => env('CONSOLE_DEBUG_WEB', true),           // browser console
            'ajax' => env('CONSOLE_DEBUG_AJAX', true),         // AJAX responses
            'laravel_log' => env('CONSOLE_DEBUG_LOG', false),  // Laravel log file
        ],

        // Filter mode: 'all', 'whitelist', 'blacklist', 'specific'
        'filter_mode' => env('CONSOLE_DEBUG_FILTER_MODE', 'all'),

        // Specific channel (when filter_mode is 'specific')
        'specific_channel' => env('CONSOLE_DEBUG_SPECIFIC', null),

        // Whitelisted channels (when filter_mode is 'whitelist')
        // Framework defaults: AUTH, DISPATCH, JS_INIT, UI, TEST, AJAX, RSX_INIT, JQHTML_INIT
        'whitelist' => env('CONSOLE_DEBUG_WHITELIST') ? explode(',', env('CONSOLE_DEBUG_WHITELIST')) : ['AUTH', 'DISPATCH', 'JS_INIT', 'UI', 'TEST', 'AJAX', 'RSX_INIT', 'JQHTML_INIT'],

        // Blacklisted channels (when filter_mode is 'blacklist')
        'blacklist' => env('CONSOLE_DEBUG_BLACKLIST') ? explode(',', env('CONSOLE_DEBUG_BLACKLIST')) : [],

        // Include timing prefix showing seconds since request start
        'include_benchmark' => env('CONSOLE_DEBUG_BENCHMARK', false),

        // Include file/line where console_debug was called
        'include_location' => env('CONSOLE_DEBUG_LOCATION', false),

        // Include full call stack
        'include_backtrace' => env('CONSOLE_DEBUG_BACKTRACE', false),

        // Enable ?__trace=1 for plain text debug output
        'enable_get_trace' => env('ENABLE_GET_TRACE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | The framework composes one policy per realm and ALWAYS ENFORCES it, with
    | every external host derived from *.externals.php declarations. An
    | undeclared resource fails visibly - the browser blocks it, and the refusal
    | is recorded in storage/logs/csp_violations.log.
    |
    | The one thing an application declares for itself is TRANSITIVE externals -
    | a declared script that loads further scripts of its own from origins
    | nothing in our code can name. Those origins have no declaration to derive
    | from, so they are listed here. A resource this app loads DIRECTLY belongs
    | in a *.externals.php file instead.
    |
    | Sources listed here are APPENDED to the framework's policy - they widen it
    | and can never weaken the hardening.
    |
    | See: php artisan rsx:man csp
    |
    */

    'csp' => [
        // Google Analytics, the worked example in rsx/lib/analytics/: the script
        // URL itself (www.googletagmanager.com) needs nothing here - it is
        // DECLARED in analytics.externals.php and the policy derives from that.
        // What is listed below is only what gtag.js goes on to fetch and beacon
        // at runtime, from origins no declaration in this tree can enumerate.
        // Uncomment together with the measurement id below; analytics ships off,
        // so with an empty measurement id nothing here would ever be loaded.
        // 'additional_sources' => [
        //     'script-src' => ['https://www.google-analytics.com'],
        //     'connect-src' => ['https://www.google-analytics.com', 'https://analytics.google.com'],
        //     'img-src' => ['https://www.google-analytics.com', 'https://analytics.google.com'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics
    |--------------------------------------------------------------------------
    |
    | GA4 measurement id ('G-XXXXXXXXXX'). EMPTY MEANS OFF, which is how this
    | template ships: nothing is fetched, and the only trace analytics leaves is
    | the declared origin in the staff policy.
    |
    | Set it and the staff app loads gtag.js lazily through the external-resources
    | registry (rsx/lib/analytics/ - the declaration plus the loader, the template's
    | worked example of the pattern). Development never reports; the sealed debug
    | and production builds do.
    |
    | ENABLING THIS MEANS UNCOMMENTING THE csp.additional_sources ABOVE. gtag.js
    | pulls further Google origins at runtime that no declaration can name, so
    | without them an enforcing policy blocks half of what analytics does.
    |
    | See: php artisan rsx:man external_resources
    |
    */

    'analytics' => [
        'measurement_id' => env('GOOGLE_ANALYTICS_ID', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser Error Logging
    |--------------------------------------------------------------------------
    |
    | Log JavaScript errors from browser to Laravel log.
    | Errors batched with 2-second debounce, max 20 per page.
    |
    */

    'log_browser_errors' => env('LOG_BROWSER_ERRORS', true),

    /*
    |--------------------------------------------------------------------------
    | HTTP Middleware (application additions)
    |--------------------------------------------------------------------------
    |
    | system/app/Http/Kernel.php is framework-owned (hard-synced by every framework
    | update), so your own HTTP middleware is declared HERE. Entries are APPEND-ONLY:
    | they run AFTER the framework stack and can never reorder or remove a framework
    | middleware - if you genuinely need that, file a framework change request.
    |
    | Every declared class is validated at bootstrap and a bad declaration throws:
    | unknown group key, missing class, or an alias already bound to another class.
    |
    | See: php artisan rsx:man config_rsx
    |
    */

    // 'middleware' => [
    //     'global' => [\App\Http\Middleware\My_Middleware::class],
    //     'web' => [],
    //     'api' => [],
    //     'aliases' => [
    //         'my_alias' => \App\Http\Middleware\My_Middleware::class,
    //     ],
    // ],

    /*
    |--------------------------------------------------------------------------
    | Response Defaults
    |--------------------------------------------------------------------------
    |
    | Default views and response behaviors for your application.
    |
    */

    'response' => [
        // Default view when controller doesn't specify
        // 'default_view' => 'welcome',

        // Custom error views
        'error_views' => [
            // '404' => 'errors.404',
            // '500' => 'errors.500',
        ],

        // CORS settings for API endpoints
        'cors' => [
            // 'enabled' => env('RSX_CORS_ENABLED', false),
            // 'allowed_origins' => ['https://myapp.com'],
            // 'allowed_methods' => ['GET', 'POST'],
            // 'allowed_headers' => ['Content-Type', 'Authorization'],
            // 'max_age' => 86400,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception Handlers
    |--------------------------------------------------------------------------
    |
    | Add custom exception handlers. Handlers run in priority order.
    |
    | Framework handlers:
    | - Cli_Exception_Handler (priority 10)
    | - Ajax_Exception_Handler (priority 20)
    | - Playwright_Exception_Handler (priority 30)
    | - Rsx_Dispatch_Bootstrapper_Handler (priority 1000)
    |
    | Priority ranges:
    | 1-50: Critical/environment-specific
    | 51-100: Standard handlers
    | 101-500: Low priority
    | 501+: Fallback/catch-all
    |
    */

    'exception_handlers' => [
        // Add your custom handlers
        // \Rsx\Exceptions\Payment_Exception_Handler::class,  // Priority 40
        // \Rsx\Exceptions\Api_Exception_Handler::class,      // Priority 50
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication & User Management
    |--------------------------------------------------------------------------
    |
    | Configuration for user registration, invitations, and verification.
    |
    | NOTE: These are development convenience switches. For production,
    | find the code using these settings and customize to your exact needs
    | rather than relying on configuration modes. This keeps the codebase
    | simple and prevents configuration explosion.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Configuration for the notification system.
    |
    */

    'notifications' => [
        // Default notification expiry in days
        // Notifications older than this are automatically deleted
        'default_expiry_days' => 21,
    ],

    /*
    |--------------------------------------------------------------------------
    | Formatting
    |--------------------------------------------------------------------------
    |
    | THIS APP'S formatting defaults. The framework has no opinion about them.
    |
    | phone_region is the ISO 3166-1 region a phone number typed WITHOUT a '+'
    | country code is understood to belong to. It is the parse region for
    | server-side validation (Frontend_Contacts_Controller::save) and the display
    | region for Rsx\Lib\Formatters::phone(). An app serving another country
    | changes it here; a number carrying '+' ignores it entirely.
    |
    */

    'formatting' => [
        'phone_region' => 'US',
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Portal
    |--------------------------------------------------------------------------
    |
    | Configuration for the client portal - a separate authenticated experience
    | for external users (customers, clients, vendors).
    |
    | URL Strategy:
    | - Production: Set 'domain' to use a dedicated portal domain
    | - Development: Leave 'domain' null to use URL prefix mode (/_portal/)
    |
    */

    'portal' => [
        // Portal domain for production (e.g., 'portal.example.com')
        // When set, portal routes are served from this domain
        // When null, portal routes use the prefix below
        'domain' => env('PORTAL_DOMAIN', null),

        // URL prefix for portal routes when no domain configured
        // All portal routes will be prefixed with this path
        'prefix' => '/_portal',

        // Session lifetime in days for portal users
        'session_lifetime_days' => 30,

        // Invitation code expiry in days
        'invitation_expiry_days' => 14,

        // Password requirements
        'password_min_length' => 8,

        // Password reset token expiry in hours
        'password_reset_expiry_hours' => 1,

        // THE site this portal serves. An APPLICATION key, not a framework one:
        // the framework never resolves a portal site, it is declared at runtime
        // with Portal_Session::set_site_id() (see rsx:man portal). This app is
        // mono-site, so the declaration reads this key - see rsx/portal_main.php.
        // A multi-tenant portal would drop this key and look the site up from the
        // request host instead.
        'site_id' => 1,

        // Default expiry for shared content links (days)
        'shared_link_default_expiry_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Email System
    |--------------------------------------------------------------------------
    |
    | Everything about outbound mail - the delivery mode, the transport, the
    | retry policy, retention and the dev-site recipient gating - is framework
    | config (system/config/rsx.php 'mail'), driven by .env. This app tier is
    | for what is genuinely YOURS: how your email looks.
    |
    | The three dev-site keys live in .env:
    |
    |   EMAIL_DEV_CATCHALL_ADDRESS=dev@example.com
    |   EMAIL_DEV_EMAIL_ADDRESS_WHITELIST=safe@example.com,other@example.com
    |   EMAIL_DEV_EMAIL_DOMAIN_WHITELIST=example.com,trusted.org
    |
    | See: php artisan rsx:man email
    |
    */

    'mail' => [
        // Branding the email layout renders. logo_url is an absolute URL or a
        // public asset path; footer_text replaces the default copyright line.
        'branding' => [
            'logo_url' => null,
            'footer_text' => null,
        ],
    ],

    'api' => [
        // Leading token on every newly minted API key: {prefix}{environment}_{random},
        // so a key from this product reads as rsx_live_xxxxx. Rename it to your own
        // product's short name. Existing keys are unaffected - lookup is by hash of the
        // whole key, never by prefix.
        'key_prefix' => 'rsx_',

        // Named scope presets offered in the Settings > API Keys mint modal.
        //
        // PRESETS ARE APPLICATION DATA. The framework never names a scope - exactly as
        // Auth_Gates never names a permission and leaves the #[Auth_Check] vocabulary to
        // rsx/permission.php. Framework core does not read this key; only the template's
        // Frontend_Settings_Api_Keys_Controller does, and only to expand a ticked name into
        // its rules. Rename them, delete them, add your own - the rule language in
        // Api_Scopes is the whole contract, and a preset is just a stored rule set with a
        // label an operator recognises.
        //
        // 'rules' is one Grant|Deny METHOD /api/vN/pattern per line (`*` is exactly one
        // segment, `**` is zero or more and only as the last segment). TICKING SEVERAL
        // PRESETS IS SET UNION AND IS ORDER-INDEPENDENT: the decision is by pattern
        // specificity with a Deny winning any tie, never by the order the rules were
        // concatenated, so no preset can be made stronger by being ticked first.
        //
        // Every preset below grants GET /api/v1/me, the identity endpoint a client calls at
        // setup to check its own key. A scoped key that cannot reach it looks broken to a
        // well-written integration on its very first call.
        'scope_presets' => [
            [
                'name' => 'Read-only',
                'description' => 'Every GET endpoint; no writes.',
                'rules' => "Grant GET /api/v1/**",
            ],
            [
                'name' => 'Contacts & clients',
                'description' => 'Read and write contacts and clients, including client document attachments.',
                'rules' => "Grant GET /api/v1/me\n"
                    . "Grant GET /api/v1/contacts/**\n"
                    . "Grant POST /api/v1/contacts/**\n"
                    . "Grant GET /api/v1/clients/**\n"
                    . "Grant POST /api/v1/clients/**",
            ],
            [
                'name' => 'Files',
                'description' => 'Upload files and read stored file content. Attaching a file to a record needs that record\'s preset as well.',
                'rules' => "Grant GET /api/v1/me\n"
                    . "Grant GET /api/v1/files/**\n"
                    . "Grant POST /api/v1/files/**",
            ],
        ],
    ],

    'auth' => [
        // Signup mode: 'invite_only', 'anonymous', 'disabled'
        //
        // invite_only: Only users with valid invitation codes can register
        // anonymous:   Anyone can create an account without invitation
        // disabled:    No new signups allowed (closed system)
        //
        // Default: invite_only (Slack/Trello-style invitation workflow)
        'signup_mode' => env('RSX_SIGNUP_MODE', 'invite_only'),

        // Verification requirements: 'none', 'email', 'sms', 'either', 'both'
        //
        // none:   No verification required (development mode)
        // email:  Email verification required
        // sms:    SMS/phone verification required
        // either: Email OR SMS verification required
        // both:   Both email AND SMS verification required
        //
        // Default: none (add verification later in development)
        'verification_required' => env('RSX_VERIFICATION_REQUIRED', 'none'),

        // Invitation expiration (days)
        //
        // How many days invitation links remain valid before expiring
        // Default: 7 days
        'invite_expiration_days' => env('RSX_INVITE_EXPIRATION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme (dark mode)
    |--------------------------------------------------------------------------
    |
    | THIS APP'S theme vocabulary. The framework resolves the user's preference and
    | renders these attributes onto <body> before the SPA shell loads; it has no idea
    | what they mean, which is what keeps RSpade free of any one UI toolkit.
    |
    | This template is built on Bootstrap 5.3, whose colour modes are driven by
    | data-bs-theme - so that is what it declares. An app on a different toolkit
    | declares whatever ITS css reads, and one that themes purely off the framework's
    | own rsx-dark class declares nothing at all.
    |
    | The mode set and the default live in the framework config; only the expression
    | is an application decision. See rsx:man dark_mode.
    |
    */

    'theme' => [
        'dark_mode' => [
            'attributes' => [
                'dark' => ['data-bs-theme' => 'dark'],
                'light' => ['data-bs-theme' => 'light'],
            ],
        ],
    ],

];
