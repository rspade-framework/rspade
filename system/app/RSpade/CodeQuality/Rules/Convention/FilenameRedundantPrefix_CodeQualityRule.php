<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\Constants;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * FilenameRedundantPrefix_CodeQualityRule - Enforces optimal filename clarity
 *
 * This rule implements two complementary filename conventions:
 *
 * 1. SUBTRACTIVE CONVENTION: Remove Directory-Redundant Prefixes
 *    --------------------------------------------------------
 *    Groups files by common prefix and removes parts that duplicate the directory path.
 *
 *    Rules:
 *    - Files are grouped by their longest common prefix (e.g., "frontend_calendar_event")
 *    - If the prefix contains parts that exist in the directory path, suggest removing them
 *    - ALL files in a group must be renamed together to maintain visual grouping
 *    - Single-segment filenames are NOT allowed (e.g., "calendar.blade.php" is invalid)
 *    - If ANY file would become single-segment, entire group is excluded
 *    - Must preserve immediate directory context for semantic clarity
 *
 *    Example:
 *    Directory: rsx/app/frontend/calendar/
 *      frontend_calendar_event.blade.php    -> calendar_event.blade.php
 *      frontend_calendar_event.js           -> calendar_event.js
 *      frontend_calendar_event.scss         -> calendar_event.scss
 *      frontend_calendar_event_controller.php -> calendar_event_controller.php
 *
 *    All four files flagged together because they share "frontend_calendar_event" prefix,
 *    and "frontend" is redundant (exists in directory path).
 *
 * 2. ADDITIVE CONVENTION: Add Detail to Generic Filenames
 *    --------------------------------------------------
 *    Flags single-segment filenames that match their directory name.
 *
 *    Rules:
 *    - File has exactly 1 segment (e.g., "edit")
 *    - Filename matches directory name
 *    - Suggests adding parent directory for context
 *
 *    Example:
 *    Directory: rsx/app/frontend/clients/edit/
 *      edit.blade.php -> clients_edit.blade.php
 *
 * CRITICAL: File Prefix Grouping
 * ------------------------------
 * Files with the same prefix are conceptually grouped and developers rely on this
 * visual relationship to identify related files. Breaking this grouping creates
 * cognitive overhead and makes codebases harder to navigate.
 *
 * Implementation Details:
 * - Checks run per-directory, analyzing all files together
 * - Groups identified by longest common prefix before file-specific suffix
 * - One violation generated per group (not per file)
 * - Group consistency enforced: all files renamed together or not at all
 */
