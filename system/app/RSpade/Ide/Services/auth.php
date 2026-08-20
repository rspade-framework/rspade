<?php
/**
 * IDE Service Authentication
 *
 * SECURITY-CRITICAL: runs BEFORE any IDE service logic AND before Laravel boots
 * (invoked from public/index.php ahead of the autoloader), so it is plain PHP -
 * no framework classes, no config(). Every /_ide/service/* request passes here.
 *
 * Model: LOCAL-FILE GRANT. The framework (dev only) writes a single
 * ide-grant-<random>.token file into the bridge dir, mode-restricted and OUTSIDE
 * the web docroot. A legitimate co-located IDE reads that file from local disk and
 * presents its contents in the X-Ide-Token header; we verify it constant-time
 * (hash_equals). Possession proves local read access - which is the grant. The
 * secret NEVER crosses the wire except as this bearer over TLS. There is NO
 * network endpoint that mints or returns a token.
 *
 * Two accepted paths:
 * 1. X-Ide-Token matches the on-disk grant token (the normal path, remote or local).
 * 2. Strict loopback bypass (defense-in-depth for pure-local http://localhost dev):
 *    host 'localhost' + http + loopback REMOTE_ADDR + no X-* proxy headers + not
 *    production. Never the sole gate.
 *
 * Hard gates first: IDE services are refused entirely in production unless
 * RSX_IDE_SERVICES_ENABLED=true, and refused anywhere RSX_IDE_SERVICES_ENABLED=false
 * is set.
 */

// Base path - framework is in system/ subdirectory
// @REALPATH-EXCEPTION - Bootstrap file: runs before helpers loaded, needs PHP's realpath()
$system_path = realpath(__DIR__ . '/../../../..');
// @REALPATH-EXCEPTION - Bootstrap file: runs before helpers loaded, needs PHP's realpath()
define('IDE_AUTH_BASE_PATH', realpath($system_path . '/..'));  // Project root
define('IDE_AUTH_SYSTEM_PATH', $system_path);                  // Framework root

// Helper to get framework paths
function ide_auth_framework_path($relative_path) {
    return IDE_AUTH_SYSTEM_PATH . '/' . ltrim($relative_path, '/');
}

// Volatile storage root: <project>/storage once the relocation marker exists (see
// bin/environment_updates/030_relocate_storage.sh), historic system/storage until then.
// This gate runs WITHOUT Laravel, so the marker is read directly.
function ide_auth_storage_path($relative_path = '') {
    $base = is_file(IDE_AUTH_BASE_PATH . '/storage/.rspade_storage_relocated')
        ? IDE_AUTH_BASE_PATH . '/storage'
        : IDE_AUTH_SYSTEM_PATH . '/storage';
    return $relative_path ? $base . '/' . ltrim($relative_path, '/') : $base;
}

// JSON response helper
function ide_auth_json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Error response helper
function ide_auth_error_response($message, $code = 400) {
    ide_auth_json_response(['success' => false, 'error' => $message], $code);
}

// Strip surrounding whitespace/CR and one layer of matching quotes from a raw value.
function ide_auth_env_normalize($value) {
    $value = trim((string) $value);
    if (strlen($value) >= 2) {
        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
            $value = trim(substr($value, 1, -1));
        }
    }

    return $value;
}

// Resolve an environment key the way a pre-boot gate must: the PROCESS environment
// wins (a container or vhost can supply APP_ENV without touching .env), then the
// .env file parsed line by line - optional "export ", optional single/double quotes,
// surrounding whitespace and CRLF endings all normalize away. Returns null when the
// key is set nowhere.
function ide_auth_env($key, $env_content) {
    $process_value = getenv($key);
    if ($process_value !== false) {
        return ide_auth_env_normalize($process_value);
    }

    foreach (explode("\n", $env_content) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }

        $separator = strpos($line, '=');
        if ($separator === false || trim(substr($line, 0, $separator)) !== $key) {
            continue;
        }

        return ide_auth_env_normalize(substr($line, $separator + 1));
    }

    return null;
}

