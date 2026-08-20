<?php

namespace App\RSpade\SchemaQuality\Rules;

use App\RSpade\SchemaQuality\SchemaViolation;

abstract class Schema_Rule_Abstract
{
    protected array $violations = [];
    protected array $excluded_tables = ['_migrations', '_sessions'];
    
    /**
     * Get the unique rule identifier
     */
    abstract public function get_id(): string;
    
    /**
     * Get human-readable rule name
     */
    abstract public function get_name(): string;
    
    /**
     * Get rule description
     */
    abstract public function get_description(): string;
    
    /**
     * Check the schema for violations
     * 
     * @param array $schema Database schema information
     */
    abstract public function check(array $schema): void;
    
    /**
     * Check if a table should be excluded from checks
     */
    protected function is_excluded_table(string $table): bool
    {
        return in_array($table, $this->excluded_tables);
    }
    
    /**
     * Add a violation
     */
    protected function add_violation(
        string $table,
        ?string $column,
        string $message,
        string $recommendation,
        string $severity = 'high'
    ): void {
        $this->violations[] = new SchemaViolation(
            rule_id: $this->get_id(),
            table: $table,
            column: $column,
            message: $message,
            recommendation: $recommendation,
            severity: $severity
        );
    }
    
    /**
     * Get all violations found
     */
    public function get_violations(): array
    {
        return $this->violations;
    }
    
    /**
     * Clear violations
     */
    public function clear_violations(): void
    {
        $this->violations = [];
    }
}