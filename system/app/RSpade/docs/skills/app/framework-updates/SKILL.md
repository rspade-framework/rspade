---
name: framework-updates
description: Updating the RSpade framework in a downstream application - what rsx:framework:pull actually does end to end, every flag and what --force does and does not mean, restoring missing framework files, the owned files (system/.gitignore, artisan, app/Http/Kernel.php) you must never hand-edit, recovering a synced-but-uncommitted release or a failed rebuild, the environment updates that self-apply after a pull, the one-time submodule-to-vendored conversion, consuming upstream_changes documents, and rsx:framework:status/verify. Use when running or recovering a pull, reading its output, or reacting to a restore/tamper/upstream-change notice.
---

# Framework updates

```bash
php artisan rsx:framework:pull     # the update. User-initiated ONLY.
php artisan rsx:framework:status   # installed release + is an update available?
php artisan rsx:framework:verify   # tamper check: tree vs release inventory + markers
```

`system/` is **vendored** into your app repo as ordinary tracked files - not a submodule, no gitlink. The pull refreshes those files from the distribution repository and commits them for you.

**Set a 10-minute (600000ms) command timeout.** An uncached run clones the full distribution, syncs ~110k files and runs the whole rebuild chain. A 2-minute tool default is not enough, and killing the pull mid-run leaves a partial (recoverable) update. Foreground with the long timeout, or detached with output to a log you poll. This is a tool-call budget, not a timeout in code - the framework's no-timeout mandate is untouched.

---

## What a pull does, in order

1. **Maintenance window up** (reason `framework update in progress`) - the same window `rsx:maintenance:enable` raises: tasks killed, realtime/fpc/php-fpm/lockd/redis stopped, web answers 503 then 502. Lifted on every exit path, success or failure. See `rspade:maintenance-mode`.
2. **Clone / refresh the distribution cache**, then **`rsx:clean`** to drop machine-made churn.
3. **Tamper gate**: `system/` is checked against the release inventory and the mutation ledger. Unauthorized (hand-made) changes under an owned zone ABORT the run unless `--force`.
4. **Hard-sync the owned zones** (whole directories plus individual owned files) - they are convergent state, made equal to the release every time.
5. **Three-way reconcile everything else** - your deliberate `system/` changes are preserved, conflicts reported. **It never merges and never stashes.**
6. **Commit `system/`** as ONE framework-update commit, path-scoped so your in-flight app work is never swept in.
7. **Rebuild** (clean + manifest + bundles), then run `system/bin/post-update.sh`.
8. **Report**: restored files, upstream changes, pending migrations.

**The commit happens BEFORE the rebuild, and that is the whole recovery story**: the commit records the release exactly as synced, so a rebuild that fails costs only the build. **Fix what it reports and rebuild. Never re-pull.**

**Then run `php artisan migrate`.** A pull can carry migrations from other environments or from the framework itself; until migrate runs, code and schema disagree. Do this whenever the user asks for a pull, without being asked separately. It is safe when nothing is pending.

The pull is **development-mode only** (`RSX_MODE=development`) - a sealed debug/production build is immutable by design, so return the host to development before updating it.

---

## Flags

| Flag | Effect |
|---|---|
| `--diff` | Preview only: changelog + diffstat the update WOULD bring. Zero changes. |
| `--diff-system-changes` | Gate-only inspection: prints exactly the lines YOU changed in owned-zone files. No changes; non-zero exit when anything is flagged. |
| `--check-foreign-changes` | Local-only drift probe: prints uncommitted `system/` changes, exit 1 if dirty. No network, no gates. |
| `--no-rebuild` | Sync + commit, skip the rebuild. Prints the commands to finish by hand. **This is the recovery for a pull that died at the rebuild.** |
| `--no-commit` | Skip the updater's own commit; changes are left for you and the run ends with the synced-but-uncommitted warning. |
| `--verbose` | Stream the per-file reconciliation (also always written to `storage/rsx-framework/last_pull_report.txt`). |
| `--yes` | Non-interactive consent (needed for the submodule conversion without a TTY). |
| `--resync` | RECOVERY, not an override: restore every owned zone to the distribution tip and re-commit `system/` as that release, ignoring what any marker claims (it never short-circuits on "up to date"). For a tree a BACKWARDS MERGE moved off its release. **Not `--force`** - the tamper gate and three-way pass still stand, so accepted drift survives. |
| `--force` | The OVERRIDE flag, and only that. See below. |

### `--force` means ONE thing: override the tamper gate

It (a) overwrites unauthorized changes under the owned zones instead of aborting, (b) takes the UPSTREAM side of every three-way conflict, and (c) proceeds when the gate cannot run because the framework is broken. On an up-to-date install it additionally forces a full repair resync.

