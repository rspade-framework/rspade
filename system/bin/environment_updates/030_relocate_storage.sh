#!/usr/bin/env bash
#
# 030 - One-time relocation of volatile storage: system/storage -> <project>/storage.
#
# system/ is the framework-owned zone (hard-synced, wholesale-committed by the updater).
# Volatile runtime data - the file-attachment blob store, thumbnails, renditions, logs,
# build artifacts, locks, db snapshots - has no business living inside it. This moves the
# whole tree up to the project root ONCE and drops the marker file
# <project>/storage/.rspade_storage_relocated, which is what every consumer keys on:
#   - system/bootstrap/app.php  -> $app->useStoragePath(), so all storage_path() follows
#   - system/artisan + system/public/index.php (pre-boot maintenance gate)
#   - system/bin/framework-pull-upstream.sh (storage_base)
# Non-existence of system/storage is NOT the signal - the marker is.
#
# When BOTH trees hold data (a long-lived checkout with pre-relocation debris at the
# project root), the trees are MERGED - system/storage is the live side (every
# storage_path() consumer has been writing there) and <project>/storage becomes
# canonical. Owner ruling 2026-08-05: the ONLY thing that stops the merge is a FILE
# collision with DIFFERING content on the two sides - directory name collisions simply
# recurse, and an identical file on both sides is not a conflict. A zero-byte file on
# either side is also not a conflict (it holds nothing to lose - the non-empty side
# wins; think stale empty logs/laravel.log debris vs the live log). A real conflict
# aborts with the specific colliding paths listed, having changed nothing.
#
# Applies in BOTH contexts (monorepo + downstream); storage is volatile everywhere.
# Same filesystem, so moves are instant renames.
# See system/bin/environment_updates/CLAUDE.md.

set -uo pipefail

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
SYSTEM_DIR="${SYSTEM_DIR:-$PROJECT_ROOT/system}"

root="$PROJECT_ROOT"
marker="$root/storage/.rspade_storage_relocated"
old_storage="$SYSTEM_DIR/storage"

# ---------------------------------------------------------------------------
# .gitignore: volatile storage must never be committed. Mirrors the block that
# system/.gitignore carried for the old location (rsx-build/cdn-cache is the one
# build artifact that IS tracked). Runs BEFORE the marker check so an already-
# relocated checkout still acquires the lines. Idempotent: append only what is
# missing, exact-line match.
# ---------------------------------------------------------------------------
gitignore="$root/.gitignore"
if [ -f "$gitignore" ]; then
    missing=()
    for line in '/storage/*' '!/storage/rsx-build/' '/storage/rsx-build/*' '!/storage/rsx-build/cdn-cache/'; do
        grep -qxF -- "$line" "$gitignore" 2>/dev/null || missing+=("$line")
    done
    if [ "${#missing[@]}" -gt 0 ]; then
        {
            printf '\n# Volatile storage (relocated out of system/); cdn-cache is a tracked build input.\n'
            printf '%s\n' "${missing[@]}"
        } >> "$gitignore"
        echo "[env] Added ${#missing[@]} storage ignore rule(s) to .gitignore."
    fi
fi

# ---------------------------------------------------------------------------
# Untrack storage files git still carries. Pre-relocation debris at the project
# root may be TRACKED; the ignore rules above do not affect already-tracked
# files, so drop them from the index (working tree untouched). check-ignore
# computes exactly the set the new rules cover - which automatically preserves
# the deliberate keep (storage/rsx-build/cdn-cache). Runs before the marker
# check so an already-relocated checkout is cleaned too. The removal lands
# STAGED; it rides the developer's next commit.
# git env stripped: this can run from inside a git hook (see Clean_Command).
# ---------------------------------------------------------------------------
if env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C "$root" rev-parse --git-dir >/dev/null 2>&1; then
    untrack="$(env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C "$root" ls-files -z -- storage 2>/dev/null \
        | env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C "$root" check-ignore -z --stdin --no-index 2>/dev/null \
        | tr '\0' '\n' | grep -c . || true)"
    if [ "${untrack:-0}" -gt 0 ]; then
        env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C "$root" ls-files -z -- storage 2>/dev/null \
            | env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C "$root" check-ignore -z --stdin --no-index 2>/dev/null \
            | env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C "$root" rm --cached -q --force --pathspec-from-file=- --pathspec-file-nul 2>/dev/null \
            && echo "[env] Untracked $untrack ignored file(s) under storage/ from git (removal staged; files kept on disk)."
    fi
fi

# Already relocated -> nothing to do.
if [ -f "$marker" ]; then
    exit 0
fi

mkdir -p "$root/storage" || { echo "[env] storage relocation: cannot create $root/storage" >&2; exit 1; }

merged=false
if [ -d "$old_storage" ]; then
    existing="$(find "$root/storage" -mindepth 1 -maxdepth 1 ! -name '.rspade_storage_relocated' -print -quit 2>/dev/null)"

    if [ -z "$existing" ]; then
        # Destination empty: plain move of every top-level entry, dotfiles included
        # (never `mv dir/*`, which misses them).
        failed=0
        while IFS= read -r -d '' entry; do
            mv -f "$entry" "$root/storage/" || failed=$((failed + 1))
        done < <(find "$old_storage" -mindepth 1 -maxdepth 1 -print0 2>/dev/null)

        if [ "$failed" -gt 0 ] || ! rmdir "$old_storage" 2>/dev/null; then
            echo "[env] storage relocation INCOMPLETE: $old_storage still holds entries:" >&2
            find "$old_storage" -mindepth 1 -maxdepth 1 2>/dev/null | sed 's/^/[env]   /' >&2
            echo "[env]   No marker written; storage still resolves to $old_storage. Resolve and re-run." >&2
            exit 1
        fi
        merged=true
    else
        # Both trees populated: MERGE, system/storage side wins existence but never
        # overwrites differing content.
        #
        # Pass 1 - conflict scan, changes NOTHING. A conflict is: the same relative
        # path is a file (or symlink) on both sides with differing content, or a
        # file-vs-directory type clash. Identical files are fine (dedup below);
        # directory collisions are fine (contents recurse).
        conflicts=()
        while IFS= read -r -d '' src; do
            rel="${src#"$old_storage"/}"
            dest="$root/storage/$rel"
            [ -e "$dest" ] || [ -L "$dest" ] || continue
            if [ -L "$src" ] || [ -L "$dest" ]; then
                [ -L "$src" ] && [ -L "$dest" ] && [ "$(readlink "$src")" = "$(readlink "$dest")" ] && continue
                conflicts+=("$rel")
            elif [ -d "$dest" ]; then
                conflicts+=("$rel (file in system/storage, directory in storage/)")
            elif cmp -s "$src" "$dest"; then
                : # byte-identical - not a conflict
            elif [ ! -s "$src" ] || [ ! -s "$dest" ]; then
                : # one side is zero bytes - nothing to lose; the non-empty side wins in pass 2
            else
                conflicts+=("$rel")
            fi
        done < <(find "$old_storage" -mindepth 1 \( -type f -o -type l \) -print0 2>/dev/null)
        while IFS= read -r -d '' src; do
            rel="${src#"$old_storage"/}"
            dest="$root/storage/$rel"
            if [ -e "$dest" ] && [ ! -d "$dest" ]; then
                conflicts+=("$rel (directory in system/storage, file in storage/)")
            fi
        done < <(find "$old_storage" -mindepth 1 -type d -print0 2>/dev/null)

        if [ "${#conflicts[@]}" -gt 0 ]; then
            echo "[env] storage relocation ABORTED: ${#conflicts[@]} path(s) collide with DIFFERING content between $old_storage and $root/storage:" >&2
            printf '[env]   %s\n' "${conflicts[@]}" >&2
            echo "[env]   Nothing was changed. Resolve each path (keep one side), then re-run:" >&2
            echo "[env]   bash $SYSTEM_DIR/bin/environment_updates/030_relocate_storage.sh" >&2
            exit 1
        fi

        # Pass 2 - apply. Directory skeleton first, then every file/symlink: move
        # when the destination is absent, drop the source when it is the identical
        # duplicate pass 1 already vetted. Then the emptied live tree goes away -
        # <project>/storage is canonical from here on.
        failed=0
        while IFS= read -r -d '' src; do
            mkdir -p "$root/storage/${src#"$old_storage"/}" || failed=$((failed + 1))
        done < <(find "$old_storage" -mindepth 1 -type d -print0 2>/dev/null)
        while IFS= read -r -d '' src; do
            rel="${src#"$old_storage"/}"
            dest="$root/storage/$rel"
            if [ -e "$dest" ] || [ -L "$dest" ]; then
                if [ -f "$dest" ] && [ ! -L "$dest" ] && [ ! -s "$dest" ] && [ -s "$src" ]; then
                    # Zero-byte destination vs non-empty source: the empty file is
                    # debris pass 1 vetted as lossless - the live content replaces it.
                    mv -f "$src" "$dest" || failed=$((failed + 1))
                else
                    rm -f "$src" || failed=$((failed + 1))
                fi
            else
                mv -f "$src" "$dest" || failed=$((failed + 1))
            fi
        done < <(find "$old_storage" -mindepth 1 \( -type f -o -type l \) -print0 2>/dev/null)

        if [ "$failed" -gt 0 ] || ! rm -rf "$old_storage" 2>/dev/null || [ -d "$old_storage" ]; then
            echo "[env] storage relocation INCOMPLETE: $old_storage could not be fully merged/removed:" >&2
            find "$old_storage" -mindepth 1 -maxdepth 1 2>/dev/null | sed 's/^/[env]   /' >&2
            echo "[env]   No marker written; storage still resolves to $old_storage. Resolve and re-run." >&2
            exit 1
        fi
        merged=true
    fi
fi

printf 'storage relocated from system/storage on %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$marker" \
    || { echo "[env] storage relocation: failed to write $marker" >&2; exit 1; }

# ---------------------------------------------------------------------------
# REAP THE HELPERS THIS MOVE JUST ORPHANED.
#
# The node RPC helpers (js-parser, js-transformer, minify, jqhtml-compile,
# js-sanitizer, js-code-quality) are long-lived daemons bound to a unix socket
# under <storage>/rsx-tmp/. Every one of them takes its socket path as an argv
# argument AT SPAWN TIME and never reconsiders it, so moving storage strands them
# on a directory that no longer exists: they keep running, keep serving a dead
# path, and the next build cannot use them.
#
# A downstream box hit exactly this (2026-08-11) - eight daemons still bound to
# system/storage/rsx-tmp/ hours after the relocation, and rsx:bundle:compile
# failing 6 of 6 bundles through several pulls that each reported success. Nothing
# in this script had ever heard of a daemon.
#
# Killing them is free: they hold no state, they are respawned on demand by the
# next PHP process that needs one, and they were already useless. The match is
# deliberately narrow - a node process whose command line names a socket under the
# OLD storage root - so a daemon already running on the NEW path is untouched and
# no unrelated node process can be caught.
#
# GENERAL RULE (see bin/CLAUDE.md): any framework operation that changes a socket
# or state directory must reap the helpers bound to the previous one.
# ---------------------------------------------------------------------------
if command -v pgrep >/dev/null 2>&1; then
    orphans="$(pgrep -f -- "--socket=${old_storage}/rsx-tmp/" 2>/dev/null || true)"
    if [ -n "$orphans" ]; then
        count="$(printf '%s\n' "$orphans" | grep -c .)"
        # TERM first; these are cooperative node servers that close their socket on
        # SIGTERM. A survivor gets KILL - it is a stranded process on a dead path and
        # there is nothing in it worth preserving. NO TIMEOUT: one settle pass, then
        # whatever is still there goes.
        printf '%s\n' "$orphans" | xargs -r kill 2>/dev/null || true
        printf '%s\n' "$orphans" | xargs -r -I{} bash -c 'kill -0 {} 2>/dev/null && kill -9 {} 2>/dev/null' || true
        echo "[env] Reaped $count RPC helper daemon(s) left bound to the old storage path."
    fi
fi

# The framework-update maintenance flag lives under rsx-framework/ and may have just been
# carried along by the move. The updater that raised it (an older release, running this
# very pull) will try to clear it at the OLD path and silently miss - leaving the app 503
# forever. Clear it here: by this point the pull's destructive work is long finished
# (sync, rebuild and commit all completed before post-update runs).
rm -f "$root/storage/rsx-framework/.maintenance.mode.framework.update" 2>/dev/null || true
# The rsx-update/ spelling covers exactly one transition: the pull that SHIPS the
# rsx-framework/ relocation is driven by the previous release's updater, which raised
# the flag at the old path. Nothing after that pull can create it.
rm -f "$root/storage/rsx-update/.maintenance.mode.framework.update" 2>/dev/null || true

if [ "$merged" = true ]; then
    echo "[env] Relocated volatile storage: system/storage -> storage/ (marker: storage/.rspade_storage_relocated)."
else
    echo "[env] Storage root established at storage/ (marker: storage/.rspade_storage_relocated)."
fi
exit 0
