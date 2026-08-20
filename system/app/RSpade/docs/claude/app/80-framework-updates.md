<!-- bucket: app — single-source, never duplicate. True ONLY in a downstream application. THIS FRAGMENT IS THE CANONICAL HOME of the pull contract and its recovery paths; the git-proxy rules live in app/81 and the maintenance window in shared/73. -->

## FRAMEWORK UPDATES

```bash
php artisan rsx:framework:pull    # User-initiated only
php artisan rsx:framework:status  # Installed release + is an update available?
php artisan rsx:framework:verify  # Standalone tamper check of the framework tree
```

The framework core (`system/`) is **vendored** into your app repo as ordinary tracked files - not a git submodule. `rsx:framework:pull` clones the distribution, **hard-syncs the framework-owned zones, three-way-reconciles the rest** (your deliberate `system/` changes are preserved and conflicts reported), gated by a marker-based tamper check. **It never merges and never stashes.** It **commits its own `system/` changes** as one dedicated framework-update commit, and only THEN rebuilds. The commit is path-scoped (your in-flight app work is never swept in) and records the release exactly as synced, so **a rebuild that fails costs only the build: fix what it reports and rebuild - never re-pull**. Class-override churn is never committed - it is local, regenerate-on-demand state. Each update is logged to `rsx/resource/framework_update_history.dat`; afterward `git log -- system/` shows what arrived. The pull raises the SAME maintenance window the operator commands do (see the maintenance fragment) and is development-mode only.

If a pull ever ends with "**system/ holds a synced but UNCOMMITTED framework release**": do NOT run `rsx:clean` and do NOT make an app commit — either reverts the update. Recover with `php artisan rsx:framework:pull --no-rebuild`.

**Run `php artisan migrate` AFTER every completed pull.** A pull can bring migrations from other environments (app migrations, or a framework-update commit carrying framework migrations); until `migrate` runs, the code and the schema disagree. So the full user-requested pull sequence is: `php artisan rsx:git pull` -> `php artisan migrate` (safe when there is nothing pending - it simply reports nothing to migrate; in development the automatic snapshot protects the database). **Do this whenever the user asks for a pull, without being asked separately.**

**Set a LONG command timeout (10 minutes / 600000ms) when running `rsx:framework:pull`** — an uncached run clones the distribution, syncs ~110k files and runs the whole rebuild chain, and killing it mid-run leaves a partial update. This is a TOOL-CALL budget, not a timeout in code; the no-timeout mandate is unaffected.

### Flags, and what `--force` actually means

`--diff` (preview only), `--diff-system-changes` (which framework files you changed), `--no-rebuild`, `--no-commit`, `--check-foreign-changes` (read-only drift probe), `--force`. **`--force` means ONE thing: override the tamper gate — and it DESTROYS local changes under the framework-owned zones**, so never use it without explicit user permission.

**`--force` is NOT how you restore a missing framework file.** Owned zones are convergent state, so a file that is not on disk is simply put back on an ordinary pull, named in the output, with no flag — **absence is never intent**. If a pull reports restored files, read the list, **and if it names migrations, run `php artisan migrate`**: that box has been running current framework code against an older schema. Files it reports as UNRESTORABLE are advisory and never fatal.

**`system/.gitignore` and `system/app/Http/Kernel.php` are framework-owned FILES — do not edit them.** Declare your own HTTP middleware in `config('rsx.middleware')` (append-only; `rsx:man config_rsx`).

### After the pull

- **Environment updates self-apply, silently** — a successful pull runs `system/bin/post-update.sh`, executing self-detecting idempotent scripts (git hooks, the Claude Code status line and git guard, the storage relocation). Never able to fail your build.
- **Pending mandatory upstream changes are surfaced automatically**: `rsx:framework:upstream_changes` (list) / `:show {name}` / `:mark {name} --fulfilled`.

Skill: `rspade:framework-updates`. Details: `php artisan rsx:man rsx_upstream`, `rsx:man upstream_changes`.
