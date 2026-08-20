<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Migrate;

use App\RSpade\Core\Rsx;

/**
 * Restore the database to the pre-migration snapshot after an INTERRUPTED migrate.
 *
 * `migrate` snapshots the datadir before running and rolls back automatically when a
 * migration FAILS - but a migration CANCELLED mid-run (Ctrl-C, killed worker) leaves
 * the migration flag and the snapshot behind with nobody to restore them. This command
 * is that missing hand: it performs the exact restore `migrate` itself performs on
 * failure (same rollback_snapshot machinery, inherited - not a second implementation).
 *
 * Contract:
 * - Migration flag present  -> restore the snapshot, then clear flag + backup.
 * - No migration flag       -> state that no migration is in progress; nothing to do.
 * - A FAILED restore keeps the flag AND the backup: the snapshot is the only copy of
 *   the pre-migration data, so nothing may delete it until a restore has succeeded.
 */
class Migrate_Restore_Command extends Maint_Migrate
{
    protected $signature = 'migrate:restore';

    protected $description = 'Restore the database to the pre-migration snapshot left by an interrupted migrate';

    public function handle()
    {
        // No flag = no migration in progress. The flag is created by create_snapshot()
        // and removed by every completed migrate (commit and rollback paths alike), so
        // its absence means there is no snapshot state to restore to.
        if (!file_exists($this->flag_file)) {
            $this->info('No migration is in progress - nothing to restore.');
            return 0;
        }

        // Same gate as migrate: the snapshot it restores was taken by, and can only
        // be put back by, the RSpade container's supervisor and data-directory
        // layout.
        if (!Rsx::is_rspade_container()) {
            $this->error('[ERROR] Snapshot restore requires the RSpade container.');
            $this->info('  The snapshot is restored by stopping the supervised MySQL service and');
            $this->info('  replacing its data directory; this environment is not that container');
            $this->info('  (/.rspade_container absent).');
            return 1;
        }

        $flag = json_decode((string) file_get_contents($this->flag_file), true) ?: [];
        $this->info(' Interrupted migration found'
            . (isset($flag['started_at']) ? ' (started ' . $flag['started_at'] . ')' : '')
            . ' - restoring pre-migration snapshot...');
        $this->info('');

        // Same preflight migrate runs before touching the datadir: a stray unsupervised
        // mysqld would keep serving while we restore under it (B-47).
        try {
            $this->preflight_mysqld_topology();
        } catch (\Exception $e) {
            $this->error('[ERROR] ' . $e->getMessage());
            return 1;
        }

        if (!$this->rollback_snapshot()) {
            // rollback_snapshot() already reported why. Keep the flag and the backup:
            // the snapshot is the only copy of the pre-migration data.
            $this->error('[ERROR] Restore failed - the migration flag and snapshot are left in place.');
            return 1;
        }

        $this->cleanup_migration_mode();

        $this->info('');
        $this->info('[OK] Database restored to pre-migration state.');

        return 0;
    }
}
