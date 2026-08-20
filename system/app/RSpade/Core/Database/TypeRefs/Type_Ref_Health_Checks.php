<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database\TypeRefs;

use Illuminate\Support\Facades\Schema;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Audit;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;

/**
 * Type_Ref_Health_Checks - the rsx:health surface for _type_refs integrity.
 *
 * A registry row whose model class was deleted or renamed is invisible until something
 * reads a column holding its id, and then it throws. This check is what tells you BEFORE
 * that: every registered type ref must name a class that still exists, and each one that
 * does not is reported with the rows still pointing at it.
 *
 * WARN, never FAIL: a retired type ref with no referencing data is a harmless leftover,
 * and even a referenced one is a data-hygiene problem, not a broken installation - rsx:health's
 * exit code gates deploys and must not flip on it. The referencing counts are what make the
 * row actionable, so they are always included.
 */
class Type_Ref_Health_Checks
{
    /**
     * Every _type_refs row resolves to a live model class.
     *
     * @return array
     */
    #[Health_Check('Type References')]
    public static function type_ref_integrity(): array
    {
        if (!Schema::hasTable('_type_refs')) {
            return [
                'status' => 'INFO',
                'detail' => '_type_refs table does not exist yet (migrations not run)',
            ];
        }

        $unresolvable = Type_Ref_Audit::unresolvable_type_refs();

        if (empty($unresolvable)) {
            return [
                'status' => 'OK',
                'detail' => count(Type_Ref_Registry::get_map()) . ' type ref(s), all resolving to a live class',
            ];
        }

        $counts = Type_Ref_Audit::reference_counts(array_column($unresolvable, 'id'));

        $rows = [];
        foreach ($unresolvable as $entry) {
            $references = $counts[$entry['id']] ?? [];
            $label = 'Type Ref ' . $entry['id'] . ': ' . $entry['class_name'];

            if (empty($references)) {
                $rows[] = [
                    'label' => $label,
                    'status' => 'WARN',
                    'detail' => 'class no longer exists in the codebase; no rows reference it (harmless leftover)',
                    'remediation' => 'php artisan rsx:type_refs:prune',
                ];
                continue;
            }

            $total = 0;
            foreach ($references as $reference) {
                $total += $reference['count'];
            }

            $rows[] = [
                'label' => $label,
                'status' => 'WARN',
                'detail' => 'class no longer exists in the codebase; ' . $total . ' row(s) still reference it: '
                    . Type_Ref_Audit::format_references($references),
                'remediation' => 'repoint or delete those rows, then php artisan rsx:type_refs:prune',
            ];
        }

        return $rows;
    }
}
