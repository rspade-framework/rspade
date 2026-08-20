# Manifest System - Developer Documentation

This documentation is for developers working on Manifest.php itself. For usage documentation, see the man pages via `php artisan rsx:man manifest_api`.

## CRITICAL: Testing Manifest.php Changes

**When modifying Manifest.php or any helper class, you MUST run `php artisan rsx:manifest:build --clean` to test your changes.**

The `--clean` flag performs both `rsx:clean` and a full manifest rebuild, ensuring your modifications are properly tested. Without this, the manifest may use cached or partially-built data that doesn't reflect your changes.

## Architecture Overview

The Manifest is a compiled cache of all file metadata in the RSX application, stored at `storage/rsx-build/manifest_data.php`. It replaces Laravel's scattered discovery mechanisms with a unified system that enables path-agnostic class loading.

### Helper Class Architecture

Manifest.php uses a delegator pattern with 8 helper classes for better organization:

| Helper Class | Purpose | Functions |
|--------------|---------|-----------|
| `_Manifest_PHP_Reflection_Helper` | PHP class reflection | php_find_class, php_get_extending, php_is_subclass_of, etc. |
| `_Manifest_JS_Reflection_Helper` | JS class reflection | js_find_class, js_get_extending, js_is_subclass_of, etc. |
| `_Manifest_Reflection_Helper` | Views, attributes, routes | find_view, get_with_attribute, get_routes, etc. |
| `_Manifest_Scanner_Helper` | File discovery, change detection | _get_rsx_files, _has_changed, _scan_directory_for_classes, etc. |
| `_Manifest_Builder_Helper` | Index generation | _build_autoloader_class_map, _collate_files_by_classes, etc. |
| `_Manifest_Cache_Helper` | Persistence, validation | _get_kernel, _load_cached_data, _save, _validate_cached_data, etc. |
| `_Manifest_Quality_Helper` | Code quality, IDE support | _process_code_quality_metadata, _generate_vscode_stubs, etc. |
| `_Manifest_Database_Helper` | Database schema | db_get_tables, db_get_table_columns, _verify_database_provisioned, etc. |

**Pattern**: Manifest.php contains the public API and delegator methods. Each delegator forwards to the corresponding helper class method. Internal methods use single `_` prefix (conceptually private but technically public for cross-class access).

**When modifying**: Edit the helper class containing the implementation, not Manifest.php (unless changing the public API signature).

### Core Data Structure

The manifest cache contains:

```php
[
    'generated' => '2025-09-28 05:02:15',
    'hash' => '42b9d0efb5c547eec0fb2ca19bf922e0',
    'data' => [
        'files' => [...],           // All indexed files with metadata
        'js_classes' => [...],       // JavaScript class map
        'php_classes' => [...],      // PHP class map
        'autoloader_class_map' => [...], // Simple name to FQCN mapping
        'models' => [...],           // Database model metadata
        'jqhtml' => [...]           // jqhtml component registry
    ]
]
```

### Schema Discovery Tool

Use `php artisan rsx:manifest:schema_dump` to inspect the manifest structure:

```bash
# Pretty-printed JSON for human reading
php artisan rsx:manifest:schema_dump

# Compact JSON for LLM parsing (saves tokens)
php artisan rsx:manifest:schema_dump --no-pretty-print
```

This tool deduplicates array structures, showing unique patterns with example values. Essential for understanding the actual data structure when writing code that directly accesses manifest data.

## 6-Phase Build Process

The build process follows these phases (implementations in helper classes):

### Phase 1: File Discovery
- Implemented in `_Manifest_Scanner_Helper::_get_rsx_files()`
- Scans directories from `config('rsx.manifest.scan_directories')`
- Default (relative to `base_path()` = `system/`): `['rsx', 'app/RSpade/Core', 'app/RSpade/Integrations', 'app/RSpade/Bundles', 'app/RSpade/Breadcrumbs', 'app/RSpade/CodeQuality', 'app/RSpade/Lib', 'app/RSpade/temp']`
- Excludes filenames via `config('rsx.manifest.excluded_files')` and path segments via `config('rsx.manifest.excluded_dirs')` (vendor, node_modules, storage, .git, public, resource, Core/Manifest)
- Returns array of file paths with basic stats (mtime, size)

### Phase 2: Token Parsing
- Implemented in `_Manifest_Scanner_Helper::_process_file()`
- Uses `token_get_all()` for fast PHP parsing without loading
- Extracts: namespace, class name, extends, implements
- **Normalizes all class references to simple names** (strips namespace qualifiers)
- Builds dependency graph for loading order

