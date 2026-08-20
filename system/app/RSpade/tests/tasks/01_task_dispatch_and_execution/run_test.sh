#!/bin/bash
set -e

TEST_NAME="Task Dispatch and Execution"
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
echo "[SETUP] Preparing task system test..." >&2

# Enter test mode (switches Laravel to rspade_test database and loads test config)
test_mode_enter

# Reset database to known state (unless called as sub-test)
if [ "$SKIP_RESET" = false ]; then
    echo "[SETUP] Resetting test database..." >&2
    "$TEST_DIR/../../_lib/db_reset.sh"
fi

# TEST LOGIC
echo "[TEST] Testing task dispatch and execution..." >&2

cd /var/www/html

# Test 1: Verify test services are available in manifest
echo "[TEST] Verifying test services in manifest..." >&2
php artisan tinker --execute="
\$tasks = App\RSpade\Core\Task\Task::get_scheduled_tasks();
if (count(\$tasks) < 3) {
    echo 'ERROR: Expected at least 3 scheduled tasks, found ' . count(\$tasks);
    exit(1);
}
echo 'OK';
" 2>&1 | grep -q "OK"

if [ $? -ne 0 ]; then
    echo "FAIL: $TEST_NAME - Scheduled tasks not found in manifest"
    exit 1
fi
echo "[TEST] ✓ Test services found in manifest" >&2

# Test 2: Dispatch a task
echo "[TEST] Dispatching task..." >&2
TASK_ID=$(php artisan tinker --execute="
echo App\RSpade\Core\Task\Task::dispatch('Service_Test_Service', 'hello_world', ['name' => 'Test']);
" 2>&1 | tail -1)

if [ -z "$TASK_ID" ] || [ "$TASK_ID" = "0" ]; then
    echo "FAIL: $TEST_NAME - Task dispatch failed"
    exit 1
fi
echo "[TEST] ✓ Task dispatched with ID: $TASK_ID" >&2

# Test 3: Verify task is pending
STATUS=$(test_db_query "SELECT status FROM _tasks WHERE id = $TASK_ID")
if [ "$STATUS" != "pending" ]; then
    echo "FAIL: $TEST_NAME - Task status should be 'pending', got '$STATUS'"
    exit 1
fi
echo "[TEST] ✓ Task status is pending" >&2

# Test 4: Process the task
echo "[TEST] Processing task..." >&2
php artisan rsx:task:process --once --queue=default > /dev/null 2>&1

# Test 5: Verify task completed
STATUS=$(test_db_query "SELECT status FROM _tasks WHERE id = $TASK_ID")
if [ "$STATUS" != "completed" ]; then
    echo "FAIL: $TEST_NAME - Task should be completed, got '$STATUS'"
    exit 1
fi
echo "[TEST] ✓ Task completed successfully" >&2

# Test 6: Verify task result
RESULT=$(test_db_query "SELECT result FROM _tasks WHERE id = $TASK_ID")
if ! echo "$RESULT" | grep -q "Hello, Test"; then
    echo "FAIL: $TEST_NAME - Task result incorrect: $RESULT"
    exit 1
fi
echo "[TEST] ✓ Task result correct" >&2

# Test 7: Verify task logs
LOGS=$(test_db_query "SELECT logs FROM _tasks WHERE id = $TASK_ID")
if ! echo "$LOGS" | grep -q "Hello world task executed"; then
    echo "FAIL: $TEST_NAME - Task logs missing expected content"
    exit 1
fi
echo "[TEST] ✓ Task logs recorded" >&2

# Test 8: Test scheduled task registration
echo "[TEST] Testing scheduled task registration..." >&2

# Clear any existing scheduled task records
test_db_query "DELETE FROM _tasks WHERE next_run_at IS NOT NULL"

# Run task processor to register scheduled tasks
php artisan rsx:task:process > /dev/null 2>&1

# Verify scheduled tasks were registered
SCHEDULED_COUNT=$(test_db_count "_tasks WHERE next_run_at IS NOT NULL")
if [ "$SCHEDULED_COUNT" -lt 3 ]; then
    echo "FAIL: $TEST_NAME - Expected at least 3 scheduled tasks registered, found $SCHEDULED_COUNT"
    exit 1
fi
echo "[TEST] ✓ Scheduled tasks registered: $SCHEDULED_COUNT" >&2

# Test 9: Test force-scheduled flag
echo "[TEST] Testing --force-scheduled flag..." >&2

# Count tasks before
BEFORE_COUNT=$(test_db_count "_tasks WHERE next_run_at IS NULL AND status = 'pending'")

# Force dispatch all scheduled tasks
php artisan rsx:task:process --force-scheduled > /dev/null 2>&1

# Count tasks after
AFTER_COUNT=$(test_db_count "_tasks WHERE next_run_at IS NULL AND status = 'pending'")

if [ "$AFTER_COUNT" -le "$BEFORE_COUNT" ]; then
    echo "FAIL: $TEST_NAME - --force-scheduled should have created new task instances"
    exit 1
fi
echo "[TEST] ✓ Force-scheduled created task instances" >&2

# All tests passed
echo "PASS: $TEST_NAME"
exit 0

# TEARDOWN happens automatically via trap
