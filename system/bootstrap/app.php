<?php

/*
|--------------------------------------------------------------------------
| Block Laravel VSCode Extension Discovery Scripts
|--------------------------------------------------------------------------
|
| The official Laravel VSCode extension creates discovery scripts in
| vendor/_laravel_ide/ which bootstrap the application. We block these
| as they conflict with RSpade framework's initialization.
|
*/

if (php_sapi_name() === 'cli') {
    $script = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    if (strpos($script, 'vendor/_laravel_ide/') !== false ||
        strpos($script, 'vendor\\_laravel_ide\\') !== false) {
        exit(0); // Exit silently
    }
}

/*
|--------------------------------------------------------------------------
| Docker-Only Enforcement for Development Mode
|--------------------------------------------------------------------------
|
| Ensure the framework is only executed within the Docker container
| when running in development mode. This prevents accidental execution
| of framework code on developer machines for security reasons.
|
*/

$app_env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
$base_path = realpath($_ENV['APP_BASE_PATH'] ?? dirname(__DIR__));

// App-layer composer autoloader (project root). Downstream applications may
// install their own composer packages at the project root (rsx:composer);
// chained AFTER the framework's own autoloader so framework packages always
// win class-name collisions. Absent file = app has no dependencies of its own.
$app_layer_autoload = dirname($base_path) . '/vendor/autoload.php';
if (file_exists($app_layer_autoload)) {
    require $app_layer_autoload;
}

// Check for spaces in base path (critical issue for any environment)
if (str_contains($base_path, ' ')) {
    $error_message = "CRITICAL ERROR: The application base path contains spaces.\n\n";
    $error_message .= "Current path: {$base_path}\n\n";
    $error_message .= "Spaces in file paths cause numerous issues:\n";
    $error_message .= "- Shell commands break without proper quoting\n";
    $error_message .= "- Git operations may fail\n";
    $error_message .= "- Build tools and npm scripts often malfunction\n";
    $error_message .= "- URLs and file references become problematic\n\n";
    $error_message .= "Solution: Move the project to a path without spaces.\n";
    $error_message .= "Example: /var/www/html or /home/user/projects/rspade\n";

    $sapi = php_sapi_name();
    $is_cli = ($sapi === 'cli' || $sapi === 'cli-server');

    if ($is_cli) {
        fwrite(STDERR, $error_message);
        exit(1);
    }
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: text/plain');
    echo $error_message;
    exit(1);
}

if ($app_env !== 'production' && $app_env !== 'prod') {
    // Check if we're not in the expected Docker environment
    // Allow root OR devuser (UID 1000) when /docker exists (autonomous Claude mode)
    /** @phpstan-ignore-next-line */
    $is_root = posix_getuid() === 0 || posix_geteuid() === 0;
    $is_devuser = posix_getuid() === 1000 && trim(shell_exec('whoami') ?? '') === 'devuser' && is_dir('/docker');
    $is_docker = ($base_path === '/var/www/html' || $base_path === '/var/www/html/system') && ($is_root || $is_devuser);

    if (!$is_docker) {
        // Determine if this is a CLI or web request
        $sapi = php_sapi_name();
        $is_cli = ($sapi === 'cli' || $sapi === 'cli-server');

        $error_message = "SECURITY ERROR: The RSX framework must be run from within the Docker container in development mode.\n\n";
        $error_message .= "Environment: {$app_env}\n";
        $error_message .= "Base path: {$base_path} (expected: /var/www/html or /var/www/html/system)\n";
        /** @phpstan-ignore-next-line */
        $error_message .= 'User ID: ' . posix_getuid() . " (expected: 0/root)\n";
        $error_message .= "SAPI: {$sapi}\n\n";

        if ($is_cli) {
            $error_message .= "This CLI request was blocked for security reasons.\n";
            $error_message .= "Please run commands from within the Docker container.\n";
            fwrite(STDERR, $error_message);
            exit(1);
        }
        $error_message .= "This web request was blocked for security reasons.\n";
        $error_message .= "Please access the application through the Docker container.\n";
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain');
        echo $error_message;
        exit(1);
    }
}

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/
/** @phpstan-ignore-next-line */
$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

// RSpade storage relocation bridge: volatile storage lives at <project>/storage once the
// relocation marker exists (written by bin/environment_updates/030_relocate_storage.sh);
// until then fall back to the historic system/storage. TRANSITIONAL - removed once the
// fleet has migrated. dirname(__DIR__, 2) = the project root (system/bootstrap -> up 2).
// Every storage_path() consumer follows from here; nothing else needs per-call edits.
$rsx_storage_root = dirname(__DIR__, 2) . '/storage';
if (is_file($rsx_storage_root . '/.rspade_storage_relocated')) {
    $app->useStoragePath($rsx_storage_root);
} else {
    $rsx_storage_root = ($_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)) . '/storage';
}

