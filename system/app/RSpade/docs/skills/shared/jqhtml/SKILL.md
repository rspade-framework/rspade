---
name: jqhtml
description: Building jqhtml components in RSX - template syntax (<Define>, interpolation, content()/slots, @click), the six-stage lifecycle (on_create, on_render, on_load, on_loaded, on_ready, on_stop), the this.args/this.data/this.state triad, component access ($sid, this.sid, this.$), reload/render/redraw/refresh/load, targeted $redrawable redraws, extends= template inheritance and abstract bases, custom events, and the complexity tiers. Use when creating or editing a .jqhtml template or its companion .js class, deciding which lifecycle hook code belongs in, fixing a component that renders stale or empty data, binding handlers that stop working after a render, choosing between reload() and render(), naming a custom event or method, building an abstract base or a datagrid with slot data, or composing a page out of semantic components.
---

# jqhtml Components

jqhtml is RSX's component system. A component is up to three co-located files sharing one name:

```
user_card.jqhtml     # markup - the template (REQUIRED)
User_Card.js         # logic, lifecycle, state (optional - only when behavior exists)
user_card.scss       # the component's COMPLETE look (wrapped in .User_Card)
```

Names are **Pascal_Snake_Case** (`User_Card`, `Save_Button`) - not bare PascalCase; jqhtml's own public docs use `UserCard` style, do not follow it here.

The manifest discovers them with zero registration, and an **undefined uppercase tag already renders** (as a div carrying the component name as a class), so a page can be scaffolded out of names before a single one exists.

Reference files in this skill, one level deep:

- `reference/template-syntax.md` - every template construct, attribute rules, the corruption gotchas
- `reference/lifecycle-and-state.md` - the six stages in full, worked state examples, loading patterns
- `reference/component-api-events.md` - DOM/component access, events, dynamic creation
- `reference/inheritance-and-slots.md` - `extends=`, abstract bases, passing data into slots
- `reference/semantic-composition.md` - designing a page as a composition of named concepts

The authoritative contract is `php artisan rsx:man jqhtml`; the page-composition method is `php artisan rsx:man semantic_composition`.

## The hook decision matrix

Most component bugs are code in the wrong hook. Find the row that matches your intent:

| I want to... | Hook | Why |
|---|---|---|
| Set `this.data`/`this.state` defaults | `on_create` | Before first render |
| Register a persistent component-level handler (`this.on/once`) | `on_create` | Persists across renders; `on_ready()` risks infinite loops from event replay |
| Fetch data | `on_load` | Only place `this.data` is writable post-create; NO DOM or child access here |
| Format/decorate the component's OWN just-rendered markup | `on_render` | Fires ~instantly after draw, even on a cached-stub render; must be idempotent, own-template only |
| Bind a handler to a static direct-descendant HTML element | `on_render` (dedup guard) or `on_ready` | Child DOM recreated each render, must re-attach |
| Call a function on a first-generation child built to be timing-indifferent (`Form_Input_Abstract.val()`) | `on_render` (cautiously) | Only if the child's own contract supports pre-ready calls; else `on_ready` |
| Interact with a child that has no such contract (call methods, read state, bind its events) | `on_ready` | Children guaranteed ready only here |
| Read the finalized/correct `this.data` for post-draw logic | `on_ready` | `on_render` may see a stale cached stub or default `this.data` |
| Start a socket/subscription/observer | `on_ready` | One-time, needs a stable tree |
| Teardown (sockets, observers, timers) | `on_stop` | Symmetric with `on_ready` setup |

One exception to the last-but-one row: a **realtime** subscription is placed in `on_create()` so the first load is gated on the subscription being live - see the realtime skill.

## Complexity tiers

Pick the lowest tier that does the job. Reaching for a higher tier than the component needs is the most common source of unnecessary jqhtml code.

**Static** - template-only, no `.js` file. Pure display from `this.args`. Most components in a well-composed app are this tier.

**Simple** - `on_load()` fetches into `this.data`; when `this.args` change, set them and call `reload()`.

**Complex** - `on_load()` for initial/cached data, then jQuery DOM manipulation for incremental mutations after initialization (appending a row rather than repainting the list).

**Standalone (last resort, docblock-declared)** - the full contract, all of which is mandatory:

