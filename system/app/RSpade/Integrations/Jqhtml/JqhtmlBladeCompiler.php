<?php

namespace App\RSpade\Integrations\Jqhtml;

/**
 * Custom Blade precompiler for jqhtml components
 *
 * Transforms uppercase component tags into jqhtml component calls.
 * Example: <User_Card name="John" /> becomes Jqhtml::component('User_Card', ['name' => 'John'])
 */
class JqhtmlBladeCompiler
{
    /**
     * Cached list of jqhtml component names from manifest
     */
    private static ?array $jqhtml_components = null;

    /**
     * Get list of jqhtml components from manifest
     */
    private static function __get_jqhtml_components(): array
    {
        if (self::$jqhtml_components === null) {
            // Get the cached list of jqhtml components from the manifest support module
            self::$jqhtml_components = \App\RSpade\Integrations\Jqhtml\Jqhtml_ManifestSupport::get_jqhtml_components();
        }
        return self::$jqhtml_components;
    }

    /**
     * Precompile Blade template to transform jqhtml component tags
     *
     * @param string $value The Blade template content
     * @return string Transformed content
     */
    public static function precompile(string $value): string
    {
        // Pattern to match tags that start with uppercase letter
        // Matches both self-closing and paired tags
        $pattern = '/<([A-Z][a-zA-Z0-9_]*)((?:\s+\$?[a-zA-Z0-9_\-:]+(?:=(?:"[^"]*"|\'[^\']*\'|[^>\s]+))?)*)\s*(?:\/>|>(.*?)<\/\1>)/s';

        $value = preg_replace_callback($pattern, function ($matches) {
            $component_name = $matches[1];
            $attributes = $matches[2] ?? '';
            $slot_content = $matches[3] ?? null;

            // Parse attributes into array
            $parsed_attrs = self::__parse_attributes($attributes);

            // Convert to PHP array syntax
            $php_array = self::__to_php_array($parsed_attrs);

            // If there's slot content, we need to output the div directly to allow blade processing of the content
            if ($slot_content !== null && trim($slot_content) !== '') {
                // Check for slot syntax - not allowed in Blade
                if (preg_match('/<Slot:[a-zA-Z0-9_]+/', $slot_content)) {
                    throw new \RuntimeException(
                        "JQHTML slot syntax (<Slot:slotname>) is not allowed in Blade files.\n" .
                        "Component '{$component_name}' contains slot tags in its innerHTML.\n" .
                        "Use standard innerHTML with content() function instead.\n\n" .
                        "Blade usage:\n" .
                        "  <{$component_name}>\n" .
                        "    Your content here\n" .
                        "  </{$component_name}>\n\n" .
                        "Template definition:\n" .
                        "  <Define:{$component_name}>\n" .
                        "    <%= content() %>\n" .
                        "  </Define:{$component_name}>"
                    );
                }

                // Check for block syntax - not allowed in Blade
                if (preg_match('/<\/?Block:([a-zA-Z0-9_]+)/i', $slot_content, $block_match)) {
                    $block_name = $block_match[1] ?? 'Unknown';
                    throw new \RuntimeException(
                        "JQHTML block/slot syntax (<Block:{$block_name}>) is not allowed in Blade files.\n" .
                        "Component '{$component_name}' contains block tags in its innerHTML.\n\n" .
                        "Block/slot content requires jqhtml template compilation and ONLY works in .jqhtml files.\n" .
                        "Blade renders components server-side as standard HTML - it cannot pass block content to jqhtml components.\n\n" .
                        "OPTION 1: Pass data via component arguments (recommended)\n" .
                        "  Instead of using blocks, pass all data as component arguments:\n\n" .
                        "  WRONG (Blade):\n" .
                        "    <{$component_name}>\n" .
                        "      <Block:{$block_name}>\n" .
                        "        Custom content...\n" .
                        "      </Block:{$block_name}>\n" .
                        "    </{$component_name}>\n\n" .
                        "  CORRECT (Blade):\n" .
                        "    <{$component_name} \$custom_content=\"...\" />\n\n" .
                        "  Then handle customization in the component's .jqhtml template file.\n\n" .
                        "OPTION 2: Move to .jqhtml template file\n" .
                        "  If you need block/slot functionality:\n\n" .
                        "  1. Create a .jqhtml wrapper component that uses blocks\n" .
                        "  2. Use the wrapper in Blade as a self-closing tag\n\n" .
                        "BLADE RULE: Jqhtml components in Blade are ALWAYS self-closing or use standard innerHTML only.\n" .
                        "Blocks/slots only work in .jqhtml -> .jqhtml relationships."
                    );
                }

                // Recursively process slot content for nested components
                $slot_content = self::precompile($slot_content);

                // Separate $-prefixed attributes (component args) from regular attributes
                $component_args = [];
                $html_attrs = [];
                $wrapper_tag = self::__get_default_wrapper_tag($component_name);

                foreach ($parsed_attrs as $key => $attr) {
                    if (str_starts_with($key, '$')) {
                        // Component arg - remove $ prefix
                        $arg_key = substr($key, 1);
                        if ($attr['type'] === 'expression') {
                            $component_args[$arg_key] = ['type' => 'expression', 'value' => $attr['value']];
                        } else {
                            $component_args[$arg_key] = ['type' => 'string', 'value' => $attr['value']];
                        }
                    } elseif (str_starts_with($key, 'data-')) {
                        // Component arg - remove data- prefix
                        $arg_key = substr($key, 5);
                        if ($attr['type'] === 'expression') {
                            $component_args[$arg_key] = ['type' => 'expression', 'value' => $attr['value']];
                        } else {
                            $component_args[$arg_key] = ['type' => 'string', 'value' => $attr['value']];
                        }
                    } elseif ($key === 'tag') {
                        // Special case: tag attribute becomes _tag component arg AND sets the wrapper element
                        if ($attr['type'] === 'expression') {
                            $component_args['_tag'] = ['type' => 'expression', 'value' => $attr['value']];
                        } else {
                            $component_args['_tag'] = ['type' => 'string', 'value' => $attr['value']];
                            // Use the tag value as the wrapper element (only for string literals, not expressions)
                            $tag_value = $attr['value'];
                            if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $tag_value)) {
                                $wrapper_tag = $tag_value;
                            }
                        }
                    } else {
                        // Regular HTML attribute
                        $html_attrs[$key] = $attr;
                    }
                }

                // Build component args JSON
                $json_args = self::__to_php_array($component_args);
                if (empty($json_args) || $json_args === '[]') {
                    $json_args = '[]';
                } else {
                    $json_args = "json_encode({$json_args})";
                }

                // Build HTML attributes string
                // Handle class attribute specially to merge with _Component_Init
                $class_value = '_Component_Init';
                if (isset($html_attrs['class'])) {
                    if ($html_attrs['class']['type'] === 'expression') {
                        $class_value = "_Component_Init ' . {$html_attrs['class']['value']} . '";
                    } else {
                        $class_value = '_Component_Init ' . $html_attrs['class']['value'];
                    }
                }

                $attrs_string = ' class="' . $class_value . '"';

                foreach ($html_attrs as $key => $attr) {
                    if ($key === 'class') {
                        continue; // Already handled above
                    }

                    if ($attr['type'] === 'expression') {
                        $attrs_string .= ' :' . $key . '="' . htmlspecialchars($attr['value']) . '"';
                    } elseif ($attr['value'] === true) {
                        $attrs_string .= ' ' . $key;
                    } else {
                        $attrs_string .= ' ' . $key . '="' . htmlspecialchars($attr['value']) . '"';
                    }
                }

                // Use {!! !!} not {{ }} because htmlspecialchars is already encoding the value
                $args_output = $json_args === '[]' ? '[]' : "{!! htmlspecialchars({$json_args}, ENT_QUOTES, 'UTF-8') !!}";

                return sprintf(
                    '<%s data-component-init-name="%s" data-component-args="%s"%s>%s</%s>',
                    $wrapper_tag,
                    $component_name,
                    $args_output,
                    $attrs_string,
                    $slot_content,
                    $wrapper_tag
                );
            }

            // Generate the wrapper element for self-closing tags
            // Separate $-prefixed attributes (component args) from regular attributes
            $component_args = [];
            $html_attrs = [];
            $wrapper_tag = self::__get_default_wrapper_tag($component_name);

            foreach ($parsed_attrs as $key => $attr) {
                if (str_starts_with($key, '$')) {
                    // Component arg - remove $ prefix
                    $arg_key = substr($key, 1);
                    $component_args[$arg_key] = $attr;
                } elseif (str_starts_with($key, 'data-')) {
                    // Component arg - remove data- prefix
                    $arg_key = substr($key, 5);
                    $component_args[$arg_key] = $attr;
                } elseif ($key === 'tag') {
                    // Special case: tag attribute becomes _tag component arg AND sets the wrapper element
                    $component_args['_tag'] = $attr;
                    // Use the tag value as the wrapper element (only for string literals, not expressions)
                    if ($attr['type'] === 'string') {
                        $tag_value = $attr['value'];
                        if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $tag_value)) {
                            $wrapper_tag = $tag_value;
                        }
                    }
                } else {
                    // Regular HTML attribute
                    $html_attrs[$key] = $attr;
                }
            }

            // Build component args JSON
            $json_args = self::__to_php_array($component_args);
            if (empty($json_args) || $json_args === '[]') {
                $json_args = '[]';
            } else {
                $json_args = "json_encode({$json_args})";
            }

            // Build HTML attributes string
            // Handle class attribute specially to merge with _Component_Init
            $class_value = '_Component_Init';
            if (isset($html_attrs['class'])) {
                if ($html_attrs['class']['type'] === 'expression') {
                    $class_value = "_Component_Init ' . {$html_attrs['class']['value']} . '";
                } else {
                    $class_value = '_Component_Init ' . $html_attrs['class']['value'];
                }
            }

            $attrs_string = ' class="' . $class_value . '"';

            foreach ($html_attrs as $key => $attr) {
                if ($key === 'class') {
                    continue; // Already handled above
                }

                if ($attr['type'] === 'expression') {
                    $attrs_string .= ' :' . $key . '="' . htmlspecialchars($attr['value']) . '"';
                } elseif ($attr['value'] === true) {
                    $attrs_string .= ' ' . $key;
                } else {
                    $attrs_string .= ' ' . $key . '="' . htmlspecialchars($attr['value']) . '"';
                }
            }

            // Use {!! !!} not {{ }} because htmlspecialchars is already encoding the value
            $args_output = $json_args === '[]' ? '[]' : "{!! htmlspecialchars({$json_args}, ENT_QUOTES, 'UTF-8') !!}";

            return sprintf(
                '<%s data-component-init-name="%s" data-component-args="%s"%s></%s>',
                $wrapper_tag,
                $component_name,
                $args_output,
                $attrs_string,
                $wrapper_tag
            );
        }, $value);

        return $value;
    }

    /**
     * Parse HTML attributes into key-value pairs
     *
     * @param string $attributes HTML attributes string
     * @return array
     */
    private static function __parse_attributes(string $attributes): array
    {
        $parsed = [];

        // Match attribute patterns (including $ prefix for literal variable names)
        preg_match_all('/(\$?[a-zA-Z0-9_\-:]+)(?:=(?:"([^"]*)"|\'([^\']*)\'|([^>\s]+)))?/', $attributes, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = $match[1];

            // Handle different attribute value formats
            if (isset($match[2]) && $match[2] !== '') {
                // Double quoted value
                $value = $match[2];
            } elseif (isset($match[3]) && $match[3] !== '') {
                // Single quoted value
                $value = $match[3];
            } elseif (isset($match[4]) && $match[4] !== '') {
                // Unquoted value
                $value = $match[4];
            } else {
                // Boolean attribute (no value)
                $value = true;
            }

            // Handle Blade expressions (: prefix means it's a PHP expression)
            if (str_starts_with($key, ':')) {
                $key = substr($key, 1);
                // Value is already a PHP expression, keep as is
                $parsed[$key] = ['type' => 'expression', 'value' => $value];
            } else {
                // Check if value contains Blade expressions {{ }} or {!! !!}
                // These need to be extracted and treated as PHP expressions
                if (is_string($value) && preg_match('/^(\{\{|\{!!)\s*(.+?)\s*(\}\}|!!})$/s', $value, $blade_match)) {
                    // Extract the PHP expression from inside the Blade braces
                    $php_expression = $blade_match[2];

                    // For {{ }} (escaped output), wrap in e() helper
                    // For {!! !!} (raw output), use as-is
                    if ($blade_match[1] === '{{') {
                        $php_expression = "e({$php_expression})";
                    }

                    $parsed[$key] = ['type' => 'expression', 'value' => $php_expression];
                } else {
                    // Regular string value
                    $parsed[$key] = ['type' => 'string', 'value' => $value];
                }
            }
        }

        return $parsed;
    }

    /**
     * Convert parsed attributes to PHP array syntax
     *
     * @param array $attrs
     * @return string
     */
    private static function __to_php_array(array $attrs): string
    {
        if (empty($attrs)) {
            return '[]';
        }

        $parts = [];
        foreach ($attrs as $key => $attr) {
            if ($attr['type'] === 'expression') {
                // PHP expression, use as is
                $parts[] = "'{$key}' => {$attr['value']}";
            } elseif ($attr['value'] === true) {
                // Boolean true
                $parts[] = "'{$key}' => true";
            } else {
                // String value, escape it
                $escaped = addslashes($attr['value']);
                $parts[] = "'{$key}' => '{$escaped}'";
            }
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Get the default wrapper tag for a component from its template metadata
     *
     * @param string $component_name Component name
     * @return string Default wrapper tag ('div' if not specified in template)
     */
    private static function __get_default_wrapper_tag(string $component_name): string
    {
        $template_metadata = \App\RSpade\Integrations\Jqhtml\Jqhtml::get_jqhtml_template_by_id($component_name);

        if ($template_metadata !== null && isset($template_metadata['tag_name'])) {
            return $template_metadata['tag_name'];
        }

        return 'div';
    }
}