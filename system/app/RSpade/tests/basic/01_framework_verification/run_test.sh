#!/bin/bash
set -e

TEST_NAME="Framework Verification"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Source test environment helpers
source "$TEST_DIR/../../_lib/test_env.sh"

# Parse arguments
SKIP_RESET=false
for arg in "$@"; do
    case $arg in
        --skip-reset)
            SKIP_RESET=true
            shift
            ;;
    esac
done

# Ensure test mode exits on script exit (success or failure)
trap test_trap_exit EXIT

# SETUP
echo "[SETUP] Testing framework verification..." >&2

# Verify test environment helpers loaded
if ! type test_mode_enter > /dev/null 2>&1; then
    echo "FAIL: $TEST_NAME - test_mode_enter function not loaded"
    exit 1
fi

# Enter test mode (switches Laravel to rspade_test database and loads test config)
test_mode_enter

# Reset database to known state (unless called as sub-test)
if [ "$SKIP_RESET" = false ]; then
    echo "[SETUP] Resetting test database..." >&2
    "$TEST_DIR/../../_lib/db_reset.sh"
fi

# TEST LOGIC
echo "[TEST] Verifying framework components..." >&2

# Test 1: Verify .env was modified
if grep -q "DB_DATABASE=rspade_test" /var/www/html/.env; then
    echo "[TEST] ✓ Environment switched to test database" >&2
else
    echo "FAIL: $TEST_NAME - .env not modified to use rspade_test"
    exit 1
fi

# Test 2: Verify we can query test database
if ! mysql -h127.0.0.1 -urspade -prspadepass rspade_test -e "SELECT 1" > /dev/null 2>&1; then
    echo "FAIL: $TEST_NAME - Cannot connect to rspade_test database"
    exit 1
fi
echo "[TEST] ✓ Test database accessible" >&2

# Test 3: Verify migrations table exists (migrations ran)
tables=$(mysql -h127.0.0.1 -urspade -prspadepass rspade_test -N -e "SHOW TABLES LIKE 'migrations'" 2>/dev/null)
if [ -z "$tables" ]; then
    echo "FAIL: $TEST_NAME - Migrations table does not exist (migrations did not run)"
    exit 1
fi
echo "[TEST] ✓ Migrations ran successfully" >&2

# Test 4: Verify backup was created
if [ ! -f "$TEST_ENV_BACKUP" ]; then
    echo "FAIL: $TEST_NAME - .env backup not created"
    exit 1
fi
echo "[TEST] ✓ Environment backup created" >&2

# Test 5: Verify test_db_query helper works
test_result=$(test_db_query "SELECT 'works'" 2>/dev/null)
if [ "$test_result" != "works" ]; then
    echo "FAIL: $TEST_NAME - test_db_query helper failed"
    exit 1
fi
echo "[TEST] ✓ Database query helper works" >&2

# All tests passed
echo "PASS: $TEST_NAME"
exit 0

# TEARDOWN happens automatically via trap
