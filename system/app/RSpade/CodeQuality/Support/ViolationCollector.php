<?php

namespace App\RSpade\CodeQuality\Support;

use App\RSpade\CodeQuality\CodeQuality_Violation;

#[Instantiatable]
class ViolationCollector
{
    private array $violations = [];

    private array $convention_violations = [];

    private array $stats = [
        'critical' => 0,
        'high' => 0,
        'medium' => 0,
        'low' => 0,
        'convention' => 0,
    ];

    public function add(CodeQuality_Violation $violation): void
    {
        if ($violation->severity === 'convention') {
            $this->convention_violations[] = $violation;
            $this->stats['convention']++;
        } else {
            $this->violations[] = $violation;
            $this->stats[$violation->severity]++;
        }
    }

    public function clear(): void
    {
        $this->violations = [];
        $this->convention_violations = [];
        $this->stats = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'convention' => 0,
        ];
    }

    public function get_all(): array
    {
        return $this->violations;
    }

    public function get_by_file(string $file_path): array
    {
        return array_filter($this->violations, fn ($v) => $v->file_path === $file_path);
    }

    public function get_by_rule(string $rule_id): array
    {
        return array_filter($this->violations, fn ($v) => $v->rule_id === $rule_id);
    }

    public function get_by_severity(string $severity): array
    {
        return array_filter($this->violations, fn ($v) => $v->severity === $severity);
    }

    /**
    * Get convention violation count
    */
    public function get_convention_count(): int
    {
        return count($this->convention_violations);
    }

    /**
    * Get convention violations only
    */
    public function get_convention_violations(): array
    {
        return $this->convention_violations;
    }

    /**
    * Get convention violations as arrays
    */
    public function get_convention_violations_as_arrays(): array
    {
        $arrays = [];
        foreach ($this->convention_violations as $violation) {
            $arrays[] = $violation->to_array();
        }

        return $arrays;
    }

    public function get_stats(): array
    {
        return $this->stats;
    }

    public function get_total_count(): int
    {
        return count($this->violations);
    }

    /**
    * Get all violations as arrays (for backward compatibility)
    */
    public function get_violations_as_arrays(): array
    {
        $arrays = [];
        foreach ($this->violations as $violation) {
            $arrays[] = $violation->to_array();
        }

        return $arrays;
    }

    /**
    * Check if there are convention violations
    */
    public function has_convention_violations(): bool
    {
        return !empty($this->convention_violations);
    }

    public function has_critical_violations(): bool
    {
        return $this->stats['critical'] > 0;
    }

    public function has_violations(): bool
    {
        return !empty($this->violations);
    }

    public function merge(ViolationCollector $other): void
    {
        foreach ($other->get_all() as $violation) {
            $this->add($violation);
        }
        foreach ($other->get_convention_violations() as $violation) {
            $this->add($violation);
        }
    }

    public function sort_by_severity(): void
    {
        usort($this->violations, function ($a, $b) {
            $weight_diff = $b->get_severity_weight() - $a->get_severity_weight();
            if ($weight_diff !== 0) {
                return $weight_diff;
            }
            // Secondary sort by file and line
            $file_cmp = strcmp($a->file_path, $b->file_path);
            if ($file_cmp !== 0) {
                return $file_cmp;
            }

            return $a->line_number - $b->line_number;
        });
    }
}