/*
|--------------------------------------------------------------------------
| Resolve APP_URL ($HOSTNAME token)
|--------------------------------------------------------------------------
|
| APP_URL is the single source of the application hostname. afterLoadingEnvironment
| runs after phpdotenv loads .env and BEFORE LoadConfiguration reads env('APP_URL'),
| so substituting the $HOSTNAME token (-> OS hostname) here means every consumer -
| config('app.url'), url(), filesystem disks, OAuth redirects - sees the resolved
| value. https enforcement is NOT here: it is deferred to
| Rsx_Framework_Provider::boot() so the throw renders readably (HandleExceptions
| has not bootstrapped yet at this phase). The composer autoloader is required by
| artisan/index.php before this file, so the class resolves. See
| App\RSpade\Core\Env\Rsx_App_Url.
|
*/

$app->afterLoadingEnvironment(function () {
    App\RSpade\Core\Env\Rsx_App_Url::patch_environment();
});

/*
|--------------------------------------------------------------------------
| Ensure Storage Directory Structure Exists
|--------------------------------------------------------------------------
|
| Create the required storage directory structure if it doesn't exist.
| This ensures the application can run even if storage is gitignored.
|
*/

// Paths are relative to the RESOLVED storage root ($rsx_storage_root above), never to
// base_path() - otherwise a relocated install would silently recreate system/storage.
$storageDirs = [
    '',
    'app',
    'app/public',
    'app/temp',
    'db_backups',
    'framework',
    'framework/cache',
    'framework/cache/data',
    'framework/sessions',
    'framework/testing',
    'framework/views',
    'logs',
    'rsx-build',
    'rsx-build/bundles',
    'rsx-build/js-stubs',
    'rsx-tmp',
    'rsx-tmp/cache',
    'rsx-tmp/jqhtml-cache',
    'rsx-tmp/npm-cache',
    'rsx-locks',
];

foreach ($storageDirs as $dir) {
    $fullPath = $dir === '' ? $rsx_storage_root : $rsx_storage_root . '/' . $dir;
    if (!is_dir($fullPath)) {
        @mkdir($fullPath, 0755, true);
    }
}

// Create .gitignore files in storage subdirectories to ensure structure
$gitignoreContent = "*\n!.gitignore\n";
$gitignoreDirs = [
    'app/public',
    'app/temp',
    'framework/cache/data',
    'framework/sessions',
    'framework/views',
    'logs',
];

foreach ($gitignoreDirs as $dir) {
    $gitignorePath = $rsx_storage_root . '/' . $dir . '/.gitignore';
    if (!file_exists($gitignorePath)) {
        @file_put_contents($gitignorePath, $gitignoreContent);
    }
}

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    /** @phpstan-ignore-next-line */
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    /** @phpstan-ignore-next-line */
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

/*
|--------------------------------------------------------------------------
| RSX Exception Handler (DO NOT MODIFY)
|--------------------------------------------------------------------------
|
| This registers the RSX framework's extensible exception handler system.
| DO NOT modify this registration - it should remain pointed at
| Rsx_ExceptionHandler in all RSX projects.
|
| TO ADD CUSTOM EXCEPTION HANDLERS:
| Register them in config/rsx.php under 'exception_handlers' array.
|
| EXAMPLE:
| 'exception_handlers' => [
|     \App\MyApp\My_Custom_ExceptionHandler::class,  // Your handler
|     \App\RSpade\Core\Exceptions\Cli_ExceptionHandler::class,
|     // ... framework handlers
| ]
|
| FOR MORE INFORMATION:
| - Base class: app/RSpade/Core/Exceptions/Rsx_Exception_Handler_Abstract.php
| - Main handler: app/RSpade/Core/Exceptions/Rsx_Exception_Handler.php
| - Config: config/rsx.php (search for 'exception_handlers')
| - Example handlers:
|   - app/RSpade/Core/Exceptions/Cli_Exception_Handler.php
|   - app/RSpade/Core/Exceptions/Ajax_Exception_Handler.php
|   - app/RSpade/Core/Debug/Playwright_Exception_Handler.php
|   - app/RSpade/Core/Providers/Rsx_Dispatch_Bootstrapper_Handler.php
|
*/

$app->singleton(
    /** @phpstan-ignore-next-line */
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\RSpade\Core\Exceptions\Rsx_Exception_Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
