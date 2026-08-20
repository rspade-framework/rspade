# RSpade Testing Framework - AI Agent Instructions

**PURPOSE**: Comprehensive guidelines for AI agents working with RSpade framework tests.

## TESTING PHILOSOPHY

### The "Press Button, Light Turns Green" Philosophy

**Analogy**: You have a button that should turn a light green when pressed. If the button doesn't turn the light green, you **DO NOT**:
- Paint the light green with a brush
- Manually flip the light switch
- Bypass the button with a workaround
- Mark the test as passing

Instead, you **FIX THE BUTTON** so pressing it turns the light green.

**Application to Testing**:
- Tests must be **fully automated** - one command to run, no manual intervention
- Tests must be **deterministic** - same input always produces same output
- Tests must be **repeatable** - can run multiple times with identical results
- Tests must **require zero human assistance** to complete

**Manual intervention to help a test complete is FORBIDDEN.**

If you find yourself:
- Running commands manually to fix test database state
- Switching to production database because test database has issues
- Modifying files between test runs to make tests pass
- Skipping test assertions because they're "too hard to fix"

**STOP IMMEDIATELY** and report the issue to the user. These are symptoms that the test infrastructure is broken and needs fixing, NOT that you should work around the problem.

## CRITICAL TESTING MANDATES

**ABSOLUTE REQUIREMENTS - NO EXCEPTIONS:**

### 1. NEVER Switch to Production Database

Tests **MUST** pass on the test database (`rspade_test`).

**Why**: Switching databases to avoid errors completely defeats the purpose of testing. The test database IS the controlled environment where tests should work. If they don't work there, there's a real problem to fix.

**Forbidden**:
```bash
# ❌ WRONG - Bypassing test database
mysql -urspade -prspadepass rspade -e "SELECT * FROM users"  # Using production DB

# ❌ WRONG - Modifying test to use production
DB_DATABASE=rspade ./run_test.sh
```

**Correct**:
```bash
# ✅ CORRECT - Tests use test database exclusively
test_db_query "SELECT * FROM users"  # Helper function for test DB only
```

### 2. NEVER Mark a Test as Passing If It's Failing

Do not write code to circumvent test failures or skip over difficult errors.

**Why**: Failing tests indicate real problems that must be fixed. A bypassed test is worse than no test - it creates false confidence.

**Forbidden**:
```bash
# ❌ WRONG - Hiding failures
if ! some_test_command; then
    echo "PASS: Test (ignored failure)"  # Lying about pass
    exit 0
fi

# ❌ WRONG - Skipping hard assertions
# if [ "$count" -ne 5 ]; then
#     echo "FAIL"  # Commented out because "too hard to fix"
# fi
```

**Correct**:
```bash
# ✅ CORRECT - Fail loud and clear
if [ "$count" -ne 5 ]; then
    echo "FAIL: Expected 5 records, found $count"
    exit 1
fi
```

### 3. When a Test Fails: Fix the Error OR Ask the User

**Priority order**:
1. **Analyze the error** - Understand what's actually wrong
2. **Attempt to fix the underlying issue** - Fix the button, not paint the light
3. **If unclear or complex, STOP and ask user for direction** - Better to ask than guess
4. **NEVER work around the failure** - Workarounds defeat the purpose

**Why**: Unexpected test errors are valuable - they reveal real issues. Simply skipping over them completely defeats the point of doing tests. The entire purpose of testing is to uncover unforeseen issues.

**Example - Wrong Approach**:
```bash
# Test fails because migrations didn't run
# ❌ WRONG - Manually run migrations
php artisan migrate --force
# ❌ WRONG - Skip the test
exit 0
# ❌ WRONG - Switch to production database where migrations exist
```

**Example - Correct Approach**:
```bash
# Test fails because migrations didn't run
# ✅ CORRECT - Fix db_reset.sh to run migrations automatically
# Update the db_reset.sh script to include migration step
# Now test runs migrations automatically and passes
```

**When to ask user**:
- Error is cryptic or unclear
- Multiple potential solutions exist
- Fix would require architectural changes
- Uncertain if behavior is intentional or a bug

