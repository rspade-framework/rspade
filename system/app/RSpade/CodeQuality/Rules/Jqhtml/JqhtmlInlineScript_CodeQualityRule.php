<?php

namespace App\RSpade\CodeQuality\Rules\Jqhtml;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * JqhtmlInlineScriptRule - Enforces no inline scripts or styles in .jqhtml template files
 *
 * This rule checks .jqhtml component template files for inline <script> or <style> tags
 * and provides remediation instructions for creating separate JS and SCSS files that
 * follow Jqhtml component patterns.
 */
class JqhtmlInlineScript_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JQHTML-INLINE-01';
    }

    public function get_name(): string
    {
        return 'Jqhtml Inline Script/Style Check';
    }

    public function get_description(): string
    {
        return 'Enforces no inline JavaScript or CSS in .jqhtml templates - must use separate component class and SCSS files';
    }

    public function get_file_patterns(): array
    {
        return ['*.jqhtml'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
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
     * inline scripts/styles in Jqhtml files violate critical framework architecture patterns.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true; // Explicitly approved for manifest-time checking
    }

    /**
     * Process file during manifest update to extract inline script/style violations
     */
    public function on_manifest_file_update(string $file_path, string $contents, array $metadata = []): ?array
    {
        $lines = explode("\n", $contents);
        $violations = [];

        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Check for <script> tags (excluding external script src)
            if (preg_match('/<script\b[^>]*>(?!.*src=)/i', $line)) {
                $violations[] = [
                    'type' => 'inline_script',
                    'line' => $line_number,
                    'code' => trim($line)
                ];
                break; // Only need to find first violation
            }

            // Check for <style> tags
            if (preg_match('/<style\b[^>]*>/i', $line)) {
                $violations[] = [
                    'type' => 'inline_style',
                    'line' => $line_number,
                    'code' => trim($line)
                ];
                break; // Only need to find first violation
            }
        }

        if (!empty($violations)) {
            return ['jqhtml_inline_violations' => $violations];
        }

        return null;
    }

    /**
     * Check jqhtml file for inline script/style violations stored in metadata
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Check for violations in code quality metadata
        if (isset($metadata['code_quality_metadata']['JQHTML-INLINE-01']['jqhtml_inline_violations'])) {
            $violations = $metadata['code_quality_metadata']['JQHTML-INLINE-01']['jqhtml_inline_violations'];

            // Throw on first violation
            foreach ($violations as $violation) {
                $component_id = $this->extract_component_id($contents);

                if ($violation['type'] === 'inline_script') {
                    $error_message = "Code Quality Violation (JQHTML-INLINE-01) - Inline Script in Jqhtml Template\n\n";
                    $error_message .= "CRITICAL: Inline <script> tags are not allowed in .jqhtml templates\n\n";
                    $error_message .= "File: {$file_path}\n";
                    $error_message .= "Line: {$violation['line']}\n";
                    $error_message .= "Code: {$violation['code']}\n\n";
                    $error_message .= $this->get_script_remediation($file_path, $component_id);
                } else {
                    $error_message = "Code Quality Violation (JQHTML-INLINE-01) - Inline Style in Jqhtml Template\n\n";
                    $error_message .= "CRITICAL: Inline <style> tags are not allowed in .jqhtml templates\n\n";
                    $error_message .= "File: {$file_path}\n";
                    $error_message .= "Line: {$violation['line']}\n";
                    $error_message .= "Code: {$violation['code']}\n\n";
                    $error_message .= $this->get_style_remediation($file_path, $component_id);
                }

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
     * Extract component ID from jqhtml file content
     * Looks for <Define:ComponentName> pattern
     */
    private function extract_component_id(string $contents): ?string
    {
        if (preg_match('/<Define:([A-Z][A-Za-z0-9_]*)>/', $contents, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Get detailed remediation instructions for scripts
     */
    private function get_script_remediation(string $file_path, ?string $component_id): string
    {
        // Determine the JS filename and class name
        $path_parts = pathinfo($file_path);
        $base_name = $path_parts['filename'];

        // Use component ID if available, otherwise use filename
        $class_name = $component_id ?: str_replace('_', '', ucwords($base_name, '_'));
        $js_filename = $path_parts['filename'] . '.js';
        $js_path = dirname($file_path) . '/' . $js_filename;

        return "FRAMEWORK CONVENTION: JavaScript for jqhtml components must be in separate ES6 class files.

REQUIRED STEPS:
1. Create a JavaScript file: {$js_path}
2. Name the ES6 class exactly: {$class_name}
3. Extend Component base class
4. Implement lifecycle methods: on_create(), on_load(), on_ready()

EXAMPLE IMPLEMENTATION for {$js_filename}:

/**
 * Component class for {$class_name}
 */
class {$class_name} extends Component {
    /**
     * Called when component instance is created
     * Use for initial setup and event binding
     */
    async on_create() {
        // Initialize component state
        this.state = {
            count: 0,
            loading: false
        };

        // Bind events to elements with \$id attribute in template
        // Example: <button \$onclick=\"handle_click\">Click Me</button>
    }

    /**
     * Called to load data (before rendering)
     * Use for async data fetching - NO DOM manipulation here
     */
    async on_load() {
        // Fetch any required data
        // this.data contains data passed to component
        // Example:
        // const response = await fetch('/api/data');
        // this.remote_data = await response.json();
    }

    /**
     * Called after component is fully rendered and ready
     * Use for final DOM setup
     */
    async on_ready() {
        // Component is fully loaded and rendered
        // The \$. property gives you the jQuery element
        this.\$.addClass('loaded');

        // Access template elements via \$id
        // Example: this.\$.find('[data-id=\"title\"]')
    }

    // Event handlers referenced in template
    handle_click(event) {
        this.state.count++;
        this.render(); // Re-render component with new state
    }
}

KEY CONVENTIONS:
- Class name MUST match the <Define:{$class_name}> in the .jqhtml file
- MUST extend Component base class
- Use lifecycle methods: on_create(), on_load(), on_ready()
- Access component element via this.\$
- Bind events using \$onclick, \$onchange, etc. in template
- Use this.render() to re-render with updated state
- NO inline scripts in the .jqhtml template

WHY THIS MATTERS:
- Separation of concerns: Template structure separate from behavior
- Component reusability: Clean component architecture
- Framework integration: Automatic lifecycle management
- Testability: JavaScript logic can be tested independently
- Performance: Components only initialize when needed";
    }

    /**
     * Get detailed remediation instructions for styles
     */
    private function get_style_remediation(string $file_path, ?string $component_id): string
    {
        // Determine the SCSS filename and class name
        $path_parts = pathinfo($file_path);
        $scss_filename = $path_parts['filename'] . '.scss';
        $scss_path = dirname($file_path) . '/' . $scss_filename;

        // Use component ID if available, otherwise use filename
        $class_name = $component_id ?: str_replace('_', '', ucwords($path_parts['filename'], '_'));

        return "FRAMEWORK CONVENTION: Styles for jqhtml components must be in separate SCSS files.

REQUIRED STEPS:
1. Create a SCSS file: {$scss_path}
2. Wrap ALL styles in .{$class_name} selector
3. Every instance of the component will have class=\"{$class_name}\" automatically

EXAMPLE IMPLEMENTATION for {$scss_filename}:

/**
 * Styles for {$class_name} component
 */
.{$class_name} {
    // All component styles MUST be nested within this class
    // This ensures styles are scoped to this component only

    // Component container styles
    display: block;
    padding: 1rem;
    border: 1px solid \$border-color;
    border-radius: 4px;

    // Child element styles
    .header {
        font-size: 1.2rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
    }

    .content {
        padding: 0.5rem 0;

        .item {
            margin: 0.25rem 0;
        }
    }

    // State-based styles
    &.loaded {
        opacity: 1;
        transition: opacity 0.3s;
    }

    &.loading {
        opacity: 0.5;
        pointer-events: none;
    }

    // Responsive styles
    @media (max-width: 768px) {
        padding: 0.5rem;
    }
}

KEY CONVENTIONS:
- ALL styles MUST be nested within .{$class_name} { }
- Component automatically gets class=\"{$class_name}\" on root element
- Use SCSS nesting for child elements
- Use & for state modifiers (&.loaded, &.active)
- Import shared variables if needed (\$border-color, etc.)
- NO inline styles in the .jqhtml template
- NO global styles that could affect other components

WHY THIS MATTERS:
- Style encapsulation: Component styles don't leak to other components
- Predictable cascade: Clear style hierarchy within component
- Reusability: Component can be used multiple times without conflicts
- Maintainability: Styles organized with their components
- Framework convention: Consistent pattern across all components";
    }
}