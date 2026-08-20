<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Portal\Portal_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * RSX Route Debug Command
 * ========================
 * 
 * PURPOSE:
 * This is a comprehensive route debugging tool designed for the RSpade project.
 * It uses Playwright to launch a real browser and fetch a route, capturing extensive
 * diagnostic information about the request, response, JavaScript execution, and logs.
 * 
 * HOW IT WORKS:
 * 1. Rotates all development logs for clean slate debugging
 * 2. Launches a headless Chromium browser via Playwright
 * 3. Navigates to the specified route on localhost
 * 4. Captures response, console output, XHR requests, DOM state, and more
 * 5. Outputs results in a terse, log-like format for easy debugging
 * 6. Rotates logs again after test for clean slate on next run
 * 
 * KEY FEATURES:
 * - Backdoor authentication: Use --user with ID or email to bypass login
 * - Plain text error output: Errors returned as plain text with stack traces
 * - Console capture: JavaScript errors and logs captured (--console for all)
 * - XHR/fetch tracking: Monitor API calls with --xhr-dump or --xhr-list
 * - Element verification: Check DOM elements with --expect-element
 * - HTML extraction: Get element HTML with --dump-element
 * - Storage inspection: View localStorage/sessionStorage with --storage
 * - Form inspection: List all input elements with --input-elements
 * - Cookie display: Show all cookies with --cookies
 * - Redirect following: Track redirect chains with --follow-redirects
 * - POST requests: Send JSON data with --post
 * - Headers inspection: Display all headers with --headers
 * - Log integration: Show Laravel/nginx logs with --log or --all-logs
 * - Wait for elements: Delay capture with --wait-for selector
 * - Full output mode: Enable all display options with --full
 * 
 * DESIGN GOALS:
 * - Data-driven debugging - Focus on data flow, not visual presentation
 * - Minimal, terse output - No fancy formatting or colors in the output
 * - Log-like format - Output designed to be easily parsed or grepped
 * - Real browser testing - Tests the full stack including JavaScript
 * - Development only - This tool is disabled in production environments
 * - Clean slate testing - Logs rotated before/after each test
 * 
 * USAGE EXAMPLES:
 * php artisan rsx:debug /dashboard                        # Basic route test
 * php artisan rsx:debug /dashboard --user=1               # Test as user ID 1
 * php artisan rsx:debug /dashboard --user=admin@test.com  # Test as user by email
 * php artisan rsx:debug /api/users --no-body              # Headers only
 * php artisan rsx:debug /login --full                     # Maximum information
 * php artisan rsx:debug /api/data --xhr-list              # Simple XHR list
 * php artisan rsx:debug /api/data --xhr-dump              # Full XHR details
 * php artisan rsx:debug /form --expect-element="#submit"  # Verify element exists
 * php artisan rsx:debug /page --dump-element=".content"   # Extract element HTML
 * php artisan rsx:debug /app --storage                    # View storage data
 * php artisan rsx:debug /form --input-elements            # List form inputs
 * php artisan rsx:debug /api --post='{"key":"value"}'     # POST JSON data
 * php artisan rsx:debug /slow --wait-for=".loaded"        # Wait for element
 * php artisan rsx:debug /auth --follow-redirects          # Track redirects
 * php artisan rsx:debug /page --all-logs                  # Show all log files
 * php artisan rsx:debug /demo --eval="typeof jQuery"      # Execute JavaScript code
 * 
 * OMITTED FEATURES:
 * This tool is designed for data-driven debugging, not visual testing or interaction.
 * The following features are intentionally omitted as out of scope:
 * 
 * - Screenshot capture: Not implemented as this tool focuses on data, not visuals
 * - PDF generation: Not implemented as this tool is for debugging, not archival
 * - Page interaction (click, fill, select): Out of scope for route debugging
 * - Visual regression testing: Use dedicated visual testing tools instead
 * - Performance profiling: Use browser DevTools or dedicated profiling tools
 * - Accessibility testing: Use dedicated accessibility testing tools
 * 
 * These omissions keep the tool focused on its core purpose: debugging data flow
 * through routes, not testing UI appearance or user interactions.
 * 
 * IMPLEMENTATION DETAILS:
 * - Uses X-Playwright-Test header to trigger plain text error responses
 * - Uses X-Dev-Auth-User-Id header for backdoor authentication
 * - Auto-installs Playwright and Chromium if not present
 * - Route interception prevents CORS issues with CDN resources
 * - Works with both Laravel routes and RSX routes
 * - Logs rotated via Debugger::logrotate() for clean testing
 * 
 * SECURITY:
 * - Only available in local/development/testing environments
 * - Throws fatal error if attempted in production
 * - Backdoor authentication only works in non-production environments
 * 
 * OUTPUT FORMAT:
 * The command outputs in a simple, parseable format:
 * - Status line with route and response code
 * - Redirect chain (if --follow-redirects used)
 * - Response headers (if --headers used)
 * - Console errors (always shown if present)
 * - Console logs (if --console used)
 * - XHR/fetch requests (if --xhr-dump or --xhr-list used)
 * - Input elements (if --input-elements used)
 * - Cookies (if --cookies used)
 * - Storage data (if --storage used)
 * - Element HTML (if --dump-element used)
 * - Response body (unless --no-body used)
 * - Laravel log errors (shown by default if errors exist)
 * - Nginx error log (shown by default if errors exist)
 * - All logs complete (if --all-logs used)
 * 
 * The --full flag enables all display options except --no-body and --follow-redirects,
 * providing maximum diagnostic information in a single command.
 */
