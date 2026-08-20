<?php
/**
 * IDE Service Handler
 *
 * Handles lightweight IDE service requests bypassing Laravel for performance.
 * Authentication is enforced by auth.php BEFORE this file is loaded (local-file
 * grant: the caller presents the content of the on-disk ide-grant-*.token file,
 * or rides the strict loopback bypass). See auth.php for the full model.
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

// Volatile storage root. Relocated to <project>/storage once the relocation marker
// exists (bin/environment_updates/030_relocate_storage.sh); historic system/storage
// until then. This handler runs WITHOUT Laravel, so the marker is read directly.
define('IDE_STORAGE_PATH',
    is_file(IDE_BASE_PATH . '/storage/.rspade_storage_relocated')
        ? IDE_BASE_PATH . '/storage'
        : IDE_SYSTEM_PATH . '/storage');

// Define the Laravel helper this standalone handler needs
// This handler never runs with Laravel, so no conflict
function storage_path($path = '') {
    $base = IDE_STORAGE_PATH;
    return $path ? $base . '/' . ltrim($path, '/') : $base;
}

// Load exec_safe() function
require_once ide_framework_path('app/RSpade/helpers.php');

// Helper to normalize file paths for IDE consumption
// Framework files (not starting with rsx/) need system/ prefix
function normalize_ide_path($path) {
    if (!$path || str_starts_with($path, 'rsx/')) {
        return $path;
    }
    return 'system/' . $path;
}

// Helper to recursively normalize all 'file' keys in response data
function normalize_response_paths($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if ($key === 'file' && is_string($value)) {
                $data[$key] = normalize_ide_path($value);
            } elseif (is_array($value)) {
                $data[$key] = normalize_response_paths($value);
            }
        }
    }
    return $data;
}

// JSON response helper
function json_response($data, $code = 200) {
    // Normalize all file paths in response for IDE consumption
    $data = normalize_response_paths($data);

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

// Merge GET parameters (for backward compatibility with /_idehelper endpoints)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $request_data = array_merge($request_data ?? [], $_GET);
}

// Route to appropriate service handler
// This handler handles lightweight services that don't need Laravel bootstrap
// All services requiring Manifest/Laravel are handled by laravel_handler.php
switch ($service_path) {
    case 'format':
        handle_format_service($request_data);
        break;

    case 'definition':
        handle_definition_service($request_data);
        break;

    case 'complete':
        handle_complete_service($request_data);
        break;

    case 'resolve_class':
        handle_resolve_class_service($request_data);
        break;

    case 'git':
        handle_git_service($request_data);
        break;

    case 'git/diff':
        handle_git_diff_service($request_data);
        break;

    case 'refactor':
        handle_refactor_service($request_data);
        break;

    case 'health':
        json_response(['success' => true, 'service' => 'ide-standalone', 'version' => '1.0.0']);
        break;

    default:
        error_response('Unknown service: ' . $service_path . ' (standalone handler supports: format, definition, complete, resolve_class, git, git/diff, refactor)', 404);
}

/**
 * Handle format service - formats PHP files
 */
function handle_format_service($data) {
    $file = $data['file'] ?? null;

    if (!$file) {
        error_response('No file provided');
    }

    // Normalize path separators (Windows sends backslashes, convert to forward slashes)
    $file = str_replace('\\', '/', $file);

    // Build full path
    $full_path = IDE_BASE_PATH . '/' . ltrim($file, '/');

    // Normalize path without requiring file to exist (for .formatting.tmp files)
    // Use realpath on the directory, then append the filename
    $dir = dirname($full_path);
    $filename = basename($full_path);

    // Get real directory path
    if (is_dir($dir)) {
        // @REALPATH-EXCEPTION - IDE service: runs standalone without framework helpers
        $real_dir = realpath($dir);
        if ($real_dir) {
            $full_path = $real_dir . '/' . $filename;
        }
    }

    // Security check - path must be within project
    if (strpos($full_path, IDE_BASE_PATH) !== 0) {
        error_response('Invalid file path - must be within project');
    }

    if (!file_exists($full_path)) {
        error_response('File not found', 404);
    }

    // Execute formatter
    $formatter_path = ide_framework_path('bin/rsx-format');
    if (!file_exists($formatter_path)) {
        error_response('Formatter not found', 500);
    }

    // Log formatting request
    $log_file = storage_path('logs/ide-formatter.log');
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    $log_entry = sprintf(
        "[%s] Formatting file: %s\n  Full path: %s\n  CWD before: %s\n  File exists: %s\n  File size: %d bytes\n",
        date('Y-m-d H:i:s'),
        $file,
        $full_path,
        getcwd(),
        file_exists($full_path) ? 'yes' : 'no',
        file_exists($full_path) ? filesize($full_path) : 0
    );
    file_put_contents($log_file, $log_entry, FILE_APPEND);

    // Change to project root so formatter can find config files
    $old_cwd = getcwd();
    chdir(IDE_BASE_PATH);

    $command = sprintf('php %s %s 2>&1',
        escapeshellarg($formatter_path),
        escapeshellarg($full_path)
    );

    file_put_contents($log_file, "  CWD after chdir: " . getcwd() . "\n  Command: $command\n", FILE_APPEND);

    $output = [];
    $return_var = 0;
    \exec_safe($command, $output, $return_var);

    // Restore working directory
    chdir($old_cwd);

    // Log result
    file_put_contents($log_file, sprintf(
        "  Return code: %d\n  Output lines: %d\n  Output: %s\n  File size after: %d bytes\n\n",
        $return_var,
        count($output),
        implode("\n    ", $output),
        file_exists($full_path) ? filesize($full_path) : 0
    ), FILE_APPEND);

    if ($return_var === 0) {
        // Read formatted content if requested
        $content = null;
        if (!empty($data['return_content'])) {
            $content = file_get_contents($full_path);
        }

        json_response([
            'success' => true,
            'message' => 'Formatting completed',
            'content' => $content
        ]);
    } else {
        error_response('Formatting failed: ' . implode("\n", $output), 500);
    }
}

