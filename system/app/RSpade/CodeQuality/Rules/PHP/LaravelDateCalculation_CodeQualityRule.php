<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * Detects Laravel date calculations that should use MySQL date functions
 *
 * This rule identifies when now()->sub() or now()->add() is used to calculate dates
 * that are then passed to database queries. These should use MySQL's
 * DATE_SUB() or DATE_ADD() functions instead for better performance,
 * accuracy, and timezone consistency.
 */
class LaravelDateCalculation_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PHP-DATE-CALC-01';
    }

    public function get_name(): string
    {
        return 'Laravel Date Calculation in DB Queries';
    }

    public function get_description(): string
    {
        return 'Detects Laravel date calculations that should use MySQL date functions in queries';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'medium';
    }

    /**
     * This rule supports checking Console Commands
     */
    public function supports_console_commands(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip non-PHP files
        if (!str_ends_with($file_path, '.php')) {
            return;
        }

        $lines = explode("\n", $contents);

        // Track variables assigned with now()->sub/add calculations
        $date_calc_vars = [];

        // Pattern to match now()->subX() or now()->addX() assignments
        // Captures: $variable = now()->subHours(24)->format(...)
        $assignment_pattern = '/\$(\w+)\s*=\s*now\(\)\s*->\s*(sub|add)(Years?|Months?|Weeks?|Days?|Hours?|Minutes?|Seconds?)\s*\(\s*(\d+)\s*\)(?:\s*->\s*format\s*\([^)]+\))?/i';

        // Also check for Carbon::now() variant
        $carbon_pattern = '/\$(\w+)\s*=\s*Carbon::now\(\)\s*->\s*(sub|add)(Years?|Months?|Weeks?|Days?|Hours?|Minutes?|Seconds?)\s*\(\s*(\d+)\s*\)(?:\s*->\s*format\s*\([^)]+\))?/i';

        // Scan for date calculation assignments
        foreach ($lines as $line_num => $line) {
            if (preg_match($assignment_pattern, $line, $matches) || preg_match($carbon_pattern, $line, $matches)) {
                $var_name = $matches[1];
                $operation = $matches[2]; // sub or add
                $unit = $matches[3]; // Hours, Days, etc.
                $amount = $matches[4];

                // Store the variable info with its line number
                $date_calc_vars[$var_name] = [
                    'line' => $line_num + 1,
                    'operation' => $operation,
                    'unit' => $unit,
                    'amount' => $amount,
                    'code' => trim($line)
                ];
            }
        }

        // If no date calculations found, nothing to check
        if (empty($date_calc_vars)) {
            return;
        }

        // Now look for usage of these variables in DB queries
        foreach ($lines as $line_num => $line) {
            foreach ($date_calc_vars as $var_name => $var_info) {
                // Check if this variable is used in a DB:: query
                if (str_contains($line, 'DB::') && str_contains($line, '$' . $var_name)) {
                    $this->report_violation($file_path, $line_num + 1, $line, $var_info);
                }

                // Check for Eloquent query usage (where, whereDate, etc.)
                if (preg_match('/->where(Date|Time|Year|Month|Day)?\s*\([^,)]*\$' . preg_quote($var_name) . '/i', $line)) {
                    $this->report_violation($file_path, $line_num + 1, $line, $var_info);
                }

                // Check for raw query bindings
                if (preg_match('/\[\s*\$' . preg_quote($var_name) . '\s*[\],]/', $line)) {
                    // Check if this line or nearby lines contain SQL keywords
                    $context = implode(' ', array_slice($lines, max(0, $line_num - 2), 5));
                    if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|WHERE)\b/i', $context)) {
                        $this->report_violation($file_path, $line_num + 1, $line, $var_info);
                    }
                }
            }
        }
    }

    /**
     * Report a violation for using Laravel date calculation in DB query
     */
    private function report_violation(string $file_path, int $line_num, string $line, array $var_info): void
    {
        // Convert Laravel time unit to MySQL interval unit
        $mysql_unit = $this->get_mysql_interval_unit($var_info['unit']);
        $operation = strtoupper($var_info['operation']); // SUB or ADD

        $suggestion = "Use MySQL date functions instead of Laravel date calculations.\n\n";
        $suggestion .= "Replace the date calculation on line {$var_info['line']}:\n";
        $suggestion .= "  {$var_info['code']}\n\n";
        $suggestion .= "With direct MySQL date function in your query:\n";
        $suggestion .= "  DATE_{$operation}(NOW(), INTERVAL {$var_info['amount']} {$mysql_unit})\n\n";
        $suggestion .= "Example:\n";
        $suggestion .= "  WHERE updated_at < DATE_{$operation}(NOW(), INTERVAL {$var_info['amount']} {$mysql_unit})\n\n";
        $suggestion .= "Benefits:\n";
        $suggestion .= "  - Better performance (calculation in database)\n";
        $suggestion .= "  - Timezone consistency (database handles it)\n";
        $suggestion .= "  - More accurate (no time gap between calculation and query)";

        $this->add_violation(
            $file_path,
            $line_num,
            "Laravel date calculation used in database query",
            trim($line),
            $suggestion,
            'medium'
        );
    }

    /**
     * Convert Laravel/Carbon time unit to MySQL INTERVAL unit
     */
    private function get_mysql_interval_unit(string $laravel_unit): string
    {
        // Remove trailing 's' for singular and convert to MySQL format
        $unit = rtrim(strtoupper($laravel_unit), 'S');

        // MySQL uses singular forms for INTERVAL units
        switch ($unit) {
            case 'YEAR':
                return 'YEAR';
            case 'MONTH':
                return 'MONTH';
            case 'WEEK':
                return 'WEEK';
            case 'DAY':
                return 'DAY';
            case 'HOUR':
                return 'HOUR';
            case 'MINUTE':
                return 'MINUTE';
            case 'SECOND':
                return 'SECOND';
            default:
                return $unit;
        }
    }
}