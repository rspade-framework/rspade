<!-- single-source: never duplicate into another fragment. Downstream-only. -->

## YOUR KNOWLEDGE vs FRAMEWORK KNOWLEDGE

The framework's instructions (everything imported alongside this file) live under `system/app/RSpade/docs/` — **framework-owned, replaced by `rsx:framework:pull`, never edit them**. Your application's knowledge has its own homes:

- **Always-on app instructions** → `rsx/resource/CLAUDE.md` (the file importing this view). Keep it terse; it loads every session alongside the framework view.
- **App skills** → your project's `.claude/skills/<name>/` (plain directories beside the `rspade` symlink — never inside it). Use them for app procedures with a triggering moment; the framework's `rspade:*` skills are namespaced and cannot collide with yours.
- **App reference docs** → `rsx/resource/man/*.txt` (`rsx:man` serves them; format in that directory's CLAUDE.md). Create one when an app feature has non-obvious details.
- **App pre-launch audits** → `rsx/resource/audits/prelaunch_checklist.md` (review it AND `rsx:man prelaunch_checklist` before going live).
- Maintain the `#[Auth_Check]` vocabulary list in your `CLAUDE.md` (see the app-context fragment's mandate).

`php artisan rsx:man <topic>` is the framework's contract tier; `rsx:man` with no argument lists topics. When a framework instruction seems wrong, it may have been superseded — check `rsx:framework:status` and the update history before working around it, and report it upstream rather than editing `system/`.
