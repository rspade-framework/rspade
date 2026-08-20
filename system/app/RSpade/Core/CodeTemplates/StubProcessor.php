<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\CodeTemplates;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Processes stub templates with placeholder replacements
 *
 * Uses Laravel's {{ placeholder }} syntax for template substitution
 */
class StubProcessor
{
    /**
     * Process a stub file with given replacements
     *
     * @param string $stub_name Name of the stub file (without .stub extension)
     * @param array $replacements Associative array of placeholder => value
     * @return string Processed content
     */
    public static function process(string $stub_name, array $replacements): string
    {
        $stub_path = __DIR__ . "/stubs/{$stub_name}.stub";

        if (!file_exists($stub_path)) {
            throw new \RuntimeException("Stub file not found: {$stub_path}");
        }

        $content = file_get_contents($stub_path);

        // Replace each placeholder
        foreach ($replacements as $key => $value) {
            $placeholder = "{{ {$key} }}";
            $content = str_replace($placeholder, $value, $content);
        }

        // Check for any unreplaced placeholders
        if (preg_match('/\{\{\s*\w+\s*\}\}/', $content, $matches)) {
            throw new \RuntimeException("Unreplaced placeholder found: {$matches[0]}");
        }

        return $content;
    }

    /**
     * Convert module/feature names to class name format
     * Example: "user_profile" becomes "User_Profile"
     *
     * @param string $name
     * @return string
     */
    public static function to_class_name(string $name): string
    {
        $parts = explode('_', $name);
        return implode('_', array_map('ucfirst', $parts));
    }

    /**
     * Convert module/feature names to human-readable title
     * Example: "user_profile" becomes "User Profile"
     *
     * @param string $name
     * @return string
     */
    public static function to_title(string $name): string
    {
        $parts = explode('_', $name);
        return implode(' ', array_map('ucfirst', $parts));
    }

