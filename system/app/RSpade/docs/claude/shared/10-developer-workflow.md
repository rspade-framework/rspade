<!-- single-source: never duplicate into another fragment. -->

## DEVELOPER WORKFLOW

**Command index** (depth lives in the named skill or man page):

| Command | What it does |
|---|---|
| `rsx:check` | Code-quality pass. Run before commits; every finding carries its own remediation text. |
| `rsx:debug /path` | Render a route through Playwright with full JS execution. Skill: `rspade:rsx-debug`. |
| `rsx:test` | Run the test suite (class name, `--filter=`, `--group=` narrow it). Skill: `rspade:rsx-testing`. |
| `rsx:man <topic>` | Framework documentation. **With no argument it lists every topic** — never work from a memorized list. |
| `rsx:health [--json]` | Verify dependencies, services, environment. **Exit 1 iff a FAIL; WARN/INFO advisory.** |
| `db:query "SQL" [--json] [--table]` | Execute MySQL directly. A SELECT with no `LIMIT` is capped at 25 rows. |
| `rsx:refactor:rename_php_class` · `:rename_php_class_function` · `:sort_php_class_functions` | The three AST-based editor refactors. |
| Owned elsewhere | `migrate` · `rsx:app:module:create` · `rsx:search:reindex` · `rsx:maintenance:enable`/`:disable` · `rsx:ajax` |

**Testing — never switch to the production DB, and never mark a failing test as passing.** Tests run against a separate database (`DB_TEST_DATABASE`, default `rspade_test`) and the runner REFUSES when that equals the dev database. **When to run the suite is the conduct fragment's mandate**, not a workflow choice.

**Debugging output**: `rsx_dump_die(...$values)` (PHP var_dump/die with location + stack trace); `console_debug('CHANNEL', ...values)` (channel-filtered by `CONSOLE_DEBUG_FILTER`, both languages, stripped in production).

Skills: `rspade:rsx-debug` (auth/portal sessions, screenshots, `--eval`, and the two rulings that stop wasted investigation), `rspade:rsx-testing` (when and how to test). Details: `rsx:man testing` (assertion API, selector algebra, DB baselines).
