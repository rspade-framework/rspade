<?php

namespace App\RSpade\Core\Files;

/**
 * File attachment icon resource management
 *
 * Provides static methods for determining the appropriate icon resource
 * for different file types based on file extension.
 */
class File_Attachment_Icons
{
    /**
     * Get the icon resource path for a given file extension
     *
     * @param string $extension File extension (without dot)
     * @return string Relative path to icon file
     */
    public static function get_icon_resource_by_file_extension($extension)
    {
        $extension = strtolower($extension);
        $base_path = 'system/app/RSpade/Core/Files/resource/icons';

        $icon_map = [
            // Brand-specific PNG icons
            'pdf' => 'pdf.png',
            'psd' => 'psd.png',
            'ai' => 'ai.png',

            // Images - Generic SVG
            'jpg' => 'image.svg',
            'jpeg' => 'image.svg',
            'png' => 'image.svg',
            'gif' => 'image.svg',
            'bmp' => 'image.svg',
            'svg' => 'image.svg',
            'webp' => 'image.svg',
            'ico' => 'image.svg',
            'tiff' => 'image.svg',
            'tif' => 'image.svg',
            'heic' => 'image.svg',
            'heif' => 'image.svg',
            'raw' => 'image.svg',
            'cr2' => 'image.svg',
            'nef' => 'image.svg',

            // Videos
            'mp4' => 'video.svg',
            'avi' => 'video.svg',
            'mov' => 'video.svg',
            'wmv' => 'video.svg',
            'flv' => 'video.svg',
            'mkv' => 'video.svg',
            'webm' => 'video.svg',
            'mpeg' => 'video.svg',
            'mpg' => 'video.svg',
            'm4v' => 'video.svg',
            '3gp' => 'video.svg',

            // Audio
            'mp3' => 'audio.svg',
            'wav' => 'audio.svg',
            'flac' => 'audio.svg',
            'aac' => 'audio.svg',
            'ogg' => 'audio.svg',
            'm4a' => 'audio.svg',
            'wma' => 'audio.svg',
            'aiff' => 'audio.svg',
            'alac' => 'audio.svg',

            // Archives
            'zip' => 'archive.svg',
            'rar' => 'archive.svg',
            '7z' => 'archive.svg',
            'tar' => 'archive.svg',
            'gz' => 'archive.svg',
            'bz2' => 'archive.svg',
            'xz' => 'archive.svg',
            'iso' => 'archive.svg',
            'dmg' => 'archive.svg',

            // Text files
            'txt' => 'text.svg',
            'md' => 'text.svg',
            'rtf' => 'text.svg',
            'log' => 'text.svg',

            // Code files
            'php' => 'code.svg',
            'js' => 'code.svg',
            'ts' => 'code.svg',
            'jsx' => 'code.svg',
            'tsx' => 'code.svg',
            'html' => 'code.svg',
            'css' => 'code.svg',
            'scss' => 'code.svg',
            'sass' => 'code.svg',
            'less' => 'code.svg',
            'json' => 'code.svg',
            'xml' => 'code.svg',
            'yaml' => 'code.svg',
            'yml' => 'code.svg',
            'py' => 'code.svg',
            'java' => 'code.svg',
            'c' => 'code.svg',
            'cpp' => 'code.svg',
            'h' => 'code.svg',
            'cs' => 'code.svg',
            'rb' => 'code.svg',
            'go' => 'code.svg',
            'rs' => 'code.svg',
            'swift' => 'code.svg',
            'kt' => 'code.svg',
            'sql' => 'code.svg',
            'sh' => 'code.svg',
            'bash' => 'code.svg',

            // 3D Models
            'stl' => '3d_model.svg',
            'obj' => '3d_model.svg',
            'fbx' => '3d_model.svg',
            'dae' => '3d_model.svg',
            'blend' => '3d_model.svg',
            '3ds' => '3d_model.svg',
            'f3d' => '3d_model.svg',
            'step' => '3d_model.svg',
            'stp' => '3d_model.svg',

            // Documents
            'doc' => 'document.svg',
            'docx' => 'document.svg',
            'odt' => 'document.svg',
            'pages' => 'document.svg',

            // Spreadsheets
            'xls' => 'spreadsheet.svg',
            'xlsx' => 'spreadsheet.svg',
            'ods' => 'spreadsheet.svg',
            'numbers' => 'spreadsheet.svg',
            'csv' => 'spreadsheet.svg',

            // Presentations
            'ppt' => 'presentation.svg',
            'pptx' => 'presentation.svg',
            'odp' => 'presentation.svg',
            'key' => 'presentation.svg',
        ];

        $icon_file = $icon_map[$extension] ?? 'file.svg';
        return $base_path . '/' . $icon_file;
    }

    /**
     * Get icon as PNG at specified dimensions
     *
     * Loads the appropriate icon file (SVG or PNG) for the given extension
     * and converts it to PNG at the target dimensions using "fit" mode
     * (maintains aspect ratio, no cropping).
     *
     * @param string $extension File extension (without dot)
     * @param int $width Target width in pixels
     * @param int $height Target height in pixels
     * @return string Binary PNG data
     */
    public static function get_icon_as_png($extension, $width, $height)
    {
        // Get the icon file path (relative from RSX root, which is one level up from Laravel base_path)
        $icon_path = static::get_icon_resource_by_file_extension($extension);
        $full_path = dirname(base_path()) . '/' . ltrim($icon_path, '/');

        if (!file_exists($full_path)) {
            // Safety*: Use generic icon if specific icon file missing from disk
            $full_path = base_path('app/RSpade/Core/Files/resource/icons/file.svg');
        }

        // Determine if source is SVG or PNG
        $is_svg = pathinfo($full_path, PATHINFO_EXTENSION) === 'svg';

        // Load image with Imagick
        $image = new \Imagick();

        if ($is_svg) {
            // For SVG: Set background to transparent and target size before reading
            $image->setBackgroundColor(new \ImagickPixel('transparent'));
            $image->setResolution($width, $height);
            $image->readImage($full_path);
            $image->setImageFormat('png');
        } else {
            // For PNG: Read then resize
            $image->readImage($full_path);
        }

        // Get original dimensions
        $original_width = $image->getImageWidth();
        $original_height = $image->getImageHeight();

        // Calculate scaling to fit within bounds (maintain aspect ratio)
        $scale_width = $width / $original_width;
        $scale_height = $height / $original_height;
        $scale = min($scale_width, $scale_height);

        $new_width = (int)round($original_width * $scale);
        $new_height = (int)round($original_height * $scale);

        // Resize image to fit
        $image->resizeImage($new_width, $new_height, \Imagick::FILTER_LANCZOS, 1);

        // Create transparent canvas at target dimensions
        $canvas = new \Imagick();
        $canvas->newImage($width, $height, new \ImagickPixel('transparent'));
        $canvas->setImageFormat('png');

        // Center the resized image on canvas
        $offset_x = (int)round(($width - $new_width) / 2);
        $offset_y = (int)round(($height - $new_height) / 2);
        $canvas->compositeImage($image, \Imagick::COMPOSITE_OVER, $offset_x, $offset_y);

        // Set PNG compression
        $canvas->setImageCompressionQuality(85);

        // Get binary data
        $png_data = $canvas->getImageBlob();

        // Clean up
        $image->destroy();
        $canvas->destroy();

        return $png_data;
    }

    /**
     * Render icon as thumbnail in WebP format
     *
     * Creates a thumbnail-style image for files that don't have an actual thumbnail.
     * For small requests (< 72x72), uses the icon scaled to fill the dimensions.
     * For larger requests, embeds a 64x64 icon centered on a white canvas.
     *
     * CACHING ARCHITECTURE:
     * ---------------------
     * This function does NOT cache results internally. When thumbnail caching is
     * implemented, the cache should be managed at the STORAGE LAYER, not here.
     *
     * The caching strategy will be:
     * - Cache location: Dedicated thumbnail cache directory
     * - Cache key pattern: {extension}_{width}x{height}.webp
     * - Deduplication: Multiple files of same type share cached thumbnails
     * - Cache invalidation: By icon file modification time
     *
     * Example cache structure:
     *   /storage/thumbnail-cache/icons/pdf_200x200.webp
     *   /storage/thumbnail-cache/icons/stl_100x100.webp
     *
     * This allows:
     * 1. All PDF files share the same 200x200 icon thumbnail
     * 2. Cache can be pre-warmed for common sizes
     * 3. Icon updates invalidate all cached thumbnails for that type
     * 4. Separation from file-specific thumbnail caches
     *
     * When implementing caching, add the cache layer in the controller's thumbnail
     * endpoint BEFORE calling this method, checking for cached icon thumbnails by
     * extension and dimensions.
     *
     * @param string $extension File extension (without dot)
     * @param int $width Target width in pixels
     * @param int $height Target height in pixels
     * @return string Binary WebP data
     */
    public static function render_icon_as_thumbnail($extension, $width, $height)
    {
        // For small thumbnails (< 72x72), use the icon itself as source
        if ($width < 72 || $height < 72) {
            // Get icon as PNG
            $icon_path = static::get_icon_resource_by_file_extension($extension);
            $full_path = dirname(base_path()) . '/' . ltrim($icon_path, '/');

            if (!file_exists($full_path)) {
                $full_path = base_path('app/RSpade/Core/Files/resource/icons/file.svg');
            }

            $is_svg = pathinfo($full_path, PATHINFO_EXTENSION) === 'svg';

            // Load icon
            $image = new \Imagick();

            if ($is_svg) {
                $image->setBackgroundColor(new \ImagickPixel('transparent'));
                $image->setResolution($width, $height);
                $image->readImage($full_path);
            } else {
                $image->readImage($full_path);
            }

            // Get original dimensions
            $original_width = $image->getImageWidth();
            $original_height = $image->getImageHeight();

            // Calculate scaling to cover entire area (may crop)
            $scale_width = $width / $original_width;
            $scale_height = $height / $original_height;
            $scale = max($scale_width, $scale_height);

            $new_width = (int)round($original_width * $scale);
            $new_height = (int)round($original_height * $scale);

            // Resize image to cover
            $image->resizeImage($new_width, $new_height, \Imagick::FILTER_LANCZOS, 1);

            // Crop to exact dimensions if needed
            if ($new_width > $width || $new_height > $height) {
                $offset_x = (int)round(($new_width - $width) / 2);
                $offset_y = (int)round(($new_height - $height) / 2);
                $image->cropImage($width, $height, $offset_x, $offset_y);
            }

            // Convert to WebP
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(85);

            $webp_data = $image->getImageBlob();
            $image->destroy();

            return $webp_data;
        }

        // For larger thumbnails, embed 64x64 icon on white canvas
        // Get icon as 64x64 PNG
        $icon_png = static::get_icon_as_png($extension, 64, 64);

        // Load the 64x64 icon
        $icon = new \Imagick();
        $icon->readImageBlob($icon_png);

        // Create white canvas at target dimensions
        $canvas = new \Imagick();
        $canvas->newImage($width, $height, new \ImagickPixel('white'));
        $canvas->setImageFormat('webp');

        // Center the 64x64 icon on canvas
        $offset_x = (int)round(($width - 64) / 2);
        $offset_y = (int)round(($height - 64) / 2);
        $canvas->compositeImage($icon, \Imagick::COMPOSITE_OVER, $offset_x, $offset_y);

        // Set WebP compression
        $canvas->setImageCompressionQuality(85);

        // Get binary data
        $webp_data = $canvas->getImageBlob();

        // Clean up
        $icon->destroy();
        $canvas->destroy();

        return $webp_data;
    }
}
