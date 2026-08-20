<?php

namespace App\RSpade\Commands\Refactor;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Reorganizes methods in PHP class files according to RSpade conventions
 *
 * This command reorders methods in PHP class files following a standardized structure:
 *
 * ORDERING LOGIC:
 * 1. Public static methods (no underscore)
 * 2. Public non-static methods (no underscore)
 * 3. [Conditional - only app/RSpade/ files] Public methods with single underscore
 *    - Prefixed with "RSpade Public Internal Methods" section header
 * 4. Public methods with 2+ underscores
 * 5. [Conditional - if any exist] Protected/Private methods section
 *    - Prefixed with "Protected / Private Methods" section header
 *    - Ordered by: static (no underscore), non-static (no underscore),
 *                  static (single underscore), non-static (single underscore),
 *                  remaining static, remaining non-static
 *
 * SECTION HEADERS:
 * - "RSpade Public Internal Methods" section only appears for app/RSpade/ files
 *   containing public methods with single underscore prefix
 * - "Protected / Private Methods" section only appears if protected/private methods exist
 * - Existing section headers are removed and conditionally re-added
 *
 * PRESERVATION:
 * - Class properties, constants, and non-method comments remain in place
 * - Method ordering within groups preserved unless --sort-alpha specified
 * - Attributes, PHPDoc, and inline comments stay with their methods
 *
 * PROCESSING:
 * 1. Validates file is in manifest and contains a PHP class
 * 2. Extracts all methods with full headers (attributes, PHPDoc, comments)
 * 3. Removes methods from file
 * 4. Runs PHP formatter on stripped file
 * 5. Finds last closing brace (class end)
 * 6. Re-inserts methods in specified order with section headers
 * 7. Runs PHP formatter again
 * 8. Writes file atomically
 *
 * FLAGS:
 * --sort-alpha: Alphabetically sort methods within each group
 */
class SortFunctions_Command extends Command
{
    protected $signature = 'rsx:refactor:sort_php_class_functions
                            {file : Path to PHP class file (relative to base_path)}
                            {--sort-alpha : Sort methods alphabetically within groups}';

    protected $description = 'Reorganize methods in PHP class files according to RSpade conventions';

    public function handle()
    {
        $file_path = $this->argument('file');
        $sort_alpha = $this->option('sort-alpha');

        // Normalize path
        $file_path = str_replace('\\', '/', $file_path);
        $file_path = ltrim($file_path, '/');

        $absolute_path = base_path($file_path);

        if (!file_exists($absolute_path)) {
            $this->error("File does not exist: {$absolute_path}");
            return 1;
        }

        $contents = file_get_contents($absolute_path);

        // Get lines and trim them all
        $lines = file($absolute_path);
        foreach ($lines as $index => $line) {
            $lines[$index] = trim($line);
        }

        // Find last closing brace (class close)
        $class_close_index = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if ($lines[$i] === '}') {
                $class_close_index = $i;
                break;
            }
        }

        if ($class_close_index === null) {
            throw new RuntimeException("Could not find class closing brace");
        }

        // Remove existing section headers from previous sorts
        $section_headers = [
            [
                '// ----------------------------------',
                '// RSpade Public Internal Methods:',
                '// ----------------------------------',
            ],
            [
                '// ----------------------------------',
                '// Protected / Private Methods:',
                '// ----------------------------------',
            ],
            [
                '// ------------------------------------------------------------------------',
                '// ---- RSpade Public Internal Methods:',
                '// ------------------------------------------------------------------------',
            ],
            [
                '// ------------------------------------------------------------------------',
                '// ---- Private / Protected Methods:',
                '// ------------------------------------------------------------------------',
            ],
        ];

        for ($i = 0; $i < count($lines) - 2; $i++) {
            foreach ($section_headers as $header) {
                if ($lines[$i] === $header[0] &&
                    $lines[$i + 1] === $header[1] &&
                    $lines[$i + 2] === $header[2]) {
                    $lines[$i] = '';
                    $lines[$i + 1] = '';
                    $lines[$i + 2] = '';
                    break;
                }
            }
        }

        // Parse tokens to find functions with metadata
        $tokens = token_get_all($contents);
        $methods = [];

        for ($i = 0; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                $line_num = $tokens[$i][2];
                $definition_index = $line_num - 1; // Convert to 0-based

                // Get visibility - scan backwards until newline (all on same line)
                $visibility = 'public'; // Default
                $is_static = false;

                for ($j = $i - 1; $j >= 0; $j--) {
                    // Stop at newline - everything before this is a different line
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE && strpos($tokens[$j][1], "\n") !== false) {
                        break;
                    }

                    if (!is_array($tokens[$j])) continue;

                    if ($tokens[$j][0] === T_PUBLIC) $visibility = 'public';
                    elseif ($tokens[$j][0] === T_PROTECTED) $visibility = 'protected';
                    elseif ($tokens[$j][0] === T_PRIVATE) $visibility = 'private';
                    elseif ($tokens[$j][0] === T_STATIC) $is_static = true;
                }