/**
 * Handle definition service - find symbol definitions
 * Supports all the types from the VS Code definition provider
 */
function handle_definition_service($data) {
    $identifier = $data['identifier'] ?? $data['symbol'] ?? null;
    $method = $data['method'] ?? null;
    $type = $data['type'] ?? null;

    if (!$identifier) {
        error_response('No identifier provided');
    }

    // Load manifest data
    $manifest_file = storage_path('rsx-build/manifest_data.php');
    if (!file_exists($manifest_file)) {
        error_response('Manifest not found - run php artisan rsx:manifest:build', 500);
    }

    $manifest_raw = include $manifest_file;

    // The manifest structure has the data under 'data' key
    $manifest = $manifest_raw['data'] ?? $manifest_raw;

    // Search based on type
    $result = null;

    switch ($type) {
        case 'class':
            // Search PHP classes with optional method
            if (isset($manifest['php']['classes'][$identifier])) {
                $class_data = $manifest['php']['classes'][$identifier];
                $file = $class_data['file'];
                $line = $class_data['line'] ?? 1;

                // If method specified, try to find method line number
                if ($method && isset($class_data['methods'][$method])) {
                    $line = $class_data['methods'][$method]['line'] ?? $line;
                }

                $result = [
                    'found' => true,
                    'file' => $file,
                    'line' => $line
                ];
            }
            break;

        case 'view':
            // Search Blade views by name or rsx_id
            foreach ($manifest['blade']['views'] ?? [] as $view) {
                if ($view['name'] === $identifier || $view['rsx_id'] === $identifier) {
                    $result = [
                        'found' => true,
                        'file' => $view['file'],
                        'line' => 1
                    ];
                    break;
                }
            }
            break;

        case 'bundle_alias':
            // Search bundle aliases defined in config/rsx.php
            $config_file = ide_framework_path('config/rsx.php');
            if (file_exists($config_file)) {
                $config = include $config_file;
                if (isset($config['bundle_aliases'][$identifier])) {
                    $bundle_class = $config['bundle_aliases'][$identifier];
                    // Extract class name from namespace
                    $class_name = str_replace('\\', '_', str_replace('App\\RSpade\\', '', $bundle_class));

                    // Find the bundle class file
                    if (isset($manifest['php']['classes'][$class_name])) {
                        $result = [
                            'found' => true,
                            'file' => $manifest['php']['classes'][$class_name]['file'],
                            'line' => $manifest['php']['classes'][$class_name]['line'] ?? 1
                        ];
                    }
                }
            }
            break;

        case 'jqhtml_template':
            // Search for .jqhtml template files
            foreach ($manifest['jqhtml']['components'] ?? [] as $component) {
                if ($component['name'] === $identifier) {
                    $result = [
                        'found' => true,
                        'file' => $component['template_file'],
                        'line' => 1
                    ];
                    break;
                }
            }
            break;

        case 'jqhtml_class':
            // Search for JavaScript class extending Component
            foreach ($manifest['jqhtml']['components'] ?? [] as $component) {
                if ($component['name'] === $identifier && isset($component['js_file'])) {
                    $result = [
                        'found' => true,
                        'file' => $component['js_file'],
                        'line' => $component['js_line'] ?? 1
                    ];
                    break;
                }
            }
            break;

        case 'jqhtml_class_method':
            // Search for method in jqhtml component JavaScript class
            foreach ($manifest['jqhtml']['components'] ?? [] as $component) {
                if ($component['name'] === $identifier && isset($component['js_file'])) {
                    $js_file = IDE_BASE_PATH . '/' . $component['js_file'];
                    $line = 1;

                    // Try to find the method line number
                    if ($method && file_exists($js_file)) {
                        $content = file_get_contents($js_file);
                        $lines = explode("\n", $content);
                        foreach ($lines as $index => $line_content) {
                            // Look for method definition patterns
                            if (preg_match('/^\s*' . preg_quote($method) . '\s*\(/', $line_content) ||
                                preg_match('/^\s*async\s+' . preg_quote($method) . '\s*\(/', $line_content) ||
                                preg_match('/^\s*static\s+' . preg_quote($method) . '\s*\(/', $line_content)) {
                                $line = $index + 1;
                                break;
                            }
                        }
                    }

                    $result = [
                        'found' => true,
                        'file' => $component['js_file'],
                        'line' => $line
                    ];
                    break;
                }
            }
            break;

        case 'route':
            // Search routes for controller::method
            foreach ($manifest['php']['routes'] ?? [] as $route) {
                if ($route['class'] . '::' . $route['method'] === $identifier) {
                    $result = [
                        'found' => true,
                        'file' => $route['file'],
                        'line' => $route['line'] ?? 1
                    ];
                    break;
                }
            }
            break;

        default:
            // Try to auto-detect type
            // First try as class
            if (isset($manifest['php']['classes'][$identifier])) {
                $class_data = $manifest['php']['classes'][$identifier];
                $result = [
                    'found' => true,
                    'file' => $class_data['file'],
                    'line' => $class_data['line'] ?? 1
                ];
            }
            // Try as view
            if (!$result) {
                foreach ($manifest['blade']['views'] ?? [] as $view) {
                    if ($view['name'] === $identifier || $view['rsx_id'] === $identifier) {
                        $result = [
                            'found' => true,
                            'file' => $view['file'],
                            'line' => 1
                        ];
                        break;
                    }
                }
            }
            // Try as jqhtml component
            if (!$result) {
                foreach ($manifest['jqhtml']['components'] ?? [] as $component) {
                    if ($component['name'] === $identifier) {
                        $result = [
                            'found' => true,
                            'file' => $component['template_file'] ?? $component['js_file'],
                            'line' => 1
                        ];
                        break;
                    }
                }
            }
    }

    if ($result) {
        json_response($result);
    } else {
        json_response(['found' => false]);
    }
}

