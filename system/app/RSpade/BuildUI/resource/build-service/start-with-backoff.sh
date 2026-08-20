#!/bin/bash
# RSpade Build UI Service Starter with Exponential Backoff
# Retries infinitely with exponential backoff capped at 10 minutes

RETRY_COUNT=0
MAX_DELAY=600  # 10 minutes in seconds
SERVICE_DIR="/var/www/html/system/app/RSpade/BuildUI/resource/build-service"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [BuildUI] $1"
}

while true; do
    log "Starting service (attempt #$((RETRY_COUNT + 1)))"

    # Change to service directory
    cd "$SERVICE_DIR" || {
        log "ERROR: Cannot change to service directory: $SERVICE_DIR"
        sleep 10
        continue
    }

    # Kill any processes using our ports (3100 and 3101)
    log "Cleaning up ports 3100 and 3101..."
    for PORT in 3100 3101; do
        PIDS=$(lsof -ti:$PORT 2>/dev/null)
        if [ -n "$PIDS" ]; then
            log "Killing processes on port $PORT: $PIDS"
            kill -9 $PIDS 2>/dev/null || true
        fi
    done

    # Also kill any orphaned node processes from previous runs (with specific filename)
    pkill -9 -f "rspade-build-ui-server.js" 2>/dev/null || true

    # Give processes time to die
    sleep 1

    # Start the service
    npm start
    EXIT_CODE=$?

    log "Service exited with code $EXIT_CODE"

    # Calculate delay with exponential backoff: 1s, 2s, 4s, 8s, 16s, 32s, 64s, 128s, 256s, 512s, 600s (max)
    if [ $RETRY_COUNT -eq 0 ]; then
        DELAY=1
    else
        # 2^RETRY_COUNT
        DELAY=$((2 ** RETRY_COUNT))

        # Cap at MAX_DELAY (10 minutes)
        if [ $DELAY -gt $MAX_DELAY ]; then
            DELAY=$MAX_DELAY
        fi
    fi

    RETRY_COUNT=$((RETRY_COUNT + 1))

    log "Waiting ${DELAY} seconds before retry..."
    sleep $DELAY
done
