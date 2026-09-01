# rsx/app/backend — a Blade admin skeleton, deletable

**A scaffold, not a feature.** One controller (`Backend_Index_Controller`, `#[Route('/admin')]`,
`#[Auth('is_logged_in')]` — no role floor) renders four KPI cards with the literal values
0/0/0/OK and a welcome card. Nothing is data-driven. `backend_layout.blade.php` hand-rolls
its own nav and links to four controllers that do not exist, so every item but Dashboard is
a dead link; `backend_bundle.php` pulls in no theme components.

**To delete it cleanly:** remove the directory. No nav entry points here, no bundle includes
it, and no `Rsx::Route()` outside it names its controller — `/admin` simply 404s afterwards.

**To keep it:** give it a real gate first (`is_logged_in` alone admits every signed-in user
to a page called Admin), then build or remove the four missing controllers. The staff app's
real admin surfaces are `../frontend/settings/` and `../frontend/system/`.

## RELATED

`../CLAUDE.md` · skills `rspade:blade-views`, `rspade:auth-gates`