**When NOT to ask user** (fix it yourself):
- Simple typos or syntax errors
- Missing files that should obviously exist
- Clear logic errors in test code
- Standard debugging (add logging, check return values, etc.)

## TEST STRUCTURE

### Test Location

**All framework tests**: `/system/app/RSpade/tests/`

```
/system/app/RSpade/tests/
├── CLAUDE.md                       # This file - AI agent instructions
├── README.md                       # Human-readable test documentation
├── _lib/                           # Test utilities and helpers
│   ├── test_env.sh                 # Test mode management functions
│   ├── rsx_test_config.php         # Manifest config for test mode
│   ├── db_reset.sh                 # Database reset utility
│   ├── db_snapshot_create.sh       # Create database snapshot
│   └── db_snapshot_restore.sh      # Restore database snapshot
├── services/                       # Test service classes
│   ├── service_test_service.php    # Example test service
│   └── scheduled_test_service.php  # Scheduled task tests
├── basic/                          # Basic framework tests
│   ├── 01_framework_verification/
│   │   ├── run_test.sh             # Test script
│   │   └── README.md               # Test documentation
│   └── 02_database_connection/
│       ├── run_test.sh
│       └── README.md
└── tasks/                          # Task system tests
    └── 01_task_dispatch_and_execution/
        ├── run_test.sh
        └── README.md
```

### Test Script Pattern

Every test follows this pattern:

```bash
#!/bin/bash
set -e  # Exit on any error

TEST_NAME="Descriptive Test Name"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Source test environment helpers
source "$TEST_DIR/../../_lib/test_env.sh"

# Parse arguments (for test composition)
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
echo "[SETUP] Preparing test..." >&2

# Enter test mode (switches to rspade_test database)
test_mode_enter

# Reset database to known state (unless called as sub-test)
if [ "$SKIP_RESET" = false ]; then
    echo "[SETUP] Resetting test database..." >&2
    "$TEST_DIR/../../_lib/db_reset.sh"
fi

# TEST LOGIC
echo "[TEST] Testing feature..." >&2

# Test assertions here
# Use test_db_query, test_db_count helpers
# Fail loud with clear error messages

# All tests passed
echo "PASS: $TEST_NAME"
exit 0

# TEARDOWN happens automatically via trap
```

**Key elements**:
- `set -e` - Any command failure fails the entire test
- `trap test_trap_exit EXIT` - Ensures cleanup even on failure
- `test_mode_enter` - Switches to test database
- `db_reset.sh` - Ensures known database state
- `--skip-reset` - For test composition (calling tests from other tests)
- Clear `[SETUP]` and `[TEST]` prefixes in output
- `echo ... >&2` - Test output goes to stderr, only final result to stdout

## TEST ENVIRONMENT MANAGEMENT

### Test Mode Functions

**Source the helpers** (required in every test script):
```bash
source /system/app/RSpade/tests/_lib/test_env.sh
```

**Available functions**:

#### test_mode_enter()
Switches environment to test mode.

**What it does**:
1. Checks if already in test mode (fails if `DB_DATABASE=rspade_test`)
2. Backs up current `.env` to `/var/www/html/system/.env.testbackup`
3. Modifies `.env` to set `DB_DATABASE=rspade_test`
4. Sets `RSX_ADDITIONAL_CONFIG=/var/www/html/system/app/RSpade/tests/_lib/rsx_test_config.php`
5. Clears Laravel config cache
6. Rebuilds manifest to include test directory

**Safety**: Refuses to create backup if already in test mode (prevents backing up test config as production).

#### test_mode_exit()
Restores production environment.

**What it does**:
1. Restores original `.env` from backup
2. Clears Laravel config cache
3. Rebuilds manifest without test directory

**Note**: Automatically called by `test_trap_exit` trap handler.

#### test_trap_exit()
Cleanup handler for test scripts.

**Usage**:
```bash
trap test_trap_exit EXIT
```

**What it does**:
- Calls `test_mode_exit()` to restore environment
- Does NOT delete backup file (contains production credentials)

#### test_db_query(sql)
Execute SQL query on test database, return single value.

