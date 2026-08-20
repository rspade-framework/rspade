<?php

namespace App\RSpade\SchemaQuality\Rules;

class BooleanColumnTypeRule extends Schema_Rule_Abstract
{
    public function get_id(): string
    {
        return 'SCHEMA-TYPE-02';
    }
    
    public function get_name(): string
    {
        return 'Boolean Column Type Rule';
    }
    
    public function get_description(): string
    {
        return 'Ensures columns starting with is_ are tinyint(1) type';
    }
    
    public function check(array $schema): void
    {
        foreach ($schema['tables'] as $table_name => $table_info) {
            if ($this->is_excluded_table($table_name)) {
                continue;
            }
            
            foreach ($table_info['columns'] as $column) {
                // Check if column name starts with is_
                if (str_starts_with($column['name'], 'is_')) {
                    // Check if it's tinyint(1)
                    $expected_type = 'tinyint(1)';
                    $actual_type = strtolower($column['type']);
                    
                    if ($actual_type !== $expected_type && $actual_type !== 'tinyint') {
                        $this->add_violation(
                            $table_name,
                            $column['name'],
                            "Column {$column['name']} starts with is_ but has type {$column['type']} instead of tinyint(1)",
                            "ALTER TABLE {$table_name} MODIFY {$column['name']} TINYINT(1)" . 
                            ($column['nullable'] === 'YES' ? ' NULL' : ' NOT NULL') .
                            ($column['default'] !== null ? " DEFAULT {$column['default']}" : '')
                        );
                    }
                }
            }
        }
    }
}