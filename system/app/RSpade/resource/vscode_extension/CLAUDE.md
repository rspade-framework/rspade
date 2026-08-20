# RSpade VS Code Extension

This directory contains the Visual Studio Code extension for the RSpade framework, providing enhanced development experience with automatic code folding, namespace management, and integrated formatting.

## Purpose

The RSpade extension enhances VS Code for RSpade framework development by:
- Updating PHP namespaces automatically when files are moved or renamed
- Integrating PHP formatting directly into VS Code (replacing RunOnSave)
- Auto-renaming files to match RSX naming conventions (optional, configurable)

## Architecture

The extension is built with TypeScript and follows VS Code extension best practices:

### Core Components

1. **extension.ts** - Main entry point that activates providers and registers commands
2. **file_watcher.ts** - Monitors file moves/renames and triggers namespace updates
3. **formatting_provider.ts** - Integrates with the main formatter for PHP formatting
4. **auto_rename_provider.ts** - Automatically renames files to match RSX naming conventions
5. **config.ts** - Centralized configuration management

### Key Features

1. **Smart File Handling**
   - Detects file moves and renames
   - Automatically updates PHP namespaces for RSX files
   - Works with drag-and-drop in VS Code explorer

2. **Integrated Formatting**
   - PHP formatting via the IDE bridge endpoint `/_ide/service/format` (POST)
   - Authenticated by a local-file grant (`X-Ide-Token` header, no Docker)
   - Runs the main formatter at `./bin/rsx-format`
   - Graceful degradation - warns but allows save if formatting fails
   - Served by standalone pre-boot handlers - works even when Laravel is broken

3. **Auto-Rename Files** (Optional - disabled by default)
   - Automatically renames files in `./rsx` to match RSX naming conventions on save
   - Supports PHP classes, JavaScript classes, .blade.php files with @rsx_id, and .jqhtml components
   - Only renames if the correct filename doesn't already exist
   - Respects short-name conventions (directory-based prefixes)
   - Files containing `@FILENAME-CONVENTION-EXCEPTION` are skipped
   - Enable via `config/rsx.php`: `'development' => ['auto_rename_files' => true]`

   **Naming Conventions Applied:**
   - **PHP classes in rsx/**: Lowercase, optional short name
   - **JS classes in rsx/**: Lowercase, snake_case for Component subclasses
   - **.blade.php with @rsx_id**: Lowercase, optional short name
   - **.jqhtml components**: snake_case (lowercase with underscores)

   **Short Names:** If class is `Foo_Bar_Baz_Bom` in directory `./rsx/app/foo/bar/`, filename can be `baz_bom.php` instead of `foo_bar_baz_bom.php`

## Development

### Setup
```bash
npm install
npm run compile
```

### Testing
Press F5 in VS Code to launch a development instance with the extension loaded.

### Building
```bash
npm install -g vsce
vsce package
```

## Configuration

Users can configure the extension through VS Code settings:
- `rspade.enableFormatOnMove` - Toggle automatic namespace updates

## Integration

The extension integrates with the existing RSpade formatting infrastructure:
- Format-on-save handled by RunOnSave extension configured in `.vscode/settings.json`
- Uses the main formatter at `./bin/rsx-format`
- PHP formatting via `./bin/formatters/php-formatter`
- JSON formatting via `./bin/formatters/json-formatter`
- Maintains compatibility with existing VS Code tasks and settings