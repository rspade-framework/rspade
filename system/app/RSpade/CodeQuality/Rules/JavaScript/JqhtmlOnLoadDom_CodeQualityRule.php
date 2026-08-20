<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\Core\Manifest\Manifest;

class JqhtmlOnLoadDom_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JQHTML-LOAD-01';
    }

    public function get_name(): string
    {
        return 'JQHTML on_load DOM Access Check';
    }

    public function get_description(): string
    {
        return 'on_load() method must not access DOM or call render() - only update this.data';
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
     * Check for DOM access in on_load methods of Component subclasses
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

            // Look for on_load method
            if (!preg_match('/(?:async\s+)?on_load\s*\([^)]*\)\s*\{/i', $class_content, $method_match, PREG_OFFSET_CAPTURE)) {
                continue; // No on_load method
            }

            $method_start = $method_match[0][1];

            // Extract the on_load method body
            $method_pos = $class_start + $method_start;
            $method_brace_count = 0;
            $in_method = false;
            $method_content = '';
            $pos = $method_pos;

            while ($pos < strlen($content)) {
                $char = $content[$pos];

                if ($char === '{') {
                    $method_brace_count++;
                    $in_method = true;
                } elseif ($char === '}') {
                    $method_brace_count--;
                    if ($method_brace_count === 0 && $in_method) {
                        $method_content = substr($content, $method_pos, $pos - $method_pos + 1);
                        break;
                    }
                }
                $pos++;
            }

            if (empty($method_content)) {
                continue;
            }

            // Check for DOM access patterns
            $lines = explode("\n", $method_content);
            $line_offset = substr_count(substr($content, 0, $method_pos), "\n");

            foreach ($lines as $line_num => $line) {
                $actual_line_number = $line_offset + $line_num + 1;
                $trimmed = trim($line);

                // Skip comments
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                // Check for this.$ (jQuery element access)
                // Match this.$ followed by anything except .ajax/.get/.post/.getJSON
                if (preg_match('/\bthis\.\$/', $line)) {
                    // Check if it's this.$.something (DOM manipulation) vs $.ajax usage
                    if (preg_match('/\bthis\.\$\./', $line)) {
                        // this.$. pattern - this is DOM manipulation
                        $this->add_violation(
                            $file_path,
                            $actual_line_number,
                            "DOM access 'this.\$' detected in on_load() method of class '{$class_name}'. The on_load() phase runs in parallel and must not access DOM.",
                            trim($line),
                            "To handle loading states, you have two options:\n" .
                            "1. Use conditional rendering in your .jqhtml template:\n" .
                            "   <% if (this.data === null): %>\n" .
                            "     <div class=\"loading\">Loading...</div>\n" .
                            "   <% else: %>\n" .
                            "     <div class=\"content\">...loaded content...</div>\n" .
                            "   <% endif; %>\n" .
                            "2. Set loading state in on_create() (before load) and clear it in on_ready() (after load).\n" .
                            '   on_load() should only update this.data properties.',
                            'high'
                        );
                    }
                }

                // Check for this.$id() calls
                if (preg_match('/\bthis\.\$id\s*\(/', $line)) {
                    $this->add_violation(
                        $file_path,
                        $actual_line_number,
                        "DOM access 'this.\$id()' detected in on_load() method of class '{$class_name}'. The on_load() phase runs in parallel and must not access DOM.",
                        trim($line),
                        "To handle loading states, you have two options:\n" .
                        "1. Use conditional rendering in your .jqhtml template:\n" .
                        "   <% if (this.data === null): %>\n" .
                        "     <div class=\"loading\">Loading...</div>\n" .
                        "   <% else: %>\n" .
                        "     <div class=\"content\">...loaded content...</div>\n" .
                        "   <% endif; %>\n" .
                        "2. Set loading state in on_create() (before load) and clear it in on_ready() (after load).\n" .
                        '   on_load() should only update this.data properties.',
                        'high'
                    );
                }

                // Check for jQuery selector usage (but allow $.ajax)
                if (preg_match('/\$\s*\([\'"`]/', $line) && !preg_match('/\$\.(ajax|get|post|getJSON)/', $line)) {
                    $this->add_violation(
                        $file_path,
                        $actual_line_number,
                        "jQuery selector '\$()' detected in on_load() method of class '{$class_name}'. The on_load() phase runs in parallel and must not access DOM.",
                        trim($line),
                        "To handle loading states, you have two options:\n" .
                        "1. Use conditional rendering in your .jqhtml template:\n" .
                        "   <% if (this.data === null): %>\n" .
                        "     <div class=\"loading\">Loading...</div>\n" .
                        "   <% else: %>\n" .
                        "     <div class=\"content\">...loaded content...</div>\n" .
                        "   <% endif; %>\n" .
                        "2. Set loading state in on_create() (before load) and clear it in on_ready() (after load).\n" .
                        '   on_load() should only update this.data properties. Use $.ajax() for data fetching.',
                        'high'
                    );
                }

                // Check for this.render() calls
                if (preg_match('/\bthis\.render\s*\(/', $line)) {
                    $this->add_violation(
                        $file_path,
                        $actual_line_number,
                        "Calling 'this.render()' in on_load() method of class '{$class_name}' is not allowed. Re-render happens automatically after on_load() if this.data changed.",
                        trim($line),
                        'Remove this.render() call. The framework automatically re-renders after on_load() if this.data was modified.',
                        'high'
                    );
                }
            }
        }
    }
}
