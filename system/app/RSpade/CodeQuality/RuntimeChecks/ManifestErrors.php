<?php

namespace App\RSpade\CodeQuality\RuntimeChecks;

use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;

/**
 * Developer-facing manifest validation errors
 *
 * These exceptions guide developers to follow RSX naming conventions
 * and code organization patterns during development.
 */
class ManifestErrors
{
    /**
     * File using invalid .old. naming pattern
     */
    public static function old_file_pattern(string $file_path): void
    {
        $error_message = "==========================================\n";
        $error_message .= "FATAL: Invalid file naming pattern detected\n";
        $error_message .= "==========================================\n\n";
        $error_message .= "File: {$file_path}\n\n";
        $error_message .= "The .old.(extension) naming convention is NOT ALLOWED in RSpade.\n\n";
        $error_message .= "WHY THIS IS A PROBLEM:\n";
        $error_message .= "Files are scanned by their file extension. A file named 'something.old.php'\n";
        $error_message .= "will still be treated as a .php file and included in bundles as live code.\n";
        $error_message .= "This can cause duplicate class definitions, outdated code execution, and\n";
        $error_message .= "other critical issues.\n\n";
        $error_message .= "CORRECT ALTERNATIVES:\n";
        $error_message .= "1. Rename to '.php.old' or '.js.old' (extension at the end)\n";
        $error_message .= "2. Move to an archive directory outside scan paths\n";
        $error_message .= "3. Delete the file if no longer needed\n\n";
        $error_message .= "RECOMMENDED ACTION:\n";
        $error_message .= "Move the file to /archived/ or delete it entirely.\n\n";
        $error_message .= "==========================================";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * JavaScript class using period in extends clause
     */
    public static function js_extends_with_period(
        string $file_path,
        string $class_name,
        string $extends
    ): void {
        $error_message = "==========================================\n";
        $error_message .= "FATAL: Invalid JavaScript class extends syntax\n";
        $error_message .= "==========================================\n\n";
        $error_message .= "File: {$file_path}\n";
        $error_message .= "Class: {$class_name}\n";
        $error_message .= "Extends: {$extends}\n\n";
        $error_message .= "JavaScript classes in RSX cannot use periods in the extends clause.\n\n";
        $error_message .= "PROBLEM:\n";
        $error_message .= "The extends clause contains '{$extends}' which includes a period.\n";
        $error_message .= "This breaks RSX class traversal and manifest processing.\n\n";
        $error_message .= "WHY THIS MATTERS:\n";
        $error_message .= "- RSX uses simple class names for inheritance tracking\n";
        $error_message .= "- Period notation suggests property access rather than class extension\n";
        $error_message .= "- The manifest system cannot properly track inheritance chains with dots\n";
        $error_message .= "- This pattern is inconsistent with RSX naming conventions\n\n";
        $error_message .= "SOLUTIONS:\n";
        $error_message .= "1. If extending an NPM module class, import it properly:\n";
        $error_message .= "   - Configure the NPM import in your bundle's npm includes\n";
        $error_message .= "   - Use the simple imported name without window prefix\n\n";
        $error_message .= "2. If extending a global class, ensure it's properly defined:\n";
        $error_message .= "   - Define the base class in a separate file\n";
        $error_message .= "   - Let RSX manifest make it globally available\n";
        $error_message .= "   - Use simple class name in extends\n\n";
        $error_message .= "EXAMPLES:\n";
        $error_message .= "[ERROR] BAD:  class MyComponent extends window.BaseComponent {}\n";
        $error_message .= "[OK] GOOD: class MyComponent extends BaseComponent {}\n\n";
        $error_message .= "[ERROR] BAD:  class Widget extends jqhtml.Component {}\n";
        $error_message .= "[OK] GOOD: class Widget extends Component {}\n\n";
        $error_message .= "This restriction maintains consistency and enables proper class hierarchy tracking.\n";
        $error_message .= "==========================================";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Route using invalid {param} syntax instead of :param
     */
    public static function invalid_route_syntax(
        string $file,
        string $method_name,
        string $pattern
    ): void {
        $error_message = "Fatal: Invalid route syntax detected.\n";
        $error_message .= "File: {$file}\n";
        $error_message .= "Method: {$method_name}\n";
        $error_message .= "Route: {$pattern}\n";
        $error_message .= "The {param} syntax is not supported. Use :param syntax instead.\n";
        $error_message .= "Example: Change '/users/{id}' to '/users/:id'";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Jqhtml component with render() method instead of template
     */
    public static function jqhtml_render_method(
        string $file,
        int $line_num,
        string $class_name
    ): void {
        $error_message = "Fatal: Incorrect Component implementation detected.\n\n";
        $error_message .= "File: {$file}\n";
        $error_message .= "Line {$line_num}: Found render() method\n\n";
        $error_message .= "PROBLEM: The render() method should not exist in JavaScript component classes.\n\n";
        $error_message .= "SOLUTION:\n";
        $error_message .= "1. Remove the render() method from {$class_name}.js\n";
        $error_message .= "2. Create a corresponding .jqhtml template file with the HTML\n\n";
        $error_message .= "EXAMPLE STRUCTURE:\n";
        $error_message .= "- Component class: /rsx/app/demo/components/User_Card.js (no render method)\n";
        $error_message .= "- Template file: /rsx/app/demo/components/User_Card.jqhtml\n\n";
        $error_message .= "TEMPLATE FORMAT (User_Card.jqhtml):\n";
        $error_message .= "<Define:{$class_name}>\n";
        $error_message .= "    <div class=\"{$class_name}\">\n";
        $error_message .= "        <!-- Your HTML here -->\n";
        $error_message .= "        <h3 \$id=\"title\"><%= this.data.title %></h3>\n";
        $error_message .= "        <button \$onclick=\"handle_click\">Click Me</button>\n";
        $error_message .= "    </div>\n";
        $error_message .= "</Define:{$class_name}>\n\n";
        $error_message .= "See /rsx/app/demo/components/Counter_Widget.jqhtml for a complete example.";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Jqhtml component with incorrect lifecycle method naming
     */
    public static function jqhtml_lifecycle_method(
        string $file,
        int $line_num,
        string $method
    ): void {
        $error_message = "Fatal: Incorrect Component lifecycle method detected.\n\n";
        $error_message .= "File: {$file}\n";
        $error_message .= "Line {$line_num}: Found '{$method}()' method\n\n";
        $error_message .= "PROBLEM: Component lifecycle methods must be prefixed with 'on_'\n\n";
        $error_message .= "SOLUTION: Rename the method:\n";
        $error_message .= "- '{$method}()' should be 'on_{$method}()'\n\n";
        $error_message .= "CORRECT LIFECYCLE METHODS:\n";
        $error_message .= "- on_create() - Setup initial state and bind events\n";
        $error_message .= "- on_load() - Fetch async data (no DOM manipulation)\n";
        $error_message .= "- on_ready() - Final setup after component is loaded\n\n";
        $error_message .= "All methods should be async if they need to wait for operations.\n\n";
        $error_message .= "EXAMPLE (from User_Card.js):\n";
        $error_message .= "async on_create() {\n";
        $error_message .= "    this.editing = false;\n";
        $error_message .= "}\n\n";
        $error_message .= "async on_load() {\n";
        $error_message .= "    // Fetch data here\n";
        $error_message .= "    return new Promise((resolve) => {\n";
        $error_message .= "        // Load data...\n";
        $error_message .= "        resolve();\n";
        $error_message .= "    });\n";
        $error_message .= "}\n\n";
        $error_message .= "async on_ready() {\n";
        $error_message .= "    this.\$.addClass('loaded');\n";
        $error_message .= "}";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * PHP file contains multiple class definitions
     */
    public static function multiple_classes_in_file(
        string $file_path,
        array $class_names
    ): void {
        $error_message = "Multiple classes detected in PHP file: {$file_path}\n";
        $error_message .= "Classes found: " . implode(', ', $class_names) . "\n";
        $error_message .= "PHP files must contain only one class per file.";

        throw new YoureDoingItWrongException($error_message);
    }
}
