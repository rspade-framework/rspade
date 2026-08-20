<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Audit;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;

/**
 * rsx:type_refs:prune - drop _type_refs rows whose model class no longer exists AND which
 * no data references.
 *
 * The retirement procedure this completes: repoint or delete the referencing rows first,
 * then prune. A registry row that data still points at is REFUSED, loudly, with the
 * table/column/count breakdown - dropping it would turn a named, throwing failure
 * ("type ref 3 names a model class that no longer exists") back into the silent phantom
 * this whole surface exists to eliminate.
 *
 * A row whose class still exists is never touched: this command prunes retirement debris,
 * it does not garbage-collect unused type refs (an unused-but-live type ref is normal -
 * the model simply has no rows yet).
 */
class Type_Refs_Prune_Command extends Command
{
    protected $signature = 'rsx:type_refs:prune {--dry-run : Report what would be dropped without dropping it}';

    protected $description = 'Drop _type_refs rows whose model class no longer exists and which nothing references';

    public function handle(): int
    {
        $unresolvable = Type_Ref_Audit::unresolvable_type_refs();

        if (empty($unresolvable)) {
            $this->info('[OK] Every type ref resolves to a live model class - nothing to prune');

            return 0;
        }

        $counts = Type_Ref_Audit::reference_counts(array_column($unresolvable, 'id'));

        $prunable = [];
        $referenced = [];
        foreach ($unresolvable as $entry) {
            if (empty($counts[$entry['id']])) {
                $prunable[] = $entry;
            } else {
                $referenced[] = $entry;
            }
        }

        foreach ($referenced as $entry) {
            $references = $counts[$entry['id']];
            $total = 0;
            foreach ($references as $reference) {
                $total += $reference['count'];
            }

            $this->error(
                '[REFUSED] type ref ' . $entry['id'] . ' ("' . $entry['class_name'] . '") - '
                . $total . ' row(s) still reference it: ' . Type_Ref_Audit::format_references($references)
            );
        }

        if (!empty($referenced)) {
            $this->line('');
            $this->line('Repoint or delete the rows listed above, then run this command again.');
            $this->line('');
        }

        if (empty($prunable)) {
            return 1;
        }

        $dry_run = (bool) $this->option('dry-run');

        foreach ($prunable as $entry) {
            if ($dry_run) {
                $this->line('[DRY-RUN] would drop type ref ' . $entry['id'] . ' ("' . $entry['class_name'] . '")');
                continue;
            }

            DB::delete('DELETE FROM _type_refs WHERE id = ?', [$entry['id']]);
            $this->info('[OK] dropped type ref ' . $entry['id'] . ' ("' . $entry['class_name'] . '")');
        }

        if (!$dry_run) {
            Type_Ref_Registry::refresh();
        }

        return empty($referenced) ? 0 : 1;
    }
}
