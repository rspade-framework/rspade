---
name: realtime
description: Making RSX pages live over WebSocket - $realtime models and the Model_Changed_Topic, #[Realtime_Touch] parent cascades, subscribing from a component in on_create(), refresh() vs reload(), writing a topic class with can_subscribe(), #[Emitter] derived values, manual realtime_emit(), bulk-write interactions, session/user refresh push, and the residual gaps. Use when a screen must update itself when data changes elsewhere, when adding a topic or emitter, or when debugging a page that does not update or updates too much.
---

# Realtime

PHP publishes via Redis; a Node relay pushes to the browser. **Messages are notification-only - never confidential data.** A frame says "something changed, go look"; the client refetches through ordinary gated Ajax. That is what makes the whole system safe by construction.

---

## Making a page live, end to end

**1. Mark the model.**

```php
class Client_Model extends Rsx_Site_Model_Abstract {
    public static $realtime = true;      // every committed save()/delete() publishes
}
```

Each committed write publishes a `Model_Changed_Topic` frame `{model, id}` - deduped per record per request, and discarded on rollback.

**2. Subscribe in `on_create()`, refresh in the callback.**

```javascript
class Client_View_Action extends Spa_Action {
    on_create() {
        this.subscribe(Client_Model, this.args.id, () => this.refresh());
    }
    async on_load() {
        this.data.client = await Client_Model.fetch(this.args.id);
    }
}
```

That is the whole pattern. `on_create()` placement matters: a subscription registered **before** the first load GATES that load on subscription establishment, so exactly ONE fetch happens, strictly after the subscription is live, and the initial resync is swallowed. No fetch-then-resync duplicate, no race.

The gate is timeboxed by `rsx.realtime.load_gate_timeout_ms` (default 1500, config not env) - one of the framework's two sanctioned timeouts, because it degrades to a WORKING outcome: a slow or down relay still loads the page, and the late resync heals it.

Subscribing in `on_ready()` instead is the secondary fetch-then-revalidate pattern - correct, just one extra fetch.

### The subscribe forms

```javascript
this.subscribe(Client_Model, id, cb);                 // model + id (most common)
this.subscribe(record, cb);                           // a fetched record instance
this.subscribe('Contact_Updated_Topic', {id: 5}, cb); // an explicit topic + filter
Client_Model.watch_changes(id, cb);                   // outside a component
const w = await Rsx_Realtime.watch(topic, filter, cb);// returns {stop(), established}
Rsx_Realtime.on_state_change(s => ...);               // connecting|connected|disconnected|reconnecting
```

Component subscriptions are **ref-counted** (N components on the same topic+filter share ONE server-side subscription), **auto-stop on destroy**, and are **idempotent per (topic, filter)** for a component instance - a repeat call returns the cached handle rather than registering a second callback. Subscribing to a model that is not `$realtime` **throws** (fails loud).

---

## `refresh()`, not `reload()`

**`refresh()` = `reload(false)`: identical refetch, but repaints only if `this.data`'s JSON snapshot changed.** `reload()` refetches and repaints unconditionally - child DOM destroyed and recreated, handlers rebound, transient UI state lost (open menus, scroll position, text selection).

Notifications are content-free hints, deduped per record, and often arrive for changes that do not alter THIS component's payload (another field, a hash-diffed emitter re-kick, a resync on reconnect). With `refresh()` all of those are free. **With `reload()`, every notification visibly churns the component for no reason, and a busy model turns the page into a strobe.**

```javascript
this.subscribe(Client_Model, id, () => this.refresh());          // SERVER says maybe
async save() { await Controller.save(...); this.reload(); }      // USER did something
```

**Rule of thumb: the SERVER telling you something may have changed -> `refresh()`; the USER doing something that must be reflected -> `reload()`.** They share one debounce queue, and a queued `reload()` outranks a queued `refresh()`.

### Write every callback as an idempotent refetch

Every (re)subscribe fires the callback once with `meta.resync === true` and `data === null` **instead of replaying missed messages**, and reconnect resyncs always fire. **There is no replay buffer, by design** - resync-on-(re)subscribe replaces it, and revisiting that is only worth it if frames ever start carrying payloads. So a callback must never assume it is being told *what* changed.

---

## Cascading to a parent (touch)

When a child changes and a PARENT's list or aggregate must repaint, use the ladder.

**Rung 1 - `#[Realtime_Touch]`, and this is THE way.** A bare marker on the child's **belongsTo** relationship method:

```php
class Contact_Model extends Rsx_Site_Model_Abstract {
    public static $realtime = true;

    #[Realtime_Touch]                       // a Contact write also emits its Client
    #[Relationship]
    public function client() { return $this->belongsTo(Client_Model::class, 'client_id'); }
}
```

It fires independently of the child's own `$realtime`. It is **belongsTo-only** and fails loud on anything else.

