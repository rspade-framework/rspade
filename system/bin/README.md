# RSX Binary Tools

This directory contains standalone command-line tools for the RSX framework.

## rsx-format

A standalone PHP formatter for RSX files that is completely independent of Laravel.

### Features

- **Laravel-independent**: Runs without requiring Laravel bootstrap
- **Namespace management**: Automatically updates namespaces based on file location
- **Use statement generation**: Detects and adds required use statements
- **PHP 8 Attribute support**: Properly detects and resolves attribute classes
- **Pint integration**: Applies Laravel Pint formatting after fixing structure
- **Smart detection**: Only formats files that need it

### Usage

```bash
# Format a single file
php bin/rsx-format path/to/file.php

# Force formatting even if file appears formatted
php bin/rsx-format path/to/file.php --force
```

### How It Works

1. **Detects file type**: RSX, app, or other directories
2. **For RSX class files**:
   - Updates namespace to match directory structure
   - Extracts all referenced classes (including PHP 8 attributes)
   - Generates and updates use statements
   - Adds LLM directive markers
   - Normalizes spacing
   - Applies Pint formatting
3. **For non-class files**: Just applies Pint formatting

### VS Code Integration

The formatter is automatically run on save for all PHP files via the VS Code settings:

```json
"emeraldwalk.runonsave": {
  "commands": [{
    "match": "\\.php$",
    "cmd": "php ${workspaceFolder}/bin/rsx-format ${file}"
  }]
}
```

### Why Standalone?

The formatter needs to be able to process PHP files that may not be valid PHP yet (missing use statements). If it depended on Laravel's autoloader, it would fail when trying to load classes with syntax errors, creating a chicken-and-egg problem.

By being standalone, it can:
- Parse files using simple regex patterns
- Add missing use statements to make files valid
- Then apply more sophisticated formatting

This ensures files can always be formatted, even when they're in an invalid state.