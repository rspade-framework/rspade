#!/bin/bash

# RSpade Test Environment Helpers
#
# Manages test database environment and provides utilities for test setup/teardown

TEST_ENV_BACKUP="/var/www/html/system/.env.testbackup"
TEST_MODE_ACTIVE=false

# Enter test mode - switch to test database
function test_mode_enter() {
    if [ "$TEST_MODE_ACTIVE" = true ]; then
        echo "[ERROR] Test mode already active" >&2
        return 1
    fi

    echo "[TEST ENV] Entering test mode..." >&2

    # Only backup .env if we're NOT already in test mode
    # (Don't want to backup test config and mistake it for production)
    if grep -q "^DB_DATABASE=rspade_test" /var/www/html/.env; then
        echo "[TEST ENV] WARNING: Already using test database - NOT creating backup" >&2
        echo "[TEST ENV] This indicates .env was not properly restored from a previous test run" >&2
        echo "[TEST ENV] Manually restore /var/www/html/.env to production credentials before running tests" >&2
        return 1
    fi

    # Backup current .env (production credentials)
    cp /var/www/html/.env "$TEST_ENV_BACKUP"

    # Switch to test database
    sed -i 's/^DB_DATABASE=.*$/DB_DATABASE=rspade_test/' /var/www/html/.env

    # Add test config to include tests directory in manifest
    if ! grep -q "^RSX_ADDITIONAL_CONFIG=" /var/www/html/.env; then
        echo "RSX_ADDITIONAL_CONFIG=/var/www/html/system/app/RSpade/tests/_lib/rsx_test_config.php" >> /var/www/html/.env
    else
        sed -i 's|^RSX_ADDITIONAL_CONFIG=.*$|RSX_ADDITIONAL_CONFIG=/var/www/html/system/app/RSpade/tests/_lib/rsx_test_config.php|' /var/www/html/.env
    fi

    # Clear Laravel config cache and rebuild manifest
    cd /var/www/html
    php artisan config:clear > /dev/null 2>&1
    php artisan rsx:clean > /dev/null 2>&1

    TEST_MODE_ACTIVE=true
    echo "[TEST ENV] Test mode active (using rspade_test database)" >&2
}

# Exit test mode - restore original database
function test_mode_exit() {
    if [ "$TEST_MODE_ACTIVE" = false ]; then
        return 0
    fi

    echo "[TEST ENV] Exiting test mode..." >&2

    # Restore original .env
    if [ -f "$TEST_ENV_BACKUP" ]; then
        mv "$TEST_ENV_BACKUP" /var/www/html/.env
    fi

    # Clear Laravel config cache and rebuild manifest
    cd /var/www/html
    php artisan config:clear > /dev/null 2>&1
    php artisan rsx:clean > /dev/null 2>&1

    TEST_MODE_ACTIVE=false
    echo "[TEST ENV] Test mode exited (restored original database)" >&2
}

# Trap to ensure test mode always exits
function test_trap_exit() {
    test_mode_exit

    # NEVER remove backup files - they contain production credentials
    # Backup files are process-specific (/tmp/rspade_test_env_backup_$$)
    # and will be cleaned up by system /tmp cleanup
}

# Query helper for test database
function test_db_query() {
    mysql -h127.0.0.1 -urspade -prspadepass rspade_test -N -e "$1" 2>/dev/null
}

# Count helper for test database
function test_db_count() {
    local table=$1
    test_db_query "SELECT COUNT(*) FROM $table"
}
