<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Detects use of jQuery .trigger() for custom events in jqhtml components.
 *
 * Custom events should use the jqhtml event bus (this.trigger()) instead of
 * jQuery's trigger() because the jqhtml bus supports firing events that
 * occurred before handlers were registered - critical for component lifecycle.
 */
class JqhtmlCustomEvent_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Standard jQuery/DOM events that are legitimate to trigger via jQuery
     */
    private const ALLOWED_EVENTS = [
        // Mouse events
        'click',
        'dblclick',
        'mousedown',
        'mouseup',
        'mouseover',
        'mouseout',
        'mouseenter',
        'mouseleave',
        'mousemove',
        'contextmenu',
        // Keyboard events
        'keydown',
        'keyup',
        'keypress',
        // Form events
        'submit',
        'change',
        'input',
        'focus',
        'blur',
        'focusin',
        'focusout',
        'select',
        'reset',
        // Touch events
        'touchstart',
        'touchend',
        'touchmove',
        'touchcancel',
        // Drag events
        'drag',
        'dragstart',
        'dragend',
        'dragover',
        'dragenter',
        'dragleave',
        'drop',
        // Scroll/resize
        'scroll',
        'resize',
        // Load events
        'load',
        'unload',
        'error',
        // Clipboard events
        'copy',
        'cut',
        'paste',
    ];

    public function get_id(): string
    {
        return 'JQHTML-EVENT-01';
    }

    public function get_name(): string
    {
        return 'JQHTML Custom Event Check';
    }

    public function get_description(): string
    {
        return 'Custom events in jqhtml components should use this.trigger() instead of jQuery .trigger()';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

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
            // Use Manifest to check inheritance
            if (!Manifest::js_is_subclass_of($class_name, 'Component')) {
                continue;
            }

            // The $contents passed to check() is already sanitized (strings removed).
            // We need the original file content to extract event names from string literals.
            $original_contents = file_get_contents($file_path);
            $original_lines = explode("\n", $original_contents);

            // Sanitized lines are used to skip comments
            $sanitized_lines = explode("\n", $contents);

            // Look for .trigger() calls on this.$ or that.$
            foreach ($original_lines as $line_number => $line) {
                $actual_line_number = $line_number + 1;

                // Use sanitized line to check if this is a comment
                $sanitized_line = $sanitized_lines[$line_number] ?? '';
                $trimmed = trim($sanitized_line);

                // Skip comments (check sanitized version which preserves comment markers)
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                // Check for exception comment on this line or previous line (use original)
                if (str_contains($line, '@' . $this->get_id() . '-EXCEPTION')) {
                    continue;
                }
                if ($line_number > 0 && str_contains($original_lines[$line_number - 1], '@' . $this->get_id() . '-EXCEPTION')) {
                    continue;
                }

                // Match patterns like: this.$.trigger( or that.$.trigger(
                // Also match: this.$sid('x').trigger( or that.$sid('x').trigger(
                if (preg_match('/(this|that)\.\$(?:sid\s*\([^)]+\))?\s*\.\s*trigger\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $matches)) {
                    $event_name = $matches[2];

                    // Check if it's an allowed standard event
                    if (in_array(strtolower($event_name), self::ALLOWED_EVENTS, true)) {
                        continue;
                    }

                    $this->add_violation(
                        $file_path,
                        $actual_line_number,
                        "Custom event '{$event_name}' triggered via jQuery .trigger() in Component class '{$class_name}'. " .
                        'Use the jqhtml event bus instead.',
                        trim($line),
                        "Use jqhtml event system for custom events:\n\n" .
                        "FIRING EVENTS:\n" .
                        "  Instead of: this.\$.trigger('{$event_name}', data)\n" .
                        "  Use:        this.trigger('{$event_name}', data)\n\n" .
                        "LISTENING FROM PARENT (using \$sid):\n" .
                        "  Instead of: this.\$sid('child').on('{$event_name}', handler)\n" .
                        "  Use:        this.sid('child').on('{$event_name}', handler)\n\n" .
                        "LISTENING FROM EXTERNAL CODE:\n" .
                        "  Instead of: \$(element).on('{$event_name}', handler)\n" .
                        "  Use:        \$(element).component().on('{$event_name}', handler)\n\n" .
                        "WHY: The jqhtml event bus fires callbacks for events that occurred before the handler " .
                        "was registered. This is critical for component lifecycle - parent components may " .
                        "register handlers after child components have already fired their events during initialization.",
                        'high'
                    );
                }
            }
        }
    }
}