**Usage**:
```bash
name=$(test_db_query "SELECT name FROM users WHERE id=1")
status=$(test_db_query "SELECT status FROM _tasks WHERE id=$task_id")
```

**Returns**: Single value from first column of first row (or empty string if no results).

#### test_db_count(table [WHERE clause])
Count rows in test database table.

**Usage**:
```bash
count=$(test_db_count "users")
count=$(test_db_count "users WHERE active=1")
count=$(test_db_count "_tasks WHERE status='pending'")
```

**Returns**: Integer count of matching rows.

### Test Database Isolation

**CRITICAL**: Tests NEVER touch the production database (`rspade`). They use a completely separate database (`rspade_test`).

**Environment Variables During Test Mode**:
- `DB_DATABASE=rspade_test` - Points to test database
- `RSX_ADDITIONAL_CONFIG=.../rsx_test_config.php` - Loads test manifest config

**Test Manifest Config** (`/system/app/RSpade/tests/_lib/rsx_test_config.php`):
```php
return [
    'manifest' => [
        'scan_directories' => [
            'app/RSpade/tests',  // Include framework tests in manifest
        ],
    ],
];
```

This allows test services, tasks, and other test code to be discovered by the manifest system **only when in test mode**, without polluting the production manifest.

### Database Reset Process

**Script**: `/system/app/RSpade/tests/_lib/db_reset.sh`

**What it does**:
1. **Safety checks** - Verifies we're in test mode, fails loud if not
2. **Drop/recreate** - `DROP DATABASE rspade_test; CREATE DATABASE rspade_test;`
3. **Restore snapshot** - If snapshot exists, restore it (fast)
4. **Run migrations** - `php artisan migrate --production` (runs any new migrations not in snapshot)

**Safety checks**:
```bash
# Ensures DB_DATABASE=rspade_test (fails if not)
if ! grep -q "^DB_DATABASE=rspade_test" /var/www/html/.env; then
    echo "FATAL ERROR: db_reset.sh must be called from within test mode!"
    exit 1
fi

# Ensures test config is loaded (fails if not)
if ! grep -q "^RSX_ADDITIONAL_CONFIG=.*rsx_test_config.php" /var/www/html/.env; then
    echo "FATAL ERROR: Test config not loaded!"
    exit 1
fi
```

**When to call**:
- At the beginning of every test (unless using `--skip-reset` for test composition)
- Ensures known database state for deterministic tests

**When NOT to call manually**:
- Tests call it automatically - no manual intervention needed

## TEST COMPOSITION

### The --skip-reset Flag

**Purpose**: Allows tests to call other tests without resetting database state between them.

**Use case**: Building complex state by chaining simpler tests.

**Example**:
```bash
# Test A creates a user
./tests/users/create_user.sh

# Test B needs a user to exist, calls Test A with --skip-reset
./tests/users/create_user.sh --skip-reset
# Now test database has a user from Test A
# Test B can proceed with its logic without losing that state
```

**Pattern**:
```bash
# In your test script
SKIP_RESET=false
for arg in "$@"; do
    case $arg in
        --skip-reset)
            SKIP_RESET=true
            shift
            ;;
    esac
done

# Later in setup
if [ "$SKIP_RESET" = false ]; then
    "$TEST_DIR/../../_lib/db_reset.sh"
fi
```

**When to use**:
- Calling prerequisite tests to set up state
- Composing complex tests from simpler building blocks
- Chaining tests that depend on each other's state

**When NOT to use**:
- Running tests independently (always reset for determinism)
- Tests should be runnable in isolation without `--skip-reset` by default

## WRITING NEW TESTS

### Step 1: Create Test Directory

```bash
mkdir -p /system/app/RSpade/tests/category/NN_test_name
cd /system/app/RSpade/tests/category/NN_test_name
```

**Naming**:
- `category/` - Group of related tests (basic, tasks, database, etc.)
- `NN_` - Two-digit prefix for ordering (01_, 02_, etc.)
- `test_name` - Descriptive name with underscores

### Step 2: Create run_test.sh

