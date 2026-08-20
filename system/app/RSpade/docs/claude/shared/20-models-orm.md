<!-- single-source: never duplicate into another fragment. -->

## MODELS & ORM

**NEVER use mass assignment** — assign every field explicitly, so what a request can write is visible in the code. `User_Model::create($request->all())` is the shape never to write.

**NEVER eager load relationships** — `->with()` throws framework-wide. Load what you need, when you need it.

Bounded result sets (`$unbounded`, `Rsx_Result_Set`, `LIMIT`) are governed by the engineering-mandates fragment.

**Enums** are integer columns (BIGINT, never VARCHAR/ENUM) mapped at the model by a `public static $enums` array to constants, labels and custom properties, read through BEM-style double-underscore magic properties — `Project_Model::STATUS_ACTIVE`, `$project->status_id__label`, `Model.status_id__enum_select()` in JS. Run `rsx:constants:regenerate` after changing one.

**Model fetch** — JavaScript reaches ORM records through ONE framework endpoint, per model, by explicit opt-in:

```javascript
const project = await Project_Model.fetch(1);           // throws if not found
const maybe   = await Project_Model.fetch_or_null(999); // null if not found
const client  = await project.client();                 // lazy relationship
```

**Security is two layers**: `#[Ajax_Endpoint_Model_Fetch]` makes the surface exist, and its MANDATORY `#[Auth]` is evaluated BEFORE any model code — **a denial returns the same generic "not found" as a missing row (anti-enumeration)**. Gates take no arguments, so **record-level rules (ownership, membership, record state) stay in the `fetch()` body**. **`fetch()` is for SECURITY, not aliasing** — never rename a field or format a date in it; it serves LIVE records only; **changes to it require user approval**. A model lacking `fetch()` gets one (a framework model via a class override in `rsx/models/`) — **separate controller endpoints that fetch single records are an anti-pattern**, and a model missing from the JS bundle means STOP and ask.

**Bulk writes**: `Model::where(...)->update()/->delete()` on a model with a side-effect surface (realtime, or an overridden `after_*` hook) loads the rows and runs each through its own `save()`/`delete()`, so frames AND hooks fire PER RECORD. `->raw_bulk()` fires NOTHING; `DB::table()`/raw SQL bypasses the model layer entirely.

**Detail tables** (class-table inheritance): a base model spanning 1:1 detail tables selected by a discriminator, declared by a `$detail_tables` map and reached as `$party->party_person->first_name` — **throws on wrong-type access** in both languages.

Skills: `rspade:model-fetch`, `rspade:model-enums`, `rspade:detail-tables`. Details: `rsx:man model_fetch`, `rsx:man enums`, `rsx:man model`.
