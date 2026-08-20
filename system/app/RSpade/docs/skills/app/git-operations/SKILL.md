---
name: git-operations
description: Running git in a downstream RSpade app through the php artisan rsx:git proxy - why bare git loses a race it cannot see, what the proxy does per subcommand (pathspec exclusion, commit unstaging, the maintenance cycle, passthrough), how system/ and app-file conflicts are handled, the stash-failed evidence classification and the scratch-worktree escape, the override flags, and troubleshooting a refusal. Use for any git operation on an app checkout, or when git refuses with overwritten-by-merge, stash failed, or unauthorized framework modifications.
---

# Git operations on an RSpade app

**Use `php artisan rsx:git <any git command>` instead of bare `git`.** Same subcommands, flags, exit codes, stdin, editor and pager behavior - only `--rsx-*` flags are consumed. A Claude Code PreToolUse hook blocks a bare `git` and names the equivalent.

## Why it exists

The build keeps class-override churn (`.php` <-> `.php.upstream` renames, use-rewrites) as UNCOMMITTED `system/` modifications and REGENERATES it within a second of any clean. So a bare `git pull` carrying another environment's framework-update commit refuses with *"your local changes would be overwritten by merge"*, and **no amount of cleaning first reliably wins that race**. `rsx:git` owns the tree instead of fighting it.

---

## Treatment by subcommand

Four treatments. Anything not named is plain git.

**1. Pathspec exclusion — `status`, `diff`, `add`**
`system/` and every submodule gitlink are excluded with a pathspec (`-- :/ ':(exclude,top)system' ...`), so **git** produces the output: native color, native pager, native exit codes, byte-clean `--porcelain`. The wrapper never filters a stream. The gitlink list is derived at runtime from `git ls-files --stage` (mode 160000), never hardcoded. A human `status` prints a one-line stderr footer naming how many paths it hid; `--porcelain`/`-z` suppress every wrapper notice.

**2. Commit — `commit`**
Any staged `system/` path is unstaged first, whatever staged it. **`-a` is REWRITTEN, not decorated**: a pathspec on `git commit` means "commit ONLY these paths, bypassing the index", which is a different operation - so `-a` becomes an excluding `git add -u -- :/ <excludes>` followed by a plain commit. Short-flag clusters parse correctly (`-am` -> `-m`; `-madd` is left alone, that `a` is part of the message).

**3. Maintenance cycle — the tree-rewriting ops**
`pull merge rebase cherry-pick revert checkout switch restore clean`, `reset --hard|--merge|--keep`, `stash pop|apply`:

```
rsx:maintenance:enable --reason="rsx:git <op>"
  -> clear skip-worktree on system/
  -> reset system/ to HEAD          (so incoming framework changes apply cleanly)
  -> run the operation
  -> rebuild once                   (rsx:clean + rsx:manifest:build)
  -> pending-migration notice       (advisory; silent when the schema is current)
  -> rsx:maintenance:disable
```
The disable runs from an EXIT/INT/TERM trap, so a failure or Ctrl-C never leaves maintenance stuck on; the rebuild happens BEFORE services return. **Ops that rewrite nothing are deliberately excluded** - `checkout -b` / `switch -c`, `clean -n`, `restore --staged` alone, a plain `reset`.

Before discarding drift the proxy runs `rsx:framework:verify`, and REFUSES the whole operation if `system/` carries unauthorized (hand-made) modifications, pointing at `rsx:clean --force` as the deliberate discard. **That refusal is the proxy's only one** - it is fail-open everywhere else.

**4. Passthrough — `push fetch remote tag config log show branch blame rev-parse apply`, and anything unknown**
Ref-only and remote ops touch no working tree. **`log` and `show` are deliberately NOT filtered**: they display COMMITS, not working-tree churn, and hiding framework-update history is the opposite of helpful.

---

## Conflicts

**`system/` is decided by the RELEASE, BEFORE the merge runs (Phase 0).** `pull`/`merge` read `system/.rspade-release.json` on both refs - one `git show` + one `sed` per side, no checkout, no php - and the newer ISO-8601 date wins. The merge is then held open with `--no-commit` and the winner's `system/` tree is restated WHOLESALE (`rm -r --cached` + `checkout <winner> -- system` + `clean -fdq` with no `-x` + `add -A`), so conflicts under `system/` are discarded with everything else and the merge commit carries one side's framework tree, not a blend. A fast-forward or rebase gets its own restate commit.

  - same `release_id` -> your tree is kept and the restate says NOTHING (a routine merge is not an event; it is still what erases a peer's stray `system/` deletions, which downstream are drift, never a release)
  - **equal date + DIFFERENT `release_id` -> REFUSED, and you are asked.** Nothing can order two releases stamped at the same instant and the wrong guess loses one; nothing is left half-done
  - marker missing/unparseable on either side -> plain git merge with a loud warning, and `system/` conflicts then reach YOU like any others

**When your side wins, the stale box is named** (release, days behind, the committer of its newest `system/`-touching commit) - it will keep re-sending its tree until somebody runs `rsx:framework:pull` there.

**What this replaced:** per-file "resolve to INCOMING", its rebase `--ours/--theirs` inversion, and its keep-local patch. All answered a whole-tree question one file at a time, and none could see the shape that actually loses code - a NON-conflicting incoming deletion, which git applies silently. That shape cost framework files on 2026-08-11 and moved a box back a full release on 2026-08-18.

**Already regressed?** Do NOT run `rsx:git pull` (its `clean -fdq -- system` deletes the untracked new-release files a bad repair left behind). Recover with `php artisan rsx:framework:pull --resync` after the bootstrap in `upstream_changes/backwards_merge_recovery_08_18.txt`.

**App-file conflicts HALT, with maintenance left ON** - services are down specifically so you can resolve the merge without traffic hitting a half-merged tree:

```bash
# resolve the files
php artisan rsx:git commit
php artisan rsx:maintenance:disable
```
`rsx:maintenance:disable` **refuses while the repository has unmerged paths** (it names them, and names `--force`). That single guard is what holds the halt in place. A resolved-but-uncommitted merge is fine; only unmerged index entries block.

Everything else obeys the merge-conflict mandate: per hunk, keep both by default, never a blanket resolution.

---

## The index, and `fatal: stash failed`

Every READ the proxy makes runs with `--no-optional-locks` - a plain `git status` otherwise refreshes and REWRITES the index, making the wrapper a contender against the operation it is wrapping. Consequence nobody costed at the time: on a box whose ctimes churn, nothing refreshes the index any more and it goes permanently stat-stale - one half of `fatal: stash failed`. So a tree-rewriting op runs **ONE deliberate `git update-index --refresh -q`** before the caller's command, and only there. Reads still never write the index.

`stash failed` has two causes wearing one message, so it is classified on **EVIDENCE, never wording**:

| Evidence | Classification | Action |
|---|---|---|
| `.git/index.lock` EXISTS right now | contention | Retry with bounded backoff (5 attempts, ~0.2s-0.8s), then give up loudly. |
| no lock file | DETERMINISTIC | Retried **zero** times. Take the scratch-worktree escape. |

Re-issuing under observed contention is safe: git aborted in its pre-merge `save_state()` before any work, so there is no `MERGE_HEAD` and HEAD has not moved. It is gated twice (classification says contention AND git left no operation in progress), so a genuine conflict fails on attempt one and is never re-run behind your back.

**The scratch-worktree escape (`pull` and `merge` only):**

```
git worktree add <tmp> -b rsx-git-escape-<pid> HEAD
git -C <tmp> merge --no-edit <ref>        # system/ conflicts auto-resolved there too
git merge --ff-only rsx-git-escape-<pid>
worktree removed, branch deleted          # on every exit path
```
A linked worktree has its OWN index and checks out no submodules, so this tree's stale index is irrelevant; the way home is a fast-forward, which never calls `save_state` and lands even while this tree is dirty. The ref is resolved honestly (for `pull`, the fetch is re-issued and FETCH_HEAD read afterwards, so a stale FETCH_HEAD can never be merged).

Three non-success outcomes, all leaving this working tree untouched: an app conflict in the scratch merge (reported, and maintenance comes DOWN - the conflict went away with the worktree); **the fast-forward is refused** ("your local changes ... would be overwritten") - the incoming change touches a file you genuinely modified, and this is the **one place the proxy ever says "commit or stash first"**; or the ref cannot be identified (the escape is not attempted, and says so).

---

## Override flags

| Flag | Use |
|---|---|
| `--rsx-raw` | No exclusion: show and stage the true tree including `system/`. "What does git actually think is dirty?" |
| `--rsx-include-system` | Deliberately stage/commit a `system/` change you mean to keep. |
| `--rsx-no-maint` | Skip the maintenance enable/disable pair (you already bracketed a batch yourself). |
| `--rsx-help` | Wrapper usage. |

---

## Everyday recipes

```bash
php artisan rsx:git status                     # your app's changes; system/ hidden, count footed
php artisan rsx:git add -A && php artisan rsx:git commit -m "..."   # system/ can never ride along
php artisan rsx:git pull                       # maintenance window + reset + rebuild, automatically
php artisan migrate                            # ALWAYS after a pull that landed anything
php artisan rsx:git log -- system              # framework-update history, deliberately unfiltered
php artisan rsx:git --rsx-raw status           # what git ACTUALLY thinks is dirty
```

A pull through the proxy ends with the same pending-migration notice the framework updater prints - one line, silent when the schema is current, and never a failure of the git operation.

## Troubleshooting

- **"your local changes would be overwritten by merge"** on a bare `git` - stop using bare git; re-run through `rsx:git`. **NEVER resolve it with `git stash`** or by committing `system/` into an app commit.
- **"unauthorized framework modifications"** - `system/` carries hand-made edits. Inspect (`rsx:framework:pull --diff-system-changes`), then revert them, commit them deliberately (committed drift never blocks), or discard with `rsx:clean --force`.
- **Maintenance stuck on after a halt** - resolve and commit the merge first; `rsx:maintenance:disable` refuses while unmerged (`--force` overrides, and leaves you serving a half-merged tree).
- **Framework files missing after a merge** - `php artisan rsx:framework:pull` restores them, no flag.
- **In the RSpade monorepo `rsx:git` is exactly git** - no exclusion, no cycle, nothing announced; there `system/` is authored source.

Details: `php artisan rsx:man rsx_git`. Related: `rspade:framework-updates`, `rspade:maintenance-mode`.
