#!/usr/bin/env bash

# =============================================================================
# rsx:git - a transparent git proxy that owns system/ safety + service quiescing
# =============================================================================
# Invoked as `php artisan rsx:git <anything you would pass to git>`, intercepted
# PRE-BOOT in system/artisan (like rsx:framework:pull and rsx:maintenance:*), so
# running it never itself triggers a manifest rebuild and it works while the
# maintenance gate is up.
#
# WHY THIS EXISTS
#   A live RSpade box has three processes mutating the working tree during any git
#   operation, and plain git is defended against none of them:
#     1. the container's fixperms loop chmod/chowns every newly created file,
#        including files inside .git/, racing git's own writes;
#     2. the JIT manifest rebuild re-applies the class-override pass (.php <->
#        .php.upstream renames + autoloader rewrite) on any web request, re-dirtying
#        system/ within ~1s of every clean;
#     3. .git/index.lock contention from concurrent status/build processes.
#   Net: system/ cannot be held clean for the instant a pull/merge needs it, so any
#   pull carrying framework-update commits aborts. This proxy owns the invariant -
#   the app never sees or commits system/ churn, and tree-rewriting ops run quiesced -
#   exactly as rsx:framework:pull owns the unsafe framework-update git dance.
#
# THE FOUR TREATMENTS (by subcommand)
#   passthrough      everything not named below - argv, stdin, TTY, exit code untouched
#   pathspec-exclude status / diff / add - system/ and gitlinks excluded via a pathspec
#                    so GIT produces the output (native color, pager, exit codes)
#   commit           staged system/ paths unstaged first; `-a` rewritten to an
#                    excluding `add -u` (pathspecs on commit would change its meaning)
#   maintenance      pull / merge / rebase / cherry-pick / revert / checkout / switch /
#                    restore / clean / reset --hard / stash pop|apply:
#                       maintenance:enable -> reset system/ to HEAD -> op -> rebuild
#                       -> maintenance:disable   (EXIT trap; never left stuck on)
#                    pull/merge additionally run PHASE 0 first: read
#                    system/.rspade-release.json on BOTH refs, decide which release is
#                    the framework (newer date wins; equal date + different id REFUSES),
#                    hold the merge open with --no-commit and restate that side's
#                    system/ tree wholesale before committing. system/ is one tree with
#                    one writer - it is never merged file by file.
#
# FAIL-OPEN. This wraps every git call for every downstream app, so a wrapper bug must
# never brick git. Anything unexpected falls through to plain git with the original
# argv (see bail_to_git). The maintenance step failing does not stop the git op.
#
# MONOREPO INERTNESS. When IS_FRAMEWORK_DEVELOPER=true this is EXACTLY git: no
# exclusion, no maintenance cycle, no system/ handling. Here system/ is the authored
# framework source, not a vendored tree - excluding it would be catastrophic.
#
# Full treatment: php artisan rsx:man rsx_git
# =============================================================================

set -uo pipefail

# The original argv, kept verbatim for the fail-open path.
ORIG_ARGV=("$@")

# -----------------------------------------------------------------------------
# Paths - derived from this script's own location, never the caller's cwd. A cwd the
# caller genuinely chose is PRESERVED (git's behavior depends on it and this is a
# transparent proxy); the artisan shim's own chdir into system/ is undone below. Only
# project-root-scoped work is explicitly -C'd.
# -----------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYSTEM_DIR="$(dirname "$SCRIPT_DIR")"
PROJECT_ROOT="$(dirname "$SYSTEM_DIR")"

# THE CALLER'S CWD IS ALREADY GONE BY THE TIME WE RUN, AND IT IS NOT system/.
#
# The project-root `artisan` shim does `chdir(__DIR__ . '/system')` before including
# the real one, so every `php artisan rsx:git ...` reaches this script with cwd =
# system/ no matter where the operator was standing. Git resolves a relative pathspec
# against cwd, so `rsx:git log -- rsx/foo.php` matched NOTHING and exited 0 - a silent
# wrong answer from an audit command, which is the worst failure shape there is
# (reported 2026-08-18; `rsx:git rev-parse --show-prefix` printed `system/`).
#
# Nothing can recover the operator's real directory (php's chdir happened in another
# process), so the honest anchor is the PROJECT ROOT: the repository's top level, which
# is what every relative pathspec a human types is written against. Only the shim's
# own chdir is undone - a cwd the caller genuinely chose (running this script directly,
# as the CLI fixtures do) is left alone. Same reasoning, and same pin, as the framework
# updater's derive_paths().
if [ "$(pwd -P 2>/dev/null)" = "$SYSTEM_DIR" ]; then
    cd "$PROJECT_ROOT" || exit 1
fi

# git -C at the project root with the inherited git context stripped. Hooks export
# GIT_DIR/GIT_INDEX_FILE, which would silently re-target these calls at an
# in-progress commit's index (same defense as Clean_Command::reset_system_tree).
ROOT_GIT=(env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C "$PROJECT_ROOT")

# The READ-ONLY twin. Every probe this proxy makes must run with --no-optional-locks,
# because a plain `git status`/`diff` REFRESHES and REWRITES the index, taking
# .git/index.lock to do it. That is not a theoretical cost: git's own pre-merge
# save_state() shells out to `git stash create`, and when that child loses the race for
# index.lock it fails silently and the merge dies with the generic `fatal: stash failed`
# having done no work. Measured: 29/40 failures under a concurrent index-refreshing
# reader, 0/40 with --no-optional-locks. So the proxy must never be its own contender -
# nor a contender against the second agent session sharing this repository.
#
# Deliberately a SEPARATE array: ROOT_GIT is used for index WRITES too, where the flag
# would be meaningless, and keeping the split makes each call site's intent legible.
ROOT_GIT_RO=(env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git --no-optional-locks -C "$PROJECT_ROOT")

# The same two, with the working directory as an argument. The conflict machinery has to
# run against a SCRATCH WORKTREE as well as the project root (see scratch_merge_escape),
# so the handful of functions it shares express their git calls through these.
#   dgit <dir> <args...>     writes (index-touching)
#   dgit_ro <dir> <args...>  reads (never takes the optional lock - see ROOT_GIT_RO)
dgit()    { local d="$1"; shift; env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C "$d" "$@"; }
dgit_ro() { local d="$1"; shift; env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git --no-optional-locks -C "$d" "$@"; }

# -----------------------------------------------------------------------------
# Output. Notices go to stderr so `rsx:git status --porcelain` stays parseable.
# -----------------------------------------------------------------------------
QUIET=false

note() { [ "$QUIET" = true ] || printf '\033[2m[rsx:git] %s\033[0m\n' "$*" >&2; }
warn() { printf '[WARNING] %s\n' "$*" >&2; }
err()  { printf '%s\n' "$*" >&2; }

# Fail-open: run plain git with the ORIGINAL argv and become that process.
bail_to_git() {
    exec git "${ORIG_ARGV[@]}"
}

usage() {
    cat <<'USAGE'
Usage: php artisan rsx:git <git subcommand> [args...]

A transparent git proxy. Every subcommand, flag, exit code, stdin and TTY behavior
passes through to real git; only the --rsx-* flags below are consumed here.

  --rsx-raw             no exclusion; show/stage the true tree including system/
  --rsx-no-maint        skip the maintenance enable/disable pair
  --rsx-include-system  deliberately stage/commit a system/ change
  --rsx-help            this text

What it does automatically:
  status / diff / add   system/ and submodule gitlinks excluded via a pathspec
  commit                staged system/ paths unstaged; `-a` staged with the exclusion
  pull / merge / rebase / cherry-pick / revert / checkout / switch / restore /
  clean / reset --hard / stash pop|apply
                        maintenance mode ON -> system/ reset to HEAD -> op ->
                        rebuild once -> maintenance mode OFF
  pull / merge          plus: the framework release is reconciled BEFORE the merge and
                        the winning side's system/ tree is restated wholesale
  push / fetch / log / show / branch / everything else
                        plain git, untouched

Full treatment: php artisan rsx:man rsx_git
USAGE
}

# -----------------------------------------------------------------------------
# Consume the --rsx-* namespace. Everything else flows to git untouched, so git's
# own --force/-f/--hard can never collide with a wrapper flag.
# -----------------------------------------------------------------------------
RSX_RAW=false
RSX_NO_MAINT=false
RSX_INCLUDE_SYSTEM=false
ARGS=()

for arg in ${1+"$@"}; do
    case "$arg" in
        --rsx-raw)            RSX_RAW=true ;;
        --rsx-no-maint)       RSX_NO_MAINT=true ;;
        --rsx-include-system) RSX_INCLUDE_SYSTEM=true ;;
        --rsx-help)           usage; exit 0 ;;
        --rsx-*)
            err "[ERROR] Unknown wrapper flag: $arg"
            err "Known: --rsx-raw, --rsx-no-maint, --rsx-include-system, --rsx-help"
            exit 1
            ;;
        *) ARGS+=("$arg") ;;
    esac
done

# No subcommand at all -> let git print its own usage.
if [ "${#ARGS[@]}" -eq 0 ]; then
    exec git
fi

for arg in "${ARGS[@]}"; do
    case "$arg" in
        --porcelain|--porcelain=*|-z|--quiet) QUIET=true ;;
    esac
done

# -----------------------------------------------------------------------------
# Monorepo: exactly git. The --rsx-* flags are still consumed above (they are ours,
# and git would reject them), but nothing else differs and nothing is announced.
# -----------------------------------------------------------------------------
if [ -f "$PROJECT_ROOT/.env" ] && grep -qE '^IS_FRAMEWORK_DEVELOPER=true[[:space:]]*$' "$PROJECT_ROOT/.env" 2>/dev/null; then
    exec git "${ARGS[@]}"
fi

# No git at all, or not a git repository -> nothing to own. Let git speak.
command -v git >/dev/null 2>&1 || bail_to_git
"${ROOT_GIT_RO[@]}" rev-parse --git-dir >/dev/null 2>&1 || exec git "${ARGS[@]}"

# -----------------------------------------------------------------------------
# Find the real subcommand by stepping past git's global options. `git -C x -c k=v
# --no-pager status` must classify as `status`, not as `-C`.
# -----------------------------------------------------------------------------
SUBCMD=""
SUB_INDEX=-1
arg_index=0
skip_next=false

for arg in "${ARGS[@]}"; do
    if [ "$skip_next" = true ]; then
        skip_next=false
        arg_index=$((arg_index + 1))
        continue
    fi

    case "$arg" in
        # Global options that consume the NEXT argument.
        -C|-c|--git-dir|--work-tree|--namespace|--exec-path|--super-prefix|--config-env)
            skip_next=true
            ;;
        # Self-contained global options (including every =value form).
        -*)
            : ;;
        *)
            SUBCMD="$arg"
            SUB_INDEX=$arg_index
            break
            ;;
    esac
    arg_index=$((arg_index + 1))
done

# No subcommand found (e.g. `git --version`) -> passthrough.
if [ -z "$SUBCMD" ]; then
    exec git "${ARGS[@]}"
fi

# Arguments AFTER the subcommand - what the per-class handlers inspect.
SUB_ARGS=()
if [ "$SUB_INDEX" -ge 0 ] && [ "$((SUB_INDEX + 1))" -lt "${#ARGS[@]}" ]; then
    SUB_ARGS=("${ARGS[@]:$((SUB_INDEX + 1))}")
fi

# Does any argument after the subcommand match one of the given literals?
sub_args_have() {
    local want a
    for a in ${SUB_ARGS[@]+"${SUB_ARGS[@]}"}; do
        for want in "$@"; do
            [ "$a" = "$want" ] && return 0
        done
    done

    return 1
}

# Does any SHORT-FLAG CLUSTER after the subcommand carry this letter? sub_args_have
# only matches a whole argument, so `clean -nd` (which is `-n -d`) read as a real clean
# and stopped the world - maintenance window, system/ reset and all - for what the user
# asked to be a preview. $2 lists the letters that consume the rest of their cluster as
# a VALUE (`clean -e <pattern>`), so `-ne` never mistakes a pattern for a flag; the same
# treatment cluster_requests_all gives `commit -am`.
sub_args_have_short_flag() {
    local want="$1" value_flags="${2:-}" a i ch

    for a in ${SUB_ARGS[@]+"${SUB_ARGS[@]}"}; do
        [ "$a" = "--" ] && break
        case "$a" in
            --*) continue ;;
            -?*) : ;;
            *)   continue ;;
        esac

        for (( i = 1; i < ${#a}; i++ )); do
            ch="${a:$i:1}"
            [ "$ch" = "$want" ] && return 0
            [[ "$value_flags" == *"$ch"* ]] && break
        done
    done

    return 1
}

# -----------------------------------------------------------------------------
# The exclusion pathspec. system/ plus every gitlink, DERIVED - never hardcoded:
# each app has its own submodules (this template has none; a downstream may have
# several), so a baked-in list would be wrong everywhere but the box it came from.
#
# `:/` is git's "whole tree from the root" pathspec, which is what `add -A` /
# `status` / `diff` already mean with no pathspec at all - so injecting it changes
# nothing except adding the exclusions.
# -----------------------------------------------------------------------------
EXCLUDES=()
EXCLUDES_BUILT=false

build_excludes() {
    [ "$EXCLUDES_BUILT" = false ] || return 0
    EXCLUDES_BUILT=true

    EXCLUDES=(':(exclude,top)system')

    # `<mode> <sha> <stage>\t<path>`; the sed keeps everything after the tab, so a
    # path containing spaces survives (awk $4 would not).
    local link
    while IFS= read -r link; do
        [ -n "$link" ] || continue
        EXCLUDES+=(":(exclude,top)$link")
    done < <("${ROOT_GIT_RO[@]}" ls-files --stage 2>/dev/null | sed -n 's/^160000 [0-9a-f]* [0-9]\t//p')
}

# Does the caller's own argument list already carry a `--` pathspec separator?
has_pathspec_separator() {
    local a
    for a in ${SUB_ARGS[@]+"${SUB_ARGS[@]}"}; do
        [ "$a" = "--" ] && return 0
    done

    return 1
}

# The arguments to append: the excludes, plus the `-- :/` scaffolding when the
# caller supplied no pathspec of their own.
EXCL_TAIL=()

build_excluded_tail() {
    build_excludes

    if has_pathspec_separator; then
        EXCL_TAIL=("${EXCLUDES[@]}")
    else
        EXCL_TAIL=(-- ':/' "${EXCLUDES[@]}")
    fi
}

# How many paths the exclusion is hiding right now (for the status footer).
hidden_path_count() {
    build_excludes

    local positives=() e
    for e in "${EXCLUDES[@]}"; do
        positives+=(":(top)${e#:(exclude,top)}")
    done

    "${ROOT_GIT_RO[@]}" status --porcelain -- "${positives[@]}" 2>/dev/null | grep -c . || true
}

# -----------------------------------------------------------------------------
# index.lock retry. Used for this wrapper's own index-writing calls, where capturing
# output is harmless.
#
# The caller's own op IS re-run, but ONLY under one narrowly classified failure: git
# died in its pre-merge save_state() because the internal `git stash create` lost the
# race for .git/index.lock (`fatal: stash failed`). That failure happens BEFORE any
# work - no MERGE_HEAD, HEAD unmoved - so re-issuing is idempotent. Any other failure,
# or a failure with an operation already in progress, is never retried: silently
# re-issuing an arbitrary git command is a worse failure mode than the error itself.
# See run_maintenance_op.
# -----------------------------------------------------------------------------
LOCK_RETRIES=5

git_retry() {
    local attempt=1 out rc delay

    while :; do
        out="$("$@" 2>&1)"
        rc=$?
        [ "$rc" -eq 0 ] && return 0

        # `stash failed` is the same contention wearing git's generic wording: the child
        # `git stash create` lost the index.lock race and reported nothing of its own.
        # `Unable to write index` is a third wording of the same family - git holding a
        # refreshed in-process index it cannot write back because the lock is taken.
        case "$out" in
            *index.lock*|*"stash failed"*|*"Unable to write index"*)
                if [ "$attempt" -ge "$LOCK_RETRIES" ]; then
                    err "[ERROR] git index is locked after $attempt attempts: $out"

                    return "$rc"
                fi
                delay="0.$((attempt * 2))"
                note "index.lock contention; retrying in ${delay}s (attempt $attempt/$LOCK_RETRIES)."
                sleep "$delay"
                attempt=$((attempt + 1))
                ;;
            *)
                [ -n "$out" ] && printf '%s\n' "$out" >&2

                return "$rc"
                ;;
        esac
    done
}

# Absolute path of the repository's .git directory. Takes the working directory as an
# optional argument so a linked worktree can be asked about its own state (a worktree's
# git dir is <main>/.git/worktrees/<name>, and that is where ITS MERGE_HEAD lives).
git_dir_abs() {
    local dir="${1:-$PROJECT_ROOT}" git_dir
    git_dir="$(dgit_ro "$dir" rev-parse --git-dir 2>/dev/null)" || return 1
    case "$git_dir" in
        /*) printf '%s' "$git_dir" ;;
        *)  printf '%s' "$dir/$git_dir" ;;
    esac
}

# One dim hint when a FAILED caller op looks like lock contention.
lock_hint_if_relevant() {
    local git_dir
    git_dir="$(git_dir_abs)" || return 0
    [ -e "$git_dir/index.lock" ] || return 0
    note "$git_dir/index.lock exists - another git process may be running. Retry once it clears."
}

# The op died in git's pre-merge save_state() (its internal `git stash create` lost a
# race for .git/index.lock) AND a lock file was observed while it was happening. The merge
# performed no work.
#
# This is the honest replacement for lock_hint_if_relevant on that path: by the time the
# op reports failure the lock file is almost always gone again, so the file-existence
# hint stays silent about the one failure it was written for.
stash_contention_hint() {
    err "[rsx:git] '$SUBCMD' aborted before doing anything: git could not stash your"
    err "          tracked state because another git process held .git/index.lock"
    err "          (any concurrent 'git status' takes it briefly). The lock file was"
    err "          observed during the attempts - this is contention, not a stuck tree."
    err "          Retried $LOCK_RETRIES times without success. Wait for the other process"
    err "          and run it again."
    err "          git reaches that stash path whenever its merge machinery believes the"
    err "          index differs from HEAD - which includes a merely STAT-STALE index on"
    err "          an otherwise clean worktree, so a clean tree is no protection."

    return 0
}

# The same wording from git, with NO lock file anywhere near it: git's merge machinery and
# its child `git stash create` disagree about the index deterministically, so the op fails
# the same way every time and retrying is pure delay.
#
# Only reachable when the scratch-worktree escape could not run or could not finish -
# whichever step stopped has already printed its own specific error above this.
stash_deterministic_hint() {
    err "[rsx:git] '$SUBCMD' aborted before doing anything: git reported 'stash failed',"
    err "          and NO .git/index.lock existed while it did - so this is not lock"
    err "          contention and re-running it would only fail again the same way."
    err "          Already tried: 'git update-index --refresh' before the operation"
    if [ "$SUBCMD" = "pull" ] || [ "$SUBCMD" = "merge" ]; then
        err "          (a stat-stale index is one known cause), then running the same merge"
        err "          in a clean scratch worktree to fast-forward the result back in."
    else
        err "          (a stat-stale index is one known cause). The scratch-worktree merge"
        err "          that rescues this for pull/merge does not apply to '$SUBCMD'."
    fi
    err "          The step that could not finish reported its own reason above."

    return 0
}

# =============================================================================
# Class: maintenance cycle (tree-rewriting ops)
# =============================================================================
MAINT_RAISED=false
MAINT_HOLD=false          # true = deliberately leave maintenance ON (app conflict)

maint_enable() {
    if [ "$RSX_NO_MAINT" = true ]; then
        note "Maintenance cycle skipped (--rsx-no-maint)."

        return 0
    fi

    if php "$SYSTEM_DIR/artisan" rsx:maintenance:enable --reason="rsx:git $SUBCMD" >/dev/null 2>&1; then
        MAINT_RAISED=true
        note "Maintenance mode ON for this $SUBCMD (services stopped). Override: --rsx-no-maint"
    else
        # FAIL-OPEN: a broken maintenance step must not stop the git operation.
        warn "Could not enter maintenance mode; continuing WITHOUT quiescing services."
    fi
}

maint_disable() {
    [ "$MAINT_RAISED" = true ] || return 0
    MAINT_RAISED=false

    if php "$SYSTEM_DIR/artisan" rsx:maintenance:disable >/dev/null 2>&1; then
        note "Maintenance mode OFF (services restored)."
    else
        warn "Could not leave maintenance mode. Run: php artisan rsx:maintenance:disable"
    fi
}

# Fires on every exit path - normal, error, or Ctrl-C - so maintenance is never left
# stuck on. The ONE exception is the app-conflict halt, which sets MAINT_HOLD.
maint_cleanup() {
    [ "$MAINT_HOLD" = true ] && return 0
    maint_disable
}

# Reset the vendored framework tree to HEAD so incoming framework changes apply
# cleanly. rsx:clean CANNOT do this for us: its reset is skipped whenever the
# maintenance flag is up (Clean_Command::framework_update_in_progress), which is
# exactly the state we are in.
reset_system_tree() {
    [ -n "$("${ROOT_GIT_RO[@]}" ls-files -- system 2>/dev/null | head -n 1)" ] || return 0

    # skip-worktree hides framework churn from git and would make the reset a no-op
    # while the files stay dirty on disk. Clear it first, always.
    local hidden
    hidden="$("${ROOT_GIT_RO[@]}" ls-files -v -- system 2>/dev/null | sed -n 's/^S //p')"
    if [ -n "$hidden" ]; then
        note "Clearing skip-worktree on $(printf '%s\n' "$hidden" | grep -c .) system/ path(s)."
        printf '%s\n' "$hidden" | tr '\n' '\0' \
            | xargs -0 -r "${ROOT_GIT[@]}" update-index --no-skip-worktree -- >/dev/null 2>&1 || true
    fi

    [ -n "$("${ROOT_GIT_RO[@]}" status --porcelain -- system 2>/dev/null | head -n 1)" ] || return 0

    # INTEGRITY GATE. Before discarding drift, prove system/ carries no hand-made
    # modifications. Same gate rsx:clean applies; a deliberate discard stays an
    # explicit, separate `rsx:clean --force`.
    if ! php "$SYSTEM_DIR/artisan" rsx:framework:verify >/dev/null 2>&1; then
        err "[ERROR] system/ carries UNAUTHORIZED framework modifications - refusing to discard them."
        err "        Inspect:  php artisan rsx:framework:verify"
        err "        Discard:  php artisan rsx:clean --force   (then re-run this command)"

        return 1
    fi

    git_retry "${ROOT_GIT[@]}" reset -q -- system || return 1
    git_retry "${ROOT_GIT[@]}" checkout -q -- system || return 1

    # `reset` and `checkout -- <path>` act on TRACKED paths only, so before this step
    # the "reset to HEAD" left every UNTRACKED file under system/ exactly where it was
    # - and an untracked file is precisely what a class-override sidecar is whenever
    # the committed tree still names the file `X.php`. A peer box's framework-update
    # commit that tracks `X.php.upstream` then collides with it and git refuses the
    # whole merge ("untracked working tree files would be overwritten by merge"),
    # deterministically, every re-run (field report, 2026-08-18). The notice claimed the
    # churn had been discarded while the file that blocked the pull was still there.
    #
    # NO `-x`. Gitignored state under system/ - the `.env` deployment symlink, ignored
    # build/vendor trees - is not churn and must survive, which is the same line
    # rsx:clean's reset draws. This tree only has to be pristine for the instant of the
    # merge; holding it clean is impossible and is not what this is for (see the header).
    git_retry "${ROOT_GIT[@]}" clean -fdq -- system || return 1
    note "system/ reset to HEAD and cleaned of untracked framework churn (the rebuild regenerates it)."

    return 0
}

rebuild_system_tree() {
    php "$SYSTEM_DIR/artisan" rsx:clean --silent --_no-system-reset --_framework-update-override >/dev/null 2>&1 || true

    if php "$SYSTEM_DIR/artisan" rsx:manifest:build --_framework-update-override >/dev/null 2>&1; then
        note "system/ rebuilt once (class overrides + manifest)."
    else
        warn "Rebuild after the $SUBCMD reported an issue; run: php artisan rsx:manifest:build"
    fi
}

# =============================================================================
# PHASE 0 - RECONCILE THE FRAMEWORK RELEASE BEFORE THE MERGE, WHOLESALE
# =============================================================================
# system/ is a vendored tree written by ONE writer (rsx:framework:pull). Which release
# it should hold is therefore not a per-file question a three-way merge can answer - it
# is one fact, stamped in system/.rspade-release.json, and the merge machinery has no
# business voting on it. Before the operation runs we read that marker on BOTH refs and
# decide which side's system/ tree is THE framework; afterwards that side's tree is
# restated wholesale (restate_system_tree) over whatever the merge produced.
#
# This replaces the old per-conflict "resolve to the incoming side" policy, its
# delete/modify keep-local patch and the rebase --ours/--theirs inversion - all three
# were attempts to answer a whole-tree question one file at a time. They could not see
# the shape that actually loses code: a NON-conflicting incoming deletion, which git
# applies silently and no conflict resolver is ever invoked for (field report, 2026-08-11 and
# again 2026-08-18, the second time moving a box back a release and breaking it).
#
# Reading a marker per-ref costs one `git show` + one `sed`. No checkout, no php.
# ISO-8601 UTC dates compare lexically, so the decision is a string comparison.
RECON_ACTIVE=false          # true once a winner is decided (drives the restate)
RECON_WINNER=""             # resolved commit whose system/ tree is the framework
RECON_WINNER_IS_LOCAL=false
RECON_INCOMING_REF=""       # resolved commit of the side being merged in
RECON_INCOMING_LABEL=""     # how to NAME that side to a human
RECON_LOCAL_ID="";    RECON_LOCAL_DATE=""
RECON_INCOMING_ID=""; RECON_INCOMING_DATE=""

# marker_field <ref> <field> - one field out of that ref's release marker, or "".
marker_field() {
    "${ROOT_GIT_RO[@]}" show "$1:system/.rspade-release.json" 2>/dev/null \
        | sed -n 's/.*"'"$2"'"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1
}

# The commit this pull/merge is about to bring in, resolved to a sha. For `merge` that
# is the first positional argument (or the upstream branch); for `pull` it is what the
# fetch just wrote to FETCH_HEAD - so the fetch is issued HERE, with the pull's own
# positional arguments, rather than guessed at.
resolve_incoming_ref() {
    local ref="" label=""

    case "$SUBCMD" in
        merge)
            label="$(positional_sub_args | head -n 1)"
            [ -n "$label" ] || label='@{u}'
            ref="$("${ROOT_GIT_RO[@]}" rev-parse -q --verify "$label^{commit}" 2>/dev/null || true)"
            ;;
        pull)
            local positional=()
            mapfile -t positional < <(positional_sub_args)
            git_retry "${ROOT_GIT[@]}" fetch ${positional[@]+"${positional[@]}"} >/dev/null 2>&1 || true
            ref="$("${ROOT_GIT_RO[@]}" rev-parse -q --verify 'FETCH_HEAD^{commit}' 2>/dev/null || true)"
            label="$("${ROOT_GIT_RO[@]}" rev-parse -q --abbrev-ref '@{u}' 2>/dev/null || true)"
            [ -n "$label" ] || label="FETCH_HEAD"
            ;;
    esac

    [ -n "$ref" ] || return 1
    RECON_INCOMING_REF="$ref"
    RECON_INCOMING_LABEL="$label"

    return 0
}

# days_between <iso-a> <iso-b> - whole days, or "" when the dates cannot be parsed.
days_between() {
    local a b
    a="$(date -u -d "$1" +%s 2>/dev/null)" || return 0
    b="$(date -u -d "$2" +%s 2>/dev/null)" || return 0
    [ -n "$a" ] && [ -n "$b" ] || return 0
    local diff=$(( (b - a) / 86400 ))
    [ "$diff" -lt 0 ] && diff=$(( -diff ))
    printf '%s' "$diff"
}

# reconcile_releases - decide the winner. rc 1 means REFUSE: the caller aborts the whole
# operation without touching the tree.
reconcile_releases() {
    case "$SUBCMD" in
        pull|merge) : ;;
        *) return 0 ;;
    esac

    # A control verb (`merge --abort`, `--continue`, `--quit`) merges nothing: there is no
    # incoming tree to decide about, and appending --no-commit to one is a git usage error.
    local a
    for a in ${SUB_ARGS[@]+"${SUB_ARGS[@]}"}; do
        case "$a" in
            --abort|--continue|--quit|--skip) return 0 ;;
        esac
    done

    local local_ref
    local_ref="$("${ROOT_GIT_RO[@]}" rev-parse -q --verify HEAD 2>/dev/null || true)"
    if [ -z "$local_ref" ] || ! resolve_incoming_ref; then
        # Nothing to compare against (a fresh repo, an unresolvable ref). The operation
        # runs exactly as plain git; there is no framework tree decision to make.
        return 0
    fi

    RECON_LOCAL_ID="$(marker_field "$local_ref" release_id)"
    RECON_LOCAL_DATE="$(marker_field "$local_ref" date)"
    RECON_INCOMING_ID="$(marker_field "$RECON_INCOMING_REF" release_id)"
    RECON_INCOMING_DATE="$(marker_field "$RECON_INCOMING_REF" date)"

    # NEVER A SILENT GUESS. A missing or unparseable marker on either side means this
    # tree cannot be decided, so the operation proceeds as plain git and SAYS SO - the
    # operator is the only one who can tell a pre-vendoring tree from a damaged one.
    if [ -z "$RECON_LOCAL_ID" ] || [ -z "$RECON_INCOMING_ID" ] \
        || [ -z "$RECON_LOCAL_DATE" ] || [ -z "$RECON_INCOMING_DATE" ]; then
        warn "system/.rspade-release.json is missing or unparseable on $([ -z "$RECON_LOCAL_ID$RECON_LOCAL_DATE" ] && printf 'this side' || printf 'the incoming side')."
        err  "          The framework release cannot be reconciled, so this $SUBCMD runs as a plain"
        err  "          git merge: whatever it does to system/ is what you get, conflicts included."

        return 0
    fi

    # Same release on both sides: no release decision to make. The restate still runs
    # (it is what erases a peer's stray deletions under an unchanged marker) and says
    # nothing - a routine merge is not an event.
    if [ "$RECON_LOCAL_ID" = "$RECON_INCOMING_ID" ]; then
        RECON_ACTIVE=true
        RECON_WINNER="$local_ref"
        RECON_WINNER_IS_LOCAL=true

        return 0
    fi

    # TWO RELEASES, ONE INSTANT. Nothing in the data can order them and the wrong guess
    # loses a release, so the machine does not guess: it stops, having changed nothing.
    if [ "$RECON_LOCAL_DATE" = "$RECON_INCOMING_DATE" ]; then
        err ""
        err "[ERROR] REFUSING this $SUBCMD: two DIFFERENT framework releases carry the SAME date."
        err "        here            $RECON_LOCAL_ID ($RECON_LOCAL_DATE)"
        err "        $RECON_INCOMING_LABEL  $RECON_INCOMING_ID ($RECON_INCOMING_DATE)"
        err ""
        err "        Nothing can order these two, and adopting the wrong one loses a release."
        err "        Nothing in this working tree was changed."
        err "        Decide by hand which release this box should carry, then either pull the"
        err "        framework (php artisan rsx:framework:pull) or merge with --rsx-no-maint"
        err "        once the two sides agree."

        return 1
    fi

    if [ "$RECON_INCOMING_DATE" \> "$RECON_LOCAL_DATE" ]; then
        RECON_ACTIVE=true
        RECON_WINNER="$RECON_INCOMING_REF"
        RECON_WINNER_IS_LOCAL=false
        note "Framework release: $RECON_INCOMING_LABEL carries $RECON_INCOMING_ID ($RECON_INCOMING_DATE),"
        note "  newer than this box's $RECON_LOCAL_ID ($RECON_LOCAL_DATE) - its system/ tree is adopted whole."

        return 0
    fi

    # LOCAL WINS. The incoming side is a stale box re-sending an old framework tree, and
    # it will keep doing it until somebody pulls there - so name it.
    RECON_ACTIVE=true
    RECON_WINNER="$local_ref"
    RECON_WINNER_IS_LOCAL=true

    local behind committer
    behind="$(days_between "$RECON_INCOMING_DATE" "$RECON_LOCAL_DATE")"
    committer="$("${ROOT_GIT_RO[@]}" log -1 --format='%cn <%ce>' "$RECON_INCOMING_REF" -- system 2>/dev/null || true)"
    [ -n "$committer" ] || committer="unknown"

    note "$RECON_INCOMING_LABEL carries release $RECON_INCOMING_ID ($RECON_INCOMING_DATE)${behind:+, $behind days behind};"
    note "  last written by $committer - that box needs a pull or it will keep re-sending this."
    note "  This box keeps its own framework ($RECON_LOCAL_ID); only its system/ tree is discarded."

    return 0
}

# restate_system_tree <dir> - replace system/ with the WINNER's tree, entire.
#
# Runs between the merge (--no-commit) and the commit, so the merge commit itself
# carries one side's framework tree rather than a per-file blend of two. Conflicts under
# system/ need no separate handling: `rm -r --cached` drops every stage of an unmerged
# entry, and the checkout that follows puts the winner's content back.
#
# `clean` deliberately carries NO -x. Gitignored state under system/ - the .env
# deployment symlink, build and vendor caches - is not part of the release and is not
# ours to delete; the same line rsx:clean and reset_system_tree draw.
#
# rsx/resource/framework_update_history.dat travels WITH the tree. It is an app-tree
# file, so it merges on its own and can disagree with the marker it is supposed to
# describe - which is precisely how a box ended up repairing itself to a stale release
# (field report, 2026-08-18). It is a LOG; the tree it logs decides its content.
restate_system_tree() {
    local dir="${1:-$PROJECT_ROOT}"
    [ "$RECON_ACTIVE" = true ] || return 0
    [ -n "$RECON_WINNER" ] || return 0

    # rm --cached may find nothing staged (fine); the checkout and add are LOAD-BEARING -
    # a failure there means the commit would record a half-restated tree, so it aborts
    # the operation instead (the merge stays open, maintenance stays up, the operator
    # resolves - the same posture as an app conflict).
    dgit "$dir" rm -r -q --cached -- system >/dev/null 2>&1 || true
    if ! dgit "$dir" checkout "$RECON_WINNER" -- system 2>&1; then
        err "[ERROR] Failed to restate system/ from the winning release ($RECON_WINNER)."
        err "        The merge is left OPEN and uncommitted - do not commit until system/"
        err "        matches one release. Inspect with: git status -- system"
        return 1
    fi
    dgit "$dir" clean -fdq -- system >/dev/null 2>&1 || true
    if ! git_retry dgit "$dir" add -A -- system >/dev/null 2>&1; then
        err "[ERROR] Failed to stage the restated system/ tree. The merge is left OPEN."
        return 1
    fi

    local hist="rsx/resource/framework_update_history.dat"
    if dgit_ro "$dir" cat-file -e "$RECON_WINNER:$hist" 2>/dev/null; then
        dgit "$dir" checkout "$RECON_WINNER" -- "$hist" >/dev/null 2>&1 || true
        git_retry dgit "$dir" add -- "$hist" >/dev/null 2>&1 || true
    fi

    return 0
}

# commit_restate_if_needed - commit the restate when the operation itself did not.
#
# A fast-forward pull and a rebase both leave no merge to commit into, so the restated
# system/ would otherwise sit staged-but-uncommitted - exactly the "synced but not in
# history" state the framework updater refuses to leave behind. Committed the same way
# the updater commits system/: RSPADE_FRAMEWORK_COMMIT=1 + --no-verify, since the
# downstream pre-commit hook exists to keep system/ out of APP commits and this is not
# one.
commit_restate_if_needed() {
    local dir="${1:-$PROJECT_ROOT}"
    [ "$RECON_ACTIVE" = true ] || return 0
    dgit_ro "$dir" diff --cached --quiet -- system rsx/resource/framework_update_history.dat 2>/dev/null && return 0

    local id="$RECON_LOCAL_ID"
    [ "$RECON_WINNER_IS_LOCAL" = true ] || id="$RECON_INCOMING_ID"

    if RSPADE_FRAMEWORK_COMMIT=1 git_retry dgit "$dir" commit --no-verify -q \
        -m "Framework release $id: system/ restated after $SUBCMD" -- system rsx/resource/framework_update_history.dat >/dev/null 2>&1; then
        note "system/ restated to release $id and committed."
    else
        warn "system/ was restated to release $id but could not be committed - it is staged."
    fi

    return 0
}

# assert_release_not_regressed <pre-op HEAD>
#
# THE ONE THING A PULL CHECKS ABOUT system/. The framework SYNCS system/: a pull pulls
# in framework changes, and files appearing, changing and disappearing is what that
# looks like. Reporting on those changes is a monitor, not a protection - and a monitor
# that speaks on the routine case is one nobody reads. The protection is on the WRITE
# side, where it belongs: the updater's inventory-asserted commit, run_commit's refusal
# to commit staged system/ paths, and the pre-commit hook. `rsx:framework:verify` is the
# on-demand integrity audit for when an operator actually wants one.
#
# What survives here is not a monitor but an INVARIANT: a merge may never move the
# INSTALLED RELEASE BACKWARDS. That is the 2026-08-11 field loss - an environment a
# release behind merges into a current one, git has no conflict to report because the
# files were untouched since the merge base, the merge lands clean, and the older tree
# propagates fleet-wide. It is a loss whether or not a single file was deleted, so it is
# decided on the marker ALONE and no file list is consulted at any point.
#
# SINCE 2026-08-18 THIS IS A POST-CONDITION, NOT A DEFENSE. Phase 0 decides the winning
# release BEFORE the merge and restate_system_tree installs that side's tree wholesale,
# so a regression is now unreachable by construction. What fires here is therefore no
# longer "a peer sent an old tree" - it is "the reconciliation itself was wrong", which
# is a framework bug and wants reporting as loudly as the loss it replaced. It stays
# cheap (two marker reads) and it stays on, exactly because nothing should ever trip it.
#
# Both inputs are free: the PRE-op inventory is `show <pre>:system/.rspade-release.json`
# and the POST-op one is the file on disk. Same release_id, or an ordering the ISO-8601
# UTC `date` fields cannot decide, is not a regression - only a demonstrably OLDER
# marker is. An unreadable inventory on either side (pre-vendoring, a fresh tree, no
# php) says nothing at all: this is an invariant check, not an audit.
assert_release_not_regressed() {
    local pre="$1"
    [ -n "$pre" ] || return 0
    command -v php >/dev/null 2>&1 || return 0

    local pre_json verdict
    pre_json="$(mktemp)"
    "${ROOT_GIT_RO[@]}" show "$pre:system/.rspade-release.json" > "$pre_json" 2>/dev/null || : > "$pre_json"

    verdict="$(php -r '
        $read = function ($p) {
            $raw = @file_get_contents($p);
            if ($raw === false || $raw === "") { return null; }
            $m = json_decode($raw, true);
            if (!is_array($m) || ($m["release_id"] ?? "") === "") { return null; }
            return $m;
        };
        $pre  = $read($argv[1]);
        $post = $read($argv[2]);
        if ($pre === null || $post === null) { exit(0); }

        $pre_dt  = (string) ($pre["date"] ?? "");
        $post_dt = (string) ($post["date"] ?? "");
        if ($pre["release_id"] === $post["release_id"]) { exit(0); }
        if ($pre_dt === "" || $post_dt === "" || strcmp($post_dt, $pre_dt) >= 0) { exit(0); }

        echo $pre["release_id"], " ", $pre_dt, " ", $post["release_id"], " ", $post_dt, "\n";
    ' "$pre_json" "$SYSTEM_DIR/.rspade-release.json" 2>/dev/null)"

    rm -f "$pre_json"
    [ -n "$verdict" ] || return 0

    local pre_id pre_dt post_id post_dt
    read -r pre_id pre_dt post_id post_dt <<< "$verdict"

    err ""
    err "[ERROR] This $SUBCMD moved system/ BACKWARDS: release $pre_id ($pre_dt)"
    err "        -> $post_id ($post_dt)."
    err "        The other side is a release BEHIND and its system/ tree has landed here."
    err "        This is the shape that loses framework code silently and then spreads it"
    err "        through shared history (field report, 2026-08-11) - it is a loss whether or not"
    err "        any file was deleted in this operation."
    err ""
    err "        Restore the current release:  php artisan rsx:framework:pull"
    err "        Then find the box that pushed the older release - it will keep arriving."
}

# The conflicts a HUMAN has to resolve.
#
# When Phase 0 decided a winner, system/ is not one of them: restate_system_tree replaces
# that subtree wholesale, conflicts and all, so any unmerged path under it is already
# gone by the time this runs and the filter is only belt-and-braces. When it did NOT
# decide (no readable marker on one side), system/ conflicts are ORDINARY conflicts and
# reach the operator with everything else - the old "resolve to the incoming side"
# default is exactly the guess this reconciliation exists to stop making.
app_conflicts() {
    local dir="${1:-$PROJECT_ROOT}"

    if [ "$RECON_ACTIVE" = true ]; then
        dgit_ro "$dir" diff --name-only --diff-filter=U 2>/dev/null | grep -v '^system/' || true
    else
        dgit_ro "$dir" diff --name-only --diff-filter=U 2>/dev/null || true
    fi
}

# Finish the operation whose only conflicts were in system/. Each op has its own
# continuation verb; GIT_EDITOR=true accepts the default message without a terminal.
continue_operation() {
    local dir="${1:-$PROJECT_ROOT}" git_dir
    git_dir="$(git_dir_abs "$dir")" || return 1

    if [ -d "$git_dir/rebase-merge" ] || [ -d "$git_dir/rebase-apply" ]; then
        GIT_EDITOR=true git_retry dgit "$dir" rebase --continue

        return $?
    fi

    if [ -f "$git_dir/CHERRY_PICK_HEAD" ]; then
        GIT_EDITOR=true git_retry dgit "$dir" cherry-pick --continue

        return $?
    fi

    if [ -f "$git_dir/REVERT_HEAD" ]; then
        GIT_EDITOR=true git_retry dgit "$dir" revert --continue

        return $?
    fi

    if [ -f "$git_dir/MERGE_HEAD" ]; then
        GIT_EDITOR=true git_retry dgit "$dir" commit --no-edit

        return $?
    fi

    return 1
}

# Deliberately NOT parameterized by directory: every caller means "has git left work in
# progress in the tree the user is operating on", which is always the project root.
operation_in_progress() {
    local git_dir
    git_dir="$(git_dir_abs)" || return 1

    [ -d "$git_dir/rebase-merge" ] || [ -d "$git_dir/rebase-apply" ] \
        || [ -f "$git_dir/CHERRY_PICK_HEAD" ] || [ -f "$git_dir/REVERT_HEAD" ] \
        || [ -f "$git_dir/MERGE_HEAD" ]
}

# =============================================================================
# The scratch-worktree escape
# =============================================================================
# The POSITIONAL arguments after the subcommand, one per line: everything that is not a
# flag and not the VALUE of a flag known to consume one (so `merge -m "message" ref`
# yields `ref`, never the message).
positional_sub_args() {
    local a skip_next=false

    for a in ${SUB_ARGS[@]+"${SUB_ARGS[@]}"}; do
        if [ "$skip_next" = true ]; then
            skip_next=false
            continue
        fi

        case "$a" in
            -m|-F|--file|-s|--strategy|-X|--strategy-option|-S|--gpg-sign|--into-name|--depth|--deepen|--upload-pack|-o|--server-option)
                skip_next=true ;;
            --) : ;;
            -*) : ;;
            *)  printf '%s\n' "$a" ;;
        esac
    done
}

# Removes the temporary worktree and its branch. Called explicitly on EVERY exit path of
# scratch_merge_escape - deliberately NOT a second EXIT trap, because maint_cleanup owns
# EXIT and a second handler would replace it.
scratch_cleanup() {
    local dir="$1" branch="$2"

    [ -n "$dir" ] && "${ROOT_GIT[@]}" worktree remove --force "$dir" >/dev/null 2>&1 || true
    [ -n "$branch" ] && "${ROOT_GIT[@]}" branch -D "$branch" >/dev/null 2>&1 || true
    [ -n "$dir" ] && rm -rf "$dir" 2>/dev/null || true

    return 0
}

# Merge somewhere the merge can actually run, then fast-forward the result in.
#
# Engaged only when the caller's own pull/merge died with `fatal: stash failed` and NO
# index.lock existed - a deterministic disagreement between git's pre-merge save_state()
# and its child `git stash create` about the state of this tree's index. Retrying that is
# pure delay; the tree needs a different route, not another attempt.
#
# A linked worktree has its OWN index and checks out no submodules, so the merge runs
# there unaffected by whatever the main index believes. Bringing the result home is a
# `merge --ff-only`, which takes git's checkout_fast_forward path and never calls
# save_state at all - so it lands even while the main tree is still dirty. Its one honest
# refusal is a local modification to a file the incoming change touches, which is the
# single case where "commit or stash first" is true advice.
scratch_merge_escape() {
    local ref=""

    case "$SUBCMD" in
        merge)
            # The first POSITIONAL argument is the ref. A bare `git merge` means the
            # upstream branch, which is what @{u} names.
            ref="$(positional_sub_args | head -n 1)"
            if [ -z "$ref" ]; then
                ref="$("${ROOT_GIT_RO[@]}" rev-parse -q --verify '@{u}' 2>/dev/null || true)"
            fi
            ;;
        pull)
            # `git pull` is fetch + merge and save_state runs in the MERGE half, so
            # FETCH_HEAD normally already names exactly what this pull was about to merge.
            # Re-issue the fetch anyway, with the pull's own positional arguments (pull's
            # <repository> <refspec...> ARE fetch's), so the escape can never merge a
            # FETCH_HEAD left behind by some older fetch. A failing fetch is not fatal
            # here: whatever the pull itself fetched is still on disk.
            local positional=()
            mapfile -t positional < <(positional_sub_args)
            git_retry "${ROOT_GIT[@]}" fetch ${positional[@]+"${positional[@]}"} >/dev/null 2>&1 || true

            ref="$("${ROOT_GIT_RO[@]}" rev-parse -q --verify FETCH_HEAD 2>/dev/null || true)"
            ;;
    esac

    if [ -z "$ref" ] || ! "${ROOT_GIT_RO[@]}" rev-parse -q --verify "$ref^{commit}" >/dev/null 2>&1; then
        err "[rsx:git] This tree cannot run a direct $SUBCMD (see below), and the commit to"
        err "          merge could not be identified, so the scratch-worktree escape was"
        err "          not attempted. Name it explicitly: php artisan rsx:git merge <ref>"

        return 1
    fi

    # OBSERVED FACT, not a guess: dirt that `status` does not show but the merge machinery
    # may still count. On some git versions a dirty submodule worktree is invisible to
    # `git stash create` while merge's save_state() still refuses to proceed without it.
    # Untracked files are excluded from the "clean" test - they are never what stash saves.
    local dirt
    dirt="$("${ROOT_GIT_RO[@]}" diff-index HEAD -- 2>/dev/null)"
    if [ -n "$dirt" ] \
        && [ -z "$("${ROOT_GIT_RO[@]}" status --porcelain --untracked-files=no 2>/dev/null | head -n 1)" ] \
        && ! printf '%s\n' "$dirt" | grep -qv '^:160000'; then
        note "dirty submodule worktree(s) merge may count but stash cannot save: $(printf '%s\n' "$dirt" | sed -n 's/^.*\t//p' | tr '\n' ' ')"
    fi

    note "Direct $SUBCMD cannot run on this tree (git's index/stash disagreement is"
    note "  deterministic here). Merging in a clean scratch worktree instead."

    local scratch_dir tmp_branch
    scratch_dir="$(mktemp -d)"
    tmp_branch="rsx-git-escape-$$"

    if ! git_retry "${ROOT_GIT[@]}" worktree add "$scratch_dir" -b "$tmp_branch" HEAD; then
        err "[rsx:git] Could not create the scratch worktree - the escape is unavailable."
        scratch_cleanup "$scratch_dir" "$tmp_branch"

        return 1
    fi

    # THE SAME RULE AS THE DIRECT PATH. The escape is not a lesser route: the 2026-08-18
    # backwards merge came through here, so the merge is held open with --no-commit and
    # the winner's system/ tree is restated into it before it is committed.
    local merge_out merge_rc
    local -a merge_flags=(--no-edit)
    [ "$RECON_ACTIVE" = true ] && merge_flags+=(--no-commit)
    merge_out="$(dgit "$scratch_dir" merge "${merge_flags[@]}" "$ref" 2>&1)"
    merge_rc=$?

    restate_system_tree "$scratch_dir" || return 1

    local remaining
    remaining="$(app_conflicts "$scratch_dir")"

    if [ -n "$remaining" ]; then
        err ""
        err "[ERROR] APP FILES CONFLICT - and this tree cannot run a direct $SUBCMD."
        err "        The merge was attempted in a scratch worktree instead, and it"
        err "        conflicts in files this proxy must not resolve for you."
        err "        Nothing in this working tree was changed."
        err ""
        err "        Conflicted:"
        printf '%s\n' "$remaining" | sed 's/^/          /' >&2
        err ""
        err "        Resolve them against the incoming commit ($ref) by hand."
        scratch_cleanup "$scratch_dir" "$tmp_branch"

        return 1
    fi

    # --no-commit leaves a SUCCESSFUL merge uncommitted too, so this is not only the
    # conflict path: finish whatever git left in progress, then commit a restate that
    # rode in on a fast-forward (where there is no merge to commit into).
    if dgit_ro "$scratch_dir" rev-parse -q --verify MERGE_HEAD >/dev/null 2>&1; then
        if ! continue_operation "$scratch_dir" >/dev/null 2>&1; then
            err "[rsx:git] The scratch-worktree merge could not be completed:"
            [ -n "$merge_out" ] && printf '%s\n' "$merge_out" | sed 's/^/          /' >&2
            scratch_cleanup "$scratch_dir" "$tmp_branch"

            return 1
        fi
        note "Scratch merge completed with system/ restated to the winning release."
    elif [ "$merge_rc" -ne 0 ]; then
        err "[rsx:git] The scratch-worktree merge could not be completed:"
        [ -n "$merge_out" ] && printf '%s\n' "$merge_out" | sed 's/^/          /' >&2
        scratch_cleanup "$scratch_dir" "$tmp_branch"

        return 1
    else
        commit_restate_if_needed "$scratch_dir"
    fi

    # Home. --ff-only takes checkout_fast_forward and never stashes, so a dirty tree is
    # not in its way - only a local modification to a file this change touches is.
    local ff_out ff_rc
    ff_out="$(git_retry "${ROOT_GIT[@]}" merge --ff-only "$tmp_branch" 2>&1)"
    ff_rc=$?
    [ -n "$ff_out" ] && printf '%s\n' "$ff_out" >&2

    if [ "$ff_rc" -ne 0 ]; then
        case "$ff_out" in
            *"local changes"*|*"would be overwritten"*)
                err ""
                err "[ERROR] The merge itself succeeded in a scratch worktree, but it cannot be"
                err "        applied here: the file(s) git named just above carry local"
                err "        modifications AND the incoming change touches them."
                err ""
                err "        This is the one case where 'commit or stash first' is true advice:"
                err "        commit or stash THOSE files, then run this command again."
                ;;
            *)
                err "[rsx:git] The scratch merge succeeded but could not be fast-forwarded into"
                err "          this tree. Nothing was changed here."
                ;;
        esac
        scratch_cleanup "$scratch_dir" "$tmp_branch"

        return 1
    fi

    note "$SUBCMD completed via a scratch worktree (merged there, fast-forwarded here)."
    scratch_cleanup "$scratch_dir" "$tmp_branch"

    return 0
}

run_maintenance_op() {
    trap maint_cleanup EXIT INT TERM

    maint_enable

    if ! reset_system_tree; then
        # The integrity gate refused. Nothing was rewritten; leave without running
        # the op at all (the trap restores services).
        return 1
    fi

    # The proxy's own reads run --no-optional-locks (deliberately - see ROOT_GIT_RO), which
    # means NOTHING refreshes the index anymore on a box whose ctimes churn (fixperms
    # loops). merge's save_state() and its child `git stash create` then disagree about a
    # stat-stale index under lock contention ('fatal: stash failed'), and on some git
    # versions a stale index alone kills the merge. Refresh ONCE, deliberately, on the
    # tree-rewriting path only (t14 pins that plain reads never write the index). rc=1 just
    # means real modifications exist - the refresh of the clean entries still happened - so
    # the exit code is deliberately ignored.
    "${ROOT_GIT[@]}" update-index --refresh -q >/dev/null 2>&1 || true

    # Remember where we were, so the installed release can be compared across the
    # operation afterwards. See assert_release_not_regressed - the loss that motivated it
    # arrives through a clean merge, not a conflict, so nothing else ever sees it.
    local pre_head
    pre_head="$("${ROOT_GIT_RO[@]}" rev-parse HEAD 2>/dev/null || true)"

    # PHASE 0 - decide the framework release BEFORE anything is merged. A refusal here
    # has changed nothing, so the operation simply does not run (the trap restores
    # services). See reconcile_releases.
    if ! reconcile_releases; then
        return 1
    fi

    # Hold the merge OPEN so the winner's system/ tree can be restated into it before it
    # becomes a commit. `pull --rebase` ignores --no-commit (git accepts it and rebases
    # anyway); that path is covered by commit_restate_if_needed below, which commits the
    # restate on its own when the operation left no merge to commit into.
    if [ "$RECON_ACTIVE" = true ]; then
        ARGS+=(--no-commit)
    fi

    # THE USER'S OWN OPERATION, with the ONE idempotent retry.
    #
    # A non-fast-forward merge over a tracked modified file makes git run its internal
    # `git stash create` (builtin/merge.c save_state()). If a concurrent process holds
    # .git/index.lock that child fails silently and the merge dies with the generic
    # `fatal: stash failed` having done NOTHING - no MERGE_HEAD, HEAD unmoved. Re-issuing
    # is therefore safe, and gated twice: the failure TEXT must match, and git must have
    # left no operation in progress. A genuine conflict fails on attempt one.
    #
    # CLASSIFY ON EVIDENCE, NEVER ON WORDING ALONE. `stash failed` also has a
    # DETERMINISTIC shape with no lock anywhere near it (git's merge machinery and its
    # child `git stash create` disagreeing about this tree's index), and retrying that
    # just spends five backoffs to fail identically - which is exactly what a downstream
    # box reported (field report, 2026-08-12: no lock file, no concurrent git, clean status).
    # So: an index.lock-shaped wording is contention; `stash failed` is contention only
    # while the lock file actually EXISTS, and otherwise routes to the escape below.
    #
    # stdout stays wired straight to the terminal (fd 3), so an editor and git's own TTY
    # detection behave exactly as they do under raw git. stderr is tee'd so it stays
    # visible AND classifiable. DELIBERATE TRADE-OFF: stderr is a pipe rather than a tty
    # for the duration, so git suppresses its progress meters on these ops.
    local rc=0 attempt=1 delay err_capture kind
    local stash_race=false stash_deterministic=false lock_evidence=false
    err_capture="$(mktemp)"

    while :; do
        stash_race=false
        { git "${ARGS[@]}" 2>&1 1>&3 | tee -- "$err_capture" >&2; rc=${PIPESTATUS[0]}; } 3>&1
        [ "$rc" -eq 0 ] && break

        case "$(cat "$err_capture")" in
            # Lock contention, stated by git itself - the evidence is in the wording.
            # `Unable to write index` is the same family: a refreshed in-process index
            # that cannot be written back because the lock is taken.
            *index.lock*|*"Unable to write index"*)
                kind=lock ;;
            *"stash failed"*)
                # The generic wording. Contention ONLY if the lock is there right now.
                if [ -e "$(git_dir_abs)/index.lock" ]; then
                    kind=lock
                else
                    kind=deterministic
                fi
                ;;
            *)
                kind=other ;;
        esac

        # An operation left in progress means git DID work - not the pre-work failure,
        # not retryable, and not what either hint describes.
        [ "$kind" = other ] && break
        operation_in_progress && break

        if [ "$kind" = deterministic ]; then
            stash_deterministic=true
            break
        fi

        stash_race=true
        lock_evidence=true
        if [ "$attempt" -ge "$LOCK_RETRIES" ]; then
            break
        fi
        delay="0.$((attempt * 2))"
        note "index.lock contention aborted $SUBCMD before it started; retrying in ${delay}s (attempt $attempt/$LOCK_RETRIES)."
        sleep "$delay"
        attempt=$((attempt + 1))
    done

    rm -f "$err_capture"

    # The direct op is deterministically unable to run here. For a merge or a pull there
    # is another route: do the merge in a scratch worktree and fast-forward it home.
    if [ "$rc" -ne 0 ] && [ "$stash_deterministic" = true ] && ! operation_in_progress; then
        case "$SUBCMD" in
            pull|merge) scratch_merge_escape && rc=0 ;;
        esac
    fi

    # THE RESTATE. Whatever the merge made of system/ - a blend, a conflict, a silently
    # applied deletion - is discarded and replaced by the winning side's tree, entire.
    restate_system_tree || return 1

    local remaining
    remaining="$(app_conflicts)"

    # The operation is not finished: either it stopped on conflicts that the restate has
    # now cleared, or --no-commit held a perfectly good merge open on purpose. Both end
    # the same way - commit it.
    if [ -z "$remaining" ] && operation_in_progress; then
        if continue_operation >/dev/null 2>&1; then
            [ "$rc" -eq 0 ] || note "$SUBCMD completed with system/ restated to the winning release."
            rc=0
        elif [ "$rc" -eq 0 ]; then
            rc=1
        fi
    fi

    # A fast-forward (and a rebase) leaves no merge to commit into, so a restate that
    # actually changed something is committed on its own.
    if [ "$rc" -eq 0 ] && [ -z "$remaining" ] && ! operation_in_progress; then
        commit_restate_if_needed
    fi

    assert_release_not_regressed "$pre_head"

    rebuild_system_tree

    if [ -n "$remaining" ]; then
        MAINT_HOLD=true
        err ""
        err "[ERROR] APP FILES CONFLICT - stopping here with maintenance mode STILL ON."
        err "        Services are down deliberately, so this merge can be resolved without traffic."
        err ""
        err "        Conflicted:"
        printf '%s\n' "$remaining" | sed 's/^/          /' >&2
        err ""
        err "        Resolve them, then:  php artisan rsx:git commit"
        err "        Services return when it lands - rsx:maintenance:disable refuses while conflicted."

        return 1
    fi

    if [ "$rc" -ne 0 ]; then
        if [ "$stash_deterministic" = true ]; then
            stash_deterministic_hint
        elif [ "$lock_evidence" = true ] || [ "$stash_race" = true ]; then
            stash_contention_hint
        else
            lock_hint_if_relevant
        fi
    fi

    # Advisory: a pulled/merged tree may carry migrations the app has not run. The same
    # notice the framework updater prints at the end of a pull - one line naming the
    # pending count and the command to run, silent when the schema is current, and never
    # a failure of the git operation. Maintenance mode may still be up here, hence the
    # override token (the child boots under the gate; the command itself is allowed, the
    # flag just keeps this identical to the updater's invocation).
    if [ "$rc" -eq 0 ]; then
        php "$SYSTEM_DIR/artisan" migrate:status_notice --_framework-update-override 2>/dev/null || true
    fi

    return "$rc"
}

# =============================================================================
# Class: pathspec exclusion (status / diff / add)
# =============================================================================
run_excluded_op() {
    build_excluded_tail

    # status/diff are READS and must not refresh the index (see ROOT_GIT_RO). `add` is a
    # write and is deliberately left alone. The flag is GLOBAL, so it goes before ARGS -
    # which carries the subcommand.
    local ro=()
    case "$SUBCMD" in
        status|diff) ro=(--no-optional-locks) ;;
    esac

    git ${ro[@]+"${ro[@]}"} "${ARGS[@]}" "${EXCL_TAIL[@]}"
    local rc=$?

    if [ "$SUBCMD" = "status" ] && [ "$QUIET" = false ]; then
        local hidden
        hidden="$(hidden_path_count)"
        if [ "${hidden:-0}" -gt 0 ]; then
            note "$hidden framework path(s) hidden (system/ + submodules). Show them: --rsx-raw"
        fi
    fi

    return "$rc"
}

# =============================================================================
# Class: commit
# =============================================================================

# Short flags that consume the rest of their cluster as a value. An `a` appearing
# after one of these is part of that VALUE (`-madd`), not the --all flag.
COMMIT_VALUE_FLAGS='mcCFSu'

# Strip the --all/-a request out of a short-flag cluster, returning what remains.
# Prints nothing when the cluster was exactly "-a".
strip_all_from_cluster() {
    local cluster="${1#-}" out="" ch i

    for (( i = 0; i < ${#cluster}; i++ )); do
        ch="${cluster:$i:1}"

        if [[ "$COMMIT_VALUE_FLAGS" == *"$ch"* ]]; then
            out+="${cluster:$i}"          # this flag owns the remainder
            break
        fi

        [ "$ch" = "a" ] && continue
        out+="$ch"
    done

    [ -n "$out" ] && printf '%s' "-$out"
}

# Does this short-flag cluster actually request --all?
cluster_requests_all() {
    local cluster="${1#-}" ch i

    for (( i = 0; i < ${#cluster}; i++ )); do
        ch="${cluster:$i:1}"
        [[ "$COMMIT_VALUE_FLAGS" == *"$ch"* ]] && return 1
        [ "$ch" = "a" ] && return 0
    done

    return 1
}

run_commit() {
    build_excludes

    # Whatever staged it, system/ does not enter an app commit. (The downstream
    # pre-commit hook is the second line of defense; this is the first.)
    local staged
    staged="$("${ROOT_GIT_RO[@]}" diff --cached --name-only -- system 2>/dev/null | grep -c . || true)"
    if [ "${staged:-0}" -gt 0 ]; then
        # git_retry captures the command's own output already; redirecting here would
        # also swallow its index.lock retry notices.
        if git_retry "${ROOT_GIT[@]}" reset -q -- system; then
            note "$staged staged system/ path(s) unstaged - system/ is committed only by rsx:framework:pull."
        else
            # Never claim an unstage that did not happen - and never proceed past it.
            # This is the FIRST line of defense for the one-writer invariant (system/
            # enters git through rsx:framework:pull's own commit and nothing else), and
            # a warn-and-continue turned it into a suggestion: the commit went ahead
            # carrying framework churn whenever the unstage lost an index.lock race.
            # The pre-commit hook is a backstop, not a licence to continue. Aborting
            # costs the operator one retry; the alternative costs the fleet a
            # contaminated system/ tree (2026-08-18).
            err "[ERROR] Could not unstage $staged system/ path(s) - refusing to commit."
            err "        system/ is committed only by rsx:framework:pull, so this commit"
            err "        would carry framework churn. Nothing was committed."
            err "        Usually index.lock contention: retry the same command."
            err "        Inspect:  git diff --cached --name-only -- system"

            return 1
        fi
    fi

    # `commit -a` cannot simply receive a pathspec: pathspecs on commit mean "commit
    # ONLY these paths, bypassing the index", which is a different operation. So -a is
    # REWRITTEN into the equivalent excluding `add -u` plus a plain commit.
    local rewritten=() had_all=false a stripped
    for a in "${ARGS[@]}"; do
        case "$a" in
            --all)
                had_all=true
                continue
                ;;
            --*)
                : ;;
            -?*)
                if cluster_requests_all "$a"; then
                    had_all=true
                    stripped="$(strip_all_from_cluster "$a")"
                    [ -n "$stripped" ] || continue
                    a="$stripped"
                fi
                ;;
        esac
        rewritten+=("$a")
    done

    if [ "$had_all" = true ]; then
        if ! git_retry "${ROOT_GIT[@]}" add -u -- ':/' "${EXCLUDES[@]}"; then
            err "[ERROR] Could not stage tracked changes for this commit."

            return 1
        fi
        note "commit -a staged via an excluding 'add -u' (system/ + submodules left alone)."
        git "${rewritten[@]}"

        return $?
    fi

    git "${ARGS[@]}"
}

# =============================================================================
# Classification + dispatch
# =============================================================================
is_tree_rewriting() {
    case "$SUBCMD" in
        pull|merge|rebase|cherry-pick|revert)
            return 0
            ;;
        clean)
            # A dry run rewrites nothing - including the clustered spelling (`-nd`).
            sub_args_have --dry-run && return 1
            sub_args_have_short_flag n e && return 1

            return 0
            ;;
        restore)
            # --staged alone touches the index, not the working tree.
            if sub_args_have --staged -S && ! sub_args_have --worktree -W; then
                return 1
            fi

            return 0
            ;;
        checkout|switch)
            # Creating a branch at HEAD does not rewrite the tree; do not stop the
            # world for it.
            sub_args_have -b -B -c -C && return 1

            return 0
            ;;
        reset)
            sub_args_have --hard --merge --keep && return 0

            return 1
            ;;
        stash)
            case "${SUB_ARGS[0]:-}" in
                pop|apply) return 0 ;;
            esac

            return 1
            ;;
    esac

    return 1
}

# --rsx-raw / --rsx-include-system disable the exclusion half; the maintenance half
# is governed only by --rsx-no-maint (raw output has nothing to do with quiescing).
EXCLUSION_ON=true
[ "$RSX_RAW" = true ] && EXCLUSION_ON=false
[ "$RSX_INCLUDE_SYSTEM" = true ] && EXCLUSION_ON=false

if is_tree_rewriting; then
    run_maintenance_op
    exit $?
fi

if [ "$EXCLUSION_ON" = true ]; then
    case "$SUBCMD" in
        status|add)
            run_excluded_op
            exit $?
            ;;
        diff)
            # --no-index compares two filesystem paths and takes no pathspec.
            if sub_args_have --no-index; then
                exec git "${ARGS[@]}"
            fi
            run_excluded_op
            exit $?
            ;;
        commit)
            run_commit
            exit $?
            ;;
    esac
fi

# Everything else - push, fetch, log, show, branch, blame, rev-parse, tag, config,
# apply, and every subcommand this proxy has never heard of - is git, unchanged.
#
# log/show deliberately do NOT get the exclusion: they display COMMITS, not
# working-tree churn, and filtering them would hide framework-update history - the
# opposite of helpful. branch/blame/rev-parse take no pathspec to exclude with.
exec git "${ARGS[@]}"
