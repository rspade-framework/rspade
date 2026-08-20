<?php

namespace App\RSpade\Core\Files;

use Exception;
use Imagick;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Files\Libreoffice;
use App\RSpade\Core\Files\Rsx_Thumbnail_Renderer_Abstract;
use App\RSpade\Core\Locks\RsxLocks;

/**
 * Libreoffice_Thumbnail_Renderer - renders Office documents (docx/xlsx/pptx/odt/...) to a raster
 * preview by shelling out to headless LibreOffice.
 *
 * Flow: acquire a concurrency slot (RSpade semaphore, bounded by config
 * rsx.libreoffice.max_concurrent), then `soffice --headless --convert-to pdf` into a private temp
 * dir, then rasterize page 1 with Imagick and return the handle. The conversion is hard-capped at
 * rsx.libreoffice.timeout seconds, measured from when the slot is acquired and the render starts.
 *
 * Fail loud: throws (with a log line) on any failure. The PIPELINE catches the throw and falls back
 * to the extension icon - this renderer never silently degrades. NOTE: the master enable/disable
 * switch (rsx.libreoffice.enabled) is enforced upstream in the renderer registry
 * (File_Attachment_Controller::renderer_class_for_mime), so when LibreOffice is disabled this class
 * is never reached and document thumbnails quietly use the icon.
 *
 * Semaphore name for max-concurrency quota.
 */
class Libreoffice_Thumbnail_Renderer extends Rsx_Thumbnail_Renderer_Abstract
{
    /** Semaphore name for the max-concurrency quota. */
    protected const SEMAPHORE_NAME = 'libreoffice_thumbnail';

    /**
     * @param string $source_path
     * @param int    $max_width
     * @param int    $max_height
     * @return Imagick
     * @throws Exception
     */
    public static function render(string $source_path, int $max_width, int $max_height): Imagick
    {
        $soffice = static::__find_soffice();
        if ($soffice === null) {
            error_log('Libreoffice_Thumbnail_Renderer: soffice binary not found (checked rsx.libreoffice.binary_path and PATH) - cannot render document preview');
            throw new Exception('LibreOffice (soffice) is not installed or not configured');
        }

        $timeout = (int) config('rsx.libreoffice.timeout', 30);
        $max_concurrent = (int) config('rsx.libreoffice.max_concurrent', 2);
        $slot_wait = (int) config('rsx.libreoffice.slot_wait_timeout', 30);

        // Bound simultaneous LibreOffice renders cluster-wide. A crashed render's slot is freed
        // the instant its process dies; 0 max_concurrent = unlimited (semaphore no-ops).
        $slot = RsxLocks::acquire_semaphore(static::SEMAPHORE_NAME, $max_concurrent, $slot_wait);
        if ($slot === null) {
            error_log("Libreoffice_Thumbnail_Renderer: no free concurrency slot within {$slot_wait}s (max_concurrent={$max_concurrent})");
            throw new Exception('LibreOffice concurrency slot unavailable');
        }

        // Private work dir - LibreOffice needs an isolated user profile and an output dir.
        $work_dir = sys_get_temp_dir() . '/rsx_soffice_' . bin2hex(random_bytes(8));
        if (!mkdir($work_dir, 0700, true) && !is_dir($work_dir)) {
            RsxLocks::release_semaphore($slot);
            throw new Exception("Failed to create LibreOffice work dir: {$work_dir}");
        }

        try {
            $process = new Process([
                $soffice,
                '-env:UserInstallation=file://' . $work_dir . '/profile',
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $work_dir,
                $source_path,
            ]);

            // Hard cap the render, measured from now (after slot acquisition).
            $process->setTimeout($timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                error_log('Libreoffice_Thumbnail_Renderer: conversion failed: ' . $process->getErrorOutput());
                throw new Exception('LibreOffice conversion failed for ' . basename($source_path));
            }

            // soffice names the output after the source basename with a .pdf extension.
            $pdf_path = $work_dir . '/' . pathinfo($source_path, PATHINFO_FILENAME) . '.pdf';
            if (!file_exists($pdf_path)) {
                throw new Exception('LibreOffice produced no PDF for ' . basename($source_path));
            }

            // Rasterize page 1. Density is chosen from the width hint so a US-Letter-ish page fills
            // the requested pixels without over-rendering (clamped to a sane range).
            $density = max(72, min(300, (int) ceil($max_width / 8.5)));

            $image = new Imagick();
            $image->setResolution($density, $density);
            $image->readImage($pdf_path . '[0]');
            $image->setImageBackgroundColor('white');
            $image = $image->flattenImages();

            return $image;
        } finally {
            RsxLocks::release_semaphore($slot);
            static::__rmdir_recursive($work_dir);
        }
    }

    /**
     * Locate the soffice binary. Delegates to the shared Libreoffice helper so binary
     * discovery has one implementation across the thumbnail renderer and text extractor.
     *
     * @return string|null
     */
    protected static function __find_soffice(): ?string
    {
        return Libreoffice::find_soffice();
    }

    /**
     * Recursively remove a directory tree (best-effort cleanup of the temp work dir).
     *
     * @param string $dir
     * @return void
     */
    protected static function __rmdir_recursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                static::__rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