### Phase 3: Dependency Loading
- Implemented in `_Manifest_Scanner_Helper::_load_changed_php_files()`
- Loads PHP files in dependency order (parents before children)
- Uses `_load_class_hierarchy()` to ensure parent classes exist
- Critical for reflection to work properly

### Phase 4: Reflection & Module Processing
- Implemented in `_Manifest_Scanner_Helper::_extract_reflection_data()`
- PHP and JavaScript are parsed directly in Manifest.php (NOT modules) - PHP attribute/method reflection and JS class extraction are first-class
- Runs registered per-file modules (`config('rsx.manifest_modules')`), then support modules (`config('rsx.manifest_support')`):
  - `Blade_ManifestModule`: Parses Blade directives (per-file module)
  - `Scss_ManifestModule`: SCSS metadata (per-file module, in `Integrations/Scss`)
  - `Model_ManifestSupport`: Adds database schema to models (support module)
  - plus `Route_`, `Portal_Route_`, `Portal_Spa_`, `Jqhtml_`, `Spa_`, `Api_Endpoint_` support modules
- Stores ALL attributes without validation (agnostic extraction)

### Phase 5: Stub Generation
- Implemented in Manifest.php (not delegated)
- Creates JavaScript stub classes for:
  - Controllers with `Ajax_Endpoint` methods → `storage/rsx-build/js-stubs/`
  - Models with `fetch()` methods → `storage/rsx-build/js-model-stubs/`
- Enables clean JavaScript API calls without manual Ajax wiring

### Phase 6: Cache Writing
- Implemented in `_Manifest_Cache_Helper::_save()`
- Serializes to PHP array format
- Writes to `storage/rsx-build/manifest_data.php`
- Uses `var_export()` for fast `include()` loading

## Key Implementation Details

### Class Name Normalization Philosophy

**CRITICAL**: RSX enforces unique simple class names across the entire codebase. This architectural constraint allows the manifest to normalize ALL class references to simple names, eliminating an entire class of bugs related to namespace format variations.

**The Problem**: PHP allows multiple ways to reference classes:
- `\Rsx\Lib\DataGrid` (leading backslash)
- `Rsx\Lib\DataGrid` (no leading backslash)
- `DataGrid` (simple name)

**The Solution**: `Manifest::_normalize_class_name()` strips namespace qualifiers:

```php
public static function _normalize_class_name(string $class_name): string
{
    // Strip leading backslash
    $class_name = ltrim($class_name, '\\');

    // Extract just the class name (last part after final backslash)
    $parts = explode('\\', $class_name);
    return end($parts);
}
```

**Applied At**:
1. **Token parsing** - `Php_Parser::_extract_class_info()` normalizes `extends` at extraction
2. **Parent lookups** - `_Manifest_PHP_Reflection_Helper::_load_class_hierarchy()` normalizes before comparison
3. **All class name operations** - Any code comparing class names uses normalization

**Why This Works**: RSX's unique simple class name enforcement means we only need FQCNs at actual `include_once` time. Throughout the manifest, simple names are sufficient and eliminate format inconsistencies.

### Static State Management

The Manifest uses public static properties (accessible by helper classes):

```php
public static ?array $data = null;           // Cached manifest data
public static bool $_has_init = false;       // Initialization flag
public static bool $_needs_manifest_restart = false; // Restart signal
public static ?ManifestKernel $kernel = null; // Kernel instance
public static array $_changed_files = [];    // Files changed in last scan
```

### Public API Methods

#### Initialization & Loading
- `init()` - Ensures manifest is loaded (auto-rebuilds in dev mode)
- `get_full_manifest()` - Returns complete manifest structure with metadata
- `clear()` - Clears the in-memory cache

#### File Lookups
- `get_all()` - Returns all files array
- `get_file($path)` - Get specific file metadata (throws if not found)
- `get_files_by_dir($dir)` - Get all files in directory
- `get_changed_files()` - Get files that changed in most recent manifest scan (for incremental code quality checks)

#### PHP Class Resolution
- `php_find_class($name)` - Find by simple class name
- `find_php_fqcn($fqcn)` - Find by fully qualified name
- `php_get_metadata_by_class($name)` - Get metadata by class name
- `php_get_metadata_by_fqcn($fqcn)` - Get metadata by FQCN
- `php_get_extending($parent)` - Find all concrete classes extending parent (filters out abstract)
- `php_is_subclass_of($sub, $super)` - Check inheritance

#### JavaScript Class Resolution
- `js_find_class($name)` - Find JavaScript class file
- `js_get_extending($parent)` - Find extending JS classes
- `js_is_subclass_of($sub, $super)` - Check JS inheritance

