<?php

namespace App\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Lifecycle\Model_Lifecycle_Registry;
use App\RSpade\Core\Database\Orm_Fetch_Preload;
use App\RSpade\Core\Database\Rsx_Result_Set;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Realtime\Realtime_Touch_Registry;

/**
 * Custom Eloquent query builder that prevents eager loading and unsafe operations
 *
 * This builder overrides dangerous methods to enforce RSpade framework safety rules:
 * - All eager loading methods throw exceptions (with/withCount/etc)
 * - DELETE without WHERE clause throws exception to prevent accidental mass deletion
 * - Automatic type_ref column conversion in WHERE clauses
 */
class RestrictedEloquentBuilder extends Builder
{
    /**
     * Cached list of type_ref columns for this builder's model
     * @var array|null
     */
    protected $_type_ref_columns_cache = null;

    /**
     * Per-statement escape hatch: ->raw_bulk() sets this so a bulk update()/delete() does the
     * single raw SQL statement and NONE of the per-record side effects (no realtime frame, no
     * after_* hook). The pressure valve for a large maintenance sweep on a model with a
     * realtime/lifecycle surface where the O(N) per-record cost is not wanted.
     * @var bool
     */
    protected $_raw_bulk = false;

    /**
     * Get the type_ref columns for the current model
     *
     * @return array
     */
    protected function _get_type_ref_columns(): array
    {
        if ($this->_type_ref_columns_cache === null) {
            $model = $this->getModel();

            // _type_ref_columns() unions the model's own declaration with the framework audit
            // authorship columns declared on the base - reading the static property directly
            // would drop the audit columns for any model that declares its own array.
            $this->_type_ref_columns_cache = ($model instanceof Rsx_Model_Abstract)
                ? $model::_type_ref_columns()
                : [];
        }

        return $this->_type_ref_columns_cache;
    }

    /**
     * Check if a column is a type_ref column
     *
     * Accepts the bare column name AND the table-qualified spelling of this builder's own
     * table ("portal_notifications.subject_type"). Eloquent's own polymorphic relations
     * qualify the morph type column - HasRelationships::morphMany() passes
     * $table.'.'.$type - so without this the relation's own `where(type, class)` constraint
     * would be compared unconverted (a class-name string against a BIGINT column, which
     * MySQL coerces to 0 and silently matches nothing). A qualifier naming any OTHER table
     * is deliberately NOT accepted: that column belongs to a joined table whose type-ref
     * declaration is not ours to read.
     *
     * @param string $column
     * @return bool
     */
    protected function _is_type_ref_column(string $column): bool
    {
        $columns = $this->_get_type_ref_columns();
        if (empty($columns)) {
            return false;
        }

        if (in_array($column, $columns)) {
            return true;
        }

        $dot = strrpos($column, '.');
        if ($dot === false) {
            return false;
        }

        $qualifier = substr($column, 0, $dot);
        $bare = substr($column, $dot + 1);

        return $qualifier === $this->getModel()->getTable() && in_array($bare, $columns);
    }

