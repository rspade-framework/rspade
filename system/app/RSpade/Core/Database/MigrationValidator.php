<?php

namespace App\RSpade\Core\Database;

use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use RuntimeException;
use App\RSpade\Core\Database\MigrationPaths;

/**
 * Migration Validator - Enforces raw SQL usage in migrations
 *
 * This validator ensures migrations use only DB::statement() for schema changes,
 * not Laravel's Schema builder. Also removes down() methods from migration files.
 */
class MigrationValidator
{
    /**
     * Validate a migration file for Schema builder usage
     *
     * @param string $filepath Path to migration file
     * @return void
     * @throws RuntimeException if validation fails
     */
    public static function validate_migration_file(string $filepath): void
    {
        if (!file_exists($filepath)) {
            throw new RuntimeException("Migration file not found: {$filepath}");
        }

        $content = file_get_contents($filepath);
        $tokens = token_get_all($content);

        // Check for Schema builder usage
        self::__check_for_schema_builder($tokens, $content, $filepath);
    }

    /**
     * Check tokens for Schema builder usage
     */
    private static function __check_for_schema_builder(array $tokens, string $content, string $filepath): void
    {
        $forbidden_patterns = [
            'Schema::create' => 'Use DB::statement("CREATE TABLE...") instead',
            'Schema::table' => 'Use DB::statement("ALTER TABLE...") instead',
            'Schema::drop' => 'Use DB::statement("DROP TABLE...") instead',
            'Schema::dropIfExists' => 'Use DB::statement("DROP TABLE IF EXISTS...") instead',
            'Schema::rename' => 'Use DB::statement("RENAME TABLE...") instead',
            'Blueprint' => 'Use raw SQL instead of Blueprint',
            '$table->' => 'Use raw SQL column definitions instead',
        ];

        $lines = explode("\n", $content);
        $in_schema_block = false;
        $schema_start_line = null;
        $current_line = 1;
        $method_buffer = '';

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            // Track line numbers
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                $current_line += substr_count($token[1], "\n");
                continue;
            }

