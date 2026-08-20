<?php

namespace App\RSpade\Commands\Refactor\Php;

use App\RSpade\Core\Manifest\Manifest;
use RuntimeException;

/**
 * Scans PHP and Blade files for static method references
 */
class MethodReferenceScanner
{
    /**
     * Find all references to a static method in ./rsx and ./app/RSpade PHP and Blade files
     *
     * @param string $class_name Simple class name
     * @param string $class_fqcn Fully qualified class name
     * @param string $method_name Method name to find
     * @return array Map of file paths to array of occurrences
     */
    public function find_all_method_references(string $class_name, string $class_fqcn, string $method_name): array
    {
        $references = [];

        // Get all files from manifest
        $all_files = Manifest::get_all();

        foreach ($all_files as $relative_path => $file_meta) {
            $extension = $file_meta['extension'] ?? '';
            $absolute_path = base_path($relative_path);

            // Only process PHP and Blade files in rsx/ or app/RSpade/
            if (!str_starts_with($relative_path, 'rsx/') && !str_starts_with($relative_path, 'app/RSpade/')) {
                continue;
            }

            // Process PHP files
            if ($extension === 'php') {
                $occurrences = $this->find_in_php_file($absolute_path, $class_name, $class_fqcn, $method_name);
                if (!empty($occurrences)) {
                    $references[$absolute_path] = $occurrences;
                }
            }
            // Process Blade files
            elseif ($extension === 'blade.php') {
                $occurrences = $this->find_in_blade_file($absolute_path, $class_name, $class_fqcn, $method_name);
                if (!empty($occurrences)) {
                    $references[$absolute_path] = $occurrences;
                }
            }
        }

        return $references;
    }

    /**
     * Find static::/self:: references to a method within a specific file
     *
     * @param string $file_path Absolute path to PHP file
     * @param string $method_name Method name to find
     * @return array Array of occurrence details
     */
    public function find_static_self_references(string $file_path, string $method_name): array
    {
        $content = file_get_contents($file_path);
        $tokens = token_get_all($content);
        $occurrences = [];

        for ($i = 0; $i < count($tokens); $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            $token = $tokens[$i];
            $token_type = $token[0];
            $token_value = $token[1];
            $line = $token[2];

            // Look for static:: or self::
            $is_static_or_self = ($token_type === T_STATIC) ||
                                ($token_type === T_STRING && $token_value === 'self');

            if (!$is_static_or_self) {
                continue;
            }

            // Check for :: followed by method name
            $next_non_whitespace = $this->get_next_non_whitespace_token($tokens, $i);

            if ($next_non_whitespace && $next_non_whitespace['token'] === '::') {
                $method_token = $this->get_next_non_whitespace_token($tokens, $next_non_whitespace['index']);

                if ($method_token &&
                    is_array($method_token['token']) &&
                    $method_token['token'][0] === T_STRING &&
                    $method_token['token'][1] === $method_name) {

                    $occurrences[] = [
                        'line' => $line,
                        'context' => $token_value === 'self' ? 'self::' : 'static::',
                        'method' => $method_name
                    ];
                }
            }
        }

        return $occurrences;
    }

    /**
     * Find method references in a PHP file using token analysis
     */
    protected function find_in_php_file(string $file_path, string $class_name, string $class_fqcn, string $method_name): array
    {
        $content = file_get_contents($file_path);
        $tokens = token_get_all($content);
        $occurrences = [];

        for ($i = 0; $i < count($tokens); $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            $token = $tokens[$i];
            $token_type = $token[0];
            $token_value = $token[1];
            $line = $token[2];

            // Look for simple class name (T_STRING) or FQCN (T_NAME_FULLY_QUALIFIED) followed by ::
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
                $next_non_whitespace = $this->get_next_non_whitespace_token($tokens, $i);

                // Check for :: - can be either T_DOUBLE_COLON token or string '::'
                $is_double_colon = false;
                if ($next_non_whitespace) {
                    $next_token = $next_non_whitespace['token'];
                    if ($next_token === '::') {
                        $is_double_colon = true;
                    } elseif (is_array($next_token) && $next_token[0] === T_DOUBLE_COLON) {
                        $is_double_colon = true;
                    }
                }

                if ($is_double_colon) {
                    $method_token = $this->get_next_non_whitespace_token($tokens, $next_non_whitespace['index']);

                    if ($method_token &&
                        is_array($method_token['token']) &&
                        $method_token['token'][0] === T_STRING &&
                        $method_token['token'][1] === $method_name) {

                        $occurrences[] = [
                            'line' => $line,
                            'context' => 'static_call',
                            'class' => $class_name,
                            'method' => $method_name
                        ];
                    }
                }
            }
        }

