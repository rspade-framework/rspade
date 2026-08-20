<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Rsx_Result_Set - a whole result set you can foreach, without holding it in memory.
 *
 * The framework's answer to the two rules that pull against each other: a function
 * must return EVERY record it promised, and the framework must not fall over when
 * that turns out to be 500,000 rows. Returning this satisfies both - the caller sees
 * a normal iterable and reaches every record; the rows arrive a page at a time.
 *
 *     // in the framework
 *     public function get_attachments(string $category)
 *     {
 *         return File_Attachment_Model::where(...)->result_set();
 *     }
 *
 *     // in application code - nothing to learn, no method to call first
 *     foreach ($record->get_attachments('documents') as $attachment) { ... }
 *
 * Iteration is keyset-paged by primary key (lazyById), which is what makes it safe
 * to MUTATE the rows you are walking: deleting them, or updating a column the WHERE
 * clause tests, cannot make the walk skip or repeat. Offset paging cannot promise
 * that - the window slides under you.
 *
 * WHEN NOT TO USE IT. Two cases, both about not over-engineering:
 *
 *   - A set with a known small ceiling (a finite catalog, a per-record handful).
 *     Return the Collection. Paging machinery for 12 rows is noise.
 *   - An ORDERED display list. lazyById() must walk by primary key, so it OVERRIDES
 *     any orderBy on the query. "The 20 most recent" is a different job: keep the
 *     ordered query and let it return rows.
 *
 * Iteration issues one query per page, so this trades queries for memory. That is
 * the right trade only when the row count is genuinely unknown.
 *
 * @see rsx:man model (RESULT SETS)
 */
#[Instantiatable]
class Rsx_Result_Set implements IteratorAggregate, Countable
{
    /**
     * Rows per page. Laravel's own default for lazyById()/chunk(); large enough that
     * a few thousand rows cost a handful of queries, small enough to stay cheap.
     */
    public const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * @var \Illuminate\Database\Eloquent\Builder The unexecuted query
     */
    private $query;

    private int $chunk_size;

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query Unexecuted query
     * @param int $chunk_size Rows fetched per page
     */
    public function __construct($query, int $chunk_size = self::DEFAULT_CHUNK_SIZE)
    {
        if ($chunk_size < 1) {
            shouldnt_happen('Rsx_Result_Set requires a positive chunk size');
        }

        $this->query = $query;
        $this->chunk_size = $chunk_size;
    }

    /**
     * The whole set, one keyset-paged page at a time. This is what makes a plain
     * foreach work; nothing needs to be called first.
     *
     * @return Traversable
     */
    public function getIterator(): Traversable
    {
        return (clone $this->query)->lazyById($this->chunk_size);
    }

    /**
     * How many records the set holds - answered by SQL COUNT, never by walking.
     * Counting by iteration is the mistake this class exists to prevent.
     *
     * @return int
     */
    public function count(): int
    {
        return (clone $this->query)->count();
    }

    /**
     * Whether the set holds nothing. Asks the database whether ANY row matches
     * rather than counting them all.
     *
     * @return bool
     */
    public function is_empty(): bool
    {
        return !(clone $this->query)->exists();
    }

    /**
     * The first record, or null. One row, one query - not a page.
     *
     * @return mixed
     */
    public function first()
    {
        return (clone $this->query)->first();
    }

    /**
     * Materialize the ENTIRE set into memory as a plain array.
     *
     * Deliberately explicit: if you call this, you are asserting the set fits. Prefer
     * foreach. Kept because some callers genuinely need an array (json encoding, a
     * count-then-index pass), and hiding that behind a cast would be worse.
     *
     * @return array
     */
    public function all(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    /**
     * The underlying query, cloned - the escape hatch for a caller that wants to
     * narrow, re-order, or page it differently. Not part of the common path: if you
     * only want the records, foreach this object.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        return clone $this->query;
    }

    /**
     * Forward everything else (map / filter / each / pluck / reject ...) to the lazy
     * collection, so the whole LazyCollection surface works and STAYS lazy - a
     * ->map() over a million rows still holds one page at a time.
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        return $this->getIterator()->$method(...$arguments);
    }
}
