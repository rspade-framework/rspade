<?php

namespace App\RSpade\Core\PHP;

use App\RSpade\Core\PHP\Filename_ShortName;

/**
 * Filename_Suggester - Utility for suggesting correct filenames based on RSX naming conventions
 *
 * Provides static methods for determining the correct filename for PHP/JS classes,
 * Blade files with @rsx_id, and Jqhtml components based on their identifiers and
 * directory structure.
 */
class Filename_Suggester
{
    /**
     * Get suggested filename for PHP/JS class files
     *
     * @param string $file Current file path (relative to project root)
     * @param string $class_name Class name
     * @param string $extension File extension (php, js)
     * @param bool $is_rspade True if in app/RSpade/, false if in rsx/
     * @param bool $is_jqhtml_component True if JS class extends Component
     * @return string Suggested filename
     */
    public static function get_suggested_class_filename(
        string $file,
        string $class_name,
        string $extension,
        bool $is_rspade,
        bool $is_jqhtml_component = false
    ): string {
        $dir_path = dirname($file);
        $short_name = static::extract_short_name($class_name, $dir_path);

        if ($is_rspade) {
            // app/RSpade: case-sensitive
            if ($short_name !== null && !file_exists(dirname(base_path($file)) . '/' . $short_name . '.' . $extension)) {
                return $short_name . '.' . $extension;
            }
            return $class_name . '.' . $extension;
        } else {
            // rsx/: For Jqhtml components, use snake_case
            if ($is_jqhtml_component) {
                $snake_case = static::pascal_to_snake_case($class_name);
                return strtolower($snake_case) . '.' . $extension;
            }

            // Regular classes
            if ($short_name !== null && !file_exists(dirname(base_path($file)) . '/' . strtolower($short_name) . '.' . $extension)) {
                return strtolower($short_name) . '.' . $extension;
            }
            return strtolower($class_name) . '.' . $extension;
        }
    }

    /**
     * Get suggested filename for Blade files
     *
     * @param string $file Current file path (relative to project root)
     * @param string $rsx_id The @rsx_id value
     * @param bool $is_rspade True if in app/RSpade/, false if in rsx/
     * @return string Suggested filename
     */
    public static function get_suggested_blade_filename(
        string $file,
        string $rsx_id,
        bool $is_rspade
    ): string {
        $dir_path = dirname($file);
        $short_name = static::extract_short_name($rsx_id, $dir_path);

        if ($is_rspade) {
            if ($short_name !== null && !file_exists(dirname(base_path($file)) . '/' . $short_name . '.blade.php')) {
                return $short_name . '.blade.php';
            }
            return $rsx_id . '.blade.php';
        } else {
            if ($short_name !== null && !file_exists(dirname(base_path($file)) . '/' . strtolower($short_name) . '.blade.php')) {
                return strtolower($short_name) . '.blade.php';
            }
            return strtolower($rsx_id) . '.blade.php';
        }
    }

    /**
     * Get suggested filename for Jqhtml component files
     *
     * @param string $file Current file path (relative to project root)
     * @param string $component_name Component name from <Define:>
     * @param bool $is_rspade True if in app/RSpade/, false if in rsx/
     * @return string Suggested filename
     */
    public static function get_suggested_jqhtml_filename(
        string $file,
        string $component_name,
        bool $is_rspade
    ): string {
        $dir_path = dirname($file);
        $short_name = static::extract_short_name($component_name, $dir_path);

        if ($is_rspade) {
            if ($short_name !== null && !file_exists(dirname(base_path($file)) . '/' . $short_name . '.jqhtml')) {
                return $short_name . '.jqhtml';
            }
            return $component_name . '.jqhtml';
        } else {
            // rsx/: use snake_case (lowercase with underscores)
            $snake_case = static::pascal_to_snake_case($component_name);
            return strtolower($snake_case) . '.jqhtml';
        }
    }

    /**
     * Convert PascalCase to snake_case
     * Inserts underscores before uppercase letters and before first digit in number sequences
     * Example: TestComponent1 -> Test_Component_1
     *
     * @param string $name PascalCase name
     * @return string snake_case name
     */
    public static function pascal_to_snake_case(string $name): string
    {
        // Insert underscore before uppercase letters (except first character)
        $result = preg_replace('/(?<!^)([A-Z])/', '_$1', $name);

        // Insert underscore before first digit in a run of digits
        $result = preg_replace('/(?<!^)(?<![0-9])([0-9])/', '_$1', $result);

        // Replace multiple consecutive underscores with single underscore
        $result = preg_replace('/_+/', '_', $result);

        return $result;
    }

    /**
     * Extract short name from class/id if directory structure matches prefix
     * Returns null if directory structure doesn't match or if short name rules aren't met
     *
     * Rules:
     * - Original name must have 3+ segments for short name to be allowed (2-segment names must use full name)
     * - Short name must have 2+ segments (exception: if original was 1 segment, short can be 1 segment)
     *
     * @param string $full_name Full class/component/id name
     * @param string $dir_path Directory path (relative to project root)
     * @return string|null Short name or null if not applicable
     */
    public static function extract_short_name(string $full_name, string $dir_path): ?string
    {
        // The suggester shares the enforcer's canonical algorithm (Core/PHP helper), so the
        // auto-fixer only ever proposes filenames the MANIFEST-FILENAME-01 enforcer accepts.
        // This adds the app/RSpade guard (framework files get NO short name) and uses the
        // anywhere-in-path prefix match rather than a tail-anchored one.
        return Filename_ShortName::extract_short_name($full_name, $dir_path);
    }
}
