#!/bin/bash
#
# Claude Code launcher for the RSpade container.
#
# Installed as /usr/local/bin/claude when the image is built with --claude.
# Keeps the CLI current and then runs it: Claude Code releases often, and a
# container that pins whatever version happened to exist on build day goes stale
# quietly.
#
# Preferences, auth and history live in ~/.claude, which the shipped
# docker-compose.yml maps to storage/.claude in your project - so they survive
# `docker compose down`, and rebuilding the image does not log you out.
#
set -u

CLAUDE_HOME="${HOME:-/root}"
BIN="$CLAUDE_HOME/.local/bin/claude"
LATEST_URL="https://downloads.claude.ai/claude-code-releases/latest"

run_installer() {
    if [ "$(id -un)" = "root" ] || ! command -v sudo >/dev/null 2>&1; then
        curl -fsSL https://claude.ai/install.sh | CLAUDE_INSTALL_ALLOW_SUDO=1 bash
    else
        curl -fsSL https://claude.ai/install.sh | sudo CLAUDE_INSTALL_ALLOW_SUDO=1 bash
    fi
}

maybe_update() {
    # An escape hatch for anyone who wants a fixed version, or is offline.
    [ "${RSPADE_CLAUDE_NO_UPDATE:-0}" = 1 ] && return 0

    # Not installed - which happens after the binary's own directory is lost to a
    # container recreate, since only ~/.claude is persisted, not ~/.local/bin.
    if [ ! -x "$BIN" ]; then
        echo "claude: Claude Code is not installed here - installing..." >&2
        run_installer || echo "claude: install failed." >&2
        return 0
    fi

    local local_ver latest_ver
    local_ver="$("$BIN" --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"
    [ -n "$local_ver" ] || return 0   # cannot compare - leave the install alone

    # The ONE timeout in this codebase's own idiom that is legitimate: a bounded
    # wait on an EXTERNAL service where expiry degrades to a working outcome. If
    # the release endpoint is slow or unreachable we simply run the version we
    # have, rather than making a version check a prerequisite for starting.
    latest_ver="$(curl -fsSL --connect-timeout 3 --max-time 5 "$LATEST_URL" 2>/dev/null)" || return 0
    [[ "$latest_ver" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]] || return 0   # junk or an error page

    if [ "$local_ver" != "$latest_ver" ]; then
        echo "claude: updating Claude Code ${local_ver} -> ${latest_ver}..." >&2
        run_installer || echo "claude: update failed - starting ${local_ver} anyway." >&2
    fi

    return 0
}

maybe_update || true

if [ ! -x "$BIN" ]; then
    echo "claude: Claude Code could not be installed." >&2
    echo "        Check network access from inside the container, or install it by hand:" >&2
    echo "            curl -fsSL https://claude.ai/install.sh | bash" >&2
    exit 1
fi

exec "$BIN" "$@"
