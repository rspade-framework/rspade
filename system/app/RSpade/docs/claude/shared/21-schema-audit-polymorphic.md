<!-- single-source: never duplicate into another fragment. -->

## SCHEMA, AUDIT AUTHORSHIP & POLYMORPHIC REFERENCES

**Migrations are forward-only, with no rollbacks: deterministic transformations against known state.** Raw SQL through `DB::statement()`; the Schema builder is prohibited. Create one with `php artisan make:migration:safe <name>`, apply with `php artisan migrate` (which snapshots the database first in development and auto-rolls-back the run on failure).

**`migrate` is fine to run whenever schema work needs it** — expected and encouraged after creating or changing a migration; treat it as one command (press the button), not "heavy machinery." **Always run `migrate` with its FULL output — never pipe it through `| tail`/`| head`, grep it, or truncate it in any way**: the snapshot/rollback narrative IS the diagnostic, and a truncated run hides which step failed. A migrate CANCELLED mid-run leaves its snapshot behind — `php artisan migrate:restore` performs the same restore a failed run performs automatically (and says so when no migration is in progress).

**No defensive coding in a migration.** No `IF EXISTS`, no `information_schema` queries, no fallbacks. **Know the current state, write the exact transformation.** Failures fail loud — the snapshot rollback is what handles recovery.

### Audit authorship

Every table carries a polymorphic PAIR per audit column — `created_by_id`+`created_by_type`, `updated_by_id`+`updated_by_type` (both BIGINT), plus `deleted_by_*` where rows soft-delete — because RSpade has three identity models (`User_Model`, `Portal_User_Model`, `Login_User_Model`) and a bare integer could not say which one it meant. **`Rsx_Model_Abstract::save()` stamps it automatically** — no trait, no wiring — and **an explicitly assigned pair always wins** (the pair is ONE unit: set one half and you must set both). Read it back with `$record->created_by` / `get_created_by_author()`.

**Migrations never declare the audit columns** — the schema-hygiene pass adds them to every table. **Never reference them positionally (`AFTER updated_by`) or by a pre-pair name.**

**An actor model** (anything an audit pair can point at) extends `Rsx_Site_Actor_Model_Abstract` when its table has `site_id` and `Rsx_Actor_Model_Abstract` when it does not, which supplies the mandated `SoftDeletes` and forces `get_printed_name()` + `get_view_profile_url()`. **An actor must never be hard-deleted** — every audit column naming it would dangle. `ACTOR-01` is a manifest-build FATAL on both counts.

### Polymorphic type references

**A polymorphic reference is ALWAYS the pair `{relation}_type` + `{relation}_id`, both BIGINT** — the `_type` stores a type-ref integer id (**never a VARCHAR class name**) and is declared in the owning model's `$type_ref_columns`; the relation itself is stock Eloquent (`morphTo()` etc., read as a property). **Two parallel nullable FKs is the forbidden shape**; a single-target reference is a plain FK, not a pair; a `type_id` ENUM column is a different concept entirely. **Simple names only**: `class_basename($model)`, never `get_class($model)`. `POLY-01` (manifest-build FATAL) rejects the Laravel string-morph pattern.

Skills: `rspade:migrations`, `rspade:actors-and-authorship` (stamp matrix, `<Record_Author>`, writing an actor), `rspade:polymorphic` (boundaries, half-set pairs, pivot morphs). Details: `rsx:man model_normalization`, `rsx:man polymorphic`.