#### View Resolution
- `find_view($id)` - Find by dot notation (e.g., 'frontend.index')
- `find_view_by_rsx_id($id)` - Find by @rsx_id value

#### Attribute & Route Discovery
- `get_with_attribute($attr)` - Find all files with specific attribute
- `get_routes()` - Extract all route definitions from attributes

#### Utility Methods
- `get_build_key()` - Get manifest hash for cache validation
- `get_stats()` - Get statistics (file counts, build time)
- `get_autoloader_class_map()` - Get class-to-file mappings
- `is_built()` - Check if manifest exists
- `_normalize_class_name($class_name)` - Strip namespace qualifiers to get simple name

### Attribute Extraction Philosophy

The manifest practices **agnostic attribute extraction**:

1. **No Validation** - Stores all attributes without checking validity
2. **No Instantiation** - Never creates attribute objects
3. **No Class Loading** - Attributes don't need backing classes
4. **Raw Storage** - Stores exactly what reflection provides

Example from `_Manifest_Scanner_Helper::_extract_reflection_data()`:
```php
foreach ($method->getAttributes() as $attribute) {
    $attributes[] = [
        'name' => $attribute->getName(),
        'arguments' => $attribute->getArguments()
    ];
}
```

### Change Detection

Files are tracked by:
- `mtime` - Modification time (primary)
- `size` - File size in bytes (secondary)
- `hash` - SHA1, only computed when mtime/size change

The `_Manifest_Scanner_Helper::_has_changed()` method compares these to detect changes.

### Error Handling

All lookup methods throw `RuntimeException` when items not found:
```php
if (!isset(Manifest::$data['data']['php_classes'][$class_name])) {
    throw new RuntimeException("PHP class not found in manifest: {$class_name}");
}
```

This ensures **fail-fast behavior** - no silent failures.

## Module System

PHP and JavaScript are parsed directly in Manifest.php and are NOT modules.
Modules cover additional file types and post-scan derivations. There are two
kinds, both abstract base classes (NOT an interface).

### Built-in Per-File Modules (`config('rsx.manifest_modules')`)

- `Blade_ManifestModule` (`Core/Manifest/Modules/`) - Blade directive parsing
- `Scss_ManifestModule` (`Integrations/Scss/`) - SCSS metadata

### Built-in Support Modules (`config('rsx.manifest_support')`, run in order)

- `Route_ManifestSupport`, `Portal_Route_ManifestSupport`, `Portal_Spa_ManifestSupport`
- `Model_ManifestSupport` (`Core/Manifest/Modules/`) - database schema extraction
- `Jqhtml_ManifestSupport` (`Integrations/Jqhtml/`) - jqhtml component registration
- `Spa_ManifestSupport`, `Api_Endpoint_ManifestSupport`

### Creating a Per-File Module

Extend `ManifestModule_Abstract`:

```php
abstract class ManifestModule_Abstract {
    abstract public function handles(): array;   // extensions, no dot
    abstract public function priority(): int;    // lower = earlier
    abstract public function process(string $file_path, array $metadata): array;
}
```

Register the class in `config('rsx.manifest_modules')`.

### Creating a Support Module

Extend `ManifestSupport_Abstract` (runs after the full manifest is built;
mutates the data by reference):

```php
abstract class ManifestSupport_Abstract {
    abstract public static function process(array &$manifest_data): void;
    abstract public static function get_name(): string;
    public static function should_run(): bool { return true; }
}
```

Register the class in `config('rsx.manifest_support')`.

## Performance Considerations

### Caching Strategy
- **Development**: Auto-rebuilds on file changes
- **Production**: Manual rebuild required, loads from cache file
- **Memory**: Full manifest kept in static variable after first load

### Optimization Points
- Token parsing avoids loading PHP files unnecessarily
- Dependency ordering minimizes class loading failures
- SHA1 hashing only when size/mtime indicate changes
- `var_export()` format allows fast PHP `include()`

## Common Issues & Solutions

### Issue: "Class not found" during reflection
**Cause**: Parent class not loaded before child
**Solution**: Check `_Manifest_PHP_Reflection_Helper::_load_class_hierarchy()` is working correctly

### Issue: Parent class not found (extends mismatch)
**Cause**: Namespace format variations in `extends` declarations (e.g., `\Rsx\Lib\DataGrid` vs `Rsx\Lib\DataGrid`)
**Solution**: Use `Manifest::_normalize_class_name()` to strip namespace qualifiers before comparison

