<?php

namespace App\RSpade\SchemaQuality;

class SchemaViolation
{
    public function __construct(
        public string $rule_id,
        public string $table,
        public ?string $column,
        public string $message,
        public string $recommendation,
        public string $severity = 'high'
    ) {}
    
    public function to_array(): array
    {
        return [
            'rule_id' => $this->rule_id,
            'table' => $this->table,
            'column' => $this->column,
            'message' => $this->message,
            'recommendation' => $this->recommendation,
            'severity' => $this->severity,
        ];
    }
    
    public function format_output(): string
    {
        $output = "  [ERROR] [{$this->rule_id}] Table: {$this->table}";
        if ($this->column) {
            $output .= ", Column: {$this->column}";
        }
        $output .= "\n     {$this->message}\n";
        $output .= "     TIP: {$this->recommendation}\n";
        return $output;
    }
}