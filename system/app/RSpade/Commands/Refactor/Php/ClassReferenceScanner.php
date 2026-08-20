<?php

namespace App\RSpade\Commands\Refactor\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\PHP\Php_Parser;
use RuntimeException;

/**
 * Scans PHP and Blade files for class references
 */
class ClassReferenceScanner
{
    /**
     * Find all references to a class in ./rsx and ./app/RSpade PHP and Blade files
     *
     * @param string $class_name Simple class name to find
     * @param string $source_fqcn Fully qualified class name from manifest
     * @return array Map of file paths to array of occurrences
     */
    public function find_all_references(string $class_name, string $source_fqcn): array
    {
        $references = [];

        // Get all files from manifest (automatically excludes resource/, public/, etc.)
        $all_files = Manifest::get_all();

        foreach ($all_files as $relative_path => $file_meta) {
            $extension = $file_meta['extension'] ?? '';
            $absolute_path = base_path($relative_path);

            // Only process PHP and Blade files in rsx/ or app/RSpade/
            if (!str_starts_with($relative_path, 'rsx/') && !str_starts_with($relative_path, 'app/RSpade/')) {
                continue;
            }

            // Process PHP files (not blade)
            if ($extension === 'php') {
                $occurrences = $this->find_in_php_file($absolute_path, $class_name);
                if (!empty($occurrences)) {
                    $references[$absolute_path] = $occurrences;
                }
            }
            // Process Blade files
            elseif ($extension === 'blade.php') {
                $occurrences = $this->find_in_blade_file($absolute_path, $class_name);
                if (!empty($occurrences)) {
                    $references[$absolute_path] = $occurrences;
                }
            }
        }

        return $references;
    }

    /**
     * Find occurrences of a class name in a PHP file using token analysis
     *
     * @param string $file_path Absolute path to PHP file
     * @param string $class_name Simple class name to find
     * @return array Array of occurrence details with line numbers
     */
    protected function find_in_php_file(string $file_path, string $class_name): array
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

            // Check if this is a class name reference
            if ($token_type === T_STRING && $token_value === $class_name) {
                // Determine context
                $context = $this->determine_context($tokens, $i);

                $occurrences[] = [
                    'line' => $line,
                    'context' => $context,
                    'token_value' => $token_value
                ];
            }
        }

        return $occurrences;
    }

    /**
     * Determine the context of a class name token
     */
    protected function determine_context(array $tokens, int $index): string
    {
        // Look backwards for context (increased to 15 for namespaced use statements)
        for ($i = $index - 1; $i >= max(0, $index - 15); $i--) {
            if (!is_array($tokens[$i])) {
                if ($tokens[$i] === '(') {
                    return 'function_call_or_instantiation';
                }
                continue;
            }

            $prev_token = $tokens[$i][0];

            if ($prev_token === T_NEW) {
                return 'new';
            }
            if ($prev_token === T_EXTENDS) {
                return 'extends';
            }
            if ($prev_token === T_IMPLEMENTS) {
                return 'implements';
            }
            if ($prev_token === T_INSTANCEOF) {
                return 'instanceof';
            }
            if ($prev_token === T_USE) {
                return 'use_statement';
            }
            if ($prev_token === T_CLASS) {
                return 'class_declaration';
            }
            if ($prev_token === T_DOUBLE_COLON) {
                return 'static_access';
            }

            // Skip whitespace and namespace separators
            if (in_array($prev_token, [T_WHITESPACE, T_NS_SEPARATOR, T_STRING])) {
                continue;
            }

            // Hit something else, stop
            break;
        }

        // Look forwards for context
        for ($i = $index + 1; $i < min(count($tokens), $index + 5); $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            $next_token = $tokens[$i][0];

            if ($next_token === T_DOUBLE_COLON) {
                return 'static_access';
            }
        }

        return 'unknown';
    }

    /**
     * Find occurrences of a class name in a Blade file
     *
     * @param string $file_path Absolute path to Blade file
     * @param string $class_name Simple class name to find
     * @return array Array of occurrence details
     */
    protected function find_in_blade_file(string $file_path, string $class_name): array
    {
        $content = file_get_contents($file_path);
        $occurrences = [];

        // Extract PHP code from Blade directives
        $php_segments = $this->extract_php_from_blade($content);

        foreach ($php_segments as $segment) {
            $segment_occurrences = $this->find_in_php_code($segment['code'], $class_name);

            foreach ($segment_occurrences as $occurrence) {
                $occurrences[] = [
                    'line' => $segment['line'] + $occurrence['line'] - 1,
                    'context' => $occurrence['context'],
                    'blade_directive' => $segment['type']
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

                    // Skip Blade comments
                    if ($directive === 'rsx_id' || $directive === 'section' || $directive === 'yield') {
                        continue;
                    }

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

                // Collect lines until @endphp
                for ($j = $i; $j < count($lines); $j++) {
                    $php_block .= $lines[$j] . "\n";
                    if (preg_match('/@endphp/', $lines[$j])) {
                        break;
                    }
                }

                // Extract PHP code between @php and @endphp
                $php_code = preg_replace('/@php(.+?)@endphp/s', '$1', $php_block);

                $segments[] = [
                    'code' => "<?php {$php_code} ?>",
                    'line' => $start_line,
                    'type' => '@php_block'
                ];

                $i = $j; // Skip past the block
            }
        }

        return $segments;
    }

    /**
     * Find class references in a PHP code string
     */
    protected function find_in_php_code(string $php_code, string $class_name): array
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

            if ($token_type === T_STRING && $token_value === $class_name) {
                $context = $this->determine_context($tokens, $i);

                $occurrences[] = [
                    'line' => $line,
                    'context' => $context
                ];
            }
        }

        return $occurrences;
    }
}
