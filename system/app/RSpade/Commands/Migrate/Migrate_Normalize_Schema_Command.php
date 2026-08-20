<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Migrate;

use App\Providers\AppServiceProvider;
use App\RSpade\Core\Manifest\Manifest;
use Doctrine\DBAL\Types\Type;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maintains required columns and applies drift correction for database schema
 *
 * This command runs AFTER migrations to ensure framework-required columns exist
 * and fix any schema drift in existing tables.
 *
 * Column additions (framework requirements):
 * - Adds the audit authorship pairs created_by_id/created_by_type and
 *   updated_by_id/updated_by_type (BIGINT actor key + BIGINT type ref naming the actor's
 *   model class), converging any earlier spelling ({base}, {base}_user_id) by rename
 * - Adds created_at and updated_at timestamp columns with millisecond precision
 * - Adds indexes on created_at and updated_at columns
 * - Adds the deletion audit pair deleted_by_id/deleted_by_type to tables that soft-delete
 *   (deleted_at), converging the earlier bare `deleted_by` spelling by rename
 * - Adds trait-specific columns (site_id for Siteable, version for Versionable/Ajaxable)
 *
 * Drift correction (legacy* tables only):
 * - Converts tables to UTF8MB4 character set if not already converted
 * - Upgrades DATETIME/TIMESTAMP columns to millisecond precision (3)
 *
 * NOTE: Type standardization (INT->BIGINT, FLOAT->DOUBLE, TEXT->LONGTEXT) is now
 * handled by SqlQueryTransformer during CREATE/ALTER execution. This command only
 * fixes pre-existing tables created before the transformer was implemented.
 *
 * No foreign key drop/recreate is performed because the transformer ensures
 * compatible types from the start.
 */
class Migrate_Normalize_Schema_Command extends Command
{
    protected $signature = 'migrate:normalize_schema {--production : Run in production mode, skipping snapshot requirements}';

    protected $description = 'Normalizes database schema by standardizing data types, character encodings, and adding required columns';

    /**
     * Check if a model uses a specific trait
     *
     * @param string $modelClass The full class name of the model
     * @param string $traitName The trait to check for
     * @return bool True if the model uses the trait
     */
    private function modelUsesTrait($modelClass, $traitName)
    {
        // Fail loud - let PHP throw error if class doesn't exist
        $traits = class_uses_recursive($modelClass);

        return isset($traits[$traitName]);
    }

    /**
     * Get all model classes that use a specific trait
     *
     * @param string $traitName The trait to search for
     * @return array Array of model class names
     */
    private function getModelsUsingTrait($traitName)
    {
        $models = [];

        // Use Manifest to find all model classes
        $all_models = Manifest::php_get_extending('Rsx_Model_Abstract');

        foreach ($all_models as $model_info) {
            $modelClass = $model_info['fqcn'];

            // Check if this model uses the specified trait
            if ($this->modelUsesTrait($modelClass, $traitName)) {
                $models[] = $modelClass;
            }
        }

        return $models;
    }

