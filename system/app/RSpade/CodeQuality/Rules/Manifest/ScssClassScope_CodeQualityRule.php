<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * ScssClassScope_CodeQualityRule - Enforces SCSS class scoping convention
 *
 * SCSS files in rsx/app/ and rsx/theme/components/ must be fully enclosed in a
 * single top-level class selector that matches their associated JavaScript class
 * or Blade view.
 *
 * Rules:
 * - rsx/app/**\/*.scss -> must match a Component subclass (action/layout/component) or Blade @rsx_id
 * - rsx/theme/components/**\/*.scss -> must match a Component subclass or Blade @rsx_id
 * - Filename must match the associated file's filename (different extension)
 * - EXCEPTION: Supplemental SCSS files may have different filenames if a primary SCSS file
 *   (with matching filename) already exists for that wrapper class. This allows splitting
 *   large stylesheets (e.g., by breakpoint or feature).
 * - Other SCSS files are not validated by this rule
 *
 * Additionally checks for nested component selectors - styling another component from within
 * this component's SCSS creates hidden coupling and scatters styles across files.
 *
 * NO EXEMPTIONS: Files in these paths MUST follow the convention. If a file truly
 * cannot follow the pattern, it must be moved outside these directories (e.g., to
 * rsx/theme/base/) but this requires explicit developer approval.
 */