- **NO `on_load()` and no participation in the load pipeline.** One explicit fetch method the component calls itself.
- **All state lives in `this.state`.** This tier is the sanctioned exception to "render from `this.data`" - do NOT "fix" a declared Standalone component into `on_load()` conformance.
- **Imperative render into stable shell-template `$sid` containers.** The template renders a shell of empty containers once; JS paints into them. A templateless Standalone component has no `$sid` at all - use in-memory element references, **never data-\* tagging**.
- **Justified only** when the DOM is so imperative and stateful between renders (drag-and-drop, inline editing, context menus) that a template re-render is unacceptable.
- **MUST hand-roll stale-while-revalidate**: `Rsx_Storage` session tier, write-through, `deep_equal` skip when nothing changed, and a **UNIQUELY-NAMED public revalidate method** - never override a reserved name like `refresh()`.
- **The docblock declares the tier**, so the next reader knows the deviation is deliberate.

```javascript
/**
 * Kanban_Board - drag-and-drop board.
 * @tier Standalone - card positions are DOM state between renders; a template
 *       re-render would drop the drag session. State in this.state; painted
 *       into the shell $sid containers; revalidate via reload_board().
 */
```

## Simple components: JS inside the template

When behavior is a few lines, write it in the template and skip the `.js` file entirely.

```jqhtml
<Define:CSV_Renderer>
  <%
    if (!this.args.csv_data) throw new Error('csv_data required');   // fail loud in the template
    const rows = this.args.csv_data.split('\n').map(r => r.split(','));
    this.toggle = () => { this.args.expanded = !this.args.expanded; this.render(); };
  %>
  <table>
    <% for (let row of rows) { %>
      <tr><% for (let cell of row) { %><td><%= cell %></td><% } %></tr>
    <% } %>
  </table>
  <button @click=this.toggle>Toggle View</button>
</Define:CSV_Renderer>
```

**Inline JS** for simple transformations, loops and basic handlers. **A `.js` file** once the JS overwhelms the template, needs external data, or holds multiple methods and real state.

Validating args in the template is the encouraged fail-loud idiom: `<% if (!this.args.user_id) throw new Error('user_id required'); %>`.

## Dynamic component creation

```javascript
// Destroys the existing component (if any) and creates a new one in its place
$(selector).component('Component_Name', { arg1: value1, arg2: value2 });

// Render a component into a container this component owns
this.$sid('result_container').component('My_Component', { data: my_data, some_option: true });
```

**Class preservation**: only PascalCase component names (capital first letter, no `__`) are replaced. Utility classes (`text-muted`), BEM child classes (`Parent__child`) and **all attributes** are preserved. So a container can carry layout/utility classes safely across repeated `.component()` calls.

Awaiting a dynamically created component: `await $(selector).component().ready()`. This is rarely needed inside a component (`on_ready()` already waits for children created during render) - it exists for dynamically created components and for Blade page JS reaching into a component.

## Incremental scaffolding

**Undefined components work immediately** - they render as a div with the component name as a class. So the honest way to build a page is to write the composition first and define the pieces afterwards:

```blade
<Dashboard>
  <Stats_Panel />
  <Recent_Activity />
</Dashboard>
```

Nothing breaks, the page renders, and each name becomes a real component when you get to it. Combined with automatic SCSS scoping on the component class, this is what makes semantic composition cheaper than copy-pasting markup.

## Pitfalls

Remedies included - most of these are silent, not thrown.