### Issue: Attributes not appearing in manifest
**Cause**: Method not public static
**Solution**: Only public static methods are indexed

### Issue: Manifest not updating
**Cause**: In production mode or file timestamps unchanged
**Solution**: Run `php artisan rsx:manifest:build --force`

### Issue: JavaScript stubs not generating
**Cause**: Missing `Ajax_Endpoint` attribute or method not public static
**Solution**: Check method has attribute and correct visibility

## Code Quality Integration

The manifest integrates with code quality checks via metadata storage. Rules that run during manifest scan store violations in `code_quality_metadata` field for each file.

### Manifest-Time Checks Run AFTER the Save (save-then-check)

The manifest-time code quality violation pass runs AFTER the manifest is persisted, not before:

1. `Manifest.php:1346` — `_save()` writes the fully-built manifest to `storage/rsx-build/manifest_data.php`. At this moment `$_manifest_is_bad` is still `false`, so a CLEAN manifest is written and every processed file's mtime/size/hash is now current on disk.
2. `Manifest.php:1356` — `_run_manifest_time_code_quality_checks()` runs the violation pass. It is gated to `env !== production` + a non-empty changed-set + not a migration context.

A single violation calls `_set_manifest_is_bad()` (`Manifest.php:1387`), which sets the `manifest_is_bad` flag and RE-SAVES the cache with that flag on, then the driver throws `YoureDoingItWrongException` to abort the request/build.

### The `manifest_is_bad` Poison Flag — a Failed Rule Keeps Failing Until Fixed

The guarantee that a manifest-time violation "keeps failing until the source is fixed" does NOT come from change tracking. The clean `_save()` at `:1346` already refreshed the offending file's mtime/size/hash, so incremental detection alone would consider it unchanged on the next load. The guarantee comes from the poison flag:

- On the next load, `_load_cached_data()` sees `manifest_is_bad` truthy (`_Manifest_Cache_Helper.php`), discards the cache to an empty files map, and returns `false` — so `init()` treats it as no valid cache and forces a FULL rebuild (every file treated as changed).
- The full rebuild reprocesses the offending file, the CQ pass at `:1356` re-fires the same violation, the poison flag is written again, and the build aborts again.
- This loop repeats on every subsequent request/build until the source is fixed. Once fixed, the rule passes, `_save()` at `:1346` writes a clean (un-poisoned) manifest, and normal incremental operation resumes.

So the save-before-check ordering is safe: a violation immediately overwrites the clean manifest with the `manifest_is_bad` flag, a hard full-rebuild trigger independent of mtime. (`_process_code_quality_metadata()` at `:1262`, which runs before the save, has the same guarantee via its own `_set_manifest_is_bad()` + rethrow.)

### Incremental Code Quality Checks

Manifest-time code quality rules support incremental checking for better performance:

- **Incremental rules** (`is_incremental() = true`): Only check files that changed in this manifest scan
- **Cross-file rules** (`is_incremental() = false`): Run once per build, access full manifest via `get_all()`

The `get_changed_files()` API provides the list of files that changed, enabling rules to optimize their processing.

## Debugging

Enable verbose output:
```php
Manifest::$_debug_options['verbose'] = true;
```

Or via command:
```bash
php artisan rsx:manifest:build --verbose
```

## Testing Considerations

When testing manifest functionality:
1. Use `Manifest::clear()` between tests to reset state
2. Mock file system for predictable test data
3. Test both scan() and rebuild() paths
4. Verify exception throwing for not-found cases
5. Check stub generation for API methods

## Important Constants & Paths

- Cache file: `storage/rsx-build/manifest_data.php`
- JS stubs: `storage/rsx-build/js-stubs/`
- Model stubs: `storage/rsx-build/js-model-stubs/`
- Default scan dirs (see `config('rsx.manifest.scan_directories')`): `['rsx', 'app/RSpade/Core', 'app/RSpade/Integrations', 'app/RSpade/Bundles', 'app/RSpade/Breadcrumbs', 'app/RSpade/CodeQuality', 'app/RSpade/Lib', 'app/RSpade/temp']`

## Direct Data Access

When bypassing helper methods to access manifest data directly:

```php
$manifest = Manifest::get_full_manifest();

// Access files
$file_data = $manifest['data']['files']['path/to/file.php'];

// Access class maps
$class_file = $manifest['data']['php_classes']['ClassName'];
$js_class = $manifest['data']['js_classes']['JsClass'];

// Access models with schema
$model = $manifest['data']['models']['User_Model'];
$columns = $model['columns'];
```

Use `rsx:manifest:schema_dump` to understand the exact structure before writing direct access code.
