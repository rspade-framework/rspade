---
name: actors-and-authorship
description: Displaying who wrote a record and adding your own actor model in RSpade - the stamp matrix deciding WHICH identity a save() records, get_created_by_author(), the <Record_Author> component, and extending Rsx_Actor_Model_Abstract / Rsx_Site_Actor_Model_Abstract with get_printed_name() and get_view_profile_url(). Use when a screen must show "created by X", when a model can sign in or be named in a created_by_type / updated_by_type / deleted_by_type pair, when class-overriding User_Model / Portal_User_Model / Login_User_Model, or when responding to an ACTOR-01 or SEALED-01 manifest-build failure.
---

# Actors and Authorship

An **actor** is anything that can sign in or stamp authorship - the entity a `created_by_id`/`created_by_type` pair points at. Every table carries those pairs and `Rsx_Model_Abstract::save()` fills them automatically. The actor layer is the other half of that deal: whatever the pair points at can always answer **"what is your name?"** and **"where can THIS viewer see you?"**.

### The stamp matrix

Which of the three identities gets stamped depends on the actor and on whether the sites line up. "Sites match" = the model is site-scoped AND the record's `site_id` equals the actor's session site AND that site is not 0.

| actor | sites match | stamp |
|---|---|---|
| portal user (portal request) | yes | `Portal_User_Model` |
| portal user (portal request) | no | nothing (NULL - a portal account has no cross-site identity; **never lie in an audit column**) |
| staff (web/CLI/API/impersonating) | yes | `User_Model` |
| staff | no | `Login_User_Model` |
| nobody signed in | - | nothing (NULL) |

**Impersonation is invisible here by design** - the stamp records the EFFECTIVE actor, not the person behind them.

INSERT sets both authorship pairs; UPDATE sets the updated pair only, and only when the write was happening anyway (a clean `save()` stays a no-op). **An explicitly assigned pair always wins, and the pair is ONE unit - set one half and you must set both.** A soft delete stamps the deleter in the SAME UPDATE that sets `deleted_at`, same matrix, same explicit-wins; **`restore()` CLEARS the pair** (a restored record is not deleted by anybody); a HARD delete stamps nothing and cannot.

Read the raw side with the stock morph relations every model has: `$record->created_by` / `->updated_by` / `->deleted_by` -> the actor model or null. Bulk `->update()`/`->delete()` stamp per record (fetch-then-iterate); `->raw_bulk()`, `DB::table()` and raw SQL stamp nothing. See also `rsx:man model_normalization`.

The framework ships three: `User_Model` (staff membership in one site), `Portal_User_Model` (portal account, per site), `Login_User_Model` (the cross-site login identity, not site-scoped). They stay separate identities on purpose; authorship is polymorphic instead.

---

## Part A - Displaying authorship

Server side, on any model:

```php
$record->get_created_by_author();   // ['name' => string, 'url' => ?string] | null
$record->get_updated_by_author();   // same
$record->get_deleted_by_author();   // same (soft-deleting tables only)
```

`null` means the record is unattributed. Ship the pair with the row from the code that already loaded it - typically inside `fetch()`:

```php
// rsx/models/client_model.php, inside fetch()'s payload builder
$data['get_created_by_author'] = $client->get_created_by_author();
```

Client side, the framework component renders it - hyperlinked when `url` is non-null, plain text when it is null. Content between the tags is the null-author placeholder (default "Unknown"):

```jqhtml
<span>Created <%= Rsx_Time.format_date(_client.created_at) %>
      by <Record_Author $author=_client.get_created_by_author /></span>

<Record_Author $author=_row.get_created_by_author>System</Record_Author>
```

**Why it is explicit and not automatic.** Resolving an author costs a query and discloses a name, so it is asked for rather than riding along on every `toArray()`. And there is deliberately **no "who wrote record X" endpoint** - that would be a new identity-lookup surface over arbitrary records, gated by nothing the record's own `fetch()` already decided.

The N+1 a list page would pay is absorbed by a per-request memo keyed on the **viewer** plus the actor: a datagrid of 200 rows written by 3 people costs 3 lookups, and an identity switch inside one process (a test, a queued task) still gets fresh answers.

---

## Part B - Adding your own actor

