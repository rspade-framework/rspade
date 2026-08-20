# RSpade VS Code Extension Setup Guide

## Overview

The RSpade VS Code extension can be set up in multiple ways depending on your needs:

1. **Development Mode** - For active development of the extension
2. **Production Mode** - For regular use with a packaged extension
3. **Automatic Setup** - Using provided scripts

## Setup Methods

### Method 1: Automatic Setup (Recommended)

The easiest way is to use the VS Code task:

1. Open Command Palette (Ctrl+Shift+P / Cmd+Shift+P)
2. Run "Tasks: Run Task"
3. Select "RSpade: Setup VS Code Extension"
4. Choose between development or production mode

### Method 2: Manual Development Mode

For extension development without packaging:

```bash
# Unix/Linux/macOS
cd ~/.vscode/extensions
ln -s /var/www/html/app/RSpade/Extension rspade.rspade-framework-dev

# Windows (requires admin or use junction)
cd %APPDATA%\Code\User\extensions
mklink /D rspade.rspade-framework-dev "C:\path\to\project\app\RSpade\Extension"
```

Then compile the TypeScript:
```bash
cd /var/www/html/app/RSpade/Extension
npm install
npm run compile
```

### Method 3: Packaged Installation

For production use:

```bash
cd /var/www/html/app/RSpade/Extension
./install.sh  # or install.ps1 on Windows
```

## VS Code Integration

### Automatic Recommendation

When you open the project, VS Code will automatically recommend the extension because it's listed in `.vscode/extensions.json`.

### Extension Features

Once installed, the extension provides:

1. **Automatic LLMDIRECTIVE Folding**
   - Folds coding convention blocks by default
   - Toggle with "RSpade: Toggle LLMDIRECTIVE Folding"

2. **RSX:USE Visual Indicators**
   - Yellow highlighting for auto-generated sections
   - Warning when editing these sections

3. **Format on Move**
   - Automatically updates namespaces when moving PHP files
   - Works with drag-and-drop in VS Code explorer

4. **Native PHP Formatting**
   - Replaces RunOnSave extension
   - Format on save or manually with "Format Document"

## Development Workflow

If you're developing the extension:

1. Make changes to TypeScript files in `src/`
2. Run `npm run compile` to rebuild
3. Reload VS Code window (Ctrl+R / Cmd+R)
4. Test your changes

## Troubleshooting

### Extension Not Loading

1. Check if it appears in Extensions view
2. Look for errors in Output > Extension Host
3. Ensure TypeScript is compiled: `npm run compile`

### Formatting Not Working

1. Ensure PHP is installed and accessible
2. Check `./bin/rsx-format` and `./bin/formatters/` are present
3. Verify RunOnSave extension is configured in `.vscode/settings.json`

### Development Symlink Issues

- On Windows, you may need admin rights for symlinks
- Use junction (`mklink /J`) as alternative
- Ensure no spaces in symlink name

## Configuration

After installation, configure via VS Code settings:

```json
{
  "rspade.enableCodeFolding": true,
  "rspade.enableReadOnlyRegions": true,
  "rspade.enableFormatOnMove": true,
  "rspade.pythonPath": "python3"  // or "python" on Windows
}
```