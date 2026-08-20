<!-- bucket: app — single-source, never duplicate. True ONLY in a downstream application. -->

## APPLICATION CONTEXT

- **Delegation research artifacts** (audits, investigation reports) are persisted verbatim into your own project's documentation tree - e.g. `rsx/resource/` - before implementation begins.

### Project documentation

Write your own man pages as `rsx/resource/man/*.txt` - they are served by the same `php artisan rsx:man <topic>` as the framework's, and that directory's `CLAUDE.md` carries the format. Keep app-specific conventions there rather than growing this always-on file.

### Testing in your app

`rsx:test` runs YOUR application suite (the tests under `/rsx/`). Framework tests are **not shipped** with a release, so `rsx:test --framework` has nothing to run here - it is a monorepo-only command.

### Auth vocabulary

**MANDATE — keep the check list in YOUR CLAUDE.md.** Maintain a terse running list of every `#[Auth_Check]` your `Permission` and `Portal_Permission` define — name plus one line on what it checks — in the application's own `CLAUDE.md`, and UPDATE IT whenever a gate is added, renamed or removed. It is what lets an agent pick the right existing check instead of minting a near-duplicate, and it is the first thing to read before annotating a new surface.

Session, login-history and Turnstile settings are yours to tune: the `rsx.sessions.*` windows (web/anonymous timeouts, `max_web_sessions_per_user`, `login_history_retention_days`, `login_failure_window_minutes`), `rsx.portal.*` and `rsx.turnstile.*` are all overridable in `rsx/resource/config/rsx.php`.

To change the behavior of a core auth model (`User_Model`, `Portal_User_Model`, `Login_User_Model`), use the **class-override** pattern — a same-named class in `rsx/models/`. Keep app concepts (CRM links, memberships) in **separate** app models, never bolted onto the overridden core model.