1. **Table**: give it `deleted_at` (plus `site_id` if it is tenant-scoped).
2. **Model**: extend the matching abstract and implement the two methods.
3. That is all - soft deletes, the audit pairs and `<Record_Author>` already work.

### Choose the abstract by the TABLE's shape, never by taste

| the table... | extend |
|---|---|
| has `site_id` | `Rsx_Site_Actor_Model_Abstract` |
| does not | `Rsx_Actor_Model_Abstract` |

Extending the site abstract installs the site global scope, the forced `site_id` on write, and the site write locks. Putting those on a cross-site identity table is wrong; omitting them on a site-scoped table is a cross-tenant read leak.

**There is NO shared interface.** The manifest indexes classes, not interfaces, so an interface here could not be imported by the code that needs it (the namespace linter would strip the `use`). Code that must accept either kind tests both class names:

```php
if ($actor instanceof Rsx_Actor_Model_Abstract || $actor instanceof Rsx_Site_Actor_Model_Abstract) { ... }
```

### Soft deletes are mandatory

Both abstracts `use SoftDeletes`. **An actor that could be hard-deleted would leave every audit column that ever named it pointing at nothing**, and no later code could recover the name - the row is gone. Historical authorship must stay readable forever, so the identity row stays forever. The declaration `Rsx_Actor_Model_Abstract::$actor_soft_deletes` is `#[Sealed]`, so redeclaring it in a subclass - the natural way someone tries to opt out - is a manifest-build FATAL (`SEALED-01`; the general `#[Sealed]` contract is in the code-conventions fragment).

### `get_printed_name(): string`

```php
public function get_printed_name(): string
{
    $full_name = $this->get_full_name();
    if ($full_name !== '') { return $full_name; }
    $email = trim((string) $this->email);
    if ($email !== '') { return $email; }
    return 'User #' . (int) $this->id;
}
```

- **NEVER empty.** There is no "no name" answer - a blank audit column is indistinguishable from an unattributed record. Cascade through what the model has and always return something.
- **MUST work on a TRASHED record**, without throwing and without going empty. That is what the soft deletes are FOR.
- **MUST render a deleted actor IDENTICALLY to a live one.** No `(deleted)` / `(inactive)` marker - the model states the name and nothing else. **Decorate in the widget, never in the model** (a screen that wants the marker reads `trashed()` itself).
- **No authorization here.** A name is not a permission decision.

### `get_view_profile_url(): ?string`

```php
public function get_view_profile_url(): ?string
{
    if (Rsx_Portal::is_portal_request()) { return null; }

    if ((int) $this->id === (int) Session::get_user_id()) {
        $own = Auth_Gates::accessible_route('Settings_Profile_Display_Action', Auth_Gates::REALM_STAFF);
        if ($own !== null) { return $own; }
    }

    return Auth_Gates::accessible_route(
        'Settings_User_Management_View_Action',
        Auth_Gates::REALM_STAFF,
        (int) $this->id
    );
}
```

- **Null is a normal answer** - "there is no such page, or you may not see it". Callers render the printed name as plain text.
- **Resolve through the gates, never a hand-rolled role test.** `Auth_Gates::accessible_route($target, $realm, $params)` returns the URL only when the surface exists in this install AND every gate declared ON THE DESTINATION passes for the current session. So a link can never promise a page the viewer would be bounced from, adding a gate to the destination tightens every link to it automatically, and naming a screen a downstream app has removed degrades to plain text instead of throwing. (`can_access()` THROWS for an unknown target - use it only for a surface you know exists.)
- **May differ per realm** for the same record: branch on `Rsx_Portal::is_portal_request()`.
- **NEVER memoize it on the instance.** The answer is about who is ASKING, not about the record. `get_created_by_author()` already memoizes per request AND per viewer.

### ACTOR-01

A manifest-build FATAL: every class the stamp can name must extend the actor layer, and every concrete actor must still resolve `SoftDeletes`.

**The case it exists for**: you class-override one of the three framework actors (a same-named class in `rsx/models/` replacing the framework file) and forget to extend the same actor abstract. The stamp would keep writing that class name into audit columns, and every authorship display would die at read time - far from the change that caused it.

Details: `php artisan rsx:man actors`, `php artisan rsx:man model_normalization`.
