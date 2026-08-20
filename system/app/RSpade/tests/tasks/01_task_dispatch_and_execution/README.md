# Task Dispatch and Execution Test

Tests the core task system functionality including task dispatch, execution, and scheduling.

## What it verifies

- Test services are discoverable in the manifest when in test mode
- Tasks can be dispatched to the queue
- Tasks start with 'pending' status
- Task processor can execute tasks
- Tasks complete with 'completed' status
- Task results are stored correctly
- Task logs are recorded
- Scheduled tasks are registered from manifest
- `--force-scheduled` flag dispatches scheduled tasks immediately

## Prerequisites

- Test mode must be active (test services in manifest)
- Database must have `_tasks` table (from migration)
- Test services must exist in `/system/app/RSpade/tests/services/`

## How to run

```bash
cd /system/app/RSpade/tests
./tasks/01_task_dispatch_and_execution/run_test.sh
```

## What it tests

1. **Manifest Integration**: Verifies test services with #[Task_Attribute] and #[Schedule] attributes are discovered
2. **Task Dispatch**: Creates a task and verifies it's inserted into the database
3. **Task Status**: Checks task starts as 'pending'
4. **Task Execution**: Runs the task processor to execute the pending task
5. **Task Completion**: Verifies task status changes to 'completed'
6. **Task Results**: Checks task return value is stored as JSON
7. **Task Logging**: Verifies Task_Instance logging is captured
8. **Scheduled Registration**: Tests Task_Process_Command registers scheduled tasks
9. **Force Dispatch**: Tests --force-scheduled flag creates task instances

## Expected output

```
[SETUP] Preparing task system test...
[SETUP] Resetting test database...
[TEST] Testing task dispatch and execution...
[TEST] Verifying test services in manifest...
[TEST] ✓ Test services found in manifest
[TEST] Dispatching task...
[TEST] ✓ Task dispatched with ID: 1
[TEST] ✓ Task status is pending
[TEST] Processing task...
[TEST] ✓ Task completed successfully
[TEST] ✓ Task result correct
[TEST] ✓ Task logs recorded
[TEST] Testing scheduled task registration...
[TEST] ✓ Scheduled tasks registered: 3
[TEST] Testing --force-scheduled flag...
[TEST] ✓ Force-scheduled created task instances
PASS: Task Dispatch and Execution
```
