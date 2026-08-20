<?php

namespace App\RSpade\Core\PHP;

/**
 * Filename_ShortName - Single source of truth for the RSX "short filename" algorithm.
 *
 * A class/identifier name (underscore_segmented) may be shortened by dropping leading
 * segments that already appear as a contiguous run ANYWHERE in its directory path.
 * The directory supplies that context, so repeating it in the filename is redundant.
 *
 * Rules:
 * - Short names are NOT allowed for framework code under app/RSpade (the enforcer
 *   requires framework filenames to match the class name exactly).
 * - The original name must have 3+ segments (a 2-segment name has no droppable prefix
 *   that still leaves a valid 2+-segment short name).
 * - The resulting short name must have 2+ segments.
 * - The dropped prefix must exist as a contiguous, case-insensitive sequence at ANY
 *   start index within the directory parts (not just tail-anchored).
 * - The longest valid short name wins (i.e. the fewest prefix segments are dropped).
 *
 * Both public methods run over one shared match routine (_match_prefix_length) so the
 * count and the string can never diverge.
 */
class Filename_ShortName
{
    /**
     * Return the valid short name for a class/id given its directory, or null.
     *
     * @param string $class_name Full class/component/id name (underscore_segmented)
     * @param string $dir_path Directory path (relative or absolute)
     * @return string|null Short name, or null when none applies
     */
    public static function extract_short_name(string $class_name, string $dir_path): ?string
    {
        if (static::_is_framework_path($dir_path)) {
            return null;
        }

        $name_parts = explode('_', $class_name);
        $dir_parts = static::_split_dir($dir_path);

        $parts_to_remove = static::_match_prefix_length($name_parts, $dir_parts);
        if ($parts_to_remove === 0) {
            return null;
        }

        return implode('_', array_slice($name_parts, $parts_to_remove));
    }

    /**
     * Return how many leading prefix segments of the name are directory-redundant.
     *
     * @param string $class_name Full class/component/id name (underscore_segmented)
     * @param string $dir_path Directory path (relative or absolute)
     * @return int Number of prefix segments that matched (0 if none, or framework path)
     */
    public static function count_matched_prefix_parts(string $class_name, string $dir_path): int
    {
        if (static::_is_framework_path($dir_path)) {
            return 0;
        }

        $name_parts = explode('_', $class_name);
        $dir_parts = static::_split_dir($dir_path);

        return static::_match_prefix_length($name_parts, $dir_parts);
    }

    /**
     * Shared match routine. Given the name segments and directory segments, find the
     * largest short name (fewest dropped prefix segments) whose dropped prefix exists
     * as a contiguous case-insensitive run anywhere in the directory. Returns the
     * number of prefix segments to drop, or 0 if no valid short name exists.
     *
     * The short name always keeps 2+ segments: $short_len ranges down to 2, so
     * $parts_to_remove never exceeds count - 2. A name of 1 or 2 segments yields 0
     * (the loop body never executes).
     */
    private static function _match_prefix_length(array $name_parts, array $dir_parts): int
    {
        $original_segment_count = count($name_parts);

        // Longest short name first (fewest dropped parts), down to a 2-segment short name.
        for ($short_len = $original_segment_count - 1; $short_len >= 2; $short_len--) {
            $parts_to_remove = $original_segment_count - $short_len;
            $prefix_parts = array_slice($name_parts, 0, $parts_to_remove);

            // Slide the prefix across the directory parts; match at any start index.
            $prefix_len = count($prefix_parts);
            for ($start_idx = 0; $start_idx <= count($dir_parts) - $prefix_len; $start_idx++) {
                $all_match = true;
                for ($i = 0; $i < $prefix_len; $i++) {
                    if (strtolower($dir_parts[$start_idx + $i]) !== strtolower($prefix_parts[$i])) {
                        $all_match = false;
                        break;
                    }
                }

                if ($all_match) {
                    return $parts_to_remove;
                }
            }
        }

        return 0;
    }

    /**
     * Framework code (app/RSpade) never gets a short name. Checked with and without a
     * leading slash so relative directory paths (e.g. "app/RSpade/...") are covered too.
     */
    private static function _is_framework_path(string $dir_path): bool
    {
        return str_contains($dir_path, '/app/RSpade') || str_contains($dir_path, 'app/RSpade');
    }

    /**
     * Split a directory path into non-empty, re-indexed segments.
     */
    private static function _split_dir(string $dir_path): array
    {
        return array_values(array_filter(explode('/', $dir_path)));
    }
}
