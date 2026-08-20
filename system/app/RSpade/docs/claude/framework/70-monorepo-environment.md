<!-- bucket: framework — single-source, never duplicate. True ONLY in the RSpade
monorepo. CANONICAL HOME of the `--_` internal-flag convention,
RSPADE_MAINT_MODE, and the framework dependency layer. The OPcache/never-restart
box fact lives in framework/00. The inverse app-layer dependency mandate lives
in app/70 and must NEVER appear in shared/. -->

## MONOREPO ENVIRONMENT

**URL**: https://rspade.framework.dev.hanson.xyz/ (user URLs) | http://localhost/ (curl/testing)
**DB**: MySQL (rspade/rspadepass) | **Dir**: `/var/www/html` | **PHP**: 8.4

**Literal paths here** (the shared fragments state these path-relatively): `base_path()` = `/var/www/html/system`, so `base_path() . '/bin/script.js'` = `/var/www/html/system/bin/script.js`; `/rsx` in framework code = `/var/www/html/system/rsx`, a symlink to `/var/www/html/rsx`. CLI interfaces live in `/system/app/RSpade/Commands/`, business logic in `/system/app/RSpade/Core/{Feature}/`, framework config in `/system/config/`, user overrides in `/rsx/resource/config/`.

### Framework dependencies

Two physical layers, one logical environment; **the framework wins all overlaps**. Framework deps are installed with plain `composer require` / `npm install` **inside `system/`**, and `system/vendor` + `system/node_modules` are committed and ship via `rsx:framework:pull` — **this is the ONLY place you add packages this template needs**. The app layer (project-root manifests, `rsx:composer`/`rsx:npm`) exists for downstream apps; **this repo's template keeps ZERO app-layer deps**. Adding a package to `dependencies.exposed_composer`/`exposed_npm` is a **standing commitment**: a breaking change or retirement ships with a Category 2 `upstream_changes` doc. The `"replace"` block in the root `composer.json` is machine-generated — **NEVER hand-edit it**.

Skill `rspade:framework-dependencies`. Details: `rsx:man dependencies`.

### Framework-internal flags: the `--_` convention

**Framework-INTERNAL flags use the `--_` convention** (`--_framework-update-override`, `--_no-system-reset`, `--_no-check-schema-updates-pending`): `--_` prefix + hyphens, **declared as NO `InputOption`**, lifted out of argv into `$GLOBALS['__rsx_internal_flags']` and stripped pre-boot by `system/artisan`, read via `Rsx_Internal_Flags::has()` — or `::get('--_name')` for the VALUED form (`::set()` is the narrow seam for an in-process `Artisan::call` that cannot pass a stripped token). **Consequence: they render in no `php artisan list`/`help` output and can never raise an unknown-option error.** `--_lock-group=<id>` is the valued one that matters: `Rsx_Artisan` attaches it to every synchronous spawn so a child inherits the parent's cluster locks.

Use `--_` for any switch whose only caller is the updater or another framework command; **a user-facing flag stays an ordinary option.**

### `RSPADE_MAINT_MODE`

A **per-process snapshot** both entrypoints define from ONE stat at boot. `Framework_Maintenance::is_active()` reads it — **so consumers never see the flag flip mid-process**; `is_active_on_disk()` is the live view, `reason()` the flag's content, `$force_active_for_tests` the PHP-test seam.

Related man pages: `rsx:man storage_directories`, `rsx:man dependencies`, `rsx:man app_mode` (which covers the prod-mode env-sync gotcha, along with skill `rspade:application-modes-deployment`).