/**
 * Handle complete service - provide autocomplete suggestions
 */
function handle_complete_service($data) {
    $prefix = $data['prefix'] ?? '';
    $context = $data['context'] ?? null;

    // Load manifest data
    $manifest_file = storage_path('rsx-build/manifest_data.php');
    if (!file_exists($manifest_file)) {
        error_response('Manifest not found', 500);
    }

    $manifest = include $manifest_file;
    $suggestions = [];

    // Search PHP classes
    foreach ($manifest['php']['classes'] ?? [] as $class_name => $class_data) {
        if (stripos($class_name, $prefix) === 0) {
            $suggestions[] = [
                'label' => $class_name,
                'kind' => 'class',
                'detail' => $class_data['file']
            ];
        }
    }

    // Limit results
    $suggestions = array_slice($suggestions, 0, 100);

    json_response([
        'success' => true,
        'suggestions' => $suggestions
    ]);
}

/**
 * Handle git service - get git status
 */
function handle_git_service($data) {
    $output = [];
    $return_var = 0;

    \exec_safe('cd ' . escapeshellarg(IDE_BASE_PATH) . ' && git status --porcelain 2>&1', $output, $return_var);

    if ($return_var !== 0) {
        error_response('Git command failed: ' . implode("\n", $output), 500);
    }

    $modified = [];
    $added = [];
    $conflicts = [];

    foreach ($output as $line) {
        if (strlen($line) < 4) continue;

        $status = substr($line, 0, 2);
        $file = trim(substr($line, 3));

        // Parse git status codes
        if (str_contains($status, 'U') || ($status[0] === 'A' && $status[1] === 'A')) {
            $conflicts[] = $file;
        } elseif ($status[0] === 'A' || $status[1] === 'A' || $status === '??') {
            $added[] = $file;
        } elseif ($status[0] === 'M' || $status[1] === 'M' || $status[0] === 'R') {
            $modified[] = $file;
        }
    }

    json_response([
        'success' => true,
        'modified' => $modified,
        'added' => $added,
        'conflicts' => $conflicts
    ]);
}

/**
 * Handle git diff service - get line-level changes for a file
 */
function handle_git_diff_service($data) {
    $file = $data['file'] ?? null;

    if (!$file) {
        error_response('No file provided');
    }

    // Normalize path separators (Windows sends backslashes, convert to forward slashes)
    $file = str_replace('\\', '/', $file);

    // Security check - file must be within project
    $full_path = IDE_BASE_PATH . '/' . ltrim($file, '/');

    // Normalize path without requiring file to exist (handles deleted files in git)
    $dir = dirname($full_path);
    $filename = basename($full_path);

    if (is_dir($dir)) {
        // @REALPATH-EXCEPTION - IDE service: runs standalone without framework helpers
        $real_dir = realpath($dir);
        if ($real_dir) {
            $full_path = $real_dir . '/' . $filename;
        }
    }

    // Validate path is within project
    if (strpos($full_path, IDE_BASE_PATH) !== 0) {
        error_response('Invalid file path');
    }

    // Note: File existence not required for git diff (might be deleted)

    $output = [];
    $return_var = 0;

    // Get diff with line numbers, no context
    \exec_safe('cd ' . escapeshellarg(IDE_BASE_PATH) . ' && git diff HEAD --unified=0 ' . escapeshellarg($file) . ' 2>&1', $output, $return_var);

    if ($return_var !== 0) {
        error_response('Git diff command failed: ' . implode("\n", $output), 500);
    }

    $added = [];
    $modified = [];
    $deleted = [];

    foreach ($output as $line) {
        // Parse unified diff format: @@ -old_start,old_count +new_start,new_count @@
        if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
            $old_start = (int)$matches[1];
            $old_count = isset($matches[2]) ? (int)$matches[2] : 1;
            $new_start = (int)$matches[3];
            $new_count = isset($matches[4]) ? (int)$matches[4] : 1;

            // Deletion: old_count > 0, new_count = 0
            if ($old_count > 0 && $new_count === 0) {
                $deleted[] = [$new_start, $new_start];
            }
            // Addition: old_count = 0, new_count > 0
            elseif ($old_count === 0 && $new_count > 0) {
                $added[] = [$new_start, $new_start + $new_count - 1];
            }
            // Modification: both > 0
            else {
                $modified[] = [$new_start, $new_start + $new_count - 1];
            }
        }
    }

    json_response([
        'success' => true,
        'added' => $added,
        'modified' => $modified,
        'deleted' => $deleted
    ]);
}

/**
 * Handle refactor service - run ONE of the fixed rsx:refactor:* AST refactor commands
 * (the editor-driven rename/sort actions). This is the ONLY command-execution surface on
 * the IDE bridge, and it is deliberately narrow: the command string is EXACT-matched
 * against a hardcoded allowlist of three metacharacter-free literals, and every argument
 * is escapeshellarg'd individually, so no shell-injection vector exists. Reachable only
 * after the local-file grant check in auth.php.
 */