```bash
#!/bin/bash
set -e

TEST_NAME="Descriptive Test Name"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

source "$TEST_DIR/../../_lib/test_env.sh"

# --skip-reset support
SKIP_RESET=false
for arg in "$@"; do
    case $arg in
        --skip-reset) SKIP_RESET=true; shift ;;
    esac
done

trap test_trap_exit EXIT

# SETUP
echo "[SETUP] Preparing test..." >&2
test_mode_enter

if [ "$SKIP_RESET" = false ]; then
    echo "[SETUP] Resetting test database..." >&2
    "$TEST_DIR/../../_lib/db_reset.sh"
fi

# TEST LOGIC
echo "[TEST] Testing feature..." >&2

# Your test code here
# Use test_db_query and test_db_count helpers

# Example assertion
result=$(test_db_query "SELECT COUNT(*) FROM users")
if [ "$result" != "0" ]; then
    echo "FAIL: $TEST_NAME - Expected 0 users, found $result"
    exit 1
fi

# All tests passed
echo "PASS: $TEST_NAME"
exit 0
```

Make it executable:
```bash
chmod +x run_test.sh
```

### Step 3: Create README.md

Document what the test verifies, prerequisites, expected output, etc. See existing test READMEs for examples.

### Step 4: Test Your Test

**Critical**: Run your test multiple times to verify it's deterministic and repeatable.

```bash
# Run once
./run_test.sh

# Run again immediately - should pass again
./run_test.sh

# Run a third time - still should pass
./run_test.sh
```

**If it fails on subsequent runs**:
- Test is not properly resetting state
- Test is leaving artifacts in database
- Test has race conditions or timing issues
- **FIX THE TEST** - don't work around it

### Writing Good Test Assertions

**Fail loud with clear error messages**:

```bash
# ❌ BAD - Cryptic failure
if [ "$count" != "5" ]; then
    exit 1
fi

# ✅ GOOD - Clear failure message
if [ "$count" != "5" ]; then
    echo "FAIL: $TEST_NAME - Expected 5 records, found $count"
    exit 1
fi
```

**Test one thing per assertion**:

```bash
# ✅ GOOD - Separate assertions
if [ "$status" != "completed" ]; then
    echo "FAIL: $TEST_NAME - Expected status 'completed', got '$status'"
    exit 1
fi

if [ -z "$result" ]; then
    echo "FAIL: $TEST_NAME - Expected result, got empty string"
    exit 1
fi
```

**Use helper functions**:

```bash
# ✅ GOOD - Using test_db_count helper
count=$(test_db_count "_tasks WHERE status='pending'")
if [ "$count" -ne 1 ]; then
    echo "FAIL: $TEST_NAME - Expected 1 pending task, found $count"
    exit 1
fi
```

## DEBUGGING FAILED TESTS

### Step 1: Read the Error Message

Failed tests should output clear error messages. Read them carefully.

```bash
[TEST] ✓ Task dispatched with ID: 1
FAIL: Task Dispatch and Execution - Expected status 'completed', got 'pending'
```

**Analysis**: Task was dispatched but never completed. Issue is in task processing, not dispatch.

### Step 2: Add Debugging Output

Add temporary debugging to understand what's happening:

```bash
# Add debugging
echo "[DEBUG] Task ID: $task_id" >&2
echo "[DEBUG] Status: $status" >&2
echo "[DEBUG] Result: $result" >&2

# Run test
./run_test.sh
```

### Step 3: Query Database Directly

Use test helpers to inspect database state:

```bash
# In your test script
test_db_query "SELECT * FROM _tasks WHERE id=$task_id" >&2
```

### Step 4: Verify Test Database State

Check that test database is properly reset:

```bash
source _lib/test_env.sh
test_mode_enter
test_db_query "SHOW TABLES"
test_mode_exit
```

### Step 5: Fix Root Cause

**DO NOT**:
- Skip the failing assertion
- Work around the failure with try/catch or || true
- Switch to production database
- Modify the test to expect wrong behavior

**DO**:
- Fix the underlying code being tested
- Fix the test infrastructure (db_reset, test_env, etc.)
- Ask user for guidance if unclear

