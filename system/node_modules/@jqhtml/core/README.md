# @jqhtml/core

A jQuery-based component framework for people who think in systems, not pixels.

```html
<Define:User_Card class="card">
  <h3><%= this.data.name %></h3>
  <p><%= this.data.email %></p>
</Define:User_Card>
```

Components have a simple lifecycle: `on_load()` fetches data, `on_render()` sets up the DOM, `on_ready()` fires when everything's ready. No virtual DOM, no complex state management - just jQuery under the hood.

**vs React/Vue:** JQHTML is for when you want component structure without the SPA complexity. Great for server-rendered apps (Laravel, Rails, Django) where you need interactive islands, not a full frontend framework.

## Status

Alpha release. It works and I use it daily, but expect rough edges. Full documentation and framework plugins coming soon.

If you try it in a project, I'd love to hear about it.

---

**hansonxyz** · [hanson.xyz](https://hanson.xyz/) · [github](https://github.com/hansonxyz)