class Route_Debug_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:debug
        {url? : The URL to debug (e.g., /dashboard, /api/users). Use --examples to see usage examples}
        {--examples : Show comprehensive usage examples}
        {--user= : Test as specific user ID or email (bypasses authentication)}
        {--log : Display Laravel error log if not empty}
        {--no-body : Suppress HTTP response body (show headers/status only)}
        {--follow-redirects : Follow HTTP redirects and show full redirect chain}
        {--headers : Display all HTTP response headers}
        {--console : Display all browser console output (not just errors) and console_debug() output even if SHOW_CONSOLE_DEBUG_HTTP=false}
        {--console-log : Alias for --console}
        {--xhr-dump : Capture full details of XHR/fetch requests (URL, headers, body, response)}
        {--input-elements : List all form input elements with values and attributes}
        {--post= : Send POST request with JSON data (e.g., --post=\'{"key":"value"}\')}
        {--cookies : Display all browser cookies with domains and expiry}
        {--wait-for= : Wait for CSS selector before capture (e.g., --wait-for=".loaded")}
        {--all-logs : Display Laravel log and nginx logs after test}
        {--expect-element= : Verify element exists by CSS selector (fails if not found)}
        {--dump-element= : Extract and display HTML of element by CSS selector}
        {--storage : Display localStorage and sessionStorage contents}
        {--xhr-list : Show simple list of XHR/fetch URLs and status codes}
        {--full : Enable all display options except no-body and follow-redirects}
        {--eval= : Execute JavaScript code in the page context and display the result}
        {--timeout= : Navigation timeout in milliseconds (minimum 30000ms, default 30000ms)}
        {--console-debug-filter= : Filter console_debug output to specific channel (e.g., BENCHMARK, DISPATCH)}
        {--console-debug-benchmark : Include benchmark timing prefixes in console_debug output}
        {--console-debug-all : Show all console_debug channels (overrides filter)}
        {--console-debug-disable : Disable console_debug entirely for this test}
        {--console-list : Alias for --console-log to display all console output}
        {--screenshot-width= : Screenshot width (px or preset: mobile, iphone-mobile, tablet, desktop-small, desktop-medium, desktop-large). Defaults to 1920}
        {--screenshot-path= : Path to save screenshot file (triggers screenshot capture, max height 5000px)}
        {--dump-dimensions= : Add data-dimensions attribute to elements matching selector (for layout debugging)}
        {--portal : Test portal routes (uses /_portal/ prefix and portal authentication)}
        {--portal-user= : Test as specific portal user ID or email (requires --portal)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug URLs using headless browser - captures response, console, XHR, DOM state, storage, and logs (development only)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Check environment - throw fatal error in production
        if (app()->environment('production')) {
            throw new \RuntimeException('FATAL: rsx:debug command is not available in production environment. This is a development-only debugging tool.');
        }

        // Check if --examples was requested
        if ($this->option('examples')) {
            $this->showExamples();
            return 0;
        }

        // Get the URL to debug
        $url = $this->argument('url');

        // If no URL provided, show help
        if (!$url) {
            $this->error('No URL provided. Use --examples to see usage examples.');
            return 1;
        }

        // Split off any #fragment. A fragment is never transmitted to the server, so it must
        // NOT take part in the dev-auth signature (the verifier signs the URI it received and
        // the two payloads would never agree). The fragment is still handed to Playwright for
        // navigation, so client-side tab/hash selection keeps working.
        $url_fragment = '';
        $fragment_position = strpos($url, '#');
        if ($fragment_position !== false) {
            $url_fragment = substr($url, $fragment_position);
            $url = substr($url, 0, $fragment_position);
            if ($url === '') {
                $url = '/';
            }
        }

        // Check if console_debug is disabled globally and user didn't override
        $console_debug_enabled = config('rsx.console_debug.enabled', false) || env('CONSOLE_DEBUG_ENABLED') === 'true';
        $console_debug_override = $this->option('console') ||
                                  $this->option('console-log') ||
                                  $this->option('console-list') ||
                                  $this->option('console-debug-all') ||
                                  $this->option('console-debug-filter') ||
                                  $this->option('console-debug-benchmark') ||
                                  env('CONSOLE_DEBUG_FILTER') ||
                                  env('CONSOLE_DEBUG_BENCHMARK');
        $console_debug_disabled = $this->option('console-debug-disable') || env('CONSOLE_DEBUG_ENABLED') === 'false';

        // If console_debug is disabled and not overridden, show a single line message
        if (!$console_debug_enabled && !$console_debug_override && !$console_debug_disabled) {
            $this->line('console_debug is disabled. Run `php artisan rsx:man console_debug` for more information on its usage.');
            // Don't return early - still run the test, just with the message
        }

        // Ensure URL starts with /
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }

        // Get portal mode options
        $portal_mode = $this->option('portal');
        $portal_user_input = $this->option('portal-user');

        // Validate portal options
        if ($portal_user_input && !$portal_mode) {
            $this->error('--portal-user requires --portal flag');
            return 1;
        }

        // Normalize URL for portal mode (strip /_portal/ prefix if present)
        if ($portal_mode && str_starts_with($url, '/_portal')) {
            $url = substr($url, 8); // Remove '/_portal'
            if ($url === '' || $url === false) {
                $url = '/';
            }
        }

        // Get user ID from options (accepts ID or email)
        $user_id = $this->option('user');
        if ($user_id !== null) {
            if ($portal_mode) {
                $this->error('Use --portal-user instead of --user when --portal flag is set');
                return 1;
            }
            $user_id = $this->resolve_user($user_id);
            if ($user_id === null) {
                return 1; // Error already displayed
            }
        }

        // Get portal user ID from options (accepts ID or email)
        $portal_user_id = null;
        if ($portal_user_input !== null) {
            $portal_user_id = $this->resolve_portal_user($portal_user_input);
            if ($portal_user_id === null) {
                return 1; // Error already displayed
            }
        }

        // Get log flag
        $show_log = $this->option('log');
        
        // Get no-body flag
        $no_body = $this->option('no-body');
        
        // Get follow-redirects flag
        $follow_redirects = $this->option('follow-redirects');
        
        // Get headers flag
        $headers = $this->option('headers');
        
        // Get console flag (--console or --console-log alias)
        $console_log = $this->option('console') || $this->option('console-log');
        
        // Get xhr-dump flag
        $xhr_dump = $this->option('xhr-dump');
        
        // Get input-elements flag
        $input_elements = $this->option('input-elements');
        
        // Get POST data
        $post_data = $this->option('post');
        
        // Get cookies flag
        $cookies = $this->option('cookies');
        
        // Get wait-for selector
        $wait_for = $this->option('wait-for');
        
        // Get all-logs flag
        $all_logs = $this->option('all-logs');
        
        // Get new feature flags
        $expect_element = $this->option('expect-element');
        $dump_element = $this->option('dump-element');
        $storage = $this->option('storage');
        $xhr_list = $this->option('xhr-list');
        $full = $this->option('full');
        $eval_code = $this->option('eval');

        // Get timeout option and validate
        $timeout = $this->option('timeout');
        if ($timeout !== null) {
            $timeout = intval($timeout);
            if ($timeout < 30000) {
                $this->error('[ERROR] Timeout value is in milliseconds and must be no less than 30000 milliseconds (30 seconds)');
                return 1;
            }
        } else {
            $timeout = 30000; // Default 30 seconds
        }

        // Get screenshot options
        $screenshot_width = $this->option('screenshot-width');
        $screenshot_path = $this->option('screenshot-path');

        // Get dump-dimensions option
        $dump_dimensions = $this->option('dump-dimensions');

        // Get console debug options (with environment variable fallbacks)
        $console_debug_filter = $this->option('console-debug-filter') ?: env('CONSOLE_DEBUG_FILTER');
        $console_debug_benchmark = $this->option('console-debug-benchmark') ?: env('CONSOLE_DEBUG_BENCHMARK', false);
        $console_debug_all = $this->option('console-debug-all') ?: (env('CONSOLE_DEBUG_FILTER') === 'ALL');
        $console_debug_disable = $this->option('console-debug-disable') ?: (env('CONSOLE_DEBUG_ENABLED') === 'false');

        // Auto-enable console-log and console_debug when console-debug-filter is set
        if ($console_debug_filter && !$console_debug_disable) {
            $console_log = true; // Enable console log output
            // console_debug is enabled via the filter itself
        }

        // Auto-enable console_debug when CONSOLE_DEBUG_ENABLED=true
        if (env('CONSOLE_DEBUG_ENABLED') === 'true' && !$console_debug_disable) {
            // Enable console output if not explicitly disabled
            if (!$console_debug_filter && !$console_debug_all) {
                $console_debug_all = true; // Show all channels if no specific filter
            }
        }
        $console_list = $this->option('console-list');

        // console-list is an alias for console-log
        if ($console_list) {
            $console_log = true;
        }

        // Rotate logs before test to ensure clean slate
        Debugger::logrotate();
        
        // Check if Playwright script exists
        $playwright_script = base_path('bin/route-debug.js');
        if (!file_exists($playwright_script)) {
            $this->error("[ERROR] Playwright script not found: {$playwright_script}");
            $this->error('Please create the script or check your installation');
            return 1;
        }
        
        // Check if node/npm is available
        $node_check = new Process(['node', '--version']);
        $node_check->run();
        
        if (!$node_check->isSuccessful()) {
            $this->error('[ERROR] Node.js is not installed or not in PATH');
            return 1;
        }
        
        // Check if playwright is installed
        $playwright_check = new Process(['node', '-e', "require('playwright')"], base_path());
        $playwright_check->run();
        
        if (!$playwright_check->isSuccessful()) {
            $this->warn('[WARNING]  Playwright not installed. Installing now...');
            $npm_install = new Process(['npm', 'install', 'playwright'], base_path());
            $npm_install->run(function ($type, $buffer) {
                echo $buffer;
            });
            
            if (!$npm_install->isSuccessful()) {
                $this->error('[ERROR] Failed to install Playwright');
                return 1;
            }
            $this->info('[OK] Playwright installed');
            $this->info('');
        }
        
        // Check if chromium browser is installed and up to date
        $browser_check_script = "const {chromium} = require('playwright'); chromium.launch({headless:true}).then(b => {b.close(); process.exit(0);}).catch(e => {console.error(e.message); process.exit(1);});";
        $browser_check = new Process(['node', '-e', $browser_check_script], base_path(), $_ENV, null, 10);
        $browser_check->run();
        
        if (!$browser_check->isSuccessful()) {
            $error_output = $browser_check->getErrorOutput() . $browser_check->getOutput();
            
            // Check if it's a browser not installed or out of date error
            if (str_contains($error_output, "Executable doesn't exist") || 
                str_contains($error_output, "browserType.launch") || 
                str_contains($error_output, "Playwright was just installed or updated")) {
                
                $this->info('Installing/updating Chromium browser...');
                $browser_install = new Process(['npx', 'playwright', 'install', 'chromium'], base_path());
                $browser_install->setTimeout(300); // 5 minute timeout for download
                $browser_install->run(function ($type, $buffer) {
                    // Silent - downloads can be verbose
                });
                
                if (!$browser_install->isSuccessful()) {
                    $this->error('[ERROR] Failed to install Chromium browser');
                    $this->error('Run manually: npx playwright install chromium');
                    return 1;
                }
                $this->info('[OK] Chromium browser installed/updated');
                $this->info('');
            } else {
                $this->error('[ERROR] Browser check failed: ' . trim($error_output));
                return 1;
            }
        }
        
        // Generate signed request token for user/site context
        // This prevents unauthorized requests from hijacking sessions via headers
        $dev_auth_token = null;
        if ($user_id) {
            $dev_auth_token = $this->generate_dev_auth_token($url, $user_id, false);
        } elseif ($portal_user_id) {
            $dev_auth_token = $this->generate_dev_auth_token($url, $portal_user_id, true);
        }

        // Build command arguments
        // Playwright navigates the FULL url - the fragment is client-side state, not part of
        // the signed request.
        $command_args = ['node', $playwright_script, $url . $url_fragment];

        if ($portal_mode) {
            $command_args[] = '--portal';
        }

        if ($user_id) {
            $command_args[] = "--user={$user_id}";
        }

        if ($portal_user_id) {
            $command_args[] = "--portal-user={$portal_user_id}";
        }

        if ($dev_auth_token) {
            $command_args[] = "--dev-auth-token={$dev_auth_token}";
        }
        
        if ($show_log) {
            $command_args[] = '--log';
        }
        
        if ($no_body) {
            $command_args[] = '--no-body';
        }
        
        if ($follow_redirects) {
            $command_args[] = '--follow-redirects';
        }
        
        if ($headers) {
            $command_args[] = '--headers';
        }
        
        if ($console_log) {
            $command_args[] = '--console-log';
        }
        
        if ($xhr_dump) {
            $command_args[] = '--xhr-dump';
        }
        
        if ($input_elements) {
            $command_args[] = '--input-elements';
        }
        
        if ($post_data) {
            $command_args[] = "--post={$post_data}";
        }
        
        if ($cookies) {
            $command_args[] = '--cookies';
        }
        
        if ($wait_for) {
            $command_args[] = "--wait-for={$wait_for}";
        }
        
        if ($all_logs) {
            $command_args[] = '--all-logs';
        }
        
        if ($expect_element) {
            $command_args[] = "--expect-element={$expect_element}";
        }
        
        if ($dump_element) {
            $command_args[] = "--dump-element={$dump_element}";
        }
        
        if ($storage) {
            $command_args[] = '--storage';
        }
        
        if ($xhr_list) {
            $command_args[] = '--xhr-list';
        }
        
        if ($full) {
            $command_args[] = '--full';
        }

        if ($eval_code) {
            // Don't use escapeshellarg here as it adds extra quotes
            // Just pass the eval code directly since Process class handles escaping
            $command_args[] = "--eval={$eval_code}";
        }

        if ($timeout) {
            $command_args[] = "--timeout={$timeout}";
        }

        if ($console_debug_filter) {
            $command_args[] = "--console-debug-filter={$console_debug_filter}";
        }

        if ($console_debug_benchmark) {
            $command_args[] = "--console-debug-benchmark";
        }

        if ($console_debug_all) {
            $command_args[] = "--console-debug-all";
        }

        if ($console_debug_disable) {
            $command_args[] = "--console-debug-disable";
        }

        if ($screenshot_width) {
            $command_args[] = "--screenshot-width={$screenshot_width}";
        }

        if ($screenshot_path) {
            $command_args[] = "--screenshot-path={$screenshot_path}";
        }

        if ($dump_dimensions) {
            $command_args[] = "--dump-dimensions={$dump_dimensions}";
        }

        // Pass Laravel log path as environment variable
        $laravel_log_path = storage_path('logs/laravel.log');

        $env = array_merge($_ENV, [
            'LARAVEL_LOG_PATH' => $laravel_log_path
        ]);

        // Add console debug filter to environment if provided
        if ($console_debug_filter) {
            $env['CONSOLE_DEBUG_FILTER'] = $console_debug_filter;
            $env['CONSOLE_DEBUG_ENABLED'] = 'true'; // Enable console_debug when filter is set
        }
        
        // Convert timeout from milliseconds to seconds for Process timeout
        // Add 10 seconds buffer to the Process timeout to allow Playwright to timeout first
        $process_timeout = ($timeout / 1000) + 10;

        // Every request the harness makes mints a TYPE_PLAYWRIGHT session, and the
        // session must OUTLIVE each request (dev-auth only signs in the navigation;
        // the page's XHRs authenticate by that session's cookie). So the RUN owns
        // them, and the run deletes them here. The minute of slack absorbs
        // PHP-vs-MySQL clock skew and can only ever reach other harness rows.
        $run_started_at = Rsx_Time::to_database(Rsx_Time::subtract(Rsx_Time::now(), 60));

        $process = new Process(
            $command_args,
            base_path(),
            $env,
            null,
            $process_timeout
        );

        $process->run(function ($type, $buffer) {
            // Output directly to console
            echo $buffer;
        });

        Session::purge_playwright_sessions($run_started_at);

        // Rotate logs after test to clean slate for next run
        Debugger::logrotate();

        return $process->isSuccessful() ? 0 : 1;
    }

    /**
     * Show comprehensive usage examples
     */
    protected function showExamples()
    {
        $this->info('RSX Debug Command - Comprehensive Usage Examples');
        $this->line('=================================================');
        $this->line('');

        $this->comment('BASIC USAGE:');
        $this->line('  php artisan rsx:debug /dashboard                        # Test a URL');
        $this->line('  php artisan rsx:debug /api/users --no-body              # Headers only');
        $this->line('  php artisan rsx:debug /login --full                     # All information');
        $this->line('');

        $this->comment('AUTHENTICATION:');
        $this->line('  php artisan rsx:debug /admin --user=1                   # Test as user ID 1');
        $this->line('  php artisan rsx:debug /admin --user=admin@example.com   # Test as user by email');
        $this->line('');

        $this->comment('PORTAL ROUTES:');
        $this->line('  php artisan rsx:debug /dashboard --portal --portal-user=1');
        $this->line('                                                          # Test portal as user ID 1');
        $this->line('  php artisan rsx:debug /_portal/dashboard --portal --portal-user=1');
        $this->line('                                                          # Same (/_portal/ prefix stripped)');
        $this->line('  php artisan rsx:debug /mail --portal --portal-user=client@example.com');
        $this->line('                                                          # Test portal as user by email');
        $this->line('');

        $this->comment('TESTING RSX JAVASCRIPT (use return or console.log for output):');
        $this->line('  php artisan rsx:debug / --eval="return typeof Rsx_Time"              # Check if class exists');
        $this->line('  php artisan rsx:debug / --eval="return Rsx_Time.now_iso()"           # Get current time');
        $this->line('  php artisan rsx:debug / --eval="return Rsx_Date.today()"             # Get today\'s date');
        $this->line('  php artisan rsx:debug / --console --eval="console.log(Rsx_Time.get_user_timezone())"');
        $this->line('                                                                       # Use console.log with --console');
        $this->line('');

        $this->comment('POST-LOAD INTERACTIONS (click buttons, test modals, etc):');
        $this->line('  php artisan rsx:debug /page --user=1 --eval="$(\'[data-sid=btn_edit]\').click(); await new Promise(r => setTimeout(r, 2000));"');
        $this->line('                                                          # Click button, wait 2s for modal');
        $this->line('  php artisan rsx:debug /form --eval="$(\'#submit\').click(); await new Promise(r => setTimeout(r, 1000));"');
        $this->line('                                                          # Submit form and capture result');
        $this->line('');

        $this->comment('DEBUGGING OUTPUT:');
        $this->line('  php artisan rsx:debug / --console                       # All console output');
        $this->line('  php artisan rsx:debug / --console-log                   # Alias for --console');
        $this->line('  php artisan rsx:debug / --console-debug-filter=AUTH     # Filter console_debug');
        $this->line('  php artisan rsx:debug / --console-debug-all             # Show all console_debug channels');
        $this->line('  php artisan rsx:debug / --console-debug-benchmark       # With timing');
        $this->line('  php artisan rsx:debug / --console-debug-disable         # Disable console_debug');
        $this->line('  php artisan rsx:debug / --log                           # Display Laravel error log');
        $this->line('  php artisan rsx:debug / --all-logs                      # Show all log files');
        $this->line('');

        $this->comment('XHR/AJAX MONITORING:');
        $this->line('  php artisan rsx:debug /api --xhr-list                   # Simple XHR list');
        $this->line('  php artisan rsx:debug /api --xhr-dump                   # Full XHR details');
        $this->line('');

        $this->comment('DOM INSPECTION:');
        $this->line('  php artisan rsx:debug /form --expect-element="#submit"  # Verify element exists');
        $this->line('  php artisan rsx:debug /page --dump-element=".content"   # Extract element HTML');
        $this->line('  php artisan rsx:debug /form --input-elements            # List form inputs');
        $this->line('  php artisan rsx:debug /slow --wait-for=".loaded"        # Wait for element');
        $this->line('');

        $this->comment('HTTP TESTING:');
        $this->line('  php artisan rsx:debug /api --post=\'{"key":"value"}\'     # POST JSON data');
        $this->line('  php artisan rsx:debug /auth --follow-redirects          # Track redirects');
        $this->line('  php artisan rsx:debug /api --headers                    # Display all headers');
        $this->line('  php artisan rsx:debug /app --cookies                    # Show all cookies');
        $this->line('');

        $this->comment('BROWSER STATE:');
        $this->line('  php artisan rsx:debug /app --storage                    # View localStorage/sessionStorage');
        $this->line('  php artisan rsx:debug /slow --timeout=60000             # 60 second timeout');
        $this->line('');

        $this->comment('SCREENSHOTS:');
        $this->line('  php artisan rsx:debug /page --screenshot-path=/tmp/screenshot.png');
        $this->line('                                                          # Screenshot at 1920px (default)');
        $this->line('  php artisan rsx:debug /page --screenshot-width=mobile --screenshot-path=/tmp/mobile.png');
        $this->line('                                                          # Mobile device (412px)');
        $this->line('  php artisan rsx:debug /page --screenshot-width=tablet --screenshot-path=/tmp/tablet.png');
        $this->line('                                                          # Tablet device (768px)');
        $this->line('  php artisan rsx:debug /page --screenshot-width=1024 --screenshot-path=/tmp/custom.png');
        $this->line('                                                          # Custom width (1024px)');
        $this->line('  # Available presets: mobile (412px), iphone-mobile (390px), tablet (768px),');
        $this->line('  #                    desktop-small (1366px), desktop-medium (1920px), desktop-large (2560px)');
        $this->line('');

        $this->comment('LAYOUT DEBUGGING:');
        $this->line('  php artisan rsx:debug /page --dump-dimensions=".card"');
        $this->line('                                                          # Add data-dimensions to .card elements');
        $this->line('  php artisan rsx:debug /page --dump-dimensions=".sidebar,.main"');
        $this->line('                                                          # Multiple selectors');
        $this->line('  # Output in DOM: data-dimensions=\'{"x":0,"y":60,"w":250,"h":800,"margin":0,"padding":"20 15 20 15"}\'');
        $this->line('');

        $this->comment('IMPORTANT NOTES:');
        $this->line('  - When using rsx:debug with grep and no output appears, re-run without grep');
        $this->line('    to see the full context and any errors that may have occurred');
        $this->line('  - Use rsx_dump_die() in your code for temporary debugging output');
        $this->line('  - This command is development-only and disabled in production');
        $this->line('  - For more details on console_debug: php artisan rsx:man console_debug');
        $this->line('  - For config options: php artisan rsx:man config_rsx');
    }

    /**
     * Resolve user identifier to user ID
     *
     * Accepts either a numeric user ID or an email address.
     * Validates that the user exists in the database.
     *
     * @param string $user_input User ID or email address
     * @return int|null User ID or null if not found (error already displayed)
     */
    protected function resolve_user(string $user_input): ?int
    {
        // Check if input is an email address
        if (str_contains($user_input, '@')) {
            $login_user = Login_User_Model::find_by_email($user_input);
            if (!$login_user) {
                $this->error("User not found: {$user_input}");
                return null;
            }
            return $login_user->id;
        }

        // Input is a user ID - validate it exists
        if (!ctype_digit($user_input)) {
            $this->error("Invalid user identifier: {$user_input} (must be numeric ID or email address)");
            return null;
        }

        $user_id = (int) $user_input;
        $login_user = Login_User_Model::find($user_id);
        if (!$login_user) {
            $this->error("User ID not found: {$user_id}");
            return null;
        }

        return $user_id;
    }

    /**
     * Resolve portal user identifier to user ID
     *
     * Accepts either a numeric user ID or an email address.
     * Validates that the portal user exists in the database.
     *
     * @param string $user_input Portal user ID or email address
     * @return int|null User ID or null if not found (error already displayed)
     */
    protected function resolve_portal_user(string $user_input): ?int
    {
        // Check if input is an email address
        if (str_contains($user_input, '@')) {
            $portal_user = Portal_User_Model::where('email', $user_input)->first();
            if (!$portal_user) {
                $this->error("Portal user not found: {$user_input}");
                return null;
            }
            return $portal_user->id;
        }

        // Input is a user ID - validate it exists
        if (!ctype_digit($user_input)) {
            $this->error("Invalid portal user identifier: {$user_input} (must be numeric ID or email address)");
            return null;
        }

        $user_id = (int) $user_input;
        $portal_user = Portal_User_Model::find($user_id);
        if (!$portal_user) {
            $this->error("Portal user ID not found: {$user_id}");
            return null;
        }

        return $user_id;
    }

    /**
     * Generate a signed dev auth token for Playwright requests
     *
     * The token is an HMAC signature of the request parameters using APP_KEY.
     * This ensures that only requests originating from rsx:debug (which has
     * access to APP_KEY) can authenticate as different users.
     *
     * @param string $url The URL being tested
     * @param int $user_id The user ID to authenticate as
     * @param bool $is_portal Whether this is a portal user (vs main site user)
     * @return string The signed token
     */
    protected function generate_dev_auth_token(string $url, int $user_id, bool $is_portal = false): string
    {
        $app_key = config('app.key');
        if (!$app_key) {
            $this->error("APP_KEY not configured - cannot generate dev auth token");
            exit(1);
        }

        // Create payload with request parameters
        $payload = json_encode([
            'url' => $url,
            'user_id' => $user_id,
            'portal' => $is_portal,
        ]);

        // Sign with HMAC-SHA256
        return hash_hmac('sha256', $payload, $app_key);
    }
}