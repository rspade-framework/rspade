# Database Connection Test

Tests basic database CRUD operations using the test database.

## What it verifies

- Database insert operations work
- Database query operations work
- Database update operations work
- Database delete operations work
- Data retrieval returns correct values

## Prerequisites

- `rspade_test` database exists
- `rspade` user has access to `rspade_test`
- Test environment helpers functional

## How to run

```bash
./run_test.sh              # Full test with database reset
./run_test.sh --skip-reset # Skip database reset (faster)
```

## What happens

1. Creates temporary table
2. Inserts test data
3. Verifies data was inserted
4. Queries specific data
5. Updates data
6. Verifies update worked
7. Deletes data
8. Verifies deletion worked
9. Cleans up (temporary table auto-removed)

## Expected output

```
[SETUP] Preparing database connection test...
[SETUP] Resetting test database...
[DB RESET] Dropping rspade_test database...
[DB RESET] Creating rspade_test database...
[DB RESET] Running migrations...
[DB RESET] Database reset complete
[TEST ENV] Entering test mode...
[TEST ENV] Test mode active (using rspade_test database)
[TEST] Testing database connection and operations...
[TEST] ✓ Insert operations work
[TEST] ✓ Query operations work
[TEST] ✓ Data retrieval works
[TEST] ✓ Update operations work
[TEST] ✓ Delete operations work
PASS: Database Connection
[TEST ENV] Exiting test mode...
[TEST ENV] Test mode exited (restored original database)
```