class FilenameRedundantPrefix_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Track which directories we've already processed to avoid duplicate violations
     */
    private array $processed_directories = [];

    public function get_id(): string
    {
        return 'CONV-FILENAME-01';
    }

    public function get_name(): string
    {
        return 'Filename Clarity Convention';
    }

    public function get_description(): string
    {
        return 'Suggests optimal filename clarity by adding or removing directory context';
    }

    public function get_file_patterns(): array
    {
        return ['*.php', '*.js', '*.jqhtml', '*.blade.php'];
    }

    public function get_default_severity(): string
    {
        return 'convention';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in ./rsx or ./app/RSpade
        $relative_path = str_replace(base_path() . '/', '', $file_path);
        $is_rsx = str_starts_with($relative_path, 'rsx/');
        $is_rspade = str_starts_with($relative_path, 'app/RSpade/');

        if (!$is_rsx && !$is_rspade) {
            return;
        }

        $extension = $metadata['extension'] ?? '';
        $filename = basename($file_path);
        $dir_path = dirname($relative_path);
        $dir_key = $dir_path; // Check per directory, not per extension

        // Get the identifier name (class name, @rsx_id, or Define: name)
        $identifier = $this->extract_identifier($metadata, $extension);

        if ($identifier === null) {
            return; // No identifier to check
        }

        // Check additive case (too generic) - per file
        $this->check_additive_case($relative_path, $filename, $identifier, $extension, $dir_path, $is_rspade);

        // Check subtractive case (redundant prefix) - per directory across ALL extensions
        if (!isset($this->processed_directories[$dir_key])) {
            $this->check_subtractive_groups_all_extensions($dir_path, $is_rspade);
            $this->processed_directories[$dir_key] = true;
        }
    }

    /**
     * Extract identifier (class name, @rsx_id, or Define: name) from metadata
     */
    private function extract_identifier(array $metadata, string $extension): ?string
    {
        // PHP/JS files: use class name
        if (isset($metadata['class'])) {
            return $metadata['class'];
        }

        // Blade/jqhtml files: use id
        if (($extension === 'blade.php' || $extension === 'jqhtml') && isset($metadata['id'])) {
            return $metadata['id'];
        }

        return null;
    }

    /**
     * ADDITIVE: Check if filename is too generic (1 part matching directory name)
     * Suggests adding parent directory detail
     */
    private function check_additive_case(string $file, string $filename, string $identifier, string $extension, string $dir_path, bool $is_rspade): void
    {
        // Skip additive suggestions for framework code - canonical single-word class names
        // like Ajax, Manifest, Session, Task are intentional and shouldn't be renamed
        if ($is_rspade) {
            return;
        }

        $filename_base = $this->get_filename_base($filename, $extension);
        $identifier_parts = explode('_', $identifier);

        // Only check if identifier has exactly 1 part
        if (count($identifier_parts) !== 1) {
            return;
        }

        // Check if filename base matches directory name
        $dir_parts = array_values(array_filter(explode('/', $dir_path)));
        $current_dir = end($dir_parts);

        if (strtolower($filename_base) !== strtolower($current_dir)) {
            return; // Filename doesn't match directory
        }

        // Get parent directory
        if (count($dir_parts) < 2) {
            return; // No parent directory to add
        }

        $parent_dir = $dir_parts[count($dir_parts) - 2];

        // Construct suggested name: parent_current
        $suggested_identifier = $parent_dir . '_' . $current_dir;
        $suggested_filename = $is_rspade
            ? $suggested_identifier . '.' . $extension
            : strtolower($suggested_identifier) . '.' . $extension;

        // Check if suggested filename already exists
        $suggested_path = dirname(base_path($file)) . '/' . $suggested_filename;
        if (file_exists($suggested_path)) {
            return; // Can't suggest - already taken
        }

        // CRITICAL: Validate against class/identifier name using MANIFEST-FILENAME-01 logic
        // The suggested filename must still be valid for the class name
        $suggested_base = $is_rspade ? $suggested_identifier : strtolower($suggested_identifier);
        if (!$this->is_valid_short_filename($suggested_base, $identifier, $dir_path, $is_rspade)) {
            return; // Suggested rename would violate MANIFEST-FILENAME-01 - skip suggestion
        }

        // Add violation
        $identifier_type = $this->get_identifier_type($extension);
        $this->add_violation(
            $file,
            1,
            "Filename is too generic - add parent directory context",
            "$identifier_type: $identifier",
            "The filename '$filename' is too generic (matches directory name only).\n" .
            "Add parent directory context for clarity:\n" .
            "  mv '$filename' '$suggested_filename'\n\n" .
            "IMPORTANT:\n" .
            "- Only the filename changes - $identifier_type '$identifier' remains unchanged\n" .
            "- Adds parent directory '$parent_dir' to provide essential context\n" .
            "- Prevents ambiguous single-word filenames like 'edit.blade.php'\n\n" .
            "Example: frontend/clients/edit/edit.blade.php\n" .
            "         -> clients_edit.blade.php (adds 'clients' context)\n" .
            "         Now we know this is the edit view FOR clients, not a generic edit.\n\n" .
            "This convention improves clarity by ensuring filenames provide sufficient context.",
            'convention'
        );
    }

    /**
     * SUBTRACTIVE: Check directory for file groups with redundant prefixes across ALL extensions
     * Groups files by common prefix and suggests removing directory-redundant parts
     */
    private function check_subtractive_groups_all_extensions(string $dir_path, bool $is_rspade): void
    {
        $directory = base_path($dir_path);
        if (!is_dir($directory)) {
            return;
        }

        // Find all files in directory across all supported extensions
        $all_extensions = ['php', 'js', 'jqhtml', 'blade.php', 'scss'];
        $all_files = [];

        foreach ($all_extensions as $ext) {
            $pattern = $directory . '/*.' . $ext;
            $files = glob($pattern);
            if ($files) {
                $all_files = array_merge($all_files, $files);
            }
        }

        if (empty($all_files)) {
            return;
        }

        // Group files by their prefix (extension-agnostic grouping)
        $groups = $this->group_files_by_prefix_all_extensions($all_files, $is_rspade);

        // Track which files were in groups (regardless of whether flagged)
        $grouped_files = [];
        $flagged_files = [];

        // Check each group for redundant prefixes
        foreach ($groups as $prefix => $file_info_list) {
            // Track all files that are part of a group
            foreach ($file_info_list as $file_info) {
                $grouped_files[] = $file_info['file_path'];
            }

            $group_flagged = $this->check_prefix_group_all_extensions($prefix, $file_info_list, $dir_path, $is_rspade);

            if ($group_flagged) {
                // Track all files in this group as flagged
                foreach ($file_info_list as $file_info) {
                    $flagged_files[] = $file_info['file_path'];
                }
            }
        }

        // Check ungrouped files individually
        // IMPORTANT: Only check files that were NOT part of any group
        // Files in groups should never be checked individually, even if the group was rejected
        foreach ($all_files as $file_path) {
            if (!in_array($file_path, $grouped_files)) {
                $this->check_individual_file_all_extensions($file_path, $dir_path, $is_rspade);
            }
        }
    }

    /**
     * Check an individual file for redundant prefix (not part of a group) - extension-agnostic version
     */
    private function check_individual_file_all_extensions(string $file_path, string $dir_path, bool $is_rspade): void
    {
        $filename = basename($file_path);
        $extension = $this->detect_extension($filename);
        $filename_base = $this->get_filename_base($filename, $extension);
        $relative_path = str_replace(base_path() . '/', '', $file_path);

        // Before checking this file, verify that ALL files sharing the same prefix can be
        // consistently renamed. If not, renaming just this file would break visual grouping.
        if (!$this->can_all_siblings_with_prefix_be_renamed($file_path, $filename_base, $dir_path, $is_rspade)) {
            return; // Skip - would break visual grouping with sibling files
        }

        // Create a single-file "group" and check it
        $file_info = [
            'file_path' => $file_path,
            'filename' => $filename,
            'filename_base' => $filename_base,
            'extension' => $extension,
        ];

        $this->check_prefix_group_all_extensions($filename_base, [$file_info], $dir_path, $is_rspade);
    }

    /**
     * Check if ALL files in the directory sharing the same prefix can be consistently renamed.
     * This prevents breaking visual grouping when some files can be shortened and others cannot.
     *
     * Example: settings_general_action.js and settings_general_controller.php share prefix "settings_general_".
     * If the action can remove "settings_" but the controller cannot (due to different class name),
     * we should NOT suggest renaming either to maintain visual grouping.
     */
    private function can_all_siblings_with_prefix_be_renamed(string $file_path, string $filename_base, string $dir_path, bool $is_rspade): bool
    {
        $directory = dirname($file_path);

        // Determine what prefix would be removed from this file
        $filename_parts = explode('_', $filename_base);
        $dir_parts = array_values(array_filter(explode('/', $dir_path)));
        $parts_to_remove = $this->count_redundant_prefix_parts($filename_parts, $dir_parts);

        if ($parts_to_remove === 0) {
            return true; // No prefix to remove, so no grouping concern
        }

        // The prefix that would be removed (e.g., "settings_" from "settings_general_action")
        $prefix_to_remove = implode('_', array_slice($filename_parts, 0, $parts_to_remove)) . '_';

        // Find all files in directory that share this prefix
        $all_extensions = ['php', 'js', 'jqhtml', 'blade.php', 'scss'];
        $sibling_files = [];

        foreach ($all_extensions as $ext) {
            $pattern = $directory . '/*.' . $ext;
            $files = glob($pattern);
            if ($files) {
                foreach ($files as $sibling_path) {
                    $sibling_filename = basename($sibling_path);
                    $sibling_base = $this->get_filename_base($sibling_filename, $ext);

                    // Check if sibling shares the same prefix
                    $sibling_base_normalized = $is_rspade ? $sibling_base : strtolower($sibling_base);
                    $prefix_normalized = $is_rspade ? rtrim($prefix_to_remove, '_') : strtolower(rtrim($prefix_to_remove, '_'));

                    if (str_starts_with($sibling_base_normalized, $prefix_normalized . '_')) {
                        $sibling_files[] = [
                            'file_path' => $sibling_path,
                            'filename_base' => $sibling_base,
                            'extension' => $ext,
                        ];
                    }
                }
            }
        }

        // If no siblings share the prefix, no grouping concern
        if (count($sibling_files) <= 1) {
            return true;
        }

        // Check if ALL siblings can have the same prefix removed
        foreach ($sibling_files as $sibling) {
            $sibling_parts = explode('_', $sibling['filename_base']);
            $short_name_parts = array_slice($sibling_parts, $parts_to_remove);
            $short_name = implode('_', $short_name_parts);

            // Skip if would become single-segment
            if (count($short_name_parts) < 2) {
                return false; // Can't rename consistently - would create single-segment filename
            }

            // Get class name for this sibling
            $relative_path = str_replace(base_path() . '/', '', $sibling['file_path']);
            $file_metadata = \App\RSpade\Core\Manifest\Manifest::get_file($relative_path);
            $class_name = $file_metadata['class'] ?? $file_metadata['id'] ?? null;

            if ($class_name !== null) {
                $suggested_base = $is_rspade ? $short_name : strtolower($short_name);
                if (!$this->is_valid_short_filename($suggested_base, $class_name, $dir_path, $is_rspade)) {
                    return false; // This sibling can't be renamed - would violate manifest rule
                }
            }
        }

        return true; // All siblings can be consistently renamed
    }

    /**
     * Detect the extension from a filename (handles .blade.php)
     */
    private function detect_extension(string $filename): string
    {
        if (str_ends_with($filename, '.blade.php')) {
            return 'blade.php';
        }
        return pathinfo($filename, PATHINFO_EXTENSION);
    }

    /**
     * Group files by their common prefix (extension-agnostic version)
     *
     * Grouping Rule: Files are grouped if they share a common base where:
     * - All files match: {base} or {base}_{one_word}
     * - Works across ALL file extensions (.php, .js, .blade.php, .scss, etc.)
     * - Example: frontend_calendar.js, frontend_calendar.blade.php, frontend_calendar_controller.php share base "frontend_calendar"
     * - Example: frontend_calendar_event.js, frontend_calendar_event_controller.php share base "frontend_calendar_event"
     * - Non-example: frontend_calendar and frontend_calendar_event are DIFFERENT bases
     */
    private function group_files_by_prefix_all_extensions(array $files, bool $is_rspade): array
    {
        $file_bases = [];

        // Extract filename bases
        foreach ($files as $file_path) {
            $filename = basename($file_path);
            $extension = $this->detect_extension($filename);
            $filename_base = $this->get_filename_base($filename, $extension);
            $file_bases[] = [
                'file_path' => $file_path,
                'filename' => $filename,
                'filename_base' => $filename_base,
                'extension' => $extension,
            ];
        }

        // Find all possible group bases by checking each unique base
        $unique_bases = array_unique(array_map(fn($f) => $is_rspade ? $f['filename_base'] : strtolower($f['filename_base']), $file_bases));

        $groups = [];

        foreach ($unique_bases as $potential_base) {
            $potential_base_normalized = $is_rspade ? $potential_base : strtolower($potential_base);

            // Find all files that match this base exactly OR have base + one word
            $matching_files = array_filter($file_bases, function($file_info) use ($potential_base_normalized, $is_rspade) {
                $file_base_normalized = $is_rspade ? $file_info['filename_base'] : strtolower($file_info['filename_base']);

                // Exact match
                if ($file_base_normalized === $potential_base_normalized) {
                    return true;
                }

                // Check if it's base + one word (base_something)
                if (str_starts_with($file_base_normalized, $potential_base_normalized . '_')) {
                    // Extract the suffix after the base
                    $suffix = substr($file_base_normalized, strlen($potential_base_normalized) + 1);

                    // Only match if suffix is a single word (no more underscores)
                    if (!str_contains($suffix, '_')) {
                        return true;
                    }
                }

                return false;
            });

            // Only keep this group if it has 2+ files
            if (count($matching_files) >= 2) {
                $groups[$potential_base_normalized] = array_values($matching_files);
            }
        }

        // Remove files from groups if they're in a more specific group
        // Priority: shorter base names win (to ensure proper grouping of base + suffix files)
        $sorted_groups = $groups;
        uksort($sorted_groups, fn($a, $b) => strlen($a) - strlen($b)); // Shortest first

        $assigned_files = [];
        $final_groups = [];

        foreach ($sorted_groups as $base => $files) {
            $unassigned_files = array_filter($files, function($file) use ($assigned_files) {
                return !in_array($file['file_path'], $assigned_files);
            });

            if (count($unassigned_files) >= 2) {
                $final_groups[$base] = array_values($unassigned_files);
                foreach ($unassigned_files as $file) {
                    $assigned_files[] = $file['file_path'];
                }
            }
        }

        return $final_groups;
    }

    /**
     * Group files by their common prefix (LEGACY - per extension)
     *
     * Grouping Rule: Files are grouped if they share a common base where:
     * - All files match: {base} or {base}_{one_word}
     * - Example: frontend_calendar_event, frontend_calendar_event_controller share base "frontend_calendar_event"
     * - Example: frontend_calendar, frontend_calendar_controller share base "frontend_calendar"
     * - Non-example: frontend_calendar and frontend_calendar_event are DIFFERENT bases
     */
    private function group_files_by_prefix(array $files, string $extension, bool $is_rspade): array
    {
        $file_bases = [];

        // Extract filename bases
        foreach ($files as $file_path) {
            $filename = basename($file_path);
            $filename_base = $this->get_filename_base($filename, $extension);
            $file_bases[] = [
                'file_path' => $file_path,
                'filename' => $filename,
                'filename_base' => $filename_base,
            ];
        }

        // Find all possible group bases by checking each unique base
        $unique_bases = array_unique(array_map(fn($f) => $is_rspade ? $f['filename_base'] : strtolower($f['filename_base']), $file_bases));

        $groups = [];

        foreach ($unique_bases as $potential_base) {
            $potential_base_normalized = $is_rspade ? $potential_base : strtolower($potential_base);

            // Find all files that match this base exactly OR have base + one word
            $matching_files = array_filter($file_bases, function($file_info) use ($potential_base_normalized, $is_rspade) {
                $file_base_normalized = $is_rspade ? $file_info['filename_base'] : strtolower($file_info['filename_base']);

                // Exact match
                if ($file_base_normalized === $potential_base_normalized) {
                    return true;
                }

                // Check if it's base + one word (base_something)
                if (str_starts_with($file_base_normalized, $potential_base_normalized . '_')) {
                    // Extract the suffix after the base
                    $suffix = substr($file_base_normalized, strlen($potential_base_normalized) + 1);

                    // Only match if suffix is a single word (no more underscores)
                    if (!str_contains($suffix, '_')) {
                        return true;
                    }
                }

                return false;
            });

            // Only keep this group if it has 2+ files
            if (count($matching_files) >= 2) {
                $groups[$potential_base_normalized] = array_values($matching_files);
            }
        }

        // Remove files from groups if they're in a more specific group
        // Priority: shorter base names win (to ensure proper grouping of base + suffix files)
        $sorted_groups = $groups;
        uksort($sorted_groups, fn($a, $b) => strlen($a) - strlen($b)); // Shortest first

        $assigned_files = [];
        $final_groups = [];

        foreach ($sorted_groups as $base => $files) {
            $unassigned_files = array_filter($files, function($file) use ($assigned_files) {
                return !in_array($file['file_path'], $assigned_files);
            });

            if (count($unassigned_files) >= 2) {
                $final_groups[$base] = array_values($unassigned_files);
                foreach ($unassigned_files as $file) {
                    $assigned_files[] = $file['file_path'];
                }
            }
        }

        return $final_groups;
    }

    /**
     * Check if a prefix group has redundant directory parts and should be shortened (extension-agnostic version)
     *
     * @return bool True if group was flagged, false otherwise
     */
    private function check_prefix_group_all_extensions(string $prefix, array $file_info_list, string $dir_path, bool $is_rspade): bool
    {
        // Check if prefix has parts that can be removed
        $prefix_parts = explode('_', $prefix);
        $dir_parts = array_values(array_filter(explode('/', $dir_path)));
        $immediate_dir = end($dir_parts);
        $parent_dir = count($dir_parts) >= 2 ? $dir_parts[count($dir_parts) - 2] : null;

        // Try to find how many parts from the prefix exist in directory path
        $parts_to_remove = $this->count_redundant_prefix_parts($prefix_parts, $dir_parts);

        if ($parts_to_remove === 0) {
            return false; // No redundant parts
        }

        // WHITELIST: If removing parts would result in a 2-segment name where the first segment
        // matches the parent directory, don't suggest the rename (preserves semantic overlap)
        // Example: settings/password_security/settings_password_security.* should stay as-is
        $short_parts = array_slice($prefix_parts, $parts_to_remove);
        if (count($short_parts) === 2 && $parent_dir !== null) {
            $first_segment = strtolower($short_parts[0]);
            $parent_dir_lower = strtolower($parent_dir);
            if ($first_segment === $parent_dir_lower) {
                return false; // Preserve semantic overlap with parent directory
            }
        }

        // Verify all files in group can be shortened consistently
        $rename_suggestions = [];
        foreach ($file_info_list as $file_info) {
            $filename_parts = explode('_', $file_info['filename_base']);

            // Remove the redundant parts
            $short_name_parts = array_slice($filename_parts, $parts_to_remove);
            $short_name = implode('_', $short_name_parts);

            // CRITICAL: Single-segment filenames are NOT allowed
            // If ANY file in the group would become a single segment, reject the entire group
            if (count($short_name_parts) === 1) {
                return false; // Single-segment filename not allowed - abort entire group
            }

            // Verify the short name preserves immediate directory context
            $short_name_lower = strtolower($short_name);
            $immediate_dir_lower = strtolower($immediate_dir);

            // Generic operation directories - renaming files in these creates proliferation
            // of identical filenames across the codebase (e.g., many "edit_action.js" files)
            if (in_array($immediate_dir_lower, Constants::GENERIC_OPERATIONS)) {
                $first_segment = strtolower($short_name_parts[0] ?? '');
                if ($first_segment === $immediate_dir_lower) {
                    return false; // Would create generic operation filename proliferation - abort
                }
            }

            if (!str_contains($short_name_lower, $immediate_dir_lower)) {
                return false; // Would lose essential directory context - abort group rename
            }

            $extension = $file_info['extension'];
            $short_filename = $is_rspade
                ? $short_name . '.' . $extension
                : strtolower($short_name) . '.' . $extension;

            // Check if short filename already exists
            $short_path = dirname($file_info['file_path']) . '/' . $short_filename;
            if (file_exists($short_path) && $short_path !== $file_info['file_path']) {
                return false; // Conflict - can't rename group
            }

            // CRITICAL: Validate against class name using MANIFEST-FILENAME-01 logic
            // Get the class/identifier name from manifest
            $relative_path = str_replace(base_path() . '/', '', $file_info['file_path']);
            $file_metadata = \App\RSpade\Core\Manifest\Manifest::get_file($relative_path);
            $class_name = $file_metadata['class'] ?? $file_metadata['id'] ?? null;

            if ($class_name !== null) {
                // Check if the suggested short filename would be valid for this class
                $suggested_base = $is_rspade ? $short_name : strtolower($short_name);
                if (!$this->is_valid_short_filename($suggested_base, $class_name, $dir_path, $is_rspade)) {
                    return false; // Suggested rename would violate MANIFEST-FILENAME-01 - abort
                }
            }

            $rename_suggestions[] = [
                'old' => $file_info['filename'],
                'new' => $short_filename,
                'file_path' => str_replace(base_path() . '/', '', $file_info['file_path']),
            ];
        }

        // Generate single violation for the entire group
        $this->generate_group_violation($rename_suggestions, $parts_to_remove, 'mixed');
        return true; // Successfully flagged this group
    }

    /**
     * Check if a prefix group has redundant directory parts and should be shortened (LEGACY - per extension)
     *
     * @return bool True if group was flagged, false otherwise
     */
    private function check_prefix_group(string $prefix, array $file_info_list, string $dir_path, string $extension, bool $is_rspade): bool
    {
        // Check if prefix has parts that can be removed
        $prefix_parts = explode('_', $prefix);
        $dir_parts = array_values(array_filter(explode('/', $dir_path)));
        $immediate_dir = end($dir_parts);

        // Try to find how many parts from the prefix exist in directory path
        $parts_to_remove = $this->count_redundant_prefix_parts($prefix_parts, $dir_parts);

        if ($parts_to_remove === 0) {
            return false; // No redundant parts
        }

        // Verify all files in group can be shortened consistently
        $rename_suggestions = [];
        foreach ($file_info_list as $file_info) {
            $filename_parts = explode('_', $file_info['filename_base']);

            // Remove the redundant parts
            $short_name_parts = array_slice($filename_parts, $parts_to_remove);
            $short_name = implode('_', $short_name_parts);

            // CRITICAL: Single-segment filenames are NOT allowed
            // If ANY file in the group would become a single segment, reject the entire group
            if (count($short_name_parts) === 1) {
                return false; // Single-segment filename not allowed - abort entire group
            }

            // Verify the short name preserves immediate directory context
            $short_name_lower = strtolower($short_name);
            $immediate_dir_lower = strtolower($immediate_dir);

            // Generic operation directories - renaming files in these creates proliferation
            // of identical filenames across the codebase (e.g., many "edit_action.js" files)
            if (in_array($immediate_dir_lower, Constants::GENERIC_OPERATIONS)) {
                $first_segment = strtolower($short_name_parts[0] ?? '');
                if ($first_segment === $immediate_dir_lower) {
                    return false; // Would create generic operation filename proliferation - abort
                }
            }

            if (!str_contains($short_name_lower, $immediate_dir_lower)) {
                return false; // Would lose essential directory context - abort group rename
            }

            $short_filename = $is_rspade
                ? $short_name . '.' . $extension
                : strtolower($short_name) . '.' . $extension;

            // Check if short filename already exists
            $short_path = dirname($file_info['file_path']) . '/' . $short_filename;
            if (file_exists($short_path) && $short_path !== $file_info['file_path']) {
                return false; // Conflict - can't rename group
            }

            // CRITICAL: Validate against class name using MANIFEST-FILENAME-01 logic
            // Get the class/identifier name from manifest
            $relative_path = str_replace(base_path() . '/', '', $file_info['file_path']);
            $file_metadata = \App\RSpade\Core\Manifest\Manifest::get_file($relative_path);
            $class_name = $file_metadata['class'] ?? $file_metadata['id'] ?? null;

            if ($class_name !== null) {
                // Check if the suggested short filename would be valid for this class
                $suggested_base = $is_rspade ? $short_name : strtolower($short_name);
                if (!$this->is_valid_short_filename($suggested_base, $class_name, $dir_path, $is_rspade)) {
                    return false; // Suggested rename would violate MANIFEST-FILENAME-01 - abort
                }
            }

            $rename_suggestions[] = [
                'old' => $file_info['filename'],
                'new' => $short_filename,
                'file_path' => str_replace(base_path() . '/', '', $file_info['file_path']),
            ];
        }

        // Generate single violation for the entire group
        $this->generate_group_violation($rename_suggestions, $parts_to_remove, $extension);
        return true; // Successfully flagged this group
    }

    /**
     * Count how many parts from the prefix exist consecutively in the directory path
     *
     * IMPORTANT: This now uses the same logic as FilenameClassMatch's extract_short_name()
     * which tries all possible short name lengths and returns the longest valid one.
     * This ensures consistency between the convention rule and the strict naming rule.
     */
    private function count_redundant_prefix_parts(array $prefix_parts, array $dir_parts): int
    {
        // Canonical algorithm lives in the shared Core/PHP helper (single source of truth).
        return \App\RSpade\Core\PHP\Filename_ShortName::count_matched_prefix_parts(
            implode('_', $prefix_parts),
            implode('/', $dir_parts)
        );
    }

    /**
     * Generate a single violation for an entire file group
     */
    private function generate_group_violation(array $rename_suggestions, int $parts_removed, string $extension): void
    {
        // Use first file in group as the violation location
        $first_file = $rename_suggestions[0];

        // Build the violation message
        $file_list = "";
        foreach ($rename_suggestions as $suggestion) {
            $file_list .= "  {$suggestion['old']} -> {$suggestion['new']}\n";
        }

        $identifier_type = $this->get_identifier_type($extension);
        $file_count = count($rename_suggestions);

        $this->add_violation(
            $first_file['file_path'],
            1,
            "File group has directory-redundant prefix that can be removed ($file_count files)",
            "Grouped files sharing common prefix",
            "The directory structure already provides context for these files.\n" .
            "Suggested renames for entire group:\n\n$file_list\n" .
            "IMPORTANT:\n" .
            "- Only filenames change - $identifier_type names remain unchanged\n" .
            "- Removes $parts_removed part(s) that duplicate directory path\n" .
            "- ALL files in group must be renamed together to maintain visual grouping\n" .
            "- Preserves immediate directory context for semantic clarity\n" .
            "- Files with same prefix form a visual group that developers rely on\n\n" .
            "Example group rename:\n" .
            "  rsx/app/frontend/calendar/\n" .
            "    frontend_calendar_event.blade.php    -> calendar_event.blade.php\n" .
            "    frontend_calendar_event.js           -> calendar_event.js\n" .
            "    frontend_calendar_event_controller.php -> calendar_event_controller.php\n\n" .
            "All files renamed together because they share 'frontend_calendar_event' prefix.\n" .
            "The 'frontend' part is redundant (exists in directory path).\n\n" .
            "This convention improves readability by removing redundancy while preserving\n" .
            "semantic clarity and maintaining visual file grouping.",
            'convention'
        );
    }

    /**
     * Extract filename base (without extension)
     */
    private function get_filename_base(string $filename, string $extension): string
    {
        if ($extension === 'blade.php') {
            return str_replace('.blade.php', '', $filename);
        }
        return pathinfo($filename, PATHINFO_FILENAME);
    }

    /**
     * Extract valid short name from class/id by checking if directory + filename parts match full name
     * This is the same logic as FilenameClassMatch_CodeQualityRule::extract_short_name()
     *
     * Returns the valid short name if one exists, or null if only full name is valid.
     */
    private function extract_valid_short_name(string $full_name, string $dir_path): ?string
    {
        // Canonical algorithm lives in the shared Core/PHP helper (single source of truth).
        return \App\RSpade\Core\PHP\Filename_ShortName::extract_short_name($full_name, $dir_path);
    }

    /**
     * Check if a suggested short filename would be valid for a given class name
     * Uses the same validation logic as MANIFEST-FILENAME-01
     */
    private function is_valid_short_filename(string $suggested_filename_base, string $class_name, string $dir_path, bool $is_rspade): bool
    {
        // Full name always valid
        $matches_full = $is_rspade
            ? $suggested_filename_base === $class_name
            : strtolower($suggested_filename_base) === strtolower($class_name);

        if ($matches_full) {
            return true;
        }

        // Check if short name is valid
        $valid_short_name = $this->extract_valid_short_name($class_name, $dir_path);

        if ($valid_short_name !== null) {
            $matches_short = $is_rspade
                ? $suggested_filename_base === $valid_short_name
                : strtolower($suggested_filename_base) === strtolower($valid_short_name);

            if ($matches_short) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get identifier type description for messages
     */
    private function get_identifier_type(string $extension): string
    {
        if ($extension === 'blade.php') {
            return '@rsx_id';
        }
        if ($extension === 'jqhtml') {
            return '<Define>';
        }
        return 'class';
    }
}