**Rung 2 - `realtime_touch(): array`, the escape hatch** for a polymorphic or conditional parent (e.g. a Task whose parent is its `taskable`):

```php
public function realtime_touch(): array {
    $parent = $this->taskable;              // return parent INSTANCES, not ids
    return $parent ? [$parent] : [];
}
```

The cascade is **cycle-guarded and never writes the parent** - it emits on the parent's behalf, it does not touch a timestamp.

---

## Writing a topic class

Topics live in `/rsx/lib/topics/` and are named `{Model_or_Feature}_{Event}_Topic`.

```php
class Contact_Updated_Topic extends Realtime_Topic_Abstract {
    public static function can_subscribe(array $filter = []): bool {
        return Session::is_logged_in();
    }
}
```

**`can_subscribe()` is the SOLE enforcement boundary.** `Realtime_Controller` has no auth gate of its own - a connection token is minted for staff, portal and anonymous callers alike - so whatever this method returns is the entire access decision. It runs in the context of the caller's actual session, so use whichever facade fits the topic's domain (`Session::`, `Portal_Session::`, `Permission::`, `Portal_Permission::`).

```php
class Admin_Alert_Topic extends Realtime_Topic_Abstract {
    public static function can_subscribe(array $filter = []): bool {
        return Permission::has_role(User_Model::ROLE_ADMIN);
    }
}

class Weather_Updated_Topic extends Realtime_Topic_Abstract {
    // @REALTIME-AUTH-01-EXCEPTION - public marketing widget; frames carry no record data
    public static bool $requires_auth = false;
    public static function can_subscribe(array $filter = []): bool { return true; }
}
```

`$requires_auth` (default `true`) declares intent rather than gating anything, and drives **`REALTIME-AUTH-01`** (`rsx:check`): `true` without a recognizable auth check inside `can_subscribe()` is flagged **high severity**; `$requires_auth = false` is flagged for **mandatory manual review** until suppressed with `// @REALTIME-AUTH-01-EXCEPTION - <rationale>`. A public topic can never ship silently.

**Site scoping is not a permission check.** Frames only route to connections matching the publisher's `site_id`, and server-side filters only deliver matching messages - both NARROW reachability; neither answers "may this person subscribe".

---

## Publishing by hand

```php
Realtime::publish('Contact_Updated_Topic', ['id' => $contact->id]);   // no-op when disabled
$record->realtime_emit();                                             // model-change frame + cascade
```

`realtime_emit()` is the remedy for a write that bypassed the model layer (`DB::table()`, raw SQL, an import, a derived value): it publishes the same `{model, id}` change including the touch cascade, **ignores `$realtime`**, needs a persisted id, fires once per (model, id) per request, and is a no-op when realtime is disabled.

**Batching**: in a web request every emission goes to an outbox and transmits once after the response, coalesced per site+model into `{model, ids:[...]}` frames (an `{id}` watcher matches a frame whose `ids` contains it). CLI transmits at commit.

---

## Bulk writes

`Model::where(...)->update()` / `->delete()` on a model with a side-effect surface goes **fetch-then-iterate** (the mechanism belongs to the ORM docs), so realtime frames and the `after_*` hooks fire **PER RECORD**, with no row cap.

- **`->raw_bulk()` fires NOTHING** - one raw statement, no frames, no hooks. Same for `DB::table()` and raw SQL; use `realtime_emit()` there.
- A `DB::raw()` value in the update set also forces the raw path.
- **`REALTIME-BULK-01`** (`rsx:check`) flags uncovered bulk writes. It cannot see another program's writes at all.

`$realtime_silent = true` marks infrastructure models that must never kick emitters.

---

## Emitters (derived and computed values)

A model frame says a ROW changed. An emitter says a COMPUTED value changed.

```php
#[Emitter('Dashboard_Kpi_Topic', 'Invoice_Model')]     // 2nd arg = model constraint
public static function unpaid_total(int $site_id, array $filter): array {
    return ['total' => Invoice_Model::where('status_id', Invoice_Model::STATUS_UNPAID)->sum('amount')];
}
```

The value is recomputed and **hash-diffed - it publishes only when it actually changed**. **There is NO timer**: non-silent model writes kick a `#[Debounce(2)]` recompute task, and that task is gated to run only while a live subscriber watches an emitter topic. The optional second argument is a model constraint, REQUIRED to compose a derived value onto the shared `Model_Changed_Topic` - which is what keeps a page on the single `subscribe(Model, id)` idiom without losing the no-churn gate. `#[Emitter]` is reflection metadata; never define the attribute class.

**Aggregate ladder, in order of preference**: touch cascade (the inputs are model writes) -> `realtime_emit()` (the writer knows an aggregate moved) -> `#[Emitter]` (computed, cross-record, or non-model inputs).

**Delivery guarantee**: baselines are SEEDED AT SUBSCRIBE time - the relay diffs its registry rewrite and POSTs new members to `/_realtime/subs_changed` (HMAC-signed with `APP_KEY`, 60s window), and PHP stores baselines without publishing. **At write time an ABSENT baseline therefore PUBLISHES rather than seeding silently.** A work item only exists because a live subscriber exists, so "absent" means the subscribe/seed race, a wiped redis (every maintenance window restarts it empty), or 24h TTL expiry under a quiet subscription - all three need the frame, and **over-emitting is harmless** (content-free doorbells, idempotent refetches). If `REALTIME_PHP_ORIGIN` is unreachable, seeding simply does not happen and the absent-baseline publish covers it.

---

## Refresh push and reconnect (control plane)

Separate from topics: a session mutation (login/logout/identity/tenant change) or a staff-user mutation (name/role/`is_enabled`, soft-delete, ACL change) pushes a TARGETED `{type:'refresh'}` to the matching live connections. Those tabs do ONE deduped `window.location.reload()` at max(500ms floor, 5s user-idle), **suppressed while the tab is navigating away**, so it never yanks the initiating tab mid-interaction. Routing is by connection stamps (realm + session id), never a subscription. `Realtime::push_session_refresh($realm, $session_id)` and `push_user_refresh($site_id, $user_id)` are framework-internal and already wired at the mutation sites - app code does not call them.

**Stale-reconnect reload**: a (re)auth after more than `rsx.realtime.stale_reload_after_ms` out of touch (default 3600000; 0 disables) - measured as max(socket downtime, wall-clock drift, since a slept laptop's `onclose` fires on resume) - schedules ONE reload through that same path, plus up to 10s herd jitter. **Resync fixes stale DATA; only a reload fixes stale CODE.**

**Auto-connect**: with realtime enabled and a non-empty `window.rsxapp.session_hash`, the client holds a system anchor connection for the page lifetime, so a page with zero subscriptions still receives refresh pushes. A first-visit anonymous page does not auto-connect (lazy connect-on-first-watch), and auto-connect changes nothing about `watch()`/`subscribe()` semantics.

---

## Deployment

`.env` -> `REALTIME_ENABLED=true`, `REALTIME_WS_PORT=6200`. Run `node system/bin/realtime-server.js` under a process supervisor and proxy `/ws` in nginx. **There is no client URL to configure**: it is derived as `wss://{Rsx::get_hostname()}/ws` from the browsed host, and the scheme follows the page protocol, so a plain-http loopback/dev page uses `ws://` and realtime works headlessly. `REALTIME_PHP_ORIGIN` (default `http://127.0.0.1`) is how the relay calls back into PHP - a deployment fact.

---

## Residual gaps (named honestly)

1. **Unsubscribed surfaces are stale by design.** Edit forms and modals deliberately do not subscribe - fields must not shift under the user's hands. This is a per-surface application choice.
2. **Only model-layer writes emit.** Raw SQL, `DB::table()` and external processes emit nothing; `realtime_emit()` is the remedy.
3. **Time-derived values never emit** - the sneakiest hole. "Overdue" arriving by the passage of time involves no write, so nothing publishes. **The sanctioned remedy is client-side recomputation**: carry the deciding timestamp in the payload the component already fetches and derive the state in the browser. There is deliberately no server-side scheduler for this - that would be a poll, which the NO-TIMER rule exists to prevent.
4. **Relay redis-subscriber blip**: if the relay's redis SUBSCRIBER drops and reconnects while browser sockets stay up, frames in that gap are lost and nothing resyncs. Small window; the relay PROCESS dying is NOT this case (every socket closes, so everyone resyncs).

---

## Testing and troubleshooting

`rsx:debug /path --user=1 --console` renders the page headlessly with realtime live (the harness page uses `ws://`), so a subscription that throws or a callback that loops shows up in the console output. Drive a change from a second process (`rsx:ajax`, `rsx:task:run`) and re-render.

- **Page never updates.** The model is not `$realtime`; or the write went through `raw_bulk()`/`DB::table()`; or the value is time-derived (gap 3).
- **Subscribing throws.** The target model is not `$realtime` - that is the fail-loud contract, not a bug.
- **The page strobes / flashes on every change.** A callback calling `reload()` instead of `refresh()`.
- **Two fetches on load.** The subscription was registered in `on_ready()` rather than `on_create()`, so the load was not gated.
- **A component reloads N times per message.** A non-idempotent subscribe registered outside `on_create()` - each `reload()` added another callback.
- **Nothing arrives anywhere.** `REALTIME_ENABLED`, the relay process, the nginx `/ws` proxy - and note that under maintenance mode frames are deliberately dropped and the registry reads empty.

Details: `php artisan rsx:man realtime`. Colocated: `system/app/RSpade/Core/Realtime/CLAUDE.md`. Related: `rspade:jqhtml`, `rspade:background-tasks`, `rspade:auth-gates`.
