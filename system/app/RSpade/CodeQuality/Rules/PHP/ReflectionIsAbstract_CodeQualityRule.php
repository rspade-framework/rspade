<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * ReflectionIsAbstract_CodeQualityRule - Detects native PHP ReflectionClass isAbstract() usage
 *
 * This rule identifies code that uses PHP's ReflectionClass to check if a class is abstract,
 * and suggests using the more efficient Manifest methods instead. The Manifest provides
 * pre-computed data that avoids the overhead of reflection.
 *
 * What it detects:
 * - new ReflectionClass($class)->isAbstract()
 * - $reflection->isAbstract() patterns
 *
 * Why it matters:
 * - ReflectionClass is expensive, especially in loops
 * - Manifest data is pre-computed during build time
 * - Avoids runtime overhead and improves performance
 *
 * Exceptions:
 * - Manifest.php itself (where the data is extracted)
 * - Exception handlers (which may need reflection for error reporting)
 */
class ReflectionIsAbstract_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'PHP-REFLECT-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'ReflectionClass isAbstract() Usage';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Detects usage of ReflectionClass->isAbstract() and suggests using Manifest methods';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Check the file for ReflectionClass isAbstract() usage
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if not in rsx or app/RSpade directories
        if (!str_contains($file_path, '/rsx/') && !str_starts_with($file_path, 'rsx/') &&
            !str_contains($file_path, '/app/RSpade/') && !str_starts_with($file_path, 'app/RSpade/')) {
            return;
        }

        // Skip Manifest.php itself
        if (str_ends_with($file_path, 'Manifest.php')) {
            return;
        }

        // Skip exception handlers
        if (str_contains($file_path, 'Exception') || str_contains($file_path, 'Handler')) {
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($lines as $line_number => $line) {
            $actual_line_number = $line_number + 1;

            // Skip if line has exception comment
            if (str_contains($line, '@PHP-REFLECT-01-EXCEPTION')) {
                continue;
            }

            // Pattern 1: Direct usage - new ReflectionClass(...)->isAbstract()
            if (preg_match('/\(?\s*new\s+\\\\?ReflectionClass\s*\([^)]+\)\s*\)?\s*->\s*isAbstract\s*\(\)/', $line)) {
                $this->add_violation(
                    $file_path,
                    $actual_line_number,
                    "Using ReflectionClass->isAbstract() instead of Manifest::php_is_abstract()",
                    trim($line),
                    $this->get_remediation_message(),
                    'high'
                );
                continue;
            }

            // Pattern 2: Variable usage - $reflection->isAbstract()
            if (preg_match('/\$\w+\s*->\s*isAbstract\s*\(\)/', $line)) {
                // Check if this variable was created from ReflectionClass in nearby lines
                $start = max(0, $line_number - 5);
                $end = min(count($lines) - 1, $line_number);

                for ($i = $start; $i <= $end; $i++) {
                    if (preg_match('/\$\w+\s*=\s*new\s+\\\\?ReflectionClass/', $lines[$i])) {
                        $this->add_violation(
                            $file_path,
                            $actual_line_number,
                            "Using ReflectionClass->isAbstract() instead of Manifest::php_is_abstract()",
                            trim($line),
                            $this->get_remediation_message(),
                            'high'
                        );
                        break;
                    }
                }
            }
        }
    }

    /**
     * Get the remediation message with examples
     */
    private function get_remediation_message(): string
    {
        return "Replace ReflectionClass->isAbstract() with Manifest methods for better performance:\n\n" .
               "Example 1 - Check if a specific class is abstract:\n" .
               "  // Before:\n" .
               "  \$reflection = new ReflectionClass(\$class_name);\n" .
               "  if (\$reflection->isAbstract()) { ... }\n\n" .
               "  // After:\n" .
               "  if (\\App\\RSpade\\Core\\Manifest\\Manifest::php_is_abstract(\$class_name)) { ... }\n\n" .
               "Example 2 - Get all non-abstract subclasses:\n" .
               "  // Before:\n" .
               "  \$subclasses = [];\n" .
               "  foreach (\$all_classes as \$class) {\n" .
               "      if (is_subclass_of(\$class, \$base_class)) {\n" .
               "          \$reflection = new ReflectionClass(\$class);\n" .
               "          if (!\$reflection->isAbstract()) {\n" .
               "              \$subclasses[] = \$class;\n" .
               "          }\n" .
               "      }\n" .
               "  }\n\n" .
               "  // After:\n" .
               "  \$subclasses = \\App\\RSpade\\Core\\Manifest\\Manifest::php_get_subclasses_of(\$base_class, false);\n\n" .
               "Example 3 - Get only concrete extending classes:\n" .
               "  // Use php_get_extending() with include_abstract = false:\n" .
               "  \$concrete = \\App\\RSpade\\Core\\Manifest\\Manifest::php_get_extending(\$base_class, false);\n\n" .
               "Benefits:\n" .
               "- Avoids expensive reflection operations\n" .
               "- Uses pre-computed manifest data (O(1) lookup)\n" .
               "- Improves performance in loops and bulk operations";
    }
}