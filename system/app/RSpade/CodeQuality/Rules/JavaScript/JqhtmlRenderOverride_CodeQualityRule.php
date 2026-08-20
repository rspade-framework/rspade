<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\Core\Manifest\Manifest;

class JqhtmlRenderOverride_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JQHTML-RENDER-01';
    }

    public function get_name(): string
    {
        return 'JQHTML render() Method Override Check';
    }

    public function get_description(): string
    {
        return 'Components must not override render() method - use on_render(), on_create(), or on_ready() instead';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check for render() method override in Component subclasses
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Get sanitized content
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $content = $sanitized_data['content'];

        // Get JavaScript class from manifest metadata
        $js_classes = [];
        if (isset($metadata['class']) && isset($metadata['extension']) && $metadata['extension'] === 'js') {
            $js_classes = [$metadata['class']];
        }

        // If no classes in metadata, nothing to check
        if (empty($js_classes)) {
            return;
        }

        // Check each class to see if it's a JQHTML component
        foreach ($js_classes as $class_name) {
            // Use Manifest to check inheritance (handles indirect inheritance)
            if (!Manifest::js_is_subclass_of($class_name, 'Component') &&
                !Manifest::js_is_subclass_of($class_name, 'Component')) {
                continue;
            }

            // Now find WHERE this class is in the source for extraction
            // @META-INHERIT-01-EXCEPTION - Finding class position with PREG_OFFSET_CAPTURE to extract method bodies
            // Not checking inheritance - need PREG_OFFSET_CAPTURE to extract method bodies
            if (!preg_match('/class\s+' . preg_quote($class_name, '/') . '\s+extends\s+\w+\s*\{/i', $content, $class_match, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $class_start = $class_match[0][1];

            // Find the class content
            $brace_count = 0;
            $in_class = false;
            $class_content = '';
            $pos = $class_start;

            while ($pos < strlen($content)) {
                $char = $content[$pos];

                if ($char === '{') {
                    $brace_count++;
                    $in_class = true;
                } elseif ($char === '}') {
                    $brace_count--;
                    if ($brace_count === 0 && $in_class) {
                        $class_content = substr($content, $class_start, $pos - $class_start + 1);
                        break;
                    }
                }
                $pos++;
            }

            if (empty($class_content)) {
                continue;
            }

            // Check for render() method definition
            $lines = explode("\n", $class_content);
            $line_offset = substr_count(substr($content, 0, $class_start), "\n");

            foreach ($lines as $line_num => $line) {
                $actual_line_number = $line_offset + $line_num + 1;
                $trimmed = trim($line);

                // Skip comments
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                // Check for render() method definition
                // Match: render() { or async render() {
                // But not: on_render() or other_render() or renderSomething()
                if (preg_match('/^(?:async\s+)?render\s*\([^)]*\)\s*\{/', $trimmed)) {
                    $this->add_violation(
                        $file_path,
                        $actual_line_number,
                        "Component class '{$class_name}' overrides render() method. This is not allowed in JQHTML components.",
                        trim($line),
                        'Use lifecycle methods instead: on_render() for initial render setup, on_create() for post-render initialization, or on_ready() for final setup. The render() method is reserved for the framework to render templates.',
                        'high'
                    );
                }

                // Also check for static render method (which would also be wrong)
                if (preg_match('/^static\s+(?:async\s+)?render\s*\([^)]*\)\s*\{/', $trimmed)) {
                    $this->add_violation(
                        $file_path,
                        $actual_line_number,
                        "Component class '{$class_name}' defines a static render() method. This is not allowed in JQHTML components.",
                        trim($line),
                        'Use lifecycle methods instead: on_render() for initial render setup, on_create() for post-render initialization, or on_ready() for final setup.',
                        'high'
                    );
                }
            }
        }
    }
}