    /**
     * Check if a directory is a submodule
     * A submodule has a layout file with @rsx_extends (not a bundle render)
     *
     * @param string $dir_path
     * @return bool
     */
    public static function is_submodule(string $dir_path): bool
    {
        if (!is_dir($dir_path)) {
            return false;
        }

        // Look for layout files
        $layout_files = glob($dir_path . '/*_layout.blade.php');

        if (empty($layout_files)) {
            return false;
        }

        // Check if the layout uses @rsx_extends
        foreach ($layout_files as $layout_file) {
            $content = file_get_contents($layout_file);
            if (strpos($content, '@rsx_extends') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the parent layout class for a submodule
     *
     * @param string $module_path
     * @return string
     */
    public static function get_parent_layout(string $module_path): string
    {
        // Look for the module's layout file
        $layout_files = glob($module_path . '/*_layout.blade.php');

        if (!empty($layout_files)) {
            $content = file_get_contents($layout_files[0]);
            // Extract the @rsx_id value
            if (preg_match("/@rsx_id\(['\"]([^'\"]+)['\"]\)/", $content, $matches)) {
                return $matches[1];
            }
        }

        // Return default layout name based on module
        $module_name = basename($module_path);
        return self::to_class_name($module_name) . '_Layout';
    }

    /**
     * Resolve parent route URL from existing controllers
     * Tries to find actual route URL from parent controllers to inherit URL structure
     *
     * @param string $module_name
     * @param string|null $submodule_name
     * @param string|null $feature_name
     * @return string|null Resolved URL or null if not found
     */
    protected static function __resolve_parent_route_url(
        string $module_name,
        ?string $submodule_name = null,
        ?string $feature_name = null
    ): ?string {
        // Try to find parent controller and extract its route
        $controller_attempts = [];

        if ($submodule_name && $feature_name) {
            // For subfeature: try Module_Submodule_Feature_Controller
            $controller_attempts[] = self::to_class_name("{$module_name}_{$submodule_name}_{$feature_name}") . '_Controller';
        } elseif ($feature_name) {
            // For subfeature (no submodule): try Module_Feature_Controller
            $controller_attempts[] = self::to_class_name("{$module_name}_{$feature_name}") . '_Controller';
        } elseif ($submodule_name) {
            // For submodule feature: try Module_Submodule_Controller with index action
            $controller_attempts[] = self::to_class_name("{$module_name}_{$submodule_name}") . '_Index_Controller';
        } else {
            // For module feature: try Module_Index_Controller
            $controller_attempts[] = self::to_class_name($module_name) . '_Index_Controller';
        }

        // Try each controller class
        foreach ($controller_attempts as $controller_class) {
            try {
                // Find controller file in manifest
                $controller_path = Manifest::php_find_class($controller_class);
                if (!$controller_path) {
                    continue;
                }

                // Parse controller file for #[Route] attribute
                $absolute_path = base_path($controller_path);
                if (!file_exists($absolute_path)) {
                    continue;
                }

                $content = file_get_contents($absolute_path);

                // Look for #[Route('...')] attribute on index method
                // Match: #[Route('/some/path')] or #[Route('/some/path', ...)]
                if (preg_match('/#\[Route\([\'"]([^\'"]+)[\'"]/m', $content, $matches)) {
                    return $matches[1]; // Return the route path
                }
            } catch (\Exception $e) {
                // Controller not found or error parsing - continue to next attempt
                continue;
            }
        }

        return null;
    }

    /**
     * Generate standard replacements for a feature
     *
     * @param string $module_name
     * @param string|null $submodule_name
     * @param string $feature_name
     * @param string|null $subfeature_name
     * @return array
     */
    public static function generate_replacements(
        string $module_name,
        ?string $submodule_name = null,
        string $feature_name = 'index',
        ?string $subfeature_name = null
    ): array {
        $replacements = [];

        // Basic naming
        $replacements['module_name'] = $module_name;
        $replacements['module_class'] = self::to_class_name($module_name);
        $replacements['module_title'] = self::to_title($module_name);
        $replacements['module_path'] = "rsx/app/{$module_name}";

        // Determine the full path and naming based on structure
        if ($submodule_name) {
            $replacements['submodule_name'] = $submodule_name;
            $replacements['submodule_class'] = self::to_class_name($submodule_name);
            $replacements['submodule_title'] = self::to_title($submodule_name);
            $replacements['submodule_css_class'] = self::to_class_name("{$module_name}_{$submodule_name}");

            if ($subfeature_name) {
                // Module > Submodule > Feature > Subfeature
                $controller_prefix = self::to_class_name("{$module_name}_{$submodule_name}_{$feature_name}_{$subfeature_name}");
                $file_prefix = "{$module_name}_{$submodule_name}_{$feature_name}_{$subfeature_name}";

                // Try to resolve parent route, fallback* to generated path
                $parent_url = self::__resolve_parent_route_url($module_name, $submodule_name, $feature_name);
                $route_path = $parent_url ? "{$parent_url}/{$subfeature_name}" : "/{$module_name}/{$submodule_name}/{$feature_name}/{$subfeature_name}";

                // Subfeature uses parent feature's namespace (not subfeature directory)
                $layout_class = self::to_class_name("{$module_name}_{$submodule_name}") . '_Layout';
                $bundle_class = self::to_class_name($module_name) . '_Bundle';
                $namespace = "Rsx\\App\\" . self::to_class_name($module_name) . "\\" . self::to_class_name($submodule_name) . "\\" . self::to_class_name($feature_name);
                $feature_description = "Handle {$subfeature_name} subfeature of {$feature_name} in {$submodule_name}";
            } else if ($feature_name !== 'index') {
                // Module > Submodule > Feature
                $controller_prefix = self::to_class_name("{$module_name}_{$submodule_name}_{$feature_name}");
                $file_prefix = "{$module_name}_{$submodule_name}_{$feature_name}";
                $route_path = "/{$module_name}/{$submodule_name}/{$feature_name}";
                $feature_description = "Handle {$feature_name} feature in {$submodule_name}";
            } else {
                // Module > Submodule > Index
                $controller_prefix = self::to_class_name("{$module_name}_{$submodule_name}") . '_Index';
                $file_prefix = "{$module_name}_{$submodule_name}_index";
                $route_path = "/{$module_name}/{$submodule_name}";
                $feature_description = "Handle index page for {$submodule_name}";
            }

            $layout_class = self::to_class_name("{$module_name}_{$submodule_name}") . '_Layout';
            $bundle_class = self::to_class_name($module_name) . '_Bundle';
            $namespace = "Rsx\\App\\" . self::to_class_name($module_name) . "\\" . self::to_class_name($submodule_name);
        } else if ($subfeature_name) {
            // Module > Feature > Subfeature (no submodule)
            $controller_prefix = self::to_class_name("{$module_name}_{$feature_name}_{$subfeature_name}");
            $file_prefix = "{$module_name}_{$feature_name}_{$subfeature_name}";

            // Try to resolve parent route, fallback* to generated path
            $parent_url = self::__resolve_parent_route_url($module_name, null, $feature_name);
            $route_path = $parent_url ? "{$parent_url}/{$subfeature_name}" : "/{$module_name}/{$feature_name}/{$subfeature_name}";

            $layout_class = self::to_class_name($module_name) . '_Layout';
            $bundle_class = self::to_class_name($module_name) . '_Bundle';
            $namespace = "Rsx\\App\\" . self::to_class_name($module_name) . "\\" . self::to_class_name($feature_name);
            $feature_description = "Handle {$subfeature_name} subfeature of {$feature_name}";
        } else if ($feature_name !== 'index') {
            // Module > Feature
            $controller_prefix = self::to_class_name("{$module_name}_{$feature_name}");
            $file_prefix = "{$module_name}_{$feature_name}";

            // Try to resolve module's index route, fallback* to generated path
            $parent_url = self::__resolve_parent_route_url($module_name, null, null);
            $route_path = $parent_url ? "{$parent_url}/{$feature_name}" : "/{$module_name}/{$feature_name}";

            $layout_class = self::to_class_name($module_name) . '_Layout';
            $bundle_class = self::to_class_name($module_name) . '_Bundle';
            $namespace = "Rsx\\App\\" . self::to_class_name($module_name) . "\\" . self::to_class_name($feature_name);
            $feature_description = "Handle {$feature_name} feature";
        } else {
            // Module > Index
            $controller_prefix = self::to_class_name($module_name) . '_Index';
            $file_prefix = "{$module_name}_index";
            // Special case: 'frontend' module goes to root /
            $route_path = $module_name === 'frontend' ? '/' : "/{$module_name}";
            $layout_class = self::to_class_name($module_name) . '_Layout';
            $bundle_class = self::to_class_name($module_name) . '_Bundle';
            $namespace = "Rsx\\App\\" . self::to_class_name($module_name);
            $feature_description = "Handle index page for {$module_name}";
        }

        // Common replacements
        $replacements['controller_prefix'] = $controller_prefix;
        $replacements['controller_class'] = $controller_prefix . '_Controller';
        $replacements['view_class'] = $controller_prefix;
        $replacements['js_class'] = $controller_prefix;
        $replacements['file_prefix'] = $file_prefix;
        $replacements['route_path'] = $route_path;
        $replacements['layout_class'] = $layout_class;
        $replacements['bundle_class'] = $bundle_class;
        $replacements['namespace'] = $namespace;
        $replacements['feature_description'] = $feature_description;

        // View-specific
        $replacements['page_title'] = self::to_title($subfeature_name ?? $feature_name);
        $replacements['view_path'] = $controller_prefix;

        // Determine extends layout
        if ($submodule_name) {
            $replacements['extends_layout'] = self::to_class_name("{$module_name}_{$submodule_name}") . '_Layout';
            $replacements['parent_layout_class'] = self::to_class_name($module_name) . '_Layout';
        } else {
            $replacements['extends_layout'] = $layout_class;
        }

        // Section declarations for views
        if ($submodule_name) {
            $replacements['section_declaration'] = "@section('submodule_content')";
            $replacements['section_end'] = "@endsection";
        } else {
            $replacements['section_declaration'] = "@section('content')";
            $replacements['section_end'] = "@endsection";
        }

        return $replacements;
    }

    /**
     * Generate SPA-mode replacements for a module/feature
     *
     * Reuses generate_replacements() for every derivation the two modes share
     * (namespace, route path, controller class, bundle class, titles) and adds
     * the keys only the SPA stubs consume. Kept as its own method so the two
     * scaffold modes stay visibly separate and a Blade stub can never silently
     * consume an SPA-only key.
     *
     * @param string $module_name
     * @param string $feature_name
     * @return array
     */
    public static function generate_spa_replacements(
        string $module_name,
        string $feature_name = 'index'
    ): array {
        $replacements = self::generate_replacements($module_name, null, $feature_name);

        $module_class = self::to_class_name($module_name);

        // In SPA mode EVERY feature (index included) lives in its own directory,
        // so the feature namespace always carries the feature segment while the
        // module-root files (bundle, bootstrap) keep the module namespace.
        $replacements['module_namespace'] = "Rsx\\App\\" . $module_class;
        $replacements['namespace'] = "Rsx\\App\\" . $module_class . "\\" . self::to_class_name($feature_name);

        // SPA bootstrap + layout live at the module root, one of each per module.
        $replacements['spa_controller_class'] = $module_class . '_Spa_Controller';
        $replacements['spa_controller_target'] = $module_class . '_Spa_Controller::index';
        $replacements['spa_layout_class'] = $module_class . '_Spa_Layout';

        // Action class: feature-focused (Clients_Index_Action), module-named for
        // the module's own index feature (Frontend_Index_Action).
        $action_base = $feature_name === 'index' ? $module_class : self::to_class_name($feature_name);
        $replacements['action_class'] = $action_base . '_Index_Action';

        // Every generated surface is login-gated; #[Auth] / @auth is mandatory.
        $replacements['auth_check'] = 'is_logged_in';

        // The SPA action's page title reads better as the feature (or module) title.
        $replacements['page_title'] = $feature_name === 'index'
            ? self::to_title($module_name)
            : self::to_title($feature_name);

        return $replacements;
    }
}
