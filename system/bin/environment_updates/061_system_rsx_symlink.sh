#!/usr/bin/env bash
#
# 061 - Ensure the system/rsx -> ../rsx app-tree symlink exists.
#
# base_path('rsx') resolves through system/rsx, a tracked symlink pointing one level up
# at the application tree. Every manifest build depends on it ("Manifest scan path does
# not exist: 'rsx'" is the failure when it is gone).
#
# Why it can be gone (2026-08-18 field incident): the symlink is tracked in git but
# structurally ABSENT from the release inventory - the publish inventory drops anything
# that does not resolve to a regular file - and the first cut of the foreign-path
# untracking read "tracked but not in inventory" as contamination, untracked it, and the
# proxy's pristine clean then deleted it from disk. The untracking now exempts
# uninventoriable paths, and this script heals any box the first cut already broke:
# recreate the link when missing, correct it when it points anywhere else.
#
# Contract (environment_updates/CLAUDE.md): self-detecting, idempotent, silent when
# already applied, non-fatal.

set -u

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
LINK="$PROJECT_ROOT/system/rsx"
TARGET="../rsx"

# Nothing to link to (a framework-only checkout has no app tree): not our box, say nothing.
[ -d "$PROJECT_ROOT/rsx" ] || exit 0

if [ -L "$LINK" ]; then
    # Correct link already in place: silent.
    [ "$(readlink "$LINK")" = "$TARGET" ] && exit 0
    rm -f "$LINK" || exit 0
elif [ -e "$LINK" ]; then
    # A real file or directory squatting on the path is NOT ours to delete - report and
    # leave it (non-fatal contract; the manifest build will name the problem loudly).
    echo "[061] system/rsx exists but is not a symlink - leaving it alone (expected: symlink to ../rsx)"
    exit 0
fi

ln -s "$TARGET" "$LINK" || exit 0
echo "[061] Restored the system/rsx -> ../rsx app-tree symlink"
exit 0