function handle_refactor_service($data) {
    $command = $data['command'] ?? null;
    $arguments = $data['arguments'] ?? [];

    if (!is_string($command) || $command === '') {
        error_response('No refactor command provided');
    }
    if (!is_array($arguments)) {
        error_response('Refactor arguments must be an array');
    }

    // Exact-match allowlist. These are fixed literals with no shell metacharacters, so a
    // matched command cannot carry an injection; anything not matching is rejected.
    $allowed_commands = [
        'rsx:refactor:rename_php_class',
        'rsx:refactor:rename_php_class_function',
        'rsx:refactor:sort_php_class_functions',
    ];
    if (!in_array($command, $allowed_commands, true)) {
        error_response('Refactor command not allowed: ' . $command, 403);
    }

    // argv: fixed 'php <artisan> <command>' + escapeshellarg'd args. The command is a
    // trusted literal; each argument is individually escaped -> no shell injection.
    $artisan_path = IDE_BASE_PATH . '/artisan';
    $parts = ['php', escapeshellarg($artisan_path), $command];
    foreach ($arguments as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }
    $full_command = implode(' ', $parts) . ' 2>&1';

    $old_cwd = getcwd();
    chdir(IDE_BASE_PATH);
    $output = [];
    $return_var = 0;
    \exec_safe($full_command, $output, $return_var);
    chdir($old_cwd);

    json_response([
        'success' => $return_var === 0,
        'output' => implode("\n", $output),
        'exit_code' => $return_var,
    ]);
}

/**
 * Try to resolve identifier as a PHP class
 */
function try_resolve_php_class($identifier, $method_name, $find_php_class) {
    $class_data = $find_php_class($identifier);

    if (!$class_data) {
        return null;
    }

    $file_path = $class_data['file'];
    $line_number = 1;
    $absolute_path = IDE_BASE_PATH . '/' . $file_path;

    if (file_exists($absolute_path)) {
        $content = file_get_contents($absolute_path);
        $lines = explode("\n", $content);

        if ($method_name) {
            // Check if method metadata exists
            if (isset($class_data['public_static_methods'][$method_name])) {
                $method_metadata = $class_data['public_static_methods'][$method_name];
                if (isset($method_metadata['line'])) {
                    $line_number = $method_metadata['line'];
                }
            } else if (isset($class_data['public_instance_methods'][$method_name])) {
                $method_metadata = $class_data['public_instance_methods'][$method_name];
                if (isset($method_metadata['line'])) {
                    $line_number = $method_metadata['line'];
                }
            }

            // If we don't have line number from metadata, search manually
            if ($line_number === 1 && !empty($lines)) {
                $in_class_or_trait = false;
                $brace_count = 0;

                foreach ($lines as $index => $line) {
                    if (preg_match('/^\s*(class|trait)\s+\w+(\s|$)/', $line)) {
                        $in_class_or_trait = true;
                    }

                    if ($in_class_or_trait) {
                        $brace_count += substr_count($line, '{');
                        $brace_count -= substr_count($line, '}');

                        if (preg_match('/^\s*(public|protected|private)?\s*(static\s+)?function\s+' . preg_quote($method_name, '/') . '\s*\(/', $line)) {
                            $line_number = $index + 1;
                            break;
                        }

                        if ($brace_count === 0 && strpos($line, '}') !== false) {
                            break;
                        }
                    }
                }
            }
        } else {
            // Find the line with the class definition
            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*class\s+' . preg_quote($identifier, '/') . '(\s|$)/', $line)) {
                    $line_number = $index + 1;
                    break;
                }
            }
        }
    }

    $response_data = [
        'found' => true,
        'type' => 'php_class',
        'file' => $file_path,
        'line' => $line_number,
        'metadata' => [
            'namespace' => $class_data['namespace'] ?? null,
            'extends' => $class_data['extends'] ?? null,
            'fqcn' => $class_data['fqcn'] ?? null,
        ],
    ];

    if ($method_name) {
        $response_data['method'] = $method_name;
    }

    return $response_data;
}

/**
 * Try to resolve identifier as a JavaScript class
 */
function try_resolve_js_class($identifier, $method_name, $files) {
    foreach ($files as $file_path => $file_data) {
        // Only check .js files (not .jqhtml)
        if (str_ends_with($file_path, '.js') && !str_ends_with($file_path, '.jqhtml')) {
            $absolute_path = IDE_BASE_PATH . '/' . $file_path;

            if (file_exists($absolute_path)) {
                $content = file_get_contents($absolute_path);

                // Check for JavaScript class (not specifically Component)
                if (preg_match('/class\s+' . preg_quote($identifier, '/') . '\s+extends\s+([A-Za-z_][A-Za-z0-9_]*)/', $content, $matches)) {
                    $lines = explode("\n", $content);
                    $line_number = 1;

                    foreach ($lines as $index => $line) {
                        if (preg_match('/class\s+' . preg_quote($identifier, '/') . '\s+extends/', $line)) {
                            $line_number = $index + 1;
                            break;
                        }
                    }

                    return [
                        'found' => true,
                        'type' => 'js_class',
                        'file' => $file_path,
                        'line' => $line_number,
                        'identifier' => $identifier,
                        'extends' => $matches[1] ?? null,
                    ];
                }
            }
        }
    }
    return null;
}

/**
 * Try to resolve identifier as a jqhtml component class
 *
 * Note: This resolver does NOT check inheritance chains. It's a simple file lookup.
 * VS Code extension should use js_is_subclass_of() RPC to determine if a class
 * is actually a Component before choosing this resolver type.
 */
function try_resolve_jqhtml_class($identifier, $method_name, $files) {
    // Load manifest to get js_classes index
    $manifest_file = storage_path('rsx-build/manifest_data.php');
    if (!file_exists($manifest_file)) {
        return null;
    }

    $manifest_raw = include $manifest_file;
    $manifest_data = $manifest_raw['data'] ?? $manifest_raw;
    $js_classes = $manifest_data['js_classes'] ?? [];

    // Check if this class exists in js_classes
    if (!isset($js_classes[$identifier])) {
        return null;
    }

    // Get the file path
    $file_path = $js_classes[$identifier];

    // Find the line number
    $absolute_path = IDE_BASE_PATH . '/' . $file_path;
    $line_number = 1;

    if (file_exists($absolute_path)) {
        $content = file_get_contents($absolute_path);
        $lines = explode("\n", $content);

        foreach ($lines as $index => $line) {
            if (preg_match('/class\s+' . preg_quote($identifier, '/') . '\s+extends/', $line)) {
                $line_number = $index + 1;
                break;
            }
        }
    }

    return [
        'found' => true,
        'type' => 'jqhtml_class',
        'file' => $file_path,
        'line' => $line_number,
        'identifier' => $identifier,
    ];
}

