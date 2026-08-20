<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Check Component implementations for common AI agent mistakes
 * Validates that components follow correct patterns
 */
class JqhtmlComponentImplementation_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JQHTML-IMPL-01';
    }

    public function get_name(): string
    {
        return 'Jqhtml Component Implementation Check';
    }

    public function get_description(): string
    {
        return 'Validates Component subclasses follow correct patterns';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    /**
     * Run during manifest build for immediate feedback
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip if not a JavaScript file
        if (!isset($metadata['extension']) || $metadata['extension'] !== 'js') {
            return;
        }

        // Skip if no class defined
        if (!isset($metadata['class'])) {
            return;
        }

        // Check if this is a Component subclass (directly or indirectly)
        $is_component_subclass = false;
        if (isset($metadata['extends']) && $metadata['extends'] === 'Component') {
            $is_component_subclass = true;
        } elseif (isset($metadata['extends'])) {
            // Check inheritance chain via manifest
            try {
                $is_component_subclass = Manifest::js_is_subclass_of($metadata['class'], 'Component');
            } catch (\Exception $e) {
                // Manifest not ready or class not found - skip
                $is_component_subclass = false;
            }
        }

        if (!$is_component_subclass) {
            return;
        }

        // Check cache to avoid redundant validation
        $cache_key = $metadata['hash'] ?? md5($contents);
        if (RsxCache::get_persistent($cache_key, false) === true) {
            // Already validated
            return;
        }

        // Check for on_destroy method using manifest metadata (the correct method is on_stop)
        if (!empty($metadata['public_instance_methods'])) {
            foreach ($metadata['public_instance_methods'] as $method_name => $method_info) {
                if ($method_name === 'on_destroy') {
                    $line = $method_info['line'] ?? 1;
                    $this->throw_on_destroy_error($file_path, $line, $metadata['class']);
                }
            }
        }

        $lines = explode("\n", $contents);

        // Check for render() method and incorrect lifecycle methods
        foreach ($lines as $line_num => $line) {
            $trimmed = trim($line);

            // Check for render() method
            if (preg_match('/^render\s*\(/', $trimmed)) {
                $this->throw_render_method_error($file_path, $line_num + 1, $metadata['class'] ?? 'Unknown');
            }

            // Check for incorrect event method names (create, load, ready without on_ prefix)
            if (preg_match('/^(create|load|ready)\s*\(/', $trimmed, $matches)) {
                $method = $matches[1];
                $this->throw_lifecycle_method_error($file_path, $line_num + 1, $method);
            }
        }

        // Mark as validated in cache
        RsxCache::set_persistent($cache_key, true);
    }

    private function throw_render_method_error(string $file_path, int $line_number, string $class_name): void
    {
        $error_message = "==========================================\n";
        $error_message .= "FATAL: Jqhtml component should not have render() method\n";
        $error_message .= "==========================================\n\n";
        $error_message .= "File: {$file_path}\n";
        $error_message .= "Line: {$line_number}\n";
        $error_message .= "Class: {$class_name}\n\n";
        $error_message .= "Jqhtml components should not define a render() method.\n\n";
        $error_message .= "PROBLEM:\n";
        $error_message .= "The render() method is not part of the Component lifecycle.\n";
        $error_message .= "Jqhtml components use template files (.jqhtml) for rendering.\n\n";
        $error_message .= "INCORRECT:\n";
        $error_message .= "  class My_Component extends Component {\n";
        $error_message .= "      render() {\n";
        $error_message .= "          return '<div>...</div>';\n";
        $error_message .= "      }\n";
        $error_message .= "  }\n\n";
        $error_message .= "CORRECT:\n";
        $error_message .= "  // Create a template file: my_component.jqhtml\n";
        $error_message .= "  <div>\n";
        $error_message .= "      <%= content() %>\n";
        $error_message .= "  </div>\n\n";
        $error_message .= "  // JavaScript class handles logic:\n";
        $error_message .= "  class My_Component extends Component {\n";
        $error_message .= "      on_ready() {\n";
        $error_message .= "          // Component logic here\n";
        $error_message .= "      }\n";
        $error_message .= "  }\n\n";
        $error_message .= "WHY THIS MATTERS:\n";
        $error_message .= "- Jqhtml separates template from logic\n";
        $error_message .= "- Templates are pre-compiled for performance\n";
        $error_message .= "- The render() pattern is from React, not Jqhtml\n\n";
        $error_message .= "FIX:\n";
        $error_message .= "1. Remove the render() method\n";
        $error_message .= "2. Create a .jqhtml template file for the component\n";
        $error_message .= "3. Use lifecycle methods like on_create(), on_load(), on_ready()\n";
        $error_message .= "==========================================";

        throw new YoureDoingItWrongException($error_message);
    }

    private function throw_lifecycle_method_error(string $file_path, int $line_number, string $method_name): void
    {
        $error_message = "==========================================\n";
        $error_message .= "FATAL: Jqhtml lifecycle method missing 'on_' prefix\n";
        $error_message .= "==========================================\n\n";
        $error_message .= "File: {$file_path}\n";
        $error_message .= "Line: {$line_number}\n";
        $error_message .= "Method: {$method_name}()\n\n";
        $error_message .= "Jqhtml lifecycle methods must use the 'on_' prefix.\n\n";
        $error_message .= "PROBLEM:\n";
        $error_message .= "The method '{$method_name}()' should be 'on_{$method_name}()'.\n";
        $error_message .= "Jqhtml components use specific lifecycle method names.\n\n";
        $error_message .= "INCORRECT:\n";
        $error_message .= "  class My_Component extends Component {\n";
        $error_message .= "      create() { ... }  // Wrong\n";
        $error_message .= "      load() { ... }    // Wrong\n";
        $error_message .= "      ready() { ... }   // Wrong\n";
        $error_message .= "  }\n\n";
        $error_message .= "CORRECT:\n";
        $error_message .= "  class My_Component extends Component {\n";
        $error_message .= "      on_create() { ... }  // Correct\n";
        $error_message .= "      on_load() { ... }    // Correct\n";
        $error_message .= "      on_ready() { ... }   // Correct\n";
        $error_message .= "  }\n\n";
        $error_message .= "LIFECYCLE METHODS:\n";
        $error_message .= "- on_create(): Called when component is created\n";
        $error_message .= "- on_load(): Called to load async data\n";
        $error_message .= "- on_ready(): Called when component is ready in DOM\n";
        $error_message .= "- on_stop(): Called when component is destroyed\n\n";
        $error_message .= "FIX:\n";
        $error_message .= "Rename '{$method_name}()' to 'on_{$method_name}()'\n";
        $error_message .= "==========================================";

        throw new YoureDoingItWrongException($error_message);
    }

    private function throw_on_destroy_error(string $file_path, int $line_number, string $class_name): void
    {
        $error_message = "==========================================\n";
        $error_message .= "FATAL: Jqhtml component uses incorrect lifecycle method name\n";
        $error_message .= "==========================================\n\n";
        $error_message .= "File: {$file_path}\n";
        $error_message .= "Line: {$line_number}\n";
        $error_message .= "Class: {$class_name}\n\n";
        $error_message .= "The method 'on_destroy()' does not exist in jqhtml components.\n\n";
        $error_message .= "PROBLEM:\n";
        $error_message .= "The correct lifecycle method for cleanup is 'on_stop()', not 'on_destroy()'.\n";
        $error_message .= "This is a common mistake from other frameworks.\n\n";
        $error_message .= "INCORRECT:\n";
        $error_message .= "  class {$class_name} extends Component {\n";
        $error_message .= "      on_destroy() {\n";
        $error_message .= "          // cleanup code\n";
        $error_message .= "      }\n";
        $error_message .= "  }\n\n";
        $error_message .= "CORRECT:\n";
        $error_message .= "  class {$class_name} extends Component {\n";
        $error_message .= "      on_stop() {\n";
        $error_message .= "          // cleanup code\n";
        $error_message .= "      }\n";
        $error_message .= "  }\n\n";
        $error_message .= "LIFECYCLE METHODS:\n";
        $error_message .= "- on_create(): Called when component is created (sync)\n";
        $error_message .= "- on_render(): Called after template renders (sync)\n";
        $error_message .= "- on_load(): Called to load async data\n";
        $error_message .= "- on_ready(): Called when component is ready in DOM\n";
        $error_message .= "- on_stop(): Called when component is destroyed (sync)\n\n";
        $error_message .= "FIX:\n";
        $error_message .= "Rename 'on_destroy()' to 'on_stop()'\n";
        $error_message .= "==========================================";

        throw new YoureDoingItWrongException($error_message);
    }
}