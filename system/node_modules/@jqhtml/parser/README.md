# @jqhtml/parser

Compiles `.jqhtml` templates into JavaScript for the JQHTML component framework.

```bash
npx jqhtml-compile component.jqhtml -o bundle.js --sourcemap
```

Templates look like HTML with embedded JavaScript:

```html
<Define:Product_Card class="card">
  <h3><%= this.data.name %></h3>
  <% if (this.data.on_sale) { %>
    <span class="badge">Sale!</span>
  <% } %>
</Define:Product_Card>
```

The parser handles the template syntax - `<Define:>` blocks, `<%= %>` expressions, control flow, slots, event bindings. Output is JavaScript that the `@jqhtml/core` runtime understands.

## Status

Alpha release. Works well enough that I use it daily, but documentation is sparse and you might hit edge cases. Webpack loader and proper docs coming soon.

Found a bug or built something cool? Drop me a line.

---

**hansonxyz** · [hanson.xyz](https://hanson.xyz/) · [github](https://github.com/hansonxyz)
