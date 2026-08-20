# Test script to debug prettier detection

Write-Host "=== Prettier Detection Test ===" -ForegroundColor Cyan
Write-Host ""

# Test 1: Direct command
Write-Host "Test 1: Running 'prettier --version'" -ForegroundColor Yellow
try {
    $prettierVersion = prettier --version 2>&1
    Write-Host "Result: $prettierVersion" -ForegroundColor Green
    Write-Host "Exit Code: $LASTEXITCODE" -ForegroundColor Gray
} catch {
    Write-Host "Error: $_" -ForegroundColor Red
}

Write-Host ""

# Test 2: Get-Command
Write-Host "Test 2: Get-Command prettier" -ForegroundColor Yellow
try {
    $prettierCmd = Get-Command prettier -ErrorAction Stop
    Write-Host "Found at: $($prettierCmd.Source)" -ForegroundColor Green
    Write-Host "Command Type: $($prettierCmd.CommandType)" -ForegroundColor Gray
} catch {
    Write-Host "Not found via Get-Command" -ForegroundColor Red
}

Write-Host ""

# Test 3: npm list global
Write-Host "Test 3: npm list -g --depth=0" -ForegroundColor Yellow
$npmList = npm list -g --depth=0 2>&1
Write-Host $npmList -ForegroundColor Gray
Write-Host ""

# Test 4: npm list prettier specifically
Write-Host "Test 4: npm list -g prettier" -ForegroundColor Yellow
$npmPrettier = npm list -g prettier 2>&1
Write-Host $npmPrettier -ForegroundColor Gray
Write-Host "Exit Code: $LASTEXITCODE" -ForegroundColor Gray

Write-Host ""

# Test 5: Check npm prefix
Write-Host "Test 5: npm config get prefix" -ForegroundColor Yellow
$npmPrefix = npm config get prefix
Write-Host "NPM Prefix: $npmPrefix" -ForegroundColor Gray

Write-Host ""

# Test 6: Check if prettier exists in npm bin
Write-Host "Test 6: Checking npm bin directory" -ForegroundColor Yellow
$npmBin = Join-Path $npmPrefix "prettier.cmd"
if (Test-Path $npmBin) {
    Write-Host "Found prettier.cmd at: $npmBin" -ForegroundColor Green
} else {
    $npmBin = Join-Path $npmPrefix "bin" "prettier"
    if (Test-Path $npmBin) {
        Write-Host "Found prettier at: $npmBin" -ForegroundColor Green
    } else {
        Write-Host "Prettier not found in npm bin directory" -ForegroundColor Red
    }
}

Write-Host ""

# Test 7: Check PATH
Write-Host "Test 7: Checking PATH" -ForegroundColor Yellow
$pathDirs = $env:PATH -split ';'
$npmInPath = $false
foreach ($dir in $pathDirs) {
    if ($dir -like "*npm*" -or $dir -like "*node*") {
        Write-Host "NPM/Node in PATH: $dir" -ForegroundColor Gray
        $npmInPath = $true
    }
}
if (-not $npmInPath) {
    Write-Host "No npm/node directories found in PATH" -ForegroundColor Red
}

Write-Host ""
Write-Host "=== End of Test ===" -ForegroundColor Cyan