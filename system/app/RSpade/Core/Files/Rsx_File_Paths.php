<?php

namespace App\RSpade\Core\Files;

/**
 * Rsx_File_Paths
 *
 * THE single choke point for every on-disk location the file subsystems touch: the
 * content-addressed blob store, the thumbnail cache, and the PDF-rendition cache.
 *
 * INVARIANT: every file-subsystem disk path resolves through this class. Adding a
 * storage_path('storage/files' | 'rsx-thumbnails' | 'rsx-renditions') call for blobs,
 * thumbnails, or renditions ANYWHERE else reintroduces the B-38 test-isolation hole
 * (a test-DB attachment delete unlinking a blob shared with the developer database).
 *
 * The storage ROOT is normally storage_path() (the live environment). The test runner
 * (Rsx_Test_Command) sets the INTERNAL config key rsx.files.storage_root to a
 * test-scoped directory so a test run's file writes/deletes can never reach the real
 * store. Normal deployments never set that key; default-mode paths are byte-identical
 * to the historic storage_path('...') layout.
 */
class Rsx_File_Paths
{
    /**
     * Absolute root under which the entire file subsystem lives.
     *
     * Returns config('rsx.files.storage_root') when set (test isolation), else the
     * live storage_path(). Never a trailing slash.
     *
     * @return string
     */
    public static function storage_root(): string
    {
        $override = config('rsx.files.storage_root');

        if (!empty($override)) {
            return rtrim((string) $override, '/');
        }

        return storage_path();
    }

    /**
     * Root of the content-addressed blob store (matches the historic
     * storage_path('storage/files') layout exactly in default mode).
     *
     * @return string
     */
    public static function blob_root(): string
    {
        return static::storage_root() . '/storage/files';
    }

    /**
     * Root of the thumbnail cache (preset/ and dynamic/ subdirectories live here).
     *
     * @return string
     */
    public static function thumbnails_root(): string
    {
        return static::storage_root() . '/rsx-thumbnails';
    }

    /**
     * Root of the PDF-rendition cache.
     *
     * @return string
     */
    public static function renditions_root(): string
    {
        return static::storage_root() . '/rsx-renditions';
    }
}