1. **`<Define>` IS the element, not a wrapper.** Use the `tag=""` attribute to choose the rendered element; do not wrap it in another div. `tag=""` is **never inherited** - every `<Define:>` extending a base must repeat it or silently render a `div`.
2. **`this.data` starts as `{}`.** Set defaults in `on_create()`; it is writable ONLY in `on_create()` and `on_load()` and frozen everywhere else.
3. **`on_load()` may read `this.args` and write `this.data`, and nothing else.** DOM access, `this.state` and modifying `this.args` all throw at runtime.
4. **NEVER call `this.render()` in `on_load()`** - the automatic re-render already happens when `this.data` changes.
5. **`this.state` is for UI state; `this.args` + `reload()` is for re-fetching.** Never re-fetch into `this.data` from an event handler.
6. **Use `Controller.method()`, never `$.ajax()`** - `#[Ajax_Endpoint]` methods are auto-callable from JS, and `$.ajax()` throws.
7. **`on_create`, `render` and `on_stop` must be synchronous.** `on_load`, `on_loaded` and `on_ready` may be async.
8. **`this.sid()` returns a component; `$(el).component()` returns a component; `this.$sid()` returns a jQuery element.** Mixing them up produces "not a function" at the call site.
9. **`@click` goes on child elements, NOT on `<Define>`** (Define attributes are component args, not DOM attributes). For a root-element click: `<% this.$.click(() => { ... }); %>`.
10. **Wrapper components must render `<%= content() %>`** or the caller's child content is silently dropped.
11. **Never put `<% %>` inside a quoted attribute value** - `class="x<% if (c) { %>y<% } %>"` renders as literal text and corrupts the value. Build the value in a `<% %>` block, then `class="<%= cls %>"`. Conditional logic toggles a WHOLE attribute *between* attributes: `<% if (cond) { %>attr="val"<% } %>`.
12. **`<%= %>` does not interpolate inside `<textarea>`, and nested content in `<pre>` throws at compile** (HTML raw-text elements). Render both empty in markup and set content from JS (`.val(...)` / `.textContent` in `on_render`).
13. **Unquoted `$arg=expression` args take no spaces**: `$alert=(_x>0)`, not `$alert=(_x > 0)`. The parser treats the first space as the end of the expression. Precompute in a `<% %>` block if it needs to be readable.
14. **`render()` destroys child DOM.** Every child element and child component is recreated, and DOM handlers on them are lost - re-register in `on_render()` (namespaced and idempotent) or `on_ready()`. For ONE data-driven fragment, mark it `$redrawable $sid="x"` and call `this.render('x')` instead - only that element redraws.
15. **`on_render` fires more than once.** Namespace every DOM bind (`.off('click.ns').on('click.ns', ...)`) and guard every DOM injection against duplicate appends.
16. **Do not render display templates from a `this.state` copy of `this.data`.** The post-load re-render is gated on `JSON.stringify(this.data)` changing, so such a template goes stale after a save that returns identical values.
17. **Never tag imperative DOM with `data-id`.** `$sid` is template-only, `data-*` belongs to the args machinery, and the jquery layer THROWS on the setter form. Use in-memory element references (a Map) or CSS classes.
18. **Never name a custom event `create`/`load`/`loaded`/`ready`/`render`/`rendered`/`stop`**, and never shadow a `Jqhtml_Component` method name (`reload`/`refresh`/`render`/`redraw`/`stop`/`ready`/`rendered`/`gate_load`/`subscribe`/`sid`/`$sid`/`closest`/`find`/`instantiator`/`on`/`once`/`trigger` + the lifecycle hooks). Both collide silently with framework behavior.
19. **Never fire custom events with `this.$.trigger()`** - use `this.trigger()` (enforced by `JQHTML-EVENT-01`).
20. **Iterate with the page renderer, not the lint suite.** `php artisan rsx:debug /path --user=1` surfaces compile, SCSS and runtime errors immediately; run the full `rsx:check` once at the end. For live timing/ordering bugs a single render pass cannot show (double-render, hook order, slow renders), `jqhtml.enableDebugMode('basic')` in the browser console logs each phase with timestamps.
21. **Object/array/function args silently disable caching AND load deduplication.** The invocation key is computed from primitives only, so `$filters={...}` or `$ids=[1,2]` opts the component out of both with no error (only a `data-nocache` attribute marks it). Flatten into individual `$` args, or give the object a `_jqhtml_cache_id`.
22. **A tag is a component only if its first letter is uppercase.** `<user_card>` is never a component no matter what is registered - it renders as a literal unknown HTML element with no scoping, lifecycle or `Component` class (`JQHTML-CLASS-01`).
23. **No inline `<script>` or `<style>` tags in a `.jqhtml` template** (`JQHTML-INLINE-01`). Behavior goes in the companion `.js` or a `<% %>` block; styling goes in the component's `.scss`.
24. **`on_stop()` is not guaranteed to fire** - a node removed outside the framework skips it. Do not put cleanup there that would be catastrophic if skipped.
