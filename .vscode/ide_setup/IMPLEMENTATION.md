# RSX Formatter Implementation Details

This document describes the cross-platform automatic setup system for the RSX code formatter.

## Problem Solved

When opening the project in VS Code on Windows, we encountered:
- "python3: command not found" errors
- No way to check dependencies without Python installed
- Platform-specific command differences (python vs python3)
- Manual setup required for each developer

## Solution Architecture

### 1. Native Shell Scripts for Bootstrap

We created platform-specific scripts that don't require Python:

- **Windows**: `check_setup.ps1` (PowerShell)
- **macOS/Linux**: `check_setup.sh` (Bash)

These scripts:
- Check for Python, PHP, and Node.js
- Provide platform-specific installation instructions
- Handle python vs python3 command differences
- Create configuration files for the formatter

### 2. VS Code Task Integration

The `tasks.json` file now uses platform-specific commands:

```json
{
  "windows": {
    "command": "powershell",
    "args": ["-ExecutionPolicy", "Bypass", "-File", "check_setup.ps1"]
  },
  "linux": {
    "command": "./check_setup.sh"
  },
  "osx": {
    "command": "./check_setup.sh"
  }
}
```

### 3. Automatic Setup Flow

When a developer opens the project:

1. VS Code prompts to install recommended extensions
2. The `RSX: Check Setup on Open` task runs automatically
3. Platform-appropriate script checks dependencies
4. If missing, shows installation instructions:
   - Windows: Chocolatey or manual install
   - macOS: Homebrew or manual install
   - Linux: Package manager commands
5. If Python is found, runs the full Python-based setup wizard

### 4. Python Command Detection

The scripts handle Python command variations:

```powershell
# PowerShell: Try python3, then python
$found, $version = Test-Command "python3"
if (-not $found) {
    $found, $version = Test-Command "python"
}
```

```bash
# Bash: Try python3, then python
if command -v python3 &> /dev/null; then
    python_cmd="python3"
elif command -v python &> /dev/null; then
    python_cmd="python"
fi
```

### 5. Configuration Management

The system creates local configuration files:

- `.setup_complete` - Marks first-time setup as done
- `.python_config.json` - Stores which Python command to use
- `.setup_status.json` - Current dependency status

### 6. Graceful Degradation

If dependencies are missing:
- Shows clear, actionable error messages
- Provides copy-paste installation commands
- Doesn't break VS Code functionality
- Allows manual formatter execution

## File Structure

```
.vscode/formatters/
├── check_setup.ps1      # Windows bootstrap script
├── check_setup.sh       # Unix bootstrap script
├── orchestrator.py      # Main formatter (Python)
├── php_formatter.php    # PHP-specific formatter
├── check_dependencies.py # Detailed dependency checker
├── setup.py            # Interactive setup wizard
├── format_file.cmd     # Windows wrapper (unused)
├── format_file.sh      # Unix wrapper (unused)
└── README.md           # User documentation
```

## Developer Experience

### First Time (No Dependencies)
1. Opens project → Terminal appears
2. Sees missing dependencies with install commands
3. Installs tools using provided commands
4. Reopens VS Code → Setup wizard runs
5. Formatting works automatically

### First Time (Dependencies Installed)
1. Opens project → Terminal appears
2. Dependencies found → Setup wizard runs
3. Guides through extension installation
4. Tests formatter
5. Shows quick reference

### Subsequent Opens
1. Quick dependency check (usually silent)
2. Only prompts if something is broken
3. Format-on-save just works

## Platform-Specific Considerations

### Windows
- PowerShell execution policy handled automatically
- Supports both `python` and `python3` commands
- Chocolatey recommended but not required
- Clear manual installation instructions

### macOS
- Assumes bash is available (it always is)
- Homebrew detection and instructions
- Usually has python3 by default

### Linux
- Distribution detection (Debian/Ubuntu, RedHat/CentOS)
- Package manager specific commands
- Handles various Python installations

## Future Enhancements

1. **Auto-install dependencies** (with user permission)
2. **VS Code extension auto-install** via API
3. **Network proxy detection** for corporate environments
4. **Docker/container support** detection
5. **WSL integration** for Windows users

## Maintenance Notes

- Shell scripts must remain simple and dependency-free
- Always test on fresh installations
- Keep installation instructions up-to-date
- Document any platform-specific quirks

This implementation provides a robust, user-friendly setup experience across all major platforms while handling the chicken-and-egg problem of checking for Python without Python.