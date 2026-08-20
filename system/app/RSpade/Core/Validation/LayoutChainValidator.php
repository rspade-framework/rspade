<?php

namespace App\RSpade\Core\Validation;

use RuntimeException;
use App\RSpade\CodeQuality\RuntimeChecks\ViewErrors;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Validates layout chains and bundle placement in embedded layouts
 *
 * Ensures that:
 * - Only the topmost layout in a chain has a bundle
 * - No circular dependencies exist in layout chains
 * - Bundle placement follows framework conventions
 */
class LayoutChainValidator
{
    /**
     * Validate an entire layout chain starting from a view
     *
     * @param string $view_id The RSX ID of the starting view
     * @throws RuntimeException if validation fails
     */
    public static function validate_layout_chain(string $view_id): void
    {
        $chain = self::_build_layout_chain($view_id);
        $found_bundle = false;
        $layout_with_bundle = null;

        // Skip validation if no layouts in chain (just a standalone view)
        if (empty($chain)) {
            return;
        }

        // Check each layout in the chain for a bundle
        foreach ($chain as $index => $layout) {
            // Skip if this is the view itself, not a layout
            if ($layout['is_layout'] === false) {
                continue;
            }

            $layout_path = base_path($layout['path']);
            if (file_exists($layout_path)) {
                $content = file_get_contents($layout_path);

                // Check for bundle render call
                if (strpos($content, '_Bundle::render()') !== false) {
                    // Only the topmost layout should have a bundle
                    $is_topmost = ($index === count($chain) - 1);

                    if (!$is_topmost) {
                        // This is an intermediate layout with a bundle - not allowed
                        ViewErrors::intermediate_layout_has_bundle(
                            $layout['path'],
                            $layout['id']
                        );
                    }

                    $found_bundle = true;
                    $layout_with_bundle = $layout['id'];
                }
            }
        }

        // Find the topmost layout (last layout in chain)
        $topmost_layout = null;
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if ($chain[$i]['is_layout']) {
                $topmost_layout = $chain[$i];
                break;
            }
        }

        // The topmost layout MUST have a bundle (unless it's mail or print)
        if ($topmost_layout && !$found_bundle) {
            $filename = basename($topmost_layout['path']);
            // Skip validation for mail and print layouts
            if (!str_contains($filename, '.mail.') && !str_contains($filename, '.print.')) {
                ViewErrors::topmost_layout_missing_bundle(
                    $topmost_layout['path'],
                    $topmost_layout['id']
                );
            }
        }
    }

    /**
     * Build the complete layout chain from a starting view/layout
     *
     * @param string $start_id The RSX ID to start from
     * @return array The layout chain, from child to parent
     */
    private static function _build_layout_chain(string $start_id): array
    {
        $chain = [];
        $current_id = $start_id;
        $visited = [];  // Prevent infinite loops

        while ($current_id) {
            if (in_array($current_id, $visited)) {
                throw new RuntimeException(
                    "Circular layout dependency detected: " .
                    implode(' -> ', $visited) . ' -> ' . $current_id
                );
            }
            $visited[] = $current_id;

            // Try to get view metadata first
            $file_path = Manifest::find_view($current_id);
            if (!$file_path) {
                // If not found as view, try as layout
                $file_path = Manifest::find_view_by_rsx_id($current_id);
            }

            if (!$file_path) {
                break;
            }

            // Get metadata to find what this extends
            $metadata = Manifest::get_file($file_path);
            if (!$metadata) {
                break;
            }

            $is_layout = isset($metadata['is_layout']) && $metadata['is_layout'];

            $chain[] = [
                'id' => $current_id,
                'path' => $file_path,
                'is_layout' => $is_layout,
                'extends' => $metadata['rsx_extends'] ?? null
            ];

            // Move to the parent layout
            $current_id = $metadata['rsx_extends'] ?? null;
        }

        return $chain;
    }
}