<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of the three application modes and the sealed-build lifecycle. Build INVISIBILITY lives in 03; the manifest/bundle one-liners live in 03 + 31. -->

## APPLICATION MODES & SEALED BUILDS

Three modes: `development`, `debug`, `production` — switched with `php artisan rsx:mode:set dev|debug|prod`. **Development** is production-worthy for low traffic: auto-rebuild on file change, JIT compile, unminified, full debugging.

**`debug` and `production` are SEALED builds — compiled ONCE by an explicit command, then IMMUTABLE.** No auto-rebuild; a missing artifact fails loud. `debug` is the sealed production-LIKE local build (unminified, sourcemaps and `console_debug()` survive) for reproducing a prod-only issue; `production` is minified with sourcemaps gone and `console_debug()` stripped, and strict enable REQUIRES `APP_ENV=production` + `APP_DEBUG=false`.

**Lifecycle**: `rsx:prod:enable [--debug]`, `rsx:prod:refresh` (rebuild + re-seal in place — **run this after pulling code onto a prod host**, or nothing served changes), `rsx:prod:disable`, `rsx:prod:verify` (asset hashes vs the seal — the cluster/CI drift check), `rsx:prod:export` (deployable package).

**A sealed build is self-contained**: it mirrors every declared external resource locally at build time (`[2/5] Mirroring external assets`) and serves them from `/_vendor/` — it NEVER downloads at request time, and a missing mirror file fails loud.

**Guards are guardrails, NOT security**: `rsx:clean` refuses while sealed and write choke points reject unauthorized writes under `rsx-build`, but raw `rm` and hand-edits bypass them — `verify` detects the drift afterwards.

**In DEVELOPMENT mode `artisan` runs inside the RSpade container or not at all** — a pre-boot gate (`bootstrap/rsx_container_gate.php`) refuses when `/.rspade_container` is absent, printing the `docker compose exec` line for the command that was typed. CLI only, development only: debug and production run outside a container normally, and the web entrypoint is never gated. The marker is written by the framework's Dockerfile; `migrate` separately reads `/.rspade_container_dev` to decide whether its snapshot is discarded (dev) or KEPT (prod).

**`RSX_MODE` in `.env` is the mode, and you never edit it by hand.** **Per-invocation intent is a `--flag`, never an env prefix: do NOT prefix an invocation with `KEY=VALUE`** (owner ruling).

**Before launch**, review `rsx:man prelaunch_checklist` AND your own `rsx/resource/audits/prelaunch_checklist.md`. Both lists, every launch.

Skill `rspade:application-modes-deployment` (per-mode behavior table, what the seal pins, the determinism contract, verify-drift diagnosis, export packaging). Details: `rsx:man app_mode`.