                // Get function name
                $func_name = null;
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $func_name = $tokens[$j][1];
                        break;
                    }
                    if ($tokens[$j] === '(') break;
                }

                if ($func_name) {
                    $methods[$func_name] = [
                        'name' => $func_name,
                        'visibility' => $visibility,
                        'is_static' => $is_static,
                        'definition_index' => $definition_index,
                    ];
                }
            }
        }

        if (empty($methods)) {
            $this->warn("No methods found in file");
            return 0;
        }

        // Find starting_index for each method
        foreach ($methods as $name => $method) {
            $def_index = $method['definition_index'];

            // Go backwards from definition to find starting point
            $found_closing_brace = false;
            for ($i = $def_index - 1; $i >= 0; $i--) {
                if ($lines[$i] === '}') {
                    // Found closing brace - start after it
                    $methods[$name]['starting_index'] = $i + 1;
                    $found_closing_brace = true;
                    break;
                } elseif ($lines[$i] === '{') {
                    // Found opening brace - need to find empty line
                    break;
                }
            }

            if (!$found_closing_brace) {
                // Go backwards from definition to find first empty line
                for ($i = $def_index - 1; $i >= 0; $i--) {
                    if ($lines[$i] === '') {
                        $methods[$name]['starting_index'] = $i + 1;
                        break;
                    }
                }
            }

            // Safety check
            if (!isset($methods[$name]['starting_index'])) {
                $methods[$name]['starting_index'] = 0;
            }
        }

        // Find method with lowest starting_index and recalculate it
        $lowest_start = PHP_INT_MAX;
        $lowest_method = null;
        foreach ($methods as $name => $method) {
            if ($method['starting_index'] < $lowest_start) {
                $lowest_start = $method['starting_index'];
                $lowest_method = $name;
            }
        }

        if ($lowest_method !== null) {
            $def_index = $methods[$lowest_method]['definition_index'];
            for ($i = $def_index - 1; $i >= 0; $i--) {
                if ($lines[$i] === '') {
                    $methods[$lowest_method]['starting_index'] = $i + 1;
                    break;
                }
            }
        }

        // Calculate ending_index for each method
        foreach ($methods as $name => $method) {
            $current_start = $method['starting_index'];

            // Find next closest method starting_index
            $next_start = null;
            foreach ($methods as $other_name => $other_method) {
                if ($other_name === $name) continue;

                $other_start = $other_method['starting_index'];
                if ($other_start > $current_start) {
                    if ($next_start === null || $other_start < $next_start) {
                        $next_start = $other_start;
                    }
                }
            }

            // Set ending_index
            if ($next_start !== null && $next_start <= $class_close_index) {
                $methods[$name]['ending_index'] = $next_start - 1;
            } else {
                $methods[$name]['ending_index'] = $class_close_index - 1;
            }
        }

        // Extract body for each method
        foreach ($methods as $name => $method) {
            $body_lines = [];
            for ($i = $method['starting_index']; $i <= $method['ending_index']; $i++) {
                $body_lines[] = $lines[$i];
            }
            $methods[$name]['body'] = trim(implode("\n", $body_lines));
        }

        // Blank out all method lines in $lines
        foreach ($methods as $name => $method) {
            for ($i = $method['starting_index']; $i <= $method['ending_index']; $i++) {
                $lines[$i] = '';
            }
        }

        // Sort methods alphabetically if requested
        if ($sort_alpha) {
            ksort($methods);
        }

        // Build sorted methods string following RSpade conventions
        $sorted_methods_output = '';
        $is_rspade = str_starts_with($file_path, 'app/RSpade/');
        $remaining_methods = $methods;

        // Group 1: Public static (no underscore)
        foreach ($remaining_methods as $name => $method) {
            if ($method['visibility'] === 'public' && $method['is_static'] && substr($name, 0, 1) !== '_') {
                $sorted_methods_output .= $method['body'] . "\n\n";
                unset($remaining_methods[$name]);
            }
        }

        // Group 2: Public non-static (no underscore)
        foreach ($remaining_methods as $name => $method) {
            if ($method['visibility'] === 'public' && !$method['is_static'] && substr($name, 0, 1) !== '_') {
                $sorted_methods_output .= $method['body'] . "\n\n";
                unset($remaining_methods[$name]);
            }
        }

        // Group 3: RSpade Internal Methods (single underscore public methods in app/RSpade/ only)
        if ($is_rspade) {
            $has_single_underscore_public = false;
            foreach ($remaining_methods as $name => $method) {
                if ($method['visibility'] === 'public' && substr($name, 0, 1) === '_' && substr($name, 1, 1) !== '_') {
                    $has_single_underscore_public = true;
                    break;
                }
            }

            if ($has_single_underscore_public) {
                $sorted_methods_output .= "// ------------------------------------------------------------------------\n";
                $sorted_methods_output .= "// ---- RSpade Public Internal Methods:\n";
                $sorted_methods_output .= "// ------------------------------------------------------------------------\n\n";

                foreach ($remaining_methods as $name => $method) {
                    if ($method['visibility'] === 'public' && substr($name, 0, 1) === '_' && substr($name, 1, 1) !== '_') {
                        $sorted_methods_output .= $method['body'] . "\n\n";
                        unset($remaining_methods[$name]);
                    }
                }
            }
        }

        // Group 4: Public methods with 2+ underscores
        foreach ($remaining_methods as $name => $method) {
            if ($method['visibility'] === 'public' && substr($name, 0, 2) === '__') {
                $sorted_methods_output .= $method['body'] . "\n\n";
                unset($remaining_methods[$name]);
            }
        }

        // Group 5+: Protected/Private Methods - Add header if any exist
        $has_protected_private = !empty(array_filter($remaining_methods, fn($m) => $m['visibility'] !== 'public'));

        if ($has_protected_private) {
            $sorted_methods_output .= "// ------------------------------------------------------------------------\n";
            $sorted_methods_output .= "// ---- Private / Protected Methods:\n";
            $sorted_methods_output .= "// ------------------------------------------------------------------------\n\n";

            // Group 6: Protected/private static (no underscore)
            foreach ($remaining_methods as $name => $method) {
                if ($method['visibility'] !== 'public' && $method['is_static'] && substr($name, 0, 1) !== '_') {
                    $sorted_methods_output .= $method['body'] . "\n\n";
                    unset($remaining_methods[$name]);
                }
            }

            // Group 7: Protected/private non-static (no underscore)
            foreach ($remaining_methods as $name => $method) {
                if ($method['visibility'] !== 'public' && !$method['is_static'] && substr($name, 0, 1) !== '_') {
                    $sorted_methods_output .= $method['body'] . "\n\n";
                    unset($remaining_methods[$name]);
                }
            }

            // Group 8: Protected/private static (single underscore)
            foreach ($remaining_methods as $name => $method) {
                if ($method['visibility'] !== 'public' && $method['is_static'] && substr($name, 0, 1) === '_' && substr($name, 1, 1) !== '_') {
                    $sorted_methods_output .= $method['body'] . "\n\n";
                    unset($remaining_methods[$name]);
                }
            }

            // Group 9: Protected/private non-static (single underscore)
            foreach ($remaining_methods as $name => $method) {
                if ($method['visibility'] !== 'public' && !$method['is_static'] && substr($name, 0, 1) === '_' && substr($name, 1, 1) !== '_') {
                    $sorted_methods_output .= $method['body'] . "\n\n";
                    unset($remaining_methods[$name]);
                }
            }

            // Group 10: Remaining protected/private static
            foreach ($remaining_methods as $name => $method) {
                if ($method['visibility'] !== 'public' && $method['is_static']) {
                    $sorted_methods_output .= $method['body'] . "\n\n";
                    unset($remaining_methods[$name]);
                }
            }

            // Group 11: Remaining protected/private non-static
            foreach ($remaining_methods as $name => $method) {
                if ($method['visibility'] !== 'public' && !$method['is_static']) {
                    $sorted_methods_output .= $method['body'] . "\n\n";
                    unset($remaining_methods[$name]);
                }
            }
        }

        // Insert sorted methods before class closing brace
        $insert_position = $class_close_index;
        array_splice($lines, $insert_position, 0, $sorted_methods_output);

        // Rebuild file
        $final_content = implode("\n", $lines);

        // Save file
        file_put_contents_safe($absolute_path, $final_content);

        // Run PHP CS Fixer
        $formatter_path = base_path('bin/formatters/php-formatter');
        $command = 'php ' . escapeshellarg($formatter_path) . ' ' . escapeshellarg($absolute_path) . ' 2>&1';
        \exec_safe($command, $output, $return_code);

        if ($return_code !== 0) {
            $this->error("PHP formatter failed:");
            $this->line(implode("\n", $output));
            return 1;
        }

        $this->info("[OK] Methods sorted: " . count($methods) . ($sort_alpha ? ' (alphabetically)' : ''));

        return 0;
    }
}
