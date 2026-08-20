# jqhtml Component API and Events

## DOM and component access

`$sid` = "scoped ID" - unique **within one component instance**, so two instances of the same component never collide.

| From inside a component | Returns | Purpose |
|---|---|---|
| `this.$` | jQuery | The component's own root element (NOT `this.$el`) |
| `this.$sid('name')` | jQuery | Child ELEMENT carrying `$sid="name"` |
| `this.sid('name')` | Component / null | Child COMPONENT instance (null if that node is not a component) |
| `this.closest(sel)` / `this.find(sel)` | Component / null | Component-aware traversal - **match COMPONENTS only, never plain divs** |
| `this.$.shallowFind(sel)` | jQuery | Direct-descendant matches only, does not recurse INTO a match - unlike `.find()`, which also returns nested same-class components |
| `this._cid` | string | This instance's unique id; what `$sid` scopes against, exposed as `data-cid` |
| `this.instantiator()` | Component / null | The component whose template rendered this one |
| `$(selector).component()` | Component / null | Get the component instance from any jQuery element |
| `await $(sel).component().ready()` | Promise | Await initialization - rarely needed (`on_ready()` auto-waits for children created during render); use for dynamically created components and Blade page JS. Also takes a callback: `.ready(() => ...)` |

### `$sid` is TEMPLATE-ONLY

`$sid`/`$id` are assigned **only in `.jqhtml` files** (they compile to scoped `id=""` values) and are **never settable from JS**.

**Tagging imperative DOM with `data-id` via `.attr()` plus `[data-id=]` selectors is WRONG.** `data-*` belongs to the args machinery, and the jquery layer THROWS on the setter form. To address DOM you created imperatively, keep **in-memory element references** (a `Map` from key to element) or use CSS classes.

Related discipline: `$sid` targets should be defined in the template and rendered unconditionally - toggle their visibility rather than gating the element behind an `if`, so the reference never disappears.

## Custom component events

```javascript
this.trigger('event_name', data);                                       // fire
this.sid('child').on('event_name', (component, data) => { ... });       // listen
```

- **`.on(event, cb)`** fires on every occurrence. **`.once(event, cb)`** fires once, then auto-removes. Both return `this` for chaining.
- **Both fire immediately if the event already happened**, receiving the DATA stored from the most recent `trigger()` - not `undefined`.
- **`this.invalidate('name')`** clears an event's already-occurred marker (registered handlers stay, they just wait for the next `trigger()`). `render()`/`reload()` do this to `ready` internally.

**The key difference from jQuery**: events fired BEFORE handler registration still trigger the callback when it is registered. This is deliberate - it solves the lifecycle timing problem where a child fires an event before its parent has had a chance to listen. It is also why persistent handlers belong in `on_create()`: registering in `on_ready()` risks infinite loops from event replay.

### `val` vs `input` on form inputs

A subscription that means "the USER changed this" rides **`input`**, never **`val`**. `Form_Input_Abstract`'s `val()` setter fires `val` on EVERY change, including the programmatic one `Rsx_Form` makes when it seeds a loaded record - and because events are sticky, a `val` handler registered in `on_ready()` (the only hook where child components are guaranteed ready) fires IMMEDIATELY with that seeded payload. An interlock wired that way undoes the loaded state the moment the form finishes loading. `input` is raised only by real interaction with the widget.

**Never use `this.$.trigger()` for custom events** - enforced by `JQHTML-EVENT-01`. jQuery's event system has none of the replay semantics above.

### Handler placement

| What | Where | Why |
|------|-------|-----|
| `this.on/once('event', ...)` | `on_create()` | Persists across renders; on_ready() risks infinite loops from event replay |
| `this.sid('child').on/once('event')` | `on_ready()` | Child component events - children are only guaranteed ready here |
| `this.$sid('elem').on('click')` | `on_render()` or `on_ready()` | Child DOM is recreated on render, so it must be re-attached - and MUST deregister first (`.off('click.ns').on('click.ns')`) since `on_render` can fire multiple times |

### Reserved event names

**NEVER** name a custom event `create` / `load` / `loaded` / `ready` / `render` / `rendered` / `stop`. These are jqhtml lifecycle events fired internally (with no payload), so a same-named custom event collides and your handler receives the framework's payload-less firings. Pick a distinct name - `preview_loaded`, not `loaded`.

### Reserved method names

Custom component methods always get unique names. **Never shadow the `Jqhtml_Component` surface:**

`reload` · `refresh` · `render` · `redraw` · `stop` · `ready` · `rendered` · `gate_load` · `subscribe` · `sid` · `$sid` · `closest` · `find` · `instantiator` · `on` · `once` · `trigger` · plus every lifecycle hook (`on_create`, `on_render`, `on_load`, `on_loaded`, `on_ready`, `on_stop`).

Overriding one of these is a deliberate OOP override for unusual edge cases only, never a naming convenience. **`render()` is the exception with no exceptions** - never override or shadow it, instance or static (`JQHTML-RENDER-01`/`JQHTML-IMPL-01` reject it). The full list is in `php artisan rsx:man jqhtml` (RESERVED NAMES).

## Dynamic component creation

```javascript
// Destroys the existing component (if any) and creates a new one in its place
$(selector).component('Component_Name', { arg1: value1, arg2: value2 });

// Example: render a component into a container
this.$sid('result_container').component('My_Component', {
    data: my_data,
    some_option: true
});
```

**Class preservation**: only PascalCase component names (capital first letter, no `__`) are replaced. Utility classes (`text-muted`), BEM child classes (`Parent__child`) and all attributes are preserved - so the container keeps its layout classes across repeated calls. A `class="..."` set at invocation is additive too: it unions onto the root's existing classes, never replaces them.

**Detached creation skips the first render.** A component created before it is in the DOM (`$('<div>').component(...)` then appended) paints once, after `on_load()` finishes - so a loading state never appears. Pass `_force_initial_render: true` if it must.

**The `val()` hook**: a component that defines `val(value)` (getter with no args, setter with one) is auto-wired into jQuery's `.val()` on its root element, so `$(sel).val()` delegates to the component. This is what makes `Form_Input_Abstract`-style components behave like native inputs.

## Communicating between components

- **Parent -> child**: pass `this.args`, then `child.reload()` if the child must refetch. Or call a method on `this.sid('child')` from `on_ready()`.
- **Child -> parent**: `this.trigger('saved', {id})` in the child; the parent listens with `this.sid('child').on('saved', ...)` in `on_ready()`.
- **Child -> parent (alternative)**: pass a callback as an arg (`<Child $on_saved=this.handle_saved />`); the child calls `this.args.on_saved(id)`. Cleaner than an event when the parent already owns the child and is the only listener.
- **Never reach across the tree with global selectors.** `this.find()`/`this.closest()` are component-aware and scoped; a bare `$('.Some_Component')` will find every instance on the page.
- **Never await a PARENT's `ready()` from a child's `on_ready()`.** Ready resolves bottom-up: the parent waits for all children before resolving its own, so the child is waiting on something waiting on it. Deadlock. Use a callback arg or an event instead.
