<?php

namespace App\RSpade\Commands\Refactor\Php;

use RuntimeException;

/**
 * Updates class references in PHP and Blade files with atomic writes
 */
class FileUpdater
{
    /**
     * Update class references in all affected files
     *
     * @param array $references Map of file paths to occurrences
     * @param string $old_class Old class name
     * @param string $new_class New class name
     * @param string $source_fqcn Source class FQCN from manifest
     * @return int Number of files updated
     */
    public function update_class_references(array $references, string $old_class, string $new_class, string $source_fqcn): int
    {
        $updated_count = 0;

        foreach ($references as $file_path => $occurrences) {
            if ($this->update_file($file_path, $old_class, $new_class, $source_fqcn)) {
                $updated_count++;
            }
        }

        return $updated_count;
    }

    /**
     * Update a single file with class name replacements
     *
     * @param string $file_path Absolute path to file
     * @param string $old_class Old class name
     * @param string $new_class New class name
     * @param string $source_fqcn Source class FQCN from manifest
     * @return bool True if file was updated
     */
    protected function update_file(string $file_path, string $old_class, string $new_class, string $source_fqcn): bool
    {
        $content = file_get_contents($file_path);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$file_path}");
        }

        // Replace class name with context awareness
        $updated_content = $this->replace_class_name($content, $old_class, $new_class, $source_fqcn);

        // Check if any changes were made
        if ($updated_content === $content) {
            return false;
        }

        // Write atomically using temp file
        $temp_file = $file_path . '.refactor-temp';

        if (file_put_contents_safe($temp_file, $updated_content) === false) {
            throw new RuntimeException("Failed to write temp file: {$temp_file}");
        }

        if (!rename($temp_file, $file_path)) {
            @unlink($temp_file);
            throw new RuntimeException("Failed to replace file: {$file_path}");
        }

        return true;
    }

    /**
     * Replace class name in content with context awareness
     *
     * Uses token-based replacement to avoid false positives in strings/comments
     */
    protected function replace_class_name(string $content, string $old_class, string $new_class, string $source_fqcn): string
    {
        // For Blade files, we need special handling
        if (str_contains($content, '@') || str_contains($content, '{{')) {
            return $this->replace_in_blade($content, $old_class, $new_class, $source_fqcn);
        }

        // For pure PHP files, use token-based replacement
        return $this->replace_in_php($content, $old_class, $new_class, $source_fqcn);
    }

    /**
     * Replace class name in PHP content using token analysis
     */
    protected function replace_in_php(string $content, string $old_class, string $new_class, string $source_fqcn): string
    {
        $tokens = token_get_all($content);
        $output = '';

        // Calculate new FQCN for string literal replacement
        $source_fqcn_normalized = ltrim($source_fqcn, '\\');
        $new_fqcn = preg_replace('/' . preg_quote($old_class, '/') . '$/', $new_class, $source_fqcn_normalized);

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            // String tokens are passed through as-is
            if (!is_array($token)) {
                $output .= $token;
                continue;
            }

            $token_type = $token[0];
            $token_value = $token[1];

            // Replace FQCN in string literals
            if ($token_type === T_CONSTANT_ENCAPSED_STRING) {
                $quote = $token_value[0];
                $string_content = substr($token_value, 1, -1);

                // Check if string exactly equals source FQCN (with or without leading \)
                if ($string_content === $source_fqcn_normalized || $string_content === '\\' . $source_fqcn_normalized) {
                    $leading_slash = str_starts_with($string_content, '\\') ? '\\' : '';
                    $output .= $quote . $leading_slash . $new_fqcn . $quote;
                    continue;
                }
            }

            // Check if this is start of a FQCN
            if ($token_type === T_STRING || $token_type === T_NS_SEPARATOR) {
                $fqcn_result = $this->check_and_replace_fqcn($tokens, $i, $old_class, $new_class);

                if ($fqcn_result !== null) {
                    // We found and replaced a FQCN, output the replacement and skip ahead
                    $output .= $fqcn_result['replacement'];
                    $i = $fqcn_result['end_index'];
                    continue;
                }
            }

            // Replace simple T_STRING tokens that match the old class name
            if ($token_type === T_STRING && $token_value === $old_class) {
                // Verify this is actually a class reference (not in string/comment)
                if ($this->is_class_reference_context($tokens, $i)) {
                    $output .= $new_class;
                } else {
                    $output .= $token_value;
                }
            } else {
                $output .= $token_value;
            }
        }

        return $output;
    }

    /**
     * Check if we're at the start of a FQCN and if it should be replaced
     *
     * @return array|null Returns ['replacement' => string, 'end_index' => int] or null
     */
    protected function check_and_replace_fqcn(array $tokens, int $start_index, string $old_class, string $new_class): ?array
    {
        // Build the full FQCN from consecutive T_STRING and T_NS_SEPARATOR tokens
        $fqcn_parts = [];
        $i = $start_index;

        while ($i < count($tokens)) {
            if (!is_array($tokens[$i])) {
                break;
            }

            $token_type = $tokens[$i][0];

            if ($token_type === T_STRING) {
                $fqcn_parts[] = $tokens[$i][1];
                $i++;
            } elseif ($token_type === T_NS_SEPARATOR) {
                $fqcn_parts[] = '\\';
                $i++;
            } elseif ($token_type === T_WHITESPACE) {
                // Skip whitespace
                $i++;
            } else {
                break;
            }
        }

        // Need at least 2 parts for a FQCN (namespace + class)
        if (count($fqcn_parts) < 3) {
            return null;
        }

        $fqcn = implode('', $fqcn_parts);

        // Extract class name (last part after final \)
        $class_name = basename(str_replace('\\', '/', $fqcn));

        // Check if class name matches
        if ($class_name !== $old_class) {
            return null;
        }

        // Check if FQCN starts with Rsx\ or App\RSpade\
        $normalized_fqcn = ltrim($fqcn, '\\');

        if (!str_starts_with($normalized_fqcn, 'Rsx\\') && !str_starts_with($normalized_fqcn, 'App\\RSpade\\')) {
            return null;
        }

        // Build replacement FQCN with new class name
        $namespace = dirname(str_replace('\\', '/', $fqcn));
        $namespace = str_replace('/', '\\', $namespace);

        if ($namespace === '.') {
            $replacement = $new_class;
        } else {
            // Preserve leading \ if original had it
            $leading_slash = str_starts_with($fqcn, '\\') ? '\\' : '';
            $replacement = $leading_slash . $namespace . '\\' . $new_class;
        }

        return [
            'replacement' => $replacement,
            'end_index' => $i - 1
        ];
    }

    /**
     * Check if a token is in a valid class reference context
     */
    protected function is_class_reference_context(array $tokens, int $index): bool
    {
        // Look backwards for context clues
        for ($i = $index - 1; $i >= max(0, $index - 10); $i--) {
            if (!is_array($tokens[$i])) {
                // Skip non-token characters like ( ) , etc
                continue;
            }

            $prev_token = $tokens[$i][0];

            // Valid contexts
            if (in_array($prev_token, [T_CLASS, T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF, T_USE, T_DOUBLE_COLON])) {
                return true;
            }

            // Skip whitespace, namespace separators, and comments
            if (in_array($prev_token, [T_WHITESPACE, T_NS_SEPARATOR, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            // For type hints, check if we're after ( or ,
            if ($prev_token === T_STRING) {
                // Could be part of a namespace
                continue;
            }

            // If we hit something else meaningful, stop looking
            if (!in_array($prev_token, [T_WHITESPACE, T_NS_SEPARATOR, T_STRING])) {
                break;
            }
        }

        // Look forwards for static access
        for ($i = $index + 1; $i < min(count($tokens), $index + 5); $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            $next_token = $tokens[$i][0];

            if ($next_token === T_DOUBLE_COLON) {
                return true;
            }

            if (in_array($next_token, [T_WHITESPACE, T_NS_SEPARATOR])) {
                continue;
            }

            break;
        }

        // Check if this appears to be a type hint (before a variable)
        for ($i = $index + 1; $i < min(count($tokens), $index + 5); $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            $next_token = $tokens[$i][0];

            // Type hint if followed by a variable
            if ($next_token === T_VARIABLE) {
                return true;
            }

            if ($next_token === T_WHITESPACE) {
                continue;
            }

            break;
        }

        return false;
    }

    /**
     * Replace class name in Blade content
     *
     * Uses simple regex replacement since Blade mixes PHP and HTML
     */
    protected function replace_in_blade(string $content, string $old_class, string $new_class, string $source_fqcn): string
    {
        // Pattern matches class name as a word boundary (not part of another identifier)
        // This prevents replacing "User" in "UserController" or in the middle of strings
        $pattern = '/\b' . preg_quote($old_class, '/') . '\b/';

        // Replace with word boundary check to avoid partial matches
        $updated = preg_replace_callback($pattern, function ($matches) use ($old_class, $new_class, $content) {
            // Additional safety: check if this looks like it's in a string literal
            // This is a simple heuristic - if surrounded by quotes, skip it
            $pos = strpos($content, $matches[0]);
            if ($pos !== false) {
                // Check 50 chars before and after for quote context
                $before = substr($content, max(0, $pos - 50), 50);
                $after = substr($content, $pos, 50);

                // Count quotes before and after
                $quotes_before = substr_count($before, '"') + substr_count($before, "'");
                $quotes_after = substr_count($after, '"') + substr_count($after, "'");

                // If odd number of quotes before/after, likely inside a string
                if ($quotes_before % 2 === 1 || $quotes_after % 2 === 1) {
                    return $matches[0]; // Keep original
                }
            }

            return $new_class;
        }, $content);

        return $updated;
    }

    /**
     * Update controller references in Route() calls and Ajax endpoints
     * Only processes files in rsx/ directory that are in the manifest
     *
     * @param string $old_class Old controller class name
     * @param string $new_class New controller class name
     * @return int Number of files updated
     */
    public function update_controller_route_references(string $old_class, string $new_class): int
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
                // Replace Rsx.Route('OLD_CLASS' and Rsx.Route("OLD_CLASS"
                $updated_content = preg_replace(
                    '/\bRsx\.Route\([\'"]' . preg_quote($old_class, '/') . '[\'"]/',
                    'Rsx.Route(\'' . $new_class . '\'',
                    $updated_content
                );
            }

            if ($extension === 'jqhtml' || $extension === 'blade.php') {
                // Replace $controller="OLD_CLASS"
                $updated_content = preg_replace(
                    '/(\s\$controller=["\'])' . preg_quote($old_class, '/') . '(["\'])/',
                    '$1' . $new_class . '$2',
                    $updated_content
                );
            }

            if ($extension === 'jqhtml') {
                // Replace unquoted attribute assignments: $attr=OLD_CLASS followed by space, dot, or >
                // Pattern: (attribute name)=(controller name)(space|dot|>)
                $updated_content = preg_replace(
                    '/(\$[\w_]+)=' . preg_quote($old_class, '/') . '\b(?=[\s.>])/',
                    '$1=' . $new_class,
                    $updated_content
                );
            }

            if ($extension === 'js') {
                // Replace Ajax endpoint calls: OLD_CLASS.method(
                // Pattern: (whitespace|;|(|[)OLD_CLASS.
                $updated_content = preg_replace(
                    '/(?<=[\s;(\[])' . preg_quote($old_class, '/') . '\b(?=\.)/',
                    $new_class,
                    $updated_content
                );
            }

            if ($extension === 'php' || $extension === 'blade.php') {
                // Replace Rsx::Route('OLD_CLASS' and Rsx::Route("OLD_CLASS"
                $updated_content = preg_replace(
                    '/\bRsx::Route\([\'"]' . preg_quote($old_class, '/') . '[\'"]/',
                    'Rsx::Route(\'' . $new_class . '\'',
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
}
