# Configuration Overrides

This directory contains configuration files that customize your RSX application's behavior.

## Quick Start

Edit `rsx.php` to override framework defaults. Uncomment and modify the values you want to change:

```php
return [
    'development' => [
        'auto_rename_files' => false,  // Disable auto-renaming
    ],

    'code_quality' => [
        'root_whitelist' => [
            'my-build-script.js',  // Allow custom root file
        ],
    ],
];
```

## How It Works

Your configuration values are **merged** with the framework defaults in `/config/rsx.php`:

- **Arrays are combined** - Your values are added to framework defaults
- **Your values take priority** - Scalar values override framework defaults
- **Deep merging** - Nested arrays are merged recursively

Access all configuration through Laravel's `config()` function:

```php
$setting = config('rsx.development.auto_rename_files');
```

## Common Customizations

### Development Settings

```php
'development' => [
    'auto_rename_files' => false,        // Disable auto-rename feature
    'ignore_filename_convention' => true, // Skip filename checks
],
```

### Code Quality

```php
'code_quality' => [
    'root_whitelist' => [
        'build.config.js',  // Allow custom root files
    ],
],
```

### Custom Bundle Aliases

```php
'bundle_aliases' => [
    'my-theme' => \Rsx\App\My_Theme_Bundle::class,
],
```

## Environment Variables

Use `.env` file for deployment-specific values:

```env
LOG_BROWSER_ERRORS=true
```

Then reference in config:

```php
'log_browser_errors' => env('LOG_BROWSER_ERRORS', false),
```

## Tips

- Only override values you need to change
- Keep commented examples as reference
- Add comments explaining your customizations
- Commit this file to version control
- Check `/config/rsx.php` for all available options
