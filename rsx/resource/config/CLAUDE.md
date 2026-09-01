# Configuration Override System

## Purpose

This directory contains user configuration files that override framework defaults. Values here are merged with the framework configuration in `system/config/`, allowing you to customize RSX behavior without modifying framework files.

## Quick Start

Edit `rsx.php` and set only the keys you want to change. The file ships with the
common ones commented out - uncomment and edit, leave the rest alone:

```php
return [
    'development' => [
        'auto_rename_files' => false,       // disable auto-renaming
    ],

    'code_quality' => [
        'root_whitelist' => [
            'my-build-script.js',           // allow a custom root file
        ],
    ],

    'bundle_aliases' => [
        'my-theme' => \Rsx\App\My_Theme_Bundle::class,
    ],
];
```

The full list of available keys and their framework defaults is
`system/config/rsx.php` (never edit that file) - `php artisan rsx:man config_rsx`
is its documented form.

## How Configuration Merging Works

Configuration files in this directory are automatically merged with their framework counterparts using deep array merging (`array_merge_deep`):

- **Array values**: Combined (no duplicates for numeric arrays)
- **Scalar values**: User config takes precedence
- **Nested arrays**: Recursively merged

**Example:**

```php
// Framework default: system/config/rsx.php
'code_quality' => [
    'root_whitelist' => ['vite.config.js', 'webpack.config.js'],
    'is_framework_developer' => false,
]

// User override: /rsx/resource/config/rsx.php
'code_quality' => [
    'root_whitelist' => ['my-custom-config.js'],
    'is_framework_developer' => true,
]

// Merged result (accessed via config()):
'code_quality' => [
    'root_whitelist' => ['vite.config.js', 'webpack.config.js', 'my-custom-config.js'],
    'is_framework_developer' => true,  // User value takes precedence
]
```

## Usage

Access all configuration values through Laravel's `config()` helper:

```php
// Get a specific config value
$auto_rename = config('rsx.development.auto_rename_files');

// Get entire section
$dev_settings = config('rsx.development');

// Get with default fallback. NOTE: rsx.libreoffice.timeout is a SANCTIONED timeout
// bounding one external soffice/pdftotext call - see `rsx:man libreoffice`, TIMEOUT.
$timeout = config('rsx.libreoffice.timeout', 120);
```

The merge happens transparently at boot time - you don't need to do anything special.

## Available Configuration Files

| File | Purpose |
|------|---------|
| `rsx.php` | Main RSX framework configuration overrides |

## Configuration Sections in rsx.php

- **development** - IDE integration, file auto-renaming, convention checking
- **ide_integration** - VS Code extension and bridge settings
- **code_quality** - Custom whitelists, suffix exemptions, rules
- **console_debug** - Debug channel filters and output customization
- **bundle_aliases** - Application-specific bundle shortcuts

## Deployment-specific values

A value that differs per BOX - a host, a credential, a toggle one environment sets -
belongs in `.env`, read through `env()` from this file. Application BEHAVIOUR belongs
here in version control, never in `.env`:

```env
LOG_BROWSER_ERRORS=true
```

```php
'log_browser_errors' => env('LOG_BROWSER_ERRORS', false),
```

Any new key added this way is also added to the project-root `.env.dist` with a
sensible default (never a secret) - that is how it reaches every other box.

## Best Practices

1. **Comment out defaults** - Keep commented examples for reference
2. **Only override what you need** - Don't duplicate entire config sections
3. **Use environment variables** - For deployment-specific values (via `env()`)
4. **Document customizations** - Add comments explaining why you override defaults
5. **Version control** - Commit this file with your application
6. **Check the framework defaults first** - `system/config/rsx.php` is the list of
   everything that can be overridden

## Framework Developer Note

When developing the RSX framework itself, avoid modifying files in `/rsx/resource/` as these are distributed to end users. Framework defaults belong in `system/config/`.
