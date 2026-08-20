#!/usr/bin/env bash
#
# Supervisor entry point for rsx-lockd.
#
# Invoke with an explicit interpreter, never by relying on the exec bit:
#   command=bash /var/www/html/system/bin/rsx-lockd/lockd-run.sh
# (`rsx:framework:pull` rsyncs this zone with core.fileMode false downstream, so the exec
# bit is not reliable.)
#
# Why a wrapper at all: supervisor starts at boot, but on a freshly-provisioned or
# mid-update box lockd.js may not be on disk yet - system/ is vendored and arrives with a
# framework pull. Exiting immediately would put the program into supervisor's FATAL state
# after three retries and leave it down until someone noticed. Instead we wait for the
# entry point to appear and then exec node, replacing this shell so supervisor signals
# reach the daemon directly.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENTRY="$SCRIPT_DIR/lockd.js"

NODE_BIN="${NODE_BIN:-}"
if [ -z "$NODE_BIN" ]; then
    NODE_BIN="$(command -v node 2>/dev/null || true)"
fi
if [ -z "$NODE_BIN" ] && [ -x /usr/bin/node ]; then
    NODE_BIN=/usr/bin/node
fi

if [ -z "$NODE_BIN" ]; then
    echo "[lockd-run] [ERROR] node not found on PATH and /usr/bin/node is not executable" >&2
    exit 1
fi

# Wait for the entry point. Announce once immediately and then every 30 iterations (60s)
# so a genuinely missing file is visible in the log rather than a silent spin.
waited=0
while [ ! -f "$ENTRY" ]; do
    if [ "$((waited % 30))" -eq 0 ]; then
        echo "[lockd-run] Waiting for $ENTRY to appear (framework files not delivered yet)" >&2
    fi
    sleep 2
    waited=$((waited + 1))
done

echo "[lockd-run] Starting $ENTRY with $NODE_BIN"
exec "$NODE_BIN" "$ENTRY" run "$@"
