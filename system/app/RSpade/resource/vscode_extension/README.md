# RSpade VS Code Extension

## Overview

The RSpade VS Code extension provides development tools and enhancements specifically for RSpade framework projects.

## Features

### RSX:USE Section Management
- Automatically highlights RSX:USE sections in gray
- Shows info icons to indicate auto-generated code
- Prevents accidental editing of managed sections

### Smart File Operations
- Automatically updates PHP namespaces when files are moved
- Maintains RSX class references during refactoring
- Updates use statements as needed

### PHP Formatting Integration
- Uses the main formatter at `./bin/rsx-format`
- Handles PHP and JSON file formatting
- Preserves file modification times to prevent VS Code conflicts
- Formats on save via RunOnSave extension (configured in settings.json)

### LLMDIRECTIVE Folding
- Automatically collapses LLMDIRECTIVE comment blocks
- Reduces visual clutter in code
- Preserves directives for AI assistants

## Installation

### Automatic Installation
The extension auto-installs when you open a terminal in VS Code if:
- The project has `"rspade.projectType": "rspade"` in `.vscode/settings.json`
- Auto-install is enabled in settings

### Manual Installation
```bash
# From the extension directory
./build.sh
code --install-extension rspade-framework.vsix
```

## Building the Extension

```bash
cd /app/RSpade/Extension
./build.sh
```

The build script:
- Auto-increments version number
- Compiles TypeScript to JavaScript
- Packages as `rspade-framework.vsix`
- Runs inside Docker for consistency

## Configuration

Add to `.vscode/settings.json`:
```json
{
    "rspade.projectType": "rspade",
    "rspade.autoCheckExtension": true,
    "rspade.autoInstallExtension": true
}
```

## Setup Scripts

Located in `.vscode/ide_setup/`:

### Unix/Linux/macOS: `check_setup.sh`
- Checks for Python, PHP, Node.js dependencies
- Verifies npm packages
- Checks extension status
- Auto-installs updates if configured

### Windows: `check_setup.ps1`
- PowerShell equivalent of Unix script
- Uses Chocolatey for package management
- Same functionality as Unix version

## Development

### Source Structure
```
src/
├── extension.ts         - Main extension entry
├── folding_provider.ts  - LLMDIRECTIVE folding
├── decoration_provider.ts - RSX:USE highlighting
├── file_watcher.ts      - File operation handling
└── formatting_provider.ts - PHP formatting integration
```

### Testing
The extension is tested with:
- Manual testing in VS Code
- Integration with formatter tests
- File operation scenarios

## Troubleshooting

### Extension Not Loading
- Check `"rspade.projectType": "rspade"` in settings
- Verify extension is installed: `code --list-extensions | grep rspade`
- Check VS Code developer console for errors

### Formatting Issues
- The main formatter is now at: `./bin/rsx-format`
- For PHP files: `./bin/formatters/php-formatter`
- For JSON files: `./bin/formatters/json-formatter`
- Check IDE setup: `.vscode/ide_setup/check_setup.sh`
- Verify PHP is in PATH