Write-Host "RSpade Extension Installer"
Write-Host ""

$projectRoot = Split-Path (Split-Path $PSScriptRoot)
$needsReload = $false

# Detect if project is on UNC path (\\server\share)
$isUncPath = $projectRoot -match "^\\\\[^\\]+\\"

# Function to get installation path for VSIX (handles UNC paths)
function Get-VsixInstallPath {
    param([string]$vsixPath)

    if ($isUncPath) {
        # Copy to local temp directory to avoid UNC path issues
        $tempDir = Join-Path $env:TEMP "rspade-extension-install"
        if (-not (Test-Path $tempDir)) {
            New-Item -Path $tempDir -ItemType Directory -Force | Out-Null
        }

        $fileName = Split-Path $vsixPath -Leaf
        $tempPath = Join-Path $tempDir $fileName

        Write-Host "UNC path detected - copying to temp: $tempPath"
        Copy-Item $vsixPath $tempPath -Force

        return $tempPath
    } else {
        return $vsixPath
    }
}

# Function to clean up temp VSIX files
function Clean-TempVsix {
    if ($isUncPath) {
        $tempDir = Join-Path $env:TEMP "rspade-extension-install"
        if (Test-Path $tempDir) {
            Write-Host "Cleaning up temporary files..."
            Remove-Item $tempDir -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

# Check for workspace template and copy if needed
$workspaceDist = Join-Path $projectRoot "rspade.code-workspace.dist"
$workspaceFile = Join-Path $projectRoot "rspade.code-workspace"

if ((Test-Path $workspaceDist) -and -not (Test-Path $workspaceFile)) {
    Write-Host "Creating workspace file from template..."
    Copy-Item $workspaceDist $workspaceFile
}

# RSPADE EXTENSION
# Find expected version
$rspadePath = Join-Path (Split-Path (Split-Path $PSScriptRoot)) "system\app\RSpade\resource\vscode_extension"
# Globbed, not hardcoded: build.sh emits rspade-vscode-extension-<version>.vsix,
# so the filename changes on every build. Newest first if several linger.
$rspadeVsix = Get-ChildItem -Path $rspadePath -Filter "rspade-vscode-extension-*.vsix" | Sort-Object LastWriteTime -Descending | Select-Object -First 1
$packageJson = Get-Content (Join-Path $rspadePath "package.json") -Raw | ConvertFrom-Json
$expectedVersion = $packageJson.version

Write-Host "RSpade expected version: $expectedVersion"

# Check installed version
$installedExtensions = & code --list-extensions --show-versions
$installedLine = $installedExtensions | Where-Object { $_ -match "^rspade\.rspade-framework@(.+)$" }
$installedVersion = if ($installedLine) { $matches[1] } else { $null }

Write-Host "RSpade installed version: $installedVersion"

$needsInstall = $false

if ($installedVersion) {
    if ($installedVersion -ne $expectedVersion) {
        Write-Host "Outdated - uninstalling"
        & code --uninstall-extension rspade.rspade-framework
        $needsInstall = $true
    } else {
        Write-Host "Already up to date"
    }
} else {
    Write-Host "Not installed"
    $needsInstall = $true
}

if ($needsInstall) {
    $installPath = Get-VsixInstallPath -vsixPath $rspadeVsix.FullName
    Write-Host "Installing $installPath"
    & code --install-extension $installPath --force
    $needsReload = $true
}

# JQHTML EXTENSION
# Find expected version
$jqhtmlPath = Join-Path (Split-Path (Split-Path $PSScriptRoot)) "system\node_modules\@jqhtml\vscode-extension"
$jqhtmlVsix = Get-ChildItem -Path $jqhtmlPath -Filter "jqhtml-vscode-extension-*.vsix" | Select-Object -First 1
$jqhtmlVsix.Name -match "jqhtml-vscode-extension-(.+)\.vsix$"
$expectedVersion = $matches[1]

Write-Host "JQHTML expected version: $expectedVersion"

# Check installed version
$installedExtensions = & code --list-extensions --show-versions
$installedLine = $installedExtensions | Where-Object { $_ -match "^jqhtml\.jqhtml-vscode-extension@(.+)$" }
$installedVersion = if ($installedLine) { $matches[1] } else { $null }

Write-Host "JQHTML installed version: $installedVersion"

$needsInstall = $false

if ($installedVersion) {
    if ($installedVersion -ne $expectedVersion) {
        Write-Host "Outdated - uninstalling"
        & code --uninstall-extension jqhtml.jqhtml-vscode-extension
        $needsInstall = $true
    } else {
        Write-Host "Already up to date"
    }
} else {
    Write-Host "Not installed"
    $needsInstall = $true
}

if ($needsInstall) {
    $installPath = Get-VsixInstallPath -vsixPath $jqhtmlVsix.FullName
    Write-Host "Installing $installPath"
    & code --install-extension $installPath --force
    $needsReload = $true
}

if ($needsReload) {
    Write-Host ""
    Write-Host "Extensions have been installed."
    Write-Host "If the window does not reload automatically, press Ctrl+Shift+P and select 'Reload Window'"

    # Clean up temporary VSIX files
    Clean-TempVsix

    $markerFile = Join-Path (Split-Path $PSScriptRoot) ".rspade-extension-updated"
    New-Item -Path $markerFile -ItemType File -Force | Out-Null
} else {
    # Check if workspace file exists and we're in folder mode
    if (Test-Path $workspaceFile) {
        # Check if we're already in workspace mode
        $currentWorkspace = $env:VSCODE_WORKSPACE_FILE

        # Normalize paths for comparison (resolve to absolute, handle WSL/Windows differences)
        $workspaceFileResolved = (Resolve-Path $workspaceFile).Path
        $currentWorkspaceResolved = if ($currentWorkspace -and (Test-Path $currentWorkspace)) { (Resolve-Path $currentWorkspace -ErrorAction SilentlyContinue).Path } else { $null }

        # Only switch if not already in this workspace
        if (-not $currentWorkspaceResolved -or $currentWorkspaceResolved -ne $workspaceFileResolved) {
            # Close this window and open workspace
            & code --reuse-window "$workspaceFile"
        }
    }

    Write-Host ""
    Write-Host "RSpade extensions valid"

    # Create marker to close terminal panel
    $closeTerminalMarker = Join-Path (Split-Path $PSScriptRoot) ".rspade-close-terminal"
    New-Item -Path $closeTerminalMarker -ItemType File -Force | Out-Null

    Start-Sleep -Seconds 1
}
