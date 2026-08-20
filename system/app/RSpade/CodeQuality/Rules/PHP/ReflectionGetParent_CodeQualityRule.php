<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * ReflectionGetParent_CodeQualityRule - Detects native PHP ReflectionClass getParentClass() usage
 *
 * This rule identifies code that uses PHP's ReflectionClass to get parent class information,
 * and suggests using the more efficient Manifest methods instead. The Manifest provides
 * pre-computed data that avoids the overhead of reflection.
 *
 * What it detects:
 * - $reflection->getParentClass()
 * - new ReflectionClass($class)->getParentClass()
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
class ReflectionGetParent_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'PHP-REFLECT-02';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'ReflectionClass getParentClass() Usage';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Detects usage of ReflectionClass->getParentClass() and suggests using Manifest methods';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * Check the file for ReflectionClass getParentClass() usage
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
            if (str_contains($line, '@PHP-REFLECT-02-EXCEPTION')) {
                continue;
            }

            // Pattern: $reflection->getParentClass() or ->getParentClass()->
            if (preg_match('/->\\s*getParentClass\\s*\\(\\s*\\)/', $line)) {
                // Check if this is likely from a ReflectionClass instance
                $start = max(0, $line_number - 5);
                $end = min(count($lines) - 1, $line_number);

                for ($i = $start; $i <= $end; $i++) {
                    if (preg_match('/\\$\\w+\\s*=\\s*new\\s*\\\\?ReflectionClass/', $lines[$i]) ||
                        preg_match('/\\(\\s*new\\s*\\\\?ReflectionClass/', $lines[$i])) {
                        $this->add_violation(
                            $file_path,
                            $actual_line_number,
                            "Using ReflectionClass->getParentClass() instead of Manifest methods",
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
        return "Replace ReflectionClass->getParentClass() with Manifest methods for better performance:\n\n" .
               "Example 1 - Get parent class name:\n" .
               "  // Before:\n" .
               "  \$reflection = new ReflectionClass(\$class_name);\n" .
               "  \$parent = \$reflection->getParentClass();\n" .
               "  if (\$parent) {\n" .
               "      \$parent_name = \$parent->getName();\n" .
               "  }\n\n" .
               "  // After:\n" .
               "  \$metadata = \\App\\RSpade\\Core\\Manifest\\Manifest::php_get_metadata_by_class(\$class_name);\n" .
               "  \$parent_name = \$metadata['extends'] ?? null;\n\n" .
               "Example 2 - Check if class has a specific parent:\n" .
               "  // Before:\n" .
               "  \$reflection = new ReflectionClass(\$class_name);\n" .
               "  \$parent = \$reflection->getParentClass();\n" .
               "  if (\$parent && \$parent->getName() === 'BaseClass') { ... }\n\n" .
               "  // After:\n" .
               "  if (\\App\\RSpade\\Core\\Manifest\\Manifest::php_is_subclass_of(\$class_name, 'BaseClass')) { ... }\n\n" .
               "Example 3 - Walk up parent chain:\n" .
               "  // Before:\n" .
               "  \$reflection = new ReflectionClass(\$class_name);\n" .
               "  while (\$parent = \$reflection->getParentClass()) {\n" .
               "      // Process parent\n" .
               "      \$reflection = \$parent;\n" .
               "  }\n\n" .
               "  // After:\n" .
               "  \$current = \$class_name;\n" .
               "  while (\$current) {\n" .
               "      \$metadata = \\App\\RSpade\\Core\\Manifest\\Manifest::php_get_metadata_by_class(\$current);\n" .
               "      \$current = \$metadata['extends'] ?? null;\n" .
               "      if (\$current) {\n" .
               "          // Process parent\n" .
               "      }\n" .
               "  }\n\n" .
               "Related methods to consider:\n" .
               "- Manifest::php_is_subclass_of() - Check if class extends another\n" .
               "- Manifest::php_get_subclasses_of() - Get all subclasses of a parent\n" .
               "- Manifest::php_get_extending() - Get concrete classes extending a parent\n\n" .
               "Benefits:\n" .
               "- Avoids expensive reflection operations\n" .
               "- Uses pre-computed manifest data (O(1) lookup)\n" .
               "- Improves performance in loops and bulk operations\n\n" .
               "Note: The 'extends' property in metadata contains the parent class name as a string,\n" .
               "not a ReflectionClass object. Adjust your code accordingly.";
    }
}