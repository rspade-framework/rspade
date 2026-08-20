#!/bin/bash

# =============================================================================
# RSpade Framework Update Script
# =============================================================================
# Pulls the framework (system/) up to date from the rspade_system distribution
# repository. In the current downstream topology system/ is ORDINARY TRACKED
# FILES in the app repo (no git submodule, no gitlink). This script therefore
# never merges: it clones the distribution into a temp checkout and reconciles
# the working tree file-by-file. It then COMMITS its own system/ changes as one
# dedicated framework-update commit (see COMMIT MODEL below). It runs BEFORE
# Laravel loads (via the artisan pre-boot interception) so a broken framework can
# still be repaired.
#
# ARCHITECTURE
#   - OWNED ZONES (app/RSpade, vendor, node_modules, bin, docs + the files
#     artisan and .rspade-release.json) are the framework's exclusively. They are
#     hard-synced from the new release with rsync --delete: whatever the release
#     ships is exactly what lands. Local edits there are removed (a --force run
#     doubles as the repair path).
#   - THE REMAINING TREE is reconciled with a three-way file comparison against
#     the currently-installed release and the new release, so genuine local
#     system/ changes outside the owned zones are preserved and conflicts are
#     reported (never silently clobbered without --force).
#   - The mutation-marker store (storage/rsx-framework) records the framework's
#     own churn so the offline tamper gate (rsx:framework:verify) can tell a real
#     local edit from a framework-authored one BY CONSTRUCTION - no regex.
#   - The distribution cache (a bare clone of rspade_system) is pure re-derivable
#     data and lives OUTSIDE the app tree, at /tmp/rspade_upstream.git - see
#     Framework_Maintenance::upstream_cache_dir(), the PHP source of truth.
#
# COMMIT MODEL (system/ is framework-owned)
#   system/ is committed ONLY by this updater, in its own commit
#   (commit_system_update) made BEFORE the rebuild, so it captures the PRISTINE
#   DISTRIBUTION STATE exactly as synced. Three properties follow, and they are the
#   whole reason for the ordering:
#     * A FAILED REBUILD IS HARMLESS. The release is already in history, so every
#       later rsx:clean / pre-commit-hook reset lands ON the new release instead of
#       reverting it. Committing after the rebuild made a deterministic rebuild
#       failure unrecoverable: the release sat synced-but-uncommitted, and the next
#       app commit's hook reset it away. A release shipping a mandatory app
#       migration (whose build cannot pass until the app migrates, which needs app
#       commits) deadlocked on that forever. The repair for a post-commit rebuild
#       failure is now simply a REBUILD (dev auto-rebuild, or rsx:manifest:build) -
#       never a re-pull.
#     * CHURN IS ALWAYS-LOCAL. Framework runtime churn (class-override
#       .php <-> .php.upstream renames, use-header rewrites) is regenerate-on-demand
#       state, exactly as every other rebuild-churn path already treats it:
#       uncommitted-but-VISIBLE system/ modifications that rsx:clean resets and the
#       next build regenerates. It is not hidden (the retired skip-worktree +
#       *.php.upstream devices were), and the mutation-marker ledger still authorizes
#       it for the tamper gate (rsx:framework:verify).
#     * THE COMMITTED TREE IS THE RELEASE, AND NOTHING ELSE. The commit is built in a
#       throwaway index that is normalized (normalize_commit_index) and then asserted
#       path-for-path against .rspade-release.json (assert_index_matches_release):
#       class-override sidecars come out, and EVERY tracked path under system/ the
#       release does not ship is untracked from the commit. Owner ruling, 2026-08-18:
#       "system gets rsynced from the framework metaphorically with --delete" - there
#       are no local additions to a vendored read-only tree, so there is no escape
#       hatch and no allowlist. The working tree is never touched by that; the disk-side
#       purge is a peer's next rsx:git reset, which is the intended outcome. A box whose
#       marker is already current repairs its committed baseline the same way, commit
#       only (see main()'s up-to-date branch).
#     * SISTER ENVIRONMENTS CONVERGE. A pristine commit is byte-identical for the
#       same release everywhere, so two boxes pulling it with different app override
#       sets no longer produce diverging framework commits - a whole class of
#       system/ merge conflict disappears.
#   A pre-commit hook (installed by bin/post-update.sh ->
#   bin/environment_updates/020_precommit_hook.sh, keyed RSPADE-PRECOMMIT-V1) resets
#   + unstages system/ from ordinary app commits so the framework and app histories
#   never mix; the updater bypasses it via RSPADE_FRAMEWORK_COMMIT=1 + --no-verify.
#   system/ must NEVER be gitignored (ensure_system_not_ignored) and the old git
#   skip-worktree + *.php.upstream hiding is retired
#   (migrate_legacy_override_hygiene) - either would mask drift and silently drop the
#   override zone from the commit. --no-commit leaves the synced release uncommitted
#   for manual commit (and warns loudly - see warn_synced_uncommitted);
#   --check-foreign-changes is a read-only system/ drift probe (exit 1 if dirty).
#
# WHAT RELEASE IS INSTALLED - ONE AUTHORITY
#   system/.rspade-release.json, and nothing else. Its release_id is matched against
#   the cached distribution history to resolve OLD_SHA; no match makes the run a
#   BASELINE (no changelog, conservative three-way).
#
#   rsx/resource/framework_update_history.dat (committed, lives under rsx/ so the sync
#   never touches it) is a LOG. It records each update, newest section first, with a
#   machine-readable first line:
#       ## RSPADE-UPDATE <ISO8601Z> from=<old_sha|BASELINE> to=<new_sha>
#   It is READ for exactly one purpose: to warn when its `to=` contradicts the marker.
#   It decides nothing. Until 2026-08-18 it was the PRIMARY resolution, which made two
#   independently-merging files both authoritative - and when a backwards merge made
#   them disagree, the run read "up to date" off the log and then repaired the tree to
#   the older marker, untracking 7,503 paths (Ascent). The marker travels inside the
#   tree it describes; the log does not.
#
# --resync
#   Restore every owned zone to the distribution tip and re-commit system/ as that
#   release, whatever any marker claims. The recovery path after a backwards merge has
#   moved a tree off its release. It is NOT --force: locally modified files still go
#   through the tamper gate, so accepted committed drift survives.
#
# LLM AGENTS: never pass --force without explicit user permission. --force
# DESTROYS local changes under the framework-owned zones and takes the upstream
# side of every three-way conflict.
#
# The live .sh is generated from this .dist by the artisan interception; edit the
# .dist only.
# =============================================================================

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------
# The public distribution repo (anonymous https - no credentials required to pull).
# publish dual-pushes every release here and to the internal repo; a box with a reason
# to pull elsewhere passes --upstream-url= explicitly.
DEFAULT_UPSTREAM_URL="https://github.com/rspade-framework/rspade_system.git"
UPSTREAM_BRANCH="master"

# Owned-zone directories (relative to system/). Kept in lockstep with
# Framework_Mutations::owned_zones(). Hard-synced with rsync --delete.
#
# database/ is owned (added 2026-08-11, Field incident): framework migrations are
# IMMUTABLE once shipped and are never legitimately authored, edited or deleted
# downstream - an app's own migrations live in rsx/resource/migrations/, and
# make:migration:safe routes there whenever is_framework_developer is false, so
# nothing downstream ever writes under system/database/. Leaving it to the
# three-way pass meant a lost migration was inferred to be a deliberate local
# deletion and preserved FOREVER (see three_way_pass), so a box could sit a full
# release behind on schema while every pull reported success. Hard-syncing makes
# that loss self-healing, exactly as it already is for bin/ and app/RSpade/.
#
# config/, resources/ and supervisor/ are owned for the same reason (2026-08-11):
# all three are framework property with an app-side equivalent elsewhere. App config
# overrides live in rsx/resource/config/ and are merged over the framework's
# (Rsx_Framework_Provider); app views live in rsx/; supervisor/ holds the framework's
# own service definitions. Nothing downstream writes to any of them, and no framework
# code generates files there at runtime - both verified before this was widened.
# public/ is deliberately NOT owned: it is the docroot, where an app legitimately
# places its own static assets.
OWNED_DIRS=(app/RSpade vendor node_modules bin docs database config resources supervisor)

# Owned-zone individual FILES (relative to system/) - a first-class peer of the
# directory list, not a two-entry special case. Any single file may be owned, and is
# then synced authoritatively exactly like a directory.
#
# .gitignore is owned (2026-08-11): it is framework-authored, it governs
# framework-tree hygiene, and it is exactly the kind of file an app is tempted to
# tweak - after which three_way_pass preserves the local edit and that box silently
# stops receiving every future upstream ignore rule. The same shape already cost a
# downstream app an outage: one local edit to config/rsx.php froze the file, and two
# releases later it was still frozen.
#
# app/Http/Kernel.php is owned (2026-08-19): it is pure framework wiring - the
# maintenance/migration/Playwright middlewares and the deliberate REMOVALS of
# Laravel's session/CSRF/ConvertEmptyStrings entries - and a framework release
# regularly has to edit it on the app's behalf. Left to the three-way pass, one
# local tweak freezes it and that box stops receiving every future change to the
# request stack. Apps extend it through config('rsx.middleware') instead, which
# is append-only and survives every update (php artisan rsx:man config_rsx).
OWNED_FILES=(artisan .rspade-release.json .gitignore app/Http/Kernel.php)

# -----------------------------------------------------------------------------
# Flag state (parsed in parse_flags)
# -----------------------------------------------------------------------------
YES=false
FORCE=false
DIFF_SYSTEM_CHANGES=false
NO_REBUILD=false
NO_COMMIT=false
RESYNC=false           # --resync: restore every owned zone to the distribution tip and
                       # re-commit system/ as that release, regardless of what any marker
                       # claims. The recovery path for a tree that a BACKWARDS MERGE moved
                       # off its release (field report, 2026-08-18). NOT --force: locally
                       # modified files still go through the tamper gate, so accepted
                       # committed drift survives - which is the whole difference.
CHECK_FOREIGN=false    # --check-foreign-changes: local-only system/ drift probe, then exit
SERVICE_CONTROL=true   # raise the maintenance window (stops the supervised services) around the
                       # sync; --no-service-control opts out. The service LIST lives in one place:
                       # bin/maintenance-mode.sh. Never enumerate it here.
SHOW_DIFF=false
UPSTREAM_URL="$DEFAULT_UPSTREAM_URL"
CACHE_DIR_OVERRIDE=""  # --cache-dir=<dir>: point the distribution cache elsewhere (test seam)

# -----------------------------------------------------------------------------
# Derived paths (set in derive_paths)
# -----------------------------------------------------------------------------
SCRIPT_DIR=""
SYSTEM_DIR=""
PROJECT_ROOT=""
CACHE=""
STORE_DIR=""
HISTORY_FILE=""
RELEASE_MANIFEST=""
UPDATER_BASENAME=""  # this script's own filename (.sh downstream, .sh.dist in the fixture)

# -----------------------------------------------------------------------------
# Runtime state
# -----------------------------------------------------------------------------
NEW_TREE=""
OLD_TREE=""
NEW_SHA=""
OLD_SHA=""            # empty => baseline mode
BASELINE=false
FRAMEWORK_CHANGELOG="" # byte-faithful OLD..NEW changelog (computed by compute_framework_changelog; used by write_history)
AUTO_COMMITTED=false   # true after commit_system_update makes a commit (tunes the summary)
MAINT_ACTIVE=false     # true once THIS run raised the maintenance window (bin/maintenance-mode.sh);
                       # threads the artisan override and tells cleanup() to lift the window
SYNCED_UNCOMMITTED=false # ARMED the moment the new release lands on disk (owned_zone_sync /
                       # do_conversion) and DISARMED by commit_system_update. While armed, an exit -
                       # normal or fatal - means system/ holds a synced but UNCOMMITTED release, the
                       # state in which an rsx:clean or an app commit silently reverts the update.
                       # cleanup() prints the loud recovery block for exactly that window.
REPORT_FILE=""        # DURABLE per-file reconciliation log (path printed in the summary)
CONFLICT_FILE=""      # temp: the conflict subset, always echoed to the console
VERBOSE=false         # --verbose: stream the full per-file report to the console
# Reconciliation tallies (see __report): counted outcomes are summarized in one line.
R_UPDATED=0; R_ADDED=0; R_DELETED=0
R_LOCAL_CHANGE=0; R_LOCAL_DEL=0; R_LOCAL_ADD=0; R_CONFLICT=0; R_MISSING=0
TMP_ROOT=""           # mktemp root for temp checkouts (cleaned on exit)
MANIFEST_FORCE=false  # true after a conversion: force a full manifest rebuild (see do_rebuild)

# =============================================================================
# Utility
# =============================================================================
say()  { echo "$*"; }
ok()   { echo "[OK] $*"; }
warn() { echo "[WARNING] $*"; }
err()  { echo "[ERROR] $*" >&2; }

die() {
    err "$1"
    exit "${2:-1}"
}

# Distinct return code meaning "all lock-timeout retries exhausted" - chosen not to
# collide with any real artisan exit code (0-255 could, but 179 is not used by any
# artisan command here).
LOCK_EXHAUSTED_RC=179
ARTISAN_OUT=""

# git_lock_fatal <what-was-being-attempted>
# Terminal handler for "the git index stayed locked through every retry". Dies, which
# fires the EXIT trap -> cleanup() -> maintenance-mode disable, so the services this
# run stopped (php-fpm, realtime, fpc-proxy, rsx-lockd, redis) come back up before the
# process leaves. The rebuild is NEVER reached: `die` exits before do_rebuild, which is
# deliberate - rebuilding on top of a tree whose framework state could not be committed
# would bury the problem under a successful-looking build.
#
# The state this leaves is the ARMED window (release on disk, not in history), so the
# message says so plainly and gives the exact recovery. Naming the lock matters: an
# operator who sees "index.lock" and no explanation reaches for `rm` on the wrong file.
git_lock_fatal() {
    local what="$1"
    err ""
    err "The git index stayed LOCKED through all 3 attempts while $what."
    err ""
    err "  Another process is holding .git/index.lock, or a process that died mid-write"
    err "  left it behind. This run changed no git state and is stopping here rather"
    err "  than rebuilding on top of a framework tree it could not commit."
    err ""
    err "  The release IS on disk and is NOT in history. Do not run rsx:clean and do"
    err "  not fold system/ into an app commit until this is resolved."
    err ""
    err "  1. Find the holder:   fuser -v $PROJECT_ROOT/.git/index.lock"
    err "                        ps -eo pid,etimes,cmd | grep '[g]it'"
    err "  2. If NOTHING holds it, the lock is stale - remove it:"
    err "                        rm -f $PROJECT_ROOT/.git/index.lock"
    err "  3. Re-run:            php artisan rsx:framework:pull --no-rebuild"
    err "                        (then a normal pull to rebuild)"
    err ""
    die "Framework update aborted: git index locked ($what)."
}

# run_git_retry <label> <git-args...>
# Runs `git <args>` from PROJECT_ROOT, retrying a TRANSIENT failure up to 3 times with
# a 1s pause. Combined output is echoed after every attempt (nothing hidden) and left
# in GIT_OUT.
#
# WHY: the failure mode is .git/index.lock. Git takes it for any index write - add,
# commit, and (until it was fixed) a plain `status` refresh from the status-line
# renderer painting on a 18MB-index repo. A concurrent holder, or a lock orphaned by
# a process cut short mid-write, makes git exit non-zero with "Another git process
# seems to be running in this repository". That is CONTENTION, not corruption: the
# holder finishes in well under a second, so a short retry clears it. Before this,
# one unlucky moment aborted the whole framework update.
#
# NOT retried: a genuine git error (bad pathspec, unmerged files, nothing to commit).
# Those do not improve by waiting, so they return their real exit code immediately and
# the caller decides. Only the lock signature is treated as transient.
#
# Returns 0 on eventual success, the real exit code on a non-lock failure, and
# $GIT_LOCK_EXHAUSTED_RC when all 3 attempts hit the lock.
GIT_LOCK_EXHAUSTED_RC=178
GIT_OUT=""
run_git_retry() {
    local label="$1"; shift
    local attempt rc
    for (( attempt = 1; attempt <= 3; attempt++ )); do
        GIT_OUT="$(git -C "$PROJECT_ROOT" "$@" 2>&1)"
        rc=$?
        [ -n "$GIT_OUT" ] && printf '%s\n' "$GIT_OUT"
        if [ "$rc" -eq 0 ]; then
            return 0
        fi
        # A real git error surfaces immediately - waiting cannot help it.
        if ! printf '%s' "$GIT_OUT" | grep -qiE 'index\.lock|Another git process'; then
            return "$rc"
        fi
        if [ "$attempt" -lt 3 ]; then
            warn "$label: git index is locked by another process (attempt $attempt/3), retrying in 1s..."
            __clear_orphaned_index_lock
            sleep 1
        fi
    done
    return "$GIT_LOCK_EXHAUSTED_RC"
}

# __clear_orphaned_index_lock - remove .git/index.lock ONLY when it is provably nobody's.
#
# A live holder finishes in well under a second and the retry above absorbs it. An ORPHAN -
# left by a process killed mid-write, which is what a status-line render cut short produces -
# is never released by anyone, so retrying alone cannot help and the update dies for no
# reason (field report, 2026-08-10: repeated stale locks all session).
#
# The test is EVIDENCE, not a guess, and deliberately not time-based: the file must be
# ZERO BYTES (git writes the new index into it, so a real in-progress write is non-empty)
# AND no process may hold it open. `fuser` is the authority; if fuser is unavailable we do
# NOT guess - we leave the lock alone and let the retry play out. Removing a lock somebody
# holds would corrupt an index, so silence from a missing tool must never read as "free".
__clear_orphaned_index_lock() {
    local lock="$PROJECT_ROOT/.git/index.lock"
    [ -f "$lock" ] || return 0
    [ -s "$lock" ] && return 0                       # non-empty: a real write is in flight
    command -v fuser >/dev/null 2>&1 || return 0     # cannot prove it is free -> do not touch

    if fuser "$lock" >/dev/null 2>&1; then
        return 0                                     # somebody holds it: not an orphan
    fi

    warn "  .git/index.lock is 0 bytes and held by no process - removing the orphan."
    rm -f "$lock" 2>/dev/null || true
}

