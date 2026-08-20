<?php

namespace App\RSpade\Core\Files;

use Imagick;
use App\RSpade\Core\Files\Rsx_Thumbnail_Renderer_Abstract;

/**
 * Imagick_Thumbnail_Renderer - the default raster renderer.
 *
 * Handles raster images (byte-identical to the historic thumbnail path - literally the same
 * `new Imagick($source_path)` the pipeline used to do inline) and PDFs (first page, requires
 * Imagick built with Ghostscript). Returns the source at native resolution; the downstream
 * pipeline applies the 66%/2x/max resize math unchanged.
 */
class Imagick_Thumbnail_Renderer extends Rsx_Thumbnail_Renderer_Abstract
{
    /**
     * @param string $source_path
     * @param int    $max_width
     * @param int    $max_height
     * @return Imagick
     */
    public static function render(string $source_path, int $max_width, int $max_height): Imagick
    {
        // Read the first frame/page. For multi-page/multi-frame sources the [0] suffix pins
        // rasterization to page 1 (PDFs) or frame 1 (animated images). For a plain single-frame
        // raster this yields the exact same pixels the historic inline path produced.
        $image = new Imagick();
        $image->readImage($source_path . '[0]');

        return $image;
    }
}
