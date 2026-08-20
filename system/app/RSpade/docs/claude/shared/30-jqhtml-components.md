<!-- single-source: never duplicate into another fragment. -->

## JQHTML COMPONENTS

RSX's component system. A component is up to three co-located files sharing one name: `Foo.jqhtml` (markup), optional `Foo.js` (class holding lifecycle hooks/logic/state), `Foo.scss` (its complete look). No registration — the manifest discovers them. Invoked from a template as `<Foo $arg=value>content</Foo>`, or from JS as `$(sel).component('Foo', {arg: v})`.

**`<Define>` IS the element**, not a wrapper: `<Define:My_Button tag="button" class="btn">`. Interpolation `<%= safe %>` / `<%!= html %>` / `<% js %>`; `<%= content() %>` renders caller content; `@click=this.handler` binds child elements.

**Three state buckets, never mixed**: `this.args` (arguments in; read-only inside `on_load()`), `this.data` (Ajax-loaded and the source of truth for display; writable ONLY in `on_create()`/`on_load()`, frozen otherwise), `this.state` (arbitrary UI/editor state, writable anywhere).

**Lifecycle hooks** in order: `on_create()` (sync defaults) -> `on_render()` (own markup; may fire repeatedly) -> `on_load()` (async fetch; DOM, `this.state` and `this.args` writes THROW here) -> `on_loaded()` -> `on_ready()` (children ready) -> `on_stop()`.

**Access**: `this.$` (element), `this.$sid('x')` (child element tagged `$sid=` in the template — template-only, never set from JS), `this.sid('x')` (child component instance), `$(sel).component()`. Events: `this.trigger(name, data)` / `child.on(name, cb)`.

**Re-render**: `reload()` re-runs `on_create()` -> `on_load()` -> render (use when `this.args` changed); `render()`/`redraw()` re-executes the template only; `stop()` destroys. Ajax belongs in `on_load()` — never refetch from a handler.

**Compose pages from named components** — an Action template reads as component invocations, not `<div class>` soup; each component owns its whole look in its own SCSS.

Skill `rspade:jqhtml` (references `template-syntax`, `lifecycle-and-state`, `component-api-events`, `semantic-composition`): syntax gotchas, hook decision matrix, editor pattern, reserved names, complexity tiers. `rsx:man jqhtml`.
