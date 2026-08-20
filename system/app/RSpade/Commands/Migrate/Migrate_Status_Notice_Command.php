<?php

namespace App\RSpade\Commands\Migrate;

use App\RSpade\Core\Database\MigrationPaths;
use Illuminate\Console\Command;

/**
 * Print a one-line notice when unapplied migrations are pending; silent otherwise.
 *
 * Designed to run after a framework pull (or a git pull that brought new migrations) so a
 * developer is told to run `php artisan migrate`. Silent-success: prints nothing when the
 * schema is up to date, and never nags or fails if the migrator is unavailable.
 */
class Migrate_Status_Notice_Command extends Command
{
    protected $signature = 'migrate:status_notice';
    protected $description = 'Print a notice when migrations are pending (silent when none)';

    public function handle()
    {
        $count = $this->get_pending_migrations_count();

        if ($count > 0) {
            $this->line("There are {$count} unapplied migrations pending. Please run `php artisan migrate` now to run them");
        }

        return 0;
    }

    /**
     * Count migrations present on disk but not yet recorded as run. Returns -1 on any
     * error; the caller treats a non-positive count as "nothing to say" (advisory notice
     * must never nag or fail because the migrator was momentarily unavailable).
     *
     * The path set is MigrationPaths::get_all_migration_files() - the framework's own
     * documented source of truth (recursive over database/migrations +
     * rsx/resource/migrations, ordered by basename), which is exactly what the migrator
     * runs. Laravel's Migrator::paths() is NOT usable here: it returns only the extra
     * directories packages register via loadMigrationsFrom() and deliberately excludes the
     * application's own migration directories, so counting against it was blind to every
     * migration that matters while permanently counting one vendor file RSX never runs.
     *
     * Identity is the BASENAME without .php - the same key Laravel's migration repository
     * records.
     */
    protected function get_pending_migrations_count(): int
    {
        try {
            $names = [];
            foreach (MigrationPaths::get_all_migration_files() as $file) {
                $names[] = str_replace('.php', '', basename($file));
            }

            $ran = app('migrator')->getRepository()->getRan();

            return count(array_diff(array_unique($names), $ran));
        } catch (\Throwable $e) {
            return -1;
        }
    }
}
