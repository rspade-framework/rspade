#!/bin/bash

# RSpade Test Database Snapshot Creator
#
# Creates a pristine snapshot of the test database after running all migrations.
# This snapshot can be used to speed up test database resets.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SNAPSHOT_FILE="$SCRIPT_DIR/test_db_snapshot.sql"
FULL_OUTPUT=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --full-output)
            FULL_OUTPUT=true
            shift
            ;;
    esac
done

echo "[SNAPSHOT] Creating pristine test database snapshot..." >&2

# Source test environment helpers
source "$SCRIPT_DIR/test_env.sh"

# Ensure test mode exits on script exit (success or failure)
trap test_trap_exit EXIT

# Reset database and run ALL migrations from scratch
echo "[SNAPSHOT] Resetting test database..." >&2

if [ "$FULL_OUTPUT" = true ]; then
    # Show full migration output
    "$SCRIPT_DIR/db_reset.sh"
else
    # Suppress output, capture errors
    TEMP_OUTPUT="/tmp/snapshot_output_$$"
    if ! "$SCRIPT_DIR/db_reset.sh" > "$TEMP_OUTPUT" 2>&1; then
        echo "" >&2
        echo "[ERROR] Database provisioning failed" >&2
        echo "[ERROR] Run with --full-output to see details:" >&2
        echo "[ERROR]   $0 --full-output" >&2
        echo "" >&2
        cat "$TEMP_OUTPUT" >&2
        rm -f "$TEMP_OUTPUT"
        exit 1
    fi
    rm -f "$TEMP_OUTPUT"
fi

# Enter test mode to verify database is accessible
test_mode_enter > /dev/null 2>&1

# Verify migrations ran
migration_count=$(test_db_query "SELECT COUNT(*) FROM migrations")
if [ -z "$migration_count" ] || [ "$migration_count" -eq 0 ]; then
    echo "[ERROR] No migrations found in database" >&2
    echo "[ERROR] Run with --full-output to see details:" >&2
    echo "[ERROR]   $0 --full-output" >&2
    exit 1
fi

echo "[SNAPSHOT] Found $migration_count migrations" >&2

# Exit test mode before dump
test_mode_exit > /dev/null 2>&1

# Create snapshot
echo "[SNAPSHOT] Creating snapshot file..." >&2

# Backup current .env
SNAPSHOT_ENV_BACKUP="/tmp/rspade_snapshot_backup_$$"
cp /var/www/html/.env "$SNAPSHOT_ENV_BACKUP"

# Switch to test database for dump
sed -i 's/^DB_DATABASE=.*$/DB_DATABASE=rspade_test/' /var/www/html/.env
php artisan config:clear > /dev/null 2>&1

# Create dump
if ! mysqldump -h127.0.0.1 -urspade -prspadepass rspade_test \
    --no-tablespaces \
    --single-transaction \
    --quick \
    --lock-tables=false \
    > "$SNAPSHOT_FILE" 2>/dev/null; then
    echo "[ERROR] Failed to create database dump" >&2
    mv "$SNAPSHOT_ENV_BACKUP" /var/www/html/.env
    php artisan config:clear > /dev/null 2>&1
    exit 1
fi

# Restore original .env
mv "$SNAPSHOT_ENV_BACKUP" /var/www/html/.env
php artisan config:clear > /dev/null 2>&1

# Get snapshot size
snapshot_size=$(du -h "$SNAPSHOT_FILE" | cut -f1)

echo "[SNAPSHOT] Snapshot created successfully ($snapshot_size)" >&2
echo "[SNAPSHOT] Tests will now use this snapshot for faster database resets" >&2
