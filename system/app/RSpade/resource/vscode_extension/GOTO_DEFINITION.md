# Go to Definition - JavaScript to PHP Navigation

## Overview

The RSpade VS Code extension now supports "Go to Definition" navigation from JavaScript class references to their corresponding PHP implementations. This feature is particularly useful when working with RSX's Internal API system, where JavaScript code calls PHP methods through auto-generated stubs.

## How It Works

When you right-click on an identifier and select "Go to Definition", the extension will:

### For JavaScript Class References
1. Detect if the identifier looks like an RSX class (contains underscore, starts with capital letter)
2. Query the IDE helper endpoint (`/_idehelper`) to resolve the PHP file location
3. If a method is being called (e.g., `Demo_Index_Controller.hello_world()`), navigate directly to that method
4. Open the PHP file at the exact line where the class or method is defined

### For Bundle Aliases in PHP
1. Detect if you're in a bundle's `'include'` array
2. Check if the string is a bundle alias (lowercase single word like 'bootstrap5', 'jquery')
3. Query the IDE helper to resolve the alias to its bundle class
4. Open the bundle PHP file at the class definition

## Technical Implementation

### IDE Helper Endpoint

The endpoint `/_ide/service/*` (implemented in `/app/RSpade/Ide/Services/handler.php`) accepts:
- `class` parameter: The PHP class name to resolve
- `method` parameter (optional): The specific method to navigate to

Returns JSON with:
- `found`: Whether the class was found
- `file`: Relative path to the PHP file
- `line`: Line number where the class/method is defined
- `metadata`: Additional information about the class

### VS Code Extension

The `RspadeDefinitionProvider` (in `/app/RSpade/Extension/src/definition_provider.ts`):
- Implements VS Code's `DefinitionProvider` interface
- Registers for JavaScript and TypeScript files
- Makes HTTPS requests to the IDE helper endpoint
- Returns a `Location` object pointing to the PHP file

## Usage Examples

### JavaScript to PHP Navigation

```javascript
// In rsx/app/demo/demo_index.js
console.log(await Demo_Index_Controller.hello_world());
//                 ^^^^^^^^^^^^^^^^^^^^^ Right-click here
//                                       Select "Go to Definition"
//                                       Opens demo_index_controller.php at line 48
```

### Bundle Alias Navigation

```php
// In rsx/app/frontend/frontend_bundle.php
public static function define(): array
{
    return [
        'include' => [
            'bootstrap5',   // <- Right-click here, Go to Definition
                           //    Opens Bootstrap5_Bundle.php
            'jquery',      // <- Opens Jquery_Bundle.php
            'lodash',      // <- Opens Lodash_Bundle.php
        ],
    ];
}
```

## Configuration

The base URL for the IDE helper can be configured in VS Code settings:

```json
{
  "rspade.baseUrl": "https://rspade.claude.dev.hanson.xyz"
}
```

## Security

- The IDE helper endpoint is only available in development mode
- Production environments return a 403 Forbidden error
- No sensitive information is exposed through this endpoint

## Installation

After building the extension with `./build.sh`, install the generated `.vsix` file:

1. In VS Code: Extensions → ... → Install from VSIX
2. Or via command line: `code --install-extension rspade-framework.vsix`

## Limitations

- Only works for RSX class names (containing underscores, starting with capital letter)
- Requires the RSpade manifest to be up-to-date
- The PHP file must exist and be indexed in the manifest