    /**
     * Convert a type_ref value (class name string) to its integer ID
     *
     * Null and integer (or integer-string) values pass through untouched. Anything else is
     * resolved through the registry, and a FAILED resolution THROWS rather than letting the
     * string reach the query: a class-name-shaped string compared against a BIGINT type-ref
     * column is coerced to 0 by MySQL and silently matches nothing. The registry's own
     * message names only the class, so it is re-thrown here with the model and column that
     * asked - the three facts needed to find the call site.
     *
     * @param mixed $value
     * @param string|null $column The column being compared, for the failure message
     * @return mixed
     */
    protected function _convert_type_ref_value($value, ?string $column = null)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // If already an integer, return as-is
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        // Convert class name to ID
        try {
            return Type_Ref_Registry::class_to_id((string) $value);
        } catch (\RuntimeException $e) {
            $model_class = class_basename($this->getModel());
            $column_label = $column === null ? 'a type-ref column' : "'{$column}'";

            throw new \RuntimeException(
                "Type-ref comparison on {$model_class} column {$column_label} received the "
                . "unresolvable value '" . (string) $value . "'. A class-name-shaped string cannot "
                . "reach a BIGINT type-ref column (MySQL coerces it to 0 and the clause silently "
                . "matches nothing). Registry: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Convert the value of a TWO-ARGUMENT where-family call (`orWhere($column, $value)`).
     *
     * where() decides which argument holds the value from its OWN func_num_args(), so any
     * sibling that delegates to it (orWhere, whereNot, orWhereNot - all of which pass four
     * arguments) loses that arity and would leave the value sitting unconverted in
     * $operator. Laravel's operator/value swap then binds the raw class-name string against
     * a BIGINT column: the silent-zero this whole seam exists to prevent.
     *
     * @param mixed $column
     * @param mixed $value
     * @return mixed
     */
    protected function _convert_short_form_value($column, $value)
    {
        if (is_string($column) && $this->_is_type_ref_column($column)) {
            return $this->_convert_type_ref_value($value, $column);
        }

        return $value;
    }

    /**
     * Override where() to auto-convert type_ref columns
     *
     * @param \Closure|string|array $column
     * @param mixed $operator
     * @param mixed $value
     * @param string $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        // Handle closure form - no conversion needed
        if ($column instanceof \Closure) {
            return parent::where($column, $operator, $value, $boolean);
        }

        // Handle array form: ['column' => 'value']
        if (is_array($column)) {
            $converted = [];
            foreach ($column as $col => $val) {
                if (is_string($col) && $this->_is_type_ref_column($col)) {
                    $converted[$col] = $this->_convert_type_ref_value($val, $col);
                } else {
                    $converted[$col] = $val;
                }
            }
            return parent::where($converted, $operator, $value, $boolean);
        }

        // Handle 2-arg form: where('column', 'value') -> operator is actually value
        // Handle 3-arg form: where('column', '=', 'value')
        if (is_string($column) && $this->_is_type_ref_column($column)) {
            if (func_num_args() === 2) {
                // 2-arg form: $operator is actually the value
                $operator = $this->_convert_type_ref_value($operator, $column);
            } else {
                // 3+ arg form: $value is the value
                $value = $this->_convert_type_ref_value($value, $column);
            }
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * Override orWhere() to auto-convert type_ref columns
     *
     * @param \Closure|string|array $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            return $this->where($column, $this->_convert_short_form_value($column, $operator), null, 'or');
        }

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Override whereNot() to auto-convert type_ref columns
     *
     * Stock whereNot() delegates to where() with four arguments, so only its short form
     * needs handling here (see _convert_short_form_value).
     *
     * @param \Closure|string|array $column
     * @param mixed $operator
     * @param mixed $value
     * @param string $boolean
     * @return $this
     */
    public function whereNot($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (func_num_args() === 2) {
            $operator = $this->_convert_short_form_value($column, $operator);
        }

        return parent::whereNot($column, $operator, $value, $boolean);
    }

    /**
     * Override orWhereNot() to auto-convert type_ref columns
     *
     * @param \Closure|string|array $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function orWhereNot($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            $operator = $this->_convert_short_form_value($column, $operator);
        }

        return parent::orWhereNot($column, $operator, $value);
    }

    /**
     * Override whereIn() to auto-convert type_ref columns
     *
     * @param string $column
     * @param mixed $values
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if (is_string($column) && $this->_is_type_ref_column($column)) {
            if (is_array($values)) {
                $values = array_map(fn($v) => $this->_convert_type_ref_value($v, $column), $values);
            }
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * Override orWhereIn() to auto-convert type_ref columns
     *
     * @param string $column
     * @param mixed $values
     * @return $this
     */
    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * Override whereNotIn() to auto-convert type_ref columns
     *
     * @param string $column
     * @param mixed $values
     * @param string $boolean
     * @return $this
     */
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Override orWhereNotIn() to auto-convert type_ref columns
     *
     * @param string $column
     * @param mixed $values
     * @return $this
     */
    public function orWhereNotIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or', true);
    }

    /**
     * ==========================================
     * POLYMORPHIC JOIN HELPERS
     * ==========================================
     */

    /**
     * Add a polymorphic INNER JOIN to the query
     *
     * Joins a table with polymorphic columns (e.g., fileable_type, fileable_id)
     * to the current model's table, automatically handling type_ref conversion.
     *
     * @param string $table The table containing the polymorphic columns
     * @param string $morphName The morph column prefix (e.g., 'fileable' for fileable_type/fileable_id)
     * @param string|null $morphClass The class name to match. If null, uses current model's class.
     * @return $this
     *
     * @example
     * // Get contacts with their attachments
     * Contact_Model::query()
     *     ->joinMorph('file_attachments', 'fileable')
     *     ->get();
     *
     * // Explicit class (when joining from a different model)
     * SomeModel::query()
     *     ->joinMorph('file_attachments', 'fileable', Contact_Model::class)
     *     ->get();
     */
    public function joinMorph(string $table, string $morphName, ?string $morphClass = null): self
    {
        return $this->_addMorphJoin('join', $table, $morphName, $morphClass);
    }

    /**
     * Add a polymorphic LEFT JOIN to the query
     *
     * @param string $table The table containing the polymorphic columns
     * @param string $morphName The morph column prefix (e.g., 'fileable')
     * @param string|null $morphClass The class name to match. If null, uses current model's class.
     * @return $this
     *
     * @example
     * // Get all contacts, with attachments if they exist
     * Contact_Model::query()
     *     ->leftJoinMorph('file_attachments', 'fileable')
     *     ->get();
     */
    public function leftJoinMorph(string $table, string $morphName, ?string $morphClass = null): self
    {
        return $this->_addMorphJoin('leftJoin', $table, $morphName, $morphClass);
    }

    /**
     * Add a polymorphic RIGHT JOIN to the query
     *
     * @param string $table The table containing the polymorphic columns
     * @param string $morphName The morph column prefix (e.g., 'fileable')
     * @param string|null $morphClass The class name to match. If null, uses current model's class.
     * @return $this
     */
    public function rightJoinMorph(string $table, string $morphName, ?string $morphClass = null): self
    {
        return $this->_addMorphJoin('rightJoin', $table, $morphName, $morphClass);
    }

    /**
     * Internal helper to add a polymorphic join
     *
     * @param string $joinMethod The join method to use (join, leftJoin, rightJoin)
     * @param string $table The table containing the polymorphic columns
     * @param string $morphName The morph column prefix
     * @param string|null $morphClass The class name to match
     * @return $this
     */
    protected function _addMorphJoin(string $joinMethod, string $table, string $morphName, ?string $morphClass): self
    {
        // Determine the class name to use
        if ($morphClass === null) {
            // Use the current model's simple class name
            $morphClass = class_basename($this->getModel());
        } else {
            // Extract simple class name if FQCN was provided
            $morphClass = class_basename($morphClass);
        }

        // Get the type_ref ID for the class
        $typeRefId = Type_Ref_Registry::class_to_id($morphClass);

        // Get the current model's table name
        $baseTable = $this->getModel()->getTable();

        // Build the join
        return $this->$joinMethod($table, function ($join) use ($table, $baseTable, $morphName, $typeRefId) {
            $join->on("{$table}.{$morphName}_id", '=', "{$baseTable}.id")
                 ->where("{$table}.{$morphName}_type", '=', $typeRefId);
        });
    }

    /**
     * Prevent eager loading via with()
     *
     * @param mixed $relations
     * @return $this
     * @throws \RuntimeException
     */
    public function with($relations, $callback = null)
    {
        // Allow empty with() calls (Laravel uses these internally)
        if (empty($relations)) {
            return parent::with($relations, $callback);
        }
        
        // Also allow if relations is an empty array
        if (is_array($relations) && count($relations) === 0) {
            return parent::with($relations, $callback);
        }
        
        // Throw exception for actual eager loading attempts
        throw new \RuntimeException(
            'Eager loading via with() is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to eager load: ' . $this->format_relations($relations)
        );
    }
    
    /**
     * Prevent eager loading via withCount()
     *
     * @param mixed $relations
     * @return $this
     * @throws \RuntimeException
     */
    public function withCount($relations)
    {
        // Allow empty withCount() calls
        if (empty($relations)) {
            return parent::withCount($relations);
        }
        
        throw new \RuntimeException(
            'Eager loading counts via withCount() is not allowed in the RSpade framework. ' .
            'Use explicit count queries instead. ' .
            'Attempted to eager load counts for: ' . $this->format_relations($relations)
        );
    }
    
    /**
     * Prevent eager loading via withMax()
     *
     * @param array|string $relation
     * @param string $column
     * @return $this
     * @throws \RuntimeException
     */
    public function withMax($relation, $column)
    {
        // Allow empty withMax() calls
        if (empty($relation)) {
            return parent::withMax($relation, $column);
        }
        
        throw new \RuntimeException(
            'Eager loading max via withMax() is not allowed in the RSpade framework. ' .
            'Use explicit max queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via withMin()
     *
     * @param array|string $relation
     * @param string $column
     * @return $this
     * @throws \RuntimeException
     */
    public function withMin($relation, $column)
    {
        // Allow empty withMin() calls
        if (empty($relation)) {
            return parent::withMin($relation, $column);
        }
        
        throw new \RuntimeException(
            'Eager loading min via withMin() is not allowed in the RSpade framework. ' .
            'Use explicit min queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via withSum()
     *
     * @param array|string $relation
     * @param string $column
     * @return $this
     * @throws \RuntimeException
     */
    public function withSum($relation, $column)
    {
        // Allow empty withSum() calls
        if (empty($relation)) {
            return parent::withSum($relation, $column);
        }
        
        throw new \RuntimeException(
            'Eager loading sum via withSum() is not allowed in the RSpade framework. ' .
            'Use explicit sum queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via withAvg()
     *
     * @param array|string $relation
     * @param string $column
     * @return $this
     * @throws \RuntimeException
     */
    public function withAvg($relation, $column)
    {
        // Allow empty withAvg() calls
        if (empty($relation)) {
            return parent::withAvg($relation, $column);
        }
        
        throw new \RuntimeException(
            'Eager loading avg via withAvg() is not allowed in the RSpade framework. ' .
            'Use explicit avg queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via withExists()
     *
     * @param array|string $relation
     * @return $this
     * @throws \RuntimeException
     */
    public function withExists($relation)
    {
        // Allow empty withExists() calls
        if (empty($relation)) {
            return parent::withExists($relation);
        }
        
        throw new \RuntimeException(
            'Eager loading exists via withExists() is not allowed in the RSpade framework. ' .
            'Use explicit exists queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via withAggregate()
     *
     * @param mixed $relations
     * @param string $column
     * @param string $function
     * @return $this
     * @throws \RuntimeException
     */
    public function withAggregate($relations, $column, $function = null)
    {
        // Allow empty withAggregate() calls
        if (empty($relations)) {
            return parent::withAggregate($relations, $column, $function);
        }
        
        throw new \RuntimeException(
            'Eager loading aggregates via withAggregate() is not allowed in the RSpade framework. ' .
            'Use explicit aggregate queries instead.'
        );
    }
    
    /**
     * Prevent lazy eager loading via has()
     * Note: has() is different - it's for filtering, not loading
     * But we'll still prevent it if it tries to eager load
     *
     * @param string $relation
     * @param string $operator
     * @param int $count
     * @param string $boolean
     * @param \Closure|null $callback
     * @return $this
     */
    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', ?\Closure $callback = null)
    {
        // has() is allowed as it's for filtering, not eager loading
        // But log it for monitoring
        if (app()->environment() !== 'testing') {
            logger()->debug('has() query used on relation', [
                'model' => get_class($this->model),
                'relation' => $relation,
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
            ]);
        }
        
        return parent::has($relation, $operator, $count, $boolean, $callback);
    }
    
    /**
     * Override delete() so a genuine USER bulk delete (Model::where(...)->delete()) processes
     * each affected record through its own model delete() — so realtime frames and the
     * after_delete() hook fire per record via the single-write path. A model's OWN delete()
     * (Eloquent's performDeleteOnModel, and a SoftDeletes deleted_at write) re-enters here under
     * the single-write guard and must run the raw statement (the model handles its own effects).
     *
     * @param mixed $id
     * @return int
     * @throws \RuntimeException
     */
    public function delete($id = null)
    {
        // If $id is provided, allow it (deleting by primary key) — a raw base-builder path.
        if ($id !== null) {
            return parent::delete($id);
        }

        // A model's own single-row delete/soft-delete write routes here from inside
        // parent::delete(): do the raw statement, nothing else (the model emits + hooks itself).
        if (Rsx_Model_Abstract::_is_inside_single_model_write()) {
            return parent::delete();
        }

        // Check if there are any WHERE clauses on the underlying query
        $baseQuery = $this->getQuery();
        if (empty($baseQuery->wheres)) {
            // @PHP-DB-01-EXCEPTION - DB::table() mentioned in error message, not used
            shouldnt_happen(
                'Attempted to delete all records from ' . $this->getModel()->getTable() . ' without WHERE clause. ' .
                'This operation is forbidden to prevent accidental data loss. ' .
                'If you truly need to delete all records, use DB::table()->truncate() or add a WHERE clause.'
            );
        }

        // Escape hatch, or a plain model with no side-effect surface: one raw statement.
        if ($this->_raw_bulk || !$this->_has_side_effect_surface()) {
            return parent::delete();
        }

        // User bulk delete on a model with a realtime/lifecycle surface: process each record
        // through $model->delete() so realtime + after_delete fire per record. Wrapped in a
        // transaction so a mid-iterate failure rolls the whole bulk back (and the afterCommit
        // buffers then flush nothing). SoftDeletes is handled naturally — $model->delete()
        // soft-deletes and fires after_delete once; the internal deleted_at update re-enters
        // this builder under the single-write guard and runs raw.
        return DB::transaction(function () {
            $count = 0;
            // lazyById, not get(): the affected set is unbounded, and paging by PRIMARY
            // KEY is what makes it safe to walk rows we are deleting - a deleted row is
            // simply behind the cursor. Bounds MEMORY, not lock duration: the whole walk
            // is one transaction by design (see above). ->raw_bulk() remains the valve
            // for a sweep too large to hold a transaction open for.
            foreach ((clone $this)->applyScopes()->lazyById() as $model) {
                if ($model->delete()) {
                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * Override update() so a genuine USER bulk update (Model::where(...)->update([...])) applies
     * the new values to each affected record and re-saves it — realtime frames and after_update()
     * (with the actually-changed field names) fire per record via the single-write path. A model's
     * OWN update (Eloquent's performUpdate, incl. a SoftDeletes deleted_at write) re-enters here
     * under the single-write guard and must run the raw statement.
     *
     * @param array $values
     * @return int
     */
    public function update(array $values)
    {
        // A model's own single-row update routes here from inside parent::save()/delete(): raw.
        if (Rsx_Model_Abstract::_is_inside_single_model_write()) {
            // THE SOFT-DELETE STAMP LANDS HERE. Laravel's SoftDeletes::runSoftDelete() writes
            // its own [deleted_at, updated_at] array and discards the model's dirty attributes,
            // so deleted_by_id/_type cannot be set before parent::delete() the way save()'s
            // authorship stamp is - this call is the statement, and merging the pair into it is
            // what keeps a soft delete ONE UPDATE. The model decides whether anything applies
            // (it returns $values untouched for every non-soft-delete write reaching here).
            $model = $this->getModel();
            if ($model instanceof Rsx_Model_Abstract) {
                $values = $model->_merge_soft_delete_stamp($values);
            }

            return parent::update($values);
        }

        // Escape hatch, a plain model with no surface, or a raw Expression value (DB::raw('n+1')
        // cannot be applied per-record safely): one raw statement.
        if ($this->_raw_bulk || $this->_values_contain_expression($values) || !$this->_has_side_effect_surface()) {
            return parent::update($values);
        }

        // User bulk update on a model with a realtime/lifecycle surface: set the new values on
        // each affected record and save it (per-record realtime + after_update via the single
        // path). Transaction-wrapped for all-or-nothing. $guarded blocks fill(), so the values
        // are set explicitly via setAttribute(); a record whose values are unchanged saves
        // nothing and fires no hook (correct — no actual write).
        return DB::transaction(function () use ($values) {
            $count = 0;
            // lazyById, not get(): unbounded affected set, and paging by PRIMARY KEY is
            // what keeps the walk correct when the update changes a column the WHERE
            // clause tests - those rows stop matching, and an OFFSET walk would then skip
            // the rows that slid into their place. See the delete() twin.
            foreach ((clone $this)->applyScopes()->lazyById() as $model) {
                foreach ($values as $column => $value) {
                    $model->setAttribute($column, $value);
                }
                if ($model->save()) {
                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * Serve a primary-key lookup from the ORM batch preload when - and ONLY when - the
     * query being intercepted is the exact query the preload's rows came from.
     *
     * The ORM batch endpoint loads every requested id in one `whereIn('id', $ids)->get()`
     * and then still runs each id through the model's own fetch(), whose first act is
     * `static::find($id)`. This override is what turns those N single-row SELECTs into
     * zero. Orm_Fetch_Preload holds the rows; its lifetime is one endpoint invocation.
     *
     * Every guard below exists so the cache can only ever return a row the real query
     * would itself have returned:
     *
     *   - scalar $id: find([1,2,3]) is findMany(), a different result shape entirely.
     *   - $columns === ['*']: a projected find() must produce a partially-hydrated model,
     *     and the preloaded instance is fully hydrated.
     *   - no wheres on the underlying query: any additional constraint narrows the result,
     *     and the preload knows nothing about it (`Model::where('x', 1)->find($id)` must
     *     be able to return null for a row the preload holds).
     *   - no removed scopes: withTrashed()/withoutGlobalScope() WIDEN the result past the
     *     default-scoped set the preload was built from. This is the guard that makes a
     *     model whose fetch() body uses withTrashed() degrade to unbatched (one query per
     *     id, exactly as before) instead of getting a wrong answer.
     *
     * A miss falls through to parent::find() unconditionally - the preload is an
     * accelerator, never an authority.
     *
     * @param mixed $id
     * @param array $columns
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|null
     */
    public function find($id, $columns = ['*'])
    {
        if (is_scalar($id)
            && $columns === ['*']
            && empty($this->getQuery()->wheres)
            && empty($this->removedScopes())) {
            $preloaded = Orm_Fetch_Preload::get(get_class($this->getModel()), $id);

            if ($preloaded !== null) {
                return $preloaded;
            }
        }

        return parent::find($id, $columns);
    }

    /**
     * Execute the query, and in development tell the developer when the answer was far
     * bigger than they probably pictured.
     *
     * The empirical half of the bounded-result-set discipline. $unbounded is a STATIC claim
     * about a table; this fires on what a query ACTUALLY returned, so it has no false
     * positives - a query that returns 40 rows is silent forever, and one that returns
     * 80,000 says so the first time it happens, while the feature is still being written.
     *
     * Never throws and never truncates: the caller asked for the whole set and gets it.
     * Development/debug only - production does not pay for the check.
     *
     * @param array|string $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get($columns = ['*'])
    {
        $result = parent::get($columns);

        $threshold = (int) config('rsx.database.result_set_warn_threshold');

        if ($threshold > 0 && (Rsx::is_development() || Rsx::is_debug())) {
            $count = $result->count();

            if ($count > $threshold) {
                static::_warn_large_result_set($this->getModel(), $count, $threshold);
            }
        }

        return $result;
    }

    /**
     * Emit the one-line tripwire warning, naming the model, the count and the application
     * frame that issued the query (not this file, and not the vendored query builder - the
     * developer needs the line they wrote).
     *
     * @param mixed $model
     * @param int $count
     * @param int $threshold
     * @return void
     */
    protected static function _warn_large_result_set($model, int $count, int $threshold): void
    {
        $model_name = is_object($model) ? class_basename($model) : 'query';
        $origin = 'unknown';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $frame) {
            $file = $frame['file'] ?? '';

            if ($file === '' || str_contains($file, '/vendor/') || str_contains($file, '/app/Database/')) {
                continue;
            }

            $origin = relative_path($file) . ':' . ($frame['line'] ?? 0);
            break;
        }

        \Log::warning(
            "[DB-UNBOUNDED] {$model_name} returned {$count} rows in one get() "
            . "(threshold {$threshold}) at {$origin}. If this set has no ceiling, iterate it "
            . '- ->result_set() - rather than holding it whole. Silence this by raising or '
            . 'disabling rsx.database.result_set_warn_threshold.'
        );
    }

    /**
     * Wrap this query in an Rsx_Result_Set: a foreach-able handle over the WHOLE set that
     * fetches it a keyset page at a time. The framework's default return shape for a
     * "these records" API whose row count has no known ceiling.
     *
     *     return File_Attachment_Model::where(...)->result_set();
     *
     * Do NOT use it for an ordered display list - iteration walks by primary key and
     * overrides orderBy - nor for a set with a known small ceiling, where returning the
     * Collection is simpler and cheaper. See Rsx_Result_Set.
     *
     * @param int $chunk_size Rows fetched per page
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    public function result_set(int $chunk_size = Rsx_Result_Set::DEFAULT_CHUNK_SIZE)
    {
        return new Rsx_Result_Set($this, $chunk_size);
    }

    /**
     * Per-statement escape hatch: ->raw_bulk()->update()/->delete() runs the single raw SQL
     * statement and NONE of the per-record side effects (no realtime frame, no after_* hook).
     * The pressure valve for a large maintenance sweep where the O(N) per-record cost is not
     * wanted (recognized by the REALTIME-BULK-01 rule). Chainable.
     *
     * @return $this
     */
    public function raw_bulk()
    {
        $this->_raw_bulk = true;

        return $this;
    }

    /**
     * Whether this builder's model has a side-effect surface that per-record processing must
     * fire: a realtime surface ($realtime, a #[Realtime_Touch] relationship, or an overridden
     * realtime_touch()) OR a lifecycle surface (an overridden after_create/update/delete hook).
     * A model with neither takes the raw single-statement fast path (today's behavior, no
     * regression).
     */
    protected function _has_side_effect_surface(): bool
    {
        $model = $this->getModel();
        if (!$model instanceof Rsx_Model_Abstract) {
            return false;
        }

        $fqcn = get_class($model);

        return Realtime_Touch_Registry::has_realtime_surface($fqcn)
            || Model_Lifecycle_Registry::has_lifecycle_surface($fqcn);
    }

    /**
     * Whether an update value set contains a raw SQL Expression (DB::raw(...)). Such a write
     * (e.g. `counter` => DB::raw('counter + 1')) is inherently a set-based statement that cannot
     * be reproduced per-record, so it forces the raw single-statement path.
     *
     * @param array $values
     */
    protected function _values_contain_expression(array $values): bool
    {
        foreach ($values as $value) {
            if ($value instanceof Expression) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format relations for error messages
     *
     * @param mixed $relations
     * @return string
     */
    protected function format_relations($relations)
    {
        if (is_array($relations)) {
            return implode(', ', array_keys($relations));
        }

        return (string) $relations;
    }
}