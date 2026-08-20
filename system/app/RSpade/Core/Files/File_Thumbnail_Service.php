<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * File_Thumbnail_Service
 *
 * Business logic for thumbnail cache management including:
 * - Quota enforcement via LRU eviction
 * - Scheduled cleanup of preset thumbnails
 * - Statistics and reporting
 *
 * Storage Structure:
 * - storage/rsx-thumbnails/preset/  - Named preset thumbnails (100MB quota, scheduled cleanup)
 * - storage/rsx-thumbnails/dynamic/ - Dynamic ad-hoc thumbnails (50MB quota, synchronous cleanup)
 *
 * Cleanup Strategy:
 * - Preset: Runs every 30 minutes via scheduled task (this class)
 * - Dynamic: Enforced synchronously in File_Attachment_Controller after each generation
 * - Both: LRU eviction (oldest mtime deleted first)
 */
class File_Thumbnail_Service extends Rsx_Service_Abstract
{
    /**
     * Scheduled cleanup of preset thumbnail cache
     *
     * Runs every 30 minutes to enforce preset thumbnail quota.
     * Deletes oldest files (by mtime) until under configured limit.
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters
     */
    #[Task('Clean preset thumbnail cache (runs every 30 minutes)')]
    #[Schedule('*/30 * * * *')]
    public static function cleanup_preset_thumbnails(Task_Instance $task, array $params = [])
    {
        // Delegates to the shared LRU eviction primitive - identical algorithm to the
        // rsx:thumbnails:clean CLI path (config key, directory, size math, oldest-first).
        [$deleted_count, $freed_bytes] = static::clean_directory('preset');

        $freed_mb = round($freed_bytes / 1024 / 1024, 2);
        $task->info("Preset thumbnail cleanup: {$deleted_count} files deleted, {$freed_mb} MB freed");

        return [$deleted_count, $freed_bytes];
    }

    /**
     * Clean a thumbnail directory by enforcing quota
     *
     * Used by CLI commands for manual cleanup operations.
     *
     * @param string $type Either 'preset' or 'dynamic'
     * @return array [files_deleted, bytes_freed]
     */
    public static function clean_directory($type)
    {
        $quota_key = $type . '_max_bytes';
        $max_bytes = config("rsx.thumbnails.quotas.{$quota_key}");
        $dir = Rsx_File_Paths::thumbnails_root() . "/{$type}/";

        if (!is_dir($dir)) {
            return [0, 0];
        }

        // Calculate current usage
        $total_size = 0;
        $files = [];

        foreach (glob($dir . '*.webp') as $file) {
            $size = filesize($file);
            $mtime = filemtime($file);
            $total_size += $size;
            $files[] = ['path' => $file, 'size' => $size, 'mtime' => $mtime];
        }

        // Not over quota? Nothing to do
        if ($total_size <= $max_bytes) {
            return [0, 0];
        }

        // Over quota - delete oldest first (LRU eviction)
        usort($files, fn($a, $b) => $a['mtime'] <=> $b['mtime']);

        $deleted_count = 0;
        $freed_bytes = 0;

        foreach ($files as $file) {
            @unlink($file['path']);
            $total_size -= $file['size'];
            $deleted_count++;
            $freed_bytes += $file['size'];

            if ($total_size <= $max_bytes) {
                break;
            }
        }

        return [$deleted_count, $freed_bytes];
    }

    /**
     * Get statistics for thumbnail cache directories
     *
     * @return array Statistics for preset and dynamic directories
     */
    public static function get_statistics()
    {
        $stats = [
            'preset' => static::__get_directory_stats('preset'),
            'dynamic' => static::__get_directory_stats('dynamic'),
        ];

        // Add breakdown by preset name
        $stats['preset_breakdown'] = static::__get_preset_breakdown();

        return $stats;
    }

    /**
     * Get statistics for a single directory
     *
     * @param string $type Either 'preset' or 'dynamic'
     * @return array Statistics including file count, total size, oldest/newest
     */
    protected static function __get_directory_stats($type)
    {
        $dir = Rsx_File_Paths::thumbnails_root() . "/{$type}/";
        $quota_key = $type . '_max_bytes';
        $max_bytes = config("rsx.thumbnails.quotas.{$quota_key}");

        if (!is_dir($dir)) {
            return [
                'exists' => false,
                'file_count' => 0,
                'total_bytes' => 0,
                'max_bytes' => $max_bytes,
                'usage_percent' => 0,
                'oldest' => null,
                'newest' => null,
            ];
        }

        $files = glob($dir . '*.webp');
        $file_count = count($files);
        $total_bytes = 0;
        $oldest = null;
        $newest = null;

        foreach ($files as $file) {
            $total_bytes += filesize($file);
            $mtime = filemtime($file);

            if ($oldest === null || $mtime < $oldest) {
                $oldest = $mtime;
            }
            if ($newest === null || $mtime > $newest) {
                $newest = $mtime;
            }
        }

        return [
            'exists' => true,
            'file_count' => $file_count,
            'total_bytes' => $total_bytes,
            'max_bytes' => $max_bytes,
            'usage_percent' => $max_bytes > 0 ? round(($total_bytes / $max_bytes) * 100, 1) : 0,
            'oldest' => $oldest,
            'newest' => $newest,
        ];
    }

    /**
     * Get breakdown of preset thumbnails by preset name
     *
     * @return array Breakdown by preset name
     */
    protected static function __get_preset_breakdown()
    {
        $dir = Rsx_File_Paths::thumbnails_root() . '/preset/';

        if (!is_dir($dir)) {
            return [];
        }

        $presets = config('rsx.thumbnails.presets', []);
        $breakdown = [];

        // Initialize breakdown for each preset
        foreach (array_keys($presets) as $preset_name) {
            $breakdown[$preset_name] = [
                'file_count' => 0,
                'total_bytes' => 0,
            ];
        }

        // Count files for each preset
        foreach (glob($dir . '*.webp') as $file) {
            $filename = basename($file);
            // Extract preset name from filename (format: presetname_hash_ext.webp)
            if (preg_match('/^([^_]+)_/', $filename, $matches)) {
                $preset_name = $matches[1];
                if (isset($breakdown[$preset_name])) {
                    $breakdown[$preset_name]['file_count']++;
                    $breakdown[$preset_name]['total_bytes'] += filesize($file);
                }
            }
        }

        return $breakdown;
    }
}