            // Check for Schema:: usage
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'Schema') {
                // Look ahead for ::
                $j = $i + 1;
                while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }

                if ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                    // Found Schema::
                    $j++;
                    while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }

                    if ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $method = $tokens[$j][1];

                        // Check if it's a forbidden method
                        $pattern_key = "Schema::{$method}";
                        if ($method !== 'hasTable' && $method !== 'hasColumn' && $method !== 'getColumnListing') {
                            // This is a forbidden Schema method
                            $in_schema_block = true;
                            $schema_start_line = $current_line;
                            $method_buffer = "Schema::{$method}";
                        }
                    }
                }
            }

            // Check for Blueprint usage
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'Blueprint') {
                $in_schema_block = true;
                $schema_start_line = $current_line;
                $method_buffer = 'Blueprint';
            }

            // Check for $table-> usage
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$table') {
                $j = $i + 1;
                while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }

                if ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_OBJECT_OPERATOR) {
                    $in_schema_block = true;
                    $schema_start_line = $current_line;
                    $method_buffer = '$table->';
                }
            }

            // If we found a schema block, extract it
            if ($in_schema_block) {
                self::__report_schema_violation($filepath, $schema_start_line, $lines, $method_buffer, $forbidden_patterns);
                return; // Stop at first violation
            }
        }
    }

    /**
     * Report a Schema builder violation with colored output
     */
    private static function __report_schema_violation(
        string $filepath,
        int $line_number,
        array $lines,
        string $pattern,
        array $forbidden_patterns
    ): void {
        echo "\n";
        echo "\033[1;31m[ERROR] Migration Validation Failed\033[0m\n";
        echo "\n";
        echo "\033[33mFile:\033[0m " . basename($filepath) . "\n";
        echo "\033[33mLine:\033[0m {$line_number}\n";
        echo "\n";
        echo "\033[1;37mViolation:\033[0m Found forbidden Schema builder usage: \033[31m{$pattern}\033[0m\n";
        echo "\n";

        // Show code preview - find the complete statement
        echo "\033[1;37mCode Preview:\033[0m\n";
        echo "\033[90m" . str_repeat("-", 60) . "\033[0m\n";

        $preview = self::__extract_statement($lines, $line_number - 1);
        echo "\033[36m" . $preview . "\033[0m\n";

        echo "\033[90m" . str_repeat("-", 60) . "\033[0m\n";
        echo "\n";

        // Get remediation advice
        $remediation = 'Use raw DB::statement() instead';
        foreach ($forbidden_patterns as $key => $advice) {
            if (strpos($pattern, str_replace(['Schema::', '$table->'], '', $key)) !== false) {
                $remediation = $advice;
                break;
            }
        }

        echo "\033[1;32mRemediation:\033[0m {$remediation}\n";
        echo "\n";
        echo "\033[1;37mExample:\033[0m\n";

        if (strpos($pattern, 'create') !== false) {
            echo "  DB::statement('CREATE TABLE users (\n";
            echo "      id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,\n";
            echo "      name VARCHAR(255),\n";
            echo "      created_at TIMESTAMP NULL DEFAULT NULL\n";
            echo "  )');\n";
        } elseif (strpos($pattern, 'table') !== false || strpos($pattern, 'Blueprint') !== false) {
            echo "  DB::statement('ALTER TABLE users ADD COLUMN age BIGINT NULL');\n";
        } elseif (strpos($pattern, 'drop') !== false) {
            echo "  DB::statement('DROP TABLE IF EXISTS users');\n";
        }

        echo "\n";
        echo "\033[1;31mMigrations must use raw SQL queries, not Laravel's Schema builder.\033[0m\n";
        echo "\n";

        throw new RuntimeException("Migration validation failed: Schema builder usage detected");
    }

    /**
     * Extract complete statement from lines starting at given position
     */
    private static function __extract_statement(array $lines, int $start_line): string
    {
        $statement = '';
        $brace_count = 0;
        $found_start = false;

        for ($i = $start_line; $i < count($lines); $i++) {
            $line = $lines[$i];
            $statement .= $line . "\n";

            // Count braces to find complete statement
            for ($j = 0; $j < strlen($line); $j++) {
                if ($line[$j] === '{') {
                    $brace_count++;
                    $found_start = true;
                } elseif ($line[$j] === '}') {
                    $brace_count--;
                    if ($found_start && $brace_count === 0) {
                        return trim($statement);
                    }
                } elseif ($line[$j] === ';' && $brace_count === 0) {
                    return trim($statement);
                }
            }

            // Stop at 20 lines to prevent huge output
            if ($i - $start_line > 20) {
                return trim($statement) . "\n  ...";
            }
        }

        return trim($statement);
    }

    /**
     * Remove down() method from a migration file
     */
    public static function remove_down_method(string $filepath): bool
    {
        if (!file_exists($filepath)) {
            return false;
        }

        $content = file_get_contents($filepath);
        $tokens = token_get_all($content);

        $down_start_pos = null;
        $down_end_pos = null;
        $brace_count = 0;
        $in_down = false;
        $positions = [];
        $current_pos = 0;

        // Build position map for each token
        for ($i = 0; $i < count($tokens); $i++) {
            $positions[$i] = $current_pos;
            if (is_array($tokens[$i])) {
                $current_pos += strlen($tokens[$i][1]);
            } else {
                $current_pos += strlen($tokens[$i]);
            }
        }

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            // Look for "function down"
            if (is_array($token) && $token[0] === T_FUNCTION) {
                $j = $i + 1;
                while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }

                if ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === 'down') {
                    // Found down() function
                    // Look backwards to find start position including visibility modifier and comments
                    $start_index = $i;

                    // Look for visibility modifier (public, protected, private)
                    for ($k = $i - 1; $k >= 0; $k--) {
                        $prev = $tokens[$k];
                        if (is_array($prev)) {
                            if ($prev[0] === T_PUBLIC || $prev[0] === T_PROTECTED || $prev[0] === T_PRIVATE) {
                                $start_index = $k;
                                break;
                            } elseif ($prev[0] === T_COMMENT || $prev[0] === T_DOC_COMMENT) {
                                $start_index = $k;
                            } elseif ($prev[0] !== T_WHITESPACE) {
                                break;
                            }
                        } else {
                            break;
                        }
                    }

                    // Look for comments/whitespace before visibility or function
                    for ($k = $start_index - 1; $k >= 0; $k--) {
                        $prev = $tokens[$k];
                        if (is_array($prev)) {
                            if ($prev[0] === T_COMMENT || $prev[0] === T_DOC_COMMENT || $prev[0] === T_WHITESPACE) {
                                $start_index = $k;
                            } else {
                                break;
                            }
                        } else {
                            break;
                        }
                    }

                    $down_start_pos = $positions[$start_index];
                    $in_down = true;
                    $brace_count = 0;
                }
            }

            // Track braces to find end of down() method
            if ($in_down) {
                // Skip string interpolation braces
                if (is_array($token) && $token[0] === T_CURLY_OPEN) {
                    continue;
                }

                if (is_string($token)) {
                    if ($token === '{') {
                        $brace_count++;
                    } elseif ($token === '}') {
                        // Check if this is closing string interpolation
                        $is_string_interpolation = false;
                        for ($j = $i - 1; $j >= max(0, $i - 10); $j--) {
                            if (is_array($tokens[$j]) && $tokens[$j][0] === T_CURLY_OPEN) {
                                $is_string_interpolation = true;
                                break;
                            }
                        }
                        if (!$is_string_interpolation) {
                            $brace_count--;
                            if ($brace_count === 0) {
                                // Found closing brace of down() method
                                $down_end_pos = $positions[$i] + 1; // Include the closing brace
                                $in_down = false;
                                break;
                            }
                        }
                    }
                }
            }
        }

        // If we found down() method, remove it
        if ($down_start_pos !== null && $down_end_pos !== null) {
            $before = substr($content, 0, $down_start_pos);
            $after = substr($content, $down_end_pos);

            // Clean up trailing whitespace from before section
            $before = rtrim($before);

            // Ensure exactly one newline before closing brace
            $new_content = $before . "\n};\n";

            file_put_contents_safe($filepath, $new_content);
            return true;
        }

        return false;
    }

    /**
     * Get pending migrations that haven't been run yet
     */
    public static function get_pending_migrations(MigrationRepositoryInterface $repository): array
    {
        $ran = $repository->getRan();
        $migration_files = [];

        // Discover migration files recursively (covers nested subdirectories such
        // as database/migrations/rspade/) using the shared, canonically ordered set.
        foreach (MigrationPaths::get_all_migration_files() as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $ran)) {
                $migration_files[] = $file;
            }
        }

        return $migration_files;
    }
}