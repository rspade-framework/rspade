<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of the refresh()-vs-reload() rule. -->

## REALTIME (WEBSOCKET)

Pages update themselves when data changes elsewhere: PHP publishes via Redis, a Node relay pushes to the browser, and **frames are notification-only — never confidential data.** A frame says "something changed, go look" and the client refetches through ordinary gated Ajax, which is what makes the system safe by construction. Enabled by `REALTIME_ENABLED` in `.env`.

**Emitting**: `public static $realtime = true;` on a model publishes a `Model_Changed_Topic` `{model, id}` on every committed `save()`/`delete()`. `#[Realtime_Touch]` on a child's belongsTo method cascades to the parent; `Realtime::publish('Some_Topic', $filter)` publishes a topic directly; `$record->realtime_emit()` covers a write that bypassed the model layer (raw SQL, `DB::table()`, `->raw_bulk()` — none of which emit on their own); `#[Emitter]` publishes hash-diffed computed values.

**Subscribing** — canonical placement is `on_create()`, which gates the first load on the subscription (one race-free fetch):

```javascript
on_create() { this.subscribe(Client_Model, this.args.id, () => this.refresh()); }
```

Also `this.subscribe('Topic', filter, cb)` and `Rsx_Realtime.watch(...)` outside components. Subscriptions are ref-counted, auto-stop on destroy, and **throw** if the model is not `$realtime`. Every (re)subscribe fires the callback once as a **resync** rather than replaying missed messages, so **write every callback as an idempotent refetch**.

**`refresh()`, not `reload()`, in a realtime callback.** `refresh()` refetches and repaints only if `this.data` actually changed; `reload()` repaints unconditionally, destroying child DOM and transient UI state. **Rule of thumb: the SERVER saying something may have changed -> `refresh()`; the USER doing something that must be reflected -> `reload()`.**

**A topic class's `can_subscribe(array $filter = []): bool` is the SOLE enforcement boundary** — `Realtime_Controller` has no gate of its own and mints a connection token for anonymous callers too. Site scoping narrows reachability; it is not a permission check.

Skill `rspade:realtime`: topic and emitter authoring, the touch-cascade ladder, bulk-write interactions, `REALTIME-AUTH-01`/`REALTIME-BULK-01`, session/user refresh push, delivery guarantees, residual gaps. Details: `rsx:man realtime`.
