---
name: application-modes-deployment
description: Building, sealing and deploying an RSpade app - the three application modes, the rsx:prod:enable/refresh/disable/verify lifecycle, what the seal file pins, strict production vs the --debug variant, the determinism contract that makes a build cluster-shareable, diagnosing verify drift and sealed-build guard refusals, rsx:prod:export packaging, and the prelaunch checklist workflow. Use when taking an app to production, reproducing a prod-only bug locally, deploying new code onto a sealed host, or debugging "not built for production mode" errors.
---

# Application modes and deployment

Three modes: `development`, `debug`, `production`. **Development is production-worthy for low-traffic use** - it auto-rebuilds and JIT-compiles, and an app can legitimately run that way forever. `debug` and `production` are **SEALED builds**: compiled ONCE by an explicit command, then IMMUTABLE. No auto-rebuild; a missing artifact fails loud instead of quietly rebuilding.

`RSX_MODE` in `.env` is the authoritative mode (default `development`). You never edit it by hand - the lifecycle commands write it.

---

## Which command, when

```bash
php artisan rsx:prod:enable            # compile + seal a STRICT production build
php artisan rsx:prod:enable --debug    # compile + seal the DEBUG variant (local test build)
php artisan rsx:prod:refresh           # rebuild + re-seal IN PLACE (needs an existing seal)
php artisan rsx:prod:disable           # unseal, return to development
php artisan rsx:prod:verify            # recompute hashes vs seal + env sanity (exit 1 on drift)
php artisan rsx:mode:set dev|debug|prod   # thin delegator, kept for muscle memory
```

`rsx:mode:set` is a delegator so there is exactly ONE implementation of each transition: `dev` -> `prod:disable`, `debug` -> `prod:enable --debug`, `prod` -> `prod:enable`.

**The one that trips people up: after pulling new code onto a host that is already sealed, run `rsx:prod:refresh`, not `enable`.** Refresh rebuilds the assets and rewrites the seal in place, preserving the mode the build was sealed for (unless you pass `--debug`, which flips it to the debug variant). `enable` on an already-sealed host is the wrong shape of operation.

### What `enable` actually does

1. Heal the `.env` symlink invariant.
2. Validate the environment (strict prod only; `--debug` makes it warn-only).
3. Write `RSX_MODE` to `.env` **and sync it into the running process** (see the gotcha below).
4. Clear the old Laravel + rsx caches and any prior seal.
5. Run the build pipeline in an AUTHORIZED subprocess (`rsx:prod:build --force --authorized`).
6. **On success ONLY, write the seal.** A failed pipeline leaves the system unsealed - fix the error and run enable again. There is never a seal describing a build that did not happen.

`rsx:prod:disable` removes the seal FIRST (so the following cache clears are not blocked by the immutability guard), sets `RSX_MODE=development`, clears the prod caches, **restores the DEVELOPMENT composer autoloader** - a strict-prod build leaves a classmap-authoritative autoloader that must be undone or new/renamed classes stop resolving - and pre-warms the dev bundle JIT. It is idempotent.

---

## Strict production vs `--debug`

**Debug is a sealed, production-like LOCAL test build.** Unminified, inline sourcemaps survive, `console_debug()` still works. Its whole reason to exist is reproducing a prod-specific issue with readable code.

**Strict production** adds, relative to debug:

- Minification (Terser for JS, cssnano for CSS, via the minify RPC server).
- Inline sourcemaps are GONE - minification strips the `sourceMappingURL` comments.
- `console_debug()` is neutralized **two ways at once**: call sites are STRIPPED from the compiled output (the minify request carries a strip flag; Terser treats `console_debug` and `Debugger.console_debug` as `pure_funcs` and deletes the calls), AND the `console_debug` config block is omitted from `window.rsxapp`, so even a surviving call site no-ops at runtime.
- CDN assets are cached locally in both sealed modes (`rsx/resource/.cdn-cache/`, served via `/_vendor/`) - they are never inlined into bundles, and no single-file bundle merging exists at all (always a vendor/app split). Bundle `cdn_assets` are mirrored by the compiler; **declared external resources (`*.externals.php`) get their own explicit build step, `[2/5] Mirroring external assets`** (nothing renders them at build time, so nothing else would discover the URL), with `mirror:false` entries skipped. **A sealed build never downloads at request time - a missing mirror file THROWS** naming the file and `rsx:prod:refresh` as the remedy.

### The environment gate

**Strict enable REQUIRES `APP_ENV=production` and `APP_DEBUG=false`.** If either is wrong, enable prints the exact fixes and exits **without touching the mode** - a strict prod build is meant for a real production host. `--debug` downgrades that to a warning so you can build the debug variant on a dev box. `rsx:prod:verify` applies the same expectation: for a strict seal it FAILS on a non-production env; for a debug seal the `APP_ENV` row is advisory.

---

## The seal file

`storage/rsx-build/prod_seal.json` pins: **build_key, mode, git commit, and a per-asset sha256**. `is_sealed()` is true only when a seal exists AND the current `RSX_MODE` is a prod mode - which is what makes a hand-edited `.env` unable to half-seal a box.

`rsx:prod:verify` is the cluster/CI tool: it recomputes the sha256 of every sealed asset, checks the on-disk build_key against the seal, checks env sanity, and reports OPcache posture (advisory). Table out, **exit 0 when the build matches and the environment is sound, 1 otherwise** - put it in the deploy health check next to `rsx:health`.

### Diagnosing drift

| Symptom | Meaning | Fix |
|---|---|---|
| verify: asset hash mismatch | Someone wrote under `storage/rsx-build` outside the framework (raw `rm`, `cp`, a hand-edited bundle) | `rsx:prod:refresh` |
| verify: build_key mismatch | The code on disk is not the code that was sealed (a `git pull` without a refresh) | `rsx:prod:refresh` |
| verify: env row FAIL | `APP_ENV`/`APP_DEBUG` drifted on a strict seal | Fix `.env`, re-verify |
| "Manifest not built for production mode" | Manifest cache missing/invalid while sealed | `rsx:prod:refresh` |
| "Bundle 'X' not compiled for production mode" | A bundle is missing while sealed | `rsx:prod:refresh` |
| "System is in prod mode (sealed build)." | You ran `rsx:clean` or a bare `rsx:prod:build` while sealed | `rsx:prod:refresh`, or `rsx:prod:disable` to leave |
| "...is an immutable prod build asset" | An unauthorized context tried to write under `rsx-build` | The guard doing its job - rebuild through `refresh` |

---

## Living with a sealed build

The single behavior change that surprises people: **on a sealed box, editing a file does nothing.** No auto-rebuild, no JIT compile - the served assets are the ones the seal describes, and they stay that way until `rsx:prod:refresh`. That is the point (a production box must not recompile under load, and a missing artifact must fail loud rather than silently rebuild something unreviewed), but it means the normal RSpade edit-and-refresh loop is OFF.

Consequences worth internalizing:

- **Debugging on a sealed box is a rebuild cycle**, not an edit cycle. If you are iterating, `rsx:prod:disable`, work in development, then re-enable.
- **`rsx:clean` refuses while sealed** - clearing `rsx-build` would leave the app unable to serve. `rsx:prod:refresh` is the rebuild; `rsx:prod:disable` is the exit.
- **A `git pull` on a sealed host changes nothing that is served** until you refresh. A build_key mismatch in `rsx:prod:verify` is exactly this state, and it is why deploy scripts pair pull with refresh.
- **OPcache posture is advisory only** in verify - it reports, it never fails. Whether a host runs OPcache is a host decision, not a framework one.

---

## Guards: guardrails, not security

`rsx:clean` refuses while sealed. Write choke points throw on unauthorized writes under `rsx-build`. **Only an `--authorized` rebuild context may write sealed assets.**

They exist to stop the framework's OWN write paths, and they do not (and cannot) stop a developer who goes around them: `rm -rf storage/rsx-build`, editing a bundle by hand, `cp` over `manifest_data.php`, `DB::table()->update()`. Nothing at the framework layer sees those. **The analogy is the realtime model layer**: `save()`/`delete()` emit change frames, a raw `DB::table()` write emits nothing, by design. `rsx:prod:verify` is the backstop that DETECTS such drift afterwards - it never prevents it.

---

## Determinism: why a build travels

**Two byte-identical codebases, checked out at DIFFERENT absolute paths, produce an IDENTICAL build_key and IDENTICAL bundle filenames, byte-for-byte.** That is what lets one compile be trusted across a cluster, or cached by CI keyed on build_key.

