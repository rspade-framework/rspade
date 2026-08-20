# Framework Verification Test

Meta-test that verifies the testing framework itself works correctly.

## What it verifies

- Test environment helpers load correctly
- `test_mode_enter()` successfully switches to test database
- `.env` is modified to use `rspade_test`
- `.env` backup is created
- Database reset script works
- Migrations run successfully on test database
- `test_db_query()` helper works
- Test mode cleanup/restore works via trap

## Prerequisites

- `rspade_test` database exists
- `rspade` user has access to `rspade_test`
- Laravel is configured normally (using `rspade` database)

## How to run

```bash
./run_test.sh              # Full test with database reset
./run_test.sh --skip-reset # Skip database reset (faster)
```

## What happens

1. Loads test environment helpers
2. Resets test database (unless --skip-reset)
3. Enters test mode (switches to rspade_test)
4. Runs 5 verification checks
5. Exits test mode (restores original database)
6. Outputs PASS or FAIL

## Expected output

```
[SETUP] Testing framework verification...
[SETUP] Resetting test database...
[DB RESET] Dropping rspade_test database...
[DB RESET] Creating rspade_test database...
[DB RESET] Running migrations...
[DB RESET] Database reset complete
[TEST ENV] Entering test mode...
[TEST ENV] Test mode active (using rspade_test database)
[TEST] Verifying framework components...
[TEST] ✓ Environment switched to test database
[TEST] ✓ Test database accessible
[TEST] ✓ Migrations ran successfully
[TEST] ✓ Environment backup created
[TEST] ✓ Database query helper works
PASS: Framework Verification
[TEST ENV] Exiting test mode...
[TEST ENV] Test mode exited (restored original database)
```

## Known limitations

- Database reset is slow (~5-10 seconds with migrations)
- Future: Use database snapshots for faster reset
