#!/bin/bash

# =============================================================================
# RSpade Maintenance Mode
# =============================================================================
# THE single implementation of entering and leaving maintenance mode. Two callers:
#
#   php artisan rsx:maintenance:enable [--reason=<text>] [--no-services]
#   php artisan rsx:maintenance:disable [--no-services] [--force]
#       -> intercepted PRE-BOOT in system/artisan and shelled straight here, so
#          these commands work on a broken/half-synced tree and can never be
#          refused by the gate they control.
#
#   bin/framework-pull-upstream.sh
#       -> enter_maintenance() / cleanup() delegate here with the reason
#          "framework update in progress". Same mechanism, different reason.
#
#   bin/rsx-git.sh
#       -> brackets every tree-rewriting git op with the reason "rsx:git <op>",
#          and on an APP-file conflict leaves the window UP on purpose. The
#          conflict guard in do_disable() is what holds it there.
#
# WHAT IT DOES
#   enable : raise the flag file (its CONTENT is the operator reason) -> force-kill
#            running background tasks -> stop realtime, fpc-proxy, php-fpm,
#            rsx-lockd, and redis LAST.
#   disable: start redis FIRST (everything else depends on it), then rsx-lockd,
#            php-fpm, realtime, fpc-proxy -> clear the flag.
#
# ORDERING IS LOAD-BEARING. The flag goes up BEFORE the lock daemon and redis stop:
# a process booting in that window must already see maintenance mode, otherwise it
# selects the live backends (RsxLocks cluster locks over rsx-lockd, and the redis
# cache) and hard-fatals on the refused connect. With the flag up first, CLUSTER
# locks (named_*/site_*) are granted as NO-OPS - a lock exists to exclude a
# concurrent writer and maintenance has already removed every one of them (web
# 503, task runners refused, workers killed) - while SYSTEM locks (flock-backed,
# this box only) are UNTOUCHED, since their contenders are exactly what
# maintenance does not stop. Redis reads miss silently. rsx-lockd also stops
# AFTER its PHP consumers (php-fpm),
# so no live request loses a lock out from under itself. Symmetrically, redis and
# the lock daemon come back BEFORE the services that need them, and the flag drops
# LAST.
#
# While the flag is up: every web request is 503, and the artisan gate is
# allow-most-deny-some (rsx:task:process / rsx:task:worker blocked, rsx:task:run
# blocked unless --force, everything else allowed). See rsx:man maintenance_mode.
#
# STATELESS. disable starts whichever of the five units exists - there is no record
# of what enable stopped. An operator-stopped unit being started by disable is
# accepted and documented; determinism beats a state file that can go stale.
#
# Both actions are IDEMPOTENT. --no-services touches only the flag (test fixtures,
# containers with no supervisord). disable REFUSES over an unresolved merge conflict
# unless --force (see repo_is_conflicted).
# =============================================================================

set -u

say()  { echo "$*"; }
ok()   { echo "[OK] $*"; }
warn() { echo "[WARNING] $*"; }
err()  { echo "[ERROR] $*" >&2; }

usage() {
    say "Usage:"
    say "  maintenance-mode.sh enable [--reason=<text>] [--no-services]"
    say "  maintenance-mode.sh disable [--no-services] [--force]"
}

# -----------------------------------------------------------------------------
# Paths (derived from this script's own location, never the caller's cwd)
# -----------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SYSTEM_DIR="$(dirname "$SCRIPT_DIR")"
PROJECT_ROOT="$(dirname "$SYSTEM_DIR")"

# Volatile storage: <project>/storage once the relocation marker exists, else the
# historic system/storage. Same resolution as bootstrap/app.php, artisan and the
# updater's storage_base().
storage_base() {
    if [ -f "$PROJECT_ROOT/storage/.rspade_storage_relocated" ]; then
        printf '%s' "$PROJECT_ROOT/storage"
    else
        printf '%s' "$SYSTEM_DIR/storage"
    fi
}

# Mirrors App\RSpade\Core\Framework\Framework_Maintenance::FLAG_RELATIVE.
FLAG="$(storage_base)/rsx-framework/.maintenance.mode.framework.update"

# The application mode, read straight out of the project .env with plain string ops - this
# script runs pre-boot and has no Laravel to ask. Defaults to production: an unreadable or
# absent .env must never be the reason an operator command leaks into a public 503 body.
app_mode() {
    local env_file="$PROJECT_ROOT/.env" line
    [ -f "$env_file" ] || { printf 'production'; return 0; }

    line="$(grep -m1 -E '^[[:space:]]*RSX_MODE[[:space:]]*=' "$env_file" 2>/dev/null)" || true
    [ -n "$line" ] || { printf 'production'; return 0; }

    line="${line#*=}"
    # Strip surrounding whitespace and optional quotes.
    line="$(printf '%s' "$line" | tr -d '"'"'" | tr -d '[:space:]')"
    [ -n "$line" ] || { printf 'production'; return 0; }

    printf '%s' "$line"
}

# -----------------------------------------------------------------------------
# Arguments
# -----------------------------------------------------------------------------
ACTION="${1:-}"
shift || true

REASON="maintenance mode"
SERVICES=true
FORCE=false

for arg in "$@"; do
    case "$arg" in
        --reason=*)     REASON="${arg#--reason=}" ;;
        --no-services)  SERVICES=false ;;
        --force)        FORCE=true ;;
        --help|-h)      usage; exit 0 ;;
        *)
            err "Unknown argument: $arg"
            usage
            exit 1
            ;;
    esac
done

[ -n "$REASON" ] || REASON="maintenance mode"

# -----------------------------------------------------------------------------
# Supervisor helpers - every one is best-effort. A box without supervisorctl, or
# without a given program, must never fail the transition.
# -----------------------------------------------------------------------------
# unit_for <prefix-regex> : first supervisor program whose name matches, else empty
unit_for() {
    command -v supervisorctl >/dev/null 2>&1 || return 0
    supervisorctl status 2>/dev/null | awk '{print $1}' | grep -E "$1" | head -n1
}

stop_unit() {
    local unit
    unit="$(unit_for "$1")"
    [ -n "$unit" ] || return 0
    say "  Stopping $unit..."
    supervisorctl stop "$unit" >/dev/null 2>&1 || warn "could not stop $unit (continuing)."
}

start_unit() {
    local unit
    unit="$(unit_for "$1")"
    [ -n "$unit" ] || return 0
    say "  Starting $unit..."
    supervisorctl start "$unit" >/dev/null 2>&1 || warn "could not start $unit (continuing)."
}

# -----------------------------------------------------------------------------
# SPAWN IS NOT READINESS.
#
# `supervisorctl start` confirms a process was spawned, never that it is SERVING.
# Bringing php-fpm up on that confirmation admits traffic that can take a cluster
# lock against a daemon which is not yet listening - and because a lock wait has no
# deadline, the result is an unattributable stall rather than an error. The ordering
# comment in this file has always said "everything depends on redis, then rsx-lockd";
# this is what actually enforces it.
#
# `ping` is the right probe and already exists for exactly this: the wire protocol
# answers it PRE-HELLO, so a bare, freshly-started daemon can prove it is serving
# frames without holding APP_KEY. rsx:health's "Lock Server" check already asks it.
#
# Endpoint comes straight out of .env with plain string ops - this script runs
# pre-boot, on a possibly broken tree, and must never need Laravel.
#
# NO TIMEOUT. This waits as long as the daemon takes; every ~5s it says so, naming
# what it is waiting for. A bounded budget here would be a NEW deadline on an
# external party, which is a decision for the owner, not this script - and a visible
# "still waiting for rsx-lockd" beats a silent deadline that admits traffic to a
# daemon that never came up. Ctrl-C is always available and the flag stays raised.
# -----------------------------------------------------------------------------
env_value() {
    local key="$1" fallback="$2" env_file="$PROJECT_ROOT/.env" line
    [ -f "$env_file" ] || { printf '%s' "$fallback"; return 0; }
    line="$(grep -m1 -E "^[[:space:]]*${key}[[:space:]]*=" "$env_file" 2>/dev/null)" || true
    [ -n "$line" ] || { printf '%s' "$fallback"; return 0; }
    line="${line#*=}"
    line="$(printf '%s' "$line" | tr -d '"'"'" | tr -d '[:space:]')"
    [ -n "$line" ] || { printf '%s' "$fallback"; return 0; }
    printf '%s' "$line"
}

# One ping. 0 = the daemon answered ok.
lockd_answers_ping() {
    local host="$1" port="$2" reply=''

    # bash /dev/tcp: no nc, no php, nothing to install. The redirection failure is
    # reported by the SHELL, not the command, so the whole exec has to be grouped to
    # silence it - otherwise a refused connection prints two lines per attempt and the
    # wait becomes a wall of noise instead of one status line every 5s.
    { exec {lockd_fd}<>"/dev/tcp/${host}/${port}"; } 2>/dev/null || return 1
    { printf '{"id":1,"op":"ping"}\n' >&"$lockd_fd"; } 2>/dev/null \
        || { exec {lockd_fd}>&-; return 1; }
    IFS= read -r -u "$lockd_fd" reply 2>/dev/null || true
    exec {lockd_fd}>&-

    case "$reply" in
        *'"status":"ok"'*) return 0 ;;
    esac

    return 1
}

wait_for_lockd() {
    local unit host port waited=0
    unit="$(unit_for '^rsx-lockd')"
    # No rsx-lockd on this box (a single-node install may not run one) - nothing to wait for.
    [ -n "$unit" ] || return 0

    host="$(env_value LOCK_SERVER_HOST 127.0.0.1)"
    port="$(env_value LOCK_SERVER_PORT 6210)"

    while ! lockd_answers_ping "$host" "$port"; do
        sleep 1
        waited=$((waited + 1))
        if [ $((waited % 5)) -eq 0 ]; then
            say "  Waiting for rsx-lockd to answer on ${host}:${port} (${waited}s)..."
            say "    Holding php-fpm back: a request that takes a cluster lock against a"
            say "    daemon that is not serving waits with no deadline and no error."
        fi
    done

    [ "$waited" -gt 0 ] && say "  rsx-lockd is serving (${waited}s)."

    return 0
}

# -----------------------------------------------------------------------------
# enable
# -----------------------------------------------------------------------------
do_enable() {
    mkdir -p "$(dirname "$FLAG")" 2>/dev/null || true

    # TWO LINES. Line 1 = the operator reason (quoted by the 503 and every CLI refusal).
    # Line 2 = "mode=<application mode>", stamped HERE because the web 503 is emitted in
    # public/index.php BEFORE composer autoload and can neither boot config nor call
    # Framework_Maintenance. The writer always knows the mode; the reader never can. Same
    # shape as Framework_Maintenance::raise() - that class documents the contract.
    printf '%s\nmode=%s\n' "$REASON" "$(app_mode)" > "$FLAG" || {
        err "Failed to write the maintenance flag: $FLAG"
        exit 1
    }
    ok "Maintenance mode ON ($REASON)."
    say "  Web requests return 503; automated task runners are refused."

    [ "$SERVICES" = true ] || return 0

    # Force-kill running background tasks so nothing keeps writing. Best-effort: a
    # pre-DB or broken tree must not fail the transition. The override token keeps
    # this immune to the gate regardless of future classification changes.
    php "$SYSTEM_DIR/artisan" rsx:tasks:kill-all \
        --explanation="maintenance quiesce" \
        --_framework-update-override >/dev/null 2>&1 || true

    # -------------------------------------------------------------------------
    # QUIESCE THE NODE RPC HELPER DAEMONS.
    #
    # The build helpers (js-parser, js-transformer, minify, jqhtml-compile,
    # js-sanitizer, js-code-quality, ssr-server) each hold a unix socket under
    # <storage>/rsx-tmp/ and each took that absolute path as an argv argument at
    # spawn time. They are PID-1 ORPHANS - spawned with Symfony Process::start()
    # and then abandoned - so NO supervisorctl stop reaches them: stopping php-fpm
    # takes down the workers, never the daemons those workers left behind. The only
    # handle that works is the socket path in their command line, which is why this
    # is a pgrep and not a unit name.
    #
    # This is the bash twin of Rpc_Client_Abstract::quiesce_all() (the PHP side,
    # called by rsx:clean before it wipes rsx-tmp). Both exist to satisfy the rule
    # in bin/CLAUDE.md: any framework operation that changes a socket or state
    # directory must reap the helpers bound to the previous one. A maintenance
    # window is exactly that operation - a framework update rewrites the server
    # scripts underneath a running daemon, and rsx:git's tree-rewriting ops enter
    # through this same script.
    #
    # Killing them is free and disable deliberately does NOT restart them: they
    # hold no state and the next process that needs one spawns it on demand. That
    # on-demand contract is what keeps a post-update daemon from serving the code
    # it was started with.
    #
    # Best-effort like the task kill above, and NO TIMEOUT: TERM, one settle pass,
    # then whatever is still there goes. Same reaper pattern as
    # bin/environment_updates/030_relocate_storage.sh.
    # -------------------------------------------------------------------------
    if command -v pgrep >/dev/null 2>&1; then
        rpc_orphans="$(pgrep -f -- "--socket=$(storage_base)/rsx-tmp/" 2>/dev/null || true)"
        if [ -n "$rpc_orphans" ]; then
            rpc_count="$(printf '%s\n' "$rpc_orphans" | grep -c .)"
            printf '%s\n' "$rpc_orphans" | xargs -r kill 2>/dev/null || true
            printf '%s\n' "$rpc_orphans" | xargs -r -I{} bash -c 'kill -0 {} 2>/dev/null && kill -9 {} 2>/dev/null' || true
            say "  Quiesced $rpc_count node RPC helper daemon(s) (respawned on demand)."
        fi
    fi

    # rsx-lockd after php-fpm (its consumers go first), and redis LAST. The flag is
    # already up, so anything booting now takes the flock backend instead of trying to
    # reach a stopped lock daemon or a stopped redis.
    stop_unit '^realtime'
    stop_unit '^fpc'
    stop_unit '^php-fpm'
    stop_unit '^rsx-lockd'
    stop_unit '^redis'
}

# -----------------------------------------------------------------------------
# Conflict guard - refuse to bring services back over an unresolved merge.
#
# rsx:git halts on an APP-file conflict and deliberately LEAVES maintenance on, so
# the merge can be resolved with no traffic hitting a half-merged tree. This guard is
# what keeps that halt from being lifted early: one mechanism serving both the manual
# operator and the proxy, rather than two that can disagree. --force overrides.
# --no-services is unaffected (it never starts anything).
# -----------------------------------------------------------------------------
repo_is_conflicted() {
    command -v git >/dev/null 2>&1 || return 1

    local git_dir
    git_dir="$(git -C "$PROJECT_ROOT" rev-parse --git-dir 2>/dev/null)" || return 1
    case "$git_dir" in
        /*) : ;;
        *)  git_dir="$PROJECT_ROOT/$git_dir" ;;
    esac

    # An in-progress merge/rebase/cherry-pick/revert is not itself a refusal - only
    # UNMERGED index entries are. A resolved-but-uncommitted merge may proceed.
    [ -n "$(git -C "$PROJECT_ROOT" diff --name-only --diff-filter=U 2>/dev/null | head -n 1)" ]
}

# -----------------------------------------------------------------------------
# disable
# -----------------------------------------------------------------------------
do_disable() {
    if [ "$FORCE" != true ] && repo_is_conflicted; then
        err "Refusing to leave maintenance mode: the repository has unresolved conflicts."
        say ""
        say "  Conflicted files:"
        git -C "$PROJECT_ROOT" diff --name-only --diff-filter=U 2>/dev/null | sed 's/^/    /'
        say ""
        say "  Resolve them and commit, then run this again:"
        say "    php artisan rsx:git commit"
        say "    php artisan rsx:maintenance:disable"
        say ""
        say "  Bringing services up over a half-merged tree serves broken code, which is"
        say "  why this is refused. Override deliberately:"
        say "    php artisan rsx:maintenance:disable --force"
        exit 1
    fi

    if [ "$SERVICES" = true ]; then
        # redis FIRST - everything else expects it to be there - then the lock daemon,
        # so php-fpm's first post-maintenance request finds cluster locks already served.
        start_unit '^redis'
        start_unit '^rsx-lockd'
        # Prove it is SERVING before admitting anything that takes a lock (see above).
        wait_for_lockd
        start_unit '^php-fpm'
        start_unit '^realtime'
        start_unit '^fpc'
    fi

    rm -f "$FLAG" 2>/dev/null || true
    if [ -f "$FLAG" ]; then
        err "Failed to remove the maintenance flag: $FLAG"
        err "Remove it by hand to leave maintenance mode."
        exit 1
    fi

    ok "Maintenance mode OFF."
}

case "$ACTION" in
    enable)  do_enable ;;
    disable) do_disable ;;
    ""|--help|-h)
        usage
        exit 1
        ;;
    *)
        err "Unknown action: $ACTION"
        usage
        exit 1
        ;;
esac

exit 0
