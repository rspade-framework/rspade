# rsx/theme/components/realtime — the connection-state indicator

## WHAT IS HERE

- `Realtime_Status_Badge.jqhtml` / `Realtime_Status_Badge.js` /
  `realtime_status_badge.scss` — a small "Connection lost" pill that appears in the header
  only during a genuine realtime outage, and renders nothing at all the rest of the time.

That is the whole group: one component, three files.

## HOW IT IS USED

Mounted in BOTH shell headers, beside the notification and user chrome:
`rsx/app/frontend/Frontend_Spa_Layout.jqhtml` and `rsx/portal/Portal_Layout.jqhtml`.

It is a state OBSERVER, not a subscriber: it registers `Rsx_Realtime.on_state_change()` in
`on_create()` (storing the unsubscribe), tears it down in `on_stop()`, and subscribes to no
topic. Its visibility lives in `this.state` and is re-applied in `on_render()`, so a layout
re-render never loses it.

**The state semantics are the load-bearing part.** The realtime client connects LAZILY, so
an idle `disconnected` means "nothing is watching" and must never warn; a real drop passes
through `reconnecting`, and a `disconnected` that FOLLOWS a trying state is the client's
confirmed-outage announcement. The badge therefore arms a 5s grace timer on
`connecting`/`reconnecting`, shows on a confirmed offline announcement or when the timer
fires without a `connected`, and hides on `connected` or an idle `disconnected`. The full
reasoning is the docblock at the top of `Realtime_Status_Badge.js` — read it before
touching the state machine.

Registry row: `rsx/resource/conventions/semantic_component_registry.md` (it is filed as a
state observer, not content vocabulary).

## HOW TO CUSTOMIZE

- **Restyle** in `realtime_status_badge.scss`; the wording lives in the template's `title`
  attribute and its `__text` span.
- The 5s grace is a deliberate constant in the component, written down beside its value —
  if you change it, change the comment that justifies it. It bounds only how long a silent
  reconnect may run before the user is TOLD; it never bounds any work.
- **Do not make it subscribe to a topic.** The moment it does, it stops being free and
  starts holding a subscription open on every page.
- Deleting it is two lines (one per layout template); nothing else references it.

## RELATED

Skill `rspade:realtime` (`Rsx_Realtime`, the connection model) · `rsx:man realtime` ·
`rsx/app/frontend/Frontend_Spa_Layout.jqhtml`, `rsx/portal/Portal_Layout.jqhtml` ·
`rsx/resource/conventions/semantic_component_registry.md`
