#!/bin/bash
#
# Run a supervised service, and keep trying if it will not start.
#
#     retry-run.sh <label> <command> [args...]
#
# WHY THIS EXISTS. Several services depend on the application being ready:
# rsx-lockd and the realtime relay authenticate with APP_KEY, the task ticker
# needs a migrated database. On a first run those things arrive DURING startup,
# so a service can quite reasonably fail at 12:00:01 and succeed at 12:00:20.
#
# Supervisor alone handles that badly. It retries a failed start immediately,
# gives up after `startretries`, and parks the program in FATAL - where it stays
# for the life of the container even after the thing it was waiting for exists.
# A developer then finds a dead service and no obvious reason for it.
#
# So: retry forever, and WAIT between attempts. Forever is the honest policy
# because every one of these failures is transient by nature - the application is
# still being built when they happen - and a service that gives up permanently on
# a condition that resolves itself is just a bug with extra steps.
#
# The delay backs off from 2 seconds to a 30-second ceiling, so a genuinely
# broken service costs one log line every half minute instead of spinning a core.
#
set -u

LABEL="${1:?retry-run.sh needs a label}"
shift

[ "$#" -gt 0 ] || { echo "[$LABEL] no command given" >&2; exit 1; }

MIN_DELAY="${RSPADE_RETRY_MIN_DELAY:-2}"
MAX_DELAY="${RSPADE_RETRY_MAX_DELAY:-30}"

delay="$MIN_DELAY"
attempt=0

while true; do
    attempt=$((attempt + 1))

    "$@"
    status=$?

    # A service that ran for a while and then stopped is a different event from
    # one that never started: reset the backoff so a long-lived service that
    # crashes once comes straight back.
    if [ "$status" -eq 0 ]; then
        echo "[$LABEL] exited cleanly; restarting."
        delay="$MIN_DELAY"
    else
        echo "[$LABEL] exited with status ${status} (attempt ${attempt}); retrying in ${delay}s."
    fi

    sleep "$delay"

    delay=$((delay * 2))
    [ "$delay" -gt "$MAX_DELAY" ] && delay="$MAX_DELAY"
done