/**
 * Try to resolve identifier as a view
 */
function try_resolve_view($identifier, $find_view) {
    $view_data = $find_view($identifier);

    if (!$view_data) {
        return null;
    }

    $file_path = $view_data['file'];
    $line_number = 1;
    $absolute_path = IDE_BASE_PATH . '/' . $file_path;

    if (file_exists($absolute_path)) {
        $content = file_get_contents($absolute_path);
        $lines = explode("\n", $content);

        // Look for @rsx_id('identifier')
        foreach ($lines as $index => $line) {
            if (preg_match('/@rsx_id\s*\(\s*[\'"]' . preg_quote($identifier, '/') . '[\'"]\s*\)/', $line)) {
                $line_number = $index + 1;
                break;
            }
        }
    }

    return [
        'found' => true,
        'type' => 'view',
        'file' => $file_path,
        'line' => $line_number,
        'identifier' => $identifier,
    ];
}

/**
 * Try to resolve identifier as a bundle alias
 */
function try_resolve_bundle_alias($identifier, $find_php_class) {
    $config_path = IDE_SYSTEM_PATH . '/config/rsx.php';
    if (!file_exists($config_path)) {
        return null;
    }

    $config = include $config_path;
    if (!isset($config['bundle_aliases'][$identifier])) {
        return null;
    }

    $bundle_class = $config['bundle_aliases'][$identifier];
    $class_parts = explode('\\', $bundle_class);
    $class_name = end($class_parts);

    $class_data = $find_php_class($class_name);
    if (!$class_data) {
        return null;
    }

    $file_path = $class_data['file'];
    $line_number = 1;
    $absolute_path = IDE_BASE_PATH . '/' . $file_path;

    if (file_exists($absolute_path)) {
        $content = file_get_contents($absolute_path);
        $lines = explode("\n", $content);

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*class\s+' . preg_quote($class_name, '/') . '(\s|$)/', $line)) {
                $line_number = $index + 1;
                break;
            }
        }
    }

    return [
        'found' => true,
        'type' => 'bundle_alias',
        'file' => $file_path,
        'line' => $line_number,
        'identifier' => $identifier,
        'resolved_class' => $bundle_class,
    ];
}

/**
 * Try to resolve identifier as a jqhtml template
 */
function try_resolve_jqhtml_template($identifier, $files, $camel_to_snake, $snake_to_pascal) {
    $component_snake = $camel_to_snake($identifier);

    foreach ($files as $file_path => $file_data) {
        if (str_ends_with($file_path, '.jqhtml')) {
            $basename = basename($file_path, '.jqhtml');

            if ($basename === $component_snake || $snake_to_pascal($basename) === $identifier) {
                $line_number = 1;
                $absolute_path = IDE_BASE_PATH . '/' . $file_path;

                if (file_exists($absolute_path)) {
                    $content = file_get_contents($absolute_path);
                    $lines = explode("\n", $content);

                    foreach ($lines as $index => $line) {
                        if (preg_match('/<Define:\s*' . preg_quote($identifier, '/') . '/', $line)) {
                            $line_number = $index + 1;
                            break;
                        }
                    }
                }

                return [
                    'found' => true,
                    'type' => 'jqhtml_template',
                    'file' => $file_path,
                    'line' => $line_number,
                    'identifier' => $identifier,
                ];
            }
        }
    }
    return null;
}

/**
 * Try to resolve method in jqhtml component
 */
function try_resolve_jqhtml_method($identifier, $method_name, $files) {
    $search_method = ($method_name === 'data') ? 'on_load' : $method_name;

    foreach ($files as $file_path => $file_data) {
        if (str_ends_with($file_path, '.js')) {
            $absolute_path = IDE_BASE_PATH . '/' . $file_path;

            if (file_exists($absolute_path)) {
                $content = file_get_contents($absolute_path);

                if (preg_match('/class\s+' . preg_quote($identifier, '/') . '\s+extends\s+[A-Za-z_]*Component/', $content)) {
                    $lines = explode("\n", $content);
                    $line_number = 1;
                    $in_class = false;
                    $brace_count = 0;

                    foreach ($lines as $index => $line) {
                        if (preg_match('/class\s+' . preg_quote($identifier, '/') . '\s+extends/', $line)) {
                            $in_class = true;
                        }

                        if ($in_class) {
                            $brace_count += substr_count($line, '{');
                            $brace_count -= substr_count($line, '}');

                            if (preg_match('/(?:async\s+)?' . preg_quote($search_method, '/') . '\s*\(/', $line)) {
                                $line_number = $index + 1;
                                break;
                            }

                            if ($brace_count === 0 && strpos($line, '}') !== false) {
                                break;
                            }
                        }
                    }

                    return [
                        'found' => true,
                        'type' => 'jqhtml_class_method',
                        'file' => $file_path,
                        'line' => $line_number,
                        'identifier' => $identifier,
                        'method' => $method_name,
                    ];
                }
            }
        }
    }
    return null;
}

