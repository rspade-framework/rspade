<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\PHP;

use App\RSpade\CodeQuality\RuntimeChecks\ManifestErrors;
use App\RSpade\Core\Debug\Rsx_File_Exception;

class Php_Parser
{
    /**
     * Parse a PHP file using token_get_all() to extract metadata
     *
     * @param string $file_path Absolute path to PHP file
     * @param array $step_2_manifest_data Current state of manifest data for fix operations
     * @return array Metadata including class, namespace, methods, etc.
     */
    public static function parse(string $file_path, array &$step_2_manifest_data): array
    {
        $content = file_get_contents($file_path);

        return self::_extract_php_metadata($file_path, $content);
    }

    /**
     * Validate PHP syntax with the full grammar parser.
     *
     * The metadata extractor uses the lenient token_get_all() (no TOKEN_PARSE),
     * which does not throw on syntax errors -- a broken file sails through and
     * only detonates later at include_once, as a confusing fatal. Running
     * TOKEN_PARSE here catches the error early, at the file, in a form we can
     * turn into an actionable message.
     *
     * @param string $file_path Absolute path (for error reporting)
     * @param string $content File contents
     * @throws Rsx_File_Exception If the file has a PHP syntax error
     */
    private static function _validate_syntax(string $file_path, string $content): void
    {
        try {
            // TOKEN_PARSE runs PHP's real grammar parser without loading/executing.
            token_get_all($content, TOKEN_PARSE);
        } catch (\CompileError $e) {
            // \ParseError extends \CompileError, so this covers both.
            self::_throw_syntax_error($file_path, $content, $e);
        }
    }

