<?php

namespace App\RSpade\CodeQuality\RuntimeChecks;

use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;

/**
 * Developer-facing view validation errors
 *
 * These exceptions guide developers to properly organize view assets
 * and follow RSX conventions for blade templates.
 */
class ViewErrors
{
    /**
     * View contains inline styles or scripts
     */
    public static function inline_assets_not_allowed(
        string $file_path,
        string $id,
        bool $has_style,
        bool $has_script
    ): void {
        $dir_path = dirname($file_path);
        $base_name = basename($file_path, '.blade.php');

        $error_message = "RSX View Validation Error: Inline styles and scripts are not allowed in view files.\n\n";
        $error_message .= "Violated in: {$file_path}\n";
        $error_message .= "ID: {$id}\n\n";
        $error_message .= "Inline <style> and <script> tags must be moved to separate files:\n\n";

        if ($has_style) {
            $scss_path = "{$dir_path}/{$base_name}.scss";
            $error_message .= "For styles, create: {$scss_path}\n";
            $error_message .= "----------------------------------------\n";
            $error_message .= ".{$id} {\n";
            $error_message .= "    // Move your <style> content here\n";
            $error_message .= "    // All styles should be nested within this class\n";
            $error_message .= "}\n";
            $error_message .= "----------------------------------------\n\n";
        }

        if ($has_script) {
            $js_path = "{$dir_path}/{$base_name}.js";
            $error_message .= "For scripts, create: {$js_path}\n";
            $error_message .= "----------------------------------------\n";
            $error_message .= "class {$id} {\n";
            $error_message .= "    static init() {\n";
            $error_message .= "        if (!\$(\".{$id}\").length) return;\n";
            $error_message .= "        \n";
            $error_message .= "        // Move your <script> content here\n";
            $error_message .= "    }\n";
            $error_message .= "}\n";
            $error_message .= "----------------------------------------\n\n";
        }

        $error_message .= "This ensures proper scoping and organization of assets.";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Layout missing required body class function
     */
    public static function layout_missing_body_class(string $layout_path): void
    {
        $error_message = "RSX Layout Validation Error: Layout is missing required body class function.\n\n";
        $error_message .= "Layout file: {$layout_path}\n\n";
        $error_message .= "The <body> tag must include the rsx_body_class() function:\n";
        $error_message .= '<body class="{{ rsx_body_class() }}">' . "\n\n";
        $error_message .= "This allows views to be properly styled with scoped CSS.";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Layout missing required bundle include
     */
    public static function layout_missing_bundle(string $layout_path, string $module_name): void
    {
        $bundle_class = ucfirst($module_name) . '_Bundle';

        $error_message = "RSX Layout Validation Error: Layout is missing required bundle include.\n\n";
        $error_message .= "Layout file: {$layout_path}\n\n";
        $error_message .= "All layout files must include a bundle. Add this to your <head> section:\n";
        $error_message .= '{!! ' . $bundle_class . '::render() !!}' . "\n\n";
        $error_message .= "Bundle files should be created at: ./rsx/app/{$module_name}/{$module_name}_bundle.php\n\n";
        $error_message .= "NOTE: If this layout is for email notifications or print views, rename the file to include\n";
        $error_message .= "'.mail.' or '.print.' in the filename to bypass this requirement.\n";
        $error_message .= "Examples: layout.mail.blade.php or invoice.print.blade.php\n\n";
        $error_message .= "Example bundle structure:\n";
        $error_message .= "----------------------------------------\n";
        $error_message .= "<?php\n";
        $error_message .= "namespace Rsx\\App\\" . ucfirst($module_name) . ";\n\n";
        $error_message .= "use App\\RSpade\\Core\\Bundle\\Rsx_Bundle_Abstract;\n\n";
        $error_message .= "class {$bundle_class} extends Rsx_Bundle_Abstract\n";
        $error_message .= "{\n";
        $error_message .= "    public static function define(): array\n";
        $error_message .= "    {\n";
        $error_message .= "        return [\n";
        $error_message .= "            'modules' => ['bootstrap5'],\n";
        $error_message .= "            'include' => ['rsx/app/{$module_name}/**'],\n";
        $error_message .= "        ];\n";
        $error_message .= "    }\n";
        $error_message .= "}\n";
        $error_message .= "----------------------------------------";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Top-most layout in chain is missing bundle include
     */
    public static function topmost_layout_missing_bundle(string $layout_path, string $layout_id): void
    {
        // Get the module name from path for bundle suggestion
        $path_parts = explode('/', $layout_path);
        $module_name = '';
        for ($i = count($path_parts) - 2; $i >= 0; $i--) {
            if ($path_parts[$i] === 'app' && isset($path_parts[$i + 1])) {
                $module_name = $path_parts[$i + 1];
                break;
            }
        }
        $bundle_class = ucfirst($module_name) . '_Bundle';

        $error_message = "RSX Layout Chain Error: Top-most layout missing bundle include.\n\n";
        $error_message .= "Layout: {$layout_id}\n";
        $error_message .= "File: {$layout_path}\n\n";
        $error_message .= "In embedded layout chains, the TOP-MOST layout MUST include a bundle.\n";
        $error_message .= "Add this to the <head> section of this layout:\n\n";
        $error_message .= '{!! ' . $bundle_class . '::render() !!}' . "\n\n";
        $error_message .= "IMPORTANT: Only the top-most layout should have a bundle.\n";
        $error_message .= "If this layout extends another layout, check that the parent\n";
        $error_message .= "layout has the bundle render call instead.\n\n";
        $error_message .= "Bundle hierarchy rule:\n";
        $error_message .= "- The highest parent layout includes the bundle\n";
        $error_message .= "- Child layouts extend parent layouts\n";
        $error_message .= "- Views extend layouts\n";
        $error_message .= "- Only ONE bundle per page is allowed";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Intermediate layout has bundle (only topmost should have it)
     */
    public static function intermediate_layout_has_bundle(string $layout_path, string $layout_id): void
    {
        $error_message = "RSX Layout Chain Error: Intermediate layout has bundle render.\n\n";
        $error_message .= "Layout: {$layout_id}\n";
        $error_message .= "File: {$layout_path}\n\n";
        $error_message .= "This layout extends another layout, so it should NOT render a bundle.\n";
        $error_message .= "Only the TOP-MOST layout in the chain should include a bundle.\n\n";
        $error_message .= "To fix this:\n";
        $error_message .= "1. Remove the {!! *_Bundle::render() !!} call from this layout\n";
        $error_message .= "2. Ensure the parent layout (that this extends) has the bundle render\n\n";
        $error_message .= "Layout hierarchy example:\n";
        $error_message .= "- app_layout.blade.php - HAS bundle render [OK]\n";
        $error_message .= "  - section_layout.blade.php - NO bundle render [OK]\n";
        $error_message .= "      - page.blade.php - NO bundle render [OK]\n\n";
        $error_message .= "Remember: Only ONE bundle per page is allowed.";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Layout is incomplete - has neither bundle nor @rsx_extends
     */
    public static function layout_incomplete(string $layout_path): void
    {
        $error_message = "RSX Layout Error: Incomplete layout configuration.\n\n";
        $error_message .= "File: {$layout_path}\n\n";
        $error_message .= "This layout has neither a bundle render nor @rsx_extends directive.\n\n";
        $error_message .= "A layout must either:\n";
        $error_message .= "1. Be embedded in another layout using @rsx_extends('Parent_Layout'), OR\n";
        $error_message .= "2. Be a complete HTML document with <html>, <head>, <body> tags and a bundle render\n\n";
        $error_message .= "For embedded layouts (extends another layout):\n";
        $error_message .= "Add at the top of your layout:\n";
        $error_message .= "@rsx_extends('Parent_Layout_Name')\n\n";
        $error_message .= "For top-level layouts (complete HTML document):\n";
        $error_message .= "Include the full HTML structure with a bundle in <head>:\n";
        $error_message .= "<!DOCTYPE html>\n";
        $error_message .= "<html>\n";
        $error_message .= "<head>\n";
        $error_message .= "    {!! Module_Bundle::render() !!}\n";
        $error_message .= "</head>\n";
        $error_message .= "<body class=\"{{ rsx_body_class() }}\">\n";
        $error_message .= "    @yield('content')\n";
        $error_message .= "</body>\n";
        $error_message .= "</html>";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Embedded layout trying to render bundle
     */
    public static function embedded_layout_has_bundle(string $layout_path): void
    {
        $error_message = "RSX Layout Error: Embedded layout has bundle render.\n\n";
        $error_message .= "File: {$layout_path}\n\n";
        $error_message .= "This layout uses @rsx_extends, making it an embedded layout.\n";
        $error_message .= "Embedded layouts should NOT render bundles.\n\n";
        $error_message .= "Only the top-most layout in the chain should render a bundle.\n\n";
        $error_message .= "To fix this:\n";
        $error_message .= "1. Remove the {!! *_Bundle::render() !!} call from this layout\n";
        $error_message .= "2. Ensure the parent layout (specified in @rsx_extends) has the bundle render\n\n";
        $error_message .= "Layout hierarchy example:\n";
        $error_message .= "- app_layout.blade.php - HAS bundle render [OK] (top-most)\n";
        $error_message .= "  - section_layout.blade.php - NO bundle, uses @rsx_extends [OK]\n";
        $error_message .= "      - page.blade.php - NO bundle, uses @rsx_extends [OK]";

        throw new YoureDoingItWrongException($error_message);
    }

    /**
     * Top-most layout incorrectly using @rsx_extends
     */
    public static function topmost_layout_has_extends(string $layout_path): void
    {
        $error_message = "RSX Layout Error: Top-most layout uses @rsx_extends.\n\n";
        $error_message .= "File: {$layout_path}\n\n";
        $error_message .= "This layout contains a complete HTML document (has </html> tag) and renders a bundle,\n";
        $error_message .= "making it a top-most layout. Top-most layouts should NOT use @rsx_extends.\n\n";
        $error_message .= "@rsx_extends is only for embedded layouts that are part of another layout.\n\n";
        $error_message .= "To fix this:\n";
        $error_message .= "Remove the @rsx_extends directive from this layout.\n\n";
        $error_message .= "Top-most layouts should:\n";
        $error_message .= "- Include complete HTML structure (<html>, <head>, <body>)\n";
        $error_message .= "- Render a bundle in the <head> section\n";
        $error_message .= "- NOT use @rsx_extends\n";
        $error_message .= "- Use @yield for content sections";

        throw new YoureDoingItWrongException($error_message);
    }
}