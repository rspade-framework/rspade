<?php

namespace App\RSpade\Core\Database;

/**
 * SQL Query Transformer
 *
 * Enforces RSpade framework schema conventions by transforming DDL statements.
 * Intercepts CREATE TABLE and ALTER TABLE queries during migrations to ensure
 * consistent column types and character sets.
 *
 * Type conversions enforced:
 * - INT/INTEGER/MEDIUMINT/SMALLINT → BIGINT (except TINYINT(1) for booleans)
 * - FLOAT/REAL → DOUBLE
 * - TEXT/MEDIUMTEXT → LONGTEXT
 * - CHAR(n) → VARCHAR(n)
 * - TIMESTAMP → TIMESTAMP(3), DATETIME → DATETIME(3) (millisecond precision)
 * - All VARCHAR/TEXT → utf8mb4 charset + utf8mb4_unicode_ci collation
 *
 * Forbidden types (throws exception):
 * - ENUM - Use VARCHAR with validation instead
 * - SET - Use JSON or separate table
 * - YEAR - Use INT or DATE
 * - TIME - Use DATETIME
 * - CHAR - Use VARCHAR
 *
 * This eliminates the need for foreign key drop/recreate in normalize_schema
 * because tables are created with correct types from the start.
 */
class SqlQueryTransformer
{
    /**
     * Whether the transformer is currently enabled
     */
    private static bool $enabled = false;

    /**
     * Enable query transformation
     *
     * Call this at the start of migration runs to activate the transformer.
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Disable query transformation
     *
     * Call this after migration runs complete.
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Check if transformer is enabled
     */
    public static function is_enabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Transform a SQL query according to framework conventions
     *
     * @param string $query The SQL query to transform
     * @return string The transformed query
     * @throws \RuntimeException If query contains forbidden column types
     */
    public static function transform(string $query): string
    {
        if (!self::$enabled) {
            return $query;
        }

        // Check if this is a CREATE TABLE or ALTER TABLE statement
        $normalized = self::__normalize_whitespace($query);
        if (!self::__is_ddl_statement($normalized)) {
            return $query;
        }

        // Validate - throw exception for forbidden types and syntax issues
        self::__validate_forbidden_types($query);
        self::__validate_syntax_issues($query);

        // Transform the query
        $transformed = $query;

        // 1. Integer type transformations
        $transformed = self::__transform_integer_types($transformed);

        // 2. Floating point transformations
        $transformed = self::__transform_float_types($transformed);

        // 3. Text type transformations
        $transformed = self::__transform_text_types($transformed);

        // 4. Datetime precision transformations
        $transformed = self::__transform_datetime_types($transformed);

        // 5. Character set enforcement
        $transformed = self::__enforce_utf8mb4($transformed);

        return $transformed;
    }

    /**
     * Normalize whitespace in SQL query for easier parsing
     *
     * - Collapses multiple spaces to single space
     * - Preserves quoted strings
     * - Removes leading/trailing whitespace
     *
     * @param string $query The SQL query
     * @return string Normalized query
     */
    private static function __normalize_whitespace(string $query): string
    {
        // Remove leading/trailing whitespace
        $query = trim($query);

        // Collapse multiple spaces, tabs, newlines to single space
        // But preserve quoted strings
        $result = '';
        $in_single_quote = false;
        $in_double_quote = false;
        $in_backtick = false;
        $prev_was_space = false;

        for ($i = 0; $i < strlen($query); $i++) {
            $char = $query[$i];

            // Handle quotes
            if ($char === "'" && !$in_double_quote && !$in_backtick) {
                $in_single_quote = !$in_single_quote;
                $result .= $char;
                $prev_was_space = false;
                continue;
            }

            if ($char === '"' && !$in_single_quote && !$in_backtick) {
                $in_double_quote = !$in_double_quote;
                $result .= $char;
                $prev_was_space = false;
                continue;
            }

            if ($char === '`' && !$in_single_quote && !$in_double_quote) {
                $in_backtick = !$in_backtick;
                $result .= $char;
                $prev_was_space = false;
                continue;
            }

            // If we're inside quotes, preserve everything
            if ($in_single_quote || $in_double_quote || $in_backtick) {
                $result .= $char;
                $prev_was_space = false;
                continue;
            }

            // Outside quotes: collapse whitespace
            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                if (!$prev_was_space) {
                    $result .= ' ';
                    $prev_was_space = true;
                }
                continue;
            }

            // Regular character
            $result .= $char;
            $prev_was_space = false;
        }

        return $result;
    }

    /**
     * Check if query is a DDL statement we need to transform
     *
     * @param string $normalized_query Normalized SQL query
     * @return bool True if this is CREATE TABLE or ALTER TABLE
     */
    private static function __is_ddl_statement(string $normalized_query): bool
    {
        $upper = strtoupper($normalized_query);

        return str_starts_with($upper, 'CREATE TABLE')
            || str_starts_with($upper, 'ALTER TABLE');
    }

    /**
     * Validate that query doesn't contain forbidden column types
     *
     * @param string $query The SQL query
     * @throws \RuntimeException If forbidden types are found
     */
    private static function __validate_forbidden_types(string $query): void
    {
        // Strip comments and quoted strings before validation to avoid false positives
        // (e.g., "TIME" in a COMMENT won't trigger the TIME type check)
        $query_without_strings = self::__strip_comments_and_strings($query);

        // Check for ENUM
        if (preg_match('/\bENUM\s*\(/i', $query_without_strings)) {
            throw new \RuntimeException(
                "ENUM column type is forbidden in RSpade. Use VARCHAR with validation instead.\n" .
                "ENUMs cannot be modified without table locks and cause deployment issues.\n" .
                "Query: " . substr($query, 0, 200)
            );
        }

        // Check for SET
        if (preg_match('/\bSET\s*\(/i', $query_without_strings)) {
            throw new \RuntimeException(
                "SET column type is forbidden in RSpade. Use JSON or a separate table instead.\n" .
                "Query: " . substr($query, 0, 200)
            );
        }

        // Check for YEAR
        if (preg_match('/\bYEAR\b/i', $query_without_strings)) {
            throw new \RuntimeException(
                "YEAR column type is forbidden in RSpade. Use INT or DATE instead.\n" .
                "Query: " . substr($query, 0, 200)
            );
        }

        // Check for TIME (but allow DATETIME and TIMESTAMP)
        if (preg_match('/\bTIME\b(?!STAMP)/i', $query_without_strings)) {
            // Additional check: make sure it's not part of DATETIME
            if (!preg_match('/\bDATETIME\b/i', $query_without_strings) ||
                preg_match('/[,\s]\s*TIME\s+/i', $query_without_strings)) {
                throw new \RuntimeException(
                    "TIME column type is forbidden in RSpade. Use DATETIME instead.\n" .
                    "Query: " . substr($query, 0, 200)
                );
            }
        }
    }

    /**
     * Strip comments and quoted strings from SQL query
     *
     * Removes:
     * - Single-quoted strings
     * - Double-quoted strings
     * - SQL comments (both single-line and multi-line)
     *
     * This prevents false positives in validation (e.g., 'TIME' in a comment)
     *
     * @param string $query The SQL query
     * @return string Query with comments and strings removed
     */
    private static function __strip_comments_and_strings(string $query): string
    {
        $result = '';
        $len = strlen($query);
        $i = 0;

        while ($i < $len) {
            // Check for single-line comment --
            if ($i < $len - 1 && $query[$i] === '-' && $query[$i + 1] === '-') {
                // Skip until newline
                while ($i < $len && $query[$i] !== "\n") {
                    $i++;
                }
                $i++; // Skip the newline
                continue;
            }

            // Check for multi-line comment /* */
            if ($i < $len - 1 && $query[$i] === '/' && $query[$i + 1] === '*') {
                // Skip until */
                $i += 2;
                while ($i < $len - 1 && !($query[$i] === '*' && $query[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2; // Skip the */
                continue;
            }

            // Check for single-quoted string
            if ($query[$i] === "'") {
                $i++; // Skip opening quote
                while ($i < $len) {
                    if ($query[$i] === "'") {
                        // Check for escaped quote ''
                        if ($i + 1 < $len && $query[$i + 1] === "'") {
                            $i += 2; // Skip escaped quote
                        } else {
                            $i++; // Skip closing quote
                            break;
                        }
                    } else {
                        $i++;
                    }
                }
                continue;
            }

            // Check for double-quoted string
            if ($query[$i] === '"') {
                $i++; // Skip opening quote
                while ($i < $len) {
                    if ($query[$i] === '"') {
                        $i++; // Skip closing quote
                        break;
                    } else if ($query[$i] === '\\' && $i + 1 < $len) {
                        $i += 2; // Skip escaped character
                    } else {
                        $i++;
                    }
                }
                continue;
            }

            // Regular character - keep it
            $result .= $query[$i];
            $i++;
        }

        return $result;
    }

    /**
     * Validate MySQL syntax patterns that commonly cause issues
     *
     * Checks for common syntax mistakes that are easy to make but produce
     * confusing error messages from MySQL.
     *
     * @param string $query The SQL query
     * @throws \RuntimeException If syntax issues are found
     */
    private static function __validate_syntax_issues(string $query): void
    {
        // Check for AFTER before COMMENT (incorrect order in ALTER TABLE ADD COLUMN)
        // MySQL requires: ... COMMENT 'text' AFTER column_name
        // NOT: ... AFTER column_name COMMENT 'text'
        if (preg_match('/\bAFTER\s+\w+\s+COMMENT\s+[\'\"]/i', $query)) {
            throw new \RuntimeException(
                "Syntax error: COMMENT must come BEFORE AFTER in ALTER TABLE statements.\n\n" .
                "Incorrect: ADD COLUMN name TYPE NULL AFTER other_column COMMENT 'text'\n" .
                "Correct:   ADD COLUMN name TYPE NULL COMMENT 'text' AFTER other_column\n\n" .
                "MySQL syntax order for ADD COLUMN:\n" .
                "  1. Column name\n" .
                "  2. Data type\n" .
                "  3. NULL/NOT NULL\n" .
                "  4. DEFAULT value (optional)\n" .
                "  5. COMMENT (optional)\n" .
                "  6. AFTER/FIRST (optional)\n\n" .
                "Query: " . substr($query, 0, 300)
            );
        }

        // Check for DEFAULT after COMMENT (incorrect order)
        // MySQL requires: ... DEFAULT value COMMENT 'text'
        // NOT: ... COMMENT 'text' DEFAULT value
        if (preg_match('/\bCOMMENT\s+[\'\"]\w+[\'\"][\s,]*DEFAULT\b/i', $query)) {
            throw new \RuntimeException(
                "Syntax error: DEFAULT must come BEFORE COMMENT in column definitions.\n\n" .
                "Incorrect: ... COMMENT 'text' DEFAULT value\n" .
                "Correct:   ... DEFAULT value COMMENT 'text'\n\n" .
                "Query: " . substr($query, 0, 300)
            );
        }

        // Check for AFTER after DEFAULT (incorrect order when COMMENT is also present)
        // This is a less common mistake but worth catching
        if (preg_match('/\bDEFAULT\s+\S+\s+AFTER\s+\w+\s+COMMENT\b/i', $query)) {
            throw new \RuntimeException(
                "Syntax error: Column definition clauses are in wrong order.\n\n" .
                "Correct order: TYPE [NULL|NOT NULL] [DEFAULT value] [COMMENT 'text'] [AFTER column]\n\n" .
                "Query: " . substr($query, 0, 300)
            );
        }
    }

    /**
     * Transform integer column types to BIGINT
     *
     * Converts: INT, INTEGER, MEDIUMINT, SMALLINT → BIGINT
     * Preserves: TINYINT(1) for booleans
     * Removes: UNSIGNED attribute (framework uses signed integers only)
     *
     * @param string $query The SQL query
     * @return string Transformed query
     */
    private static function __transform_integer_types(string $query): string
    {
        // INT, INTEGER, MEDIUMINT, SMALLINT → BIGINT
        // But NOT TINYINT(1) which is for booleans
        // Match: INT, INT(11), INT UNSIGNED, INTEGER, etc.
        // Don't match: BIGINT, TINYINT, or words containing these (like HINT, POINT, COMMENT)

        // Convert INT variants (but not BIGINT or TINYINT)
        // Use word boundaries on both sides to avoid matching INT within words like COMMENT
        $query = preg_replace(
            '/\b(?:INT|INTEGER|MEDIUMINT|SMALLINT)\b(?:\(\d+\))?(?:\s+UNSIGNED)?/i',
            'BIGINT',
            $query
        );

        // Convert TINYINT(n) where n != 1 to BIGINT
        $query = preg_replace(
            '/\bTINYINT\s*\(\s*([2-9]|[1-9]\d+)\s*\)(?:\s+UNSIGNED)?/i',
            'BIGINT',
            $query
        );

        // Remove UNSIGNED from BIGINT columns (normalize to signed)
        $query = preg_replace(
            '/\bBIGINT(?:\(\d+\))?\s+UNSIGNED/i',
            'BIGINT',
            $query
        );

        return $query;
    }

    /**
     * Transform floating point types to DOUBLE
     *
     * Converts: FLOAT, REAL → DOUBLE
     * Preserves: DECIMAL (for exact precision like money)
     *
     * @param string $query The SQL query
     * @return string Transformed query
     */
    private static function __transform_float_types(string $query): string
    {
        // FLOAT, REAL -> DOUBLE.
        //
        // THE TRAILING \b IS LOAD-BEARING. Without it, `\b` anchors only the START of the
        // match, so the identifier `realm` matched its `real` prefix and a migration
        // silently created a column named `DOUBLEm` - no error, no warning, a schema
        // quietly wrong. Every identifier beginning `real...` was affected (`realm`,
        // `realtime_id`, `real_name`). `_flash_alerts.realm_id` is now a framework column,
        // so this repository's own schema is the canary.
        //
        // The boundary still matches the type forms: in `FLOAT(10,2)` there is a word
        // boundary between `T` and `(`.
        $query = preg_replace(
            '/\b(?:FLOAT|REAL)\b(?:\s*\(\d+(?:,\d+)?\))?(?:\s+UNSIGNED)?/i',
            'DOUBLE',
            $query
        );

        return $query;
    }

    /**
     * Transform text column types
     *
     * Converts: TEXT, MEDIUMTEXT → LONGTEXT
     * Converts: CHAR(n) → VARCHAR(n)
     *
     * @param string $query The SQL query
     * @return string Transformed query
     */
    private static function __transform_text_types(string $query): string
    {
        // TEXT, MEDIUMTEXT, TINYTEXT → LONGTEXT
        $query = preg_replace(
            '/\b(?:TINY|MEDIUM)?TEXT\b/i',
            'LONGTEXT',
            $query
        );

        // CHAR(n) → VARCHAR(n)
        $query = preg_replace(
            '/\bCHAR\s*\((\d+)\)/i',
            'VARCHAR($1)',
            $query
        );

        return $query;
    }

    /**
     * Transform datetime column types to include millisecond precision
     *
     * Converts: TIMESTAMP → TIMESTAMP(3)
     * Converts: CURRENT_TIMESTAMP → CURRENT_TIMESTAMP(3)
     * Converts: DATETIME → DATETIME(3)
     *
     * This ensures datetime columns have millisecond precision from the start,
     * preventing FK type mismatches when normalize_schema runs later.
     *
     * @param string $query The SQL query
     * @return string Transformed query
     */
    private static function __transform_datetime_types(string $query): string
    {
        // TIMESTAMP without precision → TIMESTAMP(3)
        // Don't match if already has precision: TIMESTAMP(3), TIMESTAMP(6)
        // Don't match CURRENT_TIMESTAMP (handled separately below)
        $query = preg_replace(
            '/(?<!CURRENT_)\bTIMESTAMP\b(?!\s*\()/i',
            'TIMESTAMP(3)',
            $query
        );

        // CURRENT_TIMESTAMP without precision → CURRENT_TIMESTAMP(3)
        $query = preg_replace(
            '/\bCURRENT_TIMESTAMP\b(?!\s*\()/i',
            'CURRENT_TIMESTAMP(3)',
            $query
        );

        // DATETIME without precision → DATETIME(3)
        // Don't match if already has precision: DATETIME(3), DATETIME(6)
        $query = preg_replace(
            '/\bDATETIME\b(?!\s*\()/i',
            'DATETIME(3)',
            $query
        );

        return $query;
    }

    /**
     * Enforce utf8mb4 character set on all string columns
     *
     * 1. Replaces existing latin1/utf8/utf8mb3 with utf8mb4
     * 2. Adds CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci to columns without charset
     * 3. Replaces table-level DEFAULT CHARSET with utf8mb4
     *
     * @param string $query The SQL query
     * @return string Transformed query
     */
    private static function __enforce_utf8mb4(string $query): string
    {
        // Replace existing CHARACTER SET declarations with utf8mb4
        // Match: CHARACTER SET latin1, CHARACTER SET utf8, CHARACTER SET utf8mb3
        $query = preg_replace(
            '/CHARACTER\s+SET\s+(?:latin1|utf8mb3|utf8)\b/i',
            'CHARACTER SET utf8mb4',
            $query
        );

        // Replace existing COLLATE declarations with utf8mb4_unicode_ci
        // Match: COLLATE latin1_swedish_ci, COLLATE utf8_general_ci, etc.
        $query = preg_replace(
            '/COLLATE\s+(?:latin1|utf8mb3|utf8)_\w+/i',
            'COLLATE utf8mb4_unicode_ci',
            $query
        );

        // Replace table-level DEFAULT CHARSET
        $query = preg_replace(
            '/DEFAULT\s+CHARSET\s*=\s*(?:latin1|utf8mb3|utf8)\b/i',
            'DEFAULT CHARSET=utf8mb4',
            $query
        );

        // Add charset to VARCHAR(n) that doesn't already have CHARACTER SET
        // Negative lookahead allows whitespace and comments before CHARACTER SET check
        $query = preg_replace(
            '/\bVARCHAR\s*\(\s*\d+\s*\)(?!(?:\s|\/\*.*?\*\/|--[^\n]*)*\s*CHARACTER\s+SET)/i',
            '$0 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $query
        );

        // Add charset to LONGTEXT that doesn't already have CHARACTER SET
        // Negative lookahead allows whitespace and comments before CHARACTER SET check
        $query = preg_replace(
            '/\bLONGTEXT\b(?!(?:\s|\/\*.*?\*\/|--[^\n]*)*\s*CHARACTER\s+SET)/i',
            '$0 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $query
        );

        return $query;
    }
}
