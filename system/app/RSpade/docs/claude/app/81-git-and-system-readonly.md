<!-- bucket: app — single-source, never duplicate. True ONLY in a downstream application. THIS FRAGMENT IS THE CANONICAL HOME of the rsx:git rule and the system/-conflict default; the general merge-conflict mandate lives in shared/80. -->

## GIT WORKFLOW — THE FRAMEWORK IS READ-ONLY

**NEVER modify `system/`** - It's like node_modules or the Linux kernel.

- **App repo**: your project root `.git` (you control)
- **Framework**: `system/` (vendored framework files, don't touch)
- **Your code**: `rsx/` (all changes here)

**USE `php artisan rsx:git <any git command>` INSTEAD OF BARE `git`.** It is a transparent proxy — same subcommands, flags, exit codes, stdin and editor/pager behavior — that owns what plain git cannot see on an RSpade box: the build regenerates class-override churn as uncommitted `system/` modifications within a second of any clean, so **bare git loses a race it cannot see** and refuses a pull with "your local changes would be overwritten by merge". A Claude Code PreToolUse hook blocks a bare `git` and names the `rsx:git` equivalent.

What the proxy does: `status`/`diff`/`add` exclude `system/` and submodule gitlinks and `commit` unstages `system/`, so framework churn can never enter an app commit; every tree-rewriting op runs inside a maintenance window with `system/` reset to HEAD first and ONE rebuild after; a `pull`/`merge` decides the framework RELEASE before merging and restates the winner's `system/` tree wholesale, while an APP-file conflict STOPS with maintenance left ON; `push`/`fetch`/`log`/`show` and everything else is plain git. Overrides: `--rsx-raw`, `--rsx-include-system`, `--rsx-no-maint`. **NEVER resolve a pull refusal with `git stash`** or by committing `system/` into an app commit.

**The `system/` reset is integrity-gated**: before discarding drift, `rsx:clean` runs `rsx:framework:verify` and **REFUSES the reset (exit 1)** on unauthorized (hand-made) framework modifications rather than destroying them — inspect with `rsx:framework:pull --diff-system-changes`, or discard with `rsx:clean --force`. **A modification you already COMMITTED never blocks** (reported as committed drift; the reset restores exactly what is on disk).

### `system/` is decided by the RELEASE, never merged file by file

**Framework-tree conflicts are the one blessed exception to the forbidden list in the merge-conflict mandate, and `rsx:git` settles them for you before the merge even runs.** It reads `system/.rspade-release.json` on BOTH refs: the newer release wins and its `system/` tree is restated WHOLESALE over the merge result (same `release_id` = your tree is kept, silently), so a peer a release behind can never push its framework onto you — by conflict or by silent deletion. Two different releases stamped at the SAME instant is refused outright and asked about. **You should not be resolving a `system/` conflict by hand at all**; if one somehow reaches you, stop and ask — "take incoming" was the old default and it is retired, because it could not see the non-conflicting deletion that actually lost files. Afterward confirm the installed release with `rsx:framework:status` and `rsx:framework:verify`.

**If a box has already been moved BACKWARDS off its release** (system/ regressed, rebuilds failing, thousands of untracked paths under `system/`): do NOT run `rsx:git pull` — its `clean -fdq -- system` deletes the untracked new-release files. Recover with `php artisan rsx:framework:pull --resync`, and read `upstream_changes/backwards_merge_recovery_08_18.txt` first: a regressed box is running the OLD updater and needs a one-line bootstrap before `--resync` exists.

Skill: `rspade:git-operations`. Details: `php artisan rsx:man rsx_git`.
