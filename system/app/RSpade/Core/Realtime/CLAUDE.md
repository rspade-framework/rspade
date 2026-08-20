# Realtime WebSocket System

## Architecture

PHP (authority) → Redis pub/sub → Node.js relay → WebSocket → Browser

- **PHP**: Issues HMAC-signed tokens, checks permissions, publishes events
- **Node**: Validates tokens, routes messages. Zero business logic, zero durable state
- **Browser**: Ref-counted watcher registry (`Rsx_Realtime.watch()`) — connects lazily,
  dedupes identical topic+filter watches into one server-side subscription, resyncs
  on every (re)subscribe, fetches fresh data via Ajax

There is no message replay/backlog — a relay-side buffer was designed in full and REJECTED
(revisit ONLY if frames ever carry payloads). Messages never carry the changed data (see
security model point 4), so a (re)subscribe resyncing via a fresh callback call
is equivalent to — and simpler than — trying to replay whatever was missed.
Delivery guarantee + the four named residual gaps (unsubscribed-by-design surfaces,
non-model-layer writes, time-derived values, the relay's redis-SUBSCRIBER blip):
`rsx:man realtime` DELIVERY GUARANTEE / RESIDUAL GAPS.
`Realtime_Controller` has NO auth gate of its own; `can_subscribe()` is the
only enforcement point, so a connection token is minted for staff, portal, or
fully anonymous callers alike (see Security Model point 5).

## Key Files

- `Realtime.php` — Static API: `connection_token()`, `subscribe_token()`, `publish()`,
  `subscribed_registry_entries()`, `emitter_hash_get/put()`. Emitter dispatch is gated on
  `Realtime_Emitter_Service::has_watched_emitter_topic()` (see EMITTERS), not on a bare
  "any subscriber" registry check.
- `Realtime_Topic_Abstract.php` — Base class for topic permission checks + `$requires_auth`
- `Realtime_Controller.php` — Ajax endpoints for browser token requests (no auth gate), plus
  the HMAC-authenticated `#[Route]` `/_realtime/subs_changed` relay notify endpoint
- `Realtime_Emissions.php` — Per-request buffer + afterCommit flush for model change
  notifications; touch cascade; emitter dirty-kick (see MODEL CHANGE EMISSION)
- `Model_Changed_Topic.php` — The single core topic for opt-in model changes
  (`{model, id}` hints); signed-in gate (staff OR portal)
- `Realtime_Emitter_Service.php` — `#[Emitter]` discovery + hash-diff recompute engine;
  the write-kicked `#[Debounce(2)]` `run_emitters` task (see EMITTERS)
- `/system/bin/realtime-server.js` — Node.js WebSocket server + subscriber registry writer
- `/system/app/RSpade/Core/Js/Rsx_Realtime.js` — Client watcher registry
- `/system/app/RSpade/Core/Js/Rsx_Js_Model.js` — `watch_changes(id, cb)` model watch sugar
- `Rsx_Model_Abstract.php` — `$realtime` / `$realtime_silent` / `realtime_touch()` + the
  `save()`/`delete()` emission hook (`_realtime_emit_on_write()`, attributed-touch path)
- `Realtime_Touch_Registry.php` — per-process memoized `#[Realtime_Touch]` metadata (manifest
  instance-attr scan + one-time belongsTo FK/parent reflection); `has_realtime_surface()`,
  `overrides_realtime_touch()` (part of the bulk-builder fetch-then-iterate gate)
- `/system/app/Database/RestrictedEloquentBuilder.php` — bulk `update()`/`delete()`
  fetch-then-iterate (per-record `save()`/`delete()`) + `->raw_bulk()` escape hatch; gated on
  `has_realtime_surface()` OR `Model_Lifecycle_Registry::has_lifecycle_surface()`
- `CodeQuality/Rules/PHP/RealtimeTopicAuthCheck_CodeQualityRule.php` — `REALTIME-AUTH-01`
- `CodeQuality/Rules/PHP/RealtimeBulkWrite_CodeQualityRule.php` — `REALTIME-BULK-01` (AST bulk
  write coverage; `raw_bulk`/`realtime_emit`/exception suppressions)

## Session / User Refresh Push (control plane)

A second delivery path over the same relay: a TARGETED CONTROL FRAME (`rsx_rt:control`
channel, `{kind, realm, ...}`) the relay routes by connection STAMPS (realm + session_id,
or realm + site_id + user_id) — NOT by any topic/subscription. `Realtime::push_session_refresh($realm, $session_id)` / `push_user_refresh($site_id, $user_id)` buffer through
`Realtime_Emissions` (same afterCommit/outbox as model emission) and the browser does ONE
deduped `window.location.reload()` on a `{type:'refresh'}` frame — at max(500ms floor, 5s user-idle) and suppressed while the tab is navigating away (beforeunload flag, cleared on pageshow + a 10s watchdog), so it never yanks the initiating tab. Realm ('staff'
|'portal', in the connection token) says which EXPERIENCE a connection's page was minted
on. One browser has ONE session and can hold staff tabs and portal tabs on it at once, so
the realm stamp is what makes a portal-property change refresh the portal tabs and leave
the staff tabs alone - it is not an id disambiguator.

- **Session mutation** (confirmed-different gated): wired at `Session::set_login_user_id()`
  / `set_site_id()` / logout + `Portal_Session::set_portal_user_id()` / logout — a re-save of
  the same value is silent; impersonation begin/stop inherit it; `claim_impersonation()` does NOT push.
- **User record**: `User_Model` save/soft-delete (first_name/last_name/role_id/is_enabled) and
  `User_Permission_Model` grant/deny/remove push the affected staff (site_id, user_id).
- **Always-anchored client**: when realtime is enabled AND `window.rsxapp.session_hash` is a
  non-empty string (a session cookie existed at render), `Rsx_Realtime` holds a system anchor
  connection for the page lifetime (suppresses idle-disconnect) so a page with zero
  subscriptions still receives refresh pushes — on staff SPA, portal, or blade pages. A
  first-visit anonymous page (session_hash null) does NOT auto-connect; it keeps lazy
  connect-on-first-watch().

## Security Model

1. **Connection token**: HMAC-signed with APP_KEY, contains user_id (nullable) + site_id +
   session_id, expires in 60s. Minted regardless of login state.
2. **Subscribe token**: Per-topic, HMAC-signed, checks `Topic::can_subscribe()` before
   issuing — the SOLE runtime enforcement boundary in the system
3. **Site scoping**: Messages only route to connections with matching site_id (narrows
   reachability; is NOT itself a permission check — see point 5)
4. **No confidential data**: Messages are notifications only — clients fetch data through Ajax
5. **`$requires_auth`** (`Realtime_Topic_Abstract`, default `true`): declared intent, not a
   runtime gate. Drives `REALTIME-AUTH-01` (`rsx:check`): `true` without a recognizable
   auth-check pattern in `can_subscribe()` → high-severity violation; `false` → flagged for
   mandatory manual review, suppressed only via `@REALTIME-AUTH-01-EXCEPTION - <rationale>`

## Token Format

`base64(json_payload).hmac_sha256_hex`

Connection: `{realm, user_id, site_id, session_id, exp}` — `user_id`/`session_id` null for an
anonymous caller. `Realtime::connection_token()` picks the facade by the REALM OF THE REQUEST
(`Rsx_Portal::is_portal_request()`), never by who is signed in, and `session_id` is gated on
`has_session()` in both realms so opening a socket never CREATES a session as a side effect.
`_current_site_id()` forks identically — it must, or Node's site-match rejects every portal
subscribe. (Branching on `Portal_Session::is_logged_in()` sent unauthenticated portal callers
down the staff branch, where `get_session_id()` minted a staff session and set the `rsx` cookie
on a portal response — audit `docs.dev/audits/portal_realm_session_audit_2026_08_09.md`.)
Subscribe: `{topic, filter, site_id, exp}`

## Model Change Emission

Models opt in with `public static $realtime = true`. A successful `save()`/`delete()`
(the `Rsx_Model_Abstract` write choke point) queues a `Model_Changed_Topic` message
`{model, id}` via `Realtime_Emissions`, flushed on transaction COMMIT (rollback discards;
no-transaction writes flush immediately). Deduped per `model|id|site_id`. `site_id` = row's
own column, else session site, else 0.

- **Bulk model-builder writes ARE covered (fetch-then-iterate)** — `Model::where()->update()/
  ->delete()` on a model with a side-effect surface (a realtime surface: `$realtime`,
  `#[Realtime_Touch]` relation, or overridden `realtime_touch()`; OR an overridden `after_*`
  lifecycle hook) loads the affected rows and runs each through its own `save()`/`delete()`, so
  realtime frames + `after_*` hooks fire PER RECORD via the single-write path. A write-depth
  counter on `Rsx_Model_Abstract` (`_is_inside_single_model_write()`) marks a model's own
  single-row write so the builder runs it RAW (no recursion) and only fetch-then-iterates a
  genuine user bulk. NO row cap; the whole op is one `DB::transaction` (mid-iterate failure
  rolls back, buffer fires nothing). SoftDeletes `delete()` fires `after_delete` once (the
  internal `deleted_at` write re-enters raw). `->raw_bulk()` = per-statement escape hatch (one
  raw statement, fires nothing); a raw `DB::raw()` value in an update set also forces raw. A
  plain no-surface model stays one raw statement. Only `DB::table()`, `->getQuery()`/`->toBase()`
  drops, and raw SQL still bypass → `realtime_emit()`. Async successor backlogged (B-65).
- **Touch cascade** — a ladder: `#[Realtime_Touch]` (bare marker on a child's **belongsTo**
  method) is THE way — fires independent of the child's own `$realtime`; parent queued BY
  IDENTITY (no hydration) unless the parent has onward touches; belongsTo-only, fails loud
  otherwise (`Realtime_Touch_Registry`). `realtime_touch(): array` (parent INSTANCES) is the
  **escape hatch** for a polymorphic/conditional parent (Task→taskable). Both walk BFS,
  cycle-guarded (visited `model|id`) + depth-capped (25), never write the parent row, notify
  regardless of the parent's own `$realtime`.
- **Bulk WS frames** — `Realtime_Emissions::_publish_grouped` (both transmit paths) coalesces
  per `(site, model)` into `{model, ids:[...]}` frames (chunked at `FRAME_ID_CHUNK = 100`);
  single-record group keeps `{model, id}`. Node + client matchers match an `id` filter against
  a frame whose `ids` contains it.
- **`$realtime_silent = true`** — writes do NOT kick the emitter engine (infra models:
  session, mail/SMS queues). Separate axis from `$realtime`.
- **Deletes emit identically** — subscriber refetches → not-found/error state.
- **Filter** `{model, id}` = one record; `{model}` alone = collection watch (all changes to
  that model on the site), free via shallow filter matching.
- **Manual emission** — `$record->realtime_emit()` (public, `Rsx_Model_Abstract`) queues the
  SAME change (touch cascade included) for a write that bypassed the model layer
  (query-builder/bulk/raw, import, derived value). Ignores `$realtime` (calling it IS the
  intent), no-op when disabled, fails loud on empty id, ONCE per `(model,id)` per request
  (buffer/`$sent_keys` dedup, never a call-time latch — a rolled-back generation leaves no
  trace so a later emit still succeeds). Runs `Realtime_Emissions::request_emit()`.
- **Web vs CLI transmission** — flush captures intent + dedups identically, but WEB
  (`!app()->runningInConsole()`) stages into a request OUTBOX (deduped `model|id|site`
  across the request) and publishes ONCE at `app()->terminating()` (after the response;
  `public/index.php` calls `$kernel->terminate()`); CLI transmits at flush (a task emits as
  it works). Not a choice — automatic.

## Emitters

`#[Emitter('Topic')]` on a public static `(int $site_id, array $filter): mixed`. Attribute is
reflection metadata only (never define a class; linter strips `use`). Discovery = manifest
attribute scan (like `#[Task]`). Emitter topics are ordinary topic classes; emitters run
HEADLESS (no session — everything from `$site_id`/`$filter`).

- **Optional model constraint** — `#[Emitter('Topic', 'Model_Name')]` (2nd positional arg)
  scopes the emitter to registry entries whose `filter.model` matches. Narrows the dispatch
  gate (`has_watched_emitter_topic`), `emitter_topics()`, and the engine loop. REQUIRED to
  compose a derived value onto the shared `Model_Changed_Topic` without making every model
  write kick the task / fan out to every watched record (preserves no-churn). Absent →
  unconstrained (custom topics unchanged).

- **NO timer, write-kicked** — any non-silent model write marks emitters dirty; the commit
  flush dispatches `Realtime_Emitter_Service::run_emitters` (`#[Task]` `#[Debounce(2)]`) IFF
  enabled AND an emitter is registered AND the subscriber registry holds a subscription to a
  topic that has an emitter (`has_watched_emitter_topic()` — stricter than "any subscriber").
- **Hash-diff** — per work item `(site_id, topic, filter)`, `sha1(json_encode(value))` vs the
  stored hash (`rsx_rt:em:{identity}`, TTL 86400s). Same → nothing; changed → `publish(topic,
  filter, site_id)`; **MISSING → store AND PUBLISH** (the belt). A work item exists only
  because a live subscription for it is in the registry, so an absent baseline means the
  subscribe->seed race, a wiped redis (every maintenance window restarts it empty), or TTL
  expiry under a long-lived quiet subscription (which fires no new-member event ever) — all
  three need the frame. Suppressing here silently swallowed the first change after any hash
  gap. Identity includes `class::method` (two emitters on one topic must not clobber each other).
- **Baselines are seeded at SUBSCRIBE time** — `seed_subscriptions` (`#[Task]`, deliberately
  UNMANAGED: coalescing enqueue drops params and would lose seed targets) computes each
  serving emitter once and stores the baseline with NO publish, because the subscribe ack IS
  the resync signal. Driven by the relay's new-member notify: `rewrite_registry()` diffs the
  written member set against an in-memory baseline that advances only on a successful write
  (a SADD-returns-1 check is impossible — the write is a full-state DEL+SADD-all rewrite),
  coalesces additions behind a one-shot 200ms timer, and POSTs them to
  `{REALTIME_PHP_ORIGIN}/_realtime/subs_changed` (default `http://127.0.0.1`, Host from
  APP_URL) signed `X-Realtime-Signature: hex(hmac_sha256(raw_body, APP_KEY))`.
  `Realtime_Controller::subs_changed` verifies signature + a 60s timestamp window (403 +
  `Log::warning` on failure, never throws), filters to emitter-served entries (the relay stays
  dumb and reports ALL new members), and dispatches the task. Fire-and-forget with every error
  handler attached; a dropped notify costs a baseline, which the belt then covers.
- No template-app consumer ships yet (proven by framework tests + a fixture emitter); first
  real consumer is the planned notification-badge count.

## Client Contract (Rsx_Realtime.js) — additions for model changes

- **Model overloads**: `this.subscribe(Model_Class, id, cb)` and `this.subscribe(record, cb)`
  expand to `('Model_Changed_Topic', {model, id}, cb)`; `Model.watch_changes(id, cb)` for
  non-component code. `watch()` itself stays string-topic only.
- **FAIL LOUD**: subscribing to a model without `$realtime = true` (no baked `__REALTIME`
  stub static) THROWS — before the realtime-disabled no-op, so it surfaces in every env.
- **IDEMPOTENT** per `(topic, filter)` per component instance: a repeat identical
  `this.subscribe()` returns the existing handle, adds no callback (the `on_ready()` →
  `reload()` → `on_ready()` loop would otherwise stack callbacks into an exponential snowball).
  Live dispatch is trailing-debounced ~250ms; resync fires immediately.

## Redis Keys

- `rsx_rt:{site_id}` — pub/sub channel. Message `{topic, data, site_id, ts}`
- `rsx_rt:subs` — Node subscriber registry SET. Members = canonical JSON
  `{site_id, topic, filter}` (filter keys sorted). Full-state rewrite by Node on every
  subscribe/unsubscribe/close (MULTI DEL+SADD+EXPIRE 90; refresh 60s; DEL on boot). Node
  death → key expires → emitters stop (self-heal).
- `rsx_rt:em:{identity}` — last published emitter value-hash, `identity =
  sha1(class::method|site|topic|canonical_filter)`, TTL 86400s. Written by BOTH the seed
  engine (unconditional put, also refreshes the TTL on resubscribe) and the run loop.

## Client Watcher Registry (`Rsx_Realtime.js`)

- `watch(topic, filter, callback)` returns `{stop()}`. Ref-counted by a canonical key
  (`topic + sorted-filter JSON`) — the Nth watcher of an identical key only registers a
  local callback, no new token request or server-side subscription.
- On subscribe ack (`{type:'subscribed', sub_id}`, `sub_id` = the canonical key), every
  callback for that key fires once with `(null, {resync: true, ...})` — this is the resync
  signal, fired identically on first subscribe and on every post-reconnect resubscribe.
- Live messages are re-matched client-side against each watch entry's own filter
  (`_matches_filter`) before dispatch — the server has already filtered per-connection, but
  a single connection can hold multiple watches on the same topic with different filters,
  and the server's `{type:'message'}` frame carries no `sub_id` to disambiguate.
- `Component.prototype.subscribe` (patched at boot, always — safe no-op when
  `!window.rsxapp?.realtime_url`) wraps `watch()` and pushes the handle into
  `this._realtime_subs`; the existing `on_stop` patch calls `.stop()` on each.
- `on_state_change(callback)` — `connecting | connected | disconnected | reconnecting`.
- Lazy connect: no connection exists until the first `watch()`/`subscribe()` call. When the
  last watch stops, the socket closes after a short grace period if nothing new starts
  watching — an intentional idle close, which does NOT trigger reconnect logic.

## Configuration

`config('rsx.realtime.*')` (`enabled`, `ws_port`), backed by `.env`:
```
REALTIME_ENABLED=false
REALTIME_WS_PORT=6200
REALTIME_PHP_ORIGIN=http://127.0.0.1   # optional; where the relay reaches PHP for subs_changed
```
Config-only (not env): `load_gate_timeout_ms` (1500) and `stale_reload_after_ms` (3600000,
0 disables) — both surfaced under `window.rsxapp.realtime`.
The client connect URL is not configured — it is derived as `wss://{Rsx::get_hostname()}/ws`
(in web mode, the browsed host). `realtime-server.js` reads `REALTIME_WS_PORT` from `.env`
directly (no access to Laravel's config cache) — keep `ws_port` in sync if changed.
