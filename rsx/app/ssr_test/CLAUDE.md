# rsx/app/ssr_test — the server-render harness, deletable

**A test page, not a feature.** `SSR_Test_Controller` (`#[Auth('public')]`, by design) renders
one jqhtml component two ways for comparison: `/ssr-test` server-side through
`Rsx_SSR::render_component()` with PHP and Node timings in a footer bar, and `/ssr-test-csr`
client-side. Three further routes are session-cookie probes asserting which calls do and do
not mint a session. It is the only surface in the app carrying `#[FPC]`. Its content is
fixture data hardcoded in `get_page_data` — no model, no database; the four components under
`components/` exist only to give the renderer something non-trivial to draw.

**To delete it cleanly:** remove the directory. Nothing references it. Deleting it frees the
theme components `Card`, `Card_Header`, `Card_Footer` (`rsx/theme/components/card/`),
`Page_Section` and `Page_Subtitle` (`page/`), and `Breadcrumb` / `Breadcrumb_Item`
(`ui/breadcrumb/`) — none has another consumer. `Card_Title` stays (every datagrid uses it);
`Page`, `Page_Header` and `Page_Title` stay until `../dev/` goes too.

**Keep it** if you intend to server-render pages: it is the only place SSR is exercised, and
the probes are how the session-cookie contract is verified.

**Related:** `../CLAUDE.md` · `rsx/theme/components/card/CLAUDE.md` · `rsx/theme/components/page/CLAUDE.md`
