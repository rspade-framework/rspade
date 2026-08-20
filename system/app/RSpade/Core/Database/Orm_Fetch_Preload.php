<?php

namespace App\RSpade\Core\Database;

/**
 * Request-RAM prefetch for the ORM batch endpoint.
 *
 * WHAT IT IS. The ORM endpoint receives N ids for one model and must run EVERY one of
 * them through that model's own fetch()/portal_fetch() - that is where authorization and
 * per-record shaping live, and none of it may be skipped. Those bodies all start with a
 * primary-key lookup (`static::find($id)`), so N ids meant N single-row SELECTs. This
 * class holds the rows of ONE `whereIn('id', $ids)->get()` so those lookups resolve from
 * memory instead. The fetch() bodies are untouched and unaware.
 *
 * WHO POPULATES IT. Orm_Controller ONLY - fetch() before it loops the requested ids, and
 * fetch_relationship()'s plural branch before it loops the related ids. Nothing else may
 * populate it, and it is never a general-purpose model cache.
 *
 * WHO READS IT. RestrictedEloquentBuilder::find(), and only for a PRISTINE default-scoped
 * `find($id)` (see the guards there). It is an ACCELERATOR, never an authority: a miss
 * always falls through to the real query, and the cache may only ever serve a row the
 * intercepted query would itself have returned. A model whose fetch() body constrains or
 * scope-strips its lookup (withTrashed(), a where()) simply misses and runs its query -
 * that model degrades to unbatched, it never gets a wrong answer.
 *
 * LIFETIME. Populated immediately before the loop and cleared in the endpoint's `finally`,
 * so its lifetime is ONE endpoint invocation. That is what makes it safe to hold model
 * instances at all: identity, site and realm cannot change while it holds data, so a row
 * loaded under one caller's scopes can never be served to another. Same scoping rule as
 * the Turnstile per-sub-call latch (Ajax.php:243-249), which resets its state around each
 * Ajax::internal() invocation for the same reason.
 */
class Orm_Fetch_Preload
{
    /**
     * Loaded rows, [model fqcn][int id] => model instance.
     *
     * @var array
     */
    private static array $__rows = [];

    /**
     * Load a set of already-queried rows for one model class.
     *
     * The rows MUST come from a query carrying that model's default scopes and nothing
     * else (site scope, SoftDeletingScope) - that is precisely the row set a plain
     * `find($id)` would return one id at a time, which is what makes serving them
     * behavior-identical.
     *
     * @param string $fqcn The model class the rows belong to (get_class of the instances)
     * @param iterable $rows An Eloquent Collection (or any iterable of model instances)
     */
    public static function populate(string $fqcn, $rows): void
    {
        foreach ($rows as $row) {
            static::$__rows[$fqcn][(int) $row->getKey()] = $row;
        }
    }

    /**
     * The preloaded instance for one (model class, id), or null when it was not loaded.
     *
     * Null is the ordinary answer for "this id was not in the preloaded set" AND for "no
     * preload is active" - the caller treats both the same way: run the real query.
     *
     * @param string $fqcn Model class
     * @param mixed $id Record id (normalized to int, matching the primary-key column)
     * @return mixed The model instance, or null
     */
    public static function get(string $fqcn, $id)
    {
        if (!is_scalar($id) || !is_numeric($id)) {
            return null;
        }

        return static::$__rows[$fqcn][(int) $id] ?? null;
    }

    /**
     * Drop everything. Called from the endpoint's `finally` - never optional, since a
     * surviving entry would outlive the request scope its safety argument depends on.
     */
    public static function clear(): void
    {
        static::$__rows = [];
    }
}
