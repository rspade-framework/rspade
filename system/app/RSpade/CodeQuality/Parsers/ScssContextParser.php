<?php

namespace App\RSpade\CodeQuality\Parsers;

/**
 * Lightweight SCSS context parser for code quality rules
 *
 * PURPOSE: This is NOT a full SCSS compiler/parser. It only tracks:
 * - Selector nesting and building full selector paths
 * - Property declarations within each selector context
 * - Pseudo-state detection (:hover, :focus, :active)
 *
 * DESIGN: Simple state machine that builds selector context by tracking braces
 * and nesting. Designed for code quality rules that need to understand what
 * properties are set in hover/focus states vs base states.
 *
 * USAGE:
 *   $contexts = ScssContextParser::parse_contexts($scss_content);
 *   foreach ($contexts as $context) {
 *       if (ScssContextParser::is_in_hover_context($context['selector'])) {
 *           // Check properties...
 *       }
 *   }
 *
 * FUTURE: Can be extended to track @media queries, mixins, or other SCSS
 * features as needed by new rules. Each code quality rule documents what
 * parsing features it requires.
 */
class ScssContextParser
{
    /**
     * Parse SCSS content into selector contexts with their properties
     *
     * @param string $scss Raw SCSS content
     * @return array Array of contexts, each with:
     *   - 'line': Line number where selector starts
     *   - 'selector': Full selector path (e.g., '.nav-link:hover')
     *   - 'properties': Associative array of property => value
     *   - 'is_hover': Boolean if selector contains :hover
     *   - 'is_focus': Boolean if selector contains :focus
     *   - 'is_active': Boolean if selector contains :active
     */
    public static function parse_contexts(string $scss): array
    {
        $lines = explode("\n", $scss);
        $contexts = [];
        $selector_stack = [];
        $current_context = null;
        $brace_depth = 0;
        $in_comment = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $line_num = $i + 1;
            $trimmed = trim($line);

            // Skip empty lines
            if (empty($trimmed)) {
                continue;
            }

            // Handle multi-line comments
            if (str_contains($line, '/*')) {
                $in_comment = true;
            }
            if ($in_comment) {
                if (str_contains($line, '*/')) {
                    $in_comment = false;
                }
                continue;
            }

            // Skip single-line comments
            if (str_starts_with($trimmed, '//')) {
                continue;
            }

            // Remove inline comments for processing
            $clean_line = preg_replace('/\/\/.*$/', '', $line);
            $clean_line = preg_replace('/\/\*.*?\*\//', '', $clean_line);
            $trimmed_clean = trim($clean_line);

            // Count braces before processing
            $open_braces = substr_count($clean_line, '{');
            $close_braces = substr_count($clean_line, '}');

            // Handle closing braces - pop from selector stack
            for ($j = 0; $j < $close_braces; $j++) {
                if (!empty($selector_stack)) {
                    array_pop($selector_stack);
                }
                $brace_depth--;

                // Save current context when closing its block
                if ($current_context && $brace_depth < $current_context['depth']) {
                    $contexts[] = $current_context;
                    $current_context = null;
                }
            }

            // Check if this line starts a new selector block
            if ($open_braces > 0 && !empty($trimmed_clean)) {
                // Extract the selector part (before the {)
                $selector_part = trim(str_replace('{', '', $trimmed_clean));

                // Skip @keyframes, @media, @import etc
                if (str_starts_with($selector_part, '@')) {
                    $brace_depth += $open_braces;
                    continue;
                }

                // Build full selector path
                $full_selector = self::build_selector_path($selector_stack, $selector_part);

                // Push to stack for nested selectors
                $selector_stack[] = $selector_part;
                $brace_depth += $open_braces;

                // Create new context
                $current_context = [
                    'line' => $line_num,
                    'selector' => $full_selector,
                    'properties' => [],
                    'depth' => $brace_depth,
                    'is_hover' => str_contains($full_selector, ':hover'),
                    'is_focus' => str_contains($full_selector, ':focus'),
                    'is_active' => str_contains($full_selector, ':active')
                ];
            } elseif ($open_braces > 0) {
                // Opening brace without selector (continuation from previous line)
                $brace_depth += $open_braces;
            }

            // Parse property declarations within current context
            if ($current_context && $brace_depth === $current_context['depth']) {
                if (preg_match('/^\s*([a-z-]+)\s*:\s*(.+?);?\s*$/i', $trimmed_clean, $matches)) {
                    $property = $matches[1];
                    $value = trim($matches[2], '; ');
                    $current_context['properties'][$property] = $value;
                }
            }
        }