/**
 * Handle resolve_class service - resolve class/view/component definitions
 *
 * RESOLUTION TYPE SYSTEM
 * ======================
 *
 * This endpoint resolves identifiers to file locations based on type priority.
 * It supports CSV type lists for priority-based resolution.
 *
 * AVAILABLE TYPES:
 *
 * 1. php_class - PHP class files (*.php)
 *    - Searches manifest for PHP classes
 *    - Supports method lookup with fallback to class
 *    - Returns: file path, line number, namespace, extends, fqcn
 *
 * 2. js_class - JavaScript class files (*.js, not .jqhtml)
 *    - Searches for JS classes with extends clause
 *    - Does NOT require extending Component
 *    - Returns: file path, line number, extends
 *
 * 3. jqhtml_class - jqhtml component JavaScript files (*.js)
 *    - Searches for classes extending Component
 *    - Returns: file path, line number
 *
 * 4. view - Blade view templates (*.blade.php)
 *    - Searches for @rsx_id directives
 *    - Returns: file path, line number
 *
 * 5. bundle_alias - Bundle configuration aliases
 *    - Resolves bundle aliases from config/rsx.php
 *    - Returns: bundle class file location
 *
 * 6. jqhtml_template - jqhtml template files (*.jqhtml)
 *    - Searches for <Define:ComponentName> tags
 *    - Returns: template file path, line number
 *
 * 7. jqhtml_class_method - Methods in jqhtml component classes
 *    - Requires both identifier and method parameters
 *    - Searches within Component subclasses
 *    - Returns: file path, method line number
 *
 * CSV TYPE LISTS:
 * ===============
 *
 * The type parameter accepts comma-separated lists for priority-based resolution.
 * The server tries each type in order and returns the first match found.
 *
 * Examples:
 * - type=php_class,js_class
 *   Try PHP classes first, then JavaScript classes
 *   Use case: From PHP files (always want PHP), fallback to JS
 *
 * - type=js_class,php_class
 *   Try JavaScript classes first, then PHP classes
 *   Use case: From JS files (component inheritance), fallback to PHP controllers
 *
 * - type=jqhtml_class,js_class
 *   Try jqhtml components first, then general JS classes
 *   Use case: jqhtml extends="" attribute resolution
 *
 * METHOD RESOLUTION:
 * ==================
 *
 * When the method parameter is provided, the resolver attempts to find the specific
 * method within the class. If the method exists, returns its line number. If the
 * method doesn't exist but the class does, returns the class line number as fallback.
 *
 * This allows graceful degradation: clicking on Controller.missing_method() will
 * navigate to the Controller class even if missing_method() doesn't exist.
 *
 * USAGE FROM VS CODE:
 * ===================
 *
 * The VS Code extension determines which types to use based on context:
 *
 * - Route patterns → php_class (routes are always PHP)
 * - PHP class references → php_class (PHP files only reference PHP)
 * - JS class references → js_class,php_class (try JS first, then PHP controllers)
 * - jqhtml extends="" → jqhtml_class,js_class (component inheritance)
 * - jqhtml $xxx=Class.method → js_class,php_class (components or controllers)
 * - jqhtml $xxx=this.method → jqhtml_class_method (current component only)
 *
 * PARAMETERS:
 * - identifier/class (required): The class/view/component name to resolve
 * - method (optional): Method name within the class
 * - type (optional): Single type or CSV list of types to try in order
 */
