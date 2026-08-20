# RSpade Testing Framework

## Philosophy

These tests verify RSpade framework functionality using real integration tests:
- **Real database** (`rspade_test` - completely isolated from production)
- **Real browser** (Playwright with Chromium for UI tests)
- **Real HTTP requests** (curl, Playwright)
- **No mocks** - test actual behavior, not simulations

Tests are designed to be:
1. **Self-contained** - Each test is a standalone directory with `run_test.sh`
2. **Explicit** - Verbose over DRY, clear over clever
3. **LLM-friendly** - Easy for AI to read, write, and maintain
4. **Evolutionary** - Patterns emerge organically as tests are written

## Running Tests

**Run all automated tests:**
```bash
./run_all_tests.sh
```
Automatically creates fresh database snapshot before running tests.

**Run all tests with existing snapshot (faster):**
```bash
./run_all_tests.sh --use-existing-snapshot
```

**Run all tests with full migration output:**
```bash
./run_all_tests.sh --full-output
```

**Run specific test:**
```bash
./basic/01_framework_verification/run_test.sh
```

**Run specific test without database reset:**
```bash
./basic/01_framework_verification/run_test.sh --skip-reset
```
Use with caution - only for manual testing or when composing tests.

## Test Database

All tests use the `rspade_test` database:
- Completely separate from `rspade` production database
- Created fresh: `rspade_test` (utf8mb4_unicode_ci)
- Full access granted to `rspade` user
- Reset before each top-level test (ensures deterministic behavior)

**Database reset process:**
1. Drop `rspade_test` database
2. Create `rspade_test` database
3. Either:
   - **With snapshot**: Restore snapshot + run new migrations (~1 second)
   - **Without snapshot**: Run all migrations (~5-10 seconds)

**Create snapshot** (first time or after adding migrations):
```bash
./_lib/db_snapshot_create.sh              # Quiet mode
./_lib/db_snapshot_create.sh --full-output # Show all migration output
```

This creates `_lib/test_db_snapshot.sql` which speeds up all future test runs.

If snapshot creation fails, run with `--full-output` to see detailed error messages.

## Test Contract

Every test directory MUST have:

**`run_test.sh`** - Executable script that:
- Accepts `--skip-reset` flag to skip database reset (for test composition)
- Sets up test prerequisites
- Runs the actual test
- Cleans up after itself (trap EXIT)
- Exits with code 0 (pass) or 1 (fail)
- Outputs EXACTLY ONE LINE to stdout:
  - `PASS: Test description`
  - `FAIL: Test description - reason`
  - `SKIP: Test description - reason`

### Test Composition with --skip-reset

Tests can call other tests to set up complex state:

```bash
# Complex test that needs attachments and users
echo "[SETUP] Creating prerequisite state..." >&2
"$TEST_DIR/../01_create_user/run_test.sh" --skip-reset
"$TEST_DIR/../02_add_attachment/run_test.sh" --skip-reset

# Now test the feature that requires both users and attachments
echo "[TEST] Testing feature with complex state..." >&2
# ... your test logic ...
```

**Important**: Only the top-level test (called without --skip-reset) resets the database. Sub-tests called with --skip-reset build upon the existing state.

**`README.md`** - Documents:
- What this test verifies
- Prerequisites/assumptions
- How to run standalone
- Known limitations

## Standard Test Pattern

### Basic Test Template

```bash
#!/bin/bash
set -e

TEST_NAME="Example Test"
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
echo "[SETUP] Preparing test environment..." >&2

# Reset database unless --skip-reset
if [ "$SKIP_RESET" = false ]; then
    "$TEST_DIR/../../_lib/db_reset.sh"
fi

# Enter test mode (switches Laravel to rspade_test database)
test_mode_enter

# TEST LOGIC
echo "[TEST] Running test logic..." >&2

# Your test code here
count=$(test_db_count users)

if [ "$count" -eq 0 ]; then
    echo "PASS: $TEST_NAME"
    exit 0
else
    echo "FAIL: $TEST_NAME - Expected 0 users, found $count"
    exit 1
fi

# TEARDOWN happens automatically via trap
```

### Key Patterns

- `set -e` - Exit on first error
- `trap test_trap_exit EXIT` - Ensures `.env` always restored
- `--skip-reset` flag - Enables test composition (calling other tests to build state)
- `test_mode_enter` - Switches Laravel to test database
- Log to stderr `>&2`, only PASS/FAIL to stdout
- Single exit point with clear status

## Shared Helpers

Located in `_lib/`, source in your test:

### test_env.sh

```bash
source "$TEST_DIR/../../_lib/test_env.sh"

test_mode_enter           # Switch Laravel to rspade_test + run rsx:clean
test_mode_exit            # Restore Laravel to rspade + run rsx:clean
test_trap_exit            # Cleanup (call in trap EXIT)
test_db_query "SQL"       # Execute SQL on test database
test_db_count "table"     # Count rows in table
```

**Important**: `test_mode_enter` and `test_mode_exit` automatically run `rsx:clean` after switching the database in `.env` to ensure the manifest is rebuilt for the correct database environment.

### db_reset.sh

```bash
"$TEST_DIR/../../_lib/db_reset.sh"    # Drop, create, migrate rspade_test
```

**Note**: Always check `--skip-reset` flag before calling db_reset.sh

## Test Organization

```
tests/
├── _lib/               Shared utilities (grow organically)
├── basic/              Template and fundamental tests
├── core/               Framework core functionality tests
└── features/           Feature-specific tests
```

## Writing New Tests

1. **Create test directory:**
   ```bash
   mkdir -p basic/my_new_test
   ```

2. **Copy template:**
   ```bash
   cp basic/01_framework_verification/run_test.sh basic/my_new_test/
   ```

3. **Edit test logic:**
   - Update `TEST_NAME`
   - Modify test logic section
   - Update assertions

4. **Create README.md:**
   ```markdown
   # My New Test

   Tests that [feature] works correctly.

   ## What it verifies
   - Thing 1
   - Thing 2

   ## How to run
   ```bash
   ./basic/my_new_test/run_test.sh
   ```

5. **Make executable:**
   ```bash
   chmod +x basic/my_new_test/run_test.sh
   ```

6. **Run standalone:**
   ```bash
   ./basic/my_new_test/run_test.sh
   ```

## Interactive Tests

Some tests require human verification and use `run_interactive_test.sh`:
- Not run by default in `run_all_tests.sh`
- Set up environment, provide instructions, wait for user input
- Useful for visual testing, complex flows, accessibility

**Example:**
```bash
#!/bin/bash
set -e

# ... setup code ...

echo "Instructions:"
echo "1. Visit http://localhost/login"
echo "2. Verify form displays correctly"
echo "3. Did test pass? (yes/no/skip): "

read -r response
case "$response" in
    yes|y) echo "PASS: $TEST_NAME"; exit 0 ;;
    no|n) echo "FAIL: $TEST_NAME"; exit 1 ;;
    skip|s) echo "SKIP: $TEST_NAME"; exit 0 ;;
esac
```

Run interactive tests:
```bash
./run_interactive_tests.sh
```

## Best Practices

### Database

- Always use `--skip-reset` flag in test contract
- Default: reset database (safe but slow)
- Only skip reset when you know database state is correct
- Future: Snapshot/restore will make this fast

### Output

- Log progress to stderr: `echo "..." >&2`
- Only PASS/FAIL/SKIP to stdout
- Include useful context in failures: `FAIL: Test - Expected X got Y`

### Error Handling

- Use `set -e` to exit on first error
- Add cleanup trap: `trap test_trap_exit EXIT`
- Test edge cases (empty results, missing data)

### Test Independence

- Each test should work standalone
- Don't rely on other tests running first
- Reset database or seed required data
- Clean up temp files

## Common Patterns

### Pattern: Database Query Test

```bash
# Query test database
result=$(test_db_query "SELECT name FROM users WHERE id=1")

if [ "$result" = "Test User" ]; then
    echo "PASS: User name correct"
else
    echo "FAIL: Expected 'Test User', got '$result'"
    exit 1
fi
```

### Pattern: Count Test

```bash
count=$(test_db_count users)

if [ "$count" -eq 5 ]; then
    echo "PASS: Correct user count"
else
    echo "FAIL: Expected 5 users, found $count"
    exit 1
fi
```

### Pattern: API Test

```bash
response=$(curl -s http://localhost/_ajax/Test/method)

if echo "$response" | jq -e '.success == true' > /dev/null 2>&1; then
    echo "PASS: API returns success"
else
    echo "FAIL: API failed - $response"
    exit 1
fi
```

## Database Snapshot System

The testing framework uses database snapshots to speed up test runs.

**How it works:**
1. First time: Run `_lib/db_snapshot_create.sh` to create pristine snapshot
2. Tests restore from snapshot (~1 second) instead of running all migrations (~5-10 seconds)
3. After snapshot restore, any new migrations run automatically
4. When you add new migrations, recreate snapshot for maximum speed

**When to recreate snapshot:**
- After adding new migration files
- After modifying existing migration files
- If you notice tests taking longer than expected

**Snapshot file:**
- Location: `_lib/test_db_snapshot.sql`
- Not committed to git (add to .gitignore if needed)
- Each developer creates their own snapshot locally

## Troubleshooting

**Test hangs:**
- Check for missing cleanup trap
- Look for processes not cleaned up
- Add timeout to long operations

**Database errors:**
- Verify `rspade_test` database exists
- Check credentials work: `mysql -urspade -prspadepass rspade_test`
- Ensure migrations ran: `mysql -urspade -prspadepass rspade_test -e "SHOW TABLES"`

**Environment not restored:**
- Check trap is set: `trap test_trap_exit EXIT`
- Verify `.env.backup` doesn't exist after test
- Manually restore: `test_mode_exit`

**Test passes alone, fails in suite:**
- Database state contamination
- Missing `--skip-reset` flag handling
- Shared resource conflict

## Evolution

This framework is intentionally minimal. As you write tests:
- Extract common patterns into `_lib/` helpers
- Update templates with better patterns
- Document new best practices here
- Don't over-abstract - keep tests explicit

When in doubt, favor:
- **Explicit** over implicit
- **Verbose** over DRY
- **Working** over elegant

## Testing the Test Framework

The first test is a meta-test that verifies the framework itself works:

```bash
./basic/01_framework_verification/run_test.sh
```

This test verifies:
- Test environment helpers load correctly
- Test mode enter/exit works
- Database reset works
- Migrations run successfully
- Test database is accessible
