---
name: rsx-testing
description: Writing and running RSpade tests with Rsx_Test_Abstract and php artisan rsx:test - test discovery, the __assert_* helper inventory, session/user impersonation helpers, per-test transactions, database baselines, and the selector algebra for narrowing a run. Use when writing a test for a feature you just built, adding a test class, choosing between --filter/--group/class-name selectors, deciding whether a class needs $requires_db_reset or --fresh, or diagnosing "test database is the same as the development database".
---

# RSpade testing

**The full reference is `php artisan rsx:man testing`** - every assertion signature, the context and outcome helpers, database isolation, the test-database interlock and dump cache, and the selector algebra. Read it when you need an exact signature or flag. This skill is the judgment layer: what to write, when to run it, and the traps that waste an afternoon.

## Anatomy of a test class

```php
namespace Rsx\Tests;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

class Invoice_Test extends Rsx_Test_Abstract
{
    public static function setup()    { /* shared fixtures - runs once, OUTSIDE the per-test transaction */ }
    public static function teardown() { /* cleanup - runs once after all methods */ }

    public static function test_totals_round_to_cents()
    {
        $invoice = new Invoice_Model();
        $invoice->amount = 10.005;
        $invoice->save();

        static::__assert_equals_approx(10.01, (float) $invoice->fresh()->amount);
    }
}
```

- Extend `App\RSpade\Core\Testing\Rsx_Test_Abstract`.
- **Test methods are `public static` and named `test_*`** - that is the discovery rule.
- Classes are auto-discovered via the manifest; there is no registration step. Application tests live under `/rsx/` (conventionally `/rsx/tests/`); framework tests live under `app/RSpade/tests/<concern>/php/` and run with `--framework`.
- **The namespace MUST match the file path** - the single most common reason a new test class never runs.
- Helpers are `__`-prefixed and `protected static` - call them as `static::__assert_equals(...)`.
- A runnable reference class ships at `/rsx/tests/Example_Test.php`.

## What to test

**Test the failure paths.** `__assert_throws()` is how a fail-loud framework gets tested - if a function is documented to throw on bad input, there is a test proving it does. It returns the caught throwable, so you can assert further on it.

```php
$e = static::__assert_throws(\RuntimeException::class, function () {
    Rsx_Date::parse('2025-12-24T15:30:00Z');   // datetime into a date function
}, 'expects a date');
```

Assertions are strict (`===`); use `__assert_equals_approx()` for floats and money. `__skip()` is for a genuinely inapplicable environment (a missing optional binary), **never** to silence a failure.

## Choosing the isolation mode

| The class... | Declare | Why |
|---|---|---|
| reads, or writes and rolls back (the default) | nothing | each `test_*` runs in a transaction that is rolled back |
| commits, spans a subprocess, or touches no DB at all | `$use_database_transactions = false` | the transaction would be wrong or pointless |
| has the build/migration pipeline as its SUBJECT | `$requires_db_reset = true` **and** `$use_database_transactions = false` | the runner gives it a pristine migrated baseline and restores it afterwards |

`setup()`/`teardown()` run OUTSIDE the per-test transaction, so fixtures created there persist for the whole class and are `teardown()`'s job to remove.

**A transaction rolls back rows, not statics.** The runner clears the CLI session, `Portal_Session` and the Turnstile latch after every test - so a `Portal_Session::set_site_id()` in `setup()` does NOT survive into the tests. Declare the portal site inside each test method. A class that passes in the suite and fails standalone (or the reverse) is borrowed state, essentially always this.

## Running and narrowing

```bash
rsx:test                                    # the whole application suite
rsx:test Invoice_Test Client_Test           # one or more classes (substring match)
rsx:test --filter=invite --filter=expire    # match a class OR a test_* method name
rsx:test --group=portal --group=billing     # whole concern directories
rsx:test --framework --group=locks          # the framework suite, one group
rsx:test --fresh                            # drop + recreate the test DB first
```

All three selectors take a SET; within one selector members are OR'd, across selectors they are AND'd. `--framework` selects the framework partition rather than adding to it. Exit code is 1 only if something failed - a run that selected NOTHING exits 0, so read the summary line.

**You rarely need `--fresh`**: a plain run applies pending migrations to the test database first. Reach for it when you suspect the test DB has drifted or been dirtied, not because you added a migration.

## Two things that are never the answer

**Never switch to the production database**, and **never mark a failing test as passing.** The runner refuses to start when `DB_TEST_DATABASE` equals the dev database; that refusal is the feature, not an obstacle.

## When to run the suite

That policy is an always-on conduct mandate, not a lookup - short version: do not run the full suite as housekeeping, but tests you just wrote for a feature you just built are run without asking, and every feature you write gets tests.

## Further reading

- `php artisan rsx:man testing` - the complete API and runner reference.
- `system/app/RSpade/tests/CLAUDE.md` - framework test structure: concerns, the five test types (php/cli/asset/http/playwright), and the README/test_catalog contract.