        // Save any remaining context
        if ($current_context) {
            $contexts[] = $current_context;
        }

        return $contexts;
    }

    /**
     * Build full selector path from selector stack
     * Handles SCSS & parent reference properly
     */
    private static function build_selector_path(array $stack, string $current): string
    {
        if (empty($stack)) {
            return $current;
        }

        $parent = implode(' ', $stack);

        // Handle & parent reference
        if (str_starts_with($current, '&')) {
            // Replace & with the immediate parent (last item in stack)
            $immediate_parent = end($stack);
            $current = str_replace('&', '', $current);

            // Remove last item and rebuild
            $stack_without_last = array_slice($stack, 0, -1);
            if (empty($stack_without_last)) {
                return $immediate_parent . $current;
            }
            return implode(' ', $stack_without_last) . ' ' . $immediate_parent . $current;
        }

        // Handle nested selectors without &
        return $parent . ' ' . $current;
    }

    /**
     * Check if a selector represents a hover/focus/active state
     */
    public static function is_in_hover_context(string $selector): bool
    {
        return str_contains($selector, ':hover') ||
               str_contains($selector, ':focus') ||
               str_contains($selector, ':active');
    }

    /**
     * Get the base selector without pseudo-states
     * Example: '.btn:hover' => '.btn'
     */
    public static function get_base_selector(string $selector): string
    {
        return preg_replace('/:(hover|focus|active|visited|disabled)/', '', $selector);
    }

    /**
     * Compare properties between two contexts to find differences
     * Useful for detecting redundant declarations or actual changes
     */
    public static function compare_properties(array $base_props, array $state_props): array
    {
        $differences = [
            'added' => [],
            'changed' => [],
            'same' => [],
            'removed' => []
        ];

        foreach ($state_props as $prop => $value) {
            if (!isset($base_props[$prop])) {
                $differences['added'][$prop] = $value;
            } elseif ($base_props[$prop] !== $value) {
                $differences['changed'][$prop] = [
                    'from' => $base_props[$prop],
                    'to' => $value
                ];
            } else {
                $differences['same'][$prop] = $value;
            }
        }

        foreach ($base_props as $prop => $value) {
            if (!isset($state_props[$prop])) {
                $differences['removed'][$prop] = $value;
            }
        }

        return $differences;
    }

    /**
     * Check if a property is position/size related
     * These are typically prohibited in hover states
     */
    public static function is_position_property(string $property): bool
    {
        $position_properties = [
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'top', 'right', 'bottom', 'left',
            'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
            'font-size', 'line-height', 'letter-spacing', 'word-spacing'
        ];

        return in_array($property, $position_properties);
    }

    /**
     * Check if a property is visual-only (safe for hover)
     * These don't affect layout or position
     */
    public static function is_visual_only_property(string $property): bool
    {
        $visual_properties = [
            'color', 'background-color', 'background', 'background-image',
            'opacity', 'visibility',
            'border-color', 'outline', 'outline-color',
            'text-decoration', 'text-decoration-color',
            'box-shadow', 'text-shadow',
            'filter', 'backdrop-filter',
            'cursor'
        ];

        // Check for exact match or if property starts with one of these
        foreach ($visual_properties as $visual_prop) {
            if ($property === $visual_prop || str_starts_with($property, $visual_prop . '-')) {
                return true;
            }
        }

        return false;
    }
}