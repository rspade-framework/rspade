# RSX Code Formatters

This directory contains OS-agnostic code formatting tools for the RSX framework. The formatters work on Windows, macOS, and Linux, providing consistent code formatting across all development environments.

## Overview

The formatting system consists of:

1. **orchestrator.py** - Main entry point that determines file type and delegates to appropriate formatter
2. **php_formatter.php** - PHP-specific formatter that handles RSX framework conventions
3. Additional formatters can be added for other languages

## Auto-Provisioning

When you open this project in VS Code for the first time, an automatic dependency checker will run to ensure all required tools are installed on your system. This checker:

1. **Automatically runs on project open** via VS Code tasks
2. **Detects missing dependencies** (Python, PHP, Node.js, npm packages)
3. **Offers to install missing packages** with a simple Y/N prompt
4. **Rechecks after installation** to verify everything is working
5. **Works on all platforms** (Windows PowerShell, macOS/Linux Bash)

### How it works:
- If dependencies are missing, you'll see a prompt asking if you want to install them
- Type 'Y' and press Enter to automatically install
- The script will rerun to verify the installation succeeded
- Once all dependencies are installed, you can close the terminal

### What gets checked:
- **Python 3** (required for the orchestrator)
- **PHP** (required for PHP formatting)  
- **Node.js** (required for JavaScript/CSS formatting)
- **npm** (comes with Node.js)
- **npm dependencies** (prettier and @prettier/plugin-php are installed locally via `npm install`)

The setup checker will guide you through installing any missing components, including package managers like Chocolatey (Windows) or Homebrew (macOS) if needed.

## Features

### PHP Formatting
- **For `/app/` directory**: Applies Laravel Pint formatting only
- **For `/rsx/` directory with classes**: 
  - Updates namespace based on file path
  - Auto-generates `use` statements for referenced classes
  - Preserves custom `use` statements
  - Adds LLM coding convention directives
  - Applies Laravel Pint formatting
- **For `/rsx/` directory without classes**: Applies Laravel Pint formatting only
- **Other locations**: No formatting applied

### JavaScript/CSS Formatting
- Uses Prettier (required)
- 140 character line width

## Prerequisites

The formatters require the following tools to be installed on your development machine:

- **Python 3.6+** (for the orchestrator)
- **PHP 7.4+** (for PHP formatting)
- **Node.js 14+** (for JS/CSS formatting with Prettier)
- **prettier** (npm package for JS/CSS formatting)

**Note:** If any prerequisites are missing, VS Code will automatically prompt you to install them when you open the project.

## Manual Installation Instructions

**Note:** VS Code will automatically check for and offer to install these dependencies when you open the project. The following instructions are for manual installation if needed.

### Windows (via Chocolatey)

1. Install Chocolatey if not already installed:
   ```powershell
   # Run as Administrator
   Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1'))
   ```

2. Install prerequisites:
   ```powershell
   # Run as Administrator
   choco install python3 php nodejs -y
   ```

3. Verify installations:
   ```powershell
   python --version
   php --version
   node --version
   ```

4. Install project dependencies:
   ```powershell
   npm install
   ```

### macOS (via Homebrew)

1. Install Homebrew if not already installed:
   ```bash
   /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
   ```

2. Install prerequisites:
   ```bash
   brew install python@3 php node
   ```

3. Verify installations:
   ```bash
   python3 --version
   php --version
   node --version
   ```

4. Install project dependencies:
   ```bash
   npm install
   ```

### Ubuntu/Debian

1. Update package list:
   ```bash
   sudo apt update
   ```

2. Install prerequisites:
   ```bash
   sudo apt install python3 python3-pip php-cli nodejs npm -y
   ```

3. Verify installations:
   ```bash
   python3 --version
   php --version
   node --version
   ```

4. Install project dependencies:
   ```bash
   npm install
   ```

### Other Linux Distributions

For other distributions, use your package manager to install:
- `python3` (3.6 or higher)
- `php` (CLI version, 7.4 or higher)
- `nodejs` and `npm` (14 or higher)
- Then install project dependencies: `npm install`

## VS Code Integration

### 1. Install the Run on Save Extension

In VS Code, install the "Run on Save" extension by emeraldwalk:
- Open Extensions (Ctrl+Shift+X / Cmd+Shift+X)
- Search for "Run on Save"
- Install the extension by emeraldwalk

### 2. Configure VS Code

The `.vscode/settings.json` file should already contain:

```json
"emeraldwalk.runonsave": {
  "commands": [
    {
      "match": "\\.php$",
      "cmd": "python3 ${workspaceFolder}/.vscode/formatters/orchestrator.py ${file}",
      "isAsync": true,
      "notificationOnSuccess": false,
      "notificationOnFailure": true
    }
  ]
}
```

Note: On Windows, you may need to change `python3` to `python` depending on your installation.

### 3. Test the Integration

1. Open a PHP file in the `/app/` or `/rsx/` directory
2. Make some formatting changes (add extra spaces, bad indentation)
3. Save the file (Ctrl+S / Cmd+S)
4. The file should be automatically formatted

## Troubleshooting

### "python3: command not found" on Windows
- Try using `python` instead of `python3` in the VS Code settings
- Ensure Python is in your PATH

### "php: command not found"
- Ensure PHP CLI is installed and in your PATH
- On Windows, you may need to restart VS Code after installation

### Formatter not working
1. Check the VS Code Output panel (View → Output → "Run on Save")
2. Test the formatter manually:
   ```bash
   python3 .vscode/formatters/orchestrator.py path/to/file.php
   ```
3. Ensure you have Laravel Pint installed in your project:
   ```bash
   composer require laravel/pint --dev
   ```

### File not reloading in VS Code
- VS Code should automatically detect external file changes
- If not, you can manually reload: Ctrl+Shift+P → "File: Revert File"

## How It Works

1. When you save a file in VS Code, the Run on Save extension triggers
2. It calls `orchestrator.py` with the file path
3. The orchestrator determines the file type and location
4. For PHP files:
   - In `/app/`: Runs Laravel Pint only
   - In `/rsx/` with classes: Applies RSX formatting + Pint
   - In `/rsx/` without classes: Runs Pint only
5. The file is updated in place
6. VS Code detects the change and reloads the file

## Adding New Formatters

To add support for new file types:

1. Create a new formatter script (e.g., `js_formatter.js`)
2. Update `orchestrator.py` to call your formatter
3. Add any new prerequisites to this README
4. Update VS Code settings if needed

## Development

To test formatters without VS Code:

```bash
# Test PHP formatter directly
php .vscode/formatters/php_formatter.php /path/to/file.php rsx

# Test orchestrator
python3 .vscode/formatters/orchestrator.py /path/to/file.php
```

## Notes

- The formatters are designed to be idempotent - running them multiple times produces the same result
- Custom `use` statements in PHP files are always preserved
- The formatters respect the project's existing code style (via Pint configuration)
- All paths are handled in an OS-agnostic way (works on Windows, macOS, and Linux)