<?php
/**
 * IDE Service Handler (Laravel-based)
 *
 * Handles all IDE service requests that require Laravel/Manifest access.
 * Format-only services use the standalone handler.php instead.
 *
 * This handler bootstraps Laravel to access Manifest functions directly,
 * eliminating duplicate logic and ensuring single source of truth.
 *
 * Authentication: Same localhost bypass logic as standalone handler
 * See handler.php for authentication documentation.
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Base path - framework is in system/ subdirectory
// @REALPATH-EXCEPTION - Bootstrap file: runs before helpers loaded, needs PHP's realpath()
$system_path = realpath(__DIR__ . '/../../../..');
// @REALPATH-EXCEPTION - Bootstrap file: runs before helpers loaded, needs PHP's realpath()
define('IDE_BASE_PATH', realpath($system_path . '/..'));  // Project root
define('IDE_SYSTEM_PATH', $system_path);                  // Framework root

// Helper to get framework paths
function ide_framework_path($relative_path) {
    return IDE_SYSTEM_PATH . '/' . ltrim($relative_path, '/');
}

// Bootstrap Laravel
require_once IDE_SYSTEM_PATH . '/vendor/autoload.php';
$app = require_once IDE_SYSTEM_PATH . '/bootstrap/app.php';

// We don't need to handle HTTP requests through Laravel's kernel
// Just boot the application so we can use Manifest
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// JSON response helper
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Error response helper
function error_response($message, $code = 400) {
    json_response(['success' => false, 'error' => $message], $code);
}

// Authentication already handled by auth.php before this file is loaded
// Retrieve auth data from constants set by auth.php
if (!defined('IDE_AUTH_PASSED')) {
    error_response('Authentication check did not run - this should never happen', 500);
}

$auth_data = json_decode(IDE_AUTH_DATA, true);

// Parse request URI to get service
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$service_path = str_replace('/_ide/service', '', $request_uri);
$service_path = trim($service_path, '/');

// Get request body
$request_body = file_get_contents('php://input');
$request_data = json_decode($request_body, true);

// Merge GET parameters (for backward compatibility)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $request_data = array_merge($request_data ?? [], $_GET);
}

// Route to appropriate service handler
// This handler provides Laravel-based services (can access Manifest, models, etc.)
// Format service is handled by handler.php (standalone, no Laravel)
switch ($service_path) {
    case 'manifest_build':
        handle_manifest_build_service($request_data);
        break;

    case 'js_is_subclass_of':
        handle_js_is_subclass_of_service($request_data);
        break;

    case 'php_is_subclass_of':
        handle_php_is_subclass_of_service($request_data);
        break;

    case 'resolve_class':
        // TODO: Migrate from handler.php and refactor to use Manifest directly
        error_response('Service not yet migrated to Laravel handler', 501);
        break;

    case 'definition':
        // TODO: Migrate from handler.php and refactor to use Manifest directly
        error_response('Service not yet migrated to Laravel handler', 501);
        break;

    case 'complete':
        // TODO: Migrate from handler.php and refactor to use Manifest directly
        error_response('Service not yet migrated to Laravel handler', 501);
        break;

    case 'exec':
    case 'command':
        // TODO: Migrate from handler.php
        error_response('Service not yet migrated to Laravel handler', 501);
        break;

    // git and git/diff services are handled by handler.php (standalone, no Laravel needed)

    case 'js_lineage':
        // TODO: Migrate from handler.php - or remove if js_is_subclass_of replaces it
        error_response('Service not yet migrated to Laravel handler', 501);
        break;

    case 'resolve_url':
        // TODO: Migrate from handler.php and refactor to use Manifest directly
        error_response('Service not yet migrated to Laravel handler', 501);
        break;

    case 'health':
        json_response(['success' => true, 'service' => 'ide-laravel', 'version' => '1.0.0']);
        break;

    default:
        error_response('Unknown service: ' . $service_path, 404);
}

/**
 * Handle manifest_build service - trigger incremental manifest update
 *
 * Calls Manifest::init() which performs incremental update if needed.
 * Does NOT clear the manifest, just updates changed files.
 */
function handle_manifest_build_service($data) {
    try {
        // Manifest::init() will do incremental update if needed
        \App\RSpade\Core\Manifest\Manifest::init();
        json_response(['success' => true]);
    } catch (\Exception $e) {
        // Return error but VS Code extension will suppress user notification
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * Handle js_is_subclass_of service - check if JS class extends another
 *
 * Direct RPC wrapper around Manifest::js_is_subclass_of()
 */
function handle_js_is_subclass_of_service($data) {
    $subclass = $data['subclass'] ?? null;
    $superclass = $data['superclass'] ?? null;

    if (!$subclass || !$superclass) {
        json_response([
            'error' => 'Missing required parameters: subclass and superclass',
        ], 400);
    }

    try {
        $is_subclass = \App\RSpade\Core\Manifest\Manifest::js_is_subclass_of($subclass, $superclass);
        json_response(['is_subclass' => $is_subclass]);
    } catch (\Exception $e) {
        error_response('Manifest error: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle php_is_subclass_of service - check if PHP class extends another
 *
 * Direct RPC wrapper around Manifest::php_is_subclass_of()
 */
function handle_php_is_subclass_of_service($data) {
    $subclass = $data['subclass'] ?? null;
    $superclass = $data['superclass'] ?? null;

    if (!$subclass || !$superclass) {
        json_response([
            'error' => 'Missing required parameters: subclass and superclass',
        ], 400);
    }

    try {
        $is_subclass = \App\RSpade\Core\Manifest\Manifest::php_is_subclass_of($subclass, $superclass);
        json_response(['is_subclass' => $is_subclass]);
    } catch (\Exception $e) {
        error_response('Manifest error: ' . $e->getMessage(), 500);
    }
}
