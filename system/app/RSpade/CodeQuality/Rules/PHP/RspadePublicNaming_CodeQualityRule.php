<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class RspadePublicNaming_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-RSPADE-02';
    }
    
    public function get_name(): string
    {
        return 'RSpade Public Method Naming Convention';
    }
    
    public function get_description(): string
    {
        return 'Ensures public static methods in RSpade framework do not start with double underscore';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }
    
    public function get_default_severity(): string
    {
        return 'high';
    }
    
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only apply to files in app/RSpade directory
        if (!str_contains($file_path, '/app/RSpade/') && !str_contains($file_path, 'app/RSpade/')) {
            return;
        }
        
        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }

        // Skip CodeQuality and SchemaQuality directories - they have their own conventions
        if (str_contains($file_path, '/CodeQuality/') || str_contains($file_path, '/SchemaQuality/')) {
            return;
        }
        
        // Get both original and sanitized content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);
        
        // Get sanitized content with comments removed
        $sanitized_data = FileSanitizer::sanitize_php($contents);
        $sanitized_lines = $sanitized_data['lines'];
        
        foreach ($sanitized_lines as $line_num => $sanitized_line) {
            $line_number = $line_num + 1;
            
            // Skip if the line is empty in sanitized version
            if (trim($sanitized_line) === '') {
                continue;
            }
            
            // Check for public static function
            if (preg_match('/\bpublic\s+static\s+function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $sanitized_line, $matches)) {
                $function_name = $matches[1];

                // Check if function name starts with double underscore
                if (str_starts_with($function_name, '__')) {
                    // PHP magic methods are exempt - they must have double underscores
                    $php_magic_methods = [
                        '__construct', '__destruct', '__call', '__callStatic',
                        '__get', '__set', '__isset', '__unset',
                        '__sleep', '__wakeup', '__serialize', '__unserialize',
                        '__toString', '__invoke', '__set_state', '__clone',
                        '__debugInfo'
                    ];

                    if (in_array($function_name, $php_magic_methods)) {
                        continue; // Magic methods are exempt
                    }

                    $original_line = $original_lines[$line_num] ?? $sanitized_line;

                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Public static method '{$function_name}' in RSpade framework must not start with double underscore.",
                        trim($original_line),
                        "Remove one underscore from the method name (change '{$function_name}' to '" . substr($function_name, 1) . "'). " .
                        "Public static methods in the app/RSpade directory are part of the public API and should start with " .
                        "no underscore or a single underscore only. Double underscores are reserved for private/protected methods.",
                        'high'
                    );
                }
            }
        }
    }
}