function handle_resolve_class_service($data) {
    $identifier = $data['class'] ?? $data['identifier'] ?? null;
    $method_name = $data['method'] ?? null;
    $type = $data['type'] ?? null;

    if (!$identifier) {
        json_response([
            'error' => 'Missing required parameter: class or identifier',
            'found' => false,
        ], 400);
    }

    // Support CSV type list for priority ordering
    $type_list = [];
    if ($type) {
        $type_list = array_map('trim', explode(',', $type));
    }

    // Load manifest data
    $manifest_file = storage_path('rsx-build/manifest_data.php');
    if (!file_exists($manifest_file)) {
        error_response('Manifest not found - run php artisan rsx:manifest:build', 500);
    }

    $manifest_raw = include $manifest_file;
    $manifest_data = $manifest_raw['data'] ?? $manifest_raw;
    $files = $manifest_data['files'] ?? [];

    // Helper function to find PHP class in manifest
    $find_php_class = function($class_name) use ($files) {
        foreach ($files as $file_path => $file_data) {
            // Only match PHP files - check file extension and presence of PHP-specific metadata
            if (isset($file_data['class']) && $file_data['class'] === $class_name) {
                // Must be a PHP file (not .js, not .jqhtml)
                if (str_ends_with($file_path, '.php')) {
                    // Add file path to the data so caller knows which file it came from
                    $file_data['file'] = $file_path;
                    return $file_data;
                }
            }
        }
        return null;
    };

    // Helper function to find view in manifest
    $find_view = function($view_name) use ($files) {
        foreach ($files as $file_path => $file_data) {
            if (isset($file_data['id']) && $file_data['id'] === $view_name) {
                return $file_data;
            }
        }
        return null;
    };

    // Helper function to convert PascalCase to snake_case
    $camel_to_snake = function($input) {
        $result = preg_replace('/(?<!^)[A-Z]/', '_$0', $input);
        return strtolower($result);
    };

    // Helper function to convert snake_case to PascalCase
    $snake_to_pascal = function($input) {
        $parts = explode('_', $input);
        return implode('_', array_map('ucfirst', $parts));
    };

    // If no type list specified, try auto-detection with legacy 'class' type
    if (empty($type_list)) {
        if (!$type || $type === 'class' || preg_match('/^[A-Z][A-Za-z0-9_]*$/', $identifier)) {
            // Legacy: Try PHP class with auto-detection fallback
            $type_list = ['php_class', 'view', 'bundle_alias', 'jqhtml_template', 'jqhtml_class'];
        }
    }

    // Try each type in priority order
    foreach ($type_list as $current_type) {
        $result = null;

        switch ($current_type) {
            case 'php_class':
                $result = try_resolve_php_class($identifier, $method_name, $find_php_class);
                break;

            case 'js_class':
                $result = try_resolve_js_class($identifier, $method_name, $files);
                break;

            case 'jqhtml_class':
                $result = try_resolve_jqhtml_class($identifier, $method_name, $files);
                break;

            case 'view':
                $result = try_resolve_view($identifier, $find_view);
                break;

            case 'bundle_alias':
                $result = try_resolve_bundle_alias($identifier, $find_php_class);
                break;

            case 'jqhtml_template':
                $result = try_resolve_jqhtml_template($identifier, $files, $camel_to_snake, $snake_to_pascal);
                break;

            case 'jqhtml_class_method':
                if ($method_name) {
                    $result = try_resolve_jqhtml_method($identifier, $method_name, $files);
                }
                break;
        }

        // If we found a result, return it immediately
        if ($result && ($result['found'] ?? false)) {
            json_response($result);
        }
    }

    // Final fallback: Check for Base_* model stubs (auto-generated at bundle time)
    // These don't exist as JS classes but their corresponding PHP models do
    if (str_starts_with($identifier, 'Base_')) {
        $model_name = substr($identifier, 5); // Strip 'Base_' prefix
        $model_data = $find_php_class($model_name);

        if ($model_data) {
            // Check if this is a subclass of Rsx_Model_Abstract
            $is_model = false;
            $current_class = $model_name;
            $visited = [];

            while ($current_class) {
                if (in_array($current_class, $visited)) break;
                $visited[] = $current_class;

                $class_data = $find_php_class($current_class);
                if (!$class_data) break;

                $extends = $class_data['extends'] ?? null;
                if (!$extends) break;

                // Normalize extends (strip namespace)
                $extends_parts = explode('\\', $extends);
                $extends_simple = end($extends_parts);

                if ($extends_simple === 'Rsx_Model_Abstract') {
                    $is_model = true;
                    break;
                }

                $current_class = $extends_simple;
            }

            if ($is_model) {
                $file_path = $model_data['file'];
                $line_number = 1;
                $absolute_path = IDE_BASE_PATH . '/' . $file_path;

                // Try to find the fetch method line number if it exists
                if (file_exists($absolute_path)) {
                    $content = file_get_contents($absolute_path);
                    $lines = explode("\n", $content);

                    foreach ($lines as $index => $line) {
                        if (preg_match('/^\s*(public\s+)?(static\s+)?function\s+fetch\s*\(/', $line)) {
                            $line_number = $index + 1;
                            break;
                        }
                    }
                }

                json_response([
                    'found' => true,
                    'type' => 'base_model_stub',
                    'file' => $file_path,
                    'line' => $line_number,
                    'identifier' => $identifier,
                    'resolved_model' => $model_name,
                ]);
            }
        }
    }

    // Nothing found after trying all types
    json_response([
        'found' => false,
        'error' => 'Identifier not found in manifest',
        'identifier' => $identifier,
        'searched_types' => $type_list,
    ]);
}

/**
 * OBSOLETE LEGACY CODE REMOVED
 * =============================
 * The code below (approximately lines 1410-1685) was the old resolution system
 * that tried each type individually without CSV support. It has been replaced
 * by the new CSV type list system above with dedicated resolver functions.
 *
 * Removed sections:
 * - Legacy PHP class fallback (duplicate of try_resolve_php_class)
 * - Legacy view resolution (duplicate of try_resolve_view)
 * - Legacy bundle alias resolution (duplicate of try_resolve_bundle_alias)
 * - Legacy jqhtml template resolution (duplicate of try_resolve_jqhtml_template)
 * - Legacy jqhtml class resolution (duplicate of try_resolve_jqhtml_class)
 * - Legacy jqhtml method resolution (duplicate of try_resolve_jqhtml_method)
 */


/**
 * Handle js_lineage service - get JavaScript class inheritance chain
 */
function handle_js_lineage_service($data) {
    $class_name = $data['class'] ?? null;

    if (!$class_name) {
        json_response([
            'error' => 'Missing required parameter: class',
        ], 400);
    }

    // Load manifest data
    $manifest_file = storage_path('rsx-build/manifest_data.php');
    if (!file_exists($manifest_file)) {
        error_response('Manifest not found - run php artisan rsx:manifest:build', 500);
    }

    $manifest_raw = include $manifest_file;
    $manifest_data = $manifest_raw['data'] ?? $manifest_raw;
    $files = $manifest_data['files'] ?? [];

    // Find the JavaScript class and trace its lineage
    $lineage = [];
    $current_class = $class_name;

    // Helper to find extends clause in JS file
    $find_extends = function($file_path) {
        $absolute_path = IDE_BASE_PATH . '/' . $file_path;
        if (file_exists($absolute_path)) {
            $content = file_get_contents($absolute_path);
            if (preg_match('/class\s+\w+\s+extends\s+([A-Za-z_][A-Za-z0-9_]*)/', $content, $matches)) {
                return $matches[1];
            }
        }
        return null;
    };

    // Trace up to 10 levels to prevent infinite loops
    for ($i = 0; $i < 10; $i++) {
        $found = false;

        foreach ($files as $file_path => $file_data) {
            if (str_ends_with($file_path, '.js')) {
                $absolute_path = IDE_BASE_PATH . '/' . $file_path;

                if (file_exists($absolute_path)) {
                    $content = file_get_contents($absolute_path);

                    // Check if this file defines the current class
                    if (preg_match('/class\s+' . preg_quote($current_class, '/') . '\s+extends\s+([A-Za-z_][A-Za-z0-9_]*)/', $content, $matches)) {
                        $parent_class = $matches[1];
                        $lineage[] = $parent_class;
                        $current_class = $parent_class;
                        $found = true;
                        break;
                    }
                }
            }
        }

        if (!$found) {
            break;
        }
    }

    json_response([
        'class' => $class_name,
        'lineage' => $lineage,
    ]);
}

