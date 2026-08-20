#!/usr/bin/env bash
#
# RSpade post-update hook.
#
# Invoked by framework-pull-upstream.sh AFTER a successful framework update has synced +
# rebuilt + committed. Runs every self-detecting environment-update script in
# system/bin/environment_updates/ in sorted order.
#
# WHY THIS EXISTS. framework-pull-upstream.sh cannot safely be edited while it runs (a bash
# interpreter re-reads a script mid-execution with unpredictable results), and an edit to it
# only takes effect on the pull AFTER the one that syncs it. post-update.sh is invoked as a
# SUBPROCESS after the owned-zone sync, so it runs the FRESHLY-SYNCED copy of itself and of
# every environment_updates/ script -- new environment behavior lands on the SAME pull,
# lag-free. So: put durable, evolving update behavior HERE (or in environment_updates/), and
# keep edits to framework-pull-upstream.sh minimal.
#
# CONTRACT for environment_updates/*.sh: each MUST be idempotent + self-detecting -- SILENT
# (no stdout) when its change is already applied, and printing ONLY when it detects an
# unapplied change and applies it. A failing script is reported but NON-FATAL (the framework
# update itself already succeeded). See system/bin/environment_updates/CLAUDE.md.
#
# Context passed to each script via env: PROJECT_ROOT, SYSTEM_DIR, IS_FRAMEWORK_DEVELOPER.

set -uo pipefail

# Resolve context. The updater exports these; the fallbacks let post-update.sh be run by hand.
SYSTEM_DIR="${SYSTEM_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$SYSTEM_DIR/.." && pwd)}"
export SYSTEM_DIR PROJECT_ROOT

# Framework-developer context (the monorepo) vs a downstream app. The updater normally only
# runs downstream, but detect explicitly so environment_updates can gate monorepo-unsafe steps.
if [ -z "${IS_FRAMEWORK_DEVELOPER:-}" ]; then
    if grep -qsE '^[[:space:]]*IS_FRAMEWORK_DEVELOPER[[:space:]]*=[[:space:]]*true' "$PROJECT_ROOT/.env" 2>/dev/null; then
        IS_FRAMEWORK_DEVELOPER=true
    else
        IS_FRAMEWORK_DEVELOPER=false
    fi
fi
export IS_FRAMEWORK_DEVELOPER

# Universal, cheap repo hygiene (both contexts). core.fileMode false silences mode-bit
# churn across the ~100k-file vendored framework tree; it is idempotent and desirable in
# the monorepo as well, so it is NOT gated on IS_FRAMEWORK_DEVELOPER. Moved here out of
# framework-pull-upstream.sh (which stays minimal).
#
# CHECK-THEN-SET, and now in every SUBMODULE too. A submodule carries its OWN config, so
# the root setting says nothing about it - the /internal-libs/ submodules were left
# reporting mode-bit churn on every status. Checking first states the intent plainly
# ("verify this is set") and keeps the common case a read.
if [ "$(git -C "$PROJECT_ROOT" config --get core.fileMode 2>/dev/null)" != "false" ]; then
    git -C "$PROJECT_ROOT" config core.fileMode false >/dev/null 2>&1 || true
fi

git -C "$PROJECT_ROOT" submodule foreach --quiet --recursive '
    if [ "$(git config --get core.fileMode 2>/dev/null)" != "false" ]; then
        git config core.fileMode false >/dev/null 2>&1 || true
    fi
' >/dev/null 2>&1 || true

updates_dir="$SYSTEM_DIR/bin/environment_updates"
[ -d "$updates_dir" ] || exit 0

failed=0
while IFS= read -r script; do
    [ -n "$script" ] || continue
    if ! bash "$script"; then
        echo "[post-update] WARNING: environment update failed (non-fatal): $(basename "$script")" >&2
        failed=$((failed + 1))
    fi
done < <(find "$updates_dir" -maxdepth 1 -name '*.sh' -type f | sort)

if [ "$failed" -gt 0 ]; then
    echo "[post-update] ${failed} environment update(s) reported a failure; the framework update itself succeeded." >&2
fi

exit 0
