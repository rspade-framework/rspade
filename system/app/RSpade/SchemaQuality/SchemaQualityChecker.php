<?php

namespace App\RSpade\SchemaQuality;

use App\RSpade\SchemaQuality\Rules\Schema_Rule_Abstract;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Manifest\Manifest;

class SchemaQualityChecker
{
    protected array $violations = [];
    protected array $rules = [];
    
    public function __construct()
    {
        $this->load_rules();
    }
    
    /**
     * Load all schema rules
     */
    protected function load_rules(): void
    {
        // Directly instantiate known rules
        // (Manifest auto-discovery not working for this directory yet)
        $rule_classes = [
            \App\RSpade\SchemaQuality\Rules\SessionIdForeignKeyRule::class,
            \App\RSpade\SchemaQuality\Rules\IdColumnTypeRule::class,
            \App\RSpade\SchemaQuality\Rules\BooleanColumnTypeRule::class,
        ];
        
        foreach ($rule_classes as $rule_class) {
            if (!class_exists($rule_class)) {
                throw new \RuntimeException(
                    "Fatal: SchemaQualityChecker expected rule class '{$rule_class}' to exist but it was not found. " .
                    "This is a framework integrity error - all schema quality rules must exist."
                );
            }
            $this->rules[] = new $rule_class();
        }
    }
    
    /**
     * Run all schema checks
     */
    public function check(): array
    {
        $this->violations = [];
        
        // Get database schema information
        $schema = $this->get_database_schema();
        
        // Run each rule
        foreach ($this->rules as $rule) {
            $rule->clear_violations();
            $rule->check($schema);
            
            // Collect violations
            foreach ($rule->get_violations() as $violation) {
                $this->violations[] = $violation;
            }
        }
        
        return $this->violations;
    }
    
    /**
     * Get database schema information
     */
    protected function get_database_schema(): array
    {
        $schema = ['tables' => []];
        
        // Get all tables
        $tables = DB::select("SHOW TABLES");
        $db_name = DB::getDatabaseName();
        $table_key = "Tables_in_{$db_name}";
        
        foreach ($tables as $table) {
            $table_name = $table->$table_key;
            
            // Get columns
            $columns = DB::select("SHOW COLUMNS FROM `{$table_name}`");
            $column_data = [];
            foreach ($columns as $column) {
                $column_data[] = [
                    'name' => $column->Field,
                    'type' => $column->Type,
                    'nullable' => $column->Null,
                    'key' => $column->Key,
                    'default' => $column->Default,
                    'extra' => $column->Extra,
                ];
            }
            
            // Get foreign keys
            $fk_query = "
                SELECT 
                    k.COLUMN_NAME as column_name,
                    k.REFERENCED_TABLE_NAME as referenced_table,
                    k.REFERENCED_COLUMN_NAME as referenced_column,
                    k.CONSTRAINT_NAME as constraint_name,
                    r.DELETE_RULE as delete_rule
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r 
                    ON k.CONSTRAINT_NAME = r.CONSTRAINT_NAME 
                    AND k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
                WHERE k.TABLE_SCHEMA = ? 
                    AND k.TABLE_NAME = ?
                    AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ";
            
            $foreign_keys = DB::select($fk_query, [$db_name, $table_name]);
            $fk_data = [];
            foreach ($foreign_keys as $fk) {
                $fk_data[] = [
                    'column' => $fk->column_name,
                    'referenced_table' => $fk->referenced_table,
                    'referenced_column' => $fk->referenced_column,
                    'constraint_name' => $fk->constraint_name,
                    'delete_rule' => $fk->delete_rule,
                ];
            }
            
            $schema['tables'][$table_name] = [
                'columns' => $column_data,
                'foreign_keys' => $fk_data,
            ];
        }
        
        return $schema;
    }
    
    /**
     * Get violations grouped by severity
     */
    public function get_violations_by_severity(): array
    {
        $grouped = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];
        
        foreach ($this->violations as $violation) {
            $grouped[$violation->severity][] = $violation;
        }
        
        return $grouped;
    }
    
    /**
     * Check if there are any violations
     */
    public function has_violations(): bool
    {
        return count($this->violations) > 0;
    }
    
    /**
     * Get total violation count
     */
    public function get_violation_count(): int
    {
        return count($this->violations);
    }
    
    /**
     * Format violations for display
     */
    public function format_violations(): string
    {
        if (empty($this->violations)) {
            return "[OK] No schema violations found.\n";
        }
        
        $output = "[ERROR] Found " . count($this->violations) . " schema violation(s):\n\n";
        
        $grouped = $this->get_violations_by_severity();
        
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            if (!empty($grouped[$severity])) {
                $output .= strtoupper($severity) . " VIOLATIONS:\n";
                foreach ($grouped[$severity] as $violation) {
                    $output .= $violation->format_output();
                }
                $output .= "\n";
            }
        }
        
        return $output;
    }
}