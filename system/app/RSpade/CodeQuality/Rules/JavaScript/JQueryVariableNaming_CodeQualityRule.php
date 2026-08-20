<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class JQueryVariableNaming_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-JQUERY-VAR-01';
    }
    
    public function get_name(): string
    {
        return 'jQuery Variable Naming Convention';
    }
    
    public function get_description(): string
    {
        return 'Enforces $ prefix for variables storing jQuery objects';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.js'];
    }
    
    public function get_default_severity(): string
    {
        return 'medium';
    }
    
    /**
     * jQuery methods that return jQuery objects
     */
    private const JQUERY_OBJECT_METHODS = [
        'parent', 'parents', 'parentsUntil', 'closest',
        'find', 'children', 'contents',
        'next', 'nextAll', 'nextUntil',
        'prev', 'prevAll', 'prevUntil',
        'siblings', 'add', 'addBack', 'andSelf',
        'end', 'filter', 'not', 'has',
        'eq', 'first', 'last', 'slice',
        'map', 'clone', 'wrap', 'wrapAll', 'wrapInner',
        'unwrap', 'replaceWith', 'replaceAll',
        'prepend', 'append', 'prependTo', 'appendTo',
        'before', 'after', 'insertBefore', 'insertAfter',
        'detach', 'empty', 'remove'
    ];
    
    /**
     * jQuery methods that return scalar values ONLY when called as getters (no arguments)
     * When called with arguments, these return jQuery object for chaining
     */
    private const GETTER_METHODS = [
        'data', 'attr', 'val', 'text', 'html', 'prop', 'css',
        'offset', 'position', 'scrollTop', 'scrollLeft',
        'width', 'height', 'innerWidth', 'innerHeight',
        'outerWidth', 'outerHeight',
    ];

    /**
     * jQuery methods that ALWAYS return scalar values
     */
    private const SCALAR_METHODS = [
        'index', 'size', 'get', 'toArray',
        'serialize', 'serializeArray',
        'is', 'hasClass', 'is_visible' // Custom RSpade methods
    ];

    /**
     * jQuery properties (not methods) that return scalar values
     * These are accessed without parentheses: $el.length, not $el.length()
     */
    private const SCALAR_PROPERTIES = [
        'length',
    ];
    
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in ./rsx/ directory
        if (!str_contains($file_path, '/rsx/') && !str_starts_with($file_path, 'rsx/')) {
            return;
        }
        
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Get both original and sanitized content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);
        
        // Get sanitized content with comments removed
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $sanitized_lines = $sanitized_data['lines'];
        
        foreach ($sanitized_lines as $line_num => $sanitized_line) {
            $line_number = $line_num + 1;
            
            // Skip if the line is empty in sanitized version
            if (trim($sanitized_line) === '') {
                continue;
            }
            
            $original_line = $original_lines[$line_num] ?? $sanitized_line;
            
            // Pattern to match variable assignments
            // Captures: 1=var declaration, 2=variable name, 3=right side expression
            $pattern = '/(?:^|\s)((?:let\s+|const\s+|var\s+)?)([a-zA-Z_$][a-zA-Z0-9_$]*)\s*=\s*(.+?)(?:;|$)/';
            
            if (preg_match($pattern, $sanitized_line, $matches)) {
                $var_decl = $matches[1];
                $var_name = $matches[2];
                $right_side = trim($matches[3]);
                $has_dollar = str_starts_with($var_name, '$');
                
                // Analyze the right side to determine if it returns jQuery object or scalar
                $expected_type = $this->analyze_expression($right_side);
                
                if ($expected_type === 'jquery') {
                    // Should have $ prefix
                    if (!$has_dollar) {
                        $this->add_violation(
                            $file_path,
                            $line_number,
                            "jQuery object must be stored in variable starting with $.",
                            trim($original_line),
                            "Rename variable '{$var_name}' to '\${$var_name}'. " .
                            "The expression returns a jQuery object and must be stored in a variable with $ prefix. " .
                            "In RSpade, $ prefix indicates jQuery objects only.",
                            'medium'
                        );
                    }
                } elseif ($expected_type === 'scalar') {
                    // Should NOT have $ prefix
                    if ($has_dollar) {
                        $this->add_violation(
                            $file_path,
                            $line_number,
                            "Scalar values should not use $ prefix.",
                            trim($original_line),
                            "Remove $ prefix from variable '{$var_name}'. Rename to '" . substr($var_name, 1) . "'. " .
                            "The expression returns a scalar value (string, number, boolean, or DOM element), not a jQuery object. " .
                            "In RSpade, $ prefix is reserved for jQuery objects only.",
                            'medium'
                        );
                    }
                }
                // If expected_type is 'unknown', we don't enforce either way
            }
        }
    }
    
    /**
     * Analyze an expression to determine if it returns jQuery object or scalar
     * @return string 'jquery', 'scalar', or 'unknown'
     */
    private function analyze_expression(string $expr): string
    {
        $expr = trim($expr);
        
        // Direct jQuery selector: $(...)
        if (preg_match('/^\$\s*\(/', $expr)) {
            // Check if it's creating an element: $('<element>')
            if (preg_match('/^\$\s*\(\s*[\'"]</', $expr)) {
                // Creating jQuery element - always returns jQuery object
                // Even with method chains like .text() or .attr(), the chaining continues
                // We only care about method chains that END with scalar methods
                if (preg_match('/^\$\s*\([^)]*\)(.*)/', $expr, $matches)) {
                    $chain = trim($matches[1]);
                    if ($chain === '') {
                        return 'jquery'; // Just $('<element>') with no methods
                    }
                    // Only check if chain ENDS with a scalar method
                    return $this->analyze_method_chain($chain);
                }
                return 'jquery';
            }

            // Regular selector or other jQuery call
            if (preg_match('/^\$\s*\([^)]*\)(.*)/', $expr, $matches)) {
                $chain = trim($matches[1]);
                if ($chain === '') {
                    return 'jquery'; // Just $(...) with no methods
                }
                return $this->analyze_method_chain($chain);
            }
            return 'jquery';
        }
        
        // Variable starting with $ (assumed to be jQuery)
        if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*(.*)/', $expr, $matches)) {
            $chain = trim($matches[1]);
            if ($chain === '') {
                return 'jquery'; // Just $variable with no methods
            }
            if (str_starts_with($chain, '[')) {
                // Array access like $element[0]
                return 'scalar';
            }
            return $this->analyze_method_chain($chain);
        }
        
        // Everything else is unknown or definitely not jQuery
        return 'unknown';
    }
    
    /**
     * Analyze a method chain to determine final return type
     * @param string $chain The method chain starting with . or [
     * @return string 'jquery', 'scalar', or 'unknown'
     */
    private function analyze_method_chain(string $chain): string
    {
        if (empty($chain)) {
            return 'jquery'; // No methods means original jQuery object
        }

        // Array access [0] or [index] returns DOM element (scalar)
        if (preg_match('/^\[[\d]+\]/', $chain)) {
            return 'scalar';
        }

        // Check if chain ENDS with a scalar property access (e.g., .length)
        // Pattern: .property at end of chain (no parentheses, possibly followed by operators)
        if (preg_match('/\.([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:[|&+\-*\/%]|$|;)/', $chain, $prop_match)) {
            $last_property = $prop_match[1];
            if (in_array($last_property, self::SCALAR_PROPERTIES, true)) {
                return 'scalar';
            }
        }

        // Find the last method call in the chain
        // Match patterns like .method() or .method(args)
        // Also capture what's inside the parentheses
        $methods = [];
        preg_match_all('/\.([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)/', $chain, $methods, PREG_SET_ORDER);

        if (empty($methods)) {
            // No method calls found
            return 'unknown';
        }

        // Check the last method to determine return type
        $last_method_data = end($methods);
        $last_method = $last_method_data[1];
        $last_args = trim($last_method_data[2] ?? '');

        if (in_array($last_method, self::JQUERY_OBJECT_METHODS, true)) {
            return 'jquery';
        }

        if (in_array($last_method, self::SCALAR_METHODS, true)) {
            return 'scalar';
        }

        // Check getter methods - return scalar for getters, jQuery for setters
        if (in_array($last_method, self::GETTER_METHODS, true)) {
            // Count arguments by splitting on commas (simple heuristic)
            // Note: This won't handle nested function calls perfectly, but works for common cases
            $arg_count = $last_args === '' ? 0 : (substr_count($last_args, ',') + 1);

            // Special handling for methods that take a key parameter
            // .data('key') - 1 arg = getter (returns value)
            // .data('key', value) - 2 args = setter (returns jQuery)
            // .attr('name') - 1 arg = getter (returns attribute value)
            // .attr('name', value) - 2 args = setter (returns jQuery)
            if (in_array($last_method, ['data', 'attr', 'prop', 'css'], true)) {
                if ($arg_count <= 1) {
                    return 'scalar'; // Getter with key - returns scalar value
                } else {
                    return 'jquery'; // Setter with key and value - returns jQuery for chaining
                }
            }

            // For other getter methods (text, html, val, etc.)
            // .text() - no args = getter (returns text)
            // .text('value') - 1 arg = setter (returns jQuery)
            if ($last_args === '') {
                return 'scalar'; // Getter mode - returns scalar
            } else {
                return 'jquery'; // Setter mode - returns jQuery object for chaining
            }
        }

        // Unknown method - could be custom plugin
        return 'unknown';
    }
}