---
name: environment-config
description: Configuring an RSpade environment - the two-tier config merge and how to add or override a key, deciding config file vs .env, setting APP_URL on a new host (the $HOSTNAME token, the https requirement and the development http allowance) and diagnosing the dev-mode hostname-guard fatal, repairing .env symlink drift with rsx:env:heal, and which helper resolves which path. Use when standing up a new box, adding a config key, or debugging "wrong hostname"/"config edit did nothing" symptoms.
---

# Environment and configuration

Two files, deep-merged at boot:

```
system/config/rsx.php         framework defaults - NEVER edit
rsx/resource/config/rsx.php   your overrides
```

Both are plain PHP returning one array, read through `config('rsx.*')`. A third, test-runner-only layer merges last when `RSX_ADDITIONAL_CONFIG` points at a file (that is `rsx:test`'s per-run injection seam, not a general mechanism).

**Merge semantics** (`array_merge_deep`): a scalar REPLACES the framework value; an array is COMBINED (framework entries + yours); nested arrays merge recursively. So a user `bundle_aliases` block ADDS to the framework aliases, while a user `mode` scalar overrides outright. The user file is optional.

---

## Adding or overriding a key

Read the framework default first (`system/config/rsx.php`, or `rsx:man config_rsx` for the annotated tour), then write only the key you are changing into your own file:

```php
// rsx/resource/config/rsx.php
return [
    'files' => [
        'max_file_size' => 25 * 1024 * 1024,   // scalar: replaces the framework value
    ],
    'bundle_aliases' => [
        'charts' => 'Charts_Bundle',           // array: ADDS to the framework aliases
    ],
];
```

Read it back with `config('rsx.files.max_file_size')`. Never copy the whole framework block down to change one line - the merge exists so you do not have to, and a copied block silently freezes the other keys at the version you copied.

Keys that are application PREFERENCES rather than framework requirements already live in the user file by design: `console_debug` outputs/filters, `log_browser_errors`, response defaults, `development.auto_rename_files`.

### Config file or `.env`?

| | Config file (`config/*.php`) | Environment (`.env`) |
|---|---|---|
| Holds | Application behavior | System-specific values |
| Version-controlled | Yes | No |
| Examples | `max_file_size`, `thumbnails.presets`, retention days, worker counts, viewer registries | `DB_PASSWORD`, `APP_DEBUG`, `APP_URL`, `REALTIME_*` |

**Rule: use `env()` only for deployment-specific values. Application logic belongs in config files.** The decision test is "would this be the same on every host running this app?" - if yes it is config, even if it feels like a setting. Many config keys legitimately wrap `env()` so one value can be tuned per host; that is the sanctioned way to expose a knob, and the config file stays the place the key is *named*.

**Never read `env()` outside a config file.** `optimize:cache` deliberately drops Laravel's config cache (it freezes `env()`, incompatible with RSX runtime config), but the discipline stands regardless - a stray `env()` in application code is unreachable from config and untestable.

---

## Registering HTTP middleware

`system/app/Http/Kernel.php` is a **framework-owned file**: every framework update hard-syncs it, and the tamper gate refuses a pull while it carries a local edit. So you never register middleware by editing the kernel - you declare it in config and the kernel folds it in at bootstrap:

```php
// rsx/resource/config/rsx.php
'middleware' => [
    'global'  => [\App\Http\Middleware\Request_Stamp_Middleware::class],
    'web'     => [],                      // key must name a group the kernel declares
    'api'     => [],
    'aliases' => ['stamp' => \App\Http\Middleware\Request_Stamp_Middleware::class],
],
```

**Append-only, by construction.** Your middleware runs AFTER the framework stack, at the END of a group, or beside the existing aliases. There is no spelling that reorders or removes framework middleware - if you genuinely need that, file a framework change request. (Same philosophy as `csp.additional_sources`: widen, never narrow.)

**Validation is loud at bootstrap**, naming what is wrong: a class that does not exist, an unknown group key (it lists the valid ones), an alias already bound to a different class (it names both). Re-declaring something already present is a silent no-op.

Full contract: `php artisan rsx:man config_rsx`.

---

## APP_URL on a new host

`APP_URL` is the **single hostname source** for the whole framework - CLI hostname derivation, the dev-mode guard, realtime's derived `wss://` URL, mail links.

```bash
APP_URL=https://$HOSTNAME          # any host with TLS in front: dev boxes, staging, CI
APP_URL=https://app.example.com    # a true production host: the real hostname
APP_URL=http://localhost:8080      # development ONLY: a local container with no TLS
```

The literal token `$HOSTNAME` - **unquoted, no braces; that is the only spelling phpdotenv passes through** - resolves to the OS hostname (`gethostname()`) at boot, patched into env before config loads. That is what lets every dev/docker box ship the identical `.env` line.

**Outside development it MUST be https.** RSpade assumes upstream SSL termination, and in **debug and production** an `http://` value **throws at boot** - those modes emit an unconditionally `Secure` session cookie, which a plain-http page discards, leaving every request silently unauthenticated. On a staging or production box with no TLS in front, that is a proxy problem to fix, not a value to downgrade.

**Development mode also accepts `http://`**, because a local container may legitimately have nothing terminating TLS in front of it (`APP_URL=http://localhost:8080`). There, the session cookie already follows the request scheme, so plain http works end to end.

**Spell the port whenever it is not the scheme default.** `APP_URL` and every URL built for the browser must name the authority the browser actually used: a container published on `:8080` needs `http://localhost:8080`, or the realtime socket connects to `:80` and silently never opens. `Rsx::get_http_host()` is the port-carrying spelling (`Rsx::get_hostname()` is the port-stripped IDENTITY, and stays that way). **Any proxy in front of the app must forward the port too** - in nginx that means `proxy_set_header Host $http_host`, never `$host`, which strips it.

### Diagnosing the hostname-guard fatal

In **development mode only**, every web request verifies the browsed host matches the `APP_URL` host and **fatals loudly on mismatch**. That is not a bug being pedantic: it catches a pasted `.env` pointing at another instance, which otherwise produces a working-looking app writing to somebody else's database.

- Loopback REQUESTS (localhost, 127.*, ::1) are exempt - so curl testing keeps working.
- A loopback-VALUED `APP_URL` is **not** exempt; `APP_URL=https://localhost` on a real hostname still fatals.
- CLI and prod modes are exempt entirely.

Fix by making `.env` tell the truth about the host you are actually browsing (usually: restore the `$HOSTNAME` form). Cross-check what the framework thinks: `php artisan rsx:debug /` and `Rsx::get_hostname()`.

`Rsx::is_dev_site()` (hostname contains `.dev.`) suppresses production behaviors - email/SMS go through catchall/whitelist instead of out the door. `Rsx::is_debug_site()` (ends in `.dev.hanson.xyz`) additionally enables developer backdoors: credential auto-fill, debug tools. **Every debug site is also a dev site.** Both derive from the hostname, so getting `APP_URL` wrong on a staging box can silently turn real email delivery on.

---

## The `.env` symlink invariant, and `rsx:env:heal`

`system/.env` (the file Laravel actually loads) is a **symlink** to the project-root `.env`. **The root file is authoritative**; edits to either are the same edit.

**The failure mode**: a deploy, clone or file-copy materializes the symlink into a real file. The two drift, and **edits to the root become inert** - you change `.env`, nothing happens, and nothing announces it. That is the symptom to recognize ("my config change did nothing, and there is no error").

```bash
php artisan rsx:env:heal --dry-run     # report drift, change nothing
php artisan rsx:env:heal               # repair
```

The healer: backs up a real `system/.env` to `system/.env.replaced_by_healer` (0600, gitignored), replaces it with a relative symlink (`../.env`), appends keys unique to `system/.env` to the root, and on a conflicting key **keeps the ROOT value while REPORTING each discarded `system/.env` value** - never a silent drop.

**It runs automatically** on framework pull, at the start of `rsx:clean` (unsealed path), and FIRST in every prod-mode transition (`enable`/`refresh`/`disable`) so the `RSX_MODE` write lands in the single healed file.

**Verified 2026-08-17**: the heal in `rsx:clean` runs before any of the reset logic and is **not** conditioned on `--_no-system-reset` - so the programmatic variant every build tool uses (`rsx:manifest:build`, `rsx:bundle:compile`, the updater) still heals the symlink. The only `rsx:clean` path that skips it is the sealed-build refusal, which exits before doing anything at all.

---

## Paths: which helper resolves what

**Laravel runs FROM `system/`, so `base_path()` is `<project>/system`** - not the project root. This is the single most common path bug in RSpade code.

| Want | Use | Not |
|---|---|---|
| Anything under storage | `storage_path('rsx-tmp/x')` | `base_path('storage/...')` - lands inside the framework tree |
| A project-logical path (manifest keys keep this spelling) | `rsx_project_file_path('storage/...')` | string concatenation |
| A genuinely framework-internal file | `base_path('bin/script.js')` | - |
| Resolve a path without following symlinks | `rsxrealpath($path)` | `realpath()` |

Volatile storage lives at `<project>/storage`, one level ABOVE the base path, relocated out of the framework-owned `system/` zone. `storage_path()` follows it automatically via the bootstrap bridge (marker: `storage/.rspade_storage_relocated`); pre-boot code reads the same marker. Per-directory purpose and ship/omit posture: `rsx:man storage_directories`.

---

Details: `php artisan rsx:man config_rsx`, `rsx:man storage_directories`, `rsx:man app_mode`. Related: `rspade:application-modes-deployment`, `rspade:maintenance-mode`, `rspade:rsx-stdlib`.
