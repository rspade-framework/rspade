<?php
/**
 * PHP CS Fixer Configuration for RSpade Framework
 * 
 * This configuration enforces:
 * - Modern PHP array syntax [] instead of array()
 * - PSR-12 coding standards
 * - Laravel/RSpade specific conventions
 * - underscore_case for methods/variables (where possible)
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/app',
        __DIR__ . '/rsx',
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->notPath('vendor')
    ->notPath('storage')
    ->notPath('bootstrap/cache')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$config = new PhpCsFixer\Config();

return $config
    ->setRules([
        // PSR-12 standard as baseline
        '@PSR12' => true,
        
        // Enforce modern array syntax
        'array_syntax' => ['syntax' => 'short'],
        
        // Ensure consistent array formatting
        'array_indentation' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays'],
        ],
        'no_whitespace_before_comma_in_array' => true,
        'whitespace_after_comma_in_array' => true,
        
        // Clean up imports
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['const', 'class', 'function'],
        ],
        'single_import_per_statement' => true,
        'fully_qualified_strict_types' => false,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        
        // Spacing and formatting
        'blank_line_after_opening_tag' => false,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try'],
        ],
        'no_extra_blank_lines' => [
            'tokens' => [
                'case',
                'continue',
                'curly_brace_block',
                'default',
                'extra',
                'parenthesis_brace_block',
                'square_brace_block',
                'switch',
                'throw',
                'use',
            ],
        ],
        'no_blank_lines_after_phpdoc' => true,
        
        // PHP language features
        'declare_strict_types' => false, // Don't force strict types
        'void_return' => false, // Don't force void returns
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'return_type_declaration' => [
            'space_before' => 'none',
        ],
        
        // String handling
        'single_quote' => true, // Prefer single quotes
        'escape_implicit_backslashes' => [
            'double_quoted' => true,
            'heredoc_syntax' => true,
            'single_quoted' => false,
        ],
        
        // Operators
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
        'concat_space' => ['spacing' => 'one'],
        'increment_style' => ['style' => 'post'],
        'not_operator_with_successor_space' => false,
        'object_operator_without_whitespace' => true,
        'ternary_operator_spaces' => true,
        'unary_operator_spaces' => true,
        
        // Class and method formatting
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
                'property' => 'one',
            ],
        ],
        'visibility_required' => [
            'elements' => ['property', 'method', 'const'],
        ],
        
        // Comments and PHPDoc
        'multiline_comment_opening_closing' => true,
        'no_empty_comment' => true,
        'no_trailing_whitespace_in_comment' => true,
        'single_line_comment_style' => [
            'comment_types' => ['hash'],
        ],
        
        // Control structures
        'elseif' => true,
        'no_alternative_syntax' => true,
        'switch_case_semicolon_to_colon' => true,
        'switch_case_space' => true,
        
        // Keep existing code organization
        'no_blank_lines_after_class_opening' => false,
        'blank_line_after_namespace' => true,
        
        // Don't change function/variable names (preserve underscore_case)
        // PHP CS Fixer doesn't have rules to enforce snake_case, which is good
        // as we want to preserve our existing naming conventions
    ])
    ->setRiskyAllowed(false) // Don't allow risky rules that might break code
    ->setFinder($finder)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');