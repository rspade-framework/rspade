<?php

namespace App\RSpade\Commands\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Query_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:query 
        {query : MySQL query to execute}
        {--table : Display results in column-formatted table (like MySQL console with ; delimiter)}
        {--json : Return results as JSON (non-pretty print)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute MySQL queries directly - useful for data queries, schema inspection (SHOW CREATE TABLE), and query analysis (EXPLAIN). Default limit of 25 records applied to SELECT queries without LIMIT clause.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $query = $this->argument('query');
        $original_query = $query;
        
        // Check for schema modification commands and block them
        if (preg_match('/^\s*(ALTER|CREATE|DROP)\b/i', $query)) {
            throw new \RuntimeException(
                "FATAL: Schema modification commands (ALTER, CREATE, DROP) are not allowed.\n" .
                "Database schema changes must be done through migrations:\n" .
                "  1. Create migration: php artisan make:migration:safe <name>\n" .
                "  2. Edit the migration file\n" .
                "  3. Run migration: php artisan migrate"
            );
        }
        
        // Determine query type
        $is_select = preg_match('/^\s*(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC)\b/i', $query);
        $is_data_modification = preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $query);

        // Warn about querying _type_refs table directly
        if (stripos($query, 'select') !== false && stripos($query, '_type_refs') !== false) {
            $this->warn(
                "[WARNING] Querying _type_refs table directly is discouraged.\n" .
                "  Type ref IDs are auto-generated and not predictable across environments.\n" .
                "  Use the Type_Ref_Registry API instead:\n" .
                "    \$id = Type_Ref_Registry::class_to_id('Model_Name');\n" .
                "  This applies especially to migrations - see rsx/resource/migrations/CLAUDE.md"
            );
            $this->newLine();
        }
        
        // Check if this is a SELECT query that needs modification
        $needs_limit_injection = preg_match('/^\s*SELECT\b/i', $query) && preg_match('/\bFROM\b/i', $query);
        $has_calc_found_rows = preg_match('/\bSQL_CALC_FOUND_ROWS\b/i', $query);
        $has_limit = preg_match('/\blimit\s+\d+(?:\s*,\s*\d+)?\s*$/i', $query);
        
        // Modify SELECT queries if needed
        if ($needs_limit_injection) {
            // Add SQL_CALC_FOUND_ROWS if not present
            if (!$has_calc_found_rows) {
                $query = preg_replace('/\bSELECT\b/i', 'SELECT SQL_CALC_FOUND_ROWS', $query, 1);
            }
            
            // Add LIMIT 25 if no limit present
            if (!$has_limit) {
                $query = rtrim($query, '; ') . ' LIMIT 25';
            }
        }
        
        // Handle data modification queries (INSERT, UPDATE, DELETE)
        if ($is_data_modification) {
            try {
                $affected = DB::statement($query);
                if (preg_match('/^\s*DELETE\b/i', $query) || preg_match('/^\s*UPDATE\b/i', $query)) {
                    $this->info("Query successful, {$affected} rows affected");
                } else {
                    $this->info("Query successful");
                }
                return 0;
            } catch (\Exception $e) {
                $this->error("Query error: " . $e->getMessage());
                return 1;
            }
        }
        
        // Handle SELECT-like queries (SELECT, SHOW, EXPLAIN, DESCRIBE)
        try {
            $results = DB::select($query);
            
            // Get total count if SQL_CALC_FOUND_ROWS was used
            $total_count = null;
            if ($needs_limit_injection) {
                try {
                    $count_result = DB::select('SELECT FOUND_ROWS() as count');
                    $total_count = $count_result[0]->count ?? null;
                } catch (\Exception $e) {
                    // Ignore if FOUND_ROWS() fails
                }
            }
            
            // Handle empty results
            if (empty($results)) {
                $this->info("Query successful, no records returned");
                return 0;
            }
            
            // Limit results to 25 for display
            $display_count = min(count($results), 25);
            $results_to_display = array_slice($results, 0, $display_count);
            
            // Output result count message
            if ($total_count !== null && $total_count > $display_count) {
                $this->info("Query successful, displaying {$display_count} of {$total_count} records:");
            } else {
                $this->info("Query successful, displaying {$display_count} records:");
            }
            
            // Output results based on format
            if ($this->option('json')) {
                $this->output_json($results_to_display);
            } elseif ($this->option('table')) {
                $this->output_table($results_to_display);
            } else {
                $this->output_tree($results_to_display);
            }
            
        } catch (\Exception $e) {
            // If not recognized as a specific type, try as statement
            try {
                DB::statement($query);
                $this->info("Query successful");
                return 0;
            } catch (\Exception $e2) {
                $this->error("Query error: " . $e->getMessage());
                return 1;
            }
        }
        
        return 0;
    }
    
    /**
     * Output results in tree format (like MySQL \G)
     */
    protected function output_tree($results)
    {
        foreach ($results as $index => $row) {
            $this->line("*************************** " . ($index + 1) . ". row ***************************");
            foreach ((array)$row as $key => $value) {
                // Calculate padding for alignment
                $padding = str_repeat(' ', max(0, 27 - strlen($key)));
                
                // Handle NULL values
                if ($value === null) {
                    $value = 'NULL';
                } elseif (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                
                $this->line("{$padding}{$key}: {$value}");
            }
        }
    }
    
    /**
     * Output results in table format
     */
    protected function output_table($results)
    {
        if (empty($results)) {
            return;
        }
        
        // Convert objects to arrays
        $data = [];
        foreach ($results as $row) {
            $data[] = (array)$row;
        }
        
        // Get column names
        $columns = array_keys($data[0]);
        
        // Calculate column widths
        $widths = [];
        foreach ($columns as $col) {
            $widths[$col] = strlen($col);
            foreach ($data as $row) {
                $value = $row[$col] ?? '';
                if ($value === null) {
                    $value = 'NULL';
                } elseif (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                $widths[$col] = max($widths[$col], strlen((string)$value));
            }
            // Cap width at 50 characters for readability
            $widths[$col] = min($widths[$col], 50);
        }
        
        // Build separator line
        $separator = '+';
        foreach ($columns as $col) {
            $separator .= str_repeat('-', $widths[$col] + 2) . '+';
        }
        
        // Output table header
        $this->line($separator);
        $header = '|';
        foreach ($columns as $col) {
            $header .= ' ' . str_pad($col, $widths[$col]) . ' |';
        }
        $this->line($header);
        $this->line($separator);
        
        // Output data rows
        foreach ($data as $row) {
            $line = '|';
            foreach ($columns as $col) {
                $value = $row[$col] ?? '';
                if ($value === null) {
                    $value = 'NULL';
                } elseif (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                $value = (string)$value;
                // Truncate if needed
                if (strlen($value) > 50) {
                    $value = substr($value, 0, 47) . '...';
                }
                $line .= ' ' . str_pad($value, $widths[$col]) . ' |';
            }
            $this->line($line);
        }
        
        // Output footer
        $this->line($separator);
    }
    
    /**
     * Output results as JSON
     */
    protected function output_json($results)
    {
        // Convert to plain arrays for clean JSON
        $data = [];
        foreach ($results as $row) {
            $data[] = (array)$row;
        }
        
        $this->line(json_encode($data));
    }
}