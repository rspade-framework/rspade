<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database\TypeRefs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Type_Ref_Audit - the integrity view of the _type_refs registry against the codebase.
 *
 * Two questions, both answered here so rsx:health (Type_Ref_Health_Checks) and
 * rsx:type_refs:prune (Type_Refs_Prune_Command) share one implementation:
 *
 *   1. Which registered type refs name a model class that no longer exists?
 *   2. For each of those, WHICH ROWS still reference it - table, column, count?
 *
 * (2) is what separates a harmless leftover from an app that is throwing right now, and it
 * is only ever asked when (1) found something: the reference sweep is one query per
 * (table, type-ref column) across every model in the manifest, which is cheap enough for a
 * broken registry and pointless work for a healthy one.
 *
 * The column inventory is the manifest's, not a schema guess: every model's
 * _type_ref_columns() - its own $type_ref_columns declaration UNIONED with the framework
 * audit pairs (created_by_type / updated_by_type / deleted_by_type), which are type refs on
 * every table in the system.
 */
class Type_Ref_Audit
{
    /**
     * Every _type_refs row whose class_name no longer resolves to a class in the manifest.
     *
     * @return array<int, array{id: int, class_name: string}> ordered by id
     */
    public static function unresolvable_type_refs(): array
    {
        if (!Schema::hasTable('_type_refs')) {
            return [];
        }

        $rows = DB::select('SELECT id, class_name FROM _type_refs ORDER BY id');

        $unresolvable = [];
        foreach ($rows as $row) {
            if (!Type_Ref_Registry::class_resolves($row->class_name)) {
                $unresolvable[] = ['id' => (int) $row->id, 'class_name' => $row->class_name];
            }
        }

        return $unresolvable;
    }

    /**
     * Every table with type-ref columns, as table_name => list of column names.
     *
     * Built from the manifest's model inventory (a table with no model is not a table any
     * type ref can be written through) and intersected with the columns the table actually
     * has - deleted_by_type exists only where rows soft-delete.
     *
     * @return array<string, array<int, string>>
     */
    public static function type_ref_columns_by_table(): array
    {
        $by_table = [];

        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $entry) {
            // php_get_extending() returns concrete Rsx_Model_Abstract subclasses only.
            $fqcn = $entry['fqcn'] ?? null;
            if (!$fqcn) {
                continue;
            }

            $model = new $fqcn();
            $table = $model->getTable();
            if (!$table) {
                continue;
            }

            foreach ($fqcn::_type_ref_columns() as $column) {
                $by_table[$table][$column] = true;
            }
        }

        $result = [];
        foreach ($by_table as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $existing = Schema::getColumnListing($table);
            $present = array_values(array_intersect(array_keys($columns), $existing));
            if (!empty($present)) {
                $result[$table] = $present;
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * Count the rows referencing each of the given type-ref ids.
     *
     * @param array<int, int> $ids
     * @return array<int, array<int, array{table: string, column: string, count: int}>>
     *         keyed by type-ref id; an id with no references maps to an empty list.
     */
    public static function reference_counts(array $ids): array
    {
        $counts = [];
        foreach ($ids as $id) {
            $counts[(int) $id] = [];
        }

        if (empty($counts)) {
            return $counts;
        }

        $id_list = array_keys($counts);
        $placeholders = implode(', ', array_fill(0, count($id_list), '?'));

        foreach (static::type_ref_columns_by_table() as $table => $columns) {
            foreach ($columns as $column) {
                $rows = DB::select(
                    "SELECT `{$column}` AS type_ref_id, COUNT(*) AS row_count FROM `{$table}`"
                    . " WHERE `{$column}` IN ({$placeholders}) GROUP BY `{$column}`",
                    $id_list
                );

                foreach ($rows as $row) {
                    $counts[(int) $row->type_ref_id][] = [
                        'table' => $table,
                        'column' => $column,
                        'count' => (int) $row->row_count,
                    ];
                }
            }
        }

        return $counts;
    }

    /**
     * "clients.created_by_type (3), tasks.parent_type (2)" - the breakdown for one id.
     *
     * @param array<int, array{table: string, column: string, count: int}> $references
     */
    public static function format_references(array $references): string
    {
        $parts = [];
        foreach ($references as $reference) {
            $parts[] = $reference['table'] . '.' . $reference['column'] . ' (' . $reference['count'] . ')';
        }

        return implode(', ', $parts);
    }
}