/**
 * Handle js_is_subclass_of service - check if JS class extends another
 *
 * This is a direct RPC wrapper around Manifest::js_is_subclass_of()
 *
 * PARAMETERS:
 * - subclass (required): The potential subclass name
 * - superclass (required): The potential superclass name
 *
 * RETURNS:
 * - is_subclass: boolean - true if subclass extends superclass anywhere in chain
 */
function handle_js_is_subclass_of_service($data) {
    $subclass = $data['subclass'] ?? null;
    $superclass = $data['superclass'] ?? null;

    if (!$subclass || !$superclass) {
        json_response([
            'error' => 'Missing required parameters: subclass and superclass',
        ], 400);
    }

    // Load manifest
    $manifest_file = storage_path('rsx-build/manifest_data.php');
    if (!file_exists($manifest_file)) {
        error_response('Manifest not found - run php artisan rsx:manifest:build', 500);
    }

    $manifest_raw = include $manifest_file;
    $manifest_data = $manifest_raw['data'] ?? $manifest_raw;
    $js_classes = $manifest_data['js_classes'] ?? [];
    $files = $manifest_data['files'] ?? [];

    // Implement same logic as Manifest::js_is_subclass_of
    $current_class = $subclass;
    $visited = [];

    while ($current_class) {
        // Prevent infinite loops
        if (in_array($current_class, $visited)) {
            json_response(['is_subclass' => false]);
        }

        $visited[] = $current_class;

        // Find the current class in the manifest
        if (!isset($js_classes[$current_class])) {
            json_response(['is_subclass' => false]);
        }

        // Get file metadata
        $file_path = $js_classes[$current_class];
        $metadata = $files[$file_path] ?? null;

        if (!$metadata || empty($metadata['extends'])) {
            json_response(['is_subclass' => false]);
        }

        if ($metadata['extends'] == $superclass) {
            json_response(['is_subclass' => true]);
        }

        // Move up the chain to the parent class
        $current_class = $metadata['extends'];
    }

    json_response(['is_subclass' => false]);
}

/**
 * Handle php_is_subclass_of service - check if PHP class extends another
 *
 * This is a direct RPC wrapper around Manifest::php_is_subclass_of()
 *
 * PARAMETERS:
 * - subclass (required): The potential subclass name
 * - superclass (required): The potential superclass name
 *
 * RETURNS:
 * - is_subclass: boolean - true if subclass extends superclass anywhere in chain
 */
function handle_php_is_subclass_of_service($data) {
    $subclass = $data['subclass'] ?? null;
    $superclass = $data['superclass'] ?? null;

    if (!$subclass || !$superclass) {
        json_response([
            'error' => 'Missing required parameters: subclass and superclass',
        ], 400);
    }

    // Load manifest
    $manifest_file = storage_path('rsx-build/manifest_data.php');
    if (!file_exists($manifest_file)) {
        error_response('Manifest not found - run php artisan rsx:manifest:build', 500);
    }

    $manifest_raw = include $manifest_file;
    $manifest_data = $manifest_raw['data'] ?? $manifest_raw;
    $files = $manifest_data['files'] ?? [];

    // Implement same logic as Manifest::php_is_subclass_of
    $current_class = $subclass;
    $visited = [];

    while ($current_class) {
        // Prevent infinite loops
        if (in_array($current_class, $visited)) {
            json_response(['is_subclass' => false]);
        }

        $visited[] = $current_class;

        // Find the current class in files
        $found = false;
        foreach ($files as $file_path => $file_data) {
            if (isset($file_data['class']) && $file_data['class'] === $current_class) {
                // Must be a PHP file
                if (str_ends_with($file_path, '.php')) {
                    $extends = $file_data['extends'] ?? null;

                    if (!$extends) {
                        json_response(['is_subclass' => false]);
                    }

                    // Normalize the extends class name (strip namespace)
                    $extends_parts = explode('\\', $extends);
                    $extends_simple = end($extends_parts);

                    if ($extends_simple == $superclass) {
                        json_response(['is_subclass' => true]);
                    }

                    // Move up the chain
                    $current_class = $extends_simple;
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            json_response(['is_subclass' => false]);
        }
    }

    json_response(['is_subclass' => false]);
}

/**
 * Handle resolve_url service - resolve URL to controller/method
 * Takes a URL path and returns the controller and method that handles it
 */
function handle_resolve_url_service($data) {
    $url = $data['url'] ?? null;

    if (!$url) {
        json_response([
            'error' => 'Missing required parameter: url',
            'found' => false,
        ], 400);
    }

    // Load manifest to get routes
    $manifest_file = storage_path('rsx-build/manifest_data.php');
    if (!file_exists($manifest_file)) {
        error_response('Manifest not found - run php artisan rsx:manifest:build', 500);
    }

    $manifest_raw = include $manifest_file;
    $manifest_data = $manifest_raw['data'] ?? $manifest_raw;

    // Get routes from manifest
    $routes = $manifest_data['php']['routes'] ?? [];

    // Try to find matching route
    foreach ($routes as $route) {
        $pattern = $route['pattern'] ?? '';
        $controller = $route['class'] ?? '';
        $method = $route['method'] ?? '';

        // Simple pattern matching - exact match for now
        // TODO: Support route parameters like /users/:id
        if ($pattern === $url) {
            json_response([
                'found' => true,
                'controller' => $controller,
                'method' => $method,
                'pattern' => $pattern,
            ]);
        }
    }

    // Not found
    json_response([
        'found' => false,
        'url' => $url,
    ]);
}