    public function handle()
    {
        echo $this->signature . "\n";

        // Set query logging to destructive-only mode for normalize
        AppServiceProvider::set_query_log_mode(AppServiceProvider::QUERY_LOG_DESTRUCTIVE_STDOUT);

        $flag_file = '/var/www/html/.migrating';

        // Check if we're in production mode (either via flag or environment)
        $is_production = $this->option('production') || app()->environment('production');

        // Only enforce snapshot protection in development mode without --production flag
        $require_snapshot = !$is_production;

        // Check for migration mode if we require snapshot
        if ($require_snapshot) {
            if (!file_exists($flag_file)) {
                $this->error('[ERROR] Migration mode not active!');
                $this->error('');
                $this->line('In development mode, this command should be run via "php artisan migrate"');
                $this->line('which handles snapshots automatically.');
                $this->info('');
                $this->line('To run with snapshot protection:');
                $this->line('   php artisan migrate');
                $this->info('');
                $this->line('Or use the --production flag to skip snapshot protection:');
                $this->line('   php artisan migrate:normalize_schema --production');

                return 1;
            }

            $this->info('[OK] Migration mode active - snapshot available for rollback');
            $this->info('');
        } elseif ($is_production) {
            $this->info(' Running in production mode (no snapshot protection)');
        }

        try {
            // Set all tables to use default timestamps for created_at and updated_at
            // Laravel's migration tracker (configured as '_migrations' in database.php)
            // is framework-owned with a fixed schema and must never receive audit columns.
            $excludedTables = ['_migrations'];
            $tables = DB::select('SHOW TABLES');

            // Get models using our traits
            $siteableModels = $this->getModelsUsingTrait('App\\Models\\Traits\\Siteable');
            $versionableModels = $this->getModelsUsingTrait('App\\Models\\Traits\\Versionable');
            $ajaxableModels = $this->getModelsUsingTrait('App\\Models\\Traits\\Ajaxable');

            // Map models to their tables
            $siteableTables = [];
            $versionableTables = [];
            $ajaxableTables = [];

            foreach ($siteableModels as $model) {
                $instance = new $model();
                $siteableTables[] = $instance->getTable();
            }

            foreach ($versionableModels as $model) {
                $instance = new $model();
                $versionableTables[] = $instance->getTable();
            }

            foreach ($ajaxableModels as $model) {
                $instance = new $model();
                $ajaxableTables[] = $instance->getTable();
            }

            $this->info('Found ' . count($siteableTables) . ' Siteable models, ' .
                       count($versionableTables) . ' Versionable models, and ' .
                       count($ajaxableTables) . ' Ajaxable models.');

            foreach ($tables as $table) {
                $tableName = array_values((array) $table)[0];

                if (!in_array($tableName, $excludedTables)) {
                    // Standard columns for all tables

                    // Audit authorship pair: created_by_id/_type and updated_by_id/_type
                    $this->normalizeAuditColumns($tableName, 'created_by');
                    $this->normalizeAuditColumns($tableName, 'updated_by');

                    // Check and update created_at column
                    if (!Schema::hasColumn($tableName, 'created_at')) {
                        DB::statement("ALTER TABLE $tableName ADD COLUMN created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3)");
                    } else {
                        // Get current column info including precision
                        $column_info = DB::selectOne("SELECT COLUMN_DEFAULT, DATETIME_PRECISION FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableName' AND COLUMN_NAME = 'created_at'");
                        if ($column_info) {
                            $precision = intval($column_info->DATETIME_PRECISION ?? 0);
                            $default_value = $column_info->COLUMN_DEFAULT;
                            // Check if default contains CURRENT_TIMESTAMP (case-insensitive)
                            $has_default = ($default_value !== null && stripos((string)$default_value, 'CURRENT_TIMESTAMP') !== false);

                            // Only update if precision is not 3 or default is not set
                            if ($precision !== 3 || !$has_default) {
                                DB::statement("ALTER TABLE $tableName MODIFY COLUMN created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3)");
                            }
                        }
                    }

                    // Check and update updated_at column
                    if (!Schema::hasColumn($tableName, 'updated_at')) {
                        DB::statement("ALTER TABLE $tableName ADD COLUMN updated_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)");
                    } else {
                        // Get current column info including precision and on update trigger
                        $column_info = DB::selectOne("SELECT COLUMN_DEFAULT, DATETIME_PRECISION, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableName' AND COLUMN_NAME = 'updated_at'");
                        if ($column_info) {
                            $precision = intval($column_info->DATETIME_PRECISION ?? 0);
                            $default_value = $column_info->COLUMN_DEFAULT;
                            $extra_value = $column_info->EXTRA;
                            // Check if default contains CURRENT_TIMESTAMP (case-insensitive)
                            $has_default = ($default_value !== null && stripos((string)$default_value, 'CURRENT_TIMESTAMP') !== false);
                            // Check if extra contains on update CURRENT_TIMESTAMP (case-insensitive)
                            $has_on_update = (stripos((string)$extra_value, 'on update CURRENT_TIMESTAMP') !== false);

                            // Only update if precision is not 3, default is not set, or on update is not set
                            if ($precision !== 3 || !$has_default || !$has_on_update) {
                                DB::statement("ALTER TABLE $tableName MODIFY COLUMN updated_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)");
                            }
                        }
                    }

                    // Create created_at index
                    if (Schema::hasColumn($tableName, 'created_at') && !$this->columnHasIndex($tableName, 'created_at')) {
                        DB::statement("ALTER TABLE $tableName ADD INDEX created_at(created_at)");
                    }

                    // Create updated_at index
                    if (Schema::hasColumn($tableName, 'updated_at') && !$this->columnHasIndex($tableName, 'updated_at')) {
                        DB::statement("ALTER TABLE $tableName ADD INDEX updated_at(updated_at)");
                    }

                    // The deletion audit pair is NARROWER than the authorship pairs on purpose:
                    // only a table that soft-deletes can ever have a deleted-by actor (a hard
                    // delete removes the row), so it follows deleted_at. A table already
                    // carrying the column keeps it converged even without deleted_at.
                    if (Schema::hasColumn($tableName, 'deleted_at')
                        || Schema::hasColumn($tableName, 'deleted_by')
                        || Schema::hasColumn($tableName, 'deleted_by_id')) {
                        $this->normalizeAuditColumns($tableName, 'deleted_by');
                    }

                    // Handle specific trait requirements

                    // Siteable trait - ensure site_id column exists
                    if (in_array($tableName, $siteableTables) && !Schema::hasColumn($tableName, 'site_id')) {
                        DB::statement("ALTER TABLE $tableName ADD COLUMN site_id INT(11) NOT NULL");

                        // Add index on site_id
                        if (!$this->columnHasIndex($tableName, 'site_id')) {
                            DB::statement("ALTER TABLE $tableName ADD INDEX site_id(site_id)");
                        }
                    }

                    // Versionable trait - ensure version column exists
                    if (in_array($tableName, $versionableTables) && !Schema::hasColumn($tableName, 'version')) {
                        DB::statement("ALTER TABLE $tableName ADD COLUMN version INT(11) NOT NULL DEFAULT 1");

                        // Add index on id+version
                        if (!$this->indexExists($tableName, 'id_version')) {
                            DB::statement("ALTER TABLE $tableName ADD INDEX id_version(id, version)");
                        }
                    }

                    // Ajaxable trait - ensure version column for cache invalidation
                    if (in_array($tableName, $ajaxableTables) && !Schema::hasColumn($tableName, 'version')) {
                        DB::statement("ALTER TABLE $tableName ADD COLUMN version INT(11) NOT NULL DEFAULT 1");
                    }

                    // Order column normalization
                    // Tables with `order` column get: BIGINT DEFAULT NULL, order_idx index, auto-increment triggers
                    if (Schema::hasColumn($tableName, 'order')) {
                        $this->normalizeOrderColumn($tableName);
                    }
                }

                // Convert table to utf8mb4 if needed
                // This is still necessary for tables created before transformer was implemented
                $tableCollation = DB::selectOne("SELECT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableName'");
                if (!$tableCollation || strpos(strtolower($tableCollation->TABLE_COLLATION), 'utf8mb4') === false) {
                    DB::statement("ALTER TABLE $tableName CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                }
            }

            // Update all datetime/timestamp columns to millisecond precision
            $this->updateDatetimePrecision();
        } catch (Exception $e) {
            // Output the error so the developer knows what to fix, then fail loud.
            // Recovery is NOT this command's job. There is exactly one real, tested
            // recovery: the physical MySQL-datadir snapshot restore owned by
            // Maint_Migrate::rollback_snapshot(). A logical migration rollback here
            // does nothing (framework migrations have no down() method) while falsely
            // reporting success, so this command performs no rollback of its own - it
            // re-throws and lets the snapshot owner restore the datadir.
            $this->error('');
            $this->error('[ERROR] Required table columns migration failed!');
            $this->error('Error: ' . $e->getMessage());

            // Disable query echoing before re-throwing
            AppServiceProvider::disable_query_echo();

            // Re-throw to ensure the command fails
            throw $e;
        }

        // Disable query echoing
        AppServiceProvider::disable_query_echo();
    }

    /**
     * Update datetime columns to millisecond precision
     *
     * Ensures all datetime and timestamp columns support millisecond precision (3)
     * for better accuracy in time-sensitive operations.
     */
    private function updateDatetimePrecision()
    {
        $excludedTables = ['_sessions', '_migrations'];
        $tables = DB::select('SHOW TABLES');

        foreach ($tables as $table) {
            $tableName = array_values((array) $table)[0];

            if (in_array($tableName, $excludedTables)) {
                continue;
            }

            // Update all datetime columns to millisecond precision
            $columns = DB::select("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '$tableName' AND TABLE_SCHEMA = DATABASE()");

            foreach ($columns as $column) {
                $columnName = $column->COLUMN_NAME;
                $dataType = $column->DATA_TYPE;

                // Skip created_at, updated_at, and deleted_at as they're handled in the main loop with proper defaults
                if ($columnName === 'created_at' || $columnName === 'updated_at' || $columnName === 'deleted_at') {
                    continue;
                }

                $precision = null;
                if ($dataType == 'datetime' || $dataType == 'timestamp') {
                    $precision = $this->getPrecisionFromShowCreateTable($tableName, $columnName);
                }

                if ($dataType == 'datetime' && $precision != 3) {
                    DB::statement("ALTER TABLE $tableName MODIFY COLUMN $columnName DATETIME(3)");
                } elseif ($dataType == 'timestamp' && $precision != 3) {
                    DB::statement("ALTER TABLE $tableName MODIFY COLUMN $columnName TIMESTAMP(3)");
                }
            }
        }
    }

    private function getPrecisionFromShowCreateTable($tableName, $columnName)
    {
        // Fetch the CREATE TABLE statement
        $createTableResult = DB::select("SHOW CREATE TABLE `$tableName`");
        if (empty($createTableResult)) {
            return null;
        }

        $createTable = $createTableResult[0]->{'Create Table'};

        // Properly format the column name for the regex to avoid issues with special characters
        $formattedColumnName = preg_quote($columnName, '/');

        // Define the regex pattern
        // This pattern looks for the column name followed by its type (TIMESTAMP or DATETIME)
        // and captures the precision (number of digits) in parentheses if present
        $pattern = "/`$formattedColumnName` (?:TIMESTAMP|DATETIME)(?:\\((\\d+)\\))?/i";

        // Execute the regex
        if (preg_match($pattern, $createTable, $matches)) {
            // The precision, if found, will be in the first capturing group
            return $matches[1] ?? null;
        }

        // Return null if no match found
        return null;
    }

    /**
     * Converge one audit authorship column onto the polymorphic PAIR shape.
     *
     * The framework audit columns are `{base}_id` (BIGINT, the actor's primary key) plus
     * `{base}_type` (BIGINT type ref naming the actor's model class). This method brings a
     * table to that shape from any earlier spelling, in one pass, idempotently:
     *
     *   {base}_user_id  -> renamed to {base}_id   (the oldest spelling, data preserved)
     *   {base}          -> renamed to {base}_id   (the pre-pair spelling, data preserved)
     *   (neither)       -> {base}_id created NULL
     *   always          -> {base}_type created NULL when absent
     *
     * Renaming here (rather than only reporting) is what makes a downstream app self-heal:
     * `php artisan migrate` runs this command before and after the migration set, so an app
     * that never runs the framework rename migration still ends up on the pair shape with its
     * existing authorship values intact.
     *
     * BOTH `{base}` and one of the target spellings present is the collision case:
     *   - `{base}` is entirely NULL  -> DROP it (vestigial, lossless) and continue. This is
     *                                   the common real case, and it self-heals a database
     *                                   already dead-ended by the earlier rename-first order.
     *   - `{base}` holds data        -> THROW, having changed NOTHING, naming both columns
     *                                   and the exact DROP that unblocks it.
     * The decision is made BEFORE any ALTER precisely because DDL auto-commits: mutating and
     * then failing leaves a database no migration can repair.
     *
     * Applied to created_by and updated_by on every table, and to deleted_by on the tables
     * that soft-delete (see the caller - a row that hard-deletes has no deleter to record).
     *
     * @param string $tableName
     * @param string $base 'created_by', 'updated_by' or 'deleted_by'
     */
    private function normalizeAuditColumns($tableName, $base)
    {
        $id_column = $base . '_id';
        $type_column = $base . '_type';
        $legacy_user_column = $base . '_user_id';

        $has_id = Schema::hasColumn($tableName, $id_column);
        $has_base = Schema::hasColumn($tableName, $base);
        $has_legacy = Schema::hasColumn($tableName, $legacy_user_column);

        // DECIDE BEFORE MUTATING. This block used to rename first and complain later, and the
        // two halves disagreed: on a table carrying BOTH the framework's own pre-pair `{base}` and an app
        // `{base}_user_id`, the rename manufactured the exact `{base}` + `{base}_id` pair the
        // next invocation refuses. The pass's own first action created the state its second
        // action rejects (field report, 2026-08-10).
        //
        // That is worse than a bad error because DDL AUTO-COMMITS in MySQL: a failed `migrate`
        // rolls back data but not the ALTER, so the table kept both columns permanently, and
        // since this pass runs BEFORE any migration it then threw before a repair migration
        // could ever run. Only hand-written SQL could recover it. So: establish the whole
        // picture first, and refuse while the database is still migratable.
        //
        // `{base}` all-NULL is the common real case (a vestigial framework column that never
        // carried data). One COUNT proves it, and dropping it is lossless - so the situation
        // that used to dead-end a database now SELF-HEALS, including on a database already
        // dead-ended by the old behaviour.
        if ($has_base && ($has_id || $has_legacy)) {
            $populated = (int) DB::table($tableName)->whereNotNull($base)->count();

            if ($populated === 0) {
                DB::statement("ALTER TABLE $tableName DROP COLUMN $base");
                $has_base = false;
            } else {
                // Genuine data in both. Refuse HAVING CHANGED NOTHING, name which column is
                // which (the old message said "rename the conflicting application column",
                // which pointed at the wrong one - `{base}` is the FRAMEWORK column),
                // and give the exact statement that unblocks it.
                $other = $has_id ? $id_column : $legacy_user_column;
                throw new Exception(
                    "Table `{$tableName}` carries BOTH `{$base}` ({$populated} non-NULL rows) and "
                    . "`{$other}`. The framework audit pair owns `{$id_column}`/`{$type_column}`, and "
                    . "`{$base}` is the FRAMEWORK'S OWN PRE-PAIR spelling - not an application column. No schema "
                    . "change has been made; this database is still migratable.\n\n"
                    . "Decide which column holds the authorship you want to keep, then run ONE of:\n"
                    . "  ALTER TABLE {$tableName} DROP COLUMN {$base};\n"
                    . "      (keep `{$other}` - the usual answer when `{$base}` predates the pair)\n"
                    . "  ALTER TABLE {$tableName} DROP COLUMN {$other};\n"
                    . "      (keep `{$base}`, which this pass will then rename to `{$id_column}`)\n\n"
                    . 'Then re-run php artisan migrate.'
                );
            }
        }

        if (!$has_id) {
            if ($has_legacy) {
                DB::statement("ALTER TABLE $tableName RENAME COLUMN $legacy_user_column TO $id_column");
            } elseif ($has_base) {
                DB::statement("ALTER TABLE $tableName RENAME COLUMN $base TO $id_column");
            } else {
                DB::statement("ALTER TABLE $tableName ADD COLUMN $id_column BIGINT NULL");
            }
        }

        if (!Schema::hasColumn($tableName, $type_column)) {
            DB::statement("ALTER TABLE $tableName ADD COLUMN $type_column BIGINT NULL AFTER $id_column");
        }
    }

    private function columnHasIndex($tableName, $columnName)
    {
        $indexes = DB::select("SHOW INDEXES FROM $tableName WHERE Key_name = ?", [$columnName]);

        return count($indexes) > 0;
    }

    /**
     * Check if a specific index exists in a table
     *
     * @param string $tableName The name of the table
     * @param string $indexName The name of the index to check
     * @return bool True if the index exists
     */
    private function indexExists($tableName, $indexName)
    {
        $indexes = DB::select("SHOW INDEXES FROM $tableName WHERE Key_name = ?", [$indexName]);

        return count($indexes) > 0;
    }

    /**
     * Check if a trigger exists
     *
     * @param string $triggerName The name of the trigger
     * @return bool True if the trigger exists
     */
    private function triggerExists($triggerName)
    {
        $triggers = DB::select("SHOW TRIGGERS WHERE `Trigger` = ?", [$triggerName]);

        return count($triggers) > 0;
    }

    /**
     * Normalize order column for a table
     *
     * Ensures:
     * - Column is BIGINT DEFAULT NULL
     * - Index order_idx exists on (order)
     * - Triggers exist for auto-incrementing NULL values on INSERT/UPDATE
     *
     * @param string $tableName The name of the table
     */
    private function normalizeOrderColumn($tableName)
    {
        // 1. Ensure column type is BIGINT DEFAULT NULL
        $column_info = DB::selectOne(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND COLUMN_NAME = 'order'",
            [$tableName]
        );

        if ($column_info) {
            $needs_modify = false;

            // Check if type is BIGINT (case-insensitive, may include display width)
            if (stripos($column_info->COLUMN_TYPE, 'bigint') === false) {
                $needs_modify = true;
            }

            // Check if nullable
            if ($column_info->IS_NULLABLE !== 'YES') {
                $needs_modify = true;
            }

            // Check if default is NULL
            if ($column_info->COLUMN_DEFAULT !== null) {
                $needs_modify = true;
            }

            if ($needs_modify) {
                DB::statement("ALTER TABLE `$tableName` MODIFY COLUMN `order` BIGINT DEFAULT NULL");
            }
        }

        // 2. Ensure order_idx index exists
        if (!$this->indexExists($tableName, 'order_idx')) {
            DB::statement("ALTER TABLE `$tableName` ADD INDEX order_idx(`order`)");
        }

        // 3. Ensure triggers exist for auto-incrementing NULL values
        $insert_trigger_name = "{$tableName}_order_insert";
        $update_trigger_name = "{$tableName}_order_update";

        // Create INSERT trigger if not exists
        if (!$this->triggerExists($insert_trigger_name)) {
            DB::unprepared("
                CREATE TRIGGER `{$insert_trigger_name}`
                BEFORE INSERT ON `{$tableName}`
                FOR EACH ROW
                BEGIN
                    IF NEW.`order` IS NULL THEN
                        SET NEW.`order` = (SELECT COALESCE(MAX(`order`), 0) + 1 FROM `{$tableName}`);
                    END IF;
                END
            ");
        }

        // Create UPDATE trigger if not exists
        if (!$this->triggerExists($update_trigger_name)) {
            DB::unprepared("
                CREATE TRIGGER `{$update_trigger_name}`
                BEFORE UPDATE ON `{$tableName}`
                FOR EACH ROW
                BEGIN
                    IF NEW.`order` IS NULL THEN
                        SET NEW.`order` = (SELECT COALESCE(MAX(`order`), 0) + 1 FROM `{$tableName}`);
                    END IF;
                END
            ");
        }
    }
}
