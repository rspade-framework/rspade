# jqhtml Template Syntax

## `<Define>` IS the element

`<Define>` is not a wrapper around your markup - it BECOMES the rendered element.

```jqhtml
<!-- [OK] CORRECT - Define becomes the button -->
<Define:Save_Button tag="button" class="btn btn-primary">
    Save
</Define:Save_Button>

<!-- Renders as: -->
<button class="Save_Button Component btn btn-primary">Save</button>
```

The component's own class name is always stamped on the root - which is what makes SCSS scoping automatic (`.Save_Button { ... }` in `save_button.scss`).

## Interpolation

| Form | Meaning |
|---|---|
| `<%= value %>` | Escaped output (the default; use this) |
| `<%!= html %>` | Unescaped/raw HTML - only for values you trust or have run through `safe_html()` |
| `<%br= text %>` | Escaped, with newlines converted to `<br>` |
| `<% javascript %>` | A JavaScript block - no output |
| `<%-- comment --%>` | A jqhtml comment, removed at compile |

**Use `<%-- --%>` for comments, not `<!-- -->`.** An HTML comment is still parsed for jqhtml constructs, so JS inside it still executes (`JQHTML-COMMENT-01`). Inside a `<% %>` block, a line-leading `//` is stripped as an ordinary JS comment.

## Child content and slots

```jqhtml
<Define:Card tag="div" class="card">
    <%= content() %>
</Define:Card>

<!-- caller -->
<Card><p>Hello</p></Card>
```

`content()` renders whatever the caller placed between the opening and closing tags - the default, unnamed slot. **A wrapper component that forgets `content()` silently discards its caller's content.**

Named slots are a separate channel for components with more than one content region:

```jqhtml
<Define:Datagrid_Card>
    <div class="card">
        <div class="card-header"><%= content('toolbar') %></div>
        <div class="card-body"><%= content('body') %></div>
    </div>
</Define:Datagrid_Card>

<Datagrid_Card>
    <Slot:toolbar><button>Add</button></Slot:toolbar>
    <Slot:body><My_Datagrid /></Slot:body>
</Datagrid_Card>
```

**Once a template uses ANY named slot, ALL caller content must be in slots.**

Slots can also RECEIVE data - `content('row', record)`, consumed as `row` inside `<Slot:row>`. That, and `extends=` inheritance, are in `reference/inheritance-and-slots.md`; you need both to build any datagrid.

Displayed content belongs in `content()`/slots, never in an attribute - see `reference/semantic-composition.md` for the rule and its one Blade exception.

## Attributes

| Form | Meaning |
|---|---|
| `$quoted="string"` | String literal arg |
| `$unquoted=expression` | JavaScript expression arg - **no spaces allowed** (`$alert=(_x>0)`, not `$alert=(_x > 0)`) |
| `$sid="name"` | Scoped element id, addressable as `this.$sid('name')` |
| `attr="<%= expr %>"` | An ordinary HTML attribute with interpolation |
| `$prop=value` ON `<Define>` | A DEFAULT for `this.args.prop`; the caller's `$prop=` at the invocation wins |
| `tag="article"` at an INVOCATION | Overrides the rendered element for that one instance |

**Key restrictions:**

- **`<Define>` attributes are static.** No `<%= %>` on the `<Define>` tag itself. For a dynamic attribute on the root element, use inline JS: `<% this.$.attr('data-id', this.args.id); %>`.
- **`$prefix` means component arg, NOT HTML attribute.** `<My_Component $data-id=123 />` creates `this.args['data-id']` - it does not put a `data-id` attribute on the DOM node.
- **Conditional attributes use if-statements, not ternaries**: `<% if (cond) { %>checked<% } %>`.
- **Unquoted `$` expressions are synchronous.** The generated render function is not async - no `await`. Precompute in a `<% %>` block.
- **`class` and `style` MERGE; everything else overrides.** Define + invocation classes union (no dupes); `style` merges per CSS property with the invocation winning conflicts. Plain attributes and `$` args are replaced outright by the invocation.
- **Void HTML elements auto-close** (`<input>`, `<img>`, `<br>`, `<hr>`); components never do - always `<Card />` or `<Card>...</Card>`.

## Conditional attributes and the two corruption gotchas

Logic toggles a **whole attribute**, and the `<% %>` block sits **between** attributes:

```jqhtml
<input <% if (this.args.required) { %>required="required"<% } %> />
```

**GOTCHA 1 - never put `<% %>` inside a quoted attribute value.**

```jqhtml
<!-- [NO] renders the template text literally and silently corrupts the class -->
<div class="base<% if (x) { %> extra<% } %>">

<!-- [OK] compute the value, then interpolate the whole thing -->
<% const cls = 'base' + (x ? ' extra' : ''); %>
<div class="<%= cls %>">
```

A value that *starts* with `<%=` is fine (`class="<%= cls %>"`); a `<%` appearing mid-string inside the quotes is not.

**GOTCHA 2 - raw-text elements.** `<%= %>` does NOT interpolate inside `<textarea>`, and nested content inside `<pre>` throws at compile. Render both empty in the markup and set their content from JS:

```jqhtml
<textarea $sid="notes"></textarea>
```
```javascript
on_render() { this.$sid('notes').val(this.data.notes || ''); }
```

## Inline logic and handlers

```jqhtml
<Define:Toggle_Row>
  <% this.toggle = () => { this.state.open = !this.state.open; this.render(); }; %>
  <button @click=this.toggle>Toggle</button>
</Define:Toggle_Row>
```

- `@click=this.method` is **unquoted**. The method may be defined inline or in the companion `.js`.
- The handler receives **`(event, $element)`** - the native DOM event and the bound element, with `this` bound to the component. `preventDefault()` is NOT automatic; call it yourself for `@submit` and link clicks.
- **Placement**: `@click` works on child HTML elements inside the template. It does NOT work on `<Define>` itself, because Define attributes are component args, not DOM attributes. To bind the root element: `<% this.$.click(() => { ... }); %>`.

## Fail loud in the template

```jqhtml
<% if (!this.args.record_id) throw new Error('record_id required'); %>
```

A missing required arg should break the render visibly, not paint an empty component.
