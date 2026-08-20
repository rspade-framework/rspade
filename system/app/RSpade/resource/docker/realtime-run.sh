#!/bin/bash
#
# Supervisor runner for the RSpade realtime WebSocket relay.
#
# The relay itself ships with the framework at system/bin/realtime-server.js, in
# the MOUNTED project - so on a checkout whose framework predates the realtime
# feature, or during a framework update, the file may not be there. Running
# `node <missing file>` exits immediately, which supervisor reads as a crash and
# retries, and the program lands in FATAL: a red service that will never recover
# on its own even after the file appears.
#
# So while the script is absent we IDLE. Supervisor sees a healthy RUNNING
# program, and once the file exists we `exec` node in its place - same PID, so
# supervisor manages the relay from then on with real crash handling.
#
# To start it immediately after a framework update rather than waiting for the
# next poll:  supervisorctl restart realtime
#
set -u

JS="${REALTIME_JS:-/var/www/html/system/bin/realtime-server.js}"
INTERVAL="${REALTIME_WAIT_INTERVAL:-300}"

while [ ! -s "$JS" ]; do
    echo "[realtime] $JS not present yet - idling ${INTERVAL}s. Update the framework, then 'supervisorctl restart realtime' to start immediately."
    sleep "$INTERVAL"
done

echo "[realtime] found $JS - starting relay."
exec node "$JS"