## COMMON MISTAKES

### Mistake 1: Manual Database Setup

**Wrong**:
```bash
# Running commands manually before test
mysql -urspade -prspadepass rspade_test -e "INSERT INTO users ..."
./run_test.sh
```

**Correct**:
```bash
# Test sets up its own state automatically
./run_test.sh
# Test calls db_reset.sh internally, sets up state, runs assertions
```

### Mistake 2: Using Production Database

**Wrong**:
```bash
# Test fails on rspade_test, so switch to rspade
sed -i 's/rspade_test/rspade/' .env
./run_test.sh
```

**Correct**:
```bash
# Fix the underlying issue so test passes on rspade_test
# Maybe db_reset.sh isn't running migrations?
# Maybe test service isn't in manifest?
# FIX THE PROBLEM, don't bypass it
```

### Mistake 3: Hiding Failures

**Wrong**:
```bash
# Test assertion fails, so comment it out
# if [ "$count" -ne 5 ]; then
#     echo "FAIL"
#     exit 1
# fi
```

**Correct**:
```bash
# Fix why count isn't 5
# Add debugging to understand what's happening
echo "[DEBUG] Count: $count" >&2
test_db_query "SELECT * FROM users" >&2
# Then fix the underlying issue
```

### Mistake 4: Non-Deterministic Tests

**Wrong**:
```bash
# Test depends on timestamp or random values
expected=$(date +%s)
actual=$(test_db_query "SELECT created_at FROM users WHERE id=1")
if [ "$actual" != "$expected" ]; then
    echo "FAIL"
fi
```

**Correct**:
```bash
# Test for existence, not exact timestamp
created_at=$(test_db_query "SELECT created_at FROM users WHERE id=1")
if [ -z "$created_at" ]; then
    echo "FAIL: $TEST_NAME - created_at is empty"
    exit 1
fi
echo "[TEST] ✓ created_at is set" >&2
```

## AI AGENT RESPONSIBILITIES

### When Writing Tests

1. **Follow the standard pattern** - Use existing tests as templates
2. **Make tests deterministic** - Same input = same output, always
3. **Make tests repeatable** - Run 3+ times to verify
4. **Fail loud with clear messages** - Future debugging will thank you
5. **Document what's being tested** - README.md for each test

### When Debugging Failed Tests

1. **Read the error message carefully** - It's telling you something
2. **Analyze the root cause** - Don't jump to solutions
3. **Fix the underlying issue** - Not the test, the actual problem
4. **If unclear, ask the user** - Better to ask than guess wrong
5. **Never work around failures** - Defeats the entire purpose

### When NOT to Create Tests

Tests should verify framework functionality, not individual features of user applications.

**Create tests for**:
- Core framework systems (manifest, bundles, routing, auth)
- Framework utilities (task system, file uploads, caching)
- Critical infrastructure (database connections, migrations)

**Do NOT create tests for**:
- User application features (unless explicitly requested)
- One-off debugging (use artisan commands instead)
- Features that aren't production-ready yet

### Red Flags - Stop and Ask User

If you encounter any of these, STOP and ask user for guidance:

1. **Test fails only on subsequent runs** - State isn't properly reset
2. **Test requires manual setup steps** - Test infrastructure is broken
3. **Test passes but output shows errors** - Something is being silently caught
4. **Can't reproduce failure** - Race condition or non-deterministic test
5. **Unclear what test should verify** - Specification ambiguity

## SUMMARY

**Remember these core principles**:

1. **Tests are fully automated** - One command, no manual steps
2. **Tests are deterministic** - Same input = same output, always
3. **Tests are repeatable** - Can run 100 times with identical results
4. **Tests require zero human assistance** - Complete autonomously
5. **Test failures are valuable** - They reveal real problems
6. **Never work around test failures** - Fix the root cause or ask user
7. **Manual intervention is forbidden** - Fix the infrastructure instead

**The "Press Button, Light Turns Green" Philosophy**: If the button doesn't turn the light green, fix the button. Don't paint the light green and declare success.

When in doubt, ask the user. It's better to raise a concern than silently compromise test integrity.
