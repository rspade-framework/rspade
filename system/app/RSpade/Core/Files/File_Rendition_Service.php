<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * File_Rendition_Service
 *
 * Cache management for document PDF renditions produced by File_Preview_Controller.
 *
 * Storage Structure:
 * - storage/rsx-renditions/{blob-hash}.pdf - cached soffice->PDF renditions of convertible
 *   documents (Office docs). Content-addressed on the deduplicated blob hash, so identical bytes
 *   convert exactly once. PDF attachments are served directly and never cached here.
 *
 * Cleanup Strategy:
 * - Runs every 30 minutes via scheduled task (mirrors File_Thumbnail_Service).
 * - LRU eviction (oldest mtime deleted first) down to rsx.preview.quota_max_bytes.
 * - The rendition serve path touch()'es each cache hit so hot renditions survive eviction.
 */
class File_Rendition_Service extends Rsx_Service_Abstract
{
    /**
     * Scheduled cleanup of the PDF rendition cache.
     *
     * Runs every 30 minutes to enforce the rendition quota. Deletes oldest files (by mtime) until
     * under rsx.preview.quota_max_bytes.
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters
     */
    #[Task('Clean document PDF rendition cache (runs every 30 minutes)')]
    #[Schedule('*/30 * * * *')]
    public static function cleanup_renditions(Task_Instance $task, array $params = [])
    {
        $max_bytes = config('rsx.preview.quota_max_bytes');
        $dir = Rsx_File_Paths::renditions_root() . '/';

        if (!is_dir($dir)) {
            // Directory doesn't exist yet - no cleanup needed.
            $task->info('Rendition cache directory does not exist yet');
            return;
        }

        // Calculate current usage.
        $total_size = 0;
        $files = [];

        foreach (glob($dir . '*.pdf') as $file) {
            $size = filesize($file);
            $mtime = filemtime($file);
            $total_size += $size;
            $files[] = ['path' => $file, 'size' => $size, 'mtime' => $mtime];
        }

        // Not over quota? Nothing to do.
        if ($total_size <= $max_bytes) {
            return;
        }

        // Over quota - delete oldest files until under limit (LRU eviction).
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

        $freed_mb = round($freed_bytes / 1024 / 1024, 2);
        $task->info("Rendition cleanup: {$deleted_count} files deleted, {$freed_mb} MB freed");
    }

    /**
     * Get statistics for the rendition cache directory.
     *
     * @return array Statistics including file count, total size, quota usage, oldest/newest mtime.
     */
    public static function get_statistics()
    {
        $dir = Rsx_File_Paths::renditions_root() . '/';
        $max_bytes = config('rsx.preview.quota_max_bytes');

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

        $files = glob($dir . '*.pdf');
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
}
