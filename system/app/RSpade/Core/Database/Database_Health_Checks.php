<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\MigrationPaths;

/**
 * Database_Health_Checks - MySQL reachability and pending-migration status for rsx:health.
 *
 * Declared next to the migration machinery it probes. Each method is a public static
 * `#[Health_Check('label')]` (a bare marker attribute - never a defined class) returning
 * a row per Health_Check_Runner's contract.
 */
class Database_Health_Checks
{
    /**
     * MySQL connectivity. Caught locally so the remediation can point at DB_* config
     * rather than surfacing a raw PDO exception via the runner's generic wrapper.
     *
     * @return array
     */
    #[Health_Check('MySQL Connectivity')]
    public static function mysql_connectivity(): array
    {
        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            return [
                'status' => 'FAIL',
                'detail' => 'cannot query the database: ' . $e->getMessage(),
                'remediation' => 'check DB_* in .env and that MySQL is running',
            ];
        }

        return ['status' => 'OK', 'detail' => 'connected (' . config('database.default') . ')'];
    }

    /**
     * Pending migrations: files present but not yet run against the database.
     *
     * @return array
     */
    #[Health_Check('Pending Migrations')]
    public static function pending_migrations(): array
    {
        try {
            $migration_names = [];
            foreach (MigrationPaths::get_all_migration_files() as $file) {
                $migration_names[] = basename($file, '.php');
            }

            $ran = app('migrator')->getRepository()->getRan();
            $pending = array_values(array_diff($migration_names, $ran));
        } catch (\Throwable $e) {
            return [
                'status' => 'FAIL',
                'detail' => 'could not read migration state: ' . $e->getMessage(),
                'remediation' => 'check the database connection and the migrations table (php artisan migrate)',
            ];
        }

        $count = count($pending);
        if ($count === 0) {
            return ['status' => 'OK', 'detail' => 'schema up to date'];
        }

        return [
            'status' => 'WARN',
            'detail' => $count . ' pending migration(s)',
            'remediation' => 'run php artisan migrate',
        ];
    }
}
