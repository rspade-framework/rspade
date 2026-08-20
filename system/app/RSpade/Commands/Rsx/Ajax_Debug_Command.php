<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxAuthRequiredException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Ajax\Exceptions\AjaxFatalErrorException;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;

/**
 * RSX Ajax Command
 * =================
 *
 * PURPOSE:
 * Execute Ajax endpoint methods from CLI with JSON output.
 * Designed for testing, automation, and scripting.
 *
 * OUTPUT MODES:
 * 1. Default: Raw JSON response (just the return value)
 * 2. --debug: Full HTTP-like response with {success, _ajax_return_value, console_debug}
 * 3. --verbose: Add request context before JSON output
 *
 * USAGE EXAMPLES:
 * php artisan rsx:ajax Controller action                               # Basic call
 * php artisan rsx:ajax Controller action --args='{"id":1}'             # With params
 * php artisan rsx:ajax Controller action --site=1                      # With site context
 * php artisan rsx:ajax Controller action --user=1 --site=1             # Full context
 * php artisan rsx:ajax Controller action --user=admin@test.com        # User by email
 * php artisan rsx:ajax Controller action --debug                       # HTTP-like response
 * php artisan rsx:ajax Controller action --verbose --site=1            # Show context
 *
 * OUTPUT FORMAT:
 * Default: {"records":[...], "total":10}
 * --debug: {"success":true, "_ajax_return_value":{...}, "console_debug":[...]}
 * --verbose: Adds "Set site_id to 1" before JSON
 *
 * ERROR HANDLING:
 * All errors return JSON (never throws to stderr)
 * {"success":false, "error":"Error message", "error_type":"exception_type"}
 *
 * USE CASES:
 * - Test Ajax endpoints behind auth/site scoping
 * - Invoke RPC calls from automation scripts
 * - Debug API responses in production environments
 */
class Ajax_Debug_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:ajax
        {controller : The RSX controller name}
        {action : The action/method name}
        {--args= : JSON-encoded arguments to pass to the action}
        {--user= : Set user ID or email for session context}
        {--site= : Set site ID for session context}
        {--debug : Wrap output in HTTP-like response format (success, _ajax_return_value, console_debug)}
        {--show-context : Show request context before JSON output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Ajax endpoints from CLI with JSON output';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        // Get command arguments
        $controller = $this->argument('controller');
        $action = $this->argument('action');

        // Parse arguments if provided
        $args = [];
        if ($this->option('args')) {
            $args_json = $this->option('args');
            $args = json_decode($args_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->output_json_error('Invalid JSON in --args parameter: ' . json_last_error_msg(), 'invalid_json');
                return 1;
            }
        }

        // Get options
        $user_input = $this->option('user');
        $site_input = $this->option('site');
        $debug_mode = $this->option('debug');
        $show_context = $this->option('show-context');

        // Validate and resolve user
        $user_id = null;
        if ($user_input !== null) {
            $user_id = $this->resolve_user($user_input);
            if ($user_id === null) {
                return 1; // Error already displayed
            }
        }

        // Validate site
        $site_id = null;
        if ($site_input !== null) {
            $site_id = $this->resolve_site($site_input);
            if ($site_id === null) {
                return 1; // Error already displayed
            }
        }

        // Rotate logs before test
        Debugger::logrotate();

        // Set session context if provided
        if ($user_id !== null) {
            Session::set_login_user_id((int)$user_id);
            if ($show_context) {
                $this->error("Set login_user_id to {$user_id}");
            }
        }

        if ($site_id !== null) {
            Session::set_site_id((int)$site_id);
            if ($show_context) {
                $this->error("Set site_id to {$site_id}");
            }
        }

        try {
            // Call the Ajax method
            $response = Ajax::internal($controller, $action, $args);

            // Output response based on mode
            if ($debug_mode) {
                // Use shared format_ajax_response method for consistency with HTTP
                $wrapped_response = Ajax::format_ajax_response($response);
                $this->output_json($wrapped_response);
            } else {
                // Just output the raw response
                $this->output_json($response);
            }

        } catch (AjaxAuthRequiredException $e) {
            $this->output_json_error($e->getMessage(), 'auth_required');
            return 1;

        } catch (AjaxUnauthorizedException $e) {
            $this->output_json_error($e->getMessage(), 'unauthorized');
            return 1;

        } catch (AjaxFormErrorException $e) {
            $error_response = [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => 'form_error',
            ];

            $details = $e->get_details();
            if (!empty($details)) {
                $error_response['details'] = $details;
            }

            $this->output_json($error_response);
            return 1;

        } catch (AjaxFatalErrorException $e) {
            $this->output_json_error($e->getMessage(), 'fatal_error', [
                'trace' => $e->getTraceAsString()
            ]);
            return 1;

        } catch (\InvalidArgumentException $e) {
            $this->output_json_error($e->getMessage(), 'invalid_argument');
            return 1;

        } catch (\Exception $e) {
            $this->output_json_error($e->getMessage(), get_class($e), [
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        // Rotate logs after test
        Debugger::logrotate();

        return 0;
    }

    /**
     * Output JSON to stdout (pretty-printed)
     */
    protected function output_json($data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->line($json);
    }

    /**
     * Output error as JSON
     */
    protected function output_json_error(string $message, string $error_type, array $extra = []): void
    {
        $error = [
            'success' => false,
            'error' => $message,
            'error_type' => $error_type,
        ];

        foreach ($extra as $key => $value) {
            $error[$key] = $value;
        }

        $this->output_json($error);
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
                $this->output_json_error("User not found: {$user_input}", 'user_not_found');
                return null;
            }
            return $login_user->id;
        }

        // Input is a user ID - validate it exists
        if (!ctype_digit($user_input)) {
            $this->output_json_error("Invalid user identifier: {$user_input} (must be numeric ID or email address)", 'invalid_user');
            return null;
        }

        $user_id = (int) $user_input;
        $login_user = Login_User_Model::find($user_id);
        if (!$login_user) {
            $this->output_json_error("User ID not found: {$user_id}", 'user_not_found');
            return null;
        }

        return $user_id;
    }

    /**
     * Resolve site identifier to site ID
     *
     * Validates that the site exists in the database.
     *
     * @param string $site_input Site ID
     * @return int|null Site ID or null if not found (error already displayed)
     */
    protected function resolve_site(string $site_input): ?int
    {
        if (!ctype_digit($site_input)) {
            $this->output_json_error("Invalid site identifier: {$site_input} (must be numeric ID)", 'invalid_site');
            return null;
        }

        $site_id = (int) $site_input;
        $site = Site_Model::find($site_id);
        if (!$site) {
            $this->output_json_error("Site ID not found: {$site_id}", 'site_not_found');
            return null;
        }

        return $site_id;
    }
}