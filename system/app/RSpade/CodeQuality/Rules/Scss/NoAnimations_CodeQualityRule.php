<?php

namespace App\RSpade\CodeQuality\Rules\Scss;

use App\RSpade\CodeQuality\Parsers\ScssContextParser;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * NoAnimationsRule - Enforces no element movement or position changes
 *
 * PHILOSOPHY: Elements must stay still. No movement, no size changes, no layout shifts.
 * Visual feedback should be limited to color changes and other non-layout properties.
 *
 * This rule uses ScssContextParser to accurately detect hover/focus/active states
 * and validates that only approved visual properties are modified.
 */
class NoAnimations_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'SCSS-ANIM-01';
    }

    public function get_name(): string
    {
        return 'No Animations or Movement';
    }

    public function get_description(): string
    {
        return 'Prohibits animations, transforms, and element movement in SCSS. Elements must stay still.';
    }

    public function get_file_patterns(): array
    {
        return ['*.scss', '*.css'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Check SCSS/CSS files for prohibited animation and movement patterns
     * Uses ScssContextParser for accurate context detection
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip minified files
        if (str_ends_with($file_path, '.min.css') || str_ends_with($file_path, '.min.scss')) {
            return;
        }

        // Check for file-level exception comment
        // Supports: /* rsx:disable SCSS-ANIM-01 */ or // rsx:disable SCSS-ANIM-01
        if (preg_match('/(?:\/\*|\/\/)\s*rsx:disable\s+SCSS-ANIM-01/', $contents)) {
            return;
        }

        // Parse SCSS using our context parser
        // See App\RSpade\CodeQuality\Parsers\ScssContextParser for implementation
        // This parser provides accurate SCSS context analysis including proper
        // handling of nesting and pseudo-state detection
        $contexts = ScssContextParser::parse_contexts($contents);

        // Also parse raw lines for animation-specific checks
        $lines = explode("\n", $contents);

        // Track base selectors and their properties for redundancy detection
        $base_properties = [];

        // First pass: collect base properties
        foreach ($contexts as $context) {
            if (!ScssContextParser::is_in_hover_context($context['selector'])) {
                $base_selector = ScssContextParser::get_base_selector($context['selector']);
                $base_properties[$base_selector] = $context['properties'];
            }
        }

        // Second pass: check for violations
        foreach ($contexts as $context) {
            $selector = $context['selector'];
            $properties = $context['properties'];
            $line_num = $context['line'];

            // Check hover/focus/active states for position changes
            if ($context['is_hover'] || $context['is_focus'] || $context['is_active']) {
                // Skip if inside a button mixin (button-variant, etc.)
                if (isset($context['parent_context']) && str_contains($context['parent_context'], 'button')) {
                    continue;
                }

                // Check if hover effects are on prohibited elements
                $allowed_elements = ['button', 'a', 'input', 'select', 'textarea', 'img', 'tr'];
                $allowed_classes = ['.btn', '.dropdown-item', '.nav-link'];
                $is_allowed_element = false;

                foreach ($allowed_elements as $element) {
                    if (str_contains(strtolower($selector), $element)) {
                        $is_allowed_element = true;
                        break;
                    }
                }

                foreach ($allowed_classes as $class) {
                    if (str_contains(strtolower($selector), $class)) {
                        $is_allowed_element = true;
                        break;
                    }
                }

                // Special case: .card is explicitly forbidden
                if (str_contains(strtolower($selector), '.card')) {
                    $is_allowed_element = false;
                }

                // If hover effects on non-clickable elements contain position/size changes, flag it
                // Note: Color/background/opacity changes are allowed on any element (visual feedback)
                // Only position-altering properties are prohibited
                if (!$is_allowed_element && !empty($properties)) {
                    $has_position_properties = false;
                    foreach (array_keys($properties) as $property) {
                        if (ScssContextParser::is_position_property($property) || str_starts_with($property, 'transform')) {
                            $has_position_properties = true;
                            break;
                        }
                    }

                    if ($has_position_properties) {
                        $this->add_violation(
                            $file_path,
                            $line_num,
                            "Position/size changes on hover for non-clickable elements are PROHIBITED",
                            $selector,
                            "Professional business applications must remain static. Remove position/size changes from hover states. Color, background, opacity, and other visual changes are allowed.",
                            'critical'
                        );
                        continue; // Skip further checks for this context
                    }
                }
                // Get base properties for comparison
                $base_selector = ScssContextParser::get_base_selector($selector);
                $base_props = $base_properties[$base_selector] ?? [];

                foreach ($properties as $property => $value) {
                    // Check if this is a position/size property (prohibited)
                    if (ScssContextParser::is_position_property($property)) {
                        // Check if it's redundant (same value as base)
                        if (isset($base_props[$property]) && $base_props[$property] === $value) {
                            $this->add_violation(
                                $file_path,
                                $line_num,
                                "Redundant property in hover state - same value as base style",
                                "$property: $value",
                                "Remove unnecessary property redeclaration. This property already has this value.",
                                'low'
                            );
                        } else {
                            $this->add_violation(
                                $file_path,
                                $line_num,
                                "CRITICAL: Position/size changes on hover/focus/active are PROHIBITED",
                                "$property: $value",
                                "Remove ALL position/size changes. Use ONLY: color, background-color, opacity, border-color, text-decoration, box-shadow, outline, filter",
                                'critical'
                            );
                        }
                    }

                    // Check for transform property (always prohibited in hover states)
                    if (str_starts_with($property, 'transform')) {
                        $this->add_violation(
                            $file_path,
                            $line_num,
                            "Transform effects in hover states are PROHIBITED",
                            "$property: $value",
                            "Remove transform from hover state. Elements must not move or rotate on interaction.",
                            'critical'
                        );
                    }
                }
            }

            // Check for animation properties in any context
            if (isset($properties['animation']) || isset($properties['animation-name'])) {
                // Only rotation animations for spinners are allowed
                $animation_value = $properties['animation'] ?? $properties['animation-name'] ?? '';
                if (!str_contains(strtolower($animation_value), 'spin') &&
                    !str_contains(strtolower($animation_value), 'rotate')) {
                    $this->add_violation(
                        $file_path,
                        $line_num,
                        "Animation property detected - only rotation for spinners allowed",
                        "animation: $animation_value",
                        "Only rotation animations for loading spinners are permitted. Remove all other animations.",
                        'critical'
                    );
                }
            }

            // Check for transition: all (always prohibited)
            if (isset($properties['transition']) && str_contains($properties['transition'], 'all')) {
                $this->add_violation(
                    $file_path,
                    $line_num,
                    "'transition: all' is PROHIBITED",
                    "transition: {$properties['transition']}",
                    "Specify exact properties: 'transition: opacity 0.3s, background-color 0.3s'. Never use 'all'.",
                    'critical'
                );
            }

            // Check for transitions on position/size properties
            if (isset($properties['transition'])) {
                $transition_value = $properties['transition'];
                $prohibited = ['transform', 'top', 'left', 'right', 'bottom',
                              'margin', 'padding', 'width', 'height'];

                foreach ($prohibited as $prop) {
                    if (str_contains($transition_value, $prop)) {
                        $this->add_violation(
                            $file_path,
                            $line_num,
                            "Transitions on position/size are PROHIBITED",
                            "transition: $transition_value",
                            "Only allowed transitions: color, opacity, background-color, border-color. Remove position/size transitions.",
                            'critical'
                        );
                        break;
                    }
                }
            }

            // Check for will-change property
            if (isset($properties['will-change']) && $properties['will-change'] !== 'auto') {
                $this->add_violation(
                    $file_path,
                    $line_num,
                    "will-change is PROHIBITED",
                    "will-change: {$properties['will-change']}",
                    "Remove will-change property. Elements must be static and predictable.",
                    'critical'
                );
            }
        }

        // Additional line-by-line checks for keyframes and other patterns
        // that the context parser doesn't handle
        $this->check_keyframes($file_path, $lines);
    }

    /**
     * Check for @keyframes animations
     * Only pure rotation for spinners is allowed
     */
    private function check_keyframes(string $file_path, array $lines): void
    {
        $in_keyframe = false;
        $keyframe_start_line = 0;
        $keyframe_content = '';
        $brace_depth = 0;

        foreach ($lines as $line_num => $line) {
            $trimmed = trim($line);

            // Skip comments
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // Check for @keyframes start
            if (preg_match('/@keyframes\s+(\w+)/i', $line, $matches)) {
                $in_keyframe = true;
                $keyframe_start_line = $line_num + 1;
                $keyframe_content = '';
                $brace_depth = 0;
            }

            if ($in_keyframe) {
                $keyframe_content .= $line . "\n";
                $brace_depth += substr_count($line, '{');
                $brace_depth -= substr_count($line, '}');

                // End of keyframe block
                if ($brace_depth === 0 && str_contains($line, '}')) {
                    // Check if keyframe contains prohibited animations
                    if (preg_match('/transform\s*:\s*translate/i', $keyframe_content) ||
                        preg_match('/transform\s*:\s*scale/i', $keyframe_content) ||
                        preg_match('/(top|left|right|bottom|margin|padding|width|height)\s*:/i', $keyframe_content)) {

                        $this->add_violation(
                            $file_path,
                            $keyframe_start_line,
                            "Keyframe animations with movement/scaling are PROHIBITED",
                            trim($lines[$keyframe_start_line - 1]),
                            "Only rotation keyframes for spinners are allowed. Remove all translate/scale/position animations.",
                            'critical'
                        );
                    }

                    $in_keyframe = false;
                }
            }
        }
    }
}