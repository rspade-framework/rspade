<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\Core\Database\ModelHelper;

class DbTableUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-DB-01';
    }
    
    public function get_name(): string
    {
        return 'DB::table() Usage Check';
    }
    
    public function get_description(): string
    {
        return 'Enforces ORM pattern - no direct query builder access via DB::table()';
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
     * Check for DB::table() usage and enforce ORM model usage (from line 1814)
     * Enforces ORM pattern - no direct query builder access via DB::table()
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor directories
        if (str_contains($file_path, '/vendor/')) {
            return;
        }
        
        // Skip migration files - they legitimately use DB::table() for schema operations
        if (str_contains($file_path, '/database/migrations/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Skip InspectCommand.php - it documents what the checks do
        if (str_contains($file_path, 'InspectCommand.php')) {
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
            
            // Skip if the line is empty in sanitized version (was a comment)
            if (trim($sanitized_line) === '') {
                continue;
            }
            
            // Check for DB::table( usage in sanitized content
            if (preg_match('/DB\s*::\s*table\s*\(/i', $sanitized_line)) {
                // Try to extract table name from the parameter (use sanitized line)
                $table_name = null;
                if (preg_match('/DB\s*::\s*table\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/i', $sanitized_line, $matches)) {
                    $table_name = $matches[1];
                }
                
                // Skip Laravel's internal migrations table
                if ($table_name === 'migrations') {
                    continue;
                }

                // Skip framework internal tables (prefixed with underscore)
                // These are low-level system tables managed directly for performance
                if (str_starts_with($table_name, '_')) {
                    continue;
                }
                
                // Use original line for display in error message
                $original_line = $original_lines[$line_num] ?? $sanitized_line;
                
                // Determine which directory to suggest for model creation
                $model_location = str_contains($file_path, '/rsx/') ? './rsx/models' : './app/Models';
                
                // Build resolution message based on whether we found the table name
                $resolution = "Direct database table access via DB::table() violates framework architecture principles.\n\n";
                
                if ($table_name) {
                    // Check if a model exists for this table
                    $model_exists = false;
                    $model_class = null;
                    
                    try {
                        $model_class = ModelHelper::get_model_by_table($table_name);
                        $model_exists = true;
                    } catch (\Exception $e) {
                        // Model doesn't exist
                        $model_exists = false;
                    }
                    
                    if ($model_exists) {
                        $resolution .= "RECOMMENDED SOLUTION:\n";
                        $resolution .= "Use the existing '{$model_class}' model instead of DB::table('{$table_name}').\n";
                        $resolution .= "Example: {$model_class}::where('column', \$value)->get();\n\n";
                    } else {
                        $resolution .= "RECOMMENDED SOLUTION:\n";
                        $resolution .= "No model detected for table '{$table_name}'. Create a new model class extending Rsx_Model_Abstract in {$model_location}.\n";
                        $resolution .= "Example model definition:\n";
                        $resolution .= "  class " . ucfirst(str_replace('_', '', ucwords($table_name, '_'))) . " extends Rsx_Model_Abstract {\n";
                        $resolution .= "      protected \$table = '{$table_name}';\n";
                        $resolution .= "  }\n\n";
                    }
                } else {
                    $resolution .= "RECOMMENDED SOLUTION:\n";
                    $resolution .= "Create an ORM model extending Rsx_Model_Abstract in {$model_location} for the target table.\n\n";
                }
                
                $resolution .= "ALTERNATIVE (for complex reporting queries only):\n";
                $resolution .= "If ORM is genuinely inappropriate due to query complexity (e.g., multi-table aggregations, complex reporting):\n";
                $resolution .= "- Use DB::select() with raw SQL and prepared parameters for read operations\n";
                $resolution .= "- Use DB::statement() with prepared parameters for write operations\n";
                $resolution .= "Example: DB::select('SELECT * FROM table WHERE id = ?', [\$id]);\n\n";
                $resolution .= "RATIONALE:\n";
                $resolution .= "- ORM models provide data integrity, relationships, and business logic encapsulation\n";
                $resolution .= "- Query builder (DB::table()) offers no advantages over raw queries for complex operations\n";
                $resolution .= "- Consistent use of models maintains architectural coherence";
                
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "DB::table() usage detected. Framework requires ORM models for database access.",
                    trim($original_line),
                    $resolution,
                    'high'
                );
            }
        }
    }
}