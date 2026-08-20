<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;

/**
 * _Manifest_Database_Helper - Database schema introspection
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Database_Helper
{
    /**
     * Get list of all database tables from manifest model metadata
     *
     * Returns only tables that have been indexed via Model_ManifestSupport
     * (i.e., tables with corresponding model classes)
     *
     * @return array Array of table names
     */
    public static function db_get_tables(): array
    {
        Manifest::init();

        if (!isset(Manifest::$data['data']['models'])) {
            return [];
        }

        $tables = [];
        foreach (Manifest::$data['data']['models'] as $model_data) {
            if (isset($model_data['table'])) {
                $tables[] = $model_data['table'];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Get columns for a specific table with their types from manifest
     *
     * Returns simplified column information extracted during manifest build.
     * Types are from Model_ManifestSupport::__parse_column_type() which simplifies
     * MySQL types (e.g., 'bigint', 'varchar(255)' -> 'integer', 'string')
     *
     * @param string $table Table name
     * @return array Associative array of column_name => type, or empty if table not found
     */
    public static function db_get_table_columns(string $table): array
    {
        Manifest::init();

        if (!isset(Manifest::$data['data']['models'])) {
            return [];
        }

        // Find the model for this table
        foreach (Manifest::$data['data']['models'] as $model_data) {
            if (isset($model_data['table']) && $model_data['table'] === $table) {
                if (!isset($model_data['columns'])) {
                    return [];
                }

                $result = [];
                foreach ($model_data['columns'] as $column_name => $column_data) {
                    $result[$column_name] = $column_data['type'] ?? 'unknown';
                }

                return $result;
            }
        }

        return [];
    }

    /**
     * Verify database has been provisioned before running code quality checks
     *
     * Checks that:
     * 1. The _migrations table exists (created by Laravel migrate)
     * 2. At least one migration has been applied
     *
     * This prevents confusing code quality errors when the real issue is
     * that migrations haven't been run yet.
     */
    public static function _verify_database_provisioned(): void
    {
        $migrations_table = config('database.migrations', 'migrations');

        // Check if migrations table exists
        if (!\Illuminate\Support\Facades\Schema::hasTable($migrations_table)) {
            throw new \RuntimeException(
                "Database not provisioned - migrations table '{$migrations_table}' does not exist.\n\n" .
                "Run migrations before continuing:\n" .
                "    php artisan migrate\n\n" .
                "If this is a fresh installation, you may also need to:\n" .
                "    1. Create the database\n" .
                "    2. Configure .env with correct DB_* settings\n" .
                "    3. Run: php artisan migrate"
            );
        }

        // Check if at least one migration has been applied
        $result = \Illuminate\Support\Facades\DB::select("SELECT COUNT(*) as cnt FROM `{$migrations_table}`");
        if ($result[0]->cnt === 0) {
            throw new \RuntimeException(
                "Database not provisioned - no migrations have been applied.\n\n" .
                "Run migrations before continuing:\n" .
                "    php artisan migrate"
            );
        }
    }

    /**
     * Check if we're running in a migration context
     *
     * Returns true if running migrate, make:migration, or other DB setup commands.
     * Used to skip code quality checks that depend on database state.
     */
    public static function _is_migration_context(): bool
    {
        // Check if running from CLI
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        // Get the artisan command being run
        $argv = $_SERVER['argv'] ?? [];
        if (count($argv) < 2) {
            return false;
        }

        // Commands that should skip code quality checks
        $migration_commands = [
            'migrate',
            'migrate:fresh',
            'migrate:install',
            'migrate:refresh',
            'migrate:reset',
            'migrate:status',
            'migrate:normalize_schema',
            'make:migration',
            'make:migration:safe',
            'db:seed',
            'db:wipe',
        ];

        $command = $argv[1] ?? '';
        return in_array($command, $migration_commands, true);
    }

    /**
     * Get columns of a specific type for a table from manifest
     *
     * Useful for finding all boolean columns (tinyint), integer columns, etc.
     *
     * @param string $table Table name
     * @param string $type Type to filter by (from Model_ManifestSupport simplified types)
     * @return array Array of column names matching the type
     */
    public static function db_get_columns_by_type(string $table, string $type): array
    {
        $columns = Manifest::db_get_table_columns($table);

        return array_keys(array_filter($columns, function ($col_type) use ($type) {
            return $col_type === $type;
        }));
    }

}
