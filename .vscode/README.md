# VS Code Configuration for RSpade Framework

This directory contains VS Code-specific configuration and tooling for the RSpade framework project.

## Directory Structure

```
.vscode/
├── formatters/          # PHP formatting tools and setup scripts
│   ├── orchestrator.py  # Main formatting orchestrator
│   ├── php_formatter.py # PHP code formatter
│   ├── check_setup.sh   # Unix/Linux setup checker
│   └── check_setup.ps1  # Windows PowerShell setup checker
├── extensions.json      # Recommended VS Code extensions
├── launch.json         # Debug configurations
├── settings.json       # Workspace settings
├── tasks.json          # Build and run tasks
└── README.md           # This file
```

## RSpade VS Code Extension

The RSpade framework includes a custom VS Code extension that provides:

- **Automatic LLMDIRECTIVE Folding**: Auto-collapses LLMDIRECTIVE comment blocks to reduce visual clutter
- **RSX:USE Section Indicators**: Subtle visual indicators for auto-generated code sections
- **Smart File Renaming**: Automatically updates PHP namespaces when files are moved
- **Integrated PHP Formatting**: Replaces the need for RunOnSave extension

### Extension Auto-Update

The extension supports automatic updates through the setup scripts. When a new version is detected:

1. The setup script installs the new version automatically
2. Creates a marker file that the extension watches for
3. VS Code automatically reloads when the marker is detected
4. No user interaction required

### Configuration Flags

Control the extension behavior through `settings.json`:

```json
{
  "rspade.autoCheckExtension": true,    // Check for extension updates in setup scripts
  "rspade.autoInstallExtension": true,  // Auto-install extension updates
  "rspade.enableCodeFolding": true,     // Auto-fold LLMDIRECTIVE blocks
  "rspade.enableReadOnlyRegions": true, // Show RSX:USE visual indicators
  "rspade.enableFormatOnMove": true     // Update namespaces on file move
}
```

## Setup Scripts

The `check_setup.sh` (Unix/Linux/macOS) and `check_setup.ps1` (Windows) scripts:

- Check for required dependencies (Python, PHP, Node.js)
- Verify npm packages are installed
- Check RSpade extension status (if `rspade.autoCheckExtension` is true)
- Auto-install/update the extension (if `rspade.autoInstallExtension` is true)
- Guide users through fixing any missing dependencies

Run the appropriate script when first setting up the project or after pulling updates.

## PHP Formatting

The PHP formatting system consists of:

1. **orchestrator.py**: Main entry point that coordinates formatting
2. **php_formatter.py**: Handles PHP-specific formatting and namespace updates
3. Integration with VS Code through the RSpade extension

The formatter:
- Maintains RSX framework conventions
- Preserves LLMDIRECTIVE and RSX:USE blocks
- Updates namespaces based on file paths
- Respects the project's coding standards

## Workspace Settings

Key settings in `settings.json`:

- **File Associations**: Maps special file types (`.blade.php`, `.phtml`, etc.)
- **File Exclusions**: Hides build artifacts and dependencies from the explorer
- **Language-Specific Settings**: Tab sizes, formatters, and save behavior
- **RSpade Extension Settings**: Controls extension features
- **Editor Settings**: Word wrap, rulers, and general preferences

## Recommended Extensions

See `extensions.json` for the list of recommended extensions, including:
- PHP Intelephense for PHP intelligence
- Prettier for JavaScript/JSON formatting
- Laravel Blade formatter
- GitLens for enhanced Git features

## Debug Configurations

The `launch.json` file contains Xdebug configurations for PHP debugging:
- Listen for Xdebug connections
- Launch currently open script
- Proper path mappings for Docker environments

## Tasks

Common tasks defined in `tasks.json`:
- Build tasks for compiling assets
- Test runners
- Laravel-specific commands

## Troubleshooting

### Extension Not Auto-Reloading
- Check that `rspade.autoCheckExtension` and `rspade.autoInstallExtension` are `true`
- Ensure VS Code has permission to create files in `.vscode/`
- Manually reload: Press `Ctrl+Shift+P` and select "Developer: Reload Window"

### Formatting Not Working
- Run the setup script to check dependencies
- Ensure the RSpade extension is installed and enabled
- Check that `rspade.projectType` is set to `"rspade"`

### Setup Script Issues
- On Windows, ensure PowerShell execution policy allows scripts
- On Unix systems, make sure the script has execute permissions: `chmod +x check_setup.sh`
- Check that Node.js is available for JSON parsing