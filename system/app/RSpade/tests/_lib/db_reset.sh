#!/bin/bash

# RSpade Test Database Reset Script
#
# Drops and recreates rspade_test database, then either:
# 1. Restores from snapshot (fast) + runs any new migrations
# 2. Runs all migrations from scratch (slow, if no snapshot exists)
#
# MUST be called from within test mode (after test_mode_enter)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SNAPSHOT_FILE="$SCRIPT_DIR/test_db_snapshot.sql"

# SAFETY CHECK: Ensure we're in test mode
if ! grep -q "^DB_DATABASE=rspade_test" /var/www/html/.env; then
    echo "FATAL ERROR: db_reset.sh must be called from within test mode!" >&2
    echo "" >&2
    echo "Current database: $(grep "^DB_DATABASE=" /var/www/html/.env | cut -d= -f2)" >&2
    echo "" >&2
    echo "This is a safety check to prevent accidentally resetting the production database." >&2
    echo "" >&2
    echo "Correct usage:" >&2
    echo "  source /system/app/RSpade/tests/_lib/test_env.sh" >&2
    echo "  test_mode_enter" >&2
    echo "  _lib/db_reset.sh" >&2
    echo "  # ... run tests ..." >&2
    echo "  test_mode_exit" >&2
    exit 1
fi

if ! grep -q "^RSX_ADDITIONAL_CONFIG=.*rsx_test_config.php" /var/www/html/.env; then
    echo "FATAL ERROR: Test config not loaded!" >&2
    echo "" >&2
    echo "RSX_ADDITIONAL_CONFIG must point to rsx_test_config.php when running tests." >&2
    echo "This should be set automatically by test_mode_enter." >&2
    echo "" >&2
    echo "Current RSX_ADDITIONAL_CONFIG: $(grep "^RSX_ADDITIONAL_CONFIG=" /var/www/html/.env || echo 'NOT SET')" >&2
    exit 1
fi

echo "[DB RESET] Dropping rspade_test database..." >&2
mysql -h127.0.0.1 -urspade -prspadepass -e "DROP DATABASE IF EXISTS rspade_test" 2>/dev/null

echo "[DB RESET] Creating rspade_test database..." >&2
mysql -h127.0.0.1 -urspade -prspadepass -e "CREATE DATABASE rspade_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null

cd /var/www/html

if [ -f "$SNAPSHOT_FILE" ]; then
    # Fast path: Restore from snapshot
    echo "[DB RESET] Restoring from snapshot..." >&2
    mysql -h127.0.0.1 -urspade -prspadepass rspade_test < "$SNAPSHOT_FILE" 2>/dev/null

    # Run any new migrations that were added after snapshot
    echo "[DB RESET] Running new migrations..." >&2
    php artisan migrate --production 2>&1 | grep -v "Nothing to migrate" >&2
else
    # Slow path: Run all migrations from scratch
    echo "[DB RESET] No snapshot found, running all migrations..." >&2
    echo "[DB RESET] (Create snapshot with: _lib/db_snapshot_create.sh)" >&2
    php artisan migrate --production 2>&1 | grep -v "Nothing to migrate" >&2
fi

echo "[DB RESET] Database reset complete" >&2
