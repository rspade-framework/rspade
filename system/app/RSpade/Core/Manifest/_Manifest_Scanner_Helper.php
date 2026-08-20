<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\CodeQuality\RuntimeChecks\ManifestErrors;
use App\RSpade\Core\ExtensionRegistry;
use App\RSpade\Core\Manifest\Manifest;

/**
 * _Manifest_Scanner_Helper - File discovery, change detection, extraction
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Scanner_Helper
{
    // Static properties are defined on Manifest class and accessed via Manifest::$property

    /**
    * Get all files in configured scan directories (returns relative paths)
    */
    public static function _get_rsx_files(): array
    {
        if (!empty(Manifest::$_get_rsx_files_cache)) {
            return Manifest::$_get_rsx_files_cache;
        }

        $base_path = base_path();
        $scan_paths = config('rsx.manifest.scan_directories', ['rsx']);
        $files = [];

        foreach ($scan_paths as $scan_path) {
            $full_path = base_path($scan_path);

            // Check if path exists - throw fatal error if not (except for app/RSpade/temp)
            if (!file_exists($full_path)) {
                // Special case: silently skip app/RSpade/temp if it doesn't exist
                if ($scan_path === 'app/RSpade/temp') {
                    continue;
                }

                throw new \RuntimeException(
                    "Manifest scan path does not exist: '{$scan_path}'\n" .
"Please ensure all paths in config('rsx.manifest.scan_directories') exist.\n" .
'Current configuration includes: ' . implode(', ', $scan_paths)
                );
            }

            // If it's a file, add it directly
            if (is_file($full_path)) {
                // Convert to relative path and normalize to forward slashes
                $relative_path = str_replace($base_path . '/', '', $full_path);
                $relative_path = str_replace('\\', '/', $relative_path);
                // Remove leading slash if present
                $relative_path = ltrim($relative_path, '/');
                $files[] = $relative_path;
                continue;
            }

            // If it's a directory, recursively scan it
            if (is_dir($full_path)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveCallbackFilterIterator(
                        new \RecursiveDirectoryIterator($full_path, \RecursiveDirectoryIterator::SKIP_DOTS),
                        function ($file, $key, $iterator) {
                            $basename = $file->getBasename();

                            // Skip hidden files and directories (those starting with .)
                            if (strpos($basename, '.') === 0) {
                                return false;
                            }

                            // If it's a directory, check if it should be excluded
                            if ($iterator->hasChildren()) {
                                // Don't recurse into excluded directories
                                $excluded_dirs = config('rsx.manifest.excluded_dirs', Manifest::EXCLUDED_DIRS);

                                return !in_array($basename, $excluded_dirs);
                            }

                            // It's a file, check if filename should be excluded
                            $excluded_files = config('rsx.manifest.excluded_files', []);
                            if (in_array($basename, $excluded_files)) {
                                return false;
                            }

                            return true;
                        },
                    ),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $absolute_path = $file->getPathname();
                        // Convert to relative path and normalize to forward slashes
                        $absolute_path = str_replace('\\', '/', $absolute_path);
                        $base_path_normalized = str_replace('\\', '/', $base_path);
                        $relative_path = str_replace($base_path_normalized . '/', '', $absolute_path);
                        // Remove leading slash if present
                        $relative_path = ltrim($relative_path, '/');

                        // Check for disallowed .old. naming pattern
                        if (preg_match('/\\.old\\.\\w+$/', $relative_path)) {
                            ManifestErrors::old_file_pattern($relative_path);
                        }

                        $files[] = $relative_path;
                    }
                }
            }
        }

        // Hardcoded ./json includes
        $hardcoded_files = ['package-lock.json', 'composer.lock'];

        foreach ($hardcoded_files as $file) {
            if (file_exists($file) && !in_array($file, $files)) {
                $files[] = $file;
            }
        }

        // Remove duplicates and sort
        $files = array_unique($files);
        sort($files);

        Manifest::$_get_rsx_files_cache = $files;

        return $files;
    }

    /**
    * Check if a file has changed (expects relative path)
    */
    public static function _has_changed(string $file): bool
    {
        if (isset(Manifest::$_has_changed_cache[$file])) {
            return Manifest::$_has_changed_cache[$file];
        }

        if (!isset(Manifest::$data['data']['files'][$file])) {
            // Only show the message once per page load
            if (!Manifest::$__shown_rescan_message) {
                console_debug('MANIFEST', '* New file ' . $file . ' is triggering manifest rescan *');
                Manifest::$__shown_rescan_message = true;
            }
            Manifest::$_has_changed_cache[$file] = true;

            return true;
        }

        $old = Manifest::$data['data']['files'][$file];
        $absolute_path = base_path($file);

        // Make sure file exists
        if (!file_exists($absolute_path)) {
            // Only show the message once per page load
            if (!Manifest::$__shown_rescan_message) {
                console_debug('MANIFEST', '* File ' . $file . ' appears to be deleted in ' . $absolute_path . ', triggering manifest rescan *');
                Manifest::$__shown_rescan_message = true;
            }
            Manifest::$_has_changed_cache[$file] = true;

            return true;
        }

        $current_size = filesize($absolute_path);

        // Stage 1: Size check (guard for incomplete manifest entries)
        if (!isset($old['size']) || $old['size'] != $current_size) {
            // Only show the message once per page load
            if (!Manifest::$__shown_rescan_message) {
                console_debug('MANIFEST', '* File ' . $file . ' has changed size, triggering manifest rescan *');
                Manifest::$__shown_rescan_message = true;
            }
            Manifest::$_has_changed_cache[$file] = true;

            return true;
        }

        // Stage 2: mtime check (guard for incomplete manifest entries)
        $current_mtime = filemtime($absolute_path);
        if (!isset($old['mtime']) || $old['mtime'] != $current_mtime) {
            // Only show the message once per page load
            if (!Manifest::$__shown_rescan_message) {
                console_debug('MANIFEST', '* File ' . $file . ' has changed mtime, triggering manifest rescan *');
                Manifest::$__shown_rescan_message = true;
            }
            Manifest::$_has_changed_cache[$file] = true;

            return true;
        }

        Manifest::$_has_changed_cache[$file] = false;

        return false;
    }

    /**
    * Scan a directory for PHP classes, excluding vendor directories
    * @param string $directory The directory to scan
    * @return array Map of simple class names to FQCNs
    */
    public static function _scan_directory_for_classes(string $directory): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            // Skip non-PHP files
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Skip vendor directories
            $path = $file->getPathname();
            if (strpos($path, '/vendor/') !== false) {
                continue;
            }

            // Extract class information using token parsing
            $content = file_get_contents($path);
            $tokens = token_get_all($content);
            $namespace = '';
            $class_name = '';
            $getting_namespace = false;
            $getting_class = false;

            foreach ($tokens as $i => $token) {
                if (is_array($token)) {
                    if ($token[0] === T_NAMESPACE) {
                        $getting_namespace = true;
                        $namespace = '';
                    } elseif ($token[0] === T_CLASS || $token[0] === T_INTERFACE || $token[0] === T_TRAIT) {
                        // Make sure this isn't an anonymous class
                        $next_token_idx = $i + 1;
                        while ($next_token_idx < count($tokens) && is_array($tokens[$next_token_idx]) && $tokens[$next_token_idx][0] === T_WHITESPACE) {
                            $next_token_idx++;
                        }
                        if ($next_token_idx < count($tokens) && is_array($tokens[$next_token_idx]) && $tokens[$next_token_idx][0] === T_STRING) {
                            $getting_class = true;
                        }
                    } elseif ($getting_namespace && ($token[0] === T_NAME_QUALIFIED || $token[0] === T_STRING || $token[0] === T_NS_SEPARATOR)) {
                        $namespace .= $token[1];
                    } elseif ($getting_class && $token[0] === T_STRING) {
                        $class_name = $token[1];
                        $getting_class = false;

                        // We have both namespace and class name, add to map
                        if ($class_name) {
                            $fqcn = $namespace ? $namespace . '\\' . $class_name : $class_name;
                            if (!isset($classes[$class_name])) {
                                $classes[$class_name] = [];
                            }
                            $classes[$class_name][] = $fqcn;
                        }
                    }
                } else {
                    // Non-array token (like ; or {)
                    if ($token === ';' || $token === '{') {
                        $getting_namespace = false;
                    }
                }
            }
        }

        return $classes;
    }

    /**
    * Process a single file and extract comprehensive metadata
    * @param string $file_path Relative path to file
    */
    public static function _process_file(string $file_path): array
    {
        $absolute_path = base_path($file_path);
        $stat = stat($absolute_path);
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        // Handle compound extensions as special cases
        if (str_ends_with($file_path, '.externals.php')) {
            // External-resource declarations return a bare array and define no class:
            // the dedicated extension keeps them out of every 'php' code path
            // (class scan, reflection, Php_Fixer). See Core/Externals/Rsx_Externals.php.
            $extension = 'externals.php';
        } elseif (str_ends_with($file_path, '.blade.php')) {
            $extension = 'blade.php';
        } elseif (str_ends_with($file_path, '.php.upstream')) {
            $extension = 'php.upstream';
        }

        $data = [
            'file' => $file_path,  // Store relative path
            'hash' => sha1_file($absolute_path),
            'mtime' => $stat['mtime'],
            'size' => $stat['size'],
            'extension' => $extension,
        ];

        // Use kernel for basic processing (kernel expects absolute path)
        $kernel = Manifest::_get_kernel();
        $kernel_data = $kernel->process($absolute_path, $data);
        $data = array_merge($data, $kernel_data);

        // Add advanced extraction based on file type (pass absolute path)
        switch ($extension) {
            case 'php':
                $php_metadata = \App\RSpade\Core\PHP\Php_Parser::parse($absolute_path, Manifest::$data);
                $data = array_merge($data, $php_metadata);

                // Php_Parser::parse() may have modified the file via Php_Fixer in development mode
                // Recalculate file stats to reflect any changes made during parsing
                if (!app()->environment('production')) {
                    clearstatcache(true, $absolute_path);
                    $updated_stat = stat($absolute_path);
                    $data['hash'] = sha1_file($absolute_path);
                    $data['mtime'] = $updated_stat['mtime'];
                    $data['size'] = $updated_stat['size'];
                }
                break;

            case 'php.upstream':
                // Parse upstream files to extract class metadata
                // These are framework files that were renamed when an rsx/ override was created
                // Php_Fixer only runs on extension 'php', so upstream files are safe
                $php_metadata = \App\RSpade\Core\PHP\Php_Parser::parse($absolute_path, Manifest::$data);
                $data = array_merge($data, $php_metadata);
                break;

            case 'js':
                console_debug('BUILD', "Parsing JS file: {$file_path}");
                $js_metadata = \App\RSpade\Core\JsParsers\Js_Parser::extract_metadata($absolute_path);
                $data = array_merge($data, $js_metadata);
                break;

            case 'blade.php':
            case 'phtml':
                Manifest::_extract_view_info($absolute_path, $data);
                break;

            default:
                // Check if this extension has a registered handler
                if (ExtensionRegistry::process_file($extension, $absolute_path, $data)) {
                    // Handler processed the file
                    break;
                }
        }

        return $data;
    }

    /**
    * Extract public static methods and their attributes using PHP reflection
    * Note: This is called in Phase 4 after all PHP files have been loaded
    */
    public static function _extract_reflection_data(string $file_path, string $full_class_name, array &$data): void
    {
        // Skip method indexing for traits - methods will be indexed when we process classes that use them
        if (isset($data['is_trait']) && $data['is_trait']) {
            return;
        }

        // Class MUST exist if it's in the manifest
        // Fail loud if it doesn't - this indicates a serious problem
        if (!class_exists($full_class_name)) {
            shouldnt_happen("Class {$full_class_name} from manifest cannot be loaded. File: {$file_path}");
        }

        $reflection = new \ReflectionClass($full_class_name);

        // Extract whether the class is abstract
        if ($reflection->isAbstract()) {
            $data['abstract'] = true;
        } else {
            $data['abstract'] = false;
        }

        // Extract parent class FQCN if it exists
        $parent_class = $reflection->getParentClass();
        if ($parent_class !== false) {
            $data['extends_fqcn'] = $parent_class->getName();
        }

        // Extract class attributes (using simple names, not FQCNs)
        $class_attributes = [];
        foreach ($reflection->getAttributes() as $attribute) {
            $attr_full_name = $attribute->getName();
            // Extract just the simple class name (e.g., "Route" from "App\RSpade\Core\Attributes\Route")
            $attr_simple_name = substr(strrchr($attr_full_name, '\\'), 1) ?: $attr_full_name;
            $attr_args = $attribute->getArguments();

            if (!isset($class_attributes[$attr_simple_name])) {
                $class_attributes[$attr_simple_name] = [];
            }
            $class_attributes[$attr_simple_name][] = $attr_args;
        }

        if (!empty($class_attributes)) {
            $data['attributes'] = $class_attributes;
        }

        // Get list of trait files used by this class
        $trait_files = [];
        foreach ($reflection->getTraits() as $trait) {
            $trait_files[] = $trait->getFileName();
        }

        // Extract ONLY public static methods - the only methods we care about in RSX
        $public_static_methods = [];

        // Normalize file_path for comparison (without resolving symlinks)
        $normalized_file_path = rsxrealpath($file_path);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $method) {
            // Include methods from:
            // 1. This file (the class itself)
            // 2. Traits used by this class (checked via file path)
            $method_file = $method->getFileName();
            $is_from_trait = in_array($method_file, $trait_files);

            // Skip inherited methods from parent classes (but include trait methods)
            // Use rsxrealpath for comparison to avoid symlink resolution
            if (rsxrealpath($method_file) !== $normalized_file_path && !$is_from_trait) {
                continue;
            }

            $method_data = [
                'name' => $method->getName(),
                'static' => true,  // Always true since we filtered for static
                'visibility' => 'public',  // Always public since we filtered for public
                'line' => $method->getStartLine(),
            ];

            // For trait methods, store the file path (only if different from class file)
            // This enables IDE helpers to locate trait methods correctly
            if ($is_from_trait) {
                $method_data['file'] = $method_file;
            }

            // Extract method attributes (using simple names, not FQCNs)
            $method_attributes = [];
            foreach ($method->getAttributes() as $attribute) {
                $attr_full_name = $attribute->getName();
                // Extract just the simple class name (e.g., "Route" from "App\RSpade\Core\Attributes\Route")
                $attr_simple_name = substr(strrchr($attr_full_name, '\\'), 1) ?: $attr_full_name;
                $attr_args = $attribute->getArguments();

                if (!isset($method_attributes[$attr_simple_name])) {
                    $method_attributes[$attr_simple_name] = [];
                }
                $method_attributes[$attr_simple_name][] = $attr_args;
            }

            if (!empty($method_attributes)) {
                $method_data['attributes'] = $method_attributes;
            }

            // Extract parameters with types
            $parameters = [];
            foreach ($method->getParameters() as $param) {
                $param_data = [
                    'name' => $param->getName(),
                    'optional' => $param->isOptional(),
                ];

                // Get type if available
                $type = $param->getType();
                if ($type !== null) {
                    $param_data['type'] = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
                    $param_data['nullable'] = $type->allowsNull();
                }

                // Get default value if available
                if ($param->isDefaultValueAvailable()) {
                    $param_data['default'] = $param->getDefaultValue();
                }

                $parameters[] = $param_data;
            }

            if (!empty($parameters)) {
                $method_data['parameters'] = $parameters;
            }

            // Extract return type if available
            $return_type = $method->getReturnType();
            if ($return_type !== null) {
                if ($return_type instanceof \ReflectionUnionType) {
                    // Union type (e.g., "array|null")
                    $method_data['return_type'] = [
                        'type' => 'union',
                        'types' => array_map(fn($t) => $t->getName(), $return_type->getTypes()),
                        'nullable' => $return_type->allowsNull()
                    ];
                } else {
                    // Single type (e.g., "array", "string", "int")
                    $method_data['return_type'] = [
                        'type' => $return_type->getName(),
                        'nullable' => $return_type->allowsNull()
                    ];
                }
            }

            $public_static_methods[$method->getName()] = $method_data;
        }

        if (!empty($public_static_methods)) {
            $data['public_static_methods'] = $public_static_methods;
        }

        // Extract public instance methods for code-quality rules
        $public_instance_methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip inherited methods unless from same file
            if ($method->getFileName() !== $file_path) {
                continue;
            }

            // Skip static methods - we only want instance methods
            if ($method->isStatic()) {
                continue;
            }

            $method_data = [
                'name' => $method->getName(),
                'static' => false,  // Always false for instance methods
                'visibility' => 'public',  // Always public since we filtered for public
                'line' => $method->getStartLine(),
            ];

            // Extract method attributes (using simple names, not FQCNs)
            $method_attributes = [];
            foreach ($method->getAttributes() as $attribute) {
                $attr_full_name = $attribute->getName();
                // Extract just the simple class name (e.g., "Route" from "App\RSpade\Core\Attributes\Route")
                $attr_simple_name = substr(strrchr($attr_full_name, '\\'), 1) ?: $attr_full_name;
                $attr_args = $attribute->getArguments();

                if (!isset($method_attributes[$attr_simple_name])) {
                    $method_attributes[$attr_simple_name] = [];
                }
                $method_attributes[$attr_simple_name][] = $attr_args;
            }

            if (!empty($method_attributes)) {
                $method_data['attributes'] = $method_attributes;
            }

            // Extract parameters with types
            $parameters = [];
            foreach ($method->getParameters() as $param) {
                $param_data = [
                    'name' => $param->getName(),
                    'optional' => $param->isOptional(),
                ];

                // Get type if available
                $type = $param->getType();
                if ($type !== null) {
                    $param_data['type'] = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
                    $param_data['nullable'] = $type->allowsNull();
                }

                // Get default value if available
                if ($param->isDefaultValueAvailable()) {
                    $param_data['default'] = $param->getDefaultValue();
                }

                $parameters[] = $param_data;
            }

            if (!empty($parameters)) {
                $method_data['parameters'] = $parameters;
            }

            $public_instance_methods[$method->getName()] = $method_data;
        }

        // Always set public_instance_methods, even if empty, for consistency
        $data['public_instance_methods'] = $public_instance_methods;

        // Extract interfaces
        $interfaces = $reflection->getInterfaceNames();
        if (!empty($interfaces)) {
            $data['implements'] = $interfaces;
        }

        // Extract traits
        $traits = $reflection->getTraitNames();
        if (!empty($traits)) {
            $data['traits'] = $traits;
        }

        // Extract properties
        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $full_class_name) {
                $properties[] = [
                    'name' => $property->getName(),
                    'visibility' => $property->isPublic() ? 'public' : ($property->isProtected() ? 'protected' : 'private'),
                    'static' => $property->isStatic(),
                ];
            }
        }

        if (!empty($properties)) {
            $data['properties'] = $properties;
        }
    }

    /**

    /**
    * Extract reflection data only for changed files
    * Uses caching to avoid re-extracting unchanged files
    */
    public static function _extract_reflection_for_changed_files(array $changed_files): void
    {
        $cache_dir = storage_path('rsx-tmp/persistent/php_reflection');

        // Ensure cache directory exists
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }

        // Build a set of changed files for quick lookup
        $changed_files_set = array_flip($changed_files);

        // Process ALL PHP files to restore cached data or extract new reflection
        foreach (Manifest::$data['data']['files'] as $file => &$metadata) {
            // Skip non-PHP files
            if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
                continue;
            }

            // Skip files without classes
            if (!isset($metadata['fqcn'])) {
                continue;
            }

            $fqcn = $metadata['fqcn'];
            $cache_key = $metadata['hash'];
            $cache_file = $cache_dir . '/' . $cache_key . '.json';

            // Get absolute path - rsx/ files are in project root, not system/
            if (str_starts_with($file, 'rsx/')) {
                $absolute_path = rsxrealpath(base_path('../' . $file));
            } else {
                $absolute_path = rsxrealpath(base_path($file));
            }

            // Check if this file changed
            $file_changed = isset($changed_files_set[$file]);

            // Try to use cached data if file hasn't changed
            if (!$file_changed && file_exists($cache_file)) {
                $cache_mtime = filemtime($cache_file);
                $source_mtime = filemtime($absolute_path);

                // If cache is newer than source, use cached data
                if ($cache_mtime >= $source_mtime) {
                    $cached_data = json_decode(file_get_contents($cache_file), true);
                    if ($cached_data !== null) {
                        // Merge cached reflection data into manifest without breaking the reference
                        foreach ($cached_data as $key => $value) {
                            $metadata[$key] = $value;
                        }
                        continue;
                    }
                }
            }

            // Need fresh reflection data - ensure class and its hierarchy are loaded
            Manifest::_load_class_hierarchy($fqcn, Manifest::$data);

            // Extract reflection data (path already normalized with realpath above)
            Manifest::_extract_reflection_data($absolute_path, $fqcn, $metadata);

            // Cache the reflection data
            $reflection_data = [];
            // Add abstract property for correct subclass filtering
            if (isset($metadata['abstract'])) {
                $reflection_data['abstract'] = $metadata['abstract'];
            }
            if (isset($metadata['attributes'])) {
                $reflection_data['attributes'] = $metadata['attributes'];
            }
            if (isset($metadata['public_static_methods'])) {
                $reflection_data['public_static_methods'] = $metadata['public_static_methods'];
            }
            if (isset($metadata['public_instance_methods'])) {
                $reflection_data['public_instance_methods'] = $metadata['public_instance_methods'];
            }
            if (isset($metadata['properties'])) {
                $reflection_data['properties'] = $metadata['properties'];
            }
            if (isset($metadata['implements'])) {
                $reflection_data['implements'] = $metadata['implements'];
            }
            if (isset($metadata['traits'])) {
                $reflection_data['traits'] = $metadata['traits'];
            }

            if (!empty($reflection_data)) {
                file_put_contents_safe($cache_file, json_encode($reflection_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
    }

    /**
    * Extract view information from template files
    */
    public static function _extract_view_info(string $file_path, array &$data): void
    {
        $content = file_get_contents($file_path);

        // Look for @id comment or directive
        if (preg_match('/(?:<!--\s*@id\s+(\w+)\s*-->|@id\([\'"](\w+)[\'"]\))/', $content, $matches)) {
            $data['id'] = $matches[1] ?: $matches[2];
        }

        // Extract blade sections
        if (str_ends_with($file_path, '.blade.php')) {
            $sections = [];
            if (preg_match_all('/@section\([\'"](\w+)[\'"]\)/', $content, $matches)) {
                $sections = $matches[1];
            }
            if (!empty($sections)) {
                $data['sections'] = $sections;
            }

            // Extract extends
            if (preg_match('/@extends\([\'"]([^\'"\)]+)[\'"]\)/', $content, $matches)) {
                $data['extends'] = $matches[1];
            }
        }
    }

    /**
    * Load changed PHP files and their dependencies
    *
    * This method loads only the PHP files that have changed and ensures
    * their parent class hierarchies are loaded first.
    *
    * @param array $changed_files Array of changed file paths
    * @return void
    */
    public static function _load_changed_php_files(array $changed_files): void
    {
        // Filter to only PHP files
        $trait_files = [];
        $class_files = [];

        foreach ($changed_files as $file) {
            if (isset(Manifest::$data['data']['files'][$file]['extension']) &&
            Manifest::$data['data']['files'][$file]['extension'] === 'php' &&
            isset(Manifest::$data['data']['files'][$file]['fqcn'])) {
                // Separate traits from classes - traits must be loaded first
                if (isset(Manifest::$data['data']['files'][$file]['is_trait']) &&
                    Manifest::$data['data']['files'][$file]['is_trait']) {
                    $trait_files[] = $file;
                } else {
                    $class_files[] = $file;
                }
            }
        }

        // Load traits first (they have no dependencies and are used by classes)
        foreach ($trait_files as $file) {
            $fqcn = Manifest::$data['data']['files'][$file]['fqcn'];
            Manifest::_load_class_hierarchy($fqcn, Manifest::$data);
        }

        // Then load classes with their hierarchies
        foreach ($class_files as $file) {
            $fqcn = Manifest::$data['data']['files'][$file]['fqcn'];
            Manifest::_load_class_hierarchy($fqcn, Manifest::$data);
        }
    }

    public static function _load_php_files_in_dependency_order(): void
    {
        if (!isset(Manifest::$data['data']['files'])) {
            throw new \RuntimeException(
                'Fatal: Manifest::load_php_files_in_dependency_order() called but manifest data structure is not initialized. ' .
"This shouldn't happen - Phase 2 should have populated the files array."
            );
        }

        $files = Manifest::$data['data']['files'];
        $loaded = [];
        $to_load = [];

        // Build list of files with class information
        foreach ($files as $file_path => $metadata) {
            // Check for PHP files by extension
            if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
                continue;
            }

            if (!isset($metadata['class'])) {
                // No class defined, include it immediately
                $full_path = base_path($file_path);
                if (file_exists($full_path)) {
                    include_once $full_path;
                }
                continue;
            }

            $to_load[$file_path] = [
                'class' => $metadata['class'] ?? null,
                'extends' => $metadata['extends'] ?? null,
                'namespace' => $metadata['namespace'] ?? null,
                'fqcn' => $metadata['fqcn'] ?? null,
            ];
        }

        // Load files in dependency order
        $max_iterations = count($to_load) + 10; // Prevent infinite loop
        $iteration = 0;

        while (!empty($to_load) && $iteration < $max_iterations) {
            $iteration++;
            $loaded_this_round = false;

            foreach ($to_load as $file_path => $info) {
                // Check if dependencies are met
                $can_load = true;

                if (!empty($info['extends'])) {
                    // Check if parent class is already loaded
                    $parent_loaded = false;

                    // Check if it's a Laravel/PHP built-in class
                    if (class_exists($info['extends'], false) || interface_exists($info['extends'], false)) {
                        $parent_loaded = true;
                    } else {
                        // Check if parent is in our loaded list
                        foreach ($loaded as $loaded_info) {
                            if ($loaded_info['class'] === $info['extends']) {
                                $parent_loaded = true;
                                break;
                            }
                        }
                    }

                    if (!$parent_loaded) {
                        $can_load = false;
                    }
                }

                if ($can_load) {
                    // Load the file
                    $full_path = base_path($file_path);
                    if (file_exists($full_path)) {
                        include_once $full_path;
                    }

                    // Mark as loaded
                    $loaded[$file_path] = $info;
                    unset($to_load[$file_path]);
                    $loaded_this_round = true;
                }
            }

            // If nothing was loaded this round, we might have circular dependencies
            // Load remaining files anyway
            if (!$loaded_this_round && !empty($to_load)) {
                foreach ($to_load as $file_path => $info) {
                    $full_path = base_path($file_path);
                    if (file_exists($full_path)) {
                        include_once $full_path;
                    }
                }
                break;
            }
        }
    }

    // /**
    //  * Extract reflection data for all PHP files
    //  * Must be called after Phase 3 (dependency loading) completes
    //  */
    // public static function __extract_all_reflection_data(): void
    // {
    //     if (!isset(Manifest::$data['data']['files'])) {
    //         throw new \RuntimeException(
    //             'Fatal: Manifest::extract_all_reflection_data() called but manifest data structure is not initialized. ' .
    //             "This shouldn't happen - Phase 2 should have populated the files array."
    //         );
    //     }

    //     foreach (Manifest::$data['data']['files'] as $file_path => &$metadata) {
    //         // Only process PHP files with classes
    //         if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
    //             continue;
    //         }

    //         if (!isset($metadata['fqcn'])) {
    //             continue;
    //         }
    //         var_dump($file_path);
    //         // Extract reflection data (class should already be loaded)
    //         Manifest::_extract_reflection_data(base_path($file_path), $metadata['fqcn'], $metadata);
    //     }
    // }

    /**
    * Load all PHP files in dependency order
    * This ensures base classes are loaded before their subclasses
    * Must be called after Phase 2 (basic metadata extraction) completes
    * @deprecated Use _load_changed_php_files() for incremental builds
    */
    /**
    * Run Php_Fixer on all PHP files in rsx/ and app/RSpade/
    * Called before Phase 2 parsing to ensure all files are fixed
    *
    * SMART REBUILD STRATEGY:
    * This method implements an intelligent rebuild strategy to avoid unnecessary file writes:
    *
    * 1. STRUCTURE HASH: Creates SHA1 hash of "ClassName:ParentClass" for ALL classes
    *    - Detects when classes are added, removed, renamed, or inheritance changes
    *
    * 2. FULL REBUILD TRIGGERS:
    *    - New class added (may need new use statements elsewhere)
    *    - Class renamed (all references need updating)
    *    - Inheritance changed (may affect use statement resolution)
    *    → When triggered: Fix ALL PHP files in rsx/ and app/RSpade/
    *
    * 3. INCREMENTAL REBUILD:
    *    - Structure hash unchanged (no new/renamed classes)
    *    - Only fixes files that actually changed on disk
    *    → More efficient, avoids touching unchanged files
    *
    * WHY THIS MATTERS:
    * - use statement management depends on knowing all available classes
    * - FQCN replacement needs to check class name uniqueness
    * - When class structure changes, files referencing those classes need updating
    * - When structure stable, only changed files need processing
    *
    * @param array $changed_files List of changed files from Phase 1
    * @return array List of files that were modified by Php_Fixer
    */
    public static function _run_php_fixer(array $changed_files): array
    {
        $modified_files = [];

        // ==================================================================================
        // STEP 1: BUILD CLASS STRUCTURE HASH
        // ==================================================================================
        // Create a fingerprint of ALL classes in the codebase.
        // Format: "path/to/file.php" => "ClassName:ParentClass"
        // This lets us detect when the class structure itself changes (not just file contents)
        // ==================================================================================

        $class_structure_hash_data = [];

        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            // Only process PHP files with classes
            if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
                continue;
            }

            if (!isset($metadata['class'])) {
                continue;
            }

            // Build hash entry: filename => ClassName:ParentClass
            $class_name = $metadata['class'];
            $extends = $metadata['extends'] ?? '';
            $class_structure_hash_data[$file_path] = $class_name . ':' . $extends;
        }

        // Calculate hash of class structure
        $new_class_structure_hash = sha1(json_encode($class_structure_hash_data));

        // ==================================================================================
        // STEP 2: DECIDE REBUILD STRATEGY
        // ==================================================================================
        // Compare with previous hash to detect structural changes
        // ==================================================================================

        $previous_hash = Manifest::$data['data']['php_fixer_hash'] ?? null;
        $structure_changed = ($previous_hash !== $new_class_structure_hash);

        if ($structure_changed) {
            // ==================================================================================
            // FULL REBUILD: Class structure changed
            // ==================================================================================
            // When class structure changes, we MUST fix ALL files because:
            // - New classes may be referenced in existing files → need new use statements
            // - Renamed classes need all references updated
            // - Inheritance changes may affect use statement resolution
            // ==================================================================================
            $php_files_to_fix = [];

            foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
                // Only process PHP files
                if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
                    continue;
                }

                // Only process files in rsx/ or app/RSpade/
                if (!str_starts_with($file_path, 'rsx/') && !str_starts_with($file_path, 'app/RSpade/')) {
                    continue;
                }

                $php_files_to_fix[] = $file_path;
            }

            // Run Php_Fixer on all files and collect modified ones
            foreach ($php_files_to_fix as $file_path) {
                if (\App\RSpade\Core\PHP\Php_Fixer::fix($file_path, Manifest::$data)) {
                    $modified_files[] = $file_path;
                }
            }

            // Store updated hash for next rebuild comparison
            Manifest::$data['data']['php_fixer_hash'] = $new_class_structure_hash;
        } else {
            // ==================================================================================
            // INCREMENTAL REBUILD: Class structure unchanged
            // ==================================================================================
            // Only fix files that actually changed on disk.
            // Safe because:
            // - No new classes = no new use statements needed elsewhere
            // - No renamed classes = no references to update
            // - No inheritance changes = use statement resolution unchanged
            // Result: Much faster, avoids touching 99% of files on typical edits
            // ==================================================================================
            $php_files_to_fix = [];

            foreach ($changed_files as $file_path) {
                // Check if this is a PHP file with a class in the manifest
                if (!isset(Manifest::$data['data']['files'][$file_path])) {
                    continue;
                }

                $metadata = Manifest::$data['data']['files'][$file_path];

                // Only process PHP files with classes
                if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
                    continue;
                }

                if (!isset($metadata['class'])) {
                    continue;
                }

                $php_files_to_fix[] = $file_path;
            }

            // Run Php_Fixer on changed files only and collect modified ones
            foreach ($php_files_to_fix as $file_path) {
                if (\App\RSpade\Core\PHP\Php_Fixer::fix($file_path, Manifest::$data)) {
                    $modified_files[] = $file_path;
                }
            }
        }

        return $modified_files;
    }

    /**
    * Restore orphaned .upstream files when their override no longer exists
    *
    * Scans all php.upstream files in the manifest. For each one, checks if a .php file
    * with the same class name exists. If not, the override was removed and we should
    * restore the framework file.
    */
    public static function _restore_orphaned_upstream_files(): void
    {
        // Collect all PHP class names currently in the manifest
        $active_php_classes = [];
        foreach (Manifest::$data['data']['files'] as $file => $metadata) {
            if (isset($metadata['extension']) && $metadata['extension'] === 'php' &&
                isset($metadata['class']) && !empty($metadata['class'])) {
                $active_php_classes[$metadata['class']] = $file;
            }
        }

        // Check each .upstream file
        foreach (Manifest::$data['data']['files'] as $file => $metadata) {
            if (!isset($metadata['extension']) || $metadata['extension'] !== 'php.upstream') {
                continue;
            }

            if (!isset($metadata['class']) || empty($metadata['class'])) {
                continue;
            }

            $class_name = $metadata['class'];

            // Check if an active .php file with this class exists
            if (isset($active_php_classes[$class_name])) {
                // Override still exists - keep .upstream as-is. Attest the
                // applied rename (no-op when already attested): recording is
                // otherwise event-driven, so a marker ledger that post-dates
                // the rename (a pre-ledger install, a lost store) would stay
                // empty forever and trip the update tamper gate. This is the
                // one place the steady state is visited every build.
                $upstream_path = base_path($file);
                $renamed_from = preg_replace('/\.upstream$/', '', $upstream_path);
                if (file_exists($upstream_path) && !file_exists($renamed_from)) {
                    \App\RSpade\Core\Framework\Framework_Mutations::attest_rename($renamed_from, $upstream_path, 'class_override_rename');
                }
                continue;
            }

            // No active override - restore this framework file
            $upstream_path = base_path($file);
            $restored_path = preg_replace('/\.upstream$/', '', $upstream_path);

            if (file_exists($upstream_path) && !file_exists($restored_path)) {
                rename($upstream_path, $restored_path);
                console_debug('MANIFEST', "Class restore: {$class_name} - restored {$file} to .php");
                // Marker: .php.upstream gone, .php restored to its archived content.
                \App\RSpade\Core\Framework\Framework_Mutations::record_restore($upstream_path, $restored_path);

                // Remove the .upstream entry from manifest
                unset(Manifest::$data['data']['files'][$file]);

                Manifest::$_needs_manifest_restart = true;
            }
        }
    }

}
