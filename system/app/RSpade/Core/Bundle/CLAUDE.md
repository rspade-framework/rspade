# Bundle System

## Two-Tier Bundle Architecture

**Module Bundles** (`Rsx_Module_Bundle_Abstract`)
- Top-level entry point bundles rendered on pages
- Can scan directories via `include` paths
- Auto-discovers Asset Bundles in scanned directories
- Cannot include other Module Bundles (fatal error)
- Built via `rsx:bundle:build`

**Asset Bundles** (`Rsx_Asset_Bundle_Abstract`)
- Dependency declaration bundles co-located with components
- NO directory scanning - only CDN assets, NPM modules, direct file paths
- Auto-discovered when Module Bundles scan directories containing them
- Never built standalone - metadata consumed by Module Bundles
- Can use `watch` directories for cache invalidation (e.g., Bootstrap SCSS)

**Auto-Discovery Rules:**
- Asset Bundles discovered via directory scan cannot have directory paths in `include`
- If Asset Bundle needs directory scanning, include it explicitly by class name
- Discovery uses `Manifest::php_is_subclass_of()` (NOT filename patterns)

# Bundle Processor System

## Architecture
- **Extension-agnostic BundleCompiler**: Only handles .js and .css files directly
- **Global processor configuration**: Processors configured in `config/rsx.php` under `bundle_processors`
- **Processors receive ALL files**: Each processor examines all collected files and decides what to process
- **No per-bundle processor config**: Processors are globally enabled, not per-bundle

## How It Works
1. BundleCompiler collects ALL files from bundle includes (not just JS/CSS)
2. Each configured processor receives the full list of collected files
3. Processors decide which files to process based on extension or other criteria
4. Processed files replace or augment original files in the bundle
5. Final bundle contains JS and CSS files only

## Creating a Processor
```php
class MyProcessor extends AbstractBundleProcessor {
    public static function get_name(): string { return 'myprocessor'; }
    public static function get_extensions(): array { return ['myext']; }
    public static function process(string $file_path, array $options = []): ?array {
        // Transform file and return result or null to exclude
    }
}
```

## Registering Processors
Add to `config/rsx.php`:
```php
'bundle_processors' => [
    \App\RSpade\Processors\MyProcessor::class,
]
```

## SCSS @import Validation
- **Non-vendor files**: Cannot use @import directives (throws error)
- **Vendor files**: Files in directories named 'vendor' CAN use @import
- **Rationale**: @import bypasses bundle dependency management
- **Solution**: Include dependencies directly in bundle definition