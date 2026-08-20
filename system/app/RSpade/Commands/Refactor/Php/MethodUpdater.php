<?php

namespace App\RSpade\Commands\Refactor\Php;

use RuntimeException;

/**
 * Updates method references in PHP and Blade files
 */
class MethodUpdater
{
    /**
     * Rename a method definition in a class file
     *
     * @param string $file_path Absolute path to class file
     * @param string $old_method Old method name
     * @param string $new_method New method name
     * @return bool True if method was found and renamed
     */
    public function rename_method_definition(string $file_path, string $old_method, string $new_method): bool
    {
        $content = file_get_contents($file_path);
        $tokens = token_get_all($content);
        $output = '';
        $method_found = false;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                $output .= $token;
                continue;
            }

            $token_type = $token[0];
            $token_value = $token[1];

            // Look for function declarations
            if ($token_type === T_FUNCTION) {
                // Find the method name (next T_STRING token)
                $method_name_index = null;
                for ($j = $i + 1; $j < min(count($tokens), $i + 10); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $method_name_index = $j;
                        break;
                    }
                }

                if ($method_name_index && $tokens[$method_name_index][1] === $old_method) {
                    // Verify this is a static method by looking backwards
                    $is_static = false;
                    for ($k = $i - 1; $k >= max(0, $i - 20); $k--) {
                        if (!is_array($tokens[$k])) continue;
                        if ($tokens[$k][0] === T_STATIC) {
                            $is_static = true;
                            break;
                        }
                    }

                    if ($is_static) {
                        $method_found = true;
                        // Output everything up to but not including the method name
                        $output .= $token_value;

                        // Skip to method name and replace it
                        for ($j = $i + 1; $j < $method_name_index; $j++) {
                            $output .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                        }

                        // Output new method name
                        $output .= $new_method;

                        // Skip past the old method name
                        $i = $method_name_index;
                        continue;
                    }
                }
            }

            $output .= $token_value;
        }

        if ($method_found) {
            $this->write_file_atomically($file_path, $output);
        }

        return $method_found;
    }

    /**
     * Update static::/self:: references in a class file
     *
     * @param string $file_path Absolute path to class file
     * @param string $old_method Old method name
     * @param string $new_method New method name
     * @return int Number of replacements made
     */
    public function update_static_self_references(string $file_path, string $old_method, string $new_method): int
    {
        $content = file_get_contents($file_path);
        $tokens = token_get_all($content);
        $output = '';
        $replacement_count = 0;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                $output .= $token;
                continue;
            }

            $token_type = $token[0];
            $token_value = $token[1];

            // Look for static:: or self::
            $is_static_or_self = ($token_type === T_STATIC) ||
                                ($token_type === T_STRING && $token_value === 'self');

            if ($is_static_or_self) {
                // Check for :: followed by method name
                $double_colon_index = null;
                $method_name_index = null;

                // Find :: - can be either T_DOUBLE_COLON token or string '::'
                for ($j = $i + 1; $j < min(count($tokens), $i + 5); $j++) {
                    $is_double_colon_token = false;
                    if ($tokens[$j] === '::') {
                        $is_double_colon_token = true;
                    } elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                        $is_double_colon_token = true;
                    }

                    if ($is_double_colon_token) {
                        $double_colon_index = $j;
                        break;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }

                if ($double_colon_index) {
                    // Find method name after ::
                    for ($j = $double_colon_index + 1; $j < min(count($tokens), $double_colon_index + 5); $j++) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                            if ($tokens[$j][1] === $old_method) {
                                $method_name_index = $j;
                            }
                            break;
                        }
                        if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                            break;
                        }
                    }
                }

                if ($method_name_index) {
                    // Output static/self
                    $output .= $token_value;

                    // Output everything between static/self and method name (whitespace, ::)
                    for ($j = $i + 1; $j < $method_name_index; $j++) {
                        $output .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                    }

                    // Output new method name
                    $output .= $new_method;

                    // Skip to after method name
                    $i = $method_name_index;
                    $replacement_count++;
                    continue;
                }
            }

            $output .= $token_value;
        }

        if ($replacement_count > 0) {
            $this->write_file_atomically($file_path, $output);
        }

        return $replacement_count;
    }

    /**
     * Update Class::method references across all files
     *
     * @param array $references Map of file paths to occurrences from MethodReferenceScanner
     * @param string $class_name Class name
     * @param string $class_fqcn Fully qualified class name
     * @param string $old_method Old method name
     * @param string $new_method New method name
     * @return int Number of files updated
     */
    public function update_method_references(array $references, string $class_name, string $class_fqcn, string $old_method, string $new_method): int
    {
        $updated_count = 0;

        foreach ($references as $file_path => $occurrences) {
            if ($this->update_file_method_references($file_path, $class_name, $class_fqcn, $old_method, $new_method)) {
                $updated_count++;
            }
        }

        return $updated_count;
    }

    /**
     * Update method references in a single file
     */
    protected function update_file_method_references(string $file_path, string $class_name, string $class_fqcn, string $old_method, string $new_method): bool
    {
        $content = file_get_contents($file_path);

        // Determine if this is a Blade file
        if (str_ends_with($file_path, '.blade.php')) {
            $updated_content = $this->replace_method_in_blade($content, $class_name, $class_fqcn, $old_method, $new_method);
        } else {
            $updated_content = $this->replace_method_in_php($content, $class_name, $class_fqcn, $old_method, $new_method);
        }

        // Check if any changes were made
        if ($updated_content === $content) {
            return false;
        }

        $this->write_file_atomically($file_path, $updated_content);
        return true;
    }

    /**
     * Replace method references in PHP content
     */
    protected function replace_method_in_php(string $content, string $class_name, string $class_fqcn, string $old_method, string $new_method): string
    {
        $tokens = token_get_all($content);
        $output = '';

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                $output .= $token;
                continue;
            }

            $token_type = $token[0];
            $token_value = $token[1];

            // Look for simple class name (T_STRING) or FQCN (T_NAME_FULLY_QUALIFIED) followed by :: and method name
            $is_match = false;
            if ($token_type === T_STRING && $token_value === $class_name) {
                $is_match = true;
            } elseif (defined('T_NAME_FULLY_QUALIFIED') && $token_type === T_NAME_FULLY_QUALIFIED) {
                // Strip leading backslash for comparison
                $normalized_token = ltrim($token_value, '\\');
                $normalized_fqcn = ltrim($class_fqcn, '\\');
                if ($normalized_token === $normalized_fqcn) {
                    $is_match = true;
                }
            }

            if ($is_match) {
                $double_colon_index = null;
                $method_name_index = null;

                // Find :: - can be either T_DOUBLE_COLON token or string '::'
                for ($j = $i + 1; $j < min(count($tokens), $i + 5); $j++) {
                    $is_double_colon_token = false;
                    if ($tokens[$j] === '::') {
                        $is_double_colon_token = true;
                    } elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                        $is_double_colon_token = true;
                    }

                    if ($is_double_colon_token) {
                        $double_colon_index = $j;
                        break;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }

                if ($double_colon_index) {
                    // Find method name after ::
                    for ($j = $double_colon_index + 1; $j < min(count($tokens), $double_colon_index + 5); $j++) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                            if ($tokens[$j][1] === $old_method) {
                                $method_name_index = $j;
                            }
                            break;
                        }
                        if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                            break;
                        }
                    }
                }

                if ($method_name_index) {
                    // Output class name (simple or FQCN)
                    $output .= $token_value;

                    // Output everything between class and method name (whitespace, ::)
                    for ($j = $i + 1; $j < $method_name_index; $j++) {
                        $output .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                    }

                    // Output new method name
                    $output .= $new_method;

                    // Skip to after method name
                    $i = $method_name_index;
                    continue;
                }
            }

            $output .= $token_value;
        }

        return $output;
    }

    /**
     * Replace method references in Blade content
     */
    protected function replace_method_in_blade(string $content, string $class_name, string $class_fqcn, string $old_method, string $new_method): string
    {
        // Pattern to match simple Class::method with word boundaries
        $pattern1 = '/\b' . preg_quote($class_name, '/') . '\s*::\s*' . preg_quote($old_method, '/') . '\b/';
        $content = preg_replace($pattern1, $class_name . '::' . $new_method, $content);

        // Pattern to match FQCN \Namespace\Class::method
        $escaped_fqcn = preg_quote($class_fqcn, '/');
        $pattern2 = '/\\\\?' . $escaped_fqcn . '\s*::\s*' . preg_quote($old_method, '/') . '\b/';
        $content = preg_replace($pattern2, '\\' . $class_fqcn . '::' . $new_method, $content);

        return $content;
    }

    /**
     * Update controller action references in Route() calls and Ajax endpoints
     * Only processes files in rsx/ directory that are in the manifest
     *
     * @param string $class_name Controller class name
     * @param string $old_action Old action/method name
     * @param string $new_action New action/method name
     * @return int Number of files updated
     */
    public function update_controller_action_route_references(string $class_name, string $old_action, string $new_action): int
    {
        $updated_count = 0;

        // Get all files from manifest in rsx/ directory
        $manifest = \App\RSpade\Core\Manifest\Manifest::get_all();

        foreach ($manifest as $relative_path => $metadata) {
            // Only process files in rsx/ directory
            if (!str_starts_with($relative_path, 'rsx/')) {
                continue;
            }

            $extension = $metadata['extension'] ?? '';
            $file_path = base_path($relative_path);

            if (!file_exists($file_path)) {
                continue;
            }

            $content = file_get_contents($file_path);
            $updated_content = $content;

            // Apply replacements based on file type
            if ($extension === 'js' || $extension === 'jqhtml') {
                // Replace Rsx.Route('CLASS', 'OLD_ACTION'
                // Pattern matches with optional whitespace between parameters
                $updated_content = preg_replace(
                    '/\bRsx\.Route\(\s*[\'"]' . preg_quote($class_name, '/') . '[\'"]\s*,\s*[\'"]' . preg_quote($old_action, '/') . '[\'"]/',
                    'Rsx.Route(\'' . $class_name . '\', \'' . $new_action . '\'',
                    $updated_content
                );
            }

            if ($extension === 'jqhtml' || $extension === 'blade.php') {
                // Replace $action="OLD_ACTION" when $controller="CLASS_NAME" is on same line
                // This uses a callback to check both attributes are present
                $updated_content = preg_replace_callback(
                    '/^.*$/m',
                    function ($matches) use ($class_name, $old_action, $new_action) {
                        $line = $matches[0];

                        // Check if line has both $controller="CLASS_NAME" and $action="OLD_ACTION"
                        $has_controller = preg_match('/\$controller=["\']' . preg_quote($class_name, '/') . '["\']/', $line);
                        $has_action = preg_match('/\$action=["\']' . preg_quote($old_action, '/') . '["\']/', $line);

                        if ($has_controller && $has_action) {
                            // Replace the action
                            return preg_replace(
                                '/(\$action=["\'])' . preg_quote($old_action, '/') . '(["\'])/',
                                '$1' . $new_action . '$2',
                                $line
                            );
                        }

                        return $line;
                    },
                    $updated_content
                );
            }

            if ($extension === 'js') {
                // Replace Ajax endpoint calls: CLASS.OLD_ACTION(
                // Pattern: (non-alnum)(CLASS).(METHOD)(non-alnum)
                $updated_content = preg_replace(
                    '/(?<=[^a-zA-Z0-9_])' . preg_quote($class_name, '/') . '\.' . preg_quote($old_action, '/') . '(?=[^a-zA-Z0-9_])/',
                    $class_name . '.' . $new_action,
                    $updated_content
                );
            }

            if ($extension === 'php' || $extension === 'blade.php') {
                // Replace Rsx::Route('CLASS', 'OLD_ACTION'
                // Pattern matches with optional whitespace between parameters
                $updated_content = preg_replace(
                    '/\bRsx::Route\(\s*[\'"]' . preg_quote($class_name, '/') . '[\'"]\s*,\s*[\'"]' . preg_quote($old_action, '/') . '[\'"]/',
                    'Rsx::Route(\'' . $class_name . '\', \'' . $new_action . '\'',
                    $updated_content
                );
            }

            // Write if changed
            if ($updated_content !== $content) {
                file_put_contents_safe($file_path, $updated_content);
                $updated_count++;
            }
        }

        return $updated_count;
    }

    /**
     * Write file atomically using temp file
     */
    protected function write_file_atomically(string $file_path, string $content): void
    {
        $temp_file = $file_path . '.refactor-temp';

        if (file_put_contents_safe($temp_file, $content) === false) {
            throw new RuntimeException("Failed to write temp file: {$temp_file}");
        }

        if (!rename($temp_file, $file_path)) {
            @unlink($temp_file);
            throw new RuntimeException("Failed to replace file: {$file_path}");
        }
    }
}
