<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## FRAMEWORK TESTING

`rsx:test` runs the APPLICATION suite (under `/rsx/`); **`rsx:test --framework` runs the framework suite instead** - the tests under `app/RSpade/tests/`. The two never mix in one run; every other selector (class name, `--filter=`, `--group=`) narrows within whichever suite is selected.

**Framework tests live in `app/RSpade/tests/<concern>/`**, one directory per concern, each owning a README and a test catalog. Inside a concern the tests are split by execution kind: `php/`, `cli/`, `asset/`, `http/`, `playwright/`. A `--group=<concern>` selector matches that directory name exactly.

**Bash tests** in `/system/app/RSpade/tests/` run against `rspade_test` like the PHP ones: deterministic, zero manual intervention.

Full structure, conventions, and per-kind harness details: `/system/app/RSpade/tests/CLAUDE.md`.

**`--framework` is monorepo-only knowledge**: `bin/publish` excludes `app/RSpade/tests`, so a downstream release ships no framework tests and `rsx:test --framework` has nothing to run there. Never document it downstream as a usable command.
