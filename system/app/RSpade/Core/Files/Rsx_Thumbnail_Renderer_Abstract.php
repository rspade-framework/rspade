<?php

namespace App\RSpade\Core\Files;

use Imagick;

/**
 * Rsx_Thumbnail_Renderer_Abstract - the pluggable "produce a raster from bytes" step of the
 * thumbnail pipeline, keyed by mime type via config('rsx.thumbnails.renderers').
 *
 * A renderer's ONLY job is to turn a source blob into an Imagick raster handle the pipeline can
 * then resize/crop/encode exactly as it always has. Everything downstream is unchanged: the same
 * content-hash-keyed cache, quota sweeps, endpoints, and authorization gates.
 *
 * Returning a handle (rather than writing an intermediate PNG) keeps raster-image thumbnails
 * byte-identical to the historic path - the image renderer is a trivial `new Imagick($source)`.
 *
 * Fail loud: render() MUST throw on any failure. The PIPELINE (not the renderer) decides what to
 * do on failure - it falls back to the generic extension icon and logs the failure.
 */
abstract class Rsx_Thumbnail_Renderer_Abstract
{
    /**
     * Produce an Imagick raster handle for the source file. The caller owns the returned handle
     * and will clear()/destroy() it. $max_width/$max_height are an upper bound the renderer MAY
     * use to pick a sensible render resolution (e.g. document rasterization density); renderers
     * that return the source at native resolution may ignore them.
     *
     * @param string $source_path Absolute path to the resident source blob.
     * @param int    $max_width   Upper-bound width hint (pixels).
     * @param int    $max_height  Upper-bound height hint (pixels).
     * @return Imagick
     * @throws \Exception on any failure (fail loud).
     */
    abstract public static function render(string $source_path, int $max_width, int $max_height): Imagick;
}
