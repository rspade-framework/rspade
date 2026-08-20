#!/bin/bash

# RSpade Test Runner
# Runs all automated tests and reports results

set -e

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ANSI color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Track results
PASSED=0
FAILED=0
SKIPPED=0
TOTAL=0

echo -e "${BLUE}[RSpade Test Runner]${NC}"

# Parse arguments
USE_EXISTING_SNAPSHOT=false
FULL_OUTPUT=false
for arg in "$@"; do
    case $arg in
        --use-existing-snapshot)
            USE_EXISTING_SNAPSHOT=true
            shift
            ;;
        --full-output)
            FULL_OUTPUT=true
            shift
            ;;
    esac
done

# If no arguments provided (or snapshot flag not set), recreate snapshot for fresh test run
if [ "$USE_EXISTING_SNAPSHOT" = false ]; then
    echo -e "${BLUE}Creating fresh database snapshot...${NC}"
    if [ "$FULL_OUTPUT" = true ]; then
        "$TESTS_DIR/_lib/db_snapshot_create.sh" --full-output
    else
        "$TESTS_DIR/_lib/db_snapshot_create.sh"
    fi
    echo ""
else
    echo -e "${BLUE}Using existing snapshot (if available)${NC}"
    echo ""
fi

# Find all run_test.sh files (excluding run_interactive_test.sh)
echo -e "${BLUE}Finding tests...${NC}"
echo ""

# Find all test scripts
test_scripts=$(find "$TESTS_DIR" -name "run_test.sh" -type f | sort)

if [ -z "$test_scripts" ]; then
    echo -e "${RED}No tests found${NC}"
    exit 1
fi

# Count tests
while IFS= read -r script; do
    TOTAL=$((TOTAL + 1))
done <<< "$test_scripts"

echo -e "${BLUE}Found $TOTAL tests${NC}"
echo ""

# Run each test
while IFS= read -r script; do
    # Get relative path for display
    rel_path="${script#$TESTS_DIR/}"

    echo -e "${BLUE}Running: $rel_path${NC}"

    # Run test and capture stdout/stderr separately
    # Stderr goes to terminal, stdout captured for result parsing
    if output=$("$script" 2>&1 | tee /dev/stderr); then
        exit_code=0
    else
        exit_code=$?
    fi

    # Parse result from output (should be last non-empty line to stdout)
    result=$(echo "$output" | grep -E "^(PASS|FAIL|SKIP):" | tail -n 1)

    if [ -z "$result" ]; then
        result="FAIL: $rel_path - No test result output"
    fi

    if [ $exit_code -eq 0 ] && echo "$result" | grep -q "^PASS:"; then
        echo -e "${GREEN}✓ $result${NC}"
        PASSED=$((PASSED + 1))
    elif echo "$result" | grep -q "^SKIP:"; then
        echo -e "${YELLOW}○ $result${NC}"
        SKIPPED=$((SKIPPED + 1))
    else
        echo -e "${RED}✗ $result${NC}"
        FAILED=$((FAILED + 1))
    fi

    echo ""

done <<< "$test_scripts"

# Summary
echo -e "${BLUE}======================================${NC}"
echo -e "${BLUE}Test Results${NC}"
echo -e "${BLUE}======================================${NC}"
echo -e "${GREEN}Passed:  $PASSED${NC}"
echo -e "${RED}Failed:  $FAILED${NC}"
echo -e "${YELLOW}Skipped: $SKIPPED${NC}"
echo -e "${BLUE}Total:   $TOTAL${NC}"
echo ""

if [ $FAILED -gt 0 ]; then
    echo -e "${RED}Some tests failed${NC}"
    exit 1
else
    echo -e "${GREEN}All tests passed${NC}"
    exit 0
fi