**Never pass `--force` without explicit user permission - it DESTROYS local changes under the owned zones.** And it is **NOT** how you restore a missing file, nor how you recover a regressed tree - that is `--resync`.

### A box a backwards merge moved off its release

Symptoms: every rebuild fails, `git status` shows thousands of untracked paths under `system/`, `rsx:framework:verify` reports missing framework files, and an earlier `rsx:git pull` said "moved system/ BACKWARDS" (or a pull committed a "Framework baseline repair" naming an OLD release).

**Do not run `rsx:git pull` first** - its `clean -fdq -- system` deletes exactly the untracked new-release files still on disk. Such a box is also running the OLD updater, so a plain pull can short-circuit before it ever syncs the fixed one: read `upstream_changes/backwards_merge_recovery_08_18.txt` for the bootstrap (restore `system/bin` + `system/artisan` from the pre-merge commit with PLAIN git), then `php artisan rsx:framework:pull --resync`.

---

## Restore semantics: missing vs modified vs unrestorable

Owned zones are **convergent state**: every pull leaves them equal to the release. **Absence is never intent.**

| Situation | What is at stake | What happens |
|---|---|---|
| **MISSING** owned/release file | nothing | Restored on an ordinary pull, named in the output, **no flag**. |
| **MODIFIED / EXTRA** owned file | local content the sync would destroy | Tamper gate stands; `--force` required to overwrite. |
| **UNRESTORABLE** (path outside the passes, or a stale marker promising paths the release no longer carries) | — | Reported separately, **advisory and never fatal**. `--force` does not help and does not claim to. |

The class-override rename (`X.php` moved aside to `X.php.upstream`) is the one innocent explanation for an absence, and is exempted exactly as the tamper gate exempts it.

**If a pull reports restored files, read the list. If it names migrations, run `php artisan migrate`** - that box has been running current framework code against an older schema. (That is the Ascent 2026-08-11 shape: 15 framework files lost from a current install, 13 of them migrations, while every command reported the tree healthy.)

For an unrestorable file you did not delete on purpose: `php artisan rsx:git checkout -- system/<path>`.

**`system/.gitignore` and `system/app/Http/Kernel.php` are framework-owned FILES — do not edit them.** A local edit is tamper-gated like any other owned-file modification. The kernel is owned because a framework release regularly has to change the request stack on your behalf; declare your OWN HTTP middleware in `config('rsx.middleware')` instead (append-only, update-proof — `rsx:man config_rsx`). Before `.gitignore` was declared owned, a single hand tweak froze that file on one box, and that box then silently stopped receiving every future upstream ignore rule — an invisible divergence nobody could see until unrelated files started behaving differently. Relatedly, `system/` must NEVER be gitignored, and neither `git skip-worktree` nor a `*.php.upstream` ignore rule may hide framework churn: either masks drift and silently drops the override zone from your commits. The framework converges all three automatically.

A **completeness check runs even on an up-to-date install** - "up to date" is a claim about the marker, not about the tree.

---

## Recovery paths

**"system/ holds a synced but UNCOMMITTED framework release"**
Do NOT run `rsx:clean` and do NOT make an app commit - either reverts the update.
```bash
php artisan rsx:framework:pull --no-rebuild    # sync + commit, no build gate in between
```
Then rebuild normally.

**The rebuild failed.** Nothing is lost: the release is already committed. Read the error, fix it (usually a pending migration or an app file written against the older framework), and rebuild:
```bash
php artisan rsx:clean && php artisan rsx:manifest:build && php artisan migrate
```
Do not re-pull.

**Maintenance mode is stuck on** (a killed pull): `php artisan rsx:maintenance:disable`. It is intercepted pre-boot and works on a broken tree. See `rspade:maintenance-mode`.

**The gate refuses: "unauthorized framework modifications".** Someone hand-edited `system/`. Inspect with `--diff-system-changes`, then either revert those files, or commit them deliberately (committed drift no longer blocks the `rsx:clean` reset), or discard with `rsx:clean --force`. Reach for `--force` on the pull only when the user has decided those edits are expendable.

**`system/` conflicts during an ordinary git operation**: take INCOMING - and let `php artisan rsx:git` do it for you (`rspade:git-operations`).

---

## The upstream cache

The distribution is cloned into a bare cache at **`/tmp/rspade_upstream.git`**. It is **re-derivable state**: every consumer tolerates its absence, and losing it (a reboot, a `/tmp` sweep) costs exactly one re-clone on the next pull. Never back it up, never repair it - delete it if you suspect it is corrupt.

---

## Environment updates

