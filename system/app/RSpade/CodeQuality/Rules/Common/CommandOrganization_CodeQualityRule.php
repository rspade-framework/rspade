<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

class CommandOrganization_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'CMD-ORG-01';
    }
    
    public function get_name(): string
    {
        return 'Command Organization Check';
    }
    
    public function get_description(): string
    {
        return 'Ensures commands are organized in proper subdirectories based on signature';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.php'];
    }
    
    public function get_default_severity(): string
    {
        return 'high';
    }
    
    /**
     * Special method for checking commands organization
     * Only runs when using default paths
     */
    public function check_commands(): void
    {
        // Only run when using default paths
        if (!($this->config['using_default_paths'] ?? false)) {
            return;
        }
        
        $commands_dir = base_path('app/Console/Commands');
        if (!is_dir($commands_dir)) {
            return;
        }
        
        // Get files in root Commands directory only (not subdirectories)
        $files = glob($commands_dir . '/*.php');
        
        foreach ($files as $file) {
            $filename = basename($file);
            $content = file_get_contents($file);
            
            // Check for rsx: commands
            if (preg_match('/\$signature\s*=\s*[\'"]rsx:/i', $content)) {
                $this->add_violation(
                    $file,
                    0,
                    "RSX command found in root Commands directory",
                    "File: $filename contains 'rsx:' command signature",
                    "COMMAND ORGANIZATION VIOLATION\n\n" .
                    "This command has signature starting with 'rsx:' but is not in the Rsx subdirectory.\n\n" .
                    "REQUIRED ACTION:\n" .
                    "Move this file to app/RSpade/Commands/Rsx/ or one of its subdirectories.\n\n" .
                    "WHY THIS MATTERS:\n" .
                    "- All RSX framework commands must be organized in the Rsx subdirectory\n" .
                    "- This keeps framework commands separate from application commands\n" .
                    "- Maintains consistent command organization\n" .
                    "- Makes it easier to find related commands\n\n" .
                    "STEPS TO FIX:\n" .
                    "1. Move the file: mv $file " . base_path('app/RSpade/Commands/Rsx/') . "\n" .
                    "2. Update the namespace to include \\Rsx\n" .
                    "3. Run 'composer dump-autoload' to update class mappings",
                    'high'
                );
            }
            
            // Check for migrate: commands
            if (preg_match('/\$signature\s*=\s*[\'"]migrate:/i', $content)) {
                $this->add_violation(
                    $file,
                    0,
                    "Migration command found in root Commands directory",
                    "File: $filename contains 'migrate:' command signature",
                    "COMMAND ORGANIZATION VIOLATION\n\n" .
                    "This command has signature starting with 'migrate:' but is not in the Migrate subdirectory.\n\n" .
                    "REQUIRED ACTION:\n" .
                    "Move this file to app/Console/Commands/Migrate/ or one of its subdirectories.\n\n" .
                    "WHY THIS MATTERS:\n" .
                    "- All migration-related commands must be organized in the Migrate subdirectory\n" .
                    "- This groups database migration tools together\n" .
                    "- Maintains consistent command organization\n" .
                    "- Makes it easier to find migration-related commands\n\n" .
                    "STEPS TO FIX:\n" .
                    "1. Move the file: mv $file " . $commands_dir . "/Migrate/\n" .
                    "2. Update the namespace to include \\Migrate\n" .
                    "3. Run 'composer dump-autoload' to update class mappings",
                    'high'
                );
            }
        }
    }
    
    /**
     * Standard check method - not used for this rule
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // This rule uses check_commands instead
    }
}