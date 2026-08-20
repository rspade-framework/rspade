<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Environment Symlink Guard
|--------------------------------------------------------------------------
|
| system/.env is framework space and is always a symlink to the project-root
| .env. A real file there SHADOWS the root one, and the application then serves
| every request against configuration nobody wrote - silently, because it boots
| and answers perfectly well while ignoring the operator's settings.
|
| Runs first, before the IDE endpoints and before Laravel: everything after this
| point may read configuration. The healthy path is two syscalls and no writes.
|
*/

require __DIR__ . '/../bootstrap/rsx_env_link.php';

/*
|--------------------------------------------------------------------------
| First-Run Setup
|--------------------------------------------------------------------------
|
| With APP_URL still empty, offer to set it from the address this request
| arrived on - the one thing a container cannot work out for itself, and the one
| thing the browser knows for certain. Development mode only, and unreachable
| the moment APP_URL has a value.
|
*/

require __DIR__ . '/../bootstrap/rsx_first_run.php';

/*
|--------------------------------------------------------------------------
| IDE Service Endpoints (Must be before maintenance check)
|--------------------------------------------------------------------------
|
| Handle IDE service requests that bypass Laravel for performance.
| These provide fast responses for IDE integration features.
|
*/

$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Handle IDE service endpoints
if (str_starts_with($request_path, '/_ide/service')) {
    // SECURITY-CRITICAL: Authenticate FIRST before any service logic
    // This checks session auth OR localhost bypass before proceeding
    require_once __DIR__ . '/../app/RSpade/Ide/Services/auth.php';

    // If we reach here, authentication passed (auth.php exits on failure)

    // SECURITY: Explicit whitelist only - handlers must be explicitly defined here.
    // User input (service name) determines WHICH handler, but cannot inject arbitrary paths.
    // TODO: Improve the design of this subsystem invocation later.

    // Extract service name
    $service_name = str_replace('/_ide/service', '', $request_path);
    $service_name = trim($service_name, '/');

    // Whitelist of allowed handlers
    $allowed_handlers = [
        'format' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'definition' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'complete' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'resolve_class' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'git' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'git/diff' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'refactor' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        // All other services use the Laravel handler
        'default' => __DIR__ . '/../app/RSpade/Ide/Services/laravel_handler.php',
    ];

    // Determine which handler to use
    if (isset($allowed_handlers[$service_name])) {
        $handler_path = $allowed_handlers[$service_name];
    } else {
        // Services not explicitly listed use the Laravel handler
        $handler_path = $allowed_handlers['default'];
    }

    // Execute the whitelisted handler
    if (file_exists($handler_path)) {
        require_once $handler_path;
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Storage Root (pre-boot)
|--------------------------------------------------------------------------
|
| Volatile storage lives at <project>/storage once the relocation marker exists
| (written by bin/environment_updates/030_relocate_storage.sh); before that it is
| the historic system/storage. This runs before Laravel boots, so storage_path()
| is unavailable and the marker is read directly - the same resolution used by
| bootstrap/app.php, artisan and the updater. TRANSITIONAL fallback.
|
*/

$__rsx_storage = __DIR__ . '/../../storage';
if (!is_file($__rsx_storage . '/.rspade_storage_relocated')) {
    $__rsx_storage = __DIR__ . '/../storage';
}


/*
|--------------------------------------------------------------------------
| Maintenance Gate
|--------------------------------------------------------------------------
|
| While maintenance mode is up (rsx:maintenance:enable, or the window
| rsx:framework:pull holds for an update) every web request returns 503 - the
| IDE bridge above is intentionally exempt. The flag's CONTENT is the operator
| reason and is printed with the 503. RSPADE_MAINT_MODE is the per-process
| snapshot every consumer reads (see system/artisan for the full contract);
| both entrypoints define it unconditionally. Raw + autoload-free so a
| half-synced vendor/ still answers cleanly. Path mirrors
| App\RSpade\Core\Framework\Framework_Maintenance::FLAG_RELATIVE.
|
*/

$__rsx_maint_flag = $__rsx_storage . '/rsx-framework/.maintenance.mode.framework.update';
define('RSPADE_MAINT_MODE', file_exists($__rsx_maint_flag));

if (RSPADE_MAINT_MODE) {
    // The flag is TWO LINES: line 1 = the operator reason, line 2 = "mode=<app mode>"
    // stamped by the writer. See App\RSpade\Core\Framework\Framework_Maintenance - this
    // runs pre-autoload and cannot call it, so the format is replicated literally.
    $__rsx_maint_lines = preg_split('/\R/', (string) @file_get_contents($__rsx_maint_flag));
    $__rsx_maint_reason = trim($__rsx_maint_lines[0] ?? '');
    if ($__rsx_maint_reason === '') {
        $__rsx_maint_reason = 'maintenance mode';
    }

    $__rsx_maint_stamp = trim($__rsx_maint_lines[1] ?? '');
    $__rsx_maint_mode = str_starts_with($__rsx_maint_stamp, 'mode=')
        ? trim(substr($__rsx_maint_stamp, 5))
        : '';

    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/plain; charset=UTF-8');
    echo "503 Service Unavailable - RSpade is in maintenance mode ({$__rsx_maint_reason}). Please retry shortly.\n";

    // "Retry shortly" is WRONG ADVICE for a stale flag - an interrupted update, a killed
    // maintenance script - where no retry will ever succeed. On a non-production box, name
    // the one command that fixes it. Production says nothing extra, and a flag carrying no
    // stamp at all is treated as production: absence resolves to disclosing nothing.
    if ($__rsx_maint_mode !== '' && $__rsx_maint_mode !== 'production') {
        echo "\n";
        echo "[dev] If this persists, an interrupted update may have left the flag behind. Clear it with:\n";
        echo "      php artisan rsx:maintenance:disable\n";
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
|
| If the application is in maintenance / demo mode via the "down" command
| we will load this file so that any pre-rendered content can be shown
| instead of starting the framework, which could cause an exception.
|
*/

if (file_exists($maintenance = $__rsx_storage.'/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| this application. We just need to utilize it! We'll simply require it
| into the script here so we don't need to manually load our classes.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Initialize RSpade Framework
|--------------------------------------------------------------------------
|
| Acquire the global application read lock before anything else happens.
| This ensures proper coordination between processes for operations like
| manifest rebuilding. This MUST happen before the manifest loads.
|
*/

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request using
| the application's HTTP kernel. Then, we will send the response back
| to this client's browser, allowing them to enjoy our application.
|
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);
