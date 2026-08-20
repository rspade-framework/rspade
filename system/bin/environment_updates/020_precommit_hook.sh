#!/usr/bin/env bash
#
# 020 - Install the downstream pre-commit hook that keeps system/ out of app commits.
#
# system/ is the vendored framework tree: ordinary tracked files OWNED by the framework
# updater (rsx:framework:pull), which commits them itself in a dedicated framework commit
# (RSPADE_FRAMEWORK_COMMIT=1 + --no-verify). An app commit must therefore never carry
# system/ changes. The hook runs `php artisan rsx:clean` (which, downstream, resets system/
# to its last commit) and then unstages any remaining staged system/ path.
#
# Downstream-only: in the framework monorepo system/ IS the work being committed.
# Self-detecting + idempotent: silent when our hook is already installed byte-identically;
# reports and SKIPS a foreign pre-commit hook rather than clobbering it.
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

# The monorepo commits system/ directly - never install the guard there.
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
hook="$hooks_dir/pre-commit"

marker="RSPADE-PRECOMMIT-V1"

# A pre-commit hook we did not write is the developer's - never overwrite it.
if [ -f "$hook" ] && ! grep -q "$marker" "$hook" 2>/dev/null; then
    echo "[env] pre-commit: keeping your existing hook (not overwriting); merge RSPADE-PRECOMMIT logic manually."
    exit 0
fi

tmp_hook="$(mktemp)" || exit 1
trap 'rm -f "$tmp_hook"' EXIT

# NOTE: `rsx:clean --silent` is the real option name (see Clean_Command's signature).
cat > "$tmp_hook" <<'RSPADE_PRECOMMIT_HOOK'
#!/bin/bash
# RSPADE-PRECOMMIT-V1
# Keeps the vendored framework tree (system/) out of ordinary app commits.
#
# system/ is committed ONLY by 'php artisan rsx:framework:pull', in its own dedicated
# framework-update commit. This hook (a) runs rsx:clean, which downstream resets system/
# to its last commit, and (b) unstages any system/ path still staged afterward.
#
# Installed + refreshed by system/bin/environment_updates/020_precommit_hook.sh; the
# RSPADE-PRECOMMIT-V1 marker above is how it detects its own hook - do not remove it.
#
# Bypass channels: RSPADE_FRAMEWORK_COMMIT=1 (the updater's own commit; it also passes
# --no-verify), an in-progress merge (stripping a merge resolution would silently revert
# another environment's framework-pull commit), and the initial commit (no HEAD yet).

if [ "${RSPADE_FRAMEWORK_COMMIT:-0}" = "1" ]; then
    exit 0
fi

git_dir="$(git rev-parse --git-dir 2>/dev/null)" || exit 0
if [ -f "$git_dir/MERGE_HEAD" ]; then
    exit 0
fi

if ! git rev-parse --verify HEAD >/dev/null 2>&1; then
    exit 0
fi

root="$(git rev-parse --show-toplevel 2>/dev/null)"
[ -n "$root" ] || exit 0

# rsx:clean resets system/ to its last commit (downstream behavior). Run it in a CLEAN git
# environment: git exports GIT_DIR/GIT_INDEX_FILE to hooks, and the git calls inside
# rsx:clean must target the repository, not this commit's in-progress index. A failure here
# is a warning, never a commit blocker - the unstage below is what actually protects the
# commit.
if [ -f "$root/system/artisan" ]; then
    if ! (unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE; cd "$root/system" && php artisan rsx:clean --silent); then
        echo "[rspade-precommit] WARNING: rsx:clean failed; continuing with the system/ unstage only." >&2
    fi
fi

# Defensive second pass: unstage anything under system/ that is still staged (rsx:clean may
# have been skipped, or paths were staged after it ran). This uses the REAL index, so the
# inherited git environment is deliberately left intact.
staged_system="$(git diff --cached --name-only -- system 2>/dev/null)"
if [ -n "$staged_system" ]; then
    count="$(printf '%s\n' "$staged_system" | wc -l | tr -d ' ')"
    echo "[rspade-precommit] Unstaging $count system/ path(s) from this commit." >&2
    echo "[rspade-precommit] system/ is committed only by 'php artisan rsx:framework:pull'." >&2
    printf '%s\n' "$staged_system" | head -n 5 | sed 's/^/[rspade-precommit]   /' >&2
    if [ "$count" -gt 5 ]; then
        echo "[rspade-precommit]   ... and $((count - 5)) more" >&2
    fi

    git reset -q HEAD -- system || {
        echo "[rspade-precommit] ERROR: failed to unstage system/ paths; aborting commit." >&2
        exit 1
    }
fi

if git diff --cached --quiet 2>/dev/null; then
    echo "[rspade-precommit] Nothing left to commit after removing system/ paths." >&2
    echo "[rspade-precommit] Framework changes are committed by rsx:framework:pull, not app commits." >&2
    exit 1
fi

exit 0
RSPADE_PRECOMMIT_HOOK

# Already ours AND identical -> nothing to do, stay silent... EXCEPT for the exec bit. Git
# execs a hook file directly (there is no `bash <hook>` seam to lean on), so the bit is
# load-bearing here, and it does not survive every way an environment gets copied (a clone
# from an archive, a cp without -p, an rsync onto a core.fileMode=false repo). Heal it.
if [ -f "$hook" ] && cmp -s "$hook" "$tmp_hook"; then
    if [ ! -x "$hook" ]; then
        chmod +x "$hook" 2>/dev/null || true
        if [ -x "$hook" ]; then
            echo "[env] Restored the executable bit on the RSpade pre-commit hook ($hook)."
        else
            echo "[env] pre-commit: $hook is not executable and chmod +x failed; git will skip it." >&2
            exit 1
        fi
    fi
    exit 0
fi

if ! cp -f "$tmp_hook" "$hook"; then
    echo "[env] pre-commit: failed to write $hook" >&2
    exit 1
fi
chmod +x "$hook" 2>/dev/null || true
if [ ! -x "$hook" ]; then
    echo "[env] pre-commit: wrote $hook but could not make it executable; git will skip it." >&2
    exit 1
fi

echo "[env] Installed the RSpade pre-commit hook ($hook) - keeps system/ out of app commits."
exit 0
