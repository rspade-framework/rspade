#!/usr/bin/env bash
#
# 050 - Install the downstream post-commit hook that runs the environment updates.
#
# system/ is vendored, so a plain `git pull` DELIVERS new environment_updates/*.sh - but
# only rsx:framework:pull and a successful dev `rsx:manifest:build` EXECUTE them. This hook
# is the third trigger: it shortens the latency to "the next commit" on an environment that
# rarely builds. It is a SECOND trigger, never the mechanism - git does not distribute
# .git/hooks, so this script must itself arrive via one of the other two paths first.
#
# The hook never blocks and never fails a commit: it spawns post-update.sh detached, with
# output discarded (a commit's console is gone by the time the scripts finish), and always
# exits 0.
#
# Downstream-only: the framework monorepo commits constantly and self-provisions through the
# build trigger, so the hook is not installed there (mirrors 020's exclusion).
# Self-detecting + idempotent: silent when our hook is already installed byte-identically;
# reports and SKIPS a foreign post-commit hook rather than clobbering it.
# See system/bin/environment_updates/CLAUDE.md.

set -uo pipefail

# CONTAINER GATE. Every environment_updates script runs ONLY inside the RSpade
# container. These scripts modify the environment around the project - git hooks,
# editor and agent settings, the on-disk storage layout - and that environment is
# the container's, not the host's. Run on a host they would install container
# assumptions into somebody's own machine, silently and with no way to know it
# happened. Absent marker, absent consent: exit 0 and say nothing (the contract
# is silent-when-not-applicable, and this is a normal state, not a failure).
[ -f /.rspade_container ] || exit 0

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"

# The monorepo commits constantly and gets its updates from the build trigger.
if [ "${IS_FRAMEWORK_DEVELOPER:-false}" = true ]; then
    exit 0
fi

git -C "$PROJECT_ROOT" rev-parse --git-dir >/dev/null 2>&1 || exit 0

# --git-path honors core.hooksPath; the result may be relative to PROJECT_ROOT.
hooks_dir="$(git -C "$PROJECT_ROOT" rev-parse --git-path hooks 2>/dev/null)"
[ -n "$hooks_dir" ] || exit 0
case "$hooks_dir" in
    /*) : ;;
    *)  hooks_dir="$PROJECT_ROOT/$hooks_dir" ;;
esac
mkdir -p "$hooks_dir" 2>/dev/null || exit 0
hook="$hooks_dir/post-commit"

marker="RSPADE-POSTCOMMIT-V1"

# A post-commit hook we did not write is the developer's - never overwrite it.
if [ -f "$hook" ] && ! grep -q "$marker" "$hook" 2>/dev/null; then
    echo "[env] post-commit: keeping your existing hook (not overwriting); merge RSPADE-POSTCOMMIT logic manually."
    exit 0
fi

tmp_hook="$(mktemp)" || exit 1
trap 'rm -f "$tmp_hook"' EXIT

cat > "$tmp_hook" <<'RSPADE_POSTCOMMIT_HOOK'
#!/bin/bash
# RSPADE-POSTCOMMIT-V1
# Runs the RSpade environment updates (system/bin/post-update.sh) after a commit.
#
# system/ is vendored: a plain `git pull` delivers new environment_updates/*.sh but nothing
# executes them, so an environment that never runs a framework update would keep the scripts
# and none of their effects. This hook applies them at the next commit; a successful dev
# `rsx:manifest:build` is the bootstrap-safe trigger that does not depend on a hook existing.
#
# Installed + refreshed by system/bin/environment_updates/050_post_commit_env_update.sh; the
# RSPADE-POSTCOMMIT-V1 marker above is how it detects its own hook - do not remove it.
#
# Detached + output discarded: a commit must never wait on the environment updates, and a
# failing update must never fail a commit. post-update.sh derives its own context
# (PROJECT_ROOT, SYSTEM_DIR, IS_FRAMEWORK_DEVELOPER) when unset - the single derivation site.

root="$(git rev-parse --show-toplevel 2>/dev/null)"
[ -n "$root" ] || exit 0
[ -f "$root/system/bin/post-update.sh" ] || exit 0

# git exports GIT_DIR / GIT_WORK_TREE / GIT_INDEX_FILE to hooks; the environment updates run
# their own git commands against the repository and must not inherit this commit's context.
(unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE; nohup bash "$root/system/bin/post-update.sh" >/dev/null 2>&1 &) >/dev/null 2>&1

exit 0
RSPADE_POSTCOMMIT_HOOK

# Already ours AND identical -> nothing to do, stay silent... EXCEPT for the exec bit. Git
# execs a hook file directly (there is no `bash <hook>` seam to lean on), so the bit is
# load-bearing here, and it does not survive every way an environment gets copied (a clone
# from an archive, a cp without -p, an rsync onto a core.fileMode=false repo). Heal it.
if [ -f "$hook" ] && cmp -s "$hook" "$tmp_hook"; then
    if [ ! -x "$hook" ]; then
        chmod +x "$hook" 2>/dev/null || true
        if [ -x "$hook" ]; then
            echo "[env] Restored the executable bit on the RSpade post-commit hook ($hook)."
        else
            echo "[env] post-commit: $hook is not executable and chmod +x failed; git will skip it." >&2
            exit 1
        fi
    fi
    exit 0
fi

if ! cp -f "$tmp_hook" "$hook"; then
    echo "[env] post-commit: failed to write $hook" >&2
    exit 1
fi
chmod +x "$hook" 2>/dev/null || true
if [ ! -x "$hook" ]; then
    echo "[env] post-commit: wrote $hook but could not make it executable; git will skip it." >&2
    exit 1
fi

echo "[env] Installed the RSpade post-commit hook ($hook) - applies environment updates after a commit."
exit 0
