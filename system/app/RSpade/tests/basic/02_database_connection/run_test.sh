#!/bin/bash
set -e

TEST_NAME="Database Connection"
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
echo "[SETUP] Preparing database connection test..." >&2

# Enter test mode (switches Laravel to rspade_test database and loads test config)
test_mode_enter

# Reset database to known state (unless called as sub-test)
if [ "$SKIP_RESET" = false ]; then
    "$TEST_DIR/../../_lib/db_reset.sh"
fi

# TEST LOGIC
echo "[TEST] Testing database connection and operations..." >&2

# Test 1: Can we insert data?
test_db_query "CREATE TABLE IF NOT EXISTS rspade_test_temp_users (id INT, name VARCHAR(255))"
test_db_query "TRUNCATE TABLE rspade_test_temp_users"
test_db_query "INSERT INTO rspade_test_temp_users (id, name) VALUES (1, 'Alice'), (2, 'Bob')"
echo "[TEST] ✓ Insert operations work" >&2

# Test 2: Can we query data?
count=$(test_db_count rspade_test_temp_users)
if [ "$count" -ne 2 ]; then
    echo "FAIL: $TEST_NAME - Expected 2 rows, found $count"
    exit 1
fi
echo "[TEST] ✓ Query operations work" >&2

# Test 3: Can we retrieve specific data?
name=$(test_db_query "SELECT name FROM rspade_test_temp_users WHERE id=1")
if [ "$name" != "Alice" ]; then
    echo "FAIL: $TEST_NAME - Expected 'Alice', got '$name'"
    exit 1
fi
echo "[TEST] ✓ Data retrieval works" >&2

# Test 4: Can we update data?
test_db_query "UPDATE rspade_test_temp_users SET name='Charlie' WHERE id=1"
updated_name=$(test_db_query "SELECT name FROM rspade_test_temp_users WHERE id=1")
if [ "$updated_name" != "Charlie" ]; then
    echo "FAIL: $TEST_NAME - Expected 'Charlie', got '$updated_name'"
    exit 1
fi
echo "[TEST] ✓ Update operations work" >&2

# Test 5: Can we delete data?
test_db_query "DELETE FROM rspade_test_temp_users WHERE id=2"
remaining=$(test_db_count rspade_test_temp_users)
if [ "$remaining" -ne 1 ]; then
    echo "FAIL: $TEST_NAME - Expected 1 row after delete, found $remaining"
    exit 1
fi
echo "[TEST] ✓ Delete operations work" >&2

# Cleanup
test_db_query "DROP TABLE IF EXISTS rspade_test_temp_users"

# All tests passed
echo "PASS: $TEST_NAME"
exit 0

# TEARDOWN happens automatically via trap
