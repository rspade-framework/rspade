<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use Exception;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

class NoImplements_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-IMPLEMENTS-01';
    }

    public function get_name(): string
    {
        return 'No Implements Keyword';
    }

    public function get_description(): string
    {
        return 'Prohibits use of implements keyword except for Model subclasses';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Check for prohibited use of implements keyword
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Only check files in ./rsx and ./app/RSpade
        $is_rsx = str_contains($file_path, '/rsx/');
        $is_rspade = str_contains($file_path, '/app/RSpade/');

        if (!$is_rsx && !$is_rspade) {
            return;
        }

        // Get class information from metadata
        if (!isset($metadata['class'])) {
            return;
        }

        $class_name = $metadata['class'];

        // Check if this class is a subclass of Model (allowed to use implements)
        $is_model_subclass = false;

        // Check direct extends
        if (isset($metadata['extends'])) {
            $extends = $metadata['extends'];
            if ($extends === 'Model' || $extends === 'Rsx_Model_Abstract') {
                $is_model_subclass = true;
            } else {
                // Check inheritance chain
                try {
                    if (Manifest::php_is_subclass_of($class_name, 'Model') ||
                        Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
                        $is_model_subclass = true;
                    }
                } catch (Exception $e) {
                    // Class not in manifest, continue checking
                }
            }
        }

        // If it's a Model subclass, implements is allowed
        if ($is_model_subclass) {
            return;
        }

        // Check if class has implements
        if (!isset($metadata['implements']) || empty($metadata['implements'])) {
            return;
        }

        $implements = $metadata['implements'];

        // Now check if any of the implemented interfaces are from ./rsx or ./app/RSpade
        $local_interfaces = [];
        $vendor_interfaces = [];

        // PHP built-in interfaces that are allowed
        $php_builtin_interfaces = [
            'Throwable', 'Stringable', 'JsonSerializable', 'Serializable',
            'ArrayAccess', 'Countable', 'Iterator', 'IteratorAggregate',
            'Traversable', 'DateTimeInterface', 'OuterIterator',
            'RecursiveIterator', 'SeekableIterator', 'SessionHandlerInterface',
        ];

        // Get the original file content to extract use statements
        $original_content = file_get_contents($file_path);
        $use_statements = $this->extract_use_statements($original_content);

        foreach ($implements as $interface) {
            // Check if it's a PHP built-in interface
            if (in_array($interface, $php_builtin_interfaces)) {
                $vendor_interfaces[] = $interface;
                continue;
            }

            // Check if it's a fully qualified name
            if (str_contains($interface, '\\')) {
                // Already FQCN
                $fqcn = $interface;
            } else {
                // Look up in use statements
                $fqcn = $this->resolve_class_name($interface, $use_statements, $metadata['namespace'] ?? '');
            }

            // Check if this interface is from local code
            if (str_starts_with($fqcn, 'Rsx\\') || str_starts_with($fqcn, 'App\\RSpade\\')) {
                $local_interfaces[] = $interface;
            } else {
                $vendor_interfaces[] = $interface;
            }
        }

        // If there are no local interfaces, no violation
        if (empty($local_interfaces)) {
            return;
        }

        // Generate violation for local interface usage
        $lines = explode("\n", $contents);
        $line_number = 0;
        $code_snippet = '';

        // Find the line with class declaration and implements
        foreach ($lines as $i => $line) {
            if (preg_match('/\bclass\s+' . preg_quote($class_name) . '\b.*\bimplements\b/i', $line)) {
                $line_number = $i + 1;
                $code_snippet = $this->get_code_snippet($lines, $i);
                break;
            }
        }

        $resolution = "CRITICAL: The 'implements' keyword is not allowed in RSpade framework code.\n\n";
        $resolution .= "INTERFACES DETECTED:\n";
        $resolution .= '- Local interfaces (PROHIBITED): ' . implode(', ', $local_interfaces) . "\n";
        if (!empty($vendor_interfaces)) {
            $resolution .= '- Vendor interfaces (allowed): ' . implode(', ', $vendor_interfaces) . "\n";
        }
        $resolution .= "\n";

        $resolution .= "WHY THIS IS PROHIBITED:\n";
        $resolution .= "The RSpade framework philosophy requires using abstract classes instead of interfaces because:\n";
        $resolution .= "1. Abstract classes can provide default implementations, reducing boilerplate\n";
        $resolution .= "2. Abstract classes can have protected helper methods for subclasses\n";
        $resolution .= "3. Single inheritance enforces simpler, more maintainable design\n";
        $resolution .= "4. If a class needs multiple behaviors, it indicates the class is too complex\n\n";

        $resolution .= "HOW TO FIX:\n";
        $resolution .= "1. Convert the interface to an abstract class:\n";
        $resolution .= "   - Change 'interface' to 'abstract class'\n";
        $resolution .= "   - Add default implementations where appropriate\n";
        $resolution .= "   - Add protected helper methods if needed\n\n";

        $resolution .= "2. Update this class to extend the abstract class:\n";
        $resolution .= "   - Change 'implements " . implode(', ', $local_interfaces) . "' to 'extends <AbstractClassName>'\n";
        $resolution .= "   - If the class already extends something, you need to refactor\n\n";

        $resolution .= "3. If the class truly needs multiple behaviors:\n";
        $resolution .= "   - This indicates the class has too many responsibilities\n";
        $resolution .= "   - Break it into separate classes with single responsibilities\n";
        $resolution .= "   - Use composition instead of inheritance where appropriate\n\n";

        $resolution .= "EXCEPTION:\n";
        $resolution .= "Model classes (subclasses of Eloquent Model or Rsx_Model_Abstract) ARE allowed to implement interfaces\n";
        $resolution .= "because they often need to implement Laravel contracts like Authenticatable, etc.\n\n";

        $resolution .= "If this is genuinely needed (extremely rare), add '@PHP-IMPLEMENTS-01-EXCEPTION' comment.";

        $this->add_violation(
            $file_path,
            $line_number,
            "Class '{$class_name}' uses 'implements' keyword with local interfaces: " . implode(', ', $local_interfaces),
            $code_snippet,
            $resolution,
            'critical'
        );
    }

    /**
     * Extract use statements from PHP file content
     */
    protected function extract_use_statements(string $content): array
    {
        $use_statements = [];

        // Match use statements
        if (preg_match_all('/^\s*use\s+([^;]+);/m', $content, $matches)) {
            foreach ($matches[1] as $use_statement) {
                // Handle aliased imports
                if (str_contains($use_statement, ' as ')) {
                    list($fqcn, $alias) = explode(' as ', $use_statement);
                    $fqcn = trim($fqcn);
                    $alias = trim($alias);
                    $use_statements[$alias] = $fqcn;
                } else {
                    $fqcn = trim($use_statement);
                    // Get the simple name from FQCN
                    $parts = explode('\\', $fqcn);
                    $simple_name = end($parts);
                    $use_statements[$simple_name] = $fqcn;
                }
            }
        }

        return $use_statements;
    }

    /**
     * Resolve a simple class name to FQCN using use statements
     */
    protected function resolve_class_name(string $simple_name, array $use_statements, string $namespace): string
    {
        // Check if it's in use statements
        if (isset($use_statements[$simple_name])) {
            return $use_statements[$simple_name];
        }

        // If not in use statements and we have a namespace, assume it's in the same namespace
        if ($namespace) {
            return $namespace . '\\' . $simple_name;
        }

        // Otherwise return as-is
        return $simple_name;
    }

    /**
     * Check if a file in /app/RSpade/ is in an allowed subdirectory
     * Based on scan_directories configuration
     */
    private function is_in_allowed_rspade_directory(string $file_path): bool
    {
        // Get allowed subdirectories from config
        $scan_directories = config('rsx.manifest.scan_directories', []);

        // Extract allowed RSpade subdirectories
        $allowed_subdirs = [];
        foreach ($scan_directories as $scan_dir) {
            if (str_starts_with($scan_dir, 'app/RSpade/')) {
                $subdir = substr($scan_dir, strlen('app/RSpade/'));
                if ($subdir) {
                    $allowed_subdirs[] = $subdir;
                }
            }
        }

        // Check if file is in any allowed subdirectory
        foreach ($allowed_subdirs as $subdir) {
            if (str_contains($file_path, '/app/RSpade/' . $subdir . '/') ||
                str_contains($file_path, '/app/RSpade/' . $subdir)) {
                return true;
            }
        }

        return false;
    }
}
