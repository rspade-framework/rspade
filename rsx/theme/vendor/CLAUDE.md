# rsx/theme/vendor — the vendored Bootstrap source

## WHAT IS HERE

- `bootstrap5/` — an unmodified upstream Bootstrap source tree (5.3.3, per its
  `package.json`). **Never edit anything inside it.**
- `bootstrap_custom.scss` — the build entry point: imports `rsx/theme/variables.scss` FIRST
  so the app's values override Bootstrap's defaults, then `bootstrap5/scss/bootstrap`.

## HOW IT IS USED

`rsx/theme/bootstrap5_src_bundle.php` consumes both: it includes `bootstrap_custom.scss`
and `bootstrap5/dist/js/bootstrap.bundle.js`, watches `bootstrap5/scss` and
`variables.scss`, and exposes the source tree to other SCSS as `~bootstrap5_src/...`.
Bootstrap Icons is a separate CDN declaration in the same bundle, mirrored into
`rsx/resource/.cdn-cache/`.

## HOW TO CUSTOMIZE

Overrides live in `rsx/theme/` — variables in `variables.scss`, component overrides in the
theme's own SCSS. Editing the vendored tree is lost work: an upgrade replaces it whole.

**Upgrading is a manual swap; there is no framework command for it.** Replace `bootstrap5/`
with the new upstream release tree, keep the directory NAME (the bundle's include list,
watch list and module path all spell it) and `bootstrap_custom.scss`, then re-check
`variables.scss` against the release's changed defaults and bump the icons CDN URL.

## RELATED

App skill `theme` · `../CLAUDE.md` · skill `rspade:bundles` · `rsx:man external_resources`