// Master switch + production hard-off (resolved pre-boot, no config()).
$env_file = IDE_AUTH_BASE_PATH . '/.env';
$env_content = file_exists($env_file) ? file_get_contents($env_file) : '';

$ide_services_flag = strtolower((string) ide_auth_env('RSX_IDE_SERVICES_ENABLED', $env_content));
$is_production = strtolower((string) ide_auth_env('APP_ENV', $env_content)) === 'production';

// Explicit kill switch: RSX_IDE_SERVICES_ENABLED=false refuses the bridge entirely,
// in any mode (belt to the dev-only token creation on the framework side).
if ($ide_services_flag === 'false') {
    ide_auth_error_response('IDE services disabled', 403);
}

// Production requires an explicit opt-in.
if ($is_production && $ide_services_flag !== 'true') {
    ide_auth_error_response('IDE services disabled in production', 403);
}

// Parse request URI to get service
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$service_path = str_replace('/_ide/service', '', $request_uri);
$service_path = trim($service_path, '/');

// Authentication data populated by whichever grant path succeeds below.
$auth_data = [];

// Localhost bypass for IDE integration
$is_localhost_bypass = false;
$request_host = $_SERVER['HTTP_HOST'] ?? '';
$request_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';

// Any X-* header (proxy forwarding, or a token-bearing request that should use the
// grant path instead) disqualifies the pure-loopback bypass.
$has_proxy_headers = false;
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_X_')) {
        $has_proxy_headers = true;
        break;
    }
}

// Check if request is from loopback
$is_loopback_ip = (
    !$has_proxy_headers &&
    ($remote_addr === '127.0.0.1' ||
     $remote_addr === '::1' ||
     str_starts_with($remote_addr, '127.'))
);

// Not in production (production is handled by the hard-off gate above).
$is_not_production = !$is_production;

// All conditions must be true for bypass
if (
    $request_host === 'localhost' &&
    $request_scheme === 'http' &&
    $is_loopback_ip &&
    $is_not_production
) {
    $is_localhost_bypass = true;
    $auth_data = ['session' => 'localhost-bypass'];
}

if (!$is_localhost_bypass) {
    // Local-file grant: the caller must present the contents of the on-disk
    // ide-grant-<random>.token file. Only a process with local read access to the
    // (docroot-excluded, mode-restricted) bridge dir could have obtained it, so
    // possession is the grant. Verified constant-time; fail closed.
    $presented = trim((string) ($_SERVER['HTTP_X_IDE_TOKEN'] ?? ''));
    if ($presented === '') {
        ide_auth_error_response('Authentication required', 401);
    }

    $bridge_dir = ide_auth_storage_path('rsx-ide-bridge');
    $token_files = glob($bridge_dir . '/ide-grant-*.token') ?: [];
    if (empty($token_files)) {
        ide_auth_error_response('IDE bridge grant not established', 401);
    }

    $grant_ok = false;
    foreach ($token_files as $token_file) {
        $secret = trim((string) file_get_contents($token_file));
        if ($secret !== '' && hash_equals($secret, $presented)) {
            $grant_ok = true;
            break;
        }
    }
    if (!$grant_ok) {
        ide_auth_error_response('Invalid IDE token', 401);
    }

    $auth_data = ['session' => 'file-grant'];
}

// Authentication passed - store auth data for handlers to use if needed
define('IDE_AUTH_PASSED', true);
define('IDE_AUTH_DATA', json_encode($auth_data));
define('IDE_AUTH_IS_LOCALHOST_BYPASS', $is_localhost_bypass);

// Suppress console_debug output for IDE service requests
// These are programmatic API calls, not user-facing pages
define('SUPPRESS_CONSOLE_DEBUG_OUTPUT', true);
