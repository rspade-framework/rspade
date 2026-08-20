<?php

namespace App\RSpade\Integrations\Jqhtml;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Jqhtml - Public-facing JQHTML integration class
 *
 * This class provides the main public API for working with JQHTML components
 * in PHP/Blade templates. It outputs initialization divs that are processed
 * by client-side JavaScript to mount the actual components.
 */
class Jqhtml
{
    /**
     * Render a JQHTML component initialization div
     *
     * @param string $component_name Component class name (e.g., 'User_Card')
     * @param array $args Arguments to pass to the component (becomes this.args)
     * @param string $slot_content Inner HTML content for the component slot
     * @return string HTML div with initialization attributes
     */
    public static function component(string $component_name, array $args = [], string $slot_content = ''): string
    {
        if ($slot_content !== '') {
            return sprintf(
                '<div class="Component_Init" data-component-init-name="%s" data-component-args="%s">%s</div>',
                htmlspecialchars($component_name),
                htmlspecialchars(json_encode($args), ENT_QUOTES, 'UTF-8'),
                $slot_content
            );
        }

        return sprintf(
            '<div class="Component_Init" data-component-init-name="%s" data-component-args="%s"></div>',
            htmlspecialchars($component_name),
            htmlspecialchars(json_encode($args), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Get JQHTML template metadata by component ID
     *
     * @param string $template_id Component name (e.g., 'Counter_Widget')
     * @return array|null Template file metadata from manifest, or null if not found
     */
    public static function get_jqhtml_template_by_id(string $template_id): ?array
    {
        $manifest = Manifest::get_full_manifest();

        // Look up component in jqhtml index
        if (!isset($manifest['data']['jqhtml']['components'][$template_id])) {
            // INTENTIONAL DEVIATION: Return null for missing template instead of throwing exception
            // This allows callers to gracefully handle missing components
            return null;
        }

        $component = $manifest['data']['jqhtml']['components'][$template_id];

        // Get template file path
        if (!isset($component['template_file'])) {
            shouldnt_happen("JQHTML component '{$template_id}' has no template_file in manifest");
        }

        $template_file = $component['template_file'];

        // Get full file metadata
        if (!isset($manifest['data']['files'][$template_file])) {
            shouldnt_happen("JQHTML template file '{$template_file}' for component '{$template_id}' not found in manifest files");
        }

        return $manifest['data']['files'][$template_file];
    }
}