# run_artisan_lock_retry <label> <artisan-args...>
# Runs `php <artisan> <args>` capturing combined output (echoed after each attempt,
# so nothing is hidden). On a LIVE downstream box, cron/supervisor/web boots run the
# dev auto-rebuild continuously and each holds the server:MANIFEST_BUILD WRITE lock
# for the whole rebuild (~100s on a busy box). Any artisan step here can therefore
# lose the 30s lock race and die with "Failed to acquire WRITE lock ... after 30
# seconds". Rather than abort the entire update on that TRANSIENT contention, retry
# up to 10 attempts (each attempt itself blocks ~30s inside RsxLocks before timing
# out, so the loop naturally spans ~5 minutes - comfortably beyond a concurrent
# rebuild) with a short sleep between attempts.
#
# Returns 0 on eventual success; $LOCK_EXHAUSTED_RC when all 10 attempts timed out on
# the lock signature; the REAL artisan exit code on a NON-lock failure (the caller
# decides whether that is fatal). Captured output is left in ARTISAN_OUT.
run_artisan_lock_retry() {
    local label="$1"; shift
    # In-window artisan calls must carry the override or the maintenance gate (system/artisan)
    # would 503 them. Empty array otherwise (bash 5, safe).
    local -a maint=(); [ "$MAINT_ACTIVE" = true ] && maint=(--_framework-update-override)
    local attempt rc
    for (( attempt = 1; attempt <= 10; attempt++ )); do
        ARTISAN_OUT="$(php "$SYSTEM_DIR/artisan" "$@" "${maint[@]}" 2>&1)"
        rc=$?
        printf '%s\n' "$ARTISAN_OUT"
        if [ "$rc" -eq 0 ]; then
            return 0
        fi
        # A NON-lock failure surfaces immediately with the real exit code - the box
        # is not merely contending, something is genuinely wrong.
        if ! printf '%s' "$ARTISAN_OUT" | grep -qi 'Failed to acquire.*lock'; then
            return "$rc"
        fi
        # Lock timeout: a concurrent manifest rebuild is holding the build lock.
        if [ "$attempt" -lt 10 ]; then
            warn "$label is waiting for a concurrent manifest rebuild to finish (attempt $attempt/10)..."
            sleep 5
        fi
    done
    return "$LOCK_EXHAUSTED_RC"
}

# warn_synced_uncommitted - the loud block for the ONE lethal intermediate state:
# the new release is on disk but not in history. Any rsx:clean (directly, or via the
# pre-commit hook that fires on the very next app commit) resets system/ to its last
# commit and silently reverts the whole update. The reorder makes this unreachable in
# the normal flow; it remains reachable on a failure between the sync and the commit,
# and by design under --no-commit. Recovery re-runs the sync and commits WITHOUT a
# build gate in between.
warn_synced_uncommitted() {
    say ""
    # Severity follows intent: under --no-commit this state was deliberately requested
    # (a successful run must not end with an [ERROR] line); every other way of landing
    # here is a failure mid-update.
    if [ "$NO_COMMIT" = true ]; then
        warn "system/ holds a synced but UNCOMMITTED framework release."
    else
        err "system/ holds a synced but UNCOMMITTED framework release."
    fi
    say "        Do NOT run rsx:clean or make any app commit until it is committed -"
    say "        either would revert the update. Recover with:"
    say "        php artisan rsx:framework:pull --no-rebuild"
    say ""
}

cleanup() {
    if [ -n "$TMP_ROOT" ] && [ -d "$TMP_ROOT" ]; then
        rm -rf "$TMP_ROOT" 2>/dev/null || true
    fi
    # REPORT_FILE is deliberately NOT removed - it is the durable per-file
    # reconciliation log whose path the summary prints (and which --verbose streams).
    if [ -n "$CONFLICT_FILE" ] && [ -f "$CONFLICT_FILE" ]; then
        rm -f "$CONFLICT_FILE" 2>/dev/null || true
    fi
    if [ -n "$MISSING_FILE" ] && [ -f "$MISSING_FILE" ]; then
        rm -f "$MISSING_FILE" 2>/dev/null || true
    fi
    if [ -n "$PRESERVED_FILE" ] && [ -f "$PRESERVED_FILE" ]; then
        rm -f "$PRESERVED_FILE" 2>/dev/null || true
    fi
    # ALWAYS lift the maintenance window on every exit path - normal, error, or Ctrl-C (the
    # trap covers EXIT INT TERM). ONE implementation: the same bin/maintenance-mode.sh the
    # operator command runs (restarts every supervised service it stopped, in dependency
    # order, then clears the flag - that script owns the list, this one never repeats it).
    # Guarded on MAINT_ACTIVE so a window the OPERATOR raised before running the pull
    # is left exactly as they left it.
    if [ "$MAINT_ACTIVE" = true ]; then
        MAINT_ACTIVE=false
        if [ -f "$SYSTEM_DIR/bin/maintenance-mode.sh" ]; then
            bash "$SYSTEM_DIR/bin/maintenance-mode.sh" disable || true
        else
            # The tree lost the script mid-run: never leave a box stuck in 503.
            rm -f "$(storage_base)/rsx-framework/.maintenance.mode.framework.update" 2>/dev/null || true
            err "Maintenance script missing; the flag was cleared but services were NOT restarted."
        fi
    fi
    # LAST, so it is the final thing an operator sees: the armed-window warning (see
    # warn_synced_uncommitted). Fires on every exit path - normal, error, Ctrl-C - that
    # leaves the release on disk and out of history.
    if [ "$SYNCED_UNCOMMITTED" = true ]; then
        warn_synced_uncommitted
    fi
}

# =============================================================================
# parse_flags
# =============================================================================
parse_flags() {
    for arg in "$@"; do
        case "$arg" in
            --yes)                  YES=true ;;
            --force)                FORCE=true ;;
            --diff-system-changes)  DIFF_SYSTEM_CHANGES=true ;;
            --no-rebuild)           NO_REBUILD=true ;;
            --no-commit)            NO_COMMIT=true ;;
            --resync)               RESYNC=true ;;
            --check-foreign-changes) CHECK_FOREIGN=true ;;
            --no-service-control)   SERVICE_CONTROL=false ;;
            --diff)                 SHOW_DIFF=true ;;
            --verbose)              VERBOSE=true ;;
            --upstream-url=*)       UPSTREAM_URL="${arg#--upstream-url=}" ;;
            # Test seam, mirroring --upstream-url: the CLI fixtures point the
            # distribution cache at their own temp clone so a test never reads or
            # disturbs the shared cache at /tmp/rspade_upstream.git.
            --cache-dir=*)          CACHE_DIR_OVERRIDE="${arg#--cache-dir=}" ;;
            *)
                die "Unknown argument: $arg"
                ;;
        esac
    done
}

# =============================================================================
# storage_base - where VOLATILE storage lives (the updater is not Laravel-routed,
# so it cannot use storage_path()).
#
# Historically system/storage; relocated to <project>/storage by
# bin/environment_updates/030_relocate_storage.sh, which writes the marker file
# read here. The marker - NOT the mere absence of system/storage - is the signal,
# exactly as the bootstrap bridge (system/bootstrap/app.php) resolves it. Callers
# must invoke this AFTER derive_paths (it needs PROJECT_ROOT/SYSTEM_DIR).
# =============================================================================
storage_base() {
    if [ -f "$PROJECT_ROOT/storage/.rspade_storage_relocated" ]; then
        printf '%s' "$PROJECT_ROOT/storage"
    else
        printf '%s' "$SYSTEM_DIR/storage"
    fi
}

# =============================================================================
# derive_paths - everything from the script's own location (never caller cwd)
# =============================================================================
derive_paths() {
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    SYSTEM_DIR="$(dirname "$SCRIPT_DIR")"
    PROJECT_ROOT="$(dirname "$SYSTEM_DIR")"

    # This script's OWN filename. Downstream (via the artisan interceptor) it is
    # bin/framework-pull-upstream.sh (publish renamed the dev .dist to .sh); in the
    # CLI test fixture it is bin/framework-pull-upstream.sh.dist (the fixture runs the
    # .dist directly). ensure_updater_bootstrap keys off this so ONE code path keeps
    # the correct entry script committed in both trees.
    UPDATER_BASENAME="$(basename "$0")"

    # The mutation-marker store + the maintenance flag (durable state). The
    # distribution cache no longer lives here - it is re-derivable and sits at
    # Framework_Maintenance::upstream_cache_dir(), outside the app tree entirely.
    STORE_DIR="$(storage_base)/rsx-framework"
    CACHE="${CACHE_DIR_OVERRIDE:-/tmp/rspade_upstream.git}"
    HISTORY_FILE="$PROJECT_ROOT/rsx/resource/framework_update_history.dat"
    RELEASE_MANIFEST="$SYSTEM_DIR/.rspade-release.json"

    # cwd immunity: anchor the process at PROJECT_ROOT (a directory no flow ever
    # deletes) and keep it there for the script's whole life. artisan forwards to
    # this script inheriting whatever cwd `php artisan` ran from - which may be
    # INSIDE system/. The conversion path deletes/recreates system/; if the process
    # cwd were inside system/ its inode would vanish and the next spawned child
    # (rsync) would die on getcwd(). Every command here uses absolute paths, so the
    # process cwd is otherwise irrelevant - pinning it to PROJECT_ROOT makes that
    # explicit and removes any dependence on the caller's cwd.
    cd "$PROJECT_ROOT" || die "Failed to enter project root: $PROJECT_ROOT"
}

# =============================================================================
# run_gates - refuse in the situations the framework must never auto-update in
# =============================================================================
run_gates() {
    # Forked framework: the developer has taken ownership; never overwrite.
    if [ -f "$PROJECT_ROOT/.rspade-forked-framework" ] || [ -f "$SYSTEM_DIR/.rspade-forked-framework" ]; then
        err "Framework is in forked mode (.rspade-forked-framework present)."
        say ""
        say "You have taken full ownership of the RSpade framework codebase;"
        say "automatic updates are disabled to prevent overwriting your changes."
        say "For manual update procedures: php artisan rsx:man framework_fork"
        exit 1
    fi

    local env_file="$SYSTEM_DIR/.env"

    # Framework developers manage the monorepo directly; this repo must refuse.
    if [ -f "$env_file" ] && grep -q "^IS_FRAMEWORK_DEVELOPER=true" "$env_file"; then
        err "This command is disabled for framework developers."
        say ""
        say "This command is for application developers to pull framework updates."
        say "Framework developers manage the monorepo with standard git commands."
        say "To use it, set IS_FRAMEWORK_DEVELOPER=false in .env"
        exit 1
    fi

    # Never update in a production deployment.
    if [ -f "$env_file" ] && grep -q "^APP_ENV=production" "$env_file"; then
        err "Framework updates are disabled in production mode."
        say ""
        say "Incorporate and test framework updates in a development environment,"
        say "then deploy the reviewed result to production."
        exit 1
    fi

    # Only development mode may pull. Debug and production are SEALED builds; syncing fresh
    # framework files into a sealed build breaks the seal. RSX_MODE defaults to development
    # when unset. Stricter than the APP_ENV check above, which misses a sealed debug build.
    local rsx_mode
    rsx_mode="$(grep -E '^RSX_MODE=' "$env_file" 2>/dev/null | tail -n1 | sed -E 's/^RSX_MODE=//; s/["[:space:]]//g')"
    [ -z "$rsx_mode" ] && rsx_mode="development"
    if [ "$rsx_mode" != "development" ]; then
        err "Framework updates require development mode (RSX_MODE=development); current mode is '$rsx_mode'."
        say ""
        say "Debug and production are sealed builds. Pull in a development environment,"
        say "then rebuild the sealed build from the reviewed result."
        exit 1
    fi
}

# =============================================================================
# ensure_cache - cached bare distribution clone + new-release temp checkout
# =============================================================================
ensure_cache() {
    # No mkdir of the app's store here: the cache lives outside the tree and
    # `git clone --bare` creates its own directory. (Creating system/storage
    # eagerly at this point would also make the submodule conversion's
    # both-populated storage guard trip on a directory this script just made.)
    mkdir -p "$(dirname "$CACHE")" 2>/dev/null || true

    if [ -d "$CACHE" ]; then
        say "Fetching latest from the distribution repository..."
        if ! git --git-dir="$CACHE" fetch --prune "$UPSTREAM_URL" "+refs/heads/*:refs/heads/*" >/dev/null 2>&1; then
            # A fetch failure is either a CORRUPT local cache (a torn pack / interrupted prior
            # fetch) or a transient UPSTREAM/network problem. Only discard the cache when it is
            # actually broken - a fast connectivity-only fsck tells the two apart: if the cache
            # is intact, the fault is upstream (fail loud, keep the good cache); if it is corrupt,
            # self-heal by wiping + re-cloning once, and fail only if THAT also fails.
            if git --git-dir="$CACHE" fsck --connectivity-only >/dev/null 2>&1; then
                die "Failed to fetch from $UPSTREAM_URL (the local cache is intact - check the upstream URL / network)"
            fi
            warn "Fetch failed and the cached clone is corrupt - rebuilding it from scratch."
            rm -rf "$CACHE"
            if ! git clone --quiet --bare "$UPSTREAM_URL" "$CACHE" >/dev/null 2>&1; then
                die "Failed to fetch from $UPSTREAM_URL, and re-cloning the corrupt cache also failed"
            fi
        fi
    else
        say "Cloning the distribution repository (first run)..."
        if ! git clone --quiet --bare "$UPSTREAM_URL" "$CACHE" >/dev/null 2>&1; then
            die "Failed to clone $UPSTREAM_URL"
        fi
    fi

    if ! NEW_SHA="$(git --git-dir="$CACHE" rev-parse "refs/heads/$UPSTREAM_BRANCH" 2>/dev/null)"; then
        die "Distribution repository has no '$UPSTREAM_BRANCH' branch."
    fi
    ok "Distribution tip: ${NEW_SHA:0:12}"

    TMP_ROOT="$(mktemp -d)" || die "Failed to create temp working directory"
    NEW_TREE="$TMP_ROOT/new"

    # Local clone: objects are hardlinked, so the checkout is self-contained and
    # survives a later deletion of the cache (submodule-era conversion), and its
    # working files can be rsync'd as ordinary files. Cleanup is just rm.
    if ! git clone --quiet "$CACHE" "$NEW_TREE" >/dev/null 2>&1; then
        die "Failed to materialize the new release checkout"
    fi
    if ! git -C "$NEW_TREE" checkout --quiet --detach "$NEW_SHA" >/dev/null 2>&1; then
        die "Failed to check out the new release ($NEW_SHA)"
    fi
}

# =============================================================================
# resolve_old - installed rspade_system commit -> OLD_SHA (empty => baseline)
#
# ONE SOURCE OF TRUTH: system/.rspade-release.json, the marker INSIDE the tree it
# describes. rsx/resource/framework_update_history.dat is a LOG - it is read here only
# to report a disagreement, never to decide one.
#
# WHY (field report, 2026-08-18). The two files live on opposite sides of the system/ boundary
# and therefore merge INDEPENDENTLY: a backwards merge took the peer's system/ (marker
# 776b23af, 08-13) while the app-tree history.dat kept ours (37a96ed1, 08-18). OLD_SHA
# came from history.dat, matched the distribution tip, and the run concluded "up to
# date" - then fell into the path-set repair, which compares the tracked tree against
# the ON-DISK marker and duly untracked 7,503 paths the newer release had added. Two
# authorities that can disagree is not redundancy; it is a coin toss with no referee.
# The marker travels with the tree it describes, so the marker is the authority.
# =============================================================================
resolve_old() {
    OLD_SHA=""

    # THE AUTHORITY: match the installed release_id against the distribution history.
    local installed_id=""
    if [ -f "$RELEASE_MANIFEST" ]; then
        installed_id="$(__release_id_of_file "$RELEASE_MANIFEST")"
        if [ -n "$installed_id" ]; then
            local sha
            while IFS= read -r sha; do
                [ -n "$sha" ] || continue
                local content id
                content="$(git --git-dir="$CACHE" show "${sha}:.rspade-release.json" 2>/dev/null || true)"
                [ -n "$content" ] || continue
                id="$(printf '%s\n' "$content" | __release_id_of_stream)"
                if [ "$id" = "$installed_id" ]; then
                    OLD_SHA="$sha"
                    break
                fi
            done < <(git --git-dir="$CACHE" log --format=%H "$UPSTREAM_BRANCH" -- .rspade-release.json 2>/dev/null)
        fi
    fi

    # THE LOG. Read for one purpose: to say so, loudly, when it contradicts the marker.
    # A corrupt or absent history.dat changes nothing else about this run.
    local logged=""
    if [ -f "$HISTORY_FILE" ]; then
        local line
        line="$(grep -m1 '^## RSPADE-UPDATE ' "$HISTORY_FILE" 2>/dev/null || true)"
        [ -n "$line" ] && logged="$(printf '%s\n' "$line" | sed -n 's/.*[[:space:]]to=\([0-9a-f]\{7,40\}\).*/\1/p')"
    fi
    if [ -n "$logged" ] && [ -n "$OLD_SHA" ] && [ "${OLD_SHA:0:${#logged}}" != "$logged" ]; then
        warn "framework_update_history.dat disagrees with the installed release marker."
        say  "  marker  system/.rspade-release.json -> $installed_id (${OLD_SHA:0:12})"
        say  "  log     framework_update_history.dat -> ${logged:0:12}"
        say  "  The MARKER decides: it travels inside the tree it describes, while the log is"
        say  "  an app-tree file that merges separately (that split put a box a release behind"
        say  "  on 2026-08-18). The log is not consulted for anything else."
        say  ""
    fi

    if [ -z "$OLD_SHA" ]; then
        BASELINE=true
        return 0
    fi

    # Materialize the installed release for three-way comparison.
    OLD_TREE="$TMP_ROOT/old"
    if ! git clone --quiet "$CACHE" "$OLD_TREE" >/dev/null 2>&1; then
        die "Failed to materialize the installed release checkout"
    fi
    if ! git -C "$OLD_TREE" checkout --quiet --detach "$OLD_SHA" >/dev/null 2>&1; then
        # The installed commit is unknown to the distribution history: treat as baseline.
        OLD_SHA=""
        OLD_TREE=""
        BASELINE=true
    fi
}

# repair_direction_is_forward - is the ON-DISK marker at least as new as the tip?
#
# rc 0: the marker is the tip's release (or the comparison cannot be made, which decides
# nothing and must not block a repair). rc 1: the marker is OLDER than the distribution
# tip - the committed tree must be brought FORWARD by the ordinary sync, never re-stated
# backwards onto a stale inventory.
#
# ISO-8601 UTC dates compare lexically; both sides are read php-free.
repair_direction_is_forward() {
    local disk_date tip_date
    disk_date="$(sed -n 's/.*"date"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$RELEASE_MANIFEST" 2>/dev/null | head -n1)"
    tip_date="$(git --git-dir="$CACHE" show "${NEW_SHA}:.rspade-release.json" 2>/dev/null \
        | sed -n 's/.*"date"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)"

    [ -n "$disk_date" ] && [ -n "$tip_date" ] || return 0
    [[ "$disk_date" < "$tip_date" ]] || return 0

    warn "The installed release marker is OLDER than the distribution tip."
    say  "  marker  $disk_date"
    say  "  tip     $tip_date (${NEW_SHA:0:12})"
    say  "  Re-stating the committed tree onto a stale inventory would untrack everything"
    say  "  the newer release added, which is the 2026-08-18 field failure. Syncing FORWARD"
    say  "  to the tip instead."
    say  ""

    return 1
}

# release_id extractors (deliberately php-free so a broken tree still resolves).
__release_id_of_file() {
    sed -n 's/.*"release_id"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1" | head -n1
}
__release_id_of_stream() {
    sed -n 's/.*"release_id"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1
}

# =============================================================================
# preview (--diff) - show what the update WOULD bring; makes no changes
# =============================================================================
preview() {
    say ""
    say "=== Framework update preview ==="
    say ""
    if [ "$BASELINE" = true ]; then
        say "No installed release recorded (baseline)."
        say "Distribution tip: $NEW_SHA"
        say ""
        say "A full changelog is unavailable until the first update establishes history."
        return 0
    fi
    if [ "$OLD_SHA" = "$NEW_SHA" ]; then
        ok "Framework is up to date (${NEW_SHA:0:12})."
        return 0
    fi
    say "Changes ${OLD_SHA:0:12}..${NEW_SHA:0:12}:"
    say ""
    git --git-dir="$CACHE" log --date=short --pretty='  %h %ad %s' "$OLD_SHA..$NEW_SHA" 2>/dev/null || true
    say ""
    say "Files:"
    git --git-dir="$CACHE" diff --stat "$OLD_SHA" "$NEW_SHA" 2>/dev/null | sed 's/^/  /' || true
    say ""
}

# =============================================================================
# Tamper gate machinery
# =============================================================================
# __run_verify: capture the verify JSON. Sets:
#   VERIFY_CAN_RUN  (true/false) - whether the framework could be verified at all
#   VERIFY_JSON     - raw JSON output when it ran
VERIFY_CAN_RUN=false
VERIFY_JSON=""

__run_verify() {
    local out rc
    out="$(php "$SYSTEM_DIR/artisan" rsx:framework:verify --json 2>&1)"
    rc=$?
    if printf '%s' "$out" | grep -q '"clean"'; then
        VERIFY_CAN_RUN=true
        VERIFY_JSON="$out"
    else
        VERIFY_CAN_RUN=false
        VERIFY_JSON="$out"
    fi
    return 0
}

# __unauthorized_findings: emit "kind<TAB>path<TAB>has_shadow" for each
# unauthorized finding in VERIFY_JSON. php works here by definition (verify ran).
__unauthorized_findings() {
    printf '%s' "$VERIFY_JSON" | php -r '
        $j = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($j) || empty($j["unauthorized"])) { exit(0); }
        foreach ($j["unauthorized"] as $f) {
            echo ($f["kind"] ?? "") . "\t" . ($f["path"] ?? "") . "\t" . (!empty($f["has_shadow"]) ? "1" : "0") . "\n";
        }
    ' 2>/dev/null
}

# run_tamper_gate: abort on unauthorized owned-zone changes unless --force.
run_tamper_gate() {
    __run_verify

    if [ "$VERIFY_CAN_RUN" = false ]; then
        warn "Cannot verify framework integrity - the framework may be broken."
        say ""
        say "$VERIFY_JSON" | sed 's/^/  /'
        say ""
        if [ "$FORCE" = true ]; then
            warn "--force given: proceeding. The owned-zone sync will restore pristine framework files."
            return 0
        fi
        err "Refusing to update a framework that cannot be verified."
        say ""
        say "Re-run with --force to proceed anyway; a --force update restores pristine"
        say "framework files and doubles as the repair path."
        say ""
        say "LLM agents: never pass --force without explicit user permission."
        exit 1
    fi

    # ABSENCE IS NEVER INTENT, so a `missing` finding is never a reason to refuse.
    # The two categories have OPPOSITE risk profiles: a MODIFIED (or EXTRA) owned-zone
    # file holds local content the sync would destroy, and that is what the gate is
    # for; a MISSING one holds nothing at all, and restoring it costs nothing. Gating
    # both identically pushed operators toward --force to fix an absence - routing
    # them through the one flag that destroys modified files to solve the problem
    # that endangers none (field report, 2026-08-11).
    local all_findings blocking restorable
    all_findings="$(__unauthorized_findings)"
    blocking="$(printf '%s\n' "$all_findings" | grep -v '^missing	' || true)"
    restorable="$(printf '%s\n' "$all_findings" | grep '^missing	' || true)"

    if [ -n "$restorable" ]; then
        say "Framework files missing from an owned zone - the sync restores them (no flag needed):"
        printf '%s\n' "$restorable" | while IFS=$'\t' read -r _ path _; do
            say "  [missing] $path"
        done
        say ""
    fi

    if [ -z "$blocking" ]; then
        [ -n "$restorable" ] || ok "Framework tree verified - no unauthorized changes."
        return 0
    fi

    local findings="$blocking"

    if [ "$FORCE" = true ]; then
        warn "--force given: the following unauthorized changes under framework-owned zones will be DESTROYED:"
        say ""
        printf '%s\n' "$findings" | while IFS=$'\t' read -r kind path _; do
            say "  [$kind] $path"
        done
        say ""
        return 0
    fi

    err "Unauthorized changes detected under framework-owned zones."
    say ""
    say "These framework files differ from the release and were NOT authored by the"
    say "framework. Updating would DESTROY them:"
    say ""
    printf '%s\n' "$findings" | while IFS=$'\t' read -r kind path _; do
        say "  [$kind] $path"
    done
    say ""
    say "  * See exactly what changed:  php artisan rsx:framework:pull --diff-system-changes"
    say "  * Proceed and overwrite them: php artisan rsx:framework:pull --force"
    say ""
    say "LLM agents: never pass --force without explicit user permission."
    exit 1
}

# diff_system_changes (--diff-system-changes): print the offending diffs, no
# changes, exit non-zero iff unauthorized findings exist.
diff_system_changes() {
    __run_verify

    if [ "$VERIFY_CAN_RUN" = false ]; then
        warn "Cannot verify framework integrity - the framework may be broken."
        say ""
        say "$VERIFY_JSON" | sed 's/^/  /'
        return 1
    fi

    local findings
    findings="$(__unauthorized_findings)"
    if [ -z "$findings" ]; then
        ok "No unauthorized changes under framework-owned zones."
        return 0
    fi

    say ""
    say "=== Unauthorized framework changes (disk vs expected pristine/authored content) ==="
    say ""

    printf '%s\n' "$findings" | while IFS=$'\t' read -r kind path has_shadow; do
        [ -n "$path" ] || continue
        local disk="$SYSTEM_DIR/$path"
        local expected="" label=""

        if [ "$has_shadow" = "1" ] && [ -f "$STORE_DIR/shadow/$path" ]; then
            expected="$STORE_DIR/shadow/$path"
            label="framework-authored shadow"
        elif [ -n "$OLD_TREE" ] && [ -f "$OLD_TREE/$path" ]; then
            expected="$OLD_TREE/$path"
            label="installed release"
        elif [ -f "$NEW_TREE/$path" ]; then
            expected="$NEW_TREE/$path"
            label="new release"
        fi

        say "--- [$kind] $path"
        if [ -n "$expected" ] && [ -f "$disk" ]; then
            say "    (expected = $label)"
            diff -u "$expected" "$disk" 2>&1 | sed 's/^/    /' || true
        elif [ ! -f "$disk" ] && [ -n "$expected" ]; then
            say "    (file MISSING on disk; expected = $label)"
            diff -u "$expected" /dev/null 2>&1 | sed 's/^/    /' || true
        elif [ -f "$disk" ]; then
            say "    (extra file with no expected counterpart)"
            diff -u /dev/null "$disk" 2>&1 | sed 's/^/    /' || true
        fi
        say ""
    done

    return 1
}

# =============================================================================
# check_tree_complete - is every file this release ships actually ON DISK?
#
# The release inventory (.rspade-release.json) is the authoritative list of what a
# correct install contains, and NOTHING compared it against disk. That gap is what
# let a box sit a full release behind on schema while `rsx:framework:pull` reported
# "up to date" and rsx:framework:status agreed (field report, 2026-08-11).
#
# A missing file has exactly ONE innocent explanation - the class-override rename
# that moves `X.php` aside to `X.php.upstream` - so that is the only exemption, the
# same one Framework_Verify applies.
#
# TWO CATEGORIES, OPPOSITE ANSWERS. The inventory covers the WHOLE release, but the
# updater's reach does not: the owned-zone rsync and the three-way pass between them
# cover everything except storage/, and a path can only be repaired if the release
# also HOLDS a copy of it. Reporting both categories the same way produced a check
# that could dead-end on paths NOTHING - `--force` included - has the power to
# restore, and 7,326 phantom entries then camouflaged 15 real losses 490-to-1. So:
#
#   * REPAIRABLE (reachable AND present in the release) -> return 1. The caller
#     converges the tree by running the ordinary passes. No flag, no ceremony:
#     restoring a file that is not there destroys nothing, so there is no decision
#     for an operator to make.
#   * ORPHANED (everything else) -> advisory only. Named, counted, and given honest
#     advice; never fatal, and never "try --force", which would be a lie.
#
# Returns 0 when there is no repairable work, 1 when there is. NO TIMEOUT: this is
# ~100k stat calls and takes as long as the filesystem takes.
# =============================================================================
check_tree_complete() {
    local manifest="$SYSTEM_DIR/.rspade-release.json"
    [ -f "$manifest" ] || return 0     # framework-dev tree: nothing to verify against

    # The owned sets go to PHP as argv so there is exactly ONE definition of them.
    local out rc=0
    out="$(php -r '
        $base  = $argv[1];
        $new   = $argv[2];
        $dirs  = explode(",", $argv[3]);
        $files = array_flip(explode(",", $argv[4]));
        $m = json_decode(@file_get_contents($base . "/.rspade-release.json"), true);
        if (!is_array($m) || !is_array($m["files"] ?? null)) { exit(0); }

        $repairable = [];
        $orphaned   = [];
        foreach (array_keys($m["files"]) as $rel) {
            $abs = $base . "/" . $rel;
            if (is_file($abs) || is_file($abs . ".upstream")) { continue; }

            // REACHABLE means one of the two convergence mechanisms can actually put
            // this path back: the owned-zone rsync, or the three-way pass (which walks
            // everything else EXCEPT storage/ - see __list_nonowned). Anything else is
            // an ORPHAN: still reported, never presented as repairable, and never the
            // reason a run fails, because failing on a path nothing can fix is a
            // dead-end with no exit (field report, 2026-08-11).
            $reachable = isset($files[$rel]);
            if (!$reachable) {
                foreach ($dirs as $d) {
                    if ($d !== "" && strncmp($rel, $d . "/", strlen($d) + 1) === 0) { $reachable = true; break; }
                }
            }
            if (!$reachable && strncmp($rel, "storage/", 8) !== 0) { $reachable = true; }

            // ...and the release must actually HOLD a copy to install.
            if ($reachable && is_file($new . "/" . $rel)) { $repairable[] = $rel; } else { $orphaned[] = $rel; }
        }

        // Two blocks, "REPAIRABLE"/"ORPHANED" headers, count then a capped listing.
        // The cap is a DISPLAY cap on an already-complete count, not a truncated
        // result - an orphan list can legitimately run to thousands and burying the
        // repairable block under it is how real damage got camouflaged 490-to-1.
        foreach ([["REPAIRABLE", $repairable], ["ORPHANED", $orphaned]] as [$label, $set]) {
            if (!$set) { continue; }
            echo $label, " ", count($set), "\n";
            foreach (array_slice($set, 0, 40) as $p) { echo "  ", $p, "\n"; }
        }
        exit($repairable ? 1 : 0);
    ' "$SYSTEM_DIR" "$NEW_TREE" "$(IFS=,; echo "${OWNED_DIRS[*]}")" "$(IFS=,; echo "${OWNED_FILES[*]}")" 2>/dev/null)" || rc=$?

    local repairable_count orphaned_count
    repairable_count="$(printf '%s\n' "$out" | sed -n 's/^REPAIRABLE \([0-9]*\)$/\1/p')"
    orphaned_count="$(printf '%s\n' "$out" | sed -n 's/^ORPHANED \([0-9]*\)$/\1/p')"

    if [ -n "$orphaned_count" ]; then
        warn "$orphaned_count file(s) listed by the installed release are missing from disk"
        say  "  and are OUTSIDE what this updater can restore - either the release no longer"
        say  "  carries them (a stale marker from a release whose inventory over-promised) or"
        say  "  they sit outside both the owned-zone sync and the three-way pass. --force does"
        say  "  not change that: it governs the tamper gate, not the sync's reach."
        say  ""
        printf '%s\n' "$out" | sed -n '/^ORPHANED /,$p' | tail -n +2 | head -40 | sed 's/^/  /'
        if [ "${orphaned_count:-0}" -gt 40 ]; then
            say "    ... and $((orphaned_count - 40)) more"
        fi
        say ""
        say "  If you deleted them deliberately, nothing needs doing. Otherwise restore them"
        say "  from your own history:  php artisan rsx:git checkout -- system/<path>"
        say ""
    fi

    [ "$rc" -eq 0 ] && return 0

    warn "$repairable_count framework file(s) from the installed release are missing from disk."
    say  "  These live in framework-owned zones, so restoring them destroys nothing and needs"
    say  "  no flag. Converging the owned zones now."
    say  ""
    printf '%s\n' "$out" | sed -n '/^REPAIRABLE /,/^ORPHANED /p' | grep '^  ' | head -40
    if [ "${repairable_count:-0}" -gt 40 ]; then
        say "    ... and $((repairable_count - 40)) more"
    fi
    say ""

    return 1
}


# =============================================================================
# owned_zone_sync - hard rsync --delete of the owned zones from NEW
# =============================================================================
owned_zone_sync() {
    say "Synchronizing framework-owned zones..."

    local dir
    for dir in "${OWNED_DIRS[@]}"; do
        if [ -d "$NEW_TREE/$dir" ]; then
            mkdir -p "$SYSTEM_DIR/$dir"
            if ! rsync -rlD --delete --exclude='.git' "$NEW_TREE/$dir/" "$SYSTEM_DIR/$dir/"; then
                die "Failed to sync owned zone: $dir"
            fi
        elif [ -e "$SYSTEM_DIR/$dir" ]; then
            # Upstream removed the entire zone.
            rm -rf "${SYSTEM_DIR:?}/$dir"
        fi
    done

    local f
    for f in "${OWNED_FILES[@]}"; do
        if [ -f "$NEW_TREE/$f" ]; then
            mkdir -p "$(dirname "$SYSTEM_DIR/$f")"
            cp -f "$NEW_TREE/$f" "$SYSTEM_DIR/$f" || die "Failed to sync owned file: $f"
        elif [ -e "$SYSTEM_DIR/$f" ]; then
            rm -f "$SYSTEM_DIR/$f"
        fi
    done

    # From here the new release is ON DISK and not yet in history: arm the
    # armed-window warning until commit_system_update disarms it.
    SYNCED_UNCOMMITTED=true

    ok "Owned zones synchronized."
}

# =============================================================================
# ensure_updater_bootstrap - guarantee THIS updater's own entry script is present
# and STAGED downstream, even when a stale vendored .gitignore would exclude it.
#
# THE TRACKED-BUT-IGNORED CLASS. A release TRACKS bin/framework-pull-upstream.sh
# (publish renamed the dev .dist to .sh), but an OLDER shipped system/.gitignore
# still carried the dev-only line `/bin/framework-pull-upstream.sh`. In rspade_system
# git keeps tracking the already-added file, but in a downstream APP repo `git add
# system` SKIPS an ignored path - so the app-repo commit omits the updater, fresh
# provisioned clones materialize system/ without it, and artisan's pre-boot
# interceptor hard-exits. The publish-side strip + class assertion (Fixpack F) stops
# NEW releases from shipping the poison line; THIS is the second-pass remediation
# that unblocks a box provisioned from an OLDER release whose stale vendored
# .gitignore is still hiding the updater.
#
# The updater lives inside the owned zone (bin/), so by the time this runs the tree
# is already on disk (owned-zone sync on a normal update; the materialize + `git add
# system` on a conversion). We only need to force it into the developer's staged
# commit when an ignore rule would otherwise drop it.
#
# $1 = "conversion" -> force-stage unconditionally (do_conversion already staged the
# tree wholesale with `git add system`; -f makes the updater's staging robust and
# testable regardless of ignore rules). Any other value -> normal flow: intervene
# only when the path is actually ignored (else a plain, side-effect-free `git add`).
# =============================================================================
ensure_updater_bootstrap() {
    local mode="${1:-normal}"
    local abs="$SCRIPT_DIR/$UPDATER_BASENAME"
    local rel="system/bin/$UPDATER_BASENAME"

    # bin/ is owned-zone synced, so the script MUST be on disk here. Its absence
    # means the release itself is broken - fail loud.
    if [ ! -f "$abs" ]; then
        die "Framework updater script missing after sync: $abs (the release is broken)."
    fi

    local ignored=false
    if git -C "$PROJECT_ROOT" check-ignore -q "$rel" 2>/dev/null; then
        ignored=true
    fi

    if [ "$ignored" = true ]; then
        warn "An ignore rule is hiding the framework updater from git: $rel"
        say "  A stale vendored .gitignore (from an older release) or a local rule is"
        say "  excluding the updater's own entry script from your commit. Without it a"
        say "  fresh clone of this repo cannot run 'php artisan rsx:framework:pull'."
        say "  Force-staging it so your commit includes it regardless."
    fi

    if [ "$ignored" = true ] || [ "$mode" = "conversion" ]; then
        git -C "$PROJECT_ROOT" add -f "$rel" >/dev/null 2>&1 \
            || die "Failed to force-stage the framework updater: $rel"
    else
        # Not ignored, normal flow: a plain add leaves the developer's staging as it
        # would otherwise be (the summary hands off an unstaged tree for review).
        git -C "$PROJECT_ROOT" add "$rel" >/dev/null 2>&1 || true
    fi
}

# =============================================================================
# three_way_pass - reconcile the non-owned remainder of system/
# =============================================================================
# List a tree's non-owned, non-storage regular files (paths relative to root).
#
# The exclusions are DERIVED from OWNED_DIRS/OWNED_FILES rather than restated. This
# list and those arrays used to be two hand-maintained copies of one fact, and a path
# owned by one but not the other is handled by BOTH passes or NEITHER - the second of
# which is how a lost migration became permanent. Deriving makes adding an owned zone
# a one-line change that cannot go half-applied.
#
# The './storage/*' exclusion covers the historic system/storage layout; once storage
# has been relocated to <project>/storage (see storage_base) system/storage no longer
# exists and the exclusion is a harmless no-op - kept for pre-move compatibility.
# '.env' is a deployment symlink, never framework content.
__list_nonowned() {
    local root="$1"
    local -a prune=(-not -path './.git/*' -not -path './storage/*' -not -name '.env')
    local d f
    for d in "${OWNED_DIRS[@]}"; do prune+=(-not -path "./$d/*"); done
    for f in "${OWNED_FILES[@]}"; do prune+=(-not -path "./$f"); done

    ( cd "$root" 2>/dev/null && find . -type f "${prune[@]}" 2>/dev/null | sed 's|^\./||' | sort )
}

__sha() { sha256sum "$1" 2>/dev/null | cut -d' ' -f1; }

# -----------------------------------------------------------------------------
# __report <category> <message>
#
# The console is an operator-facing STATUS STREAM - "which step am I on, did
# anything need a decision, did anything break" - not an audit log. A routine
# update reconciles thousands of files whose outcome nobody has to think about;
# enumerating them buries what matters (a field pull emitted ~13,000 lines and
# 1.5 MB, hiding a mid-run Fatal at output line 23). So:
#
#   conflict     -> counted AND always listed on the console (an operator decision)
#   everything   -> counted only; one summary line per run
#
# EVERY outcome is written to the durable per-file report regardless. Its path is
# printed with the summary, and --verbose streams it to the console.
# -----------------------------------------------------------------------------
__report() {
    local category="$1"; shift
    printf '%s\n' "$*" >> "$REPORT_FILE"
    case "$category" in
        conflict)     R_CONFLICT=$((R_CONFLICT + 1)); printf '%s\n' "$*" >> "$CONFLICT_FILE" ;;
        updated)      R_UPDATED=$((R_UPDATED + 1)) ;;
        added)        R_ADDED=$((R_ADDED + 1)) ;;
        deleted)      R_DELETED=$((R_DELETED + 1)) ;;
        local_change) R_LOCAL_CHANGE=$((R_LOCAL_CHANGE + 1)); printf '%s\n' "$*" >> "$PRESERVED_FILE" ;;
        local_del)    R_LOCAL_DEL=$((R_LOCAL_DEL + 1)) ;;
        missing)      R_MISSING=$((R_MISSING + 1)); printf '%s\n' "$*" >> "$MISSING_FILE" ;;
        local_add)    R_LOCAL_ADD=$((R_LOCAL_ADD + 1)) ;;
    esac
}

