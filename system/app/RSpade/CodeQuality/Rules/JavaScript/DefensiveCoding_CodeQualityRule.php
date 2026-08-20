<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class DefensiveCoding_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-DEFENSIVE-01';
    }
    
    public function get_name(): string
    {
        return 'JavaScript Defensive Coding Check';
    }
    
    public function get_description(): string
    {
        return 'Prohibits existence checks - code must fail loudly if dependencies are missing';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.js'];
    }
    
    public function get_default_severity(): string
    {
        return 'high';
    }
    
    /**
     * Check JavaScript file for defensive coding violations (from line 833)
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Get sanitized content
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $lines = $sanitized_data['lines'];
        
        foreach ($lines as $line_num => $line) {
            $line_number = $line_num + 1;

            // Skip if line has exception comment
            if (str_contains($line, '@' . $this->get_id() . '-EXCEPTION')) {
                continue;
            }

            // Skip comments
            $trimmed_line = trim($line);
            if (str_starts_with($trimmed_line, '//') || str_starts_with($trimmed_line, '*')) {
                continue;
            }
            
            // Pattern 1: typeof variable checks (!== undefined, === undefined, == 'function', etc.)
            // Match: typeof SomeVar !== 'undefined' or typeof SomeVar == 'function'
            if (preg_match('/typeof\s+(\w+)\s*([!=]=+)\s*[\'"]?(undefined|function)[\'"]?/i', $line, $matches)) {
                $variable = $matches[1];
                
                // Skip if it's a property check (contains dot)
                if (!str_contains($variable, '.')) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Defensive coding violation: Checking if '{$variable}' exists. All classes and variables must be assumed to exist. Code should fail loudly if something is undefined.",
                        trim($line),
                        "Remove the existence check. Let the code fail if '{$variable}' is not defined.",
                        'high'
                    );
                }
            }
            
            // Pattern 2: typeof window.variable checks
            if (preg_match('/typeof\s+window\.(\w+)\s*([!=]=+)\s*[\'"]?undefined[\'"]?/i', $line, $matches)) {
                $variable = 'window.' . $matches[1];
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Defensive coding violation: Checking if '{$variable}' exists. All global variables must be assumed to exist. Code should fail loudly if something is undefined.",
                    trim($line),
                    "Remove the existence check. Let the code fail if '{$variable}' is not defined.",
                    'high'
                );
            }
            
            // Pattern 3: if (variable) or if (!variable) existence checks (more careful pattern)
            // Only match simple variables, not property access
            if (preg_match('/if\s*\(\s*(!)?(\w+)\s*\)/', $line, $matches)) {
                $variable = $matches[2];
                
                // Skip if it's a property or array access or a boolean-like variable name
                if (!str_contains($line, '.' . $variable) && 
                    !str_contains($line, '[' . $variable) &&
                    !str_contains($line, $variable . '.') &&
                    !str_contains($line, $variable . '[') &&
                    !preg_match('/^(is|has|can|should|will|did|was)[A-Z]/', $variable) && // Skip boolean-named vars
                    !in_array(strtolower($variable), ['true', 'false', 'null', 'undefined'])) { // Skip literals
                    
                    // Check if this looks like an existence check by looking at context
                    if (preg_match('/if\s*\(\s*(!)?typeof\s+' . preg_quote($variable, '/') . '/i', $line) ||
                        preg_match('/if\s*\(\s*' . preg_quote($variable, '/') . '\s*&&\s*' . preg_quote($variable, '/') . '\./i', $line)) {
                        $this->add_violation(
                            $file_path,
                            $line_number,
                            "Defensive coding violation: Checking if '{$variable}' exists. All classes and variables must be assumed to exist. Code should fail loudly if something is undefined.",
                            trim($line),
                            "Remove the existence check. Let the code fail if '{$variable}' is not defined.",
                            'high'
                        );
                    }
                }
            }
            
            // Pattern 4: Guard clauses like: Rsx && Rsx.method() or component && component.val
            if (preg_match('/\b(\w+)\s*&&\s*\1\.\w+/i', $line, $matches)) {
                $variable = $matches[1];

                // Skip common boolean variable patterns
                if (preg_match('/^(is|has|can|should|will|did|was)[A-Z]/', $variable)) {
                    continue;
                }

                // Skip variables that commonly represent optional/polymorphic data (duck typing)
                $duck_typing_patterns = [
                    'result', 'results',
                    'response',
                    'data',
                    'error', 'errors',
                    'obj', 'object', 'objects',
                    'item', 'items',
                    'value', 'values',
                    'options', 'option',
                    'config',
                    'params', 'param',
                    'args', 'arg',
                    'settings',
                    'payload',
                    'context',
                    'metadata',
                ];

                if (in_array(strtolower($variable), $duck_typing_patterns)) {
                    // This is acceptable duck typing - checking for optional capabilities
                    continue;
                }

                // Flag potential defensive coding (core classes, components, etc.)
                $fix_message = "DETECTED PATTERN: if ({$variable} && {$variable}.method)\n\n" .
                    "This pattern has TWO possible meanings:\n" .
                    "1. DEFENSIVE CODING (violation): Checking for something that MUST exist\n" .
                    "2. DUCK TYPING (acceptable): Checking for optional capabilities\n\n" .
                    "FOR LLM AGENTS - YOU MUST NOT DECIDE:\n" .
                    "This rule requires human judgment. You MUST:\n" .
                    "1. Analyze the context and determine which scenario applies\n" .
                    "2. Present BOTH options:\n" .
                    "   - If defensive: Remove check, let it fail loud\n" .
                    "   - If duck typing: Use 'in' operator: if ('method' in {$variable})\n" .
                    "3. Provide your recommendation with reasoning\n" .
                    "4. WAIT for user decision\n" .
                    "5. NEVER apply a fix without user confirmation\n\n" .
                    "EXAMPLES:\n\n" .
                    "DEFENSIVE CODING (remove the check):\n" .
                    "  if (Rsx && Rsx.Route(...))  // BAD - Rsx is core, must exist\n" .
                    "  if (component && component.render())  // BAD if render is required method\n\n" .
                    "DUCK TYPING (acceptable with these variable names):\n" .
                    "  if (result && result.redirect)  // OK - result may have optional redirect\n" .
                    "  if (error && error.message)  // OK - polymorphic error handling\n" .
                    "  if (options && options.callback)  // OK - optional configuration\n\n" .
                    "NOTE: Core guaranteed classes (Rsx, Modal, Component, etc.) should NEVER be checked - let failures happen loudly during development.";

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Defensive coding violation: Guard clause checking if '{$variable}' exists. All classes and variables must be assumed to exist. Code should fail loudly if something is undefined.",
                    trim($line),
                    $fix_message,
                    'high'
                );
            }
            
            // Pattern 5: try/catch used for existence checking (simplified detection)
            if (preg_match('/try\s*\{.*?(\w+).*?\}\s*catch/i', $line, $matches)) {
                // This is a simplified check - in reality you'd need multi-line parsing
                // Skip for now as it's complex to detect intent
            }
        }
    }
}