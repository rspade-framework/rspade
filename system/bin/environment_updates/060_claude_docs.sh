#!/usr/bin/env bash
#
# 060 - Wire the RSpade knowledge tree (system/app/RSpade/docs/) into this environment.
#
# The framework ships ONE knowledge tree, which is also a Claude Code plugin root:
#
#   system/app/RSpade/docs/
#     .claude-plugin/plugin.json   skills: ./skills/shared/ + ./skills/local/
#     claude/{framework.md,app.md,shared/,framework/,app/}   CLAUDE.md fragments
#     skills/{shared,framework,app}/       (manifest lists shared/ + framework/ here;
#                                           publish rewrites framework/ -> app/ in a release)
#
# Two wires activate it, and this script owns both:
#
#   1. SKILLS (both contexts): .claude/skills/rspade -> ../../system/app/RSpade/docs
#      Claude Code loads a directory holding .claude-plugin/plugin.json from .claude/skills/
#      as a PLUGIN, so every skill is namespaced (rspade:jqhtml) and can never collide with
#      an app's own skills. Personal scope (~/.claude/skills) was deliberately NOT used: it
#      OVERRIDES project scope, which would stop a downstream app from ever shadowing a
#      framework skill.
#
#   2. MEMORY (downstream only): the always-on fragments arrive through a CLAUDE.md @import.
#      Plugins cannot ship memory, so rsx/resource/CLAUDE.md - the app developer's OWN file,
#      symlinked from the project root - gets one import line prepended. The path is written
#      RELATIVE TO THE REAL FILE (rsx/resource/), because a relative @import resolves against
#      the real file, never the symlink it was reached through. In the monorepo the equivalent
#      import already lives in the repo's own root CLAUDE.md (authored, not generated) - this
#      script never touches it.
#
# Also retires the CONTAINER-ERA wiring: the dev image used to symlink /root/CLAUDE.md (or
# /root/.claude/CLAUDE.md) at the framework's shipped doc file. That mechanism was
# container-specific (it did nothing on a production host) and is superseded by the import
# chain above. A symlink there POINTING AT THE RSPADE TREE is removed; anything else - a real
# file, a symlink of the developer's own - is left strictly alone.
#
# AUGMENT-NEVER-CLOBBER (contract rule 7): a foreign .claude/skills/rspade entry, or a
# CLAUDE.md that already carries the import, is reported/skipped - never overwritten. And the
# .claude/skills PARENT is never removed and recreated: recreating a watched top-level
# directory mid-session breaks Claude Code's file watcher until it restarts.
#
# TEST SEAM: RSPADE_CLAUDE_HOME_DIR overrides the /root home used by the container-era
# cleanup, so a simulation can exercise that branch without touching the real /root.
#
# See system/bin/environment_updates/CLAUDE.md.

set -uo pipefail

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
SYSTEM_DIR="${SYSTEM_DIR:-$PROJECT_ROOT/system}"
IS_FRAMEWORK_DEVELOPER="${IS_FRAMEWORK_DEVELOPER:-false}"
CLAUDE_HOME_DIR="${RSPADE_CLAUDE_HOME_DIR:-/root}"

errored=false

docs_tree="$SYSTEM_DIR/app/RSpade/docs"
skills_dir="$PROJECT_ROOT/.claude/skills"
link="$skills_dir/rspade"
link_target='../../system/app/RSpade/docs'
import_line='@../../system/app/RSpade/docs/claude/app.md'

# ---------------------------------------------------------------------------
# 1. The skills/plugin symlink. BOTH contexts - the monorepo runs the identical
#    wiring, which is what makes the downstream configuration developable here.
#    Reconcile the ENTRY only, never the parent directory.
# ---------------------------------------------------------------------------
link_created=false
if [ -d "$docs_tree" ]; then
    if [ -L "$link" ]; then
        current="$(readlink "$link" 2>/dev/null || true)"
        resolved="$(readlink -m "$link" 2>/dev/null || true)"
        if [ "$current" = "$link_target" ]; then
            :                                                  # correct already
        elif [ ! -e "$link" ]; then
            # Dead link: nothing is at stake, retarget it.
            if ln -sfn "$link_target" "$link" 2>/dev/null; then
                echo "[env] Repaired the dead .claude/skills/rspade symlink -> $link_target (RSpade skills)."
            else
                echo "[env] claude docs: cannot repair $link" >&2
                errored=true
            fi
        elif [ "${resolved#"$PROJECT_ROOT/system/app/RSpade"}" != "$resolved" ]; then
            # Points somewhere else inside the rspade tree (an earlier spelling) - ours to fix.
            if ln -sfn "$link_target" "$link" 2>/dev/null; then
                echo "[env] Retargeted .claude/skills/rspade -> $link_target (RSpade skills)."
            else
                echo "[env] claude docs: cannot retarget $link" >&2
                errored=true
            fi
        else
            echo "[env] .claude/skills/rspade points outside the framework tree ($current); left untouched." >&2
            echo "[env]   Remove it if you want the RSpade skills, then re-run this update." >&2
        fi
    elif [ -e "$link" ]; then
        echo "[env] .claude/skills/rspade exists and is not a symlink; left untouched (RSpade skills not linked)." >&2
        echo "[env]   Move it aside if you want the RSpade skills, then re-run this update." >&2
    else
        if mkdir -p "$skills_dir" 2>/dev/null && ln -s "$link_target" "$link" 2>/dev/null; then
            echo "[env] Linked .claude/skills/rspade -> $link_target (RSpade framework skills)."
            link_created=true
        else
            echo "[env] claude docs: cannot create $link" >&2
            errored=true
        fi
    fi
fi

# ---------------------------------------------------------------------------
# 2. DOWNSTREAM ONLY from here. Gate before anything that treats rsx/ as the
#    app's own or assumes the container-era home wiring (contract rule 5).
# ---------------------------------------------------------------------------
if [ "$IS_FRAMEWORK_DEVELOPER" != true ]; then

    # 2a. The memory import in the app developer's own CLAUDE.md.
    app_claude="$PROJECT_ROOT/rsx/resource/CLAUDE.md"
    if [ -f "$app_claude" ]; then
        if ! grep -qF -- "$import_line" "$app_claude" 2>/dev/null; then
            tmp="$app_claude.rspade-tmp.$$"
            if { printf '%s\n\n' "$import_line"; cat "$app_claude"; } > "$tmp" 2>/dev/null \
               && cat "$tmp" > "$app_claude" 2>/dev/null; then
                rm -f "$tmp"
                echo "[env] Added the RSpade framework knowledge import to rsx/resource/CLAUDE.md ($import_line)."
            else
                rm -f "$tmp"
                echo "[env] claude docs: cannot rewrite $app_claude" >&2
                errored=true
            fi
        fi
    else
        if mkdir -p "$(dirname "$app_claude")" 2>/dev/null \
           && printf '%s\n\n# Application Notes\n\nYour own always-on instructions go here.\n' "$import_line" > "$app_claude" 2>/dev/null; then
            echo "[env] Created rsx/resource/CLAUDE.md carrying the RSpade framework knowledge import."
        else
            echo "[env] claude docs: cannot create $app_claude" >&2
            errored=true
        fi
    fi

    # 2b. Retire the container-era home symlink. ONLY a symlink, and only one whose
    #     target names the rspade tree or resolves inside this install's system/.
    for home_claude in "$CLAUDE_HOME_DIR/CLAUDE.md" "$CLAUDE_HOME_DIR/.claude/CLAUDE.md"; do
        [ -L "$home_claude" ] || continue
        home_target="$(readlink "$home_claude" 2>/dev/null || true)"
        home_resolved="$(readlink -m "$home_claude" 2>/dev/null || true)"
        ours=false
        case "$home_target" in *rspade*) ours=true ;; esac
        [ "${home_resolved#"$PROJECT_ROOT/system/"}" != "$home_resolved" ] && ours=true
        if [ "$ours" = true ]; then
            if rm -f "$home_claude" 2>/dev/null; then
                echo "[env] Removed the retired container-era symlink $home_claude (superseded by the rsx/resource/CLAUDE.md import)."
            else
                echo "[env] claude docs: cannot remove $home_claude" >&2
                errored=true
            fi
        fi
    done
fi

# ---------------------------------------------------------------------------
# Advisories, printed ONLY on the run that first created the plugin symlink.
# ---------------------------------------------------------------------------
if [ "$link_created" = true ]; then
    echo "[env]   Claude Code will ask once to trust this workspace's plugin; accept it to load the rspade skills."
    echo "[env]   The symlink is left uncommitted; committing it is your team's choice."
fi

[ "$errored" = true ] && exit 1
exit 0