1. **File hashing branches on `RSX_MODE`, not `APP_ENV`.** In a prod mode the hash covers the file's PROJECT-RELATIVE path plus its CONTENT (sha512) - never the absolute path, never disk timestamps. (Development still uses a fast abspath+size+mtime hash; it only needs to notice local edits and is deliberately not portable.)
2. **The manifest hash excludes per-file mtime/size.** They stay in the cache file for dev change-detection but are OUT of the hashed projection, and the prod cache file also drops the "generated" timestamp (that moved to the seal). So `manifest_data.php` is byte-stable and build_key is content-derived.
3. **Bundle filenames are `{Bundle}__{app|vendor}.{hash8}.{ext}`**, the hash8 deriving from the same relative-path + content inputs plus the committed lockfile hashes and npm declarations. Minified output is reproducible given the pinned, committed `node_modules`.

**Bonus, and it is a real one**: because build_key is content-derived and stable across checkouts, full-page-cache keys (`fpc:{build_key}:...`) are **cluster-shareable** - two nodes on the same build hit the same FPC entries. (`rsx:man fpc`.)

---

## The mode-transition env-sync gotcha

`RSX_MODE` is a deployment ENVIRONMENT fact living in `.env`, **not** an invocation parameter. When a prod-mode command spawns its build subprocess it writes `RSX_MODE` to `.env` FIRST **and also syncs the value into the running process** (`putenv` + `$_ENV` + `$_SERVER` + the mode cache), because the child inherits the parent's environment and its Dotenv will not override an already-set var.

Without that sync: an `enable` launched from a development parent spawns a build that runs **unminified and unstripped** (silently wrong output), and a `disable` launched from a production parent throws "Manifest not built for production mode" while pre-warming dev. If you ever add a command that shells out across a mode change, this is the trap.

**Per-invocation intent is a `--flag`, never an env prefix**: `--force` to force a rebuild, `--authorized` to authorize a sealed rebuild. Do NOT prefix invocations with `KEY=VALUE` (owner ruling).

---

## Packaging: `rsx:prod:export`

```bash
php artisan rsx:prod:export [--path=./rsx-export]
```

Runs the authorized build and copies `system/`, `rsx/`, `node_modules/`, `vendor/`, `storage/rsx-build/` and the root `*.json` into the export dir. **Excludes** `.env`, `storage/app`, `storage/logs`, `storage/framework/{cache,sessions,views}`, `storage/rsx-tmp`, tests, and VCS/IDE dirs. **The destination host supplies its own `.env`.** Add `/rsx-export/` to `.gitignore` - each deployment generates a fresh one. (By contrast `rsx/resource/.cdn-cache/` IS committed; it is a build input.)

`rsx:prod:build` is the shared pipeline the other commands drive - manifest -> bundles -> composer autoloader -> `optimize:cache`. Not run by hand; while sealed it refuses without `--authorized`.

**`optimize:cache` drops config and view caching deliberately**: Laravel's config cache freezes `env()`, which is incompatible with RSX runtime config. Only routes and events are cached.

---

## Typical flow

1. Develop (`RSX_MODE=development`) - changes are live on save.
2. Reproduce a prod-only bug locally: `rsx:prod:enable --debug`, then `rsx:prod:disable` to return.
3. On the production host (`APP_ENV=production`, `APP_DEBUG=false`): `rsx:prod:enable`.
4. `rsx:prod:verify`.
5. After pulling new code onto that host: `rsx:prod:refresh`.
6. Package for elsewhere: `rsx:prod:export`.
7. Back to development: `rsx:prod:disable`.

---

## Before launch

`php artisan rsx:man prelaunch_checklist` is a curated audit of framework-required requirements that are impractical to enforce with a lint rule - whole-flow wiring obligations, patterns a build cannot detect. `rsx:prod:enable` prints a reminder pointing at it.

Work it as a checklist, not a skim: each entry says what to audit, why, and what a correct implementation looks like. Then work your OWN list at `rsx/resource/audits/prelaunch_checklist.md` - app-specific items (permission gates you added, email templates, seed-data cleanup, throwaway accounts). **Review BOTH before going live**; neither replaces the other.

---

Details: `php artisan rsx:man app_mode`, `rsx:man prelaunch_checklist`, `rsx:man storage_directories`, `rsx:man fpc`. Related: `rspade:maintenance-mode`, `rspade:environment-config`.