__take_new() {  # copy NEW/$1 -> disk
    local rel="$1"
    mkdir -p "$(dirname "$SYSTEM_DIR/$rel")"
    cp -f "$NEW_TREE/$rel" "$SYSTEM_DIR/$rel"
}

__delete_disk() { rm -f "$SYSTEM_DIR/$1"; }

three_way_pass() {
    # Durable log (survives the run so the summary can point at it); the conflict
    # subset is temp because it is echoed to the console in full.
    mkdir -p "$(storage_base)/rsx-framework" 2>/dev/null || true
    REPORT_FILE="$(storage_base)/rsx-framework/last_pull_report.txt"
    : > "$REPORT_FILE" 2>/dev/null || REPORT_FILE="$(mktemp)"
    CONFLICT_FILE="$(mktemp)"
    MISSING_FILE="$(mktemp)"
    PRESERVED_FILE="$(mktemp)"

    local union
    union="$( { __list_nonowned "$NEW_TREE"; __list_nonowned "$SYSTEM_DIR"; } | sort -u )"

    local rel
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue

        local new_path="$NEW_TREE/$rel" disk_path="$SYSTEM_DIR/$rel"
        local new_exists=false disk_exists=false
        [ -f "$new_path" ] && new_exists=true
        [ -f "$disk_path" ] && disk_exists=true

        # -------------------------------------------------------------- baseline
        if [ "$BASELINE" = true ]; then
            if [ "$new_exists" = true ] && [ "$disk_exists" = false ]; then
                __take_new "$rel"; __report added "added (baseline): $rel"
            elif [ "$new_exists" = true ] && [ "$disk_exists" = true ]; then
                if [ "$(__sha "$new_path")" != "$(__sha "$disk_path")" ]; then
                    __report local_change "differs from upstream, left untouched (baseline): $rel"
                fi
            elif [ "$new_exists" = false ] && [ "$disk_exists" = true ]; then
                __report local_add "local file kept (baseline): $rel"
            fi
            continue
        fi

        # ------------------------------------------------------------ normal 3-way
        local old_path="$OLD_TREE/$rel" old_exists=false
        [ -f "$old_path" ] && old_exists=true

        local disk_sha="" new_sha="" old_sha=""
        [ "$disk_exists" = true ] && disk_sha="$(__sha "$disk_path")"
        [ "$new_exists" = true ]  && new_sha="$(__sha "$new_path")"
        [ "$old_exists" = true ]  && old_sha="$(__sha "$old_path")"

        if [ "$new_exists" = true ] && [ "$old_exists" = true ] && [ "$disk_exists" = true ]; then
            if [ "$disk_sha" = "$old_sha" ]; then
                if [ "$new_sha" != "$old_sha" ]; then __take_new "$rel"; __report updated "updated: $rel"; fi
            else
                if [ "$new_sha" = "$old_sha" ]; then
                    __report local_change "preserved local change: $rel"
                else
                    if [ "$FORCE" = true ]; then __take_new "$rel"; __report conflict "CONFLICT -> took upstream (--force): $rel";
                    else __report conflict "CONFLICT -> kept local (use --force for upstream): $rel"; fi
                fi
            fi

        elif [ "$new_exists" = true ] && [ "$old_exists" = true ] && [ "$disk_exists" = false ]; then
            # AN UPSTREAM FILE THAT IS NOT ON DISK HAS EXACTLY ONE INNOCENT EXPLANATION:
            # the class-override rename, which moves `X.php` aside to `X.php.upstream` and
            # puts the app's version in its place. That is the SAME test Framework_Verify
            # uses to excuse a missing file (see its "rename pair" handling). Anything else
            # is a LOST FILE, and inferring "the operator meant to delete this" from its
            # absence is how 13 framework migrations went missing on Ascent (2026-08-11)
            # while every pull printed [OK] - including a --force pull, since the old
            # force branch was unreachable for a file byte-identical across releases, which
            # an immutable shipped migration always is.
            #
            # RESTORE IS NOT AN OVERRIDE, so this does NOT consult --force (2026-08-11).
            # --force means one thing: override the tamper gate. Making it double as the
            # switch that decides whether a lost file comes back routed operators through
            # the one flag that DESTROYS modified files in order to solve the problem that
            # endangers none - and left the plain path dead-ending on damage it was
            # perfectly able to repair. Re-adding a file that is not there destroys
            # nothing, so it simply happens, and is reported.
            #
            # Order matters: the .upstream test comes FIRST so the restore never re-creates
            # `X.php` next to a live `X.php.upstream` and corrupts the override pair.
            if [ -f "${disk_path}.upstream" ]; then
                __report local_del "class override in place (.upstream present): $rel"
            else
                __take_new "$rel"; __report missing "$rel"
            fi

        elif [ "$new_exists" = true ] && [ "$old_exists" = false ]; then
            if [ "$disk_exists" = false ]; then
                __take_new "$rel"; __report added "added: $rel"
            elif [ "$disk_sha" != "$new_sha" ]; then
                if [ "$FORCE" = true ]; then __take_new "$rel"; __report conflict "CONFLICT -> took upstream (--force): $rel";
                else __report conflict "CONFLICT -> kept local (new file also added upstream; use --force): $rel"; fi
            fi

        elif [ "$new_exists" = false ] && [ "$old_exists" = true ]; then
            if [ "$disk_exists" = false ]; then
                : # already gone
            elif [ "$disk_sha" = "$old_sha" ]; then
                __delete_disk "$rel"; __report deleted "deleted (removed upstream): $rel"
            else
                if [ "$FORCE" = true ]; then __delete_disk "$rel"; __report conflict "CONFLICT -> deleted (--force; removed upstream): $rel";
                else __report conflict "CONFLICT -> kept local (removed upstream; use --force to delete): $rel"; fi
            fi

        elif [ "$new_exists" = false ] && [ "$old_exists" = false ] && [ "$disk_exists" = true ]; then
            __report local_add "local addition kept: $rel"
        fi
    done <<< "$union"

    # ---- Summary: counts for the routine outcomes, every conflict named. ----
    local -a parts=()
    if [ "$R_UPDATED" -gt 0 ];      then parts+=("$R_UPDATED updated from upstream"); fi
    if [ "$R_ADDED" -gt 0 ];        then parts+=("$R_ADDED added from upstream"); fi
    if [ "$R_DELETED" -gt 0 ];      then parts+=("$R_DELETED deleted (removed upstream)"); fi
    # local_del now means ONLY "a class override stands in this file's place" - a routine,
    # explained absence. An UNEXPLAINED absence is R_MISSING and kills the run below.
    if [ "$R_LOCAL_DEL" -gt 0 ];    then parts+=("$R_LOCAL_DEL class override(s) in place"); fi
    if [ "$R_LOCAL_ADD" -gt 0 ];    then parts+=("$R_LOCAL_ADD local addition(s) kept"); fi

    if [ "${#parts[@]}" -gt 0 ]; then
        local summary="" p
        for p in "${parts[@]}"; do
            if [ -n "$summary" ]; then summary="$summary, $p"; else summary="$p"; fi
        done
        say "Three-way reconciliation: $summary."
    fi

    # Conflicts are the operator-decision outcomes: ALWAYS named, never summarized away.
    if [ "$R_CONFLICT" -gt 0 ]; then
        warn "$R_CONFLICT conflict(s) - each listed below:"
        sed 's/^/    /' "$CONFLICT_FILE"
    fi

    # A PRESERVED LOCAL CHANGE MEANS "YOU ARE NOT GETTING THE UPSTREAM VERSION OF THIS
    # FILE - NOW, OR EVER AGAIN". Keeping a locally-modified file is the right behaviour;
    # doing it silently is what caused a full app outage (field report, 2026-08-10). One small
    # edit to system/config/rsx.php made the reconciler keep that file on EVERY subsequent
    # pull, so the app silently never received any upstream change to it. Two releases later
    # the file was missing the manifest_support registration that switches on the auth-gate
    # index, every page 500'd, and the updater had reported success throughout. The tell was
    # one digit inside a summary line: "1 local change(s) preserved".
    #
    # So: name every one, every time. The list is short by nature (a downstream rarely edits
    # framework files), and a named file is a file somebody can reconcile. Registration lists
    # are called out by name because a skipped hunk there dark-launches a whole release's
    # wiring - app/Console/Kernel.php, app/Providers/* and config/ are the ones that turn a
    # quiet skip into an outage. (app/Http/Kernel.php is no longer among them: it became an
    # OWNED file in 2026-08-19, so it is hard-synced and can never be preserved here.)
    if [ "$R_LOCAL_CHANGE" -gt 0 ]; then
        warn "$R_LOCAL_CHANGE file(s) differ from upstream and were KEPT AS YOURS."
        warn "  You are NOT receiving upstream changes to these - not now, and not on any"
        warn "  future pull, until the local difference is resolved."
        sed 's/^preserved local change: /    /' "$PRESERVED_FILE" 2>/dev/null | head -60
        if [ "$R_LOCAL_CHANGE" -gt 60 ]; then
            say "    ... and $((R_LOCAL_CHANGE - 60)) more (full list: $REPORT_FILE)"
        fi
        # A skipped hunk in a registration list is the outage case, not a style difference.
        if grep -qE '(^|/)(Kernel\.php|config/|app/Providers/)' "$PRESERVED_FILE" 2>/dev/null; then
            warn ""
            warn "  ONE OR MORE OF THESE IS A REGISTRATION LIST (a Kernel, a provider, or config/)."
            warn "  Upstream additions there - service providers, manifest_support entries, middleware -"
            warn "  are NOT reaching this app, and the symptom will surface later as unrelated"
            warn "  breakage. Diff each against the release and merge the upstream side:"
            warn "    php artisan rsx:framework:pull --diff-system-changes"
        fi
    fi

    # A LOST FILE IS RESTORED, AND SAID OUT LOUD. This used to be one clause inside the
    # summary line above ("N local deletion(s) preserved"), which is how 13 lost framework
    # migrations hid behind 7,326 correctly-deleted fixtures on Ascent while the run
    # printed [OK] and marked the release installed.
    #
    # It then became a FATAL that told the operator to re-run with --force. That was the
    # wrong half of the lesson: it made --force the switch for restoring a file, which is
    # the flag that destroys modified ones, so the tool routed people through the
    # destructive option to solve the non-destructive problem - and a box whose --force
    # could not satisfy the check had no way forward at all. Absence is never intent and
    # restoring costs nothing, so the file simply comes back. Every one is still named:
    # a silent restore would be as dishonest as the silent preservation it replaced.
    if [ "$R_MISSING" -gt 0 ]; then
        warn "$R_MISSING file(s) shipped in this release were MISSING from system/ - RESTORED."
        say ""
        say "  Each existed in the release, did not exist on disk, and had no '.upstream'"
        say "  sibling to explain it (the one legitimate reason a framework file is absent -"
        say "  the class-override rename). system/ is machine-owned; an absence there is"
        say "  damage, not a decision, so the release copy has been put back:"
        sed 's/^/    /' "$MISSING_FILE" | head -60
        if [ "$R_MISSING" -gt 60 ]; then
            say "    ... and $((R_MISSING - 60)) more - full list: $REPORT_FILE"
        fi
        say ""
    fi

    if [ "$VERBOSE" = true ]; then
        if [ -s "$REPORT_FILE" ]; then
            say "Full per-file reconciliation (--verbose):"
            sed 's/^/  /' "$REPORT_FILE"
        fi
    elif [ -s "$REPORT_FILE" ]; then
        say "  Full per-file report: $REPORT_FILE (--verbose streams it here)"
    fi

    ok "System tree reconciled."
}

# =============================================================================
# do_rebuild - post-update artisan pipeline
# =============================================================================
do_rebuild() {
    if [ "$NO_REBUILD" = true ]; then
        warn "--no-rebuild: skipping the framework rebuild."
        say ""
        say "Run these manually to finish the update:"
        say "  php artisan rsx:env:heal"
        # --_no-system-reset is framework-internal, but it MUST be typed here: it declares
        # cache invalidation ONLY. system/ is already committed by this point (the commit
        # precedes the rebuild), so a reset would no longer revert the update - but it
        # would still discard framework runtime churn the manifest pass is about to
        # regenerate, i.e. pure churn for no reason.
        say "  php artisan rsx:clean --silent --_no-system-reset"
        say "  php artisan rsx:manifest:build"
        say "  php artisan migrate --framework-only --force"
        say "  php artisan rsx:bundle:compile"
        say "  php artisan rsx:framework:post_update"
        say ""
        return 0
    fi

    say "Rebuilding framework..."

    # Every artisan step below is routed through run_artisan_lock_retry so a live-box
    # boot that loses the 30s build-lock race is RETRIED (up to 10 attempts, ~5 min)
    # instead of aborting the whole update. A NON-lock failure keeps each step's
    # original fail-loud/abort semantics; lock exhaustion is a warn-and-continue for
    # the best-effort steps (a concurrent rebuild is itself keeping the tree current)
    # and a warn-and-continue for migrate too (a lock timeout is NOT a migration
    # error - migrate stays LOUD only on a real, non-lock failure).
    local rc

    run_artisan_lock_retry "rsx:env:heal" rsx:env:heal
    [ $? -eq 0 ] || warn "rsx:env:heal reported a problem (continuing)."

    # rsx:clean is intentionally NOT lock-wrapped (it is not in the enumerated retry
    # set): a real rsx:clean failure must abort loudly. --_no-system-reset makes the
    # intent EXPLICIT: the updater wants cache invalidation only - it manages system/
    # itself, and build/cache tooling never touches git state. The release is already
    # committed at this point, so a reset here would no longer revert the update; the
    # flag stays because a reset is still pointless work (it would discard exactly the
    # churn the rebuild below is about to regenerate). (RSPADE_FRAMEWORK_UPDATE=1 plus
    # rsx:clean's own ambient in-flight detection remain as belts for older updaters
    # that lack the flag.)
    local -a __maint_clean=(); [ "$MAINT_ACTIVE" = true ] && __maint_clean=(--_framework-update-override)
    RSPADE_FRAMEWORK_UPDATE=1 php "$SYSTEM_DIR/artisan" rsx:clean --silent --_no-system-reset "${__maint_clean[@]}" || die "rsx:clean failed"

    # A conversion (fresh or resume) restores storage that can carry a pre-conversion
    # manifest cache which validates as fresh while the materialized tree's Phase-6
    # stub outputs are gone. Force a full rebuild so the manifest is regenerated from
    # the pristine tree, never trusted from the restored cache. Normal updates keep
    # the plain incremental build.
    # --_no-check-schema-updates-pending: the build's tail normally prints the pending-migration
    # notice, but main() already emits it once as the pull's final line. Without this the same
    # notice would fire twice per update.
    if [ "$MANIFEST_FORCE" = true ]; then
        run_artisan_lock_retry "rsx:manifest:build --force" rsx:manifest:build --force --_no-check-schema-updates-pending
    else
        run_artisan_lock_retry "rsx:manifest:build" rsx:manifest:build --_no-check-schema-updates-pending
    fi
    rc=$?
    if [ "$rc" -eq "$LOCK_EXHAUSTED_RC" ]; then
        warn "rsx:manifest:build could not obtain the build lock after 10 attempts; a concurrent rebuild is holding it and thereby keeping the manifest current, so continuing."
    elif [ "$rc" -ne 0 ]; then
        die "rsx:manifest:build failed"
    fi

    run_artisan_lock_retry "framework migrations" migrate --framework-only --force
    rc=$?
    if [ "$rc" -eq "$LOCK_EXHAUSTED_RC" ]; then
        say ""
        warn "framework migrations could not obtain the build lock after 10 attempts (a concurrent rebuild held it). This is lock contention, NOT a migration failure. Re-run once the box is quiet to be certain the schema is current: php artisan migrate --framework-only --force"
        say ""
    elif [ "$rc" -ne 0 ]; then
        say ""
        die "Framework migrations FAILED. The update is incomplete - resolve the migration error above and re-run."
    fi

    run_artisan_lock_retry "rsx:bundle:compile" rsx:bundle:compile
    rc=$?
    if [ "$rc" -eq "$LOCK_EXHAUSTED_RC" ]; then
        warn "rsx:bundle:compile could not obtain the build lock after 10 attempts; bundles JIT-compile on web request, so continuing."
    elif [ "$rc" -ne 0 ]; then
        die "rsx:bundle:compile failed"
    fi

    run_artisan_lock_retry "rsx:framework:post_update" rsx:framework:post_update
    [ $? -eq 0 ] || warn "Post-update check failed (non-fatal) - run manually: php artisan rsx:framework:post_update"

    ok "Framework rebuilt."
}

# =============================================================================
# prune_store - RECONCILE the mutation-marker store at the pristine moment (right
# after the owned-zone sync restored pristine files). This REPLACES the old blind
# wipe.
#
# THE RACE it closes (B-52): a downstream box is LIVE - cron, supervisor workers
# and web requests boot artisan continuously, and each boot runs the dev auto-
# rebuild. A boot firing between owned_zone_sync and this call can LEGITIMATELY
# re-apply the framework's own churn (class-override renames, use-header rewrites)
# AND record it in the marker store. A blind `rm` then DESTROYS those valid
# records, and the pull's own rebuild - finding the churn already applied - is an
# idempotent fixpoint that no-ops and records nothing, leaving an EMPTY ledger so
# the next verify false-flags all of that churn forever (the ascent field failure).
# No reset ordering can serialize the pull against unbounded concurrent boots.
#
# The reconciling prune keeps every marker whose recorded content STILL matches
# disk (a concurrent-boot record is exactly as good as one the pull's own rebuild
# would write, whenever it was written) and drops only the stale ones - so the
# race dissolves. Running it right after the sync (before three_way_pass, which
# never touches owned zones the markers cover) shrinks - it does not rely on -
# the window.
#
# Primary path: the artisan prune command (needs a working PHP), wrapped in the
# lock-retry loop because on a live box its OWN artisan boot contends with the
# concurrent auto-rebuild for the build lock (the ascent field failure: the prune
# boot died on a 30s lock timeout).
#
# Two failure classes need OPPOSITE handling:
#   * LOCK TIMEOUT after all retries - positive PROOF a concurrent boot is alive and
#     actively re-recording framework churn (a rebuild is holding the lock the whole
#     window). Wiping now would destroy exactly the valid, freshly-recorded markers
#     the reconciling prune exists to keep. So DO NOT WIPE - skip and heal next pull.
#   * NON-lock failure (PHP fatal, missing class, command not found) - artisan cannot
#     boot at all, so NOTHING is concurrently recording churn a wipe could lose. The
#     wholesale reset is the safe degradation THERE, and the subsequent forced rebuild
#     re-records from scratch on the freshly-synced pristine tree. (This degraded path
#     can in principle re-open the race, accepted: it only fires when PHP is broken,
#     where nothing is concurrently rebuilding.)
# =============================================================================
prune_store() {
    run_artisan_lock_retry "mutation-store prune" rsx:framework:prune_mutations
    local rc=$?

    if [ "$rc" -eq 0 ]; then
        return 0
    fi

    if [ "$rc" -eq "$LOCK_EXHAUSTED_RC" ]; then
        warn "mutation-store prune skipped: the box is continuously rebuilding (a concurrent manifest rebuild held the build lock across all 10 attempts)."
        say "  Not wiping the marker store. A lock timeout is proof a live boot is actively re-recording framework churn - the very records a wipe would destroy."
        say "  A stale marker (if any) can only make rsx:framework:verify OVER-flag (false FLAGS, never false-clean: verify still requires disk==shadow to pass); the next pull/prune reconciles it away. Continuing the update."
        return 0
    fi

    # NON-lock failure: artisan could not boot, so the wholesale reset is safe here.
    warn "could not prune mutation markers via artisan (PHP could not run the command - not lock contention); store reset wholesale. Safe here: if artisan cannot boot at all, nothing is concurrently recording the churn a wipe would lose."
    rm -f "$STORE_DIR/mutations.json" 2>/dev/null || true
    rm -rf "$STORE_DIR/shadow" 2>/dev/null || true
}

# =============================================================================
# write_history - changelog + prepend a dated section, newest-first
# =============================================================================
write_history() {
    # A --force repair on an already-current install (OLD_SHA == NEW_SHA) has no
    # changelog: a `from=X to=X` section is pure noise and the installed-commit
    # record is unchanged. Skip writing entirely. (Baseline runs carry an empty
    # OLD_SHA, so this never fires on a baseline - that path still records below.)
    if [ "$BASELINE" != true ] && [ "$OLD_SHA" = "$NEW_SHA" ]; then
        return 0
    fi

    local now human section
    now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    human="$(date -u '+%Y-%m-%d %H:%M UTC')"

    if [ "$BASELINE" = true ]; then
        section="$(cat <<EOF
## RSPADE-UPDATE $now from=BASELINE to=$NEW_SHA
Date: $human

History baseline established (no previously-installed release was recorded).
Installed rspade_system commit: $NEW_SHA

EOF
)"
        say ""
        say "History baseline established at ${NEW_SHA:0:12}."
    else
        local log stat
        # The byte-faithful OLD..NEW changelog is computed once by compute_framework_changelog
        # (each release commit's BODY carries the per-commit export bin/publish embedded); reuse
        # it here for the .dat record.
        compute_framework_changelog
        log="$FRAMEWORK_CHANGELOG"
        stat="$(git --git-dir="$CACHE" diff --stat "$OLD_SHA" "$NEW_SHA" 2>/dev/null | sed 's/^/  /' || true)"

        say ""
        say "=== Framework changelog (${OLD_SHA:0:12}..${NEW_SHA:0:12}) ==="
        say ""
        printf '%s\n' "$log"
        say ""

        section="$(cat <<EOF
## RSPADE-UPDATE $now from=$OLD_SHA to=$NEW_SHA
Date: $human

Changelog (${OLD_SHA:0:12}..${NEW_SHA:0:12}):
$log

Files changed:
$stat

EOF
)"
    fi

    mkdir -p "$(dirname "$HISTORY_FILE")"
    local tmp
    tmp="$(mktemp)"
    printf '%s\n' "$section" > "$tmp"
    if [ -f "$HISTORY_FILE" ]; then
        cat "$HISTORY_FILE" >> "$tmp"
    fi
    mv "$tmp" "$HISTORY_FILE"
    ok "Update recorded in rsx/resource/framework_update_history.dat"
}

# =============================================================================
# print_summary - working-tree changes for the developer; NO commit.
# =============================================================================
print_summary() {
    say ""
    say "=== Update complete ==="
    say ""
    if git -C "$PROJECT_ROOT" rev-parse --git-dir >/dev/null 2>&1; then
        local status
        status="$(git -C "$PROJECT_ROOT" status --porcelain 2>/dev/null | head -n 40)"
        if [ -n "$status" ]; then
            say "Working-tree changes:"
            printf '%s\n' "$status" | sed 's/^/  /'
            local total
            total="$(git -C "$PROJECT_ROOT" status --porcelain 2>/dev/null | wc -l | tr -d ' ')"
            if [ "$total" -gt 40 ]; then
                say "  ... and $((total - 40)) more"
            fi
            say ""
        fi
    fi
    if [ "$AUTO_COMMITTED" = true ]; then
        say "The framework's own changes (system/) were committed automatically, as the"
        say "pristine release. Any system/ modifications shown above are local framework"
        say "runtime churn (regenerated by every build; rsx:clean resets them); anything"
        say "else is app-side, left for your review."
    elif [ "$NO_COMMIT" = true ]; then
        say "--no-commit: the framework's system/ changes are staged but uncommitted."
        say "Commit them yourself (they must NOT ride an app commit - the system-guard hook"
        say "strips them): RSPADE_FRAMEWORK_COMMIT=1 git commit --no-verify -- system"
    else
        say "Review and commit these changes."
    fi

    # The reconciliation section prints this too - and is exactly the part that scrolls
    # away (a downstream box piped its pull through `tail -30` and lost the whole
    # RESTORED / deleted / CONFLICT summary while the durable copy sat on disk
    # unmentioned; Ascent, 2026-08-18). The final block is what an operator retains, so
    # the path is repeated here.
    if [ -n "${REPORT_FILE:-}" ] && [ -f "${REPORT_FILE:-}" ]; then
        say ""
        say "Full file-by-file reconciliation report: $REPORT_FILE"
    fi
    say ""
}

# =============================================================================
# compute_framework_changelog - set FRAMEWORK_CHANGELOG to THE canonical byte-faithful
# OLD..NEW changelog. ONE string, TWO consumers: the history .dat file (in full) and the
# framework-update commit message (size-capped, see commit_system_update).
#
# Format, per distribution commit: a header line `<sha> <date> <release subject>` then the
# commit BODY. The two halves are BOTH load-bearing:
#
#   %b   is where the substance lives. bin/publish embeds every underlying monorepo commit's
#        FULL message - subject and body - into the release commit's body, so a single
#        distribution commit's %b is the complete rationale for everything in that release
#        (48KB for an 18-commit release, measured). This is why the changelog does not need
#        to "reach through" the release squash: the squash already carries the text.
#   %s   is the release-boundary marker, and the FLOOR. A release subject on its own is
#        nearly content-free ("RSpade framework release X..Y (N commits)"), which is exactly
#        why the commit message used to be useless - it had ONLY this. But it must stay:
#        when a range spans several releases the header lines are what separate the blocks,
#        and a commit with NO body (possible upstream, and true of every test fixture)
#        would otherwise contribute literally nothing.
#
# Agent attribution noise is filtered out - this text is the customer-facing audit record in
# a downstream app's history, not a record of who typed it.
#
# Memoized (safe to call more than once). Empty on a baseline or up-to-date run. Reads only
# the cached distribution repo - never the zone - so it is valid at the pristine pre-rebuild
# moment.
# =============================================================================
compute_framework_changelog() {
    [ -n "${FRAMEWORK_CHANGELOG_DONE:-}" ] && return 0
    FRAMEWORK_CHANGELOG_DONE=1
    FRAMEWORK_CHANGELOG=""
    { [ "$BASELINE" = true ] || [ "$OLD_SHA" = "$NEW_SHA" ]; } && return 0
    FRAMEWORK_CHANGELOG="$(git --git-dir="$CACHE" log --date=short \
        --pretty=format:'%h %ad %s%n%n%b' "$OLD_SHA..$NEW_SHA" 2>/dev/null \
        | grep -vE 'Claude Code|Co-Authored-By:' || true)"
}

# =============================================================================
# changelog_for_commit_message - FRAMEWORK_CHANGELOG, capped for a commit message.
#
# The history .dat always gets the changelog IN FULL and is the complete record. A commit
# message is read in a terminal, a PR view and a hosting UI, so it takes a size cap - but a
# cap that SAYS what it dropped and where the rest is. Silent truncation would defeat the
# entire point of putting the text here.
#
# Cap on BYTES, not commit count: 100 subjects was fine, 100 full bodies is not, and the
# meaningful cost is the size of the message rather than how many commits produced it. The
# largest release measured is 48KB, so 64KB passes real ranges through untouched and only
# engages on an unusually long catch-up. Truncation lands on a LINE boundary - half a
# sentence reads as corruption.
# =============================================================================
CHANGELOG_COMMIT_MAX_BYTES=65536
changelog_for_commit_message() {
    compute_framework_changelog
    local log="$FRAMEWORK_CHANGELOG"
    [ -n "$log" ] || { printf ''; return 0; }

    local size
    size="$(printf '%s' "$log" | wc -c)"
    if [ "$size" -le "$CHANGELOG_COMMIT_MAX_BYTES" ]; then
        printf '%s' "$log"
        return 0
    fi

    local kept dropped
    kept="$(printf '%s' "$log" | head -c "$CHANGELOG_COMMIT_MAX_BYTES" | sed '$d')"
    dropped=$(( size - $(printf '%s' "$kept" | wc -c) ))
    printf '%s\n\n[... %s more bytes of changelog truncated from this commit message.\n     The COMPLETE text for this range is in rsx/resource/framework_update_history.dat ...]' \
        "$kept" "$dropped"
}

# =============================================================================
# enter_maintenance - raise the maintenance window just before the destructive passes.
#
# ONE implementation, in bin/maintenance-mode.sh (the same script
# `php artisan rsx:maintenance:enable` runs): flag + reason, task kill-all, then stop every
# supervised service in dependency order. THAT SCRIPT OWNS THE SERVICE LIST - it is
# deliberately not repeated here, so adding a service (rsx-lockd was the last one) is a
# one-file change and this comment cannot go stale. cleanup() (the EXIT/INT/TERM trap) lifts
# it all, even on Ctrl-C. maintenance-mode.sh ships in the same bin/ owned-zone sync as this
# updater, so the two are always the same generation.
# =============================================================================
enter_maintenance() {
    # --no-service-control opts out of the whole window (the operator manages it, or a test
    # fixture runs against a synthetic tree whose artisan does not yet honor the override).
    [ "$SERVICE_CONTROL" = true ] || return 0

    local script="$SYSTEM_DIR/bin/maintenance-mode.sh"
    [ -f "$script" ] || die "Maintenance script missing: $script (the framework tree is broken)."

    bash "$script" enable --reason="framework update in progress" || die "Failed to enter maintenance mode."
    MAINT_ACTIVE=true
    say "Maintenance window raised (web 503; automated task runners refused) until the update completes."
}

# =============================================================================
# ensure_system_not_ignored - system/ must NEVER be gitignored downstream
# (operator ruling 2026-08-03). Ignore rules over the vendored tree are the
# root of the tracked-but-ignored failure class (see ensure_updater_bootstrap)
# and would mask drift from --check-foreign-changes; commit hygiene is the
# pre-commit hook's job, not an ignore rule's. Runs every updater invocation:
# strips any root-.gitignore line that ignores system/ wholesale, then warns
# if some OTHER rule (global/core.excludesFile) still ignores the tree.
# =============================================================================
ensure_system_not_ignored() {
    local gi="$PROJECT_ROOT/.gitignore"
    if [ -f "$gi" ]; then
        # Lines that ignore the whole vendored tree: system, /system, system/,
        # /system/ (with optional trailing whitespace). Nothing else is touched.
        if grep -qE '^[[:space:]]*/?system/?[[:space:]]*$' "$gi"; then
            local tmp
            tmp="$(mktemp)"
            grep -vE '^[[:space:]]*/?system/?[[:space:]]*$' "$gi" > "$tmp" && mv "$tmp" "$gi"
            warn "Removed a system/ ignore line from the root .gitignore (system/ must never be ignored; the pre-commit hook owns commit hygiene)."
            git -C "$PROJECT_ROOT" add -- .gitignore >/dev/null 2>&1 || true
        fi
    fi
    # A rule elsewhere (global excludes, .git/info/exclude) may still hide the
    # tree - surface it, do not silently fight it.
    if git -C "$PROJECT_ROOT" check-ignore -q system/artisan 2>/dev/null; then
        warn "system/ is STILL ignored by a rule outside the root .gitignore:"
        git -C "$PROJECT_ROOT" check-ignore -v system/artisan 2>/dev/null | sed 's/^/  /'
        say "  Remove that rule - an ignored system/ masks drift detection and re-opens"
        say "  the tracked-but-ignored bootstrap failure class."
    fi
}

# =============================================================================
# migrate_legacy_override_hygiene - one-time (idempotent) migration off the OLD
# commit model, distributed to the whole fleet via the updater. The previous
# updater HID framework runtime churn by (a) setting git skip-worktree on the
# system/app/RSpade zone and (b) adding a *.php.upstream gitignore line. The new
# model has commit_system_update state the whole system/ tree deliberately - as the
# RELEASE, with the class-override pair normalized out of the commit (see its header;
# hiding devices are neither needed nor sufficient for that) - so both MUST go:
#   (a) skip-worktree MUST be cleared and NEVER reapplied - `git add -f -A --
#       system` in commit_system_update honors -f over gitignore but NOT over
#       skip-worktree, so a left-set bit silently drops the override zone from
#       the commit. This is the exact piece the sandbox reference missed (a
#       fresh repo never had the bit set).
#   (b) the *.php.upstream ignore line is removed so the sidecars are VISIBLE to
#       git status / --check-foreign-changes - they are always-local churn and the
#       operator is entitled to see them. Visible is not the same as committed:
#       commit_system_update normalizes them out of the commit, so a sidecar shows
#       up exactly where it belongs, as unstaged working-tree state.
# Both are no-ops on a fresh install (nothing set / no line present).
# =============================================================================
migrate_legacy_override_hygiene() {
    git -C "$PROJECT_ROOT" rev-parse --git-dir >/dev/null 2>&1 || return 0

    # (a) Clear skip-worktree on the override zone (the only path it was ever set
    # on). Idempotent; harmless when none are set.
    git -C "$PROJECT_ROOT" ls-files -z system/app/RSpade 2>/dev/null \
        | xargs -0 -r git -C "$PROJECT_ROOT" update-index --no-skip-worktree 2>/dev/null || true

    # (b) Strip the legacy *.php.upstream ignore line from the root .gitignore.
    local gi="$PROJECT_ROOT/.gitignore"
    if [ -f "$gi" ] && grep -qxF '*.php.upstream' "$gi"; then
        local tmp
        tmp="$(mktemp)"
        grep -vxF '*.php.upstream' "$gi" > "$tmp" && mv "$tmp" "$gi"
        git -C "$PROJECT_ROOT" add -- .gitignore >/dev/null 2>&1 || true
        warn "Removed the legacy *.php.upstream ignore line (override sidecars are now committed as framework churn)."
    fi
}

# =============================================================================
# check_foreign_changes - local-only drift check (no gates, no network, no PHP,
# no changes). Prints uncommitted changes under system/ and exits 1 if any, 0 if
# pristine. The ONE shared "did anything change under system/ that shouldn't
# have" probe; rsx:clean --reset-system shells out to it.
# =============================================================================
check_foreign_changes() {
    if ! git -C "$PROJECT_ROOT" rev-parse --git-dir >/dev/null 2>&1; then
        warn "Not a git repository; cannot check system/ drift."
        return 0
    fi
    local drift
    drift="$(git -C "$PROJECT_ROOT" status --porcelain -- system 2>/dev/null)"
    if [ -z "$drift" ]; then
        ok "system/ is pristine (matches the last framework commit)."
        return 0
    fi
    say "Uncommitted changes under system/ (foreign, or local framework runtime churn -"
    say "regenerate-on-demand state no commit ever captures):"
    printf '%s\n' "$drift" | sed 's/^/  /'
    return 1
}

# =============================================================================
# commit_system_update - the updater's OWN commit of the framework changes it just
# made. Runs AFTER the sync + history write and BEFORE the rebuild, so what lands in
# history is the PRISTINE DISTRIBUTION STATE as synced (see COMMIT MODEL at the top):
# no rebuild-authored class-override churn, which is always-local
# regenerate-on-demand state the ledger authorizes for the tamper gate.
#
# "PRISTINE" IS NOW ENFORCED, NOT ASSUMED. The old claim held only for churn THIS
# run's rebuild would author; churn the PREVIOUS run left on disk (the class-override
# pair `X.php` gone + `X.php.upstream` present) was staged verbatim and published as
# framework content. The tree is therefore assembled deliberately - see the block
# above the index build below - and asserted against the release inventory before it
# is committed: THE COMMITTED system/ TREE IS THE RELEASE, WHICHEVER BOX RAN THE PULL.
# Pristine by construction is also why the commit needs no authorization gate of its
# own - the tamper gate's real job is refusing to OVERWRITE hand edits at sync time.
# Scoped exactly as `git commit -- <paths>` was (everything unnamed comes from HEAD),
# so in-flight app work - staged or not - is never swept, and built in a THROWAWAY
# index so the developer's own index is not touched at all. No hook can fire on the
# plumbing path (the system-guard hook was bypassed deliberately before, via
# --no-verify + RSPADE_FRAMEWORK_COMMIT=1). Failure is LOUD but non-fatal: the sync
# itself succeeded; the changes are left staged with manual-commit instructions and
# the armed-window warning stands. On by default; --no-commit skips (and keeps the
# warning armed).
# =============================================================================
# The throwaway index the framework-update commit's tree is built in (see
# commit_system_update). Global so __commit_index_die can clean it up from anywhere.
COMMIT_TMP_INDEX=""

# __unstage_system_churn - put the DEVELOPER'S index back in step with HEAD for
# system/, once the framework-update commit has landed (or provably has nothing to
# land). The staging add is a means to an end - proving the index is writable - and
# leaving its entries behind would park a staged DELETION of a framework file in an
# operator's index, where the next app commit has to strip it again. Unstaged is
# where this box's override churn belongs: ` D X.php` plus an untracked
# `X.php.upstream`, the ordinary steady state of any box carrying an override.
#
# Never fatal: the commit already succeeded, and a stale index entry is cosmetic
# next to it.
__unstage_system_churn() {
    local parent="$1"
    [ -n "$parent" ] || return 0
    run_git_retry "restoring the index after the framework-update commit" reset -q -- system >/dev/null 2>&1 \
        || warn "Could not restore the index after the commit; run: git reset -- system"
}

# Abort the update without leaving a throwaway index behind - and without leaking
# GIT_INDEX_FILE into the exit trap, where it would silently re-target any git call
# cleanup() makes at an index that is about to be deleted.
__commit_index_die() {
    unset GIT_INDEX_FILE
    [ -n "$COMMIT_TMP_INDEX" ] && rm -f "$COMMIT_TMP_INDEX" 2>/dev/null
    COMMIT_TMP_INDEX=""
    die "$1"
}

# =============================================================================
# normalize_commit_index - make the throwaway index BE the release, leaving the
# WORKING TREE untouched. Two passes, same rule from two directions:
#
#   PASS 1  the class-override rename comes OUT of the staged tree (below).
#   PASS 2  EVERY tracked path under system/ the release inventory does not list is
#           untracked from the commit (__untrack_foreign_paths_in_index).
#
# PASS 1 - the class-override pair.
#
# A class override (rsx:man class_override) moves the framework's `X.<ext>` aside to
# `X.<ext>.upstream` and lets the app's copy answer to the class name. That pair is
# ALWAYS-LOCAL, regenerate-on-demand state: it is authored by this box's manifest
# build, it differs per box, and no commit anywhere may record it. Committed, it
# publishes one box's override set as framework content - which is exactly what
# happened: `R100 X.php -> X.php.upstream` entered shared history, the real framework
# filename stopped being tracked at all, upstream changes began arriving INSIDE the
# sidecar, and the next box's pull aborted on its own untracked sidecar colliding with
# the incoming tracked blob (field report, 2026-08-18).
#
# For every sidecar on disk: drop the sidecar path from the index, and stage the
# RELEASE's copy of the real filename in its place. The release checkout is the
# authority - the sidecar on disk belongs to whatever release was installed when the
# rename happened, and HEAD may not carry the real filename at all.
#
# THIS IS ALSO THE DECONTAMINATION PATH. On a box whose HEAD already tracks sidecars
# (seven paths went fleet-wide before this existed), the same two operations record
# "delete X.php.upstream / add X.php", so the first post-fix pull that commits
# anything converges the shared tree with no hand surgery and no one-off commit.
#
# The working tree is deliberately NOT repaired here: this box is RUNNING on that
# override pair, and re-creating `X.php` beside a live `X.php.upstream` corrupts it
# (the same reason three_way_pass refuses to restore that one absence).
# =============================================================================
normalize_commit_index() {
    local sidecar rel base blob mode normalized=0

    while IFS= read -r sidecar; do
        [ -n "$sidecar" ] || continue
        rel="${sidecar#$SYSTEM_DIR/}"
        base="${rel%.upstream}"

        # --force-remove drops the path from the INDEX whether it arrived from HEAD (a
        # contaminated baseline) or from the staging add; it never touches the disk.
        git -C "$PROJECT_ROOT" update-index --force-remove -- "system/$rel" >/dev/null 2>&1 \
            || __commit_index_die "Failed to drop a class-override sidecar from the commit index: system/$rel"

        # No release copy => upstream does not ship this file (a sidecar left over from
        # an older release). Dropping it is the whole job; there is nothing to restore.
        [ -f "$NEW_TREE/$base" ] || continue

        mode=100644
        [ -x "$NEW_TREE/$base" ] && mode=100755
        # --path makes the blob go through the same attribute/filter path a plain
        # `git add system/<base>` would use, so byte fidelity matches the rest of the
        # tree (the release ships `* -text` precisely so nothing is normalized).
        blob="$(git -C "$PROJECT_ROOT" hash-object -w --path="system/$base" -- "$NEW_TREE/$base" 2>/dev/null)"
        [ -n "$blob" ] \
            || __commit_index_die "Failed to hash the release copy of system/$base while normalizing a class override."
        git -C "$PROJECT_ROOT" update-index --add --cacheinfo "$mode,$blob,system/$base" >/dev/null 2>&1 \
            || __commit_index_die "Failed to stage the release copy of system/$base while normalizing a class override."

        normalized=$((normalized + 1))
    done < <(find "$SYSTEM_DIR" -type f -name '*.upstream' \
                  -not -path "$SYSTEM_DIR/.git/*" -not -path "$SYSTEM_DIR/storage/*" 2>/dev/null | LC_ALL=C sort)

    if [ "$normalized" -gt 0 ]; then
        say "  $normalized class-override sidecar(s) normalized out of the commit (the working tree keeps its override pairs)."
    fi

    __untrack_foreign_paths_in_index
}

# =============================================================================
# __untrack_foreign_paths_in_index - PASS 2: drop every tracked path under system/
# that the release inventory does not list, from the COMMIT INDEX only.
#
# THE RULING (owner, 2026-08-18): "system gets rsynced from the framework
# metaphorically with --delete - any tracked files in the destination not in the
# framework distribution within 'owned' directories must be untracked and deleted
# from git." system/ is a vendored, machine-owned, READ-ONLY tree: the release IS the
# path set, and there is no such thing as a local addition to it that history may
# carry. So there is no escape hatch and no allowlist - a path the inventory does not
# name does not belong in the commit, full stop.
#
# What that removes in practice is machine-generated box state that `add -f -A --
# system` swept in and that history then published fleet-wide: fossil CLI-test
# storage trees, build/cache/route_patterns/*, bootstrap/cache/*.php,
# .env.replaced_by_healer - 7,480 paths on one field box.
#
# THE WORKING TREE IS NOT TOUCHED (same discipline as the sidecar pass): those files
# are live local state this box may still be running on. Untracking is a statement
# about HISTORY. The disk-side consequence is deliberate and stated in the ruling: a
# peer's next `rsx:git pull` reset (clean -fdq) removes what is no longer tracked.
#
# THE EXCLUSIONS ARE THE ASSERTION'S EXCLUSIONS, by construction - the two functions
# must agree, so both go through __release_partition_report (the inventory file
# itself, .env, storage/**).
#
# A missing/unreadable inventory untracks NOTHING - the same posture the assertion
# and check_tree_complete take: never refuse (or mutilate) a good update over a
# missing tool.
# =============================================================================
__untrack_foreign_paths_in_index() {
    local manifest="$SYSTEM_DIR/.rspade-release.json"
    [ -f "$manifest" ] || return 0
    command -v php >/dev/null 2>&1 || return 0

    # NUL-separated list for `update-index --stdin -z`; the human summary comes back
    # on stdout (count first, then the first ten paths).
    local list_file="${COMMIT_TMP_INDEX:-$TMP_ROOT/framework-commit-index}.foreign"
    rm -f "$list_file"

    local rep
    rep="$(git -C "$PROJECT_ROOT" ls-files -z -- system 2>/dev/null | php -r '
        $manifest = $argv[1];
        $out      = $argv[2];
        $root     = $argv[3];
        $m = json_decode(@file_get_contents($manifest), true);
        if (!is_array($m) || !is_array($m["files"] ?? null)) { exit(0); }

        // A path the inventory CANNOT list carries no information by being absent
        // from it. The publish inventory enumerates the index but drops anything
        // that does not resolve to a regular file ([ -f ]) - so a tracked symlink
        // to a directory (system/rsx -> ../rsx, the app-tree link base_path()
        // depends on) or a broken link is structurally uninventoriable and must
        // NEVER be treated as foreign. Untracking system/rsx let the proxy clean
        // delete it and killed every manifest build downstream (2026-08-18).
        $skip = function ($p) use ($root) {
            if ($p === "" || $p === ".rspade-release.json" || $p === ".env") { return true; }
            if (strncmp($p, "storage/", 8) === 0) { return true; }
            $abs = $root . "/system/" . $p;
            return is_link($abs) && !is_file($abs);
        };

        $foreign = [];
        foreach (explode("\0", stream_get_contents(STDIN)) as $p) {
            if (strncmp($p, "system/", 7) !== 0) { continue; }
            $rel = substr($p, 7);
            if ($skip($rel)) { continue; }
            if (isset($m["files"][$rel])) { continue; }
            $foreign[] = $p;
        }
        sort($foreign);
        file_put_contents($out, $foreign ? implode("\0", $foreign) . "\0" : "");
        echo count($foreign), "\n";
        foreach (array_slice($foreign, 0, 10) as $p) { echo "  ", $p, "\n"; }
    ' "$manifest" "$list_file" "$PROJECT_ROOT" 2>/dev/null)"

    local n
    n="$(printf '%s\n' "$rep" | head -n1)"
    case "$n" in ''|*[!0-9]*) rm -f "$list_file"; return 0 ;; esac
    if [ "$n" -eq 0 ]; then
        rm -f "$list_file"
        return 0
    fi

    # ONE update-index call for the whole set: a per-path loop forks thousands of
    # times on a contaminated box.
    git -C "$PROJECT_ROOT" update-index --force-remove -z --stdin < "$list_file" >/dev/null 2>&1 \
        || { rm -f "$list_file"; __commit_index_die "Failed to untrack foreign path(s) from the commit index."; }
    rm -f "$list_file"

    say "  $n path(s) under system/ are not in the release inventory - untracked from the commit"
    say "  (the working tree keeps them; system/ in history is the release, nothing else):"
    printf '%s\n' "$rep" | tail -n +2 | sed 's/^/  /'
    [ "$n" -gt 10 ] && say "    ... and $((n - 10)) more"
    return 0
}

# =============================================================================
# assert_index_matches_release - the property the commit must satisfy: THE COMMITTED
# system/ TREE IS THE RELEASE, INDEPENDENT OF WHICH BOX RAN THE PULL.
#
# Compares the path set staged in the throwaway index against .rspade-release.json,
# the per-release pristine inventory bin/publish writes and rsx:framework:verify
# reads. Path-set equality, not content hashes: the sync passes already reconcile
# CONTENT file by file (and a preserved local edit is a reported, legitimate outcome),
# while the failure this exists to catch is structural - a path that vanished or a
# path that must never appear.
#
# DELIBERATE EXCLUSIONS, applied to BOTH sides, each for a stated reason:
#   .rspade-release.json  the inventory never lists itself
#   .env                  a deployment symlink, never framework content (the same
#                         exclusion __list_nonowned makes)
#   storage/**            volatile state reachable by neither sync pass: an inventory
#                         entry there is an advisory orphan and never a failure
#                         (2026-08-11), and the LIVE maintenance flag under it is
#                         excluded from the commit on purpose (see the P0 above)
#
# ALL THREE findings are FATAL - the property is path-set EQUALITY, in both directions:
#   MISSING  a release path the commit does not carry (the `D X.php` half)
#   SIDECAR  a `*.upstream` path the commit DOES carry (the `A X.php.upstream` half)
#   EXTRA    a tracked path under system/ the release does not ship
#
# EXTRA WAS ONCE REPORTED-BUT-TOLERATED, on the theory that a "local addition kept"
# by the three-way pass had to be allowed into history. THE OWNER RULED OTHERWISE
# (2026-08-18): "system gets rsynced from the framework metaphorically with --delete -
# any tracked files in the destination not in the framework distribution within
# 'owned' directories must be untracked and deleted from git." system/ is a vendored,
# machine-owned, read-only tree; a local addition to it is not a thing history may
# carry, so there is no escape hatch and no allowlist. normalize_commit_index
# untracks every such path BEFORE this runs, which makes EXTRA empty by construction -
# and therefore a non-empty EXTRA now means the normalization itself failed, which is
# exactly the kind of silent structural fault this assertion exists to stop.
#
# Prints its findings and returns 1 when the run must refuse. An inventory that
# cannot be read (no php, unparseable json) asserts nothing - the same posture
# check_tree_complete takes, since the alternative is refusing a good update over a
# missing tool.
# =============================================================================
# =============================================================================
# __release_partition_report <manifest-path>
#
# Reads a NUL-separated path list on stdin (a git ls-files / ls-tree stream) and
# partitions it against the release inventory, printing:
#
#   MISSING <n> / SIDECAR <n> / EXTRA <n>   each followed by up to 40 indented paths
#
# ONE implementation, shared by the commit assertion and the committed-baseline check,
# so the two can never drift apart on the exclusion list (see the assertion header).
# Silent (prints nothing) when the inventory cannot be read.
# =============================================================================
__release_partition_report() {
    local manifest="$1"
    php -r '
        $manifest = $argv[1];
        $root     = $argv[2];
        $m = json_decode(@file_get_contents($manifest), true);
        if (!is_array($m) || !is_array($m["files"] ?? null)) { exit(0); }

        // Same exemption as __untrack_foreign_paths_in_index: a tracked path that
        // does not resolve to a regular file (dir-symlink like system/rsx, broken
        // link) can never appear in the inventory - the publish [ -f ] filter
        // drops it - so its absence is not a finding. Both functions MUST share
        // this rule or the assertion would refuse what the untracking preserves.
        $skip = function ($p) use ($root) {
            if ($p === "" || $p === ".rspade-release.json" || $p === ".env") { return true; }
            if (strncmp($p, "storage/", 8) === 0) { return true; }
            $abs = $root . "/system/" . $p;
            return is_link($abs) && !is_file($abs);
        };

        $staged = [];
        foreach (explode("\0", stream_get_contents(STDIN)) as $p) {
            if (strncmp($p, "system/", 7) !== 0) { continue; }
            $p = substr($p, 7);
            if ($skip($p)) { continue; }
            $staged[$p] = true;
        }

        $missing = []; $sidecar = []; $extra = [];
        foreach (array_keys($m["files"]) as $rel) {
            if ($skip($rel)) { continue; }
            if (!isset($staged[$rel])) { $missing[] = $rel; }
        }
        foreach (array_keys($staged) as $rel) {
            if (substr($rel, -9) === ".upstream") { $sidecar[] = $rel; continue; }
            if (!isset($m["files"][$rel])) { $extra[] = $rel; }
        }

        foreach ([["MISSING", $missing], ["SIDECAR", $sidecar], ["EXTRA", $extra]] as [$label, $set]) {
            if (!$set) { continue; }
            sort($set);
            echo $label, " ", count($set), "\n";
            foreach (array_slice($set, 0, 40) as $p) { echo "  ", $p, "\n"; }
        }
    ' "$manifest" "$PROJECT_ROOT" 2>/dev/null
}

assert_index_matches_release() {
    local manifest="$SYSTEM_DIR/.rspade-release.json"
    [ -f "$manifest" ] || return 0
    command -v php >/dev/null 2>&1 || return 0

    local out
    out="$(git -C "$PROJECT_ROOT" ls-files -z -- system 2>/dev/null | __release_partition_report "$manifest")"

    local missing_n sidecar_n extra_n
    missing_n="$(printf '%s\n' "$out" | sed -n 's/^MISSING \([0-9]*\)$/\1/p')"
    sidecar_n="$(printf '%s\n' "$out" | sed -n 's/^SIDECAR \([0-9]*\)$/\1/p')"
    extra_n="$(printf '%s\n' "$out" | sed -n 's/^EXTRA \([0-9]*\)$/\1/p')"

    if [ -z "$missing_n" ] && [ -z "$sidecar_n" ] && [ -z "$extra_n" ]; then
        return 0
    fi

    err ""
    err "The framework-update commit would NOT match the release inventory - refusing to commit."
    err ""
    if [ -n "$missing_n" ]; then
        err "  $missing_n release file(s) MISSING from the commit:"
        printf '%s\n' "$out" | sed -n '/^MISSING /,/^\(SIDECAR\|EXTRA\) /p' | grep '^  ' | head -40 >&2
    fi
    if [ -n "$sidecar_n" ]; then
        err "  $sidecar_n class-override sidecar(s) IN the commit:"
        printf '%s\n' "$out" | sed -n '/^SIDECAR /,/^EXTRA /p' | grep '^  ' | head -40 >&2
    fi
    if [ -n "$extra_n" ]; then
        err "  $extra_n path(s) IN the commit that the release does not ship:"
        printf '%s\n' "$out" | sed -n '/^EXTRA /,$p' | grep '^  ' | head -40 >&2
        err "  (normalize_commit_index should have untracked every one of these - that it did not"
        err "  means the normalization failed, not that the paths are acceptable.)"
    fi
    err ""
    err "  system/ in history must equal the release, whichever box ran the pull. Committing"
    err "  this tree would publish THIS box's local state as framework content, and every"
    err "  other box would then collide with it on its next pull."
    err ""

    return 1
}

# =============================================================================
# committed_baseline_is_dirty - does HEAD's system/ tree already equal the release?
#
# The same path-set equality the commit assertion enforces, asked of HEAD instead of a
# staged index. It exists because "up to date" is a claim about the MARKER, and a box
# whose marker matches never reaches commit_system_update at all - so a contaminated
# baseline (tracked sidecars, tracked foreign paths, or a release file HEAD lost) sat
# there until the next release happened to come along. On the field box that was 7
# sidecars plus 7,480 foreign paths, indefinitely.
#
# Returns 0 (dirty, repair it) / 1 (clean, or nothing to compare against). Prints the
# verdict when dirty; silent when clean. Not a gate: an unreadable inventory, no php
# and no HEAD all answer "clean", because refusing to be up to date over a missing tool
# is the wrong failure.
# =============================================================================
committed_baseline_is_dirty() {
    local manifest="$SYSTEM_DIR/.rspade-release.json"
    [ -f "$manifest" ] || return 1
    command -v php >/dev/null 2>&1 || return 1
    git -C "$PROJECT_ROOT" rev-parse --git-dir >/dev/null 2>&1 || return 1
    git -C "$PROJECT_ROOT" rev-parse --verify -q HEAD >/dev/null 2>&1 || return 1

    local out
    out="$(git -C "$PROJECT_ROOT" ls-tree -r -z --name-only HEAD -- system 2>/dev/null \
        | __release_partition_report "$manifest")"
    [ -n "$out" ] || return 1

    local missing_n sidecar_n extra_n
    missing_n="$(printf '%s\n' "$out" | sed -n 's/^MISSING \([0-9]*\)$/\1/p')"
    sidecar_n="$(printf '%s\n' "$out" | sed -n 's/^SIDECAR \([0-9]*\)$/\1/p')"
    extra_n="$(printf '%s\n' "$out" | sed -n 's/^EXTRA \([0-9]*\)$/\1/p')"
    : "${missing_n:=0}" "${sidecar_n:=0}" "${extra_n:=0}"

    if [ "$missing_n" -eq 0 ] && [ "$sidecar_n" -eq 0 ] && [ "$extra_n" -eq 0 ]; then
        return 1
    fi

    say "The committed system/ tree is NOT the release: $missing_n missing, $sidecar_n class-override"
    say "sidecar(s), $extra_n path(s) the release does not ship."
    return 0
}

# MODES
#   normal      the ordinary post-sync commit of a release that was just installed.
#   conversion  the submodule-era one-time vendoring commit.
#   repair      COMMIT-ONLY BASELINE REPAIR, from main()'s up-to-date branch. Nothing
#               was synced, nothing will be rebuilt and no maintenance window is
#               needed: the only writes are git object/ref writes plus the throwaway
#               index, and the only inputs are ones ensure_cache/resolve_old already
#               produced (NEW_SHA, NEW_TREE for the sidecar restore, TMP_ROOT for the
#               index). It skips the WORKING-TREE dirty check, because the state it
#               repairs is in HEAD: on a box whose disk is pristine there is nothing
#               dirty to see, and the tree-vs-HEAD comparison below is what still
#               guarantees no empty commit is written.
commit_system_update() {
    local mode="${1:-normal}"   # normal | conversion | repair

    if [ "$NO_COMMIT" = true ]; then
        warn "--no-commit: framework changes left uncommitted for manual review."
        # SYNCED_UNCOMMITTED stays ARMED: this is the state the warning exists for,
        # deliberately entered.
        return 0
    fi
    if ! git -C "$PROJECT_ROOT" rev-parse --git-dir >/dev/null 2>&1; then
        warn "Not a git repository; skipping the framework auto-commit."
        # Nothing can be committed here and no rsx:clean reset can revert anything
        # (there is no HEAD to reset to), so the armed-window warning does not apply.
        SYNCED_UNCOMMITTED=false
        return 0
    fi

    local history_rel="rsx/resource/framework_update_history.dat"

    # IN-FLIGHT STATE MUST NEVER ENTER THE COMMIT.
    #
    # This function runs INSIDE the maintenance window - the window spans the whole
    # update and is lifted only by the EXIT trap, so it is up here whether the commit
    # precedes or follows the rebuild. That means the LIVE maintenance flag is sitting
    # on disk right now (the P0 below is unaffected by the ordering). On a
    # downstream app where system/storage/ is tracked (the pre-relocation layout),
    # `add -f -A -- system` sweeps it into the framework-update commit, which is then
    # pushed: every box that pulls, and every fresh clone, materializes a maintenance
    # flag and answers 503 with no update running anywhere. Deterministic on every
    # successful update; it cost two 13-hour outages before it was traced.
    #
    # A .gitignore entry CANNOT prevent this - `-f` exists precisely to override
    # ignore rules, and removing it would break the app-side `/system/` ignore case it
    # was added for. The exclusion has to be an explicit pathspec, applied to all
    # three places that must agree: the dirty check, the staging add, and the commit
    # pathspec.
    #
    # THE EXCLUSION IS CONDITIONAL ON THE FILE BEING ON DISK, and that is the whole
    # trick. `git commit -- <paths>` takes WORKING-TREE content for the named paths,
    # so:
    #
    #   pre-relocation  - the live flag IS at system/storage/... during the window.
    #                     The exclusion engages and the flag stays out of the commit.
    #   post-relocation - the live flag is at <project>/storage/..., outside the
    #                     `system` pathspec entirely, so nothing matches on disk. The
    #                     exclusion stays OFF, which lets the commit record the
    #                     DELETION of any flag an earlier (buggy) release already
    #                     committed. The damage purges itself on the first pull after
    #                     a box relocates.
    #
    # An unconditional exclusion would look safer and would in fact freeze the bad
    # file in HEAD forever, which is the outage that has to be undone.
    local flag_dir="$PROJECT_ROOT/system/storage/rsx-framework"
    local -a transient_excludes=()
    local _flag
    for _flag in "$flag_dir"/.maintenance.mode.*; do
        # No nullglob here: an unmatched pattern arrives literally and fails -e.
        [ -e "$_flag" ] || continue
        transient_excludes=(':(exclude)system/storage/rsx-framework/.maintenance.mode.*')
        break
    done

    # Pathspec list: the history file joins only when it actually exists (on disk
    # or in HEAD) - `git commit -- <path>` ERRORS on a pathspec matching nothing
    # (e.g. a --force repair on a repo that never recorded history).
    local -a paths=(system ${transient_excludes[@]+"${transient_excludes[@]}"})
    if [ -f "$PROJECT_ROOT/$history_rel" ] \
        || git -C "$PROJECT_ROOT" cat-file -e "HEAD:$history_rel" 2>/dev/null; then
        paths+=("$history_rel")
    fi

    # The dirty check carries the same exclusion, so a run whose ONLY change is the
    # live flag takes the clean no-op path below instead of reaching a commit that
    # would then have nothing to record.
    local dirty
    dirty="$(git -C "$PROJECT_ROOT" status --porcelain -- "${paths[@]}" 2>/dev/null)"
    if [ -z "$dirty" ] && [ "$mode" != repair ]; then
        ok "No framework changes to commit."
        # Nothing differs from HEAD: the release IS what history holds, so the tree is
        # not in the synced-but-uncommitted state.
        SYNCED_UNCOMMITTED=false
        return 0
    fi

    # Stage the framework paths in the DEVELOPER'S index. This is no longer what the
    # commit is built from (that is the throwaway index below) - it is kept for two
    # reasons: it is the load-bearing index WRITE that proves the repository's index is
    # usable before anything is committed (a locked index must abort the run here, not
    # halfway through), and it leaves the hand-off state the failure path documents.
    # -f overrides any ignore rule (e.g. an app-side /system/ gitignore line suppressing
    # NEW untracked framework files); -A picks up deletions. The index is put back in
    # step with HEAD once the commit lands (__unstage_system_churn).
    # Lock-retried: staging is an index WRITE, so it is exactly what a concurrent
    # index.lock holder blocks. Exhausting the retries is FATAL - an unstaged tree
    # cannot produce the framework-update commit, and continuing to the commit
    # attempt would only fail again, leaving the release on disk and out of history.
    # NOTE the `|| stage_rc=$?` shape rather than `if ! ...; then rc=$?`: after `!`
    # the status is the NEGATED one (always 0), so the exhausted-lock code would be
    # invisible and this would silently fall through to the commit.
    local stage_rc=0
    run_git_retry "staging system/" add -f -A -- system ${transient_excludes[@]+"${transient_excludes[@]}"} || stage_rc=$?
    if [ "$stage_rc" -eq "$GIT_LOCK_EXHAUSTED_RC" ]; then
        git_lock_fatal "staging system/ for the framework-update commit"
    elif [ "$stage_rc" -ne 0 ]; then
        warn "git add of system/ reported an issue (continuing to commit attempt)."
    fi
    run_git_retry "staging the update history" add -A -- "$history_rel" >/dev/null 2>&1 || true

    # ---- Build the aggregated commit message from the upstream changelog. ----
    local subject body="" n=0
    if [ "$mode" = "repair" ]; then
        local rid
        rid="$(__release_id_of_file "$RELEASE_MANIFEST" 2>/dev/null || true)"
        subject="Framework baseline repair ${rid:-${NEW_SHA:0:12}}: system/ re-stated as the release"
        body="The installed release was already current; only the COMMITTED system/ tree was wrong.
This commit restates it as the release inventory (${NEW_SHA:0:12}) - class-override sidecars
normalized out, paths the release does not ship untracked, release files it had lost restored.
No sync and no rebuild ran; the working tree was not touched."
    elif [ "$mode" = "conversion" ]; then
        subject="Framework: vendor system/ as tracked files + install ${NEW_SHA:0:12}"
        body="One-time submodule-era conversion: system/ materialized as ordinary tracked files at rspade_system ${NEW_SHA}."
    elif [ "$BASELINE" = true ]; then
        subject="Framework baseline ${NEW_SHA:0:12}"
        body="History baseline established at rspade_system ${NEW_SHA} (no previously-installed release was recorded; no changelog available)."
    elif [ "$OLD_SHA" = "$NEW_SHA" ]; then
        subject="Framework repair resync at ${NEW_SHA:0:12} (--force)"
        body="Owned zones restored to pristine release content; no upstream version change."
    else
        n="$(git --git-dir="$CACHE" rev-list --count "$OLD_SHA..$NEW_SHA" 2>/dev/null || echo 0)"
        subject="Framework update ${OLD_SHA:0:12} -> ${NEW_SHA:0:12} ($n upstream commits)"
        # THE FULL upstream changelog - subjects AND bodies - not just subject lines.
        #
        # A downstream app's git history is the audit record shown to the people funding the
        # work: "what changed in the framework, and why" has to be answerable from `git log`
        # alone. Subject-only failed that completely, because the distribution's own commits
        # are release SQUASHES whose subjects say only "RSpade framework release X..Y
        # (N commits)" - so a release that widened the owned zones, added index-lock retry
        # and fixed statusline locking recorded exactly one content-free line. The substance
        # existed, but only in framework_update_history.dat, which is not what git log, a PR
        # view, or a hosting UI shows.
        #
        # Same canonical string the .dat gets (see compute_framework_changelog), size-capped
        # for a commit message with an explicit pointer to the uncapped copy.
        body="$(changelog_for_commit_message)"
    fi

    local msg="$subject

$body

Framework-Update-Range: ${OLD_SHA:-BASELINE}..${NEW_SHA}
Committed-By: rsx:framework:pull"

    # =========================================================================
    # THE COMMIT'S TREE IS BUILT DELIBERATELY, IN A THROWAWAY INDEX.
    #
    # `git commit -- <paths>` cannot be used any more, and the reason is exact: with a
    # pathspec, git rebuilds the tree from HEAD plus the WORKING TREE content of the
    # named paths and IGNORES THE INDEX ENTIRELY. Every normalization of the index
    # would be silently discarded, and the working tree is the one thing that must not
    # be trusted here - it carries this box's class-override pairs.
    #
    # So the same semantics are reproduced by hand, one step at a time:
    #   1. seed a throwaway index from HEAD          (everything unnamed comes from HEAD)
    #   2. add the same pathspec set into it         (working-tree content for system/)
    #   3. normalize the index into the release       (see normalize_commit_index)
    #   4. assert the result IS the release          (see assert_index_matches_release)
    #   5. write-tree + commit-tree + update-ref     (commit exactly the tree we built)
    #
    # The developer's own index is never read or written by this, so in-flight app work
    # - staged or not - is untouchable by construction rather than by pathspec. No hook
    # runs on the plumbing path, which is what --no-verify + RSPADE_FRAMEWORK_COMMIT=1
    # were arranging anyway; the env var is kept on the call for anything that inspects
    # it. index.lock is not taken either: the staging add above is the operation that
    # proves the index is writable, and it is lock-retried.
    # =========================================================================
    local idx_dir="$TMP_ROOT"
    { [ -n "$idx_dir" ] && [ -d "$idx_dir" ]; } || idx_dir="$(mktemp -d)"
    COMMIT_TMP_INDEX="$idx_dir/framework-commit-index"
    rm -f "$COMMIT_TMP_INDEX"
    export GIT_INDEX_FILE="$COMMIT_TMP_INDEX"

    local parent_sha=""
    parent_sha="$(git -C "$PROJECT_ROOT" rev-parse --verify -q HEAD 2>/dev/null || true)"
    if [ -n "$parent_sha" ]; then
        git -C "$PROJECT_ROOT" read-tree HEAD >/dev/null 2>&1 \
            || __commit_index_die "Failed to seed the framework-update commit index from HEAD."
    else
        # No HEAD yet (a repository whose first commit this is): start from nothing.
        git -C "$PROJECT_ROOT" read-tree --empty >/dev/null 2>&1 \
            || __commit_index_die "Failed to initialize the framework-update commit index."
    fi

    # A SUBMODULE-ERA HEAD carries system/ as a GITLINK (mode 160000), and `git add`
    # refuses to descend into a path its index calls a submodule - the conversion commit
    # would come out empty. Drop the gitlink from the throwaway index first; that is
    # exactly what do_conversion did to the real index (`git rm --cached system`).
    local link
    while IFS= read -r link; do
        [ -n "$link" ] || continue
        git -C "$PROJECT_ROOT" update-index --force-remove -- "$link" >/dev/null 2>&1 \
            || __commit_index_die "Failed to drop the submodule gitlink from the commit index: $link"
    done < <(git -C "$PROJECT_ROOT" ls-files --stage -- system 2>/dev/null | sed -n 's/^160000 [0-9a-f]* [0-9]\t//p')

    git -C "$PROJECT_ROOT" add -f -A -- "${paths[@]}" >/dev/null 2>&1 \
        || __commit_index_die "Failed to build the framework-update commit index from system/."

    normalize_commit_index

    assert_index_matches_release \
        || __commit_index_die "Framework update aborted: the commit would not match the release inventory."

    local new_tree
    new_tree="$(git -C "$PROJECT_ROOT" write-tree 2>/dev/null)"
    [ -n "$new_tree" ] || __commit_index_die "Failed to write the framework-update commit tree."

    # NOTHING TO RECORD. The dirty check above sees the WORKING TREE (where the override
    # pair always shows as a deletion plus an untracked sidecar), so a run whose only
    # difference from HEAD is that churn reaches here with an identical tree. Committing
    # it would be an empty commit stating a change nobody made.
    local head_tree=""
    [ -n "$parent_sha" ] && head_tree="$(git -C "$PROJECT_ROOT" rev-parse -q --verify "HEAD^{tree}" 2>/dev/null || true)"
    if [ -n "$head_tree" ] && [ "$head_tree" = "$new_tree" ]; then
        unset GIT_INDEX_FILE
        rm -f "$COMMIT_TMP_INDEX"; COMMIT_TMP_INDEX=""
        __unstage_system_churn "$parent_sha"
        ok "No framework changes to commit."
        SYNCED_UNCOMMITTED=false
        return 0
    fi

    # Mirror the repository's signing preference: `git commit` would have honored
    # commit.gpgsign and this path must not quietly stop doing so.
    local -a sign=()
    [ "$(git -C "$PROJECT_ROOT" config --bool --get commit.gpgsign 2>/dev/null)" = "true" ] && sign=(-S)

    local -a parent_args=()
    [ -n "$parent_sha" ] && parent_args=(-p "$parent_sha")

    local commit_sha commit_rc=0
    commit_sha="$(printf '%s' "$msg" | RSPADE_FRAMEWORK_COMMIT=1 git -C "$PROJECT_ROOT" \
        commit-tree ${sign[@]+"${sign[@]}"} ${parent_args[@]+"${parent_args[@]}"} "$new_tree" 2>&1)" || commit_rc=$?
    if [ "$commit_rc" -eq 0 ] && [ -n "$commit_sha" ]; then
        # update-ref follows HEAD's symref to the branch and writes a reflog entry. The
        # old value is passed so a HEAD that moved underneath us fails the update rather
        # than clobbering whatever landed there.
        git -C "$PROJECT_ROOT" update-ref -m "$subject" HEAD "$commit_sha" ${parent_sha:+"$parent_sha"} >/dev/null 2>&1 \
            || commit_rc=1
    else
        [ -n "$commit_sha" ] && printf '%s\n' "$commit_sha" >&2
        commit_rc=1
    fi

    unset GIT_INDEX_FILE
    rm -f "$COMMIT_TMP_INDEX"; COMMIT_TMP_INDEX=""

    if [ "$commit_rc" -eq 0 ]; then
        __unstage_system_churn "$parent_sha"
        local sha
        sha="$(git -C "$PROJECT_ROOT" rev-parse --short HEAD 2>/dev/null)"
        AUTO_COMMITTED=true
        SYNCED_UNCOMMITTED=false
        ok "Framework changes committed: $sha \"$subject\""
    else
        # Still ARMED: the release is on disk and out of history - the lethal state.
        warn "Framework auto-commit FAILED. The sync itself succeeded; the changes"
        say "  remain staged in the working tree. Commit them manually:"
        say "    RSPADE_FRAMEWORK_COMMIT=1 git commit --no-verify -- system $history_rel"
        say "  (Do NOT fold them into an app commit - the system-guard hook will strip them.)"
        say "  A manual commit records the working tree as it stands, so on a box carrying a"
        say "  class override it will also record the .upstream sidecars this updater keeps"
        say "  out. Re-running the pull once the problem is fixed commits the release cleanly."
    fi
}

# =============================================================================
# Submodule-era conversion (system/ was a git submodule of rspade_system)
# =============================================================================
# The set-aside for the live storage tree during conversion. FIXED name (no PID
# suffix) so a crashed conversion is deterministically findable and resumable.
SETASIDE_DIR=""

# _orphan_setaside_globs - print each PID-suffixed set-aside dir (one per line).
# A crash under the OLD (pre-fix) script left the set-aside under a PID-suffixed
# name; the resume path promotes exactly one such dir to the fixed name.
_orphan_setaside_globs() {
    local d
    for d in "$PROJECT_ROOT"/.tmp_convert_storage_[0-9]*; do
        [ -e "$d" ] && printf '%s\n' "$d"
    done
}

# _system_is_empty - system/ is missing or contains no entries.
_system_is_empty() {
    [ ! -d "$SYSTEM_DIR" ] || [ -z "$(ls -A "$SYSTEM_DIR" 2>/dev/null)" ]
}

# _system_gitlink_staged - the index still carries a submodule gitlink for system,
# or a STAGED DELETION of one (the field-crash state: `git rm --cached system`).
_system_gitlink_staged() {
    local stage status
    stage="$(git -C "$PROJECT_ROOT" ls-files --stage -- system 2>/dev/null)"
    printf '%s' "$stage" | grep -q '^160000' && return 0
    status="$(git -C "$PROJECT_ROOT" status --porcelain -- system 2>/dev/null)"
    printf '%s' "$status" | grep -q '^D  system' && return 0
    return 1
}

# needs_conversion - true when a submodule-era conversion (fresh OR resume of a
# crashed one) is required. Conservative: must NEVER fire on a healthy vendored
# app (populated system/ with a .rspade-release.json, no set-aside, no staged
# gitlink).
needs_conversion() {
    # Fresh: .gitmodules still wires system/ as a submodule.
    if [ -f "$PROJECT_ROOT/.gitmodules" ] && grep -q "path = system" "$PROJECT_ROOT/.gitmodules"; then
        return 0
    fi
    # Resume: the fixed-name set-aside from a crashed conversion is present.
    if [ -e "$PROJECT_ROOT/.tmp_convert_storage" ]; then
        return 0
    fi
    # Resume: a PID-suffixed set-aside from a crash under the old script is present.
    if [ -n "$(_orphan_setaside_globs)" ]; then
        return 0
    fi
    # Resume: system/ is missing/empty, is not a materialized release, and the
    # index still carries a staged gitlink (or its staged deletion) for system.
    if _system_is_empty && [ ! -f "$SYSTEM_DIR/.rspade-release.json" ] && _system_gitlink_staged; then
        return 0
    fi
    return 1
}

# prepare_conversion - promotes a single crashed set-aside to the fixed name so
# do_conversion adopts it. The distribution cache needs no handling here: it lives
# outside the app tree (Framework_Maintenance::upstream_cache_dir()), so a crashed
# conversion never sets it aside and ensure_cache reuses it - or re-clones - freely.
prepare_conversion() {
    SETASIDE_DIR="$PROJECT_ROOT/.tmp_convert_storage"

    # Promote a PID-suffixed orphan set-aside to the fixed name.
    local matches=() d
    while IFS= read -r d; do [ -n "$d" ] && matches+=("$d"); done < <(_orphan_setaside_globs)
    if [ "${#matches[@]}" -gt 1 ]; then
        err "Multiple interrupted-conversion set-aside dirs found at the project root:"
        for d in "${matches[@]}"; do say "  $d"; done
        die "Resolve manually (keep exactly one, remove the rest) then re-run."
    fi
    if [ "${#matches[@]}" -eq 1 ]; then
        if [ -e "$SETASIDE_DIR" ]; then
            die "Both $SETASIDE_DIR and ${matches[0]} exist; cannot determine the authoritative set-aside. Resolve manually and re-run."
        fi
        mv "${matches[0]}" "$SETASIDE_DIR" || die "Failed to promote set-aside dir ${matches[0]}"
    fi
}

do_conversion() {
    say ""
    say "=== One-time submodule conversion required ==="
    say ""
    say "system/ is currently a git submodule of the framework distribution. The"
    say "framework no longer ships as a submodule - it is now ordinary tracked files"
    say "in this app repo. This one-time conversion will:"
    say "  * de-initialize and un-track the system/ submodule"
    say "  * materialize the current framework release as ordinary files under system/"
    say "  * stage a single large commit (vendor/ and node_modules/ are included)"
    say ""
    say "Framework history remains browsable at the rspade_system distribution repo."
    say "After this, 'git log -- system/' works in this app repo."
    say ""

    if [ "$YES" != true ]; then
        if [ ! -t 0 ]; then
            die "Submodule conversion needs confirmation. Re-run with --yes to proceed non-interactively."
        fi
        printf "Proceed with the conversion? [y/N] "
        local reply
        read -r reply
        case "$reply" in
            y|Y|yes|YES) ;;
            *) die "Conversion declined." ;;
        esac
    fi

    # prepare_conversion (run before ensure_cache) set SETASIDE_DIR; on a fresh
    # run it may still be empty here.
    [ -n "$SETASIDE_DIR" ] || SETASIDE_DIR="$PROJECT_ROOT/.tmp_convert_storage"

    # Every step below is idempotent so this ONE function heals both a fresh
    # conversion and the resume of a crashed one (deinit/rm tolerate "already
    # gone", wiring strip tolerates absence, materialize targets an empty-or-
    # missing system/, storage is restored from the set-aside).

    # Preserve a REAL system/.env file (a symlink needs nothing - env heal
    # recreates it). On a resume system/ is already empty, so there is nothing to
    # save; env heal recreates the symlink during the rebuild - do NOT fabricate one.
    local saved_env=""
    if [ -f "$SYSTEM_DIR/.env" ] && [ ! -L "$SYSTEM_DIR/.env" ]; then
        saved_env="$TMP_ROOT/saved.env"
        cp -f "$SYSTEM_DIR/.env" "$saved_env"
    fi

    # Preserve (fresh) or adopt (resume) the LIVE storage tree BEFORE any submodule
    # surgery: `git submodule deinit` empties the submodule working tree, so this
    # must happen first. This is real deployment data, not framework code - the
    # file-attachment blob store (storage/files/), thumbnail and rendition caches,
    # migrate snapshots, locks, and logs live there. Releases ship no storage/
    # (publish excludes it), so without this the conversion would leave the
    # deployment with an EMPTY storage dir and destroy uploaded file blobs.
    # STORAGE-MOVE-AWARE: once storage has been relocated to <project>/storage
    # (marker file; see storage_base) it lives OUTSIDE system/ and survives the
    # materialize rm untouched - both branches below are then naturally inert
    # (no system/storage, no set-aside) and no set-aside is needed.
    if [ -e "$SETASIDE_DIR" ]; then
        # Resume: the set-aside is authoritative. If system/storage ALSO still
        # holds data we cannot know which is the live copy - stop, never guess.
        if [ -d "$SYSTEM_DIR/storage" ] && [ -n "$(ls -A "$SYSTEM_DIR/storage" 2>/dev/null)" ]; then
            die "Both $SETASIDE_DIR and a non-empty $SYSTEM_DIR/storage exist; cannot determine the authoritative storage. Resolve manually and re-run."
        fi
        say "Adopting live storage set aside by an interrupted conversion."
    elif [ -d "$SYSTEM_DIR/storage" ]; then
        mv "$SYSTEM_DIR/storage" "$SETASIDE_DIR" || die "Failed to set aside system/storage during conversion"
        say "Set aside live storage for restoration after materialize."
    fi

    # --- Remove the submodule wiring (idempotent; verified; staged as made). ----
    git -C "$PROJECT_ROOT" submodule deinit -f system >/dev/null 2>&1 || warn "submodule deinit reported an issue (continuing)."
    git -C "$PROJECT_ROOT" rm -f --cached system >/dev/null 2>&1 || warn "git rm --cached system reported an issue (continuing)."

    # Strip the submodule.system section; delete .gitmodules if it becomes empty.
    git -C "$PROJECT_ROOT" config -f "$PROJECT_ROOT/.gitmodules" --remove-section submodule.system >/dev/null 2>&1 || true
    if [ -f "$PROJECT_ROOT/.gitmodules" ] && [ ! -s "$PROJECT_ROOT/.gitmodules" ]; then
        rm -f "$PROJECT_ROOT/.gitmodules"
    elif [ -f "$PROJECT_ROOT/.gitmodules" ] && ! grep -q '\[submodule' "$PROJECT_ROOT/.gitmodules"; then
        rm -f "$PROJECT_ROOT/.gitmodules"
    fi
    # Stage the .gitmodules change (edit OR deletion) at the moment it is made, so a
    # crash never leaves an unstaged .gitmodules deletion beside a staged `D system`.
    git -C "$PROJECT_ROOT" add -A -- .gitmodules >/dev/null 2>&1 || true

    # Resolve the submodule's git dir ABSOLUTELY. `rev-parse --git-path` returns a
    # path relative to the invoking cwd (NOT to -C), so it must never be used for a
    # destructive rm. --absolute-git-dir yields the real .git base regardless of cwd.
    local git_dir modules_dir
    git_dir="$(git -C "$PROJECT_ROOT" rev-parse --absolute-git-dir 2>/dev/null || echo '')"
    if [ -n "$git_dir" ]; then
        modules_dir="$git_dir/modules/system"
        # Sanity guard: only rm -rf an absolute path that names the submodule store.
        case "$modules_dir" in
            /*/modules/system) ;;
            *) die "Refusing to remove unexpected submodule git dir path: '$modules_dir'" ;;
        esac
        if [ -e "$modules_dir" ]; then
            rm -rf "$modules_dir"
            # Verify the removal took effect; never print success over a survivor.
            [ -e "$modules_dir" ] && die "Failed to remove submodule git dir: $modules_dir"
        fi
    fi
    say "Removed submodule wiring."

    # --- Materialize the framework release into an empty-or-missing system/. -----
    # The NEW checkout is a hardlinked local clone OUTSIDE system/, so its files
    # survive this rm; live storage was already set aside above.
    rm -rf "$SYSTEM_DIR"
    mkdir -p "$SYSTEM_DIR"
    if ! rsync -rlD --exclude='.git' "$NEW_TREE/" "$SYSTEM_DIR/"; then
        die "Failed to materialize system/ from the new release"
    fi
    say "Materialized framework release into system/."
    # The release is on disk and not yet in history (see owned_zone_sync).
    SYNCED_UNCOMMITTED=true

    # --- Restore the live storage tree, then reset the mutation-marker store. ----
    # Pre-conversion markers describe a tree that no longer exists (the materialized
    # tree is pristine) and would false-flag in the next verify. The restore `mv`
    # IS the set-aside cleanup - nothing must remain at SETASIDE_DIR afterward.
    # Post-relocation there is no set-aside (storage never left the project root),
    # but the marker reset applies either way - hence it is outside the branch.
    if [ -e "$SETASIDE_DIR" ]; then
        rm -rf "$SYSTEM_DIR/storage"
        mv "$SETASIDE_DIR" "$SYSTEM_DIR/storage" || die "Failed to restore system/storage after conversion"
        [ -e "$SETASIDE_DIR" ] && die "Set-aside storage dir still present after restore: $SETASIDE_DIR"
        say "Restored live deployment storage."
    fi
    rm -f "$(storage_base)/rsx-framework/mutations.json"
    rm -rf "$(storage_base)/rsx-framework/shadow"

    # Recreate the system/rsx -> ../rsx symlink (environment wiring).
    if [ ! -e "$SYSTEM_DIR/rsx" ]; then
        ln -s ../rsx "$SYSTEM_DIR/rsx"
    fi

    # Restore a preserved real .env; a symlinked one is recreated by env heal.
    if [ -n "$saved_env" ]; then
        cp -f "$saved_env" "$SYSTEM_DIR/.env"
    fi

    # After conversion there is no installed-release history and no marker store.
    STORE_DIR="$(storage_base)/rsx-framework"
    BASELINE=true
    OLD_SHA=""
    OLD_TREE=""

    # The restored storage may carry a stale pre-conversion manifest cache; force a
    # full manifest rebuild from the pristine materialized tree (consumed by do_rebuild).
    MANIFEST_FORCE=true

    git -C "$PROJECT_ROOT" add system >/dev/null 2>&1 || true
    git -C "$PROJECT_ROOT" add -A -- .gitmodules >/dev/null 2>&1 || true

    # Force-stage the updater's own entry script. `git add system` above stages the
    # materialized tree, but if a stale vendored .gitignore is hiding the updater the
    # wholesale add drops it - the very bootstrap failure this fixpack closes.
    ensure_updater_bootstrap conversion

    ok "Submodule converted to ordinary tracked files."
}

# =============================================================================
# run_post_update - hand off to bin/post-update.sh (see that file's header).
#
# Invoked as a SUBPROCESS after the owned-zone sync, so it runs the FRESHLY-SYNCED
# post-update.sh + environment_updates/*.sh: new environment behavior lands on the
# SAME pull that ships it, with no edit to this (running) script. Never fatal - the
# framework update itself has already succeeded by this point. Downstream pulls are
# by definition not the monorepo, hence IS_FRAMEWORK_DEVELOPER=false.
# =============================================================================
# Gated on -f, NOT -x: the script is invoked through `bash`, and a repo script's exec bit is
# unreliable downstream (core.fileMode false + this pull's own rsync). An -x gate would make a
# lost exec bit silently skip EVERY environment update - including the ones that repair it.
run_post_update() {
    if [ -f "$SYSTEM_DIR/bin/post-update.sh" ]; then
        PROJECT_ROOT="$PROJECT_ROOT" SYSTEM_DIR="$SYSTEM_DIR" IS_FRAMEWORK_DEVELOPER=false \
            bash "$SYSTEM_DIR/bin/post-update.sh" || warn "post-update reported failures (non-fatal)."
    fi
}

# =============================================================================
# main
# =============================================================================
main() {
    # HUP is in the list deliberately. An untrapped SIGHUP terminates a non-interactive
    # bash WITHOUT running the EXIT trap, so a pull whose terminal went away (a dropped
    # SSH session, a closed window) left the maintenance window up and the box 503ing
    # until somebody found the dotfile. Observed as an overnight stale flag. SIGKILL
    # remains uncoverable by construction - `rsx:maintenance:disable` is the remedy
    # there, and the 503 body now names it.
    trap cleanup EXIT INT TERM HUP

    parse_flags "$@"
    derive_paths

    # ADOPT A STALE WINDOW THIS UPDATER'S OWN LINEAGE LEFT BEHIND. cleanup() only lifts
    # a window THIS run raised (MAINT_ACTIVE), which is right for an operator's window -
    # but it meant a flag stranded by a previous updater run (a kill, a crash, or a
    # historical early-exit path) survived every subsequent run that exited early:
    # a repair or up-to-date run walked past it and the box stayed 502 until someone
    # ran rsx:maintenance:disable by hand (field report 2026-08-18). Ownership is the
    # flag CONTENT - the first line is the raiser's reason - so only our own reason
    # string is adopted; an operator's window is left exactly as they raised it.
    # With MAINT_ACTIVE set, EVERY exit path - the early up-to-date and baseline-repair
    # exits included - lifts the window and restarts the services via the EXIT trap.
    __stale_flag="$(storage_base)/rsx-framework/.maintenance.mode.framework.update"
    if [ "$SERVICE_CONTROL" = true ] && [ -f "$__stale_flag" ] \
        && [ "$(head -n1 "$__stale_flag" 2>/dev/null)" = "framework update in progress" ]; then
        MAINT_ACTIVE=true
        say "Adopting a stale maintenance window left by a previous framework update -"
        say "it will be lifted (services restarted) when this run exits."
    fi

    # --check-foreign-changes: the shared system/ drift probe (rsx:clean --reset-system
    # shells out to it). Local-only - no gates, no network, no changes.
    if [ "$CHECK_FOREIGN" = true ]; then
        check_foreign_changes
        exit $?
    fi

    run_gates

    # COMMIT-AFFECTING fleet hygiene, on EVERY run - these MUST precede
    # commit_system_update: system/ must never be gitignored, and the legacy-hygiene
    # migration retires the old skip-worktree + *.php.upstream model (clears
    # skip-worktree so commit_system_update can stage the override zone).
    # Everything ELSE that configures the environment (the pre-commit hook,
    # core.fileMode, the Claude Code status line, one-time migrations) now lands via
    # bin/post-update.sh + bin/environment_updates/ at the end of main() - editing this
    # script is unsafe mid-run and lags one pull; post-update runs the freshly-synced copy.
    ensure_system_not_ignored
    migrate_legacy_override_hygiene

    say ""
    say "=== Pull RSpade Framework Updates ==="
    say ""

    # Decide conversion up front and reconcile a crashed run's set-aside before any
    # other pass can observe the half-converted tree.
    local convert=false
    if needs_conversion; then
        convert=true
        prepare_conversion
    fi

    ensure_cache
    resolve_old

    # --diff: preview only, no changes.
    if [ "$SHOW_DIFF" = true ]; then
        preview
        exit 0
    fi

    # --diff-system-changes: gate-only inspection, no changes.
    if [ "$DIFF_SYSTEM_CHANGES" = true ]; then
        diff_system_changes
        exit $?
    fi

    # Submodule-era conversion is a full replacement; it subsumes the sync passes.
    # do_conversion's storage-restore step already resets the marker files with a
    # wholesale inline rm of mutations.json + shadow after materialize (kept bash-only
    # / PHP-independent for resilience; same wholesale-degradation semantics as
    # prune_store's non-lock degraded path - the fresh materialized tree has no valid markers to
    # preserve anyway), so the tree is pristine before do_rebuild - no separate
    # prune_store here (a reset AFTER do_rebuild would wipe the churn the rebuild's
    # manifest pass records; that was B-49).
    if [ "$convert" = true ]; then
        do_conversion
        write_history
        # Commit the freshly-vendored system/ tree BEFORE the rebuild, as the pristine
        # distribution state (COMMIT MODEL). A rebuild that then fails costs a rebuild,
        # never the vendored tree.
        commit_system_update conversion
        do_rebuild
        print_summary
        run_post_update
        exit 0
    fi

    if [ "$BASELINE" != true ] && [ "$OLD_SHA" = "$NEW_SHA" ]; then
        # Up to date - but that is a claim about the MARKER, not about the TREE.
        #
        # OWNED ZONES ARE CONVERGENT STATE, NOT DIFFED STATE. rsx:clean runs
        # immediately before this and reverts every machine-made change to system/,
        # so past that point a missing owned-zone file is damage or a violation of
        # the read-only rule - never an instruction to honour. Files do go missing
        # (an operator `git clean -fd system`, a bad restore, a merge that adopted
        # another environment's older deletion), and this branch used to exit 0 on
        # the marker alone, so a box missing a release worth of migrations reported
        # itself current forever (field report, 2026-08-11).
        #
        # Restoring costs NOTHING - it destroys no local content - so it is not an
        # override and takes no flag. When check_tree_complete finds repairable
        # misses we fall through to the ordinary passes, which converge the owned
        # zones and commit the result; the tamper gate still protects MODIFIED files
        # on the way past, which is the one case where content is genuinely at risk.
        #
        # --force means exactly one thing here, as everywhere: override the tamper
        # gate. It additionally forces this fall-through unconditionally, because a
        # complete-looking tree can still hold drift or wiped build state that only a
        # resync repairs.
        if [ "$RESYNC" = true ]; then
            # --resync: DO NOT SHORT-CIRCUIT. The marker agreeing with the tip says
            # nothing about the tree, and this flag exists precisely for the box where
            # the two have already been proven to disagree.
            say "Resync requested: restoring every framework-owned zone to the distribution"
            say "tip (${NEW_SHA:0:12}) and re-committing system/ as that release."
            say ""
        elif [ "$FORCE" != true ]; then
            if check_tree_complete; then
                # THE DISK IS COMPLETE - NOW ASK THE SAME QUESTION OF HISTORY.
                #
                # "Up to date" used to be the end of the run, so a box whose marker
                # already matched could never repair a contaminated COMMITTED tree: it
                # waited for the next release to happen along, carrying its tracked
                # sidecars and its thousands of tracked foreign paths in shared history
                # the whole time (field report, 2026-08-18 - the pull ran twice and this
                # short-circuit took it both times).
                #
                # So the committed path set is checked against the inventory here, with
                # the same partition the commit assertion uses, and a disagreement falls
                # through to a COMMIT-ONLY repair: no sync, no rebuild, no maintenance
                # window (see commit_system_update's mode table for why none is needed).
                # A clean baseline keeps the exact behavior this branch always had.
                if committed_baseline_is_dirty; then
                    # REPAIR ONLY EVER GOES FORWARD.
                    #
                    # The repair re-states the committed tree to match the ON-DISK
                    # marker, so pointing it at a STALE marker untracks everything the
                    # newer release added - which is exactly what happened on 2026-08-18
                    # (7,503 paths untracked, framework files left on disk and untracked,
                    # every rebuild failing afterwards). resolve_old now derives OLD_SHA
                    # from that same marker, so reaching here with a stale one should be
                    # impossible; this is the second line, and it is cheap.
                    if ! repair_direction_is_forward; then
                        MANIFEST_FORCE=true
                    else
                        say "Repairing the baseline commit (no sync, no rebuild)."
                        say ""
                        commit_system_update repair
                        exit 0
                    fi
                else
                    ok "Framework is up to date (${NEW_SHA:0:12})."
                    exit 0
                fi
            fi
            say "Restoring them from the installed release (${NEW_SHA:0:12})."
            say ""
        else
            ok "Framework is up to date (${NEW_SHA:0:12}) - --force: performing full repair resync."
        fi
        # A repair implies distrusting local build state - force a full manifest rebuild.
        MANIFEST_FORCE=true
    fi

    run_tamper_gate
    enter_maintenance
    owned_zone_sync
    # Guarantee the updater's own entry script is committed downstream even when a
    # stale vendored .gitignore (older release) is hiding it - the second-pass
    # remediation for the tracked-but-ignored bootstrap failure. bin/ was just synced
    # so the file is on disk; this only fixes its git staging.
    ensure_updater_bootstrap normal
    # RECONCILE the mutation markers at the EARLIEST pristine moment - immediately
    # after the owned-zone sync restored the tree, BEFORE three_way_pass (which only
    # touches the non-owned remainder the markers never cover) and BEFORE the rebuild.
    # A blind wipe here loses the valid records a concurrent live-box boot may have
    # written between the sync and now, leaving an empty ledger that false-flags all
    # framework churn forever (B-52, the ascent field failure); the reconciling prune
    # keeps every still-true marker and drops only the stale ones. The rebuild's
    # manifest pass then re-applies + re-records class overrides on top (B-49: reset
    # must precede the rebuild so that fresh churn is retained, not wiped).
    prune_store
    three_way_pass
    # ---- The release is now fully on disk. COMMIT IT BEFORE BUILDING ANYTHING. ----
    # write_history and commit_system_update describe the SAME pristine state and move
    # together. Everything the commit needs (OLD_SHA/NEW_SHA + the changelog) is read
    # from the cached distribution repo, so it is valid at this pre-rebuild moment; the
    # commit consumes NOTHING the rebuild produces - which is exactly the point (see
    # COMMIT MODEL). The system-guard hook keeps system/ out of app commits; this is
    # the ONLY path that commits it.
    #
    # A rebuild failure after this point is harmless: the release is in history, the
    # repair is a REBUILD (dev auto-rebuild, or rsx:manifest:build) rather than a
    # re-pull, and every rsx:clean / pre-commit-hook reset lands ON the new release.
    # Under the old commit-after-rebuild order a release whose own validator failed
    # deterministically (e.g. new build-time enforcement the app must migrate to) left
    # the tree synced-but-uncommitted, and the next app commit's hook reverted it -
    # a deadlock, since the migration that fixes the build needs app commits.
    write_history
    commit_system_update normal
    do_rebuild
    print_summary
    run_post_update

    # Advisory: if the update (or a skipped --no-rebuild) left migrations pending, say so.
    # Silent when the schema is current; never fails the pull.
    local -a __maint_notice=(); [ "$MAINT_ACTIVE" = true ] && __maint_notice=(--_framework-update-override)
    php "$SYSTEM_DIR/artisan" migrate:status_notice "${__maint_notice[@]}" 2>/dev/null || true

    exit 0
}

main "$@"
