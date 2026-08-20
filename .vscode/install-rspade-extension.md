# RSpade VS Code Extension Installation

The RSpade framework includes a custom VS Code extension that enhances your development experience.

## Features
- Automatic folding of LLMDIRECTIVE blocks
- Visual indicators for auto-generated RSX:USE sections  
- Automatic namespace updates when moving PHP files
- Integrated PHP formatting (replaces RunOnSave)

## Installation Instructions

### Option 1: Command Line (Recommended)
```bash
code --install-extension ./app/RSpade/Extension/rspade-framework-0.1.0.vsix
```

### Option 2: VS Code UI
1. Open VS Code
2. Press `Ctrl+Shift+X` (or `Cmd+Shift+X` on macOS)
3. Click the `...` menu → `Install from VSIX...`
4. Navigate to: `app/RSpade/Extension/rspade-framework-0.1.0.vsix`
5. Click Install

### Option 3: Use VS Code Task
1. Press `Ctrl+Shift+P` (or `Cmd+Shift+P` on macOS)
2. Type "Tasks: Run Task"
3. Select "RSpade: Install Extension"

## After Installation
- Reload VS Code window (`Ctrl+R` or `Cmd+R`)
- The extension will automatically activate for PHP files
- Check settings for `rspade.*` configuration options

## Rebuilding the Extension
If you need to rebuild the extension:
```bash
docker exec -it <container-name> /var/www/html/app/RSpade/Extension/build.sh
```