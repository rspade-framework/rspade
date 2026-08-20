<?php

namespace App\RSpade\Core\Validation;

use App\RSpade\CodeQuality\RuntimeChecks\ViewErrors;

/**
 * Validates RSX view files for proper asset organization
 * 
 * Enforces:
 * - No inline <style> or <script> tags in views (not layouts)
 * - Layouts must have rsx_body_class() in body tag
 */
class ViewValidator
{
    /**
     * Validate a view file for inline styles and scripts
     * 
     * @param string $id The ID of the view
     * @param string $file_path The path to the view file
     * @param array $metadata The manifest metadata for the view
     * @throws \RuntimeException if validation fails
     */
    public static function validate_view(string $id, string $file_path, array $metadata): void
    {
        // Skip validation for layouts (they can have inline styles)
        if (isset($metadata['is_layout']) && $metadata['is_layout']) {
            return;
        }
        
        // Read the file content (file_path should be absolute)
        if (!str_starts_with($file_path, '/')) {
            $file_path = base_path($file_path);
        }
        $content = file_get_contents($file_path);
        
        // Remove PHP blocks, blade comments, and HTML comments to avoid false positives
        $cleaned_content = $content;
        $cleaned_content = preg_replace('/@php.*?@endphp/s', '', $cleaned_content);
        $cleaned_content = preg_replace('/{{--.*?--}}/s', '', $cleaned_content);
        $cleaned_content = preg_replace('/<!--.*?-->/s', '', $cleaned_content);
        
        // Check for inline styles or scripts
        if (stripos($cleaned_content, '<style') !== false || stripos($cleaned_content, '<script') !== false) {
            // Views cannot have inline assets
            ViewErrors::inline_assets_not_allowed(
                $file_path,
                $id,
                stripos($cleaned_content, '<style') !== false,
                stripos($cleaned_content, '<script') !== false
            );
        }
    }
    
    /**
     * Validate a layout file has the required body class function
     *
     * @param string $layout_path The path to the layout file
     * @throws \RuntimeException if validation fails
     */
    public static function validate_layout(string $layout_path): void
    {
        // Make sure path is absolute
        if (!str_starts_with($layout_path, '/')) {
            $layout_path = base_path($layout_path);
        }

        // Read the layout file
        $content = file_get_contents($layout_path);
        $filename = basename($layout_path);

        // Check if rsx_body_class() is present near a <body tag
        if (preg_match('/<body[^>]*>/i', $content, $matches)) {
            $body_tag = $matches[0];

            // Check if the body tag or nearby content has rsx_body_class()
            $context_start = max(0, strpos($content, $body_tag) - 100);
            $context_end = min(strlen($content), strpos($content, $body_tag) + strlen($body_tag) + 100);
            $context = substr($content, $context_start, $context_end - $context_start);

            if (strpos($context, 'rsx_body_class()') === false) {
                // Layout must have body class function
                ViewErrors::layout_missing_body_class($layout_path);
            }
        }

        // Skip validation for print and mail layouts
        if (str_contains($filename, '.print.') || str_contains($filename, '.mail.')) {
            return;
        }

        // Check layout structure - must have bundle OR @rsx_extends
        $has_bundle = strpos($content, '_Bundle::render()') !== false;
        $has_rsx_extends = strpos($content, '@rsx_extends') !== false;
        $has_html_close = strpos($content, '</html>') !== false;

        // Determine error condition
        if (!$has_bundle && !$has_rsx_extends) {
            // No bundle, no extends - this is incomplete
            if (!$has_html_close) {
                // Partial layout that's incomplete
                ViewErrors::layout_incomplete($layout_path);
            } else {
                // Full HTML doc missing bundle
                $path_parts = explode('/', $layout_path);
                $module_name = '';
                for ($i = count($path_parts) - 2; $i >= 0; $i--) {
                    if ($path_parts[$i] === 'app' && isset($path_parts[$i + 1])) {
                        $module_name = $path_parts[$i + 1];
                        break;
                    }
                }
                ViewErrors::layout_missing_bundle($layout_path, $module_name);
            }
        } elseif ($has_bundle && $has_rsx_extends) {
            // Has both bundle and extends - not allowed
            if (!$has_html_close) {
                // Embedded layout trying to render bundle
                ViewErrors::embedded_layout_has_bundle($layout_path);
            } else {
                // Topmost layout using rsx_extends
                ViewErrors::topmost_layout_has_extends($layout_path);
            }
        }
        // If has only bundle or only extends, that's valid
    }
}