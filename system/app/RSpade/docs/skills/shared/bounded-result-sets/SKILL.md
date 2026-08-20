---
name: bounded-result-sets
description: Returning and consuming whole database result sets in RSpade without truncating them or exhausting memory, using Rsx_Result_Set, keyset iteration, and the $unbounded declaration. Use when writing a function that returns "these records", consuming a framework API that returns a result set, adding or removing a LIMIT, deciding whether a model needs $unbounded, or responding to a DB-UNBOUNDED-01 finding or a [DB-UNBOUNDED] runtime warning.
---

# Bounded Result Sets

## The principle (recap)

A function does exactly what its name says, in its entirety. `get_sessions_for_user()` returns ALL of a user's sessions. Correctness outranks memory conservation, and satisfying both means **iterating rather than truncating**. The always-on mandate lives in the engineering-mandates fragment; this skill is the how.

**Scope: database result sets.** Rows coming out of the database are the volume the framework does not control. Bounded in-process work whose size the codebase itself determines (the manifest indexing files, a directory scan, a config array, a list of registered classes) is NOT governed by any of this - do not contort it into chunked walks.

## The 1,000-row threshold

Any framework mechanism that hands DB records to another framework mechanism must process ALL of them and must not fall over if the set turns out to be 100,000 rows instead of the 20 you had in mind. The threshold to design for is roughly **1,000 rows**: below that, materializing is fine; above it, or where the ceiling is genuinely unknown, iterate.

**An OOM caused purely by a customer having more data than expected is a framework defect, not a capacity problem.** The framework's own worked example: `Session::get_sessions_for_user()` ran an unbounded `->get()` and the caller sliced to 20 - but one login user had accumulated 62,617 rows, enough to exhaust a 128MB process and return a blank 500.

## Rsx_Result_Set

### Author side - return one

Where a function's job is "these records", return `->result_set()` rather than an executed Collection:

```php
// framework
public function get_attachments(string $category)
{
    return File_Attachment_Model::where(...)->result_set();
}

// caller - nothing to learn
foreach ($record->get_attachments('documents') as $attachment) { ... }
```

Nothing to unwrap, nothing to call first - **a handle the caller has to unwrap is a worse API than a list.**

### Consumer side - what you are holding

Framework APIs may hand you one (`get_attachments()`, `get_deleted_files()` and friends). Treat it as a list:

- `foreach` it - reaches every record, holding one page at a time.
- `count()` it - a SQL `COUNT`, not a walk.
- `map` / `filter` / `each` / `pluck` / `reject` / `take` - forwarded to the lazy collection and STAY lazy; a `->map()` over a million rows still holds one page.
- `is_empty()`, `first()` - the obvious ones.

**It is NOT an array**: no `[]` indexing, no `array_map()`. Call `->all()` only if you truly need an array - that materializes the whole set, which is the thing you were avoiding.

### Caveats that decide whether to use it

- **Iteration is keyset-paged by primary key** (`lazyById`), which OVERRIDES any `orderBy` on the query. An ORDERED display list ("the 20 most recent") is a different job - keep the ordered query. The upside of keyset paging: it is safe to MUTATE the rows you are walking (deleting them, or updating a column the WHERE clause tests, cannot make the walk skip or repeat - an offset window slides under you).
- **A known small ceiling** (a finite catalog, a per-record handful): return the Collection. Paging machinery for 12 rows is noise.
- **One query per page.** It trades queries for memory, so it earns its keep only when the row count is genuinely unknown.

## LIMIT - the three cases

`LIMIT` is a strong guideline, not an absolute ban. It is right when it IS the mechanism, or when the caller genuinely asked for N. A bare truncating cap is always wrong.

```php
// [OK] CORRECT - limit IS the pagination mechanism
$last_id = 0;
while (true) {
    $batch = Model::where('...')->where('id', '>', $last_id)
        ->orderBy('id')->limit(500)->get();
    if ($batch->isEmpty()) break;
    foreach ($batch as $row) { $last_id = $row->id; /* ... */ }
}

// [OK] CORRECT - "the 10 most recent" IS what the caller asked for
Activity_Model::where('user_id', $id)->orderByDesc('created_at')->limit(10)->get();

// [NO] WRONG - a bare cap on a function that promised everything
public static function get_all_invoices($client_id) {
    return Invoice_Model::where('client_id', $client_id)->limit(100)->get();
}
```

If you find yourself adding a LIMIT "for safety", you are changing what the function does - either the name is wrong or the design is. Every situation has its own circumstances: if you keep a cap for a reason not covered here, **say why at the call site**. A caller handed a partial set cannot tell, and **an unexplained cap is indistinguishable from the bug.**

## Declaring `$unbounded`

Any model whose row count grows with CUSTOMER ACTIVITY rather than with your codebase declares it, next to `$table`:

```php
class Invoice_Model extends Rsx_Site_Model_Abstract {
    protected $table = 'invoices';
    public static $unbounded = true;   // one row per invoice, forever
}
```

**Mark**: logs, event/message streams, notifications, queues, uploads, and the core business tables that scale with customers.

**Do NOT mark**: human-authored low-volume tables (announcements, groups), fixtures, join tables you always narrow to one parent, 1:1 detail tables.

The test is **"could a reasonable query here return thousands?"** - and **over-marking dilutes the signal.** It is a declaration, not a gate: it changes no behavior, and a small well-narrowed query on a marked model is still fine. It marks where a bare `->get()` deserves a second look. The template app ships marked examples.

## The two enforcement surfaces

**`DB-UNBOUNDED-01`** (`rsx:check`, AST) flags a whole-set read (`->get()` / `->pluck()`) reached through a builder chain whose root model declares `$unbounded`. Scope is **framework code only** (`system/app/`) - the code shipped to everyone, held to the higher bar; application code is covered by the runtime tripwire and by the prelaunch checklist's unbounded-query audit, and linting it would fire on perfectly reasonable app queries. Suppressed when the chain already carries a bound or an iteration strategy (`->limit()`, `->take()`, `->result_set()`, `->lazyById()`, `->lazy()`, `->chunkById()`, `->chunk()`, `->cursor()`, `->paginate()`, `->simplePaginate()`, `->first()`, `->raw_bulk()`), or by a file-level `@DB-UNBOUNDED-01-EXCEPTION - <rationale>`. It is ADVISORY: the answer is usually to state what bounds this one query.

**`rsx.database.result_set_warn_threshold`** (default 1000): in development, logs ONE `[DB-UNBOUNDED]` warning when a `get()` actually returns more rows than that, naming your call site. **It never throws and never truncates.** Set `0`/`null` to disable.

## Anti-zealotry

These are guiding principles, not absolutes. Sometimes a chunked walk makes a simple thing unreadable for no real benefit, and clarity wins - a genuinely small, structurally-bounded set does not need machinery. State what bounds it and move on. But the default posture inside the framework's black box is: bounded by design, iterated not truncated, and whole.

See also: `rsx:man model` (RESULT SETS).
