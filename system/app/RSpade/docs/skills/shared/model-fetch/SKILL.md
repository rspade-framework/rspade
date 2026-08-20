---
name: model-fetch
description: Loading model records from JavaScript through the ORM endpoint - writing a gated fetch() with #[Ajax_Endpoint_Model_Fetch] + #[Auth], lazy relationships, fetch_cached, and the policy on when a custom Ajax endpoint is allowed instead. Use when implementing or changing fetch()/portal_fetch(), loading a record or relationship from JS, deciding what to do when a model has no fetch() or is missing from the bundle, hitting the relationship cap error (rsx.model_fetch.max_relationship_records), or responding to a MODEL-FETCH-TRASHED-01 finding.
---

# Model Fetch

JavaScript reaches ORM records through ONE framework endpoint, per model, by explicit opt-in. No model is fetchable by default, and the model decides what data leaves the server.

## Security - two layers

**`#[Ajax_Endpoint_Model_Fetch]` makes the surface exist. `#[Auth(...)]` decides who may use it, and it is MANDATORY** - the manifest build fails without a gate. The gate is evaluated at the ORM seam BEFORE any model code runs, and **a denial returns the same generic "not found" as a missing row (anti-enumeration)**.

**Gate vs record.** A gate is user-scoped and takes no arguments, so it can answer "may this USER fetch this KIND of record at all" and nothing else. **Everything that depends on WHICH row stays in the `fetch()` body.**

```php
class Project_Model extends Rsx_Site_Model_Abstract
{
    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]        // MANDATORY - the build fails without it
    public static function fetch($id)
    {
        $project = static::find($id);          // site scope is already applied
        if (!$project) { return false; }

        // Record-level rules go HERE (ownership, membership, record state -> false).
        // Gates take no arguments, so they can never express "only your own row".
        if ($project->owner_user_id !== Session::get_user_id()) { return false; }

        return $project->to_fetch_array();
    }
}
```

**Return values**: a model (serialized via `toArray()`, carrying `__MODEL` for JS hydration), an array built FROM `toArray()` (so `__MODEL` survives and you can add computed fields), or **`false`** - not `null` - for not-found-or-unauthorized.

**Relationships** can be made fetchable on-demand for app data, and a related model must implement its OWN gated `fetch()`. **`fetch()` changes require user approval due to security implications** - it is a disclosure surface, not a convenience method.

## Non-deleted records only

`fetch()`/`fetch_or_null()` serve LIVE records: a soft-deleted row reads as not found. **A `fetch()`/`portal_fetch()` body must never widen the default scope** - a `withTrashed()` in there is flagged by **`MODEL-FETCH-TRASHED-01`**.

A screen that must show a deleted record uses a dedicated `#[Ajax_Endpoint]` gated for exactly that situation. The template's worked example is `Frontend_Clients_Controller::fetch_deleted`, which shares one payload builder with the model's `fetch()` so both return the identical shape.

## Augmenting the payload

```php
$data = $contact->toArray();                 // ALWAYS start here - preserves __MODEL
$data['full_name']  = $contact->full_name();
$data['avatar_url'] = $contact->get_avatar_url();
unset($data['password_hash']);               // remove what JS must not see
return $data;
```

## Anti-aliasing (the one home for this rule)

**`fetch()` is for SECURITY, not aliasing.** It exists to remove private data users shouldn't see.

```php
// [NO] WRONG - renaming obscures the data source and breaks grep
$data['type_label'] = $record->type_id__label;
$data['created']    = Rsx_Time::format_date($record->created_at);   // no server-side formatting

// [OK] CORRECT - full BEM names; format on the client with Rsx_Date / Rsx_Time
$data['type_id__label'] = $record->type_id__label;
```

Field names are identical across every layer: database -> PHP -> JSON -> JavaScript. One string everywhere, so grep finds all usages. (The general rule is in the code-conventions fragment; the enums skill points here.)

## JavaScript usage

```javascript
const project = await Project_Model.fetch(123);        // THROWS if not found
const maybe   = await Project_Model.fetch_or_null(999); // null if not found

console.log(project.status_id__label);                 // enum properties ride along
if (project.status_id === Project_Model.STATUS_ACTIVE) { /* static constants */ }
```

### Lazy relationships

Declare the relationship method as a fetch surface on the PHP side. A **class-level `#[Auth]` covers every surface in the model**, which is how the template's models gate their relationships; a method-level one is ADDITIVE and only ever narrows.

```php
#[Auth('is_logged_in')]              // class level - covers fetch() and every relationship below
class Contact_Model extends Rsx_Site_Model_Abstract
{
    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function client() { return $this->belongsTo(Client_Model::class); }

    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function tasks()  { return $this->hasMany(Task_Model::class); }
}
```

```javascript
const project = await Project_Model.fetch(123);
const client  = await project.client();   // belongsTo -> Model or null
const tasks   = await project.tasks();    // hasMany   -> Model[]
const subject = await activity.subject(); // morphTo   -> the polymorphic model
```

### The plural cap

A lazy `await record.things()` is ONE gated `fetch()` per related id, so it is capped at **`rsx.model_fetch.max_relationship_records`** (default 500) and **THROWS** past it rather than returning a partial set - a partial set the caller cannot detect is worse than an error.

**A relationship that outgrows the cap wants its own paginated `#[Ajax_Endpoint]`, not a bigger number.** Return a page plus the pagination info the screen needs.

### fetch_cached

```javascript
const client = await Client_Model.fetch_cached(id);   // in-memory, promise-deduped
```

For **non-critical display data** - names, labels, log history. The cache stores promises, so concurrent calls share one request; `fetch()`/`fetch_or_null()` warm it. It resets automatically on SPA navigation.

**Do NOT use it for data that must be fresh after edits.** Manual reset, three forms:

```javascript
Rsx_Js_Model.orm_cache_reset();                        // everything
Rsx_Js_Model.orm_cache_reset('Contact_Model');         // one model
Rsx_Js_Model.orm_cache_reset('Contact_Model', 5);      // one record
```

## Data-fetching policy - the ORM endpoint is the way

- **The model lacks `fetch()`**: add one, with `#[Ajax_Endpoint_Model_Fetch]` and its `#[Auth]`. For a FRAMEWORK model (in `system/`), create a class override in `rsx/models/`. **Do NOT create separate controller endpoints to fetch single records - this duplicates ORM functionality and is an anti-pattern.**
- **The model is not available in the JS bundle**: **STOP and ask the developer.** Bundles should include the models they need. Do not create workaround endpoints without approval.
- **Custom Ajax endpoints require developer approval**, and are only for:
  - aggregations, batch operations, or complex result sets;
  - system/root-only models intentionally excluded from a bundle;
  - queries beyond a simple id lookup.

## In an SPA action

```javascript
async on_load() {
    const project = await Project_Model.fetch(this.args.id);
    const [client, tasks] = await Promise.all([project.client(), project.tasks()]);
    this.data.project = project;
    this.data.client  = client;
    this.data.tasks   = tasks;
}
```

Details: `php artisan rsx:man model_fetch`.
