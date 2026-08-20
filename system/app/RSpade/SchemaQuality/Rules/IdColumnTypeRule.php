<?php

namespace App\RSpade\SchemaQuality\Rules;

class IdColumnTypeRule extends Schema_Rule_Abstract
{
    public function get_id(): string
    {
        return 'SCHEMA-TYPE-01';
    }
    
    public function get_name(): string
    {
        return 'ID Column Type Rule';
    }
    
    public function get_description(): string
    {
        return 'Ensures columns ending in _id are integer or bigint type';
    }
    
    public function check(array $schema): void
    {
        foreach ($schema['tables'] as $table_name => $table_info) {
            if ($this->is_excluded_table($table_name)) {
                continue;
            }
            
            foreach ($table_info['columns'] as $column) {
                // Check if column name ends with _id
                if (str_ends_with($column['name'], '_id')) {
                    // Exception: session_id is always VARCHAR to match sessions.id
                    if ($column['name'] === 'session_id') {
                        continue;
                    }
                    
                    // Extract the base type without size/precision
                    $base_type = strtolower(preg_replace('/\(.*\)/', '', $column['type']));
                    
                    // Check if it's an integer type
                    $valid_types = ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint'];
                    if (!in_array($base_type, $valid_types)) {
                        $this->add_violation(
                            $table_name,
                            $column['name'],
                            "Column {$column['name']} ends with _id but has type {$column['type']} instead of integer/bigint",
                            "ALTER TABLE {$table_name} MODIFY {$column['name']} BIGINT" . 
                            ($column['nullable'] === 'YES' ? ' NULL' : ' NOT NULL')
                        );
                    }
                }
            }
        }
    }
}