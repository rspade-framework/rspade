<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Health_Check_Runner - discovery, invocation, and normalization for rsx:health.
 *
 * The command (Health_Command) is a thin formatter; ALL the logic lives here so it
 * is testable in-process. A health check is a public static method declared WHERE its
 * feature lives, tagged `#[Health_Check('Human Label')]`. Discovery is a manifest
 * attribute scan (Manifest::get_with_attribute) - never reflection getAttributes()
 * directly (PHP-ATTR-01), and never a defined attribute class (agnostic extraction).
 *
 * Return contract of a check method (a single assoc row OR a list of rows):
 *   ['status' => 'OK'|'WARN'|'FAIL'|'INFO', 'detail' => string,
 *    'remediation' => ?string, 'label' => ?string]
 *
 * A check that throws, returns an out-of-contract shape, or an invalid status becomes
 * a FAIL row naming the offender - the runner NEVER dies mid-report, so one broken
 * check cannot hide the rest of the inventory.
 */
class Health_Check_Runner
{
    /** The valid row statuses. */
    private const VALID_STATUSES = ['OK', 'WARN', 'FAIL', 'INFO'];

    /**
     * Run every discovered health check and return the normalized result rows.
     *
     * Each row: ['label' => string, 'status' => string, 'detail' => string,
     * 'remediation' => ?string]. Rows are grouped by check, checks ordered by label.
     *
     * @return array<int, array{label: string, status: string, detail: string, remediation: ?string}>
     */
    public static function run(): array
    {
        $rows = [];

        foreach (static::discover() as $check) {
            foreach (static::run_one($check['fqcn'], $check['method'], $check['label']) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Invoke a single check method and normalize its outcome into rows. A method that
     * throws becomes a single FAIL row carrying the exception message - a broken check
     * never aborts the surrounding report.
     *
     * @return array<int, array{label: string, status: string, detail: string, remediation: ?string}>
     */
    public static function run_one(string $fqcn, string $method, string $label): array
    {
        try {
            $result = $fqcn::$method();
        } catch (\Throwable $e) {
            return [[
                'label' => $label,
                'status' => 'FAIL',
                'detail' => $e->getMessage(),
                'remediation' => 'fix or remove the failing #[Health_Check] method',
            ]];
        }

        return static::normalize_check_result($result, $label, $fqcn . '::' . $method);
    }

    /**
     * Discover the health-check inventory from the manifest: every public static method
     * tagged `#[Health_Check('label')]`. A method with no label argument fails loud.
     *
     * @return array<int, array{fqcn: string, method: string, label: string}>
     */
    public static function discover(): array
    {
        $checks = [];

        foreach (Manifest::get_with_attribute('Health_Check') as $row) {
            // Only method-level attributes are health checks (a class-level #[Health_Check]
            // is meaningless - it has no method to invoke).
            if (($row['type'] ?? null) !== 'method') {
                continue;
            }

            $instances = $row['instances'] ?? [];
            $first = $instances[0] ?? null;
            $label = is_array($first) ? ($first[0] ?? null) : null;

            if (!is_string($label) || $label === '') {
                $fqcn = $row['fqcn'] ?? ($row['class'] ?? 'unknown');
                $method = $row['method'] ?? 'unknown';
                shouldnt_happen(
                    "#[Health_Check] on {$fqcn}::{$method} is missing its required label argument"
                    . " (usage: #[Health_Check('Human Label')])"
                );
            }

            $checks[] = [
                'fqcn' => $row['fqcn'],
                'method' => $row['method'],
                'label' => $label,
            ];
        }

        // Deterministic order for both the table and the JSON payload.
        usort($checks, static fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $checks;
    }

    /**
     * Normalize a single check's raw return value into a list of validated rows.
     *
     * Accepts a single assoc row (has a 'status' key) or a list of rows. Anything else -
     * a non-array, an empty list, an associative array without 'status', or an invalid
     * per-row shape/status - becomes a single FAIL row naming the offending check.
     *
     * @param mixed $result Raw return value of the check method.
     * @param string $attr_label The label from the #[Health_Check] attribute (used when a row names no label of its own).
     * @param string $identifier fqcn::method (for offender-naming in error rows).
     * @return array<int, array{label: string, status: string, detail: string, remediation: ?string}>
     */
    public static function normalize_check_result($result, string $attr_label, string $identifier): array
    {
        if (!is_array($result)) {
            return [static::__invalid_row(
                $attr_label,
                $identifier,
                'returned ' . gettype($result) . ' - expected a row array or a list of rows'
            )];
        }

        // Single assoc row vs a list of rows.
        if (array_key_exists('status', $result)) {
            $raw_rows = [$result];
        } elseif (array_is_list($result)) {
            if (empty($result)) {
                return [static::__invalid_row($attr_label, $identifier, 'returned an empty list of rows')];
            }
            $raw_rows = $result;
        } else {
            return [static::__invalid_row(
                $attr_label,
                $identifier,
                'returned an associative array without a status key'
            )];
        }

        $rows = [];
        $total = count($raw_rows);
        $index = 0;
        foreach ($raw_rows as $raw) {
            $index++;
            $rows[] = static::__normalize_one($raw, $attr_label, $identifier, $index, $total);
        }

        return $rows;
    }

    // =========================================================================
    // internals
    // =========================================================================

    /**
     * Validate and normalize one row. Bad shape/status -> a FAIL row naming the offender.
     *
     * @param mixed $raw
     * @return array{label: string, status: string, detail: string, remediation: ?string}
     */
    private static function __normalize_one($raw, string $attr_label, string $identifier, int $index, int $total): array
    {
        if (!is_array($raw) || !array_key_exists('status', $raw)) {
            return static::__invalid_row($attr_label, $identifier, 'row #' . $index . ' is not a valid row (missing status)');
        }

        $status = $raw['status'];
        if (!in_array($status, self::VALID_STATUSES, true)) {
            return static::__invalid_row(
                $attr_label,
                $identifier,
                'row #' . $index . ' has invalid status ' . var_export($status, true)
                . ' (expected OK|WARN|FAIL|INFO)'
            );
        }

        // Label: an explicit per-row label wins; otherwise inherit the attribute label,
        // suffixing #2, #3... for the second and later rows of a multi-row check.
        $label = $raw['label'] ?? null;
        if (!is_string($label) || $label === '') {
            $label = $attr_label;
            if ($total > 1 && $index >= 2) {
                $label .= ' #' . $index;
            }
        }

        $remediation = $raw['remediation'] ?? null;

        return [
            'label' => $label,
            'status' => $status,
            'detail' => (string) ($raw['detail'] ?? ''),
            'remediation' => ($remediation === null || $remediation === '') ? null : (string) $remediation,
        ];
    }

    /**
     * A FAIL row describing a health check that violated the return contract.
     *
     * @return array{label: string, status: string, detail: string, remediation: string}
     */
    private static function __invalid_row(string $attr_label, string $identifier, string $reason): array
    {
        return [
            'label' => $attr_label,
            'status' => 'FAIL',
            'detail' => "health check {$identifier} {$reason}",
            'remediation' => 'fix the #[Health_Check] method to return a valid row or list of rows',
        ];
    }
}
