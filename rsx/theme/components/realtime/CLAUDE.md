# rsx/theme/components/realtime — the connection-state indicator

## WHAT IS HERE

- `Realtime_Status_Badge.jqhtml` / `Realtime_Status_Badge.js` /
  `realtime_status_badge.scss` — a small "Connection lost" pill that appears in the header
  only during a genuine realtime outage, and renders nothing at all the rest of the time.

That is the whole group: one component, three files.

## HOW IT IS USED

Mounted in BOTH shell headers, beside the notification and user chrome:
`rsx/app/frontend/Frontend_Spa_Layout.jqhtml` and `rsx/portal/Portal_Layout.jqhtml`.

It is a connection OBSERVER, not a subscriber: it registers `Rsx_Realtime.watch_connection()`
in `on_create()`, stops the monitor in `on_stop()`, and subscribes to no topic. It shows while
the monitor reports `'disconnected'` and hides on `'connected'` or `'disabled'`. The framework
reports `'disconnected'` only after the link has been down for its 5s grace window, so the
badge needs no timer or state machine of its own. Its visibility lives in `this.state` and is
re-applied in `on_render()`, so a layout re-render never loses it.

A registered monitor holds the socket open. On the signed-in pages this badge is mounted on,
the framework's session anchor already holds it, so the badge costs nothing extra.

Registry row: `rsx/resource/conventions/semantic_component_registry.md` (it is filed as a
state observer, not content vocabulary).

## HOW TO CUSTOMIZE

- **Restyle** in `realtime_status_badge.scss`; the wording lives in the template's `title`
  attribute and its `__text` span.
- **Do not make it subscribe to a topic.** It needs none, and a subscription would make every
  page refetch on frames it does not care about.
- Deleting it is two lines (one per layout template); nothing else references it.

## RELATED

Skill `rspade:realtime` (`Rsx_Realtime`, the connection model) · `rsx:man realtime` ·
`rsx/app/frontend/Frontend_Spa_Layout.jqhtml`, `rsx/portal/Portal_Layout.jqhtml` ·
`rsx/resource/conventions/semantic_component_registry.md`