A successful pull ends by running `system/bin/post-update.sh`, which executes every self-detecting, idempotent script under `system/bin/environment_updates/` (silent when already applied, non-fatal on failure) and sets `core.fileMode false`.

**Three triggers**, all entering through that one script:
1. The pull.
2. A **successful `rsx:manifest:build` in development mode** - the bootstrap-safe trigger: `system/` is vendored, so a plain `git pull` DELIVERS new environment updates that nothing else would ever execute. Skipped under maintenance mode (which is what lets a pull apply them exactly once), serialized on a named lock, never able to fail a build.
3. The **`post-commit` hook**, detached, so a commit never waits.

What ships today:

| Script | What it does |
|---|---|
| `010_claude_statusline.sh` | Claude Code status line (`model • branch • dirty± • hostname`) installed augment-only into `.claude/settings.json`; a custom `statusLine` is never overwritten and `/statusline off` disables it. The dirty count EXCLUDES `system/` - that tree's churn is machine-managed, so the number means YOUR changes. |
| `020_precommit_hook.sh` | Pre-commit hook: runs `rsx:clean --silent` (which resets `system/` to its last commit, untracked files under it removed too; gitignored files like the `system/.env` symlink untouched) and unstages any staged `system/` path, so framework and app histories never mix. Never clobbers a foreign hook. Bypasses: `RSPADE_FRAMEWORK_COMMIT=1`, an in-progress merge, `--no-verify`. |
| `030_relocate_storage.sh` | One-time storage relocation to `<project>/storage`. Always use `storage_path()`. |
| `040_claude_git_guard.sh` | Registers `system/bin/claude-git-guard.sh` as a PreToolUse hook on Bash: blocks a bare `git`, names the `rsx:git` equivalent. Fail-open, augment-only, foreign hooks preserved. |
| `050_post_commit_env_update.sh` | Installs the detached post-commit trigger above. |

**The pre-commit reset is integrity-gated**: `rsx:clean` runs `rsx:framework:verify` first and REFUSES the reset (exit 1) on unauthorized framework modifications rather than destroying them. A modification already COMMITTED to git never blocks - it is reported as committed drift. `rsx:clean --force` skips the check. The hook treats an `rsx:clean` failure as a warning, never a commit blocker; the unstage step is what actually protects the commit.

---

## Status, verify, and the tamper gate

```bash
php artisan rsx:framework:status   # installed release id + date, last update, update available?
php artisan rsx:framework:verify   # every framework file vs the release inventory + markers
```

`status` reads the installed release marker (`system/.rspade-release.json` — the SOLE authority for what release is installed; `rsx/resource/framework_update_history.dat` is a LOG the pull reads only to warn when it contradicts the marker) and then asks the distribution whether anything newer exists. Use it after any merge that touched `system/` to confirm the release you think you have.

`verify` is the offline integrity check the pull and `rsx:clean` both run in-process. Each release SELF-DESCRIBES its pristine state (`system/.rspade-release.json`: `release_id`, `date`, and a sha256 per file), so nothing has to be recorded at update time. Findings come in three shapes:

- **Framework-authored change** - recorded in the mutation ledger (`Framework_Mutations` records every framework-authored write; no regex, no guessing). Expected, silent.
- **Committed drift** - the file differs from the inventory but is identical to git HEAD. Reported, never blocking: the reset would restore exactly what is on disk, and history holds the change.
- **Unauthorized modification** - a hand-made edit. This is what stops a pull (until `--force`) and what makes `rsx:clean` refuse its reset.

The class-override rename (`X.php` -> `X.php.upstream`) is exempted by the gate exactly as it is by the restore pass.

## Upstream changes (things only you can do)

After a pull, pending mandatory changes surface automatically. A document exists **only** when you must act by hand - it is not a changelog.

```bash
php artisan rsx:framework:upstream_changes                                  # list pending
php artisan rsx:framework:upstream_changes:show <name>                      # full text + status
php artisan rsx:framework:upstream_changes:mark <name> --fulfilled          # after you did it
php artisan rsx:framework:upstream_changes:mark <name> --unfulfilled        # reopen
```

Work them before treating the update as finished; `--fulfilled` is your app's record that you did.

---

## Legacy: submodule → vendored

An app still on the old git-submodule layout is offered a **one-time conversion** on its next pull (with confirmation; `--yes` for non-interactive) that vendors `system/` in as tracked files - a single large commit. Afterwards the app is on the ordinary vendored topology and nothing about the submodule era applies.

---

Details: `php artisan rsx:man rsx_upstream`, `rsx:man framework_pull_mechanics`, `rsx:man upstream_changes`. Related: `rspade:git-operations`, `rspade:maintenance-mode`, `rspade:dependencies`.
