<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * NoPageLoadAnimationRule - Detect animations that occur on page load
 *
 * This rule ensures all page elements appear immediately on initial page load.
 * Animations are only allowed in specific scenarios:
 * - After loading data via AJAX (discouraged but allowed)
 * - In response to user interaction (click, checkbox, etc)
 * - For position:absolute overlays like modals
 */
class NoPageLoadAnimation_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Animation methods that we want to detect
     * Note: show() and hide() without duration are allowed as they're instant
     */
    private const ANIMATION_METHODS = [
        'animate',
        'fadeIn',
        'fadeOut',
        'fadeTo',
        'fadeToggle',
        'slideIn',
        'slideOut',
        'slideDown',
        'slideUp',
        'slideToggle'
    ];

    /**
     * Init methods where animations are not allowed (unless in anonymous function)
     */
    private const INIT_METHODS = [
        '_on_framework_core_define',
        '_on_framework_modules_define',
        '_on_framework_core_init',
        'on_app_modules_define',
        'on_app_define',
        '_on_framework_modules_init',
        'on_app_modules_init',
        'on_app_init',
        'on_app_ready'
    ];

    /**
     * Event binding methods that indicate user interaction
     */
    private const EVENT_METHODS = [
        'on',
        'click',
        'change',
        'submit',
        'keydown',
        'keyup',
        'keypress',
        'mouseenter',
        'mouseleave',
        'hover',
        'focus',
        'blur',
        'addEventListener'
    ];

    /**
     * AJAX methods that indicate data loading
     */
    private const AJAX_METHODS = [
        'ajax',
        'get',
        'post',
        'getJSON',
        'load',
        'done',
        'success',
        'complete',
        'then',
        'fetch'
    ];

    /**
     * Get the unique identifier for this rule
     */
    public function get_id(): string
    {
        return 'JS-ANIMATION-01';
    }

    /**
     * Get the default severity level
     */
    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Get the file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.js', '*.jqhtml'];
    }

    /**
     * Get the display name for this rule
     */
    public function get_name(): string
    {
        return 'No Page Load Animation';
    }

    /**
     * Get the description of what this rule checks
     */
    public function get_description(): string
    {
        return 'Detects animations on initial page load - all elements must appear immediately';
    }

    /**
     * Check the file contents for violations
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip minified files
        if (str_contains($file_path, '.min.js')) {
            return;
        }

        $lines = explode("\n", $contents);
        $in_init_method = false;
        $init_method_name = '';
        $brace_depth = 0;
        $init_method_depth = 0;

        foreach ($lines as $line_num => $line) {
            // Check if we're entering an init method
            if ($this->_is_entering_init_method($line)) {
                $in_init_method = true;
                $init_method_name = $this->_extract_method_name($line);
                $init_method_depth = $brace_depth;
            }

            // Track brace depth
            $brace_depth += substr_count($line, '{');
            $brace_depth -= substr_count($line, '}');

            // Check if we're leaving the init method
            if ($in_init_method && $brace_depth <= $init_method_depth) {
                $in_init_method = false;
                $init_method_name = '';
            }

            // If we're in an init method, check for animations
            if ($in_init_method) {
                // Check if this line is inside an anonymous function (including arrow functions)
                if ($this->_is_in_anonymous_function($lines, $line_num) ||
                    $this->_is_in_event_handler($lines, $line_num) ||
                    $this->_is_in_ajax_callback($lines, $line_num)) {
                    continue; // Allowed context
                }

                // Check for animation calls
                foreach (self::ANIMATION_METHODS as $method) {
                    // Pattern for jQuery style: .animate( or .fadeIn(
                    if (preg_match('/\.\s*' . preg_quote($method, '/') . '\s*\(/i', $line)) {
                        // Check for specific exceptions
                        if ($this->_is_allowed_animation($line, $lines, $line_num)) {
                            continue;
                        }

                        $this->add_violation(
                            $line_num + 1,
                            strpos($line, $method),
                            "Animation on page load detected: .{$method}()",
                            trim($line),
                            "Remove animation from {$init_method_name}(). Elements must appear immediately on page load.\n" .
                            "If you need to show/hide elements at page load, use .show() or .hide() instead of fade/slide effects.\n" .
                            "Animations are only allowed:\n" .
                            "- In response to user interaction (click, change, etc)\n" .
                            "- After AJAX data loading (discouraged)\n" .
                            "- For position:absolute overlays (modals)"
                        );
                    }
                }

                // Also check for direct opacity manipulation during init
                if (preg_match('/\.css\s*\(\s*[\'"]opacity[\'"]/', $line) &&
                    (str_contains($line, 'setTimeout') || str_contains($line, 'setInterval'))) {
                    $this->add_violation(
                        $line_num + 1,
                        strpos($line, 'opacity'),
                        "Delayed opacity change on page load detected",
                        trim($line),
                        "Remove opacity animation from {$init_method_name}(). Use CSS for initial styling."
                    );
                }
            }
        }
    }

    /**
     * Check if we're entering an init method
     */
    private function _is_entering_init_method(string $line): bool
    {
        foreach (self::INIT_METHODS as $method) {
            // Match: static method_name() or function method_name()
            if (preg_match('/(?:static\s+|function\s+)?' . preg_quote($method, '/') . '\s*\(/i', $line)) {
                return true;
            }
            // Match: method_name: function()
            if (preg_match('/' . preg_quote($method, '/') . '\s*:\s*function\s*\(/i', $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract the method name from a line
     */
    private function _extract_method_name(string $line): string
    {
        foreach (self::INIT_METHODS as $method) {
            if (str_contains($line, $method)) {
                return $method;
            }
        }
        return 'initialization';
    }

    /**
     * Check if current context is inside an anonymous function (including arrow functions)
     */
    private function _is_in_anonymous_function(array $lines, int $current_line): bool
    {
        // Count function depth by looking backwards
        $function_depth = 0;
        $paren_depth = 0;
        $brace_depth = 0;

        // Look backwards from current line to find function declarations
        for ($i = $current_line; $i >= 0; $i--) {
            $line = $lines[$i];

            // Count braces to track scope
            $brace_depth += substr_count($line, '}');
            $brace_depth -= substr_count($line, '{');

            // If we've exited all scopes, stop looking
            if ($brace_depth > 0) {
                break;
            }

            // Check for anonymous function patterns
            // Regular function: function() { or function(args) {
            if (preg_match('/function\s*\([^)]*\)\s*{/', $line)) {
                return true;
            }

            // Arrow function: () => { or (args) => {
            if (preg_match('/\([^)]*\)\s*=>\s*{/', $line)) {
                return true;
            }

            // Single arg arrow function: arg => {
            if (preg_match('/\w+\s*=>\s*{/', $line)) {
                return true;
            }

            // Common callback patterns: setTimeout, setInterval, forEach, map, filter, etc.
            if (preg_match('/(setTimeout|setInterval|forEach|map|filter|reduce|some|every|find)\s*\(\s*(function|\([^)]*\)\s*=>|\w+\s*=>)/', $line)) {
                return true;
            }

            // jQuery each pattern: .each(function() or .each((i, el) =>
            if (preg_match('/\.each\s*\(\s*(function|\([^)]*\)\s*=>)/', $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if current context is inside an event handler
     */
    private function _is_in_event_handler(array $lines, int $current_line): bool
    {
        // Look backwards for event binding within 10 lines
        $start = max(0, $current_line - 10);

        for ($i = $current_line; $i >= $start; $i--) {
            $line = $lines[$i];

            foreach (self::EVENT_METHODS as $event) {
                // Check for .on('click', or .click( patterns
                if (preg_match('/\.\s*' . preg_quote($event, '/') . '\s*\(/i', $line)) {
                    return true;
                }
                // Check for addEventListener
                if (str_contains($line, 'addEventListener')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if current context is inside an AJAX callback
     */
    private function _is_in_ajax_callback(array $lines, int $current_line): bool
    {
        // Look backwards for AJAX methods within 10 lines
        $start = max(0, $current_line - 10);

        for ($i = $current_line; $i >= $start; $i--) {
            $line = $lines[$i];

            foreach (self::AJAX_METHODS as $ajax) {
                if (preg_match('/\.\s*' . preg_quote($ajax, '/') . '\s*\(/i', $line) ||
                    preg_match('/\$\s*\.\s*' . preg_quote($ajax, '/') . '\s*\(/i', $line)) {
                    return true;
                }
            }

            // Check for promise patterns
            if (str_contains($line, '.then(') || str_contains($line, 'async ') || str_contains($line, 'await ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this is an allowed animation exception
     */
    private function _is_allowed_animation(string $line, array $lines, int $line_num): bool
    {
        // Check for modal or overlay keywords
        $allowed_selectors = [
            'modal',
            'overlay',
            'popup',
            'dialog',
            'tooltip',
            'dropdown-menu',
            'position-absolute',
            'position-fixed'
        ];

        foreach ($allowed_selectors as $selector) {
            if (str_contains(strtolower($line), $selector)) {
                return true;
            }
        }

        // Check if the element being animated has position:absolute in a nearby style
        // This is harder to detect statically, so we'll be conservative

        // Check for comments indicating AJAX loading
        if ($line_num > 0) {
            $prev_line = $lines[$line_num - 1];
            if (str_contains(strtolower($prev_line), 'ajax') ||
                str_contains(strtolower($prev_line), 'load') ||
                str_contains(strtolower($prev_line), 'fetch')) {
                return true;
            }
        }

        return false;
    }
}