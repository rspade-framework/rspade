<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Framework\Framework_Mutations;
use Illuminate\Console\Command;

/**
 * rsx:framework:prune_mutations - reconciling reset of the mutation-marker store.
 *
 * Internal-use: the framework pull script invokes this at the pristine moment
 * (right after the owned-zone sync) in place of the old blind wipe. It drops only
 * the markers whose recorded content no longer matches disk and KEEPS every marker
 * that still attests the current tree - which is what closes the live-box update
 * race (a concurrent boot may legitimately record framework churn between the sync
 * and this prune; a valid record is retained whenever it was written). See
 * Framework_Mutations::prune() for the full keep/drop matrix and rationale.
 *
 * Harmless to run manually. Silent-success: it prints one [OK] line only when it
 * actually dropped a stale marker, and nothing when the store is already clean or
 * absent. --base-dir is a test seam (mirrors rsx:framework:verify) pointing the
 * whole reconciliation at a fixture tree.
 */
class Framework_Prune_Mutations_Command extends Command
{
    protected $signature = 'rsx:framework:prune_mutations {--base-dir= : Prune a store rooted elsewhere (test seam; default = base_path())}';

    protected $description = '[internal] Reconcile the framework mutation-marker store (drop stale markers, keep true ones)';

    protected $hidden = true;

    public function handle(): int
    {
        // --base-dir points the hashing base AND the store (base-dir/storage/rsx-framework)
        // at an arbitrary tree; the pull script never uses it (it prunes the real
        // store), the CLI test suite uses it to prune fixture stores with the real
        // implementation. Default: the live tree.
        $override_base = $this->option('base-dir');
        $base_dir = null;
        $store_root = null;
        if ($override_base !== null && $override_base !== '') {
            $base_dir = rtrim($override_base, '/');
            $store_root = $base_dir . '/storage/rsx-framework';
        }

        $before = count(Framework_Mutations::get_index($store_root)['entries']);
        Framework_Mutations::prune($base_dir, $store_root);
        $after = count(Framework_Mutations::get_index($store_root)['entries']);

        $dropped = $before - $after;
        if ($dropped > 0) {
            $this->line("[OK] Pruned {$dropped} stale framework mutation marker(s); {$after} true marker(s) retained.");
        }

        return 0;
    }
}