        return $occurrences;
    }

    /**
     * Find method references in a Blade file
     */
    protected function find_in_blade_file(string $file_path, string $class_name, string $class_fqcn, string $method_name): array
    {
        $content = file_get_contents($file_path);
        $occurrences = [];

        // Extract PHP code from Blade directives
        $php_segments = $this->extract_php_from_blade($content);

        foreach ($php_segments as $segment) {
            $segment_occurrences = $this->find_in_php_code($segment['code'], $class_name, $class_fqcn, $method_name);

            foreach ($segment_occurrences as $occurrence) {
                $occurrences[] = [
                    'line' => $segment['line'] + $occurrence['line'] - 1,
                    'context' => $occurrence['context'],
                    'blade_directive' => $segment['type'],
                    'class' => $class_name,
                    'method' => $method_name
                ];
            }
        }

        return $occurrences;
    }

    /**
     * Extract PHP code segments from Blade content
     */
    protected function extract_php_from_blade(string $content): array
    {
        $segments = [];
        $lines = explode("\n", $content);

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $line_num = $i + 1;

            // Match {{ }} and {!! !!} expressions
            if (preg_match_all('/\{\{(.+?)\}\}|\{!!(.+?)!!\}/s', $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $code = trim($match[0], '{}! ');
                    $segments[] = [
                        'code' => "<?php {$code} ?>",
                        'line' => $line_num,
                        'type' => 'echo'
                    ];
                }
            }

            // Match @directive() calls
            if (preg_match_all('/@(\w+)\s*\((.+?)\)/s', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $directive = $match[1];
                    $args = $match[2];

                    $segments[] = [
                        'code' => "<?php {$args} ?>",
                        'line' => $line_num,
                        'type' => "@{$directive}"
                    ];
                }
            }

            // Match @php...@endphp blocks
            if (preg_match('/@php/', $line)) {
                $php_block = '';
                $start_line = $line_num;

                for ($j = $i; $j < count($lines); $j++) {
                    $php_block .= $lines[$j] . "\n";
                    if (preg_match('/@endphp/', $lines[$j])) {
                        break;
                    }
                }

                $php_code = preg_replace('/@php(.+?)@endphp/s', '$1', $php_block);

                $segments[] = [
                    'code' => "<?php {$php_code} ?>",
                    'line' => $start_line,
                    'type' => '@php_block'
                ];

                $i = $j;
            }
        }

        return $segments;
    }

    /**
     * Find method references in a PHP code string
     */
    protected function find_in_php_code(string $php_code, string $class_name, string $class_fqcn, string $method_name): array
    {
        $tokens = @token_get_all($php_code);
        if ($tokens === false) {
            return [];
        }

        $occurrences = [];

        for ($i = 0; $i < count($tokens); $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            $token = $tokens[$i];
            $token_type = $token[0];
            $token_value = $token[1];
            $line = $token[2] ?? 1;

            // Look for simple class name (T_STRING) or FQCN (T_NAME_FULLY_QUALIFIED) followed by ::
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
                $next_non_whitespace = $this->get_next_non_whitespace_token($tokens, $i);

                // Check for :: - can be either T_DOUBLE_COLON token or string '::'
                $is_double_colon = false;
                if ($next_non_whitespace) {
                    $next_token = $next_non_whitespace['token'];
                    if ($next_token === '::') {
                        $is_double_colon = true;
                    } elseif (is_array($next_token) && $next_token[0] === T_DOUBLE_COLON) {
                        $is_double_colon = true;
                    }
                }

                if ($is_double_colon) {
                    $method_token = $this->get_next_non_whitespace_token($tokens, $next_non_whitespace['index']);

                    if ($method_token &&
                        is_array($method_token['token']) &&
                        $method_token['token'][0] === T_STRING &&
                        $method_token['token'][1] === $method_name) {

                        $occurrences[] = [
                            'line' => $line,
                            'context' => 'static_call'
                        ];
                    }
                }
            }
        }

        return $occurrences;
    }

    /**
     * Get next non-whitespace token
     */
    protected function get_next_non_whitespace_token(array $tokens, int $start_index): ?array
    {
        for ($i = $start_index + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            // Skip whitespace tokens
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return ['token' => $token, 'index' => $i];
        }

        return null;
    }
}
