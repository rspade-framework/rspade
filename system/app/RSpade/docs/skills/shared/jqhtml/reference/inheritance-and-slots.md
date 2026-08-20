# jqhtml Template Inheritance and Slot Data

The mechanism behind every `*_Abstract` base (`View_Section_Abstract`, `Form_Input_Abstract`,
`DataGrid_Abstract`). Read this before building or extending an abstract base.

## Three inheritance mechanisms

They compose; use as many as the component needs.

| Mechanism | Declares | Use for |
|---|---|---|
| `<Define:Child extends="Parent">` | Template structure | The child supplies `<Slot:x>` blocks; the parent's markup and its `content('x')` calls do the rendering |
| `class Child extends Parent` (JS) | Behavior | Inherit lifecycle hooks and methods; override only what differs |
| Slot-only template (no `extends=`) | Template structure, implicitly | A child whose body is ONLY `<Slot:>` blocks auto-inherits the JS parent class's template |

**Resolution order**: explicit template (markup present) → `extends=""` → the JS class prototype
chain. `extends=""` is consulted only when the child body is slot-only; a child template
containing real markup renders its own markup and ignores it.

If both the JS class and the template name a parent, they must name the SAME parent. Nothing
validates this - each chain resolves independently and a divergence is silently wrong.

## Building an abstract base

```jqhtml
<%-- datagrid_abstract.jqhtml - the base owns the structure --%>
<Define:DataGrid_Abstract tag="table" class="table">
  <thead><tr><%= content('header') %></tr></thead>
  <tbody>
    <% for (let record of this.data.records) { %>
      <tr><%= content('row', record) %></tr>
    <% } %>
  </tbody>
</Define:DataGrid_Abstract>
```

```jqhtml
<%-- users_datagrid.jqhtml - the concrete supplies only slots --%>
<Define:Users_DataGrid extends="DataGrid_Abstract" tag="table" class="table">
  <Slot:header><th>ID</th><th>Name</th></Slot:header>
  <Slot:row><td><%= row.id %></td><td><%= row.name %></td></Slot:row>
</Define:Users_DataGrid>
```

## Slot data

`content('name', value)` passes a value INTO the slot. The `<Slot:name>` block receives it as an
in-scope variable **named after the slot** - no parameter is declared. `content('row', record)`
above is consumed as `row` in `<Slot:row>`.

This is the only way to build a per-record slot, and therefore the only way to build a datagrid.

**Slot forwarding** (a middle layer wrapping a deeper child): wrap the parent's `content('row', row)`
call inside the middle layer's own `<Slot:row>` passed down to the child.

## What is and is not inherited

| Attribute | Through `extends=` / the class chain |
|---|---|
| `tag=""` | **NEVER inherited** - see the pitfall below |
| `class` | Merged - parent and child classes both apply |
| everything else | Child overrides parent |

**`tag=""` is never inherited.** Every `<Define:>` in a chain must repeat its own `tag=""` or it
silently falls back to `div`. A concrete component extending `Form_Input_Abstract` that omits
`tag="input"` renders a `<div>`: no error, wrong DOM, and the styling and `.val()` behaviour both
break downstream. This is the most common silent failure when building on an abstract base.

**Every ancestor's name is stamped on the root class list**
(`Users_DataGrid DataGrid_Abstract Component ...`). So the abstract base's SCSS file styles its
own class and every concrete descendant inherits that look for free - which is what makes an
abstract base worth having.

## Gotcha: slot-only inheritance re-scopes `$sid`

Covered as P1 in `reference/semantic-composition.md`. A child defined by slot-only inheritance
re-scopes the content passed into it, so the PARENT's `this.sid('x')` on elements inside that
content returns null. Use body-preserving `extends=` on the `<Define:>` tag instead.
