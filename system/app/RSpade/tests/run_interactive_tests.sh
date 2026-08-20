#!/bin/bash

# RSpade Interactive Test Runner
# Runs tests that require human verification

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

# Find all run_interactive_test.sh files
echo -e "${BLUE}[RSpade Interactive Test Runner]${NC}"
echo -e "${BLUE}Finding interactive tests...${NC}"
echo ""

# Find all interactive test scripts
test_scripts=$(find "$TESTS_DIR" -name "run_interactive_test.sh" -type f | sort)

if [ -z "$test_scripts" ]; then
    echo -e "${YELLOW}No interactive tests found${NC}"
    exit 0
fi

# Count tests
while IFS= read -r script; do
    TOTAL=$((TOTAL + 1))
done <<< "$test_scripts"

echo -e "${BLUE}Found $TOTAL interactive tests${NC}"
echo ""

# Run each test
while IFS= read -r script; do
    # Get relative path for display
    rel_path="${script#$TESTS_DIR/}"

    echo -e "${BLUE}Running: $rel_path${NC}"
    echo ""

    # Run test and capture output
    if output=$("$script" 2>&1); then
        exit_code=0
    else
        exit_code=$?
    fi

    # Parse result from last line
    result=$(echo "$output" | tail -n 1)

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
echo -e "${BLUE}Interactive Test Results${NC}"
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
