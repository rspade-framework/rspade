<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * FilenameClassMatch_CodeQualityRule - Enforces filename matches class name
 *
 * Ensures that files containing classes have filenames that match the class name.
 * - app/RSpade: case-sensitive exact match required
 * - rsx: case-insensitive match allowed (snake_case encouraged)
 */
class FilenameClassMatch_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'MANIFEST-FILENAME-01';
    }

    public function get_name(): string
    {
        return 'Filename Class Match';
    }

    public function get_description(): string
    {
        return 'Ensures filenames match the class names they contain';
    }

    public function get_file_patterns(): array
    {
        return ['*.php', '*.js'];
    }

    public function is_called_during_manifest_scan(): bool
    {
        return false;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;

        // Only check once per manifest build
        if ($already_checked) {
            return;
        }
        $already_checked = true;

        // Check if filename conventions are globally disabled
        if (config('rsx.development.ignore_filename_convention', false)) {
            return;
        }

        // Get all manifest files
        $files = \App\RSpade\Core\Manifest\Manifest::get_all();
        if (empty($files)) {
            return;
        }

        // Check if framework developer mode is enabled
        $is_framework_developer = env('IS_FRAMEWORK_DEVELOPER', false);

        foreach ($files as $file => $metadata) {
            // Skip backup/upstream files
            if (str_ends_with($file, '.upstream') || str_ends_with($file, '.backup')) {
                continue;
            }

            // Only check files in ./rsx or ./app/RSpade
            $is_rsx = str_starts_with($file, 'rsx/');
            $is_rspade = str_starts_with($file, 'app/RSpade/');

            if (!$is_rsx && !$is_rspade) {
                continue;
            }

            // app/RSpade/ files only checked in framework developer mode
            if ($is_rspade && !$is_framework_developer) {
                continue;
            }

            $extension = $metadata['extension'] ?? '';
            $filename = basename($file);

            // Check PHP/JS files with classes
            if (isset($metadata['class'])) {
                $this->check_class_filename($file, $metadata['class'], $extension, $filename, $is_rsx, $is_rspade);
            }

            // Check blade.php files with @rsx_id
            if ($extension === 'blade.php' && isset($metadata['id'])) {
                $this->check_blade_filename($file, $metadata['id'], $filename, $is_rsx, $is_rspade);
            }

            // Check jqhtml files with Define:
            if ($extension === 'jqhtml' && isset($metadata['id'])) {
                $this->check_jqhtml_filename($file, $metadata['id'], $filename, $is_rsx, $is_rspade);
            }
        }
    }

    private function check_class_filename(string $file, string $class_name, string $extension, string $filename, bool $is_rsx, bool $is_rspade): void
    {
        $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);

        // Check if this is a JavaScript class extending Component
        $is_jqhtml_component = false;
        if ($extension === 'js') {
            try {
                $is_jqhtml_component = \App\RSpade\Core\Manifest\Manifest::js_is_subclass_of($class_name, 'Component');
            } catch (\Exception $e) {
                // Class not in manifest or not a JS class, treat as regular class
                $is_jqhtml_component = false;
            }
        }

        // For rsx/ Jqhtml components, allow flexible naming like jqhtml files
        if ($is_rsx && $is_jqhtml_component) {
            // Check exact match (case-insensitive)
            if (strtolower($filename_without_ext) === strtolower($class_name)) {
                return;
            }

            // Check snake_case variations
            $snake_case = $this->pascal_to_snake_case($class_name);
            if (strtolower($filename_without_ext) === strtolower($snake_case)) {
                return;
            }

            // Check short name variations
            $dir_path = dirname($file);
            $short_name = $this->extract_short_name($class_name, $dir_path);
            if ($short_name !== null) {
                $short_snake = $this->pascal_to_snake_case($short_name);
                if (strtolower($filename_without_ext) === strtolower($short_name) ||
                    strtolower($filename_without_ext) === strtolower($short_snake)) {
                    return;
                }
            }
        } else {
            // Regular class filename matching
            $matches_full = $is_rspade
                ? $filename_without_ext === $class_name
                : strtolower($filename_without_ext) === strtolower($class_name);

            if ($matches_full) {
                return; // Perfect match
            }

            // Check if short filename is valid (directory structure matches prefix)
            $dir_path = dirname($file);
            $short_name = $this->extract_short_name($class_name, $dir_path);

            if ($short_name !== null) {
                $matches_short = $is_rspade
                    ? $filename_without_ext === $short_name
                    : strtolower($filename_without_ext) === strtolower($short_name);

                if ($matches_short) {
                    return; // Valid short name
                }
            }
        }

        // Violation: filename doesn't match
        // Determine suggested filename
        $suggested_filename = $this->get_suggested_filename_from_remediation(
            $file, $class_name, $extension, $is_rspade, $is_jqhtml_component
        );

        // Check if we should auto-rename or throw violation
        if ($this->should_auto_rename_or_throw_violation($file, $suggested_filename, $is_rsx)) {
            return; // File was renamed, manifest will restart
        }

        // Add violation
        if ($is_rspade) {
            $this->add_violation(
                $file,
                1,
                "Filename '$filename' must match class name '$class_name' (case-sensitive)",
                "class $class_name",
                $this->get_class_remediation($file, $class_name, $filename, $extension, $is_rspade, $is_jqhtml_component),
                'high'
            );
        } else {
            $context = $is_jqhtml_component ? ' (Jqhtml component)' : '';
            $this->add_violation(
                $file,
                1,
                "Filename '$filename' must match class name '$class_name' (case-insensitive in rsx/){$context}",
                "class $class_name",
                $this->get_class_remediation($file, $class_name, $filename, $extension, $is_rspade, $is_jqhtml_component),
                'medium'
            );
        }
    }

    private function check_blade_filename(string $file, string $rsx_id, string $filename, bool $is_rsx, bool $is_rspade): void
    {
        $filename_without_blade = str_replace('.blade.php', '', $filename);

        // Check if filename matches (either full name or short name with matching directory structure)
        $matches_full = $is_rspade
            ? $filename_without_blade === $rsx_id
            : strtolower($filename_without_blade) === strtolower($rsx_id);

        if ($matches_full) {
            return; // Perfect match
        }

        // Check if short filename is valid (directory structure matches prefix)
        $dir_path = dirname($file);
        $short_name = $this->extract_short_name($rsx_id, $dir_path);

        if ($short_name !== null) {
            $matches_short = $is_rspade
                ? $filename_without_blade === $short_name
                : strtolower($filename_without_blade) === strtolower($short_name);

            if ($matches_short) {
                return; // Valid short name
            }
        }

        // Violation: filename doesn't match
        // Determine suggested filename
        $suggested_filename = $this->get_suggested_blade_filename_from_remediation($file, $rsx_id, $is_rspade);

        // Check if we should auto-rename or throw violation
        if ($this->should_auto_rename_or_throw_violation($file, $suggested_filename, $is_rsx)) {
            return; // File was renamed, manifest will restart
        }

        // Add violation
        if ($is_rspade) {
            $this->add_violation(
                $file,
                1,
                "Blade filename '$filename' must match @rsx_id '$rsx_id' (case-sensitive)",
                "@rsx_id('$rsx_id')",
                $this->get_blade_remediation($file, $rsx_id, $filename, $is_rspade),
                'high'
            );
        } else {
            $this->add_violation(
                $file,
                1,
                "Blade filename '$filename' must match @rsx_id '$rsx_id' (case-insensitive in rsx/)",
                "@rsx_id('$rsx_id')",
                $this->get_blade_remediation($file, $rsx_id, $filename, $is_rspade),
                'medium'
            );
        }
    }

    private function check_jqhtml_filename(string $file, string $component_name, string $filename, bool $is_rsx, bool $is_rspade): void
    {
        $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);

        // For rsx/, allow three formats for PascalCase component names:
        // 1. Exact match (TestComponent1)
        // 2. Snake_case with underscores (Test_Component_1)
        // 3. Lowercase with underscores (test_component_1)
        if ($is_rsx) {
            // Check exact match (case-insensitive)
            if (strtolower($filename_without_ext) === strtolower($component_name)) {
                return;
            }

            // Check snake_case variations
            $snake_case = $this->pascal_to_snake_case($component_name);
            if (strtolower($filename_without_ext) === strtolower($snake_case)) {
                return;
            }

            // Check short name variations
            $dir_path = dirname($file);
            $short_name = $this->extract_short_name($component_name, $dir_path);
            if ($short_name !== null) {
                $short_snake = $this->pascal_to_snake_case($short_name);
                if (strtolower($filename_without_ext) === strtolower($short_name) ||
                    strtolower($filename_without_ext) === strtolower($short_snake)) {
                    return;
                }
            }
        } else {
            // app/RSpade: strict case-sensitive match
            if ($filename_without_ext === $component_name) {
                return;
            }

            // Check short name
            $dir_path = dirname($file);
            $short_name = $this->extract_short_name($component_name, $dir_path);
            if ($short_name !== null && $filename_without_ext === $short_name) {
                return;
            }
        }

        // Violation: filename doesn't match
        // Determine suggested filename
        $suggested_filename = $this->get_suggested_jqhtml_filename_from_remediation($file, $component_name, $is_rspade);

        // Check if we should auto-rename or throw violation
        if ($this->should_auto_rename_or_throw_violation($file, $suggested_filename, $is_rsx)) {
            return; // File was renamed, manifest will restart
        }

        // Add violation
        if ($is_rspade) {
            $this->add_violation(
                $file,
                1,
                "Jqhtml filename '$filename' must match component name '$component_name' (case-sensitive)",
                "<Define:$component_name>",
                $this->get_jqhtml_remediation($file, $component_name, $filename, $is_rspade),
                'high'
            );
        } else {
            $this->add_violation(
                $file,
                1,
                "Jqhtml filename '$filename' must match component name '$component_name' (case-insensitive in rsx/)",
                "<Define:$component_name>",
                $this->get_jqhtml_remediation($file, $component_name, $filename, $is_rspade),
                'medium'
            );
        }
    }

    /**
     * Convert PascalCase to snake_case
     * Inserts underscores before uppercase letters and before first digit in a run of digits
     * Example: TestComponent1 -> Test_Component_1
     */
    private function pascal_to_snake_case(string $name): string
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
     * Extract valid short name(s) from class/id by checking if directory + filename parts match full name
     * Returns the filename parts as a valid short name if they can be combined with directory parts
     *
     * Rules:
     * - Short names only allowed in ./rsx directory (NOT in /app/RSpade)
     * - Original name must have 3+ segments for short name to be allowed (2-segment names must use full name)
     * - Short name must have 2+ segments (exception: if original was 1 segment, short can be 1 segment)
     * - Allows partial overlap between directory and filename (e.g., frontend/settings/account/ with settings_account filename)
     *
     * Algorithm: For a filename to be valid, there must exist a contiguous sequence in the directory path
     * that, when combined with the filename parts, equals the full name parts.
     */
    private function extract_short_name(string $full_name, string $dir_path): ?string
    {
        // Canonical algorithm lives in the shared Core/PHP helper (single source of truth).
        return \App\RSpade\Core\PHP\Filename_ShortName::extract_short_name($full_name, $dir_path);
    }

    private function get_class_remediation(string $file, string $class_name, string $filename, string $extension, bool $is_rspade, bool $is_jqhtml_component = false): string
    {
        $dir_path = dirname($file);
        $short_name = $this->extract_short_name($class_name, $dir_path);

        $message = $is_rspade
            ? "Files in app/RSpade/ must have filenames that match the class name (case-sensitive).\n\n"
            : "Files in rsx/ must have filenames that match the class name (case-insensitive).\n\n";

        $message .= "Class name: $class_name\n";
        $message .= "Current filename: $filename\n\n";
        $message .= "Fix options:\n";

        // For Jqhtml components in rsx/, use same flexible naming as jqhtml files
        if (!$is_rspade && $is_jqhtml_component) {
            $snake_case = $this->pascal_to_snake_case($class_name);
            $snake_lower = strtolower($snake_case);

            $options = [];

            // Always suggest lowercase snake_case first (convention)
            $options[] = [
                'label' => 'RECOMMENDED (RSpade convention)',
                'filename' => $snake_lower . '.' . $extension,
            ];

            // If PascalCase differs from snake_case, offer it as alternative
            if (strtolower($class_name) !== $snake_lower) {
                $options[] = [
                    'label' => 'Alternative',
                    'filename' => strtolower($class_name) . '.' . $extension,
                ];
            }

            // Add options to message
            $option_num = 1;
            foreach ($options as $option) {
                $message .= "{$option_num}. {$option['label']}: Rename to '{$option['filename']}'\n";
                $message .= "   mv '$filename' '{$option['filename']}'\n\n";
                $option_num++;
            }
        } else {
            // Regular class naming
            if ($short_name !== null && !file_exists(dirname(base_path($file)) . '/' . strtolower($short_name) . '.' . $extension)) {
                $short_filename = $is_rspade ? $short_name . '.' . $extension : strtolower($short_name) . '.' . $extension;
                $message .= "1. RECOMMENDED (short name): Rename to '$short_filename'\n";
                $message .= "   mv '$filename' '$short_filename'\n\n";
                $full_filename = $is_rspade ? $class_name . '.' . $extension : strtolower($class_name) . '.' . $extension;
                $message .= "2. Full name: Rename to '$full_filename'\n";
                $message .= "   mv '$filename' '$full_filename'\n\n";
            } else {
                $full_filename = $is_rspade ? $class_name . '.' . $extension : strtolower($class_name) . '.' . $extension;
                $message .= "1. Rename to '$full_filename'\n";
                $message .= "   mv '$filename' '$full_filename'\n\n";
            }
        }

        return $message;
    }

    private function get_blade_remediation(string $file, string $rsx_id, string $filename, bool $is_rspade): string
    {
        $dir_path = dirname($file);
        $short_name = $this->extract_short_name($rsx_id, $dir_path);

        $message = $is_rspade
            ? "Blade files in app/RSpade/ must have filenames that match the @rsx_id (case-sensitive).\n\n"
            : "Blade files in rsx/ must have filenames that match the @rsx_id (case-insensitive).\n\n";

        $message .= "@rsx_id: $rsx_id\n";
        $message .= "Current filename: $filename\n\n";
        $message .= "Fix options:\n";

        if ($short_name !== null && !file_exists(dirname(base_path($file)) . '/' . strtolower($short_name) . '.blade.php')) {
            $short_filename = $is_rspade ? $short_name . '.blade.php' : strtolower($short_name) . '.blade.php';
            $message .= "1. RECOMMENDED (short name): Rename to '$short_filename'\n";
            $message .= "   mv '$filename' '$short_filename'\n\n";
            $full_filename = $is_rspade ? $rsx_id . '.blade.php' : strtolower($rsx_id) . '.blade.php';
            $message .= "2. Full name: Rename to '$full_filename'\n";
            $message .= "   mv '$filename' '$full_filename'\n\n";
        } else {
            $full_filename = $is_rspade ? $rsx_id . '.blade.php' : strtolower($rsx_id) . '.blade.php';
            $message .= "1. Rename to '$full_filename'\n";
            $message .= "   mv '$filename' '$full_filename'\n\n";
        }

        return $message;
    }

    private function get_jqhtml_remediation(string $file, string $component_name, string $filename, bool $is_rspade): string
    {
        $dir_path = dirname($file);
        $short_name = $this->extract_short_name($component_name, $dir_path);

        $message = $is_rspade
            ? "Jqhtml files in app/RSpade/ must have filenames that match the component name (case-sensitive).\n\n"
            : "Jqhtml files in rsx/ must have filenames that match the component name (case-insensitive).\n\n";

        $message .= "Component name: $component_name\n";
        $message .= "Current filename: $filename\n\n";
        $message .= "Fix options:\n";

        if ($is_rspade) {
            // app/RSpade: case-sensitive exact match only
            if ($short_name !== null && !file_exists(dirname(base_path($file)) . '/' . $short_name . '.jqhtml')) {
                $message .= "1. RECOMMENDED (short name): Rename to '{$short_name}.jqhtml'\n";
                $message .= "   mv '$filename' '{$short_name}.jqhtml'\n\n";
                $message .= "2. Full name: Rename to '{$component_name}.jqhtml'\n";
                $message .= "   mv '$filename' '{$component_name}.jqhtml'\n\n";
            } else {
                $message .= "1. Rename to '{$component_name}.jqhtml'\n";
                $message .= "   mv '$filename' '{$component_name}.jqhtml'\n\n";
            }
        } else {
            // rsx/: Allow PascalCase or snake_case (lowercase with underscores is convention)
            $snake_case = $this->pascal_to_snake_case($component_name);
            $snake_lower = strtolower($snake_case);

            // Determine which options to show
            $options = [];

            // Always suggest lowercase snake_case first (convention)
            $options[] = [
                'label' => 'RECOMMENDED (RSpade convention)',
                'filename' => $snake_lower . '.jqhtml',
            ];

            // If PascalCase differs from snake_case, offer it as alternative
            if (strtolower($component_name) !== $snake_lower) {
                $options[] = [
                    'label' => 'Alternative',
                    'filename' => strtolower($component_name) . '.jqhtml',
                ];
            }

            // Add options to message
            $option_num = 1;
            foreach ($options as $option) {
                $message .= "{$option_num}. {$option['label']}: Rename to '{$option['filename']}'\n";
                $message .= "   mv '$filename' '{$option['filename']}'\n\n";
                $option_num++;
            }
        }

        return $message;
    }

    /**
     * Check if file should be auto-renamed or if violation should be thrown
     * Returns true if file was renamed (signals manifest restart needed)
     * Returns false if violation should be added
     */
    private function should_auto_rename_or_throw_violation(string $file, string $suggested_filename, bool $is_rsx): bool
    {
        // Check if file has @FILENAME-CONVENTION-EXCEPTION marker
        $file_contents = file_get_contents(base_path($file));
        if (str_contains($file_contents, '@FILENAME-CONVENTION-EXCEPTION')) {
            return true; // Skip this file entirely (no violation, no rename)
        }

        // Check if auto-rename is enabled and file is in rsx/
        if (!config('rsx.development.auto_rename_files', false) || !$is_rsx) {
            return false; // Throw normal violation
        }

        // Check if target filename already exists
        $target_path = dirname($file) . '/' . $suggested_filename;
        if (file_exists(base_path($target_path))) {
            return false; // Conflict - throw normal violation
        }

        // Perform rename
        $old_path = base_path($file);
        $new_path = base_path($target_path);

        rename($old_path, $new_path);
        console_debug('MANIFEST', "Auto-renamed: {$file} -> {$target_path}");

        // Signal manifest to restart
        \App\RSpade\Core\Manifest\Manifest::flag_needs_restart();

        return true; // File was renamed, no violation needed
    }

    /**
     * Extract suggested filename for class files from remediation logic
     */
    private function get_suggested_filename_from_remediation(string $file, string $class_name, string $extension, bool $is_rspade, bool $is_jqhtml_component): string
    {
        $dir_path = dirname($file);
        $short_name = $this->extract_short_name($class_name, $dir_path);

        if ($is_rspade) {
            // app/RSpade: case-sensitive
            if ($short_name !== null && !file_exists(dirname(base_path($file)) . '/' . $short_name . '.' . $extension)) {
                return $short_name . '.' . $extension;
            }
            return $class_name . '.' . $extension;
        } else {
            // rsx/: For Jqhtml components, use snake_case
            if ($is_jqhtml_component) {
                $snake_case = $this->pascal_to_snake_case($class_name);
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
     * Extract suggested filename for blade files from remediation logic
     */
    private function get_suggested_blade_filename_from_remediation(string $file, string $rsx_id, bool $is_rspade): string
    {
        $dir_path = dirname($file);
        $short_name = $this->extract_short_name($rsx_id, $dir_path);

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
     * Extract suggested filename for jqhtml files from remediation logic
     */
    private function get_suggested_jqhtml_filename_from_remediation(string $file, string $component_name, bool $is_rspade): string
    {
        $dir_path = dirname($file);
        $short_name = $this->extract_short_name($component_name, $dir_path);

        if ($is_rspade) {
            if ($short_name !== null && !file_exists(dirname(base_path($file)) . '/' . $short_name . '.jqhtml')) {
                return $short_name . '.jqhtml';
            }
            return $component_name . '.jqhtml';
        } else {
            // rsx/: use snake_case (lowercase with underscores)
            $snake_case = $this->pascal_to_snake_case($component_name);
            return strtolower($snake_case) . '.jqhtml';
        }
    }
}