class ScssClassScope_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Get the unique rule identifier
     */
    public function get_id(): string
    {
        return 'SCSS-SCOPE-01';
    }

    /**
     * Get human-readable rule name
     */
    public function get_name(): string
    {
        return 'SCSS Class Scope';
    }

    /**
     * Get rule description
     */
    public function get_description(): string
    {
        return 'Enforces SCSS files in rsx/app/ and rsx/theme/components/ are scoped to their associated action/layout/component class';
    }

    /**
     * Get file patterns this rule applies to
     */
    public function get_file_patterns(): array
    {
        return ['*.scss'];
    }

    /**
     * This rule runs during manifest scan for immediate feedback
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * This is a cross-file rule - needs full manifest context
     */
    public function is_incremental(): bool
    {
        return false;
    }

    /**
     * Get default severity for this rule
     */
    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Check SCSS files for proper class scoping
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;

        // Only check once per manifest build
        if ($already_checked) {
            return;
        }
        $already_checked = true;

        // Get all manifest files
        $files = Manifest::get_all();
        if (empty($files)) {
            return;
        }

        // Build lookup maps for quick matching
        // Components include Spa_Action and Spa_Layout since they extend Component
        $components = [];   // class_name => file_path (without extension)
        $blade_ids = [];    // id => file_path (without extension)

        foreach ($files as $file => $file_metadata) {
            $extension = $file_metadata['extension'] ?? '';

            // Collect Component subclasses (includes Spa_Action, Spa_Layout, and direct Component subclasses)
            if ($extension === 'js') {
                $class_name = $file_metadata['class'] ?? null;
                if ($class_name) {
                    try {
                        if (Manifest::js_is_subclass_of($class_name, 'Component')) {
                            $components[$class_name] = pathinfo($file, PATHINFO_FILENAME);
                        }
                    } catch (\Exception $e) {
                        // Class not found in inheritance chain, skip
                    }
                }
            }

            // Collect Blade @rsx_id values
            if ($extension === 'blade.php') {
                $id = $file_metadata['id'] ?? null;
                if ($id) {
                    // Remove .blade.php to get base filename
                    $filename = basename($file, '.blade.php');
                    $blade_ids[$id] = $filename;
                }
            }

            // Collect jqhtml component IDs (from <Define:Component_Name>)
            // These are Components without a companion .js file
            if ($extension === 'jqhtml') {
                $id = $file_metadata['id'] ?? null;
                if ($id) {
                    // Check if there's already a .js file for this component
                    // If so, skip - the .js file takes precedence
                    if (!isset($components[$id])) {
                        $filename = pathinfo($file, PATHINFO_FILENAME);
                        $components[$id] = $filename;
                    }
                }
            }
        }

        // Build map of wrapper classes that have a primary SCSS file (filename matches)
        // This allows supplemental SCSS files with different names for the same wrapper class
        $wrapper_classes_with_primary_scss = [];

        foreach ($files as $file => $file_metadata) {
            $extension = $file_metadata['extension'] ?? '';
            if ($extension !== 'scss') {
                continue;
            }

            $wrapper_class = $file_metadata['scss_wrapper_class'] ?? null;
            if ($wrapper_class === null) {
                continue;
            }

            $scss_filename = pathinfo($file, PATHINFO_FILENAME);

            // Check if this SCSS file's name matches its wrapper class's associated file
            $matched_filename = $components[$wrapper_class] ?? $blade_ids[$wrapper_class] ?? null;
            if ($matched_filename !== null && strcasecmp($scss_filename, $matched_filename) === 0) {
                $wrapper_classes_with_primary_scss[$wrapper_class] = true;
            }
        }

        // Now validate SCSS files
        foreach ($files as $file => $file_metadata) {
            $extension = $file_metadata['extension'] ?? '';

            if ($extension !== 'scss') {
                continue;
            }

            // Determine which validation to apply based on path
            $is_rsx_app = str_starts_with($file, 'rsx/app/');
            $is_theme_component = str_starts_with($file, 'rsx/theme/components/');

            // Skip files outside our enforcement paths
            if (!$is_rsx_app && !$is_theme_component) {
                continue;
            }

            // NO EXEMPTIONS - all files in these paths must follow the convention

            // Skip files that contain only variables and comments (no actual rules)
            if (!empty($file_metadata['scss_variables_only'])) {
                continue;
            }

            $wrapper_class = $file_metadata['scss_wrapper_class'] ?? null;
            $scss_filename = pathinfo($file, PATHINFO_FILENAME);

            $is_valid = $this->validate_scss_file($file, $wrapper_class, $scss_filename, $components, $blade_ids, $wrapper_classes_with_primary_scss);

            // If file passed basic validation, check for nested component selectors
            if ($is_valid && $wrapper_class !== null) {
                $this->check_nested_component_selectors($file, $wrapper_class, $components);
            }
        }
    }

    /**
     * Validate SCSS file against Component classes and Blade @rsx_id values
     *
     * @return bool True if file passed validation, false if violations were found
     */
    private function validate_scss_file(
        string $file,
        ?string $wrapper_class,
        string $scss_filename,
        array $components,
        array $blade_ids,
        array $wrapper_classes_with_primary_scss
    ): bool {
        // Check if file has a wrapper class
        if ($wrapper_class === null) {
            $this->add_violation(
                $file,
                1,
                "SCSS file must be fully enclosed in a single class rule (e.g., .My_Component { ... })",
                basename($file),
                $this->build_no_wrapper_suggestion($file),
                'critical'
            );
            return false;
        }

        // Check if wrapper class matches a Component or Blade @rsx_id
        $matched_filename = null;
        $match_type = null;

        if (isset($components[$wrapper_class])) {
            $matched_filename = $components[$wrapper_class];
            $match_type = 'Component';
        } elseif (isset($blade_ids[$wrapper_class])) {
            $matched_filename = $blade_ids[$wrapper_class];
            $match_type = 'Blade @rsx_id';
        }

        if ($matched_filename === null) {
            // Check if this is a BEM child class (contains __) where the parent is a known component
            if (strpos($wrapper_class, '__') !== false) {
                [$parent_class, $child_suffix] = explode('__', $wrapper_class, 2);
                if (isset($components[$parent_class]) || isset($blade_ids[$parent_class])) {
                    // This is a BEM child class - provide specialized guidance
                    $this->add_violation(
                        $file,
                        1,
                        "BEM child class '.{$wrapper_class}' must be nested within parent component block",
                        ".{$wrapper_class} { ... }",
                        $this->build_bem_child_suggestion($parent_class, $child_suffix),
                        'critical'
                    );
                    return false;
                }
            }

            $this->add_violation(
                $file,
                1,
                "SCSS wrapper class '{$wrapper_class}' does not match any Component class or Blade @rsx_id",
                ".{$wrapper_class} { ... }",
                $this->build_no_match_suggestion($file, $wrapper_class),
                'critical'
            );
            return false;
        }

        // Check if filename matches
        if (strcasecmp($scss_filename, $matched_filename) !== 0) {
            // Allow supplemental SCSS files if a primary file already exists for this wrapper class
            if (isset($wrapper_classes_with_primary_scss[$wrapper_class])) {
                // This is a supplemental file - filename mismatch is allowed
                return true;
            }

            $this->add_violation(
                $file,
                1,
                "SCSS filename '{$scss_filename}.scss' must match associated {$match_type} file '{$matched_filename}'. " .
                "Supplemental files with different names are allowed only if a primary file ('{$matched_filename}.scss') exists. " .
                "See: php artisan rsx:man scss",
                basename($file),
                $this->build_filename_mismatch_suggestion($file, $scss_filename, $matched_filename, $match_type),
                'critical'
            );
            return false;
        }

        return true;
    }

    /**
     * Build suggestion for files without a wrapper class
     */
    private function build_no_wrapper_suggestion(string $file): string
    {
        $lines = [];
        $lines[] = "SCSS files in rsx/app/ and rsx/theme/components/ must be fully enclosed";
        $lines[] = "in a single top-level class selector that matches their associated";
        $lines[] = "action, layout, component, or Blade view.";
        $lines[] = "";
        $lines[] = "This prevents CSS conflicts and ensures styles are scoped to the";
        $lines[] = "element they are intended to style.";
        $lines[] = "";
        $lines[] = "VALID ASSOCIATIONS:";
        $lines[] = "  - Spa_Action class (e.g., Frontend_Dashboard extends Spa_Action)";
        $lines[] = "  - Spa_Layout class (e.g., Frontend_Layout extends Spa_Layout)";
        $lines[] = "  - Component class (e.g., Sidebar_Nav extends Component)";
        $lines[] = "  - Blade @rsx_id (e.g., @rsx_id('Login_Index'))";
        $lines[] = "";
        $lines[] = "TO FIX:";
        $lines[] = "  Wrap ALL rules in the associated class:";
        $lines[] = "";
        $lines[] = "    .My_Component {";
        $lines[] = "        // ALL styles go here";
        $lines[] = "        .card { ... }";
        $lines[] = "        .button { ... }";
        $lines[] = "    }";
        $lines[] = "";
        $lines[] = "NO EXEMPTIONS are allowed in these directories. If this file cannot";
        $lines[] = "be associated to an action/layout/component/view, it may need to be";
        $lines[] = "moved to rsx/theme/ (outside components/) - but this is rare and";
        $lines[] = "requires explicit developer approval.";
        $lines[] = "";
        $lines[] = "See: php artisan rsx:man scss";

        return implode("\n", $lines);
    }

    /**
     * Build suggestion for wrapper class that doesn't match anything
     */
    private function build_no_match_suggestion(string $file, string $wrapper_class): string
    {
        // Convert wrapper class to a plausible BEM element name (lowercase with underscores)
        $bem_element = strtolower($wrapper_class);

        $lines = [];
        $lines[] = "The wrapper class '{$wrapper_class}' does not match any known";
        $lines[] = "action, layout, component, or Blade view.";
        $lines[] = "";
        $lines[] = "OPTION 1 - STANDALONE COMPONENT:";
        $lines[] = "  If this is a reusable component, create the matching class:";
        $lines[] = "  - Spa_Action: class {$wrapper_class} extends Spa_Action";
        $lines[] = "  - Spa_Layout: class {$wrapper_class} extends Spa_Layout";
        $lines[] = "  - Component: class {$wrapper_class} extends Component";
        $lines[] = "  - Blade view: @rsx_id('{$wrapper_class}')";
        $lines[] = "";
        $lines[] = "OPTION 2 - BEM CHILD ELEMENT (most common):";
        $lines[] = "  If this is part of a parent component (action, layout, or component),";
        $lines[] = "  use BEM naming in the PARENT's SCSS file instead of creating a";
        $lines[] = "  separate file:";
        $lines[] = "";
        $lines[] = "    Parent: Frontend_Layout";
        $lines[] = "    Element: {$bem_element}";
        $lines[] = "";
        $lines[] = "    SCSS (in Frontend_Layout.scss):";
        $lines[] = "      .Frontend_Layout {";
        $lines[] = "          &__{$bem_element} { ... }";
        $lines[] = "      }";
        $lines[] = "";
        $lines[] = "    HTML:";
        $lines[] = "      <nav class=\"Frontend_Layout__{$bem_element}\">";
        $lines[] = "";
        $lines[] = "  Then DELETE this orphaned SCSS file.";
        $lines[] = "";
        $lines[] = "OPTION 3 - GLOBAL STYLES (rare):";
        $lines[] = "  If this provides truly shared styles that cannot be scoped to any";
        $lines[] = "  component, move to rsx/theme/ (outside components/). This requires";
        $lines[] = "  explicit developer approval.";
        $lines[] = "";
        $lines[] = "See: php artisan rsx:man scss";

        return implode("\n", $lines);
    }

    /**
     * Build suggestion for BEM child class at top level
     */
    private function build_bem_child_suggestion(string $parent_class, string $child_suffix): string
    {
        $lines = [];
        $lines[] = "BEM child classes must be nested within their parent component block,";
        $lines[] = "not declared at the top level of the SCSS file.";
        $lines[] = "";
        $lines[] = "INSTEAD OF:";
        $lines[] = "    .{$parent_class}__{$child_suffix} {";
        $lines[] = "        // styles";
        $lines[] = "    }";
        $lines[] = "";
        $lines[] = "USE:";
        $lines[] = "    .{$parent_class} {";
        $lines[] = "        &__{$child_suffix} {";
        $lines[] = "            // styles";
        $lines[] = "        }";
        $lines[] = "    }";
        $lines[] = "";
        $lines[] = "This compiles to the same CSS (.{$parent_class}__{$child_suffix})";
        $lines[] = "but maintains proper component scoping in the source files.";
        $lines[] = "";
        $lines[] = "See: php artisan rsx:man scss";

        return implode("\n", $lines);
    }

    /**
     * Build suggestion for filename mismatch
     */
    private function build_filename_mismatch_suggestion(
        string $file,
        string $scss_filename,
        string $expected_filename,
        string $match_type
    ): string {
        $lines = [];
        $lines[] = "SCSS filename must match the associated {$match_type} file.";
        $lines[] = "";
        $lines[] = "Current:  {$scss_filename}.scss";
        $lines[] = "Expected: {$expected_filename}.scss";
        $lines[] = "";
        $lines[] = "TO FIX:";
        $lines[] = "  Rename the SCSS file:";
        $lines[] = "    mv {$scss_filename}.scss {$expected_filename}.scss";
        $lines[] = "";
        $lines[] = "See: php artisan rsx:man scss";

        return implode("\n", $lines);
    }

    /**
     * Check for nested component selectors within the wrapper class
     *
     * Detects patterns like .Some_Component { .Another_Component { ... } }
     * which indicates styling another component from within this component's SCSS.
     */
    private function check_nested_component_selectors(string $file, string $wrapper_class, array $components): void
    {
        // Read file contents
        $full_path = base_path() . '/' . $file;
        if (!file_exists($full_path)) {
            return;
        }

        $contents = file_get_contents($full_path);
        if ($contents === false) {
            return;
        }

        // Strip comments to avoid false positives
        $contents = $this->strip_scss_comments($contents);

        // Find the wrapper class block and extract its contents
        $wrapper_content = $this->extract_wrapper_block_content($contents, $wrapper_class);
        if ($wrapper_content === null) {
            return;
        }

        // Find all class selectors within the wrapper block
        // Pattern: . followed by uppercase letter, then word characters (letters, numbers, underscores)
        // Must NOT contain __ or -- (BEM elements/modifiers are allowed)
        preg_match_all('/\.([A-Z][A-Za-z0-9_]*)\s*\{/', $wrapper_content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            $nested_class = $match[0];

            // Skip if it's the wrapper class itself (shouldn't happen but be safe)
            if ($nested_class === $wrapper_class) {
                continue;
            }

            // Skip BEM elements and modifiers (contain __ or --)
            if (strpos($nested_class, '__') !== false || strpos($nested_class, '--') !== false) {
                continue;
            }

            // Skip if this class is not a known component (could be a utility class like .Container)
            // Only flag if it matches a known component
            if (!isset($components[$nested_class])) {
                continue;
            }

            // Find the line number for this match
            $position = $match[1];
            $line_number = $this->find_line_number_in_wrapper($contents, $wrapper_class, $nested_class);

            $this->add_violation(
                $file,
                $line_number,
                "Nested selector '.{$nested_class}' styles a different component. " .
                "Each component should define its own styles in its own SCSS file.",
                ".{$nested_class} { ... }",
                $this->build_nested_component_suggestion($wrapper_class, $nested_class),
                'high'
            );
        }
    }

    /**
     * Strip SCSS comments from content
     */
    private function strip_scss_comments(string $contents): string
    {
        // Remove single-line comments
        $contents = preg_replace('/\/\/.*$/m', '', $contents);

        // Remove multi-line comments
        $contents = preg_replace('/\/\*.*?\*\//s', '', $contents);

        return $contents;
    }

    /**
     * Extract the content inside a wrapper class block
     *
     * Given ".Wrapper { content }", returns "content"
     */
    private function extract_wrapper_block_content(string $contents, string $wrapper_class): ?string
    {
        // Find the wrapper class opening
        $pattern = '/\.' . preg_quote($wrapper_class, '/') . '\s*\{/';
        if (!preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start_pos = $matches[0][1] + strlen($matches[0][0]);

        // Find the matching closing brace
        $brace_count = 1;
        $length = strlen($contents);
        $pos = $start_pos;

        while ($pos < $length && $brace_count > 0) {
            $char = $contents[$pos];
            if ($char === '{') {
                $brace_count++;
            } elseif ($char === '}') {
                $brace_count--;
            }
            $pos++;
        }

        if ($brace_count !== 0) {
            return null;
        }

        // Return content between braces (excluding the final closing brace)
        return substr($contents, $start_pos, $pos - $start_pos - 1);
    }

    /**
     * Find line number of a nested class within the wrapper block
     */
    private function find_line_number_in_wrapper(string $contents, string $wrapper_class, string $nested_class): int
    {
        // Find the nested class after the wrapper class
        $pattern = '/\.' . preg_quote($wrapper_class, '/') . '\s*\{.*?\.' . preg_quote($nested_class, '/') . '\s*\{/s';
        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $position = $matches[0][1];
            // Count newlines up to this position
            $before = substr($contents, 0, $position + strlen($matches[0][0]));
            return substr_count($before, "\n") + 1;
        }

        return 1;
    }

    /**
     * Build suggestion for nested component selector
     */
    private function build_nested_component_suggestion(string $wrapper_class, string $nested_class): string
    {
        $wrapper_lower = strtolower(str_replace('_', '_', $wrapper_class));
        $nested_lower = strtolower(str_replace('_', '-', $nested_class));

        $lines = [];
        $lines[] = "Styling '.{$nested_class}' from within '.{$wrapper_class}' creates hidden coupling";
        $lines[] = "and scatters {$nested_class}'s styles across multiple files.";
        $lines[] = "";
        $lines[] = "Each component should own its own styles. If {$wrapper_class} needs to";
        $lines[] = "customize {$nested_class}'s appearance in this context, add a contextual";
        $lines[] = "class to the component in your template:";
        $lines[] = "";
        $lines[] = "  <{$nested_class} class=\"{$wrapper_class}__{$nested_lower}\" />";
        $lines[] = "";
        $lines[] = "Then style that class instead:";
        $lines[] = "";
        $lines[] = "  .{$wrapper_class} {";
        $lines[] = "      &__{$nested_lower} {";
        $lines[] = "          // custom styles for {$nested_class} in this context";
        $lines[] = "      }";
        $lines[] = "  }";
        $lines[] = "";
        $lines[] = "This keeps all contextual styling within {$wrapper_class}'s scope while";
        $lines[] = "allowing {$nested_class} to maintain its own base styles.";

        return implode("\n", $lines);
    }
}
