<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

// @ROUTE-EXISTS-01-EXCEPTION

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * RequestQueryInController_CodeQualityRule - Detect $request->query() usage in controllers
 *
 * This rule detects patterns where $request->query() is used in controller methods.
 * Controllers should use the $params array which contains combined values from:
 * - Route parameters extracted from the URL (e.g., /myroute/:code)
 * - GET query parameters (e.g., ?code=value)
 *
 * The $params array is automatically populated by the framework and passed to all
 * controller methods.
 */
class RequestQueryInController_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique identifier for this rule
     *
     * @return string
     */
    public function get_id(): string
    {
        return 'PHP-CONTROLLER-REQUEST-01';
    }

    /**
     * Get the default severity level
     *
     * @return string One of: critical, high, medium, low, convention
     */
    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Get the file patterns this rule applies to
     *
     * @return array
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Get the display name for this rule
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'Request Query Parameter Detection in Controllers';
    }

    /**
     * Get the description of what this rule checks
     *
     * @return string
     */
    public function get_description(): string
    {
        return 'Detects $request->query() usage in controller classes and suggests using $params instead';
    }

    /**
     * Check if this rule should be called during manifest scan
     *
     * This rule runs at manifest-time to catch incorrect parameter access
     * immediately in controller methods.
     *
     * @return bool
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Check the file contents for violations
     *
     * @param string $file_path The path to the file being checked
     * @param string $contents The contents of the file
     * @param array $metadata Additional metadata about the file
     * @return void
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in rsx/ directory
        if (!str_contains($file_path, '/rsx/')) {
            return;
        }

        // Skip if no class or no FQCN
        if (!isset($metadata['fqcn'])) {
            return;
        }

        // Check if this class extends Rsx_Controller_Abstract
        if (!$this->is_controller_descendant($metadata['fqcn'])) {
            return;
        }

        // Check for $request->query( usage
        $lines = explode("\n", $contents);

        foreach ($lines as $line_num => $line) {
            // Look for $request->query( pattern
            if (preg_match('/\$request\s*->\s*query\s*\(/', $line)) {
                // Add violation
                $this->add_violation(
                    $file_path,
                    $line_num + 1,
                    "\$request->query() used in controller method",
                    $line,
                    "Controller methods should use the \$params array instead of \$request->query().\n\n" .
                    "The \$params array contains combined values from:\n" .
                    "- Route parameters extracted from the URL (e.g., /myroute/:code)\n" .
                    "- GET query parameters (e.g., ?code=value)\n\n" .
                    "WRONG:\n" .
                    "  public static function index(Request \$request, array \$params = [])\n" .
                    "  {\n" .
                    "      \$code = \$request->query('code');\n" .
                    "      \$page = \$request->query('page');\n" .
                    "  }\n\n" .
                    "CORRECT:\n" .
                    "  public static function index(Request \$request, array \$params = [])\n" .
                    "  {\n" .
                    "      \$code = \$params['code'] ?? null;\n" .
                    "      \$page = \$params['page'] ?? null;\n" .
                    "  }\n\n" .
                    "WHY USE \$params:\n" .
                    "- Works with both route parameters (/users/:id) and query strings (?id=5)\n" .
                    "- Unified API for all parameter access\n" .
                    "- Automatically populated by the framework\n" .
                    "- Consistent with RSX controller conventions"
                );
            }
        }
    }

    /**
     * Check if a class is a descendant of Rsx_Controller_Abstract
     *
     * @param string $fqcn Fully qualified class name
     * @return bool
     */
    private function is_controller_descendant(string $fqcn): bool
    {
        // Check if class is loaded
        if (!class_exists($fqcn, false)) {
            // Try to load from manifest metadata
            try {
                $metadata = Manifest::php_get_metadata_by_fqcn($fqcn);
                $parent = $metadata['extends'] ?? null;

                // Walk up the inheritance chain
                while ($parent) {
                    if ($parent === 'Rsx_Controller_Abstract' ||
                        $parent === 'App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract') {
                        return true;
                    }

                    // Try to get parent's metadata
                    try {
                        $parent_metadata = Manifest::php_get_metadata_by_class($parent);
                        $parent = $parent_metadata['extends'] ?? null;
                    } catch (\Exception $e) {
                        // Parent not found in manifest
                        break;
                    }
                }
            } catch (\Exception $e) {
                // Class not found in manifest
                return false;
            }
        } else {
            // Class is loaded, use reflection
            $reflection = new \ReflectionClass($fqcn);
            return $reflection->isSubclassOf('App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract');
        }

        return false;
    }
}
