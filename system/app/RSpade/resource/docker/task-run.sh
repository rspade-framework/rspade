#!/bin/bash
#
# Supervisor runner for RSpade background work.
#
# THIS IS THE CRON REPLACEMENT. The framework's documented driver for all
# scheduled (#[Schedule]) and dispatched (Task::dispatch()) work is one crontab
# line:
#
#     * * * * * php artisan rsx:task:process
#
# A container has no crontab, so the same once-a-minute tick lives here instead -
# where it is visible in `supervisorctl status` and logs like every other
# service.
#
# Why this matters more than it looks: dispatching a task SUCCEEDS whether or not
# anything ever processes it. With no tick, background work does not fail - it
# queues forever, silently, and the only symptom is that nothing happens.
#
# rsx:task:process is a TICK, not a worker: it enqueues due work, spawns what it
# needs, and returns promptly. The sleep is therefore the cadence, not a timeout,
# and a tick is never interrupted.
#
# A failing tick must not take this program FATAL and stop every future one, so
# the exit status is deliberately swallowed - the failure is on stdout, where
# supervisor records it.
#
set -u

ARTISAN="${RSPADE_ARTISAN:-/var/www/html/system/artisan}"
INTERVAL="${RSPADE_TASK_INTERVAL:-60}"

# The project may not be mounted yet on a very early start. Idle rather than
# crash-looping, exactly as the realtime runner does.
while [ ! -f "$ARTISAN" ]; do
    echo "[tasks] $ARTISAN not present yet - idling ${INTERVAL}s."
    sleep "$INTERVAL"
done

# The FIRST tick waits for the application to actually be able to process work.
# Supervisor starts programs in priority order but does not wait for the earlier
# ones to become healthy, so this program is running while MySQL is still coming
# up - and a tick then fails for a completely uninteresting reason. Retrying it
# quietly, rather than logging a failure on every single container start, is the
# difference between a log that means something and one nobody reads.
until php "$ARTISAN" rsx:task:process >/dev/null 2>&1; do
    sleep 5
done

echo "[tasks] ticking rsx:task:process every ${INTERVAL}s."

# From here a failure IS worth reporting: the application was working a moment
# ago. It is reported and swallowed - one bad tick must not take the program
# FATAL and stop every future one.
while true; do
    sleep "$INTERVAL"
    php "$ARTISAN" rsx:task:process || echo "[tasks] tick failed (continuing)"
done