    /**
     * Convert a caught PHP syntax error into an actionable Rsx_File_Exception.
     *
     * Special-cases the "cron step syntax inside a block comment" trap: an
     * asterisk-slash-number sequence (the every-N-minutes cron form) written in
     * a /* ... *(slash) comment closes the comment early -- its asterisk-slash
     * pair IS the comment terminator -- turning the rest of the intended comment
     * into live PHP that fails to parse. This is otherwise very hard to spot.
     *
     * @param string $file_path Absolute path (for error reporting)
     * @param string $content File contents
     * @param \CompileError $e The original parser error
     * @throws Rsx_File_Exception Always
     */
    private static function _throw_syntax_error(string $file_path, string $content, \CompileError $e): void
    {
        // Fingerprint: a block-comment close ("*" + "/") immediately followed by a
        // digit. In real code that asterisk-slash pair only closes a comment, so a
        // digit right after it means an intended "*(slash)N" cron step was written
        // inside the comment and truncated it.
        if (preg_match('/\/\*[\s\S]*?\*\/(\d)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $line = substr_count($content, "\n", 0, $matches[1][1]) + 1;

            $message = "==========================================\n";
            $message .= "FATAL: cron step syntax inside a block comment\n";
            $message .= "==========================================\n\n";
            $message .= "File: {$file_path}\n";
            $message .= "Near line {$line}\n\n";
            $message .= "A block comment contains cron \"step\" syntax -- an asterisk, a slash,\n";
            $message .= "then a number (the every-N-minutes form). That asterisk-slash pair is\n";
            $message .= "ALSO the block-comment terminator, so it closed the comment early.\n";
            $message .= "Everything after it became live PHP, which is why the parser reported:\n";
            $message .= "  \"{$e->getMessage()}\"\n\n";
            $message .= "HOW TO FIX:\n";
            $message .= "1. Use a line comment (//) instead of a block comment, OR\n";
            $message .= "2. Reword using a human-readable schedule phrase, e.g.\n";
            $message .= "     #[Schedule('every 5 minutes')]   #[Schedule('every 6 hours')]\n";
            $message .= "   These contain no asterisk-slash pair, so they are safe in both\n";
            $message .= "   attributes and comments (see Cron_Parser for the full phrase list).\n\n";
            $message .= "==========================================";

            throw new Rsx_File_Exception($message, 0, $e, $file_path, $line);
        }

        // Any other syntax error: surface it cleanly at the file and line, at parse
        // time, instead of letting it blow up later inside include_once.
        throw new Rsx_File_Exception(
            "PHP syntax error in {$file_path} (line {$e->getLine()}): {$e->getMessage()}",
            0,
            $e,
            $file_path,
            $e->getLine()
        );
    }

    /**
     * Main metadata extraction orchestrator
     * Coordinates all parsing and validation steps
     */
    private static function _extract_php_metadata(string $file_path, string $content): array
    {
        // Validate syntax before anything else. This is the first read of every
        // changed PHP file (Phase 2), well before the Phase 3 include_once that
        // would otherwise surface a syntax error as an opaque fatal deep inside
        // the file -- and before Php_Fixer rewrites the (broken) file.
        self::_validate_syntax($file_path, $content);

        $data = [];
        $tokens = token_get_all($content);
        $is_rsx_file = str_starts_with($file_path, 'rsx/');

        // Step 1: Parse basic structure from tokens
        $structure = self::__parse_basic_structure($tokens);

        // Step 2: Validate FQCN usage
        $fqcn_data = self::__validate_fqcn_usage($tokens, $file_path);

        // Step 3: Validate file structure based on class presence
        if (!empty($structure['classes_found'])) {
            // File has classes
            $validation_data = self::__validate_class_file_structure(
                $structure,
                $tokens,
                $file_path,
                $is_rsx_file
            );
        } else {
            // Classless file
            $validation_data = self::__validate_classless_file_structure(
                $structure,
                $tokens,
                $file_path,
                $is_rsx_file
            );
        }

        // Step 4: Parse static properties via token loop (if class exists)
        if ($structure['class_name']) {
            $static_properties = self::__extract_static_properties($content, $tokens);
            if (!empty($static_properties)) {
                $data['static_properties'] = $static_properties;
            }
        }

        // Step 5: Assemble final metadata
        $data = self::__assemble_metadata(
            $data,
            $structure,
            $fqcn_data,
            $validation_data,
            $file_path
        );

        return $data;
    }

    /**
     * Parse basic structure: namespace, classes, global functions/constants
     * Returns: namespace, class_name, extends_class, classes_found[], global_functions[], global_constants[]
     */
    private static function __parse_basic_structure(array $tokens): array
    {
        $namespace = '';
        $classes_found = [];
        $class_name = null;
        $extends_class = null;
        $global_functions = [];
        $global_constants = [];

        // Track brace depth to know when we're at global scope
        $brace_depth = 0;
        $in_function = false;
        $in_class = false;

        // Token-based extraction for basic info
        for ($i = 0; $i < count($tokens); $i++) {
            // Skip string interpolation braces (T_CURLY_OPEN indicates {$var} inside strings)
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CURLY_OPEN) {
                continue;
            }

            // Track braces to know scope
            if ($tokens[$i] === '{') {
                $brace_depth++;
            } elseif ($tokens[$i] === '}') {
                // Check if this is closing string interpolation
                $is_string_interpolation = false;
                for ($j = $i - 1; $j >= max(0, $i - 10); $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CURLY_OPEN) {
                        $is_string_interpolation = true;
                        break;
                    }
                }
                if (!$is_string_interpolation) {
                    $brace_depth--;
                    if ($brace_depth === 0) {
                        $in_function = false;
                        $in_class = false;
                    }
                }
            }

            // Extract namespace
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_NAMESPACE) {
                $namespace = self::__extract_namespace_from_tokens($tokens, $i);
            }

            // Extract class name and extends (also detect traits)
            if (is_array($tokens[$i]) && ($tokens[$i][0] === T_CLASS || $tokens[$i][0] === T_TRAIT)) {
                // Skip if this is a ::class constant reference
                if ($i > 0 && is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_DOUBLE_COLON) {
                    continue;
                }

                $in_class = true;
                $is_trait = ($tokens[$i][0] === T_TRAIT);
                $class_info = self::__extract_class_info($tokens, $i, $is_trait);

                if ($class_info && !$class_info['is_anonymous']) {
                    $classes_found[] = [
                        'name' => $class_info['name'],
                        'extends' => $class_info['extends'],
                        'is_trait' => $is_trait,
                    ];

                    // Store first named class/trait as primary
                    if ($class_name === null) {
                        $class_name = $class_info['name'];
                        $extends_class = $class_info['extends'];
                    }
                }
            }

            // Extract global functions (only at top level scope)
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION && $brace_depth === 0 && !$in_class) {
                $in_function = true;
                $func_name = self::__extract_function_name($tokens, $i);
                if ($func_name) {
                    $global_functions[] = $func_name;
                }
            }

            // Extract global constants defined with define()
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'define' && $brace_depth === 0 && !$in_class) {
                $const_name = self::__extract_define_constant($tokens, $i);
                if ($const_name) {
                    $global_constants[] = $const_name;
                }
            }
        }

        return [
            'namespace' => $namespace,
            'class_name' => $class_name,
            'extends_class' => $extends_class,
            'classes_found' => $classes_found,
            'global_functions' => $global_functions,
            'global_constants' => $global_constants,
        ];
    }

    /**
     * Extract namespace from tokens starting at position i
     */
    private static function __extract_namespace_from_tokens(array $tokens, int $i): string
    {
        $namespace_parts = [];
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                break;
            }
            if (is_array($tokens[$j])) {
                // PHP 8.0+ uses T_NAME_QUALIFIED and T_NAME_FULLY_QUALIFIED for namespaces
                if ($tokens[$j][0] === T_STRING ||
                    $tokens[$j][0] === T_NS_SEPARATOR ||
                    (defined('T_NAME_QUALIFIED') && $tokens[$j][0] === T_NAME_QUALIFIED) ||
                    (defined('T_NAME_FULLY_QUALIFIED') && $tokens[$j][0] === T_NAME_FULLY_QUALIFIED)) {
                    $namespace_parts[] = $tokens[$j][1];
                }
            }
        }

        return implode('', $namespace_parts);
    }

    /**
     * Extract class/trait information (name, extends, whether anonymous)
     */
    private static function __extract_class_info(array $tokens, int $i, bool $is_trait = false): ?array
    {
        $current_class = null;
        $current_extends = null;
        $is_anonymous = false;

        // Check if next non-whitespace token after T_CLASS/T_TRAIT is T_STRING (named class/trait)
        // or something else like '(' or T_EXTENDS (anonymous class - traits cannot be anonymous)
        for ($j = $i + 1; $j < count($tokens); $j++) {
            // Skip whitespace
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            // If we find a string, it's a named class/trait
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $current_class = $tokens[$j][1];
                break;
            }

            // If we find extends, implements, or {, it's an anonymous class (traits cannot be anonymous)
            if ((is_array($tokens[$j]) && in_array($tokens[$j][0], [T_EXTENDS, T_IMPLEMENTS])) ||
                $tokens[$j] === '{' || $tokens[$j] === '(') {
                $is_anonymous = true;
                break;
            }
        }

        // Skip anonymous classes
        if ($is_anonymous) {
            return null;
        }

        // Look for extends (only for named classes/traits)
        if ($current_class) {
            for ($j = $i + 1; $j < count($tokens); $j++) {
                if ($tokens[$j] === '{') {
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_EXTENDS) {
                    for ($k = $j + 1; $k < count($tokens); $k++) {
                        if (is_array($tokens[$k])) {
                            // PHP 8.0+ uses T_NAME_QUALIFIED for class names with namespaces
                            if ($tokens[$k][0] === T_STRING ||
                                (defined('T_NAME_QUALIFIED') && $tokens[$k][0] === T_NAME_QUALIFIED) ||
                                (defined('T_NAME_FULLY_QUALIFIED') && $tokens[$k][0] === T_NAME_FULLY_QUALIFIED)) {
                                // Normalize to simple class name (strip namespace qualifiers and leading backslash)
                                // Since RSX enforces unique simple class names, we only need the simple name
                                $raw_extends = $tokens[$k][1];
                                $current_extends = \App\RSpade\Core\Manifest\Manifest::_normalize_class_name($raw_extends);
                                break;
                            }
                        }
                    }
                    break;
                }
            }

            return [
                'name' => $current_class,
                'extends' => $current_extends,
                'is_anonymous' => false,
                'is_trait' => $is_trait,
            ];
        }

        return null;
    }

    /**
     * Extract function name from tokens starting at position i
     */
    private static function __extract_function_name(array $tokens, int $i): ?string
    {
        // Look for function name
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                return $tokens[$j][1];
            }
            if ($tokens[$j] === '(' || $tokens[$j] === '{') {
                break; // Anonymous function
            }
        }

        return null;
    }

    /**
     * Extract constant name from define() call
     */
    private static function __extract_define_constant(array $tokens, int $i): ?string
    {
        // Look for the constant name in define('NAME', value)
        $found_paren = false;
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if ($tokens[$j] === '(') {
                $found_paren = true;
                continue;
            }
            if ($found_paren && is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                // Extract the constant name from quotes
                return trim($tokens[$j][1], '\'"');
            }
            if ($tokens[$j] === ';') {
                break;
            }
        }

        return null;
    }

    /**
     * Validate FQCN usage - check for direct \Rsx\ references outside use statements
     */
    private static function __validate_fqcn_usage(array $tokens, string $file_path): array
    {
        $has_rsx_fqcn_usage = false;
        $rsx_fqcn_violations = [];
        $in_use_statement = false;

        for ($i = 0; $i < count($tokens); $i++) {
            // Track if we're in a use statement
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_USE) {
                $in_use_statement = true;
            } elseif ($tokens[$i] === ';' && $in_use_statement) {
                $in_use_statement = false;
            }

            // Look for T_NAME_FULLY_QUALIFIED or \Rsx\ patterns (not in use statements)
            if (!$in_use_statement && is_array($tokens[$i])) {
                $token_value = $tokens[$i][1];

                // Check for PHP 8.0+ T_NAME_FULLY_QUALIFIED token
                if (defined('T_NAME_FULLY_QUALIFIED') && $tokens[$i][0] === T_NAME_FULLY_QUALIFIED) {
                    if (str_starts_with($token_value, '\\Rsx\\')) {
                        $line = $tokens[$i][2] ?? 0;
                        $rsx_fqcn_violations[] = [
                            'line' => $line,
                            'fqcn' => $token_value,
                            'message' => "Direct FQCN reference '{$token_value}' not allowed. Use simple class name instead - the autoloader will resolve it.",
                        ];
                        $has_rsx_fqcn_usage = true;
                    }
                }
                // For older PHP or when token isn't recognized as T_NAME_FULLY_QUALIFIED
                elseif ($tokens[$i][0] === T_NS_SEPARATOR && $i + 1 < count($tokens)) {
                    // Check if this starts \Rsx\
                    if (is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_STRING && $tokens[$i + 1][1] === 'Rsx') {
                        // Build the full FQCN by collecting tokens
                        $fqcn = '\\Rsx';
                        $j = $i + 2;
                        while ($j < count($tokens)) {
                            if (is_array($tokens[$j]) && $tokens[$j][0] === T_NS_SEPARATOR) {
                                $fqcn .= '\\';
                            } elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                                $fqcn .= $tokens[$j][1];
                            } else {
                                break;
                            }
                            $j++;
                        }

                        $line = $tokens[$i][2] ?? 0;
                        $rsx_fqcn_violations[] = [
                            'line' => $line,
                            'fqcn' => $fqcn,
                            'message' => "Direct FQCN reference '{$fqcn}' not allowed. Use simple class name instead - the autoloader will resolve it.",
                        ];
                        $has_rsx_fqcn_usage = true;
                    }
                }
            }
        }

        return [
            'has_rsx_fqcn_usage' => $has_rsx_fqcn_usage,
            'rsx_fqcn_violations' => $rsx_fqcn_violations,
        ];
    }

    /**
     * Validate structure for files containing classes
     */
    private static function __validate_class_file_structure(array $structure, array $tokens, string $file_path, bool $is_rsx_file): array
    {
        $violations = [];
        $has_disallowed_code = false;

        if (!$is_rsx_file) {
            return [
                'has_disallowed_code' => false,
                'violations' => [],
            ];
        }

        // In class files, only namespace, use, comments, and restricted includes are allowed outside the class
        // Functions and define() at global scope are violations
        if (!empty($structure['global_functions'])) {
            foreach ($structure['global_functions'] as $func_name) {
                $violations[] = [
                    'function' => $func_name,
                    'message' => "Global function '{$func_name}' not allowed in class file. Only namespace, use, comments, and include/require (with path restrictions) allowed outside class definition.",
                ];
            }
            $has_disallowed_code = true;
        }

        if (!empty($structure['global_constants'])) {
            foreach ($structure['global_constants'] as $const_name) {
                $violations[] = [
                    'constant' => $const_name,
                    'message' => "Global define() for '{$const_name}' not allowed in class file. Only namespace, use, comments, and include/require (with path restrictions) allowed outside class definition.",
                ];
            }
            $has_disallowed_code = true;
        }

        // Check for include/require statements with invalid paths in class files
        $include_violations = self::__validate_include_paths($tokens);
        if (!empty($include_violations)) {
            $violations = array_merge($violations, $include_violations);
            $has_disallowed_code = true;
        }

        return [
            'has_disallowed_code' => $has_disallowed_code,
            'violations' => $violations,
        ];
    }

    /**
     * Validate include/require paths in class files
     */
    private static function __validate_include_paths(array $tokens): array
    {
        $violations = [];
        $check_brace_depth = 0;

        for ($i = 0; $i < count($tokens); $i++) {
            // Skip string interpolation braces
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CURLY_OPEN) {
                continue;
            }

            // Track brace depth to only check global scope
            if ($tokens[$i] === '{') {
                $check_brace_depth++;
            } elseif ($tokens[$i] === '}') {
                // Check if this is closing string interpolation
                $is_string_interpolation = false;
                for ($j = $i - 1; $j >= max(0, $i - 10); $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CURLY_OPEN) {
                        $is_string_interpolation = true;
                        break;
                    }
                }
                if (!$is_string_interpolation) {
                    $check_brace_depth--;
                }
            }

            // Only check includes at global scope (outside class definition)
            if ($check_brace_depth === 0 && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
                // Look for the path in the include/require statement
                $include_type = token_name($tokens[$i][0]);
                $path = null;

                // Find the string literal after include/require
                for ($j = $i + 1; $j < count($tokens) && $j < $i + 10; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $path = trim($tokens[$j][1], '\'"');
                        break;
                    }
                }

                // Check if path starts with rsx/ and doesn't contain /resource/
                if ($path && str_starts_with($path, 'rsx/') && !str_contains($path, '/resource/')) {
                    $line = $tokens[$i][2] ?? 0;
                    $violations[] = [
                        'line' => $line,
                        'message' => "{$include_type} with path '{$path}' not allowed. In class files, includes can only target paths that don't start with 'rsx/' (unless they contain '/resource/').",
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Validate structure for classless files
     */
    private static function __validate_classless_file_structure(array $structure, array $tokens, string $file_path, bool $is_rsx_file): array
    {
        $violations = [];
        $has_disallowed_code = false;
        $data = [];

        if (!$is_rsx_file) {
            return [
                'has_disallowed_code' => false,
                'violations' => [],
                'global_functions' => $structure['global_functions'],
                'global_constants' => $structure['global_constants'],
            ];
        }

        // Track scope for validation
        $validation_brace_depth = 0;
        $in_function_scope = false;
        $in_define_call = false;
        $define_paren_depth = 0;

        for ($i = 0; $i < count($tokens); $i++) {
            // Skip string interpolation braces
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CURLY_OPEN) {
                continue;
            }

            // Track braces for scope
            if ($tokens[$i] === '{') {
                $validation_brace_depth++;
            } elseif ($tokens[$i] === '}') {
                // Check if this is closing string interpolation
                $is_string_interpolation = false;
                for ($j = $i - 1; $j >= max(0, $i - 10); $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CURLY_OPEN) {
                        $is_string_interpolation = true;
                        break;
                    }
                }
                if (!$is_string_interpolation) {
                    $validation_brace_depth--;
                    if ($validation_brace_depth === 0) {
                        $in_function_scope = false;
                    }
                }
            }

            // Track if we're entering a function
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION && $validation_brace_depth === 0) {
                // Find the opening brace for this function
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j] === '{') {
                        $in_function_scope = true;
                        break;
                    }
                }
                continue;
            }

            // Track if this is a define() call
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING &&
                $tokens[$i][1] === 'define' && $validation_brace_depth === 0) {
                // Check if next non-whitespace is '('
                $next_pos = $i + 1;
                while ($next_pos < count($tokens) && is_array($tokens[$next_pos]) &&
                       $tokens[$next_pos][0] === T_WHITESPACE) {
                    $next_pos++;
                }
                if ($next_pos < count($tokens) && $tokens[$next_pos] === '(') {
                    $in_define_call = true;
                    $define_paren_depth = 0;
                }
                continue;
            }

            // Track parentheses for define() calls
            if ($in_define_call) {
                if ($tokens[$i] === '(') {
                    $define_paren_depth++;
                } elseif ($tokens[$i] === ')') {
                    $define_paren_depth--;
                    if ($define_paren_depth === 0) {
                        $in_define_call = false;
                    }
                }
                continue; // Skip validation inside define() calls
            }

            // Skip validation inside functions
            if ($in_function_scope) {
                continue;
            }

            // Check if this token is allowed at global scope
            if (is_array($tokens[$i])) {
                $token_type = $tokens[$i][0];
                $token_name = token_name($token_type);

                // Check for specifically disallowed constructs at global scope
                $disallowed_at_global = [
                    T_ECHO, T_PRINT,      // No output at global scope
                    T_IF, T_ELSE, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_DO, T_SWITCH, // No control structures at global
                    T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, // No includes
                    T_EVAL,               // Never eval
                    T_EXIT,               // No exit/die at global (T_DIE is an alias for T_EXIT)
                    T_GLOBAL,             // No global keyword needed
                    T_NEW,                // No instantiation at global scope
                ];

                if (in_array($token_type, $disallowed_at_global)) {
                    $line = $tokens[$i][2] ?? 0;
                    $rationale = ' Classless PHP files are loaded on every request and should only define functions/constants, not execute code. ' .
                                'Code that runs on every request should be in Main::init(), pre_dispatch() methods, or implemented in Laravel outside RSX.';
                    $violations[] = [
                        'line' => $line,
                        'token' => $token_name,
                        'message' => "Disallowed construct at global scope: {$token_name}." . $rationale,
                    ];
                    $has_disallowed_code = true;
                }

                // Special check for function calls at global scope (other than define)
                if ($token_type === T_STRING && $tokens[$i][1] !== 'define') {
                    // Check if next non-whitespace token is '('
                    $next_token_pos = $i + 1;
                    while ($next_token_pos < count($tokens) &&
                           is_array($tokens[$next_token_pos]) &&
                           $tokens[$next_token_pos][0] === T_WHITESPACE) {
                        $next_token_pos++;
                    }

                    if ($next_token_pos < count($tokens) && $tokens[$next_token_pos] === '(') {
                        // This looks like a function call at global scope
                        $line = $tokens[$i][2] ?? 0;
                        $rationale = ' Classless PHP files are loaded on every request and should only define functions/constants, not execute code. ' .
                                    'Code that runs on every request should be in Main::init(), pre_dispatch() methods, or implemented in Laravel outside RSX.';
                        $violations[] = [
                            'line' => $line,
                            'function' => $tokens[$i][1],
                            'message' => "Function call at global scope not allowed: {$tokens[$i][1]}()." . $rationale,
                        ];
                        $has_disallowed_code = true;
                    }
                }
            }
        }

        // Store metadata about classless file
        $data = [];
        if (!empty($structure['global_functions'])) {
            $data['global_functions'] = $structure['global_functions'];
        }

        if (!empty($structure['global_constants'])) {
            $data['global_constants'] = $structure['global_constants'];
        }

        return [
            'has_disallowed_code' => $has_disallowed_code,
            'violations' => $violations,
            'global_functions' => $structure['global_functions'],
            'global_constants' => $structure['global_constants'],
        ];
    }

    /**
     * Assemble final metadata from all parsing/validation steps
     */
    private static function __assemble_metadata(
        array $data,
        array $structure,
        array $fqcn_data,
        array $validation_data,
        string $file_path
    ): array {
        // Check for multiple classes in PHP file
        if (count($structure['classes_found']) > 1) {
            $class_names = array_column($structure['classes_found'], 'name');
            ManifestErrors::multiple_classes_in_file($file_path, $class_names);
        }

        // Add structure violations if any
        if ($validation_data['has_disallowed_code']) {
            $data['structure_violations'] = $validation_data['violations'];
        }

        // Add FQCN violations if any
        if ($fqcn_data['has_rsx_fqcn_usage']) {
            $data['rsx_fqcn_violations'] = $fqcn_data['rsx_fqcn_violations'];
        }

        // Add classless file metadata
        if (isset($validation_data['global_functions'])) {
            $data['global_functions'] = $validation_data['global_functions'];
        }
        if (isset($validation_data['global_constants'])) {
            $data['global_constants'] = $validation_data['global_constants'];
        }

        // Add class metadata
        if ($structure['class_name']) {
            $data['class'] = $structure['class_name'];
            // Only set namespace if we found one
            if ($structure['namespace'] !== '') {
                $data['namespace'] = $structure['namespace'];
            }
            // Build FQCN from namespace
            $final_namespace = $structure['namespace'] ?: ($data['namespace'] ?? '');
            $data['fqcn'] = $final_namespace ? $final_namespace . '\\' . $structure['class_name'] : $structure['class_name'];

            if ($structure['extends_class']) {
                $data['extends'] = $structure['extends_class'];
            }

            // Add is_trait flag if this is a trait
            if (!empty($structure['classes_found'])) {
                $first_class = $structure['classes_found'][0];
                if (isset($first_class['is_trait']) && $first_class['is_trait']) {
                    $data['is_trait'] = true;
                }
            }
        }

        return $data;
    }

    /**
     * Extract static properties from PHP code with value assignment information and comments
     */
    private static function __extract_static_properties(string $content, array $tokens): array
    {
        $static_properties = [];
        $class_found = false;
        $in_class = false;
        $brace_depth = 0;

        for ($i = 0; $i < count($tokens); $i++) {
            // Track when we find a class declaration
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                // Check if this is ::class constant reference or a real class declaration
                $is_class_constant = false;
                if ($i > 0) {
                    // Look back for :: (T_DOUBLE_COLON)
                    $j = $i - 1;
                    while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j--;
                    }
                    if ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                        $is_class_constant = true;
                    }
                }

                // Only track real class declarations, not ::class constants
                if (!$is_class_constant) {
                    $class_found = true;
                    $brace_depth = 0;
                }
            }

            // Skip string interpolation braces
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CURLY_OPEN) {
                continue;
            }

            // Track braces
            if ($tokens[$i] === '{') {
                if ($class_found && $brace_depth === 0) {
                    // This is the opening brace of the class
                    $in_class = true;
                    $class_found = false;
                }
                if ($in_class) {
                    $brace_depth++;
                }
            } elseif ($tokens[$i] === '}') {
                // Check if this is closing string interpolation
                $is_string_interpolation = false;
                for ($j = $i - 1; $j >= max(0, $i - 10); $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CURLY_OPEN) {
                        $is_string_interpolation = true;
                        break;
                    }
                }
                if (!$is_string_interpolation && $in_class) {
                    $brace_depth--;
                    if ($brace_depth === 0) {
                        $in_class = false;
                    }
                }
            }

            // Look for static property declarations inside class
            if ($in_class && $brace_depth === 1 && is_array($tokens[$i]) && $tokens[$i][0] === T_STATIC) {
                // Look for the visibility modifier before T_STATIC
                $visibility = 'public'; // Default visibility
                $visibility_index = $i - 1;

                // Skip whitespace backwards
                while ($visibility_index >= 0 && is_array($tokens[$visibility_index]) && $tokens[$visibility_index][0] === T_WHITESPACE) {
                    $visibility_index--;
                }

                // Check for visibility modifier
                if ($visibility_index >= 0 && is_array($tokens[$visibility_index])) {
                    if ($tokens[$visibility_index][0] === T_PUBLIC) {
                        $visibility = 'public';
                    } elseif ($tokens[$visibility_index][0] === T_PROTECTED) {
                        $visibility = 'protected';
                    } elseif ($tokens[$visibility_index][0] === T_PRIVATE) {
                        $visibility = 'private';
                    }
                }

                // Now look for the variable name after T_STATIC
                $j = $i + 1;

                // Skip whitespace
                while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }

                // Check if it's a variable (property)
                if ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                    $property_name = substr($tokens[$j][1], 1); // Remove the $ prefix

                    // Now check if there's an assignment (=) after the variable
                    $has_value = false;
                    $k = $j + 1;

                    // Skip whitespace
                    while ($k < count($tokens) && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        $k++;
                    }

                    // Check for assignment operator
                    if ($k < count($tokens) && $tokens[$k] === '=') {
                        $has_value = true;
                    }

                    // Extract comment if property has no value
                    $comment = true;
                    if (!$has_value) {
                        // Look backwards for a comment
                        $comment_index = $visibility_index - 1;

                        // Skip whitespace backwards
                        while ($comment_index >= 0 && is_array($tokens[$comment_index]) && $tokens[$comment_index][0] === T_WHITESPACE) {
                            // Check if whitespace contains only newlines and spaces (no actual content)
                            if (trim($tokens[$comment_index][1], "\n\r\t ") === '') {
                                $comment_index--;
                            } else {
                                break;
                            }
                        }

                        // Check for doc comment or regular comment
                        if ($comment_index >= 0 && is_array($tokens[$comment_index])) {
                            if ($tokens[$comment_index][0] === T_DOC_COMMENT) {
                                // Extract and clean the doc comment
                                $doc_comment = $tokens[$comment_index][1];
                                // Remove /** and */ and clean up
                                $doc_comment = preg_replace('/^\/\*\*/', '', $doc_comment);
                                $doc_comment = preg_replace('/\*\/$/', '', $doc_comment);
                                // Remove leading asterisks from each line
                                $lines = explode("\n", $doc_comment);
                                $cleaned_lines = [];
                                foreach ($lines as $line) {
                                    $line = trim($line);
                                    $line = preg_replace('/^\*\s?/', '', $line);
                                    if ($line !== '') {
                                        $cleaned_lines[] = $line;
                                    }
                                }
                                $comment = implode(' ', $cleaned_lines);
                                if (empty($comment)) {
                                    $comment = true;
                                }
                            } elseif ($tokens[$comment_index][0] === T_COMMENT) {
                                // Extract and clean regular comment
                                $regular_comment = $tokens[$comment_index][1];
                                // Remove // or # and trim
                                $regular_comment = preg_replace('/^(\/\/|#)\s?/', '', $regular_comment);
                                $comment = trim($regular_comment);
                                if (empty($comment)) {
                                    $comment = true;
                                }
                            }
                        }
                    }

                    // Store the property information
                    $static_properties[$property_name] = [
                        'has_value' => $has_value,
                        'comment' => $has_value ? null : $comment, // Only store comment if no value
                        'visibility' => $visibility,
                    ];
                }
            }
        }

        return $static_properties;
    }

    /**
     * Convert decorator format from parser to compact array format
     * @param array $decorators Array of decorator objects from parser
     * @return array Compact array format [[name, [args]], ...]
     */
    public static function compact_decorators(array $decorators): array
    {
        $compact = [];
        foreach ($decorators as $decorator) {
            $name = $decorator['name'] ?? 'unknown';
            $arguments = $decorator['arguments'] ?? [];
            $compact[] = [$name, $arguments];
        }

        return $compact;
    }
}
