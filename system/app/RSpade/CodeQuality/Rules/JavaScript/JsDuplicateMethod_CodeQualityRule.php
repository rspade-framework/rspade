<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * JsDuplicateMethodRule - Detects duplicate method definitions in ES6 classes
 *
 * JavaScript allows defining the same method name multiple times in a class,
 * but later definitions silently overwrite earlier ones. This is almost always
 * a mistake - commonly caused by copy/paste errors or merge conflicts.
 *
 * Example of problematic code:
 *   class MyComponent extends Component {
 *       on_render() { ... }  // First definition
 *       // ... lots of code ...
 *       on_render() { ... }  // Overwrites the first one silently!
 *   }
 */
class JsDuplicateMethod_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-DUPLICATE-METHOD-01';
    }

    public function get_name(): string
    {
        return 'Duplicate Method Definition';
    }

    public function get_description(): string
    {
        return 'Detects ES6 class methods that are defined more than once (later definitions overwrite earlier ones)';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * This rule runs during manifest scan for immediate feedback
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Check JavaScript file for duplicate method definitions
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if no duplicate methods detected by parser
        if (empty($metadata['duplicate_methods'])) {
            return;
        }

        $class_name = $metadata['class'] ?? 'unknown';

        foreach ($metadata['duplicate_methods'] as $duplicate) {
            $method_name = $duplicate['name'];
            $is_static = $duplicate['static'] ?? false;
            $first_line = $duplicate['firstLine'] ?? 1;
            $second_line = $duplicate['secondLine'] ?? 1;

            $static_prefix = $is_static ? 'static ' : '';
            $method_type = $is_static ? 'Static method' : 'Method';

            $this->add_violation(
                $file_path,
                $second_line,
                "{$method_type} '{$method_name}' is defined multiple times in class {$class_name}",
                $this->extract_code_lines($contents, $first_line, $second_line),
                $this->get_remediation($method_name, $class_name, $is_static, $first_line, $second_line),
                'critical'
            );
        }
    }

    /**
     * Extract code snippets for both method definitions
     */
    private function extract_code_lines(string $contents, int $first_line, int $second_line): string
    {
        $lines = explode("\n", $contents);
        $snippets = [];

        if (isset($lines[$first_line - 1])) {
            $snippets[] = "Line {$first_line}: " . trim($lines[$first_line - 1]);
        }
        if (isset($lines[$second_line - 1])) {
            $snippets[] = "Line {$second_line}: " . trim($lines[$second_line - 1]);
        }

        return implode("\n", $snippets);
    }

    /**
     * Get remediation instructions
     */
    private function get_remediation(string $method_name, string $class_name, bool $is_static, int $first_line, int $second_line): string
    {
        $static_prefix = $is_static ? 'static ' : '';

        return "DUPLICATE METHOD DEFINITION: '{$method_name}' is defined twice in {$class_name}

PROBLEM:
JavaScript allows defining the same method name multiple times in a class,
but later definitions silently overwrite earlier ones. This means:
- The first definition at line {$first_line} is IGNORED
- Only the second definition at line {$second_line} will be executed

This is almost always a mistake caused by:
- Copy/paste errors
- Merge conflicts
- Forgetting an earlier implementation exists

SOLUTION:
1. Review both definitions to understand what each was intended to do
2. Merge the logic if both are needed, OR
3. Remove the duplicate definition if one is obsolete

FIRST DEFINITION (line {$first_line}) - THIS IS BEING IGNORED:
Look at what this implementation does - is any of this logic needed?

SECOND DEFINITION (line {$second_line}) - THIS IS THE ACTIVE ONE:
This is the only version that will actually run.

COMMON PATTERNS:
- If both have different logic: merge them into a single method
- If they're identical: remove one (preferably keep the better-documented one)
- If one is outdated: remove the obsolete version";
    }
}
