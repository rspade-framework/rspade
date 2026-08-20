<?php

namespace App\RSpade\CodeQuality\Support;

/**
 * Provides shared initialization method suggestions based on code location
 */
class InitializationSuggestions
{
    /**
     * Get appropriate lifecycle method suggestions based on file path
     *
     * @param string $file_path The path to the JavaScript file
     * @return string Formatted suggestion text
     */
    public static function get_suggestion(string $file_path): string
    {
        $is_framework_code = str_contains($file_path, '/app/RSpade/');
        $is_user_code = str_contains($file_path, '/rsx/');

        if ($is_framework_code) {
            return "Use framework lifecycle methods in ES6 classes:\n" .
                   "  - _on_framework_core_define() - For core framework metadata\n" .
                   "  - _on_framework_core_init() - For core framework initialization\n" .
                   "  - _on_framework_module_define() - For framework module metadata\n" .
                   "  - _on_framework_module_init() - For framework module initialization\n" .
                   "These methods are automatically called by the RSpade JS runtime.";
        } elseif ($is_user_code) {
            return "Use ES6 class lifecycle methods:\n" .
                   "  - on_app_ready() - For final initialization (most common)\n" .
                   "  - on_app_init() - For app-level setup\n" .
                   "  - on_modules_init() - For module initialization\n" .
                   "  - on_modules_define() - For module metadata registration\n" .
                   "These methods are automatically called by the RSpade JS runtime.";
        } else {
            return "Create an ES6 class with static lifecycle methods. These will be called automatically by the RSpade JS runtime.";
        }
    }

    /**
     * Get framework-only method suggestions
     *
     * @return string Formatted suggestion text for framework methods
     */
    public static function get_framework_suggestion(): string
    {
        return "Use framework lifecycle methods instead:\n" .
               "  - _on_framework_core_define() - For core framework metadata\n" .
               "  - _on_framework_core_init() - For core framework initialization\n" .
               "  - _on_framework_module_define() - For framework module metadata\n" .
               "  - _on_framework_module_init() - For framework module initialization";
    }

    /**
     * Get user-only method suggestions
     *
     * @return string Formatted suggestion text for user methods
     */
    public static function get_user_suggestion(): string
    {
        return "Use user code lifecycle methods instead:\n" .
               "  - on_app_ready() - For final initialization (most common)\n" .
               "  - on_app_init() - For app-level setup\n" .
               "  - on_modules_init() - For module initialization\n" .
               "  - on_modules_define() - For module metadata registration";
    }
}