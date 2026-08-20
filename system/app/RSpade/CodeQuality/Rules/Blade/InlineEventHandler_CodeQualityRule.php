<?php

namespace App\RSpade\CodeQuality\Rules\Blade;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * Rule: BLADE-EVENT-01
 *
 * Detects inline event handlers (onclick, onchange, etc.) in Blade views and jqhtml templates.
 * Enforces separation of concerns - event handlers must be in companion .js files.
 *
 * For jqhtml templates, the @event syntax (e.g., @click="method_name") is allowed as an alternative,
 * but the event handler must reference a method on the companion JavaScript class, not inline code.
 */
class InlineEventHandler_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Common HTML event attributes to check for
     */
    private const EVENT_HANDLERS = [
        'onclick',
        'ondblclick',
        'onmousedown',
        'onmouseup',
        'onmouseover',
        'onmouseout',
        'onmousemove',
        'onmouseenter',
        'onmouseleave',
        'onchange',
        'onsubmit',
        'onkeydown',
        'onkeyup',
        'onkeypress',
        'onfocus',
        'onblur',
        'onload',
        'onunload',
        'oninput',
        'onselect',
        'onscroll',
        'onresize',
        'ondrag',
        'ondrop',
        'ondragstart',
        'ondragend',
        'ondragover',
        'ondragenter',
        'ondragleave',
        'ontouchstart',
        'ontouchend',
        'ontouchmove',
        'ontouchcancel',
    ];

    public function get_id(): string
    {
        return 'BLADE-EVENT-01';
    }

    public function get_name(): string
    {
        return 'Inline Event Handler Check';
    }

    public function get_description(): string
    {
        return 'Enforces no inline event handlers in Blade views and jqhtml templates - ' .
               'all event handlers must be in companion JavaScript files. ' .
               'For jqhtml templates, @event syntax is allowed but must reference class methods, not inline code.';
    }

    public function get_file_patterns(): array
    {
        return ['*.blade.php', '*.jqhtml'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * This rule should run during manifest scan to provide immediate feedback
     *
     * IMPORTANT: This method should ALWAYS return false unless explicitly requested
     * by the framework developer. Manifest-time checks are reserved for critical
     * framework convention violations that need immediate developer attention.
     *
     * Rules executed during manifest scan will run on every file change in development,
     * potentially impacting performance. Only enable this for rules that:
     * - Enforce critical framework conventions that would break the application
     * - Need to provide immediate feedback before code execution
     * - Have been specifically requested to run at manifest-time by framework maintainers
     *
     * DEFAULT: Always return false unless you have explicit permission to do otherwise.
     *
     * EXCEPTION: This rule has been explicitly approved to run at manifest-time because
     * inline event handlers in Blade/jqhtml files violate critical separation of concerns.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    /**
     * Process file during manifest update to extract inline event handler violations
     */
    public function on_manifest_file_update(string $file_path, string $contents, array $metadata = []): ?array
    {
        // Skip layout files (may have specific use cases)
        if (str_contains($file_path, 'layout') || str_contains($file_path, 'Layout')) {
            return null;
        }

        $is_jqhtml = str_ends_with($file_path, '.jqhtml');
        $lines = explode("\n", $contents);
        $violations = [];

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Check each event handler
            foreach (self::EVENT_HANDLERS as $event_attr) {
                // Pattern: onclick="..." or onclick='...' (but not @onclick which is allowed in jqhtml)
                // We need to make sure it's not preceded by @ symbol
                $pattern = '/(?<!@)\b' . $event_attr . '\s*=\s*["\'][^"\']*["\']/i';

                if (preg_match($pattern, $line, $matches)) {
                    $violations[] = [
                        'event_attr' => $event_attr,
                        'line' => $line_number,
                        'code' => trim($line),
                        'is_jqhtml' => $is_jqhtml,
                    ];
                    break; // Only need first violation per line
                }
            }
        }

        if (!empty($violations)) {
            return ['inline_event_violations' => $violations];
        }

        return null;
    }

    /**
     * Check file for inline event handler violations stored in metadata
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip layouts
        if (str_contains($file_path, 'layout') || str_contains($file_path, 'Layout')) {
            return;
        }

        // Check for violations in code quality metadata
        if (isset($metadata['code_quality_metadata']['BLADE-EVENT-01']['inline_event_violations'])) {
            $violations = $metadata['code_quality_metadata']['BLADE-EVENT-01']['inline_event_violations'];

            // Throw on first violation
            foreach ($violations as $violation) {
                $is_jqhtml = $violation['is_jqhtml'];
                $file_type = $is_jqhtml ? 'jqhtml template' : 'Blade view';

                $error_message = "Code Quality Violation (BLADE-EVENT-01) - Inline Event Handler in {$file_type}\n\n";
                $error_message .= "CRITICAL: Inline event handlers (e.g., {$violation['event_attr']}=\"...\") are not allowed\n\n";
                $error_message .= "File: {$file_path}\n";
                $error_message .= "Line: {$violation['line']}\n";
                $error_message .= "Code: {$violation['code']}\n\n";
                $error_message .= $this->get_detailed_remediation($file_path, $is_jqhtml);

                throw new \App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException(
                    $error_message,
                    0,
                    null,
                    base_path($file_path),
                    $violation['line']
                );
            }
        }
    }

    /**
     * Get detailed remediation instructions
     */
    private function get_detailed_remediation(string $file_path, bool $is_jqhtml): string
    {
        $path_parts = pathinfo($file_path);
        $base_name = $path_parts['filename'];

        // Remove .blade from blade.php files
        $base_name = str_replace('.blade', '', $base_name);

        // Determine JavaScript filename
        $js_filename = $base_name . '.js';
        $js_path = dirname($file_path) . '/' . $js_filename;

        // Convert to class name (handle snake_case to PascalCase if needed)
        $class_name = str_replace('_', ' ', $base_name);
        $class_name = str_replace(' ', '_', ucwords($class_name));

        if ($is_jqhtml) {
            return $this->get_jqhtml_remediation($js_path, $class_name, $base_name);
        } else {
            return $this->get_blade_remediation($js_path, $class_name);
        }
    }

    /**
     * Get remediation for Blade views
     */
    private function get_blade_remediation(string $js_path, string $class_name): string
    {
        return "FRAMEWORK CONVENTION: Event handlers must be in companion JavaScript files, not inline.

REQUIRED STEPS:
1. Remove the inline event handler attribute (e.g., onclick=\"...\")
2. Add an id or class to the element for jQuery selection
3. Create/update companion JavaScript file: {$js_path}
4. Bind the event handler in the on_app_ready() method

EXAMPLE IMPLEMENTATION:

Blade View (remove inline handler):
<!-- [ERROR] WRONG -->
<button onclick=\"doSomething()\">Click Me</button>

<!-- [OK] CORRECT -->
<button id=\"my-button\" class=\"btn btn-primary\">Click Me</button>

Companion JavaScript ({$js_path}):
/**
 * JavaScript for {$class_name} view
 */
class {$class_name} {
    /**
     * Initialize when app is ready
     * This method is automatically called by RSX framework
     */
    static on_app_ready() {
        // Only initialize if we're on this specific view
        if (!$('.{$class_name}').exists()) {
            return;
        }

        // Bind event handlers using jQuery
        $('#my-button').click(() => {
            // Event handler code here
            console.log('Button clicked');
        });
    }
}

KEY CONVENTIONS:
- Event handlers MUST be in companion .js file
- Use static on_app_ready() method (called automatically)
- Check for view presence before initializing
- Use jQuery .click(), .change(), etc. for event binding
- NO inline event handlers in HTML attributes

WHY THIS MATTERS:
- Separation of concerns: HTML structure separate from behavior
- Maintainability: All JavaScript in one place
- Testing: Event handlers can be unit tested
- Security: Reduces XSS attack surface
- LLM-friendly: Predictable patterns for AI code generation";
    }

    /**
     * Get remediation for jqhtml templates
     */
    private function get_jqhtml_remediation(string $js_path, string $class_name, string $template_name): string
    {
        return "FRAMEWORK CONVENTION: jqhtml templates should use @event syntax, not inline event handlers.

REQUIRED STEPS:
1. Replace inline event handler (onclick=\"...\") with @event syntax (@click)
2. Reference a method on the companion JavaScript class
3. Implement the method in {$js_path}

EXAMPLE IMPLEMENTATION:

jqhtml Template (use @event syntax):
<!-- [ERROR] WRONG - Inline event handler -->
<button onclick=\"doSomething()\">Click Me</button>

<!-- [ERROR] ALSO WRONG - Inline JavaScript code in @event -->
<button @click=\"console.log('clicked')\">Click Me</button>

<!-- [OK] CORRECT - Reference class method -->
<button @click=\"this.handle_click\">Click Me</button>

Companion JavaScript ({$js_path}):
/**
 * {$class_name} jqhtml component
 */
class {$class_name} extends Component {
    on_create() {
        // Initialize component state
        this.data.count = 0;
    }

    // Event handler method referenced in template
    handle_click() {
        this.data.count++;
        console.log('Clicked', this.data.count, 'times');
        this.render(); // Re-render if needed
    }
}

KEY CONVENTIONS FOR JQHTML:
- Use @event syntax in template (@click, @change, @submit, etc.)
- Event handlers MUST reference methods on the component class
- Method names should use underscore_case (e.g., handle_click)
- NO inline JavaScript code in @event attributes
- NO standard HTML event attributes (onclick, onchange, etc.)

COMMON @EVENT ATTRIBUTES:
- @click - Click events
- @change - Input/select change events
- @submit - Form submission
- @keyup, @keydown - Keyboard events
- @focus, @blur - Focus events

For more details on jqhtml event handling, see:
php artisan rsx:man jqhtml

WHY THIS MATTERS:
- Framework integration: @event syntax integrates with jqhtml lifecycle
- Automatic preventDefault: Framework handles event.preventDefault() automatically
- Component encapsulation: All behavior defined in component class
- Maintainability: Clear separation of template and logic
- Type safety: Methods can be validated and tested";
    }
}
