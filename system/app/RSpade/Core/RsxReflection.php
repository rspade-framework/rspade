<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core;

use Exception;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use App\RSpade\Core\Build_Manager;
use App\RSpade\Core\Manifest\Manifest;

/**
 * RsxReflection provides comprehensive reflection capabilities for the RSX framework
 * 
 * This class provides methods for:
 * - Class discovery and inheritance checking
 * - Method and attribute extraction
 * - Route generation and resolution
 * - Caching of reflection data
 */
class RsxReflection
{
    /**
     * Memory cache for reflection data (per-request)
     */
    private static array $reflection_cache = [];
    
    /**
     * Cache prefix for BuildManager
     */
    private const CACHE_PREFIX = 'reflection/';
    
    /**
     * Get all classes in RSX that extend a base class
     * 
     * @param string $base_class The base class name
     * @return array Array of class names
     */
    public static function get_classes_extending(string $base_class): array
    {
        $cache_key = "classes_extending_{$base_class}";
        
        // Check memory cache
        if (isset(self::$reflection_cache[$cache_key])) {
            return self::$reflection_cache[$cache_key];
        }
        
        // Check file cache
        $cached = Build_Manager::get(self::CACHE_PREFIX . $cache_key);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                self::$reflection_cache[$cache_key] = $decoded;
                return $decoded;
            }
        }
        
        // Get from manifest
        $classes = Manifest::get_classes_extending($base_class);
        
        // Cache results
        self::$reflection_cache[$cache_key] = $classes;
        Build_Manager::put(self::CACHE_PREFIX . $cache_key, json_encode($classes));
        
        return $classes;
    }
    
    /**
     * Get all classes in RSX that implement an interface
     * 
     * @param string $interface The interface name
     * @return array Array of class names
     */
    public static function get_classes_implementing(string $interface): array
    {
        $cache_key = "classes_implementing_{$interface}";
        
        // Check memory cache
        if (isset(self::$reflection_cache[$cache_key])) {
            return self::$reflection_cache[$cache_key];
        }
        
        // Check file cache
        $cached = Build_Manager::get(self::CACHE_PREFIX . $cache_key);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                self::$reflection_cache[$cache_key] = $decoded;
                return $decoded;
            }
        }
        
        $classes = [];
        
        // Get all PHP classes from manifest
        $manifest_data = Manifest::get_manifest();
        
        if (isset($manifest_data['php'])) {
            foreach ($manifest_data['php'] as $class_name => $info) {
                // Need to check if file exists and load it
                if (file_exists($info['file'])) {
                    require_once $info['file'];
                    
                    // Trust that the class exists if Manifest says so
                    $reflection = new ReflectionClass($class_name);
                    if ($reflection->implementsInterface($interface)) {
                        $classes[] = $class_name;
                    }
                }
            }
        }
        
        // Cache results
        self::$reflection_cache[$cache_key] = $classes;
        Build_Manager::put(self::CACHE_PREFIX . $cache_key, json_encode($classes));
        
        return $classes;
    }
    
    /**
     * Get all public static methods of a class
     * 
     * @param string $class_name The class name
     * @param bool $include_inherited Include inherited methods
     * @return array Array of method names
     */
    public static function get_static_methods(string $class_name, bool $include_inherited = false): array
    {
        $cache_key = "methods_{$class_name}_{$include_inherited}";
        
        // Check memory cache
        if (isset(self::$reflection_cache[$cache_key])) {
            return self::$reflection_cache[$cache_key];
        }
        
        // Check file cache
        $cached = Build_Manager::get(self::CACHE_PREFIX . $cache_key);
        if ($cached !== null && !self::__is_class_modified($class_name)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                self::$reflection_cache[$cache_key] = $decoded;
                return $decoded;
            }
        }
        
        $methods = [];
        
        try {
            // Get class info from Manifest - trust it exists
            try {
                $class_info = Manifest::php_get_metadata_by_class($class_name);
                if (isset($class_info['file']) && file_exists(base_path($class_info['file']))) {
                    require_once base_path($class_info['file']);
                }
                $actual_class = $class_info['fqcn'] ?? $class_name;
            } catch (\RuntimeException $e) {
                // Try as FQCN
                $class_info = Manifest::php_get_metadata_by_fqcn($class_name);
                if (isset($class_info['file']) && file_exists(base_path($class_info['file']))) {
                    require_once base_path($class_info['file']);
                }
                $actual_class = $class_name;
            }
            
            // Trust that the class exists if Manifest has it
            $reflection = new ReflectionClass($actual_class);
            
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
                if (!$include_inherited && $method->getDeclaringClass()->getName() !== $actual_class) {
                    continue;
                }
                $methods[] = $method->getName();
            }
        } catch (Exception $e) {
            // Class doesn't exist or other error
        }
        
        // Cache results
        self::$reflection_cache[$cache_key] = $methods;
        Build_Manager::put(self::CACHE_PREFIX . $cache_key, json_encode($methods));
        
        return $methods;
    }
    
    /**
     * Get all attributes of a specific type on a method
     * 
     * @param string $class_name The class name
     * @param string $method_name The method name
     * @param string|null $attribute_class Optional specific attribute class
     * @return array Array of attribute instances
     */
    public static function get_method_attributes(string $class_name, string $method_name, ?string $attribute_class = null): array
    {
        $cache_key = "method_attrs_{$class_name}_{$method_name}_" . ($attribute_class ?? 'all');
        
        // Check memory cache
        if (isset(self::$reflection_cache[$cache_key])) {
            return self::$reflection_cache[$cache_key];
        }
        
        $attributes = [];
        
        try {
            // Get class info from Manifest - trust it exists
            try {
                $class_info = Manifest::php_get_metadata_by_class($class_name);
                if (isset($class_info['file']) && file_exists(base_path($class_info['file']))) {
                    require_once base_path($class_info['file']);
                }
                $actual_class = $class_info['fqcn'] ?? $class_name;
            } catch (\RuntimeException $e) {
                // Try as FQCN
                $class_info = Manifest::php_get_metadata_by_fqcn($class_name);
                if (isset($class_info['file']) && file_exists(base_path($class_info['file']))) {
                    require_once base_path($class_info['file']);
                }
                $actual_class = $class_name;
            }
            
            // Trust that the class exists if Manifest has it
            $reflection = new ReflectionMethod($actual_class, $method_name);
                
            if ($attribute_class) {
                $attrs = $reflection->getAttributes($attribute_class, ReflectionAttribute::IS_INSTANCEOF);
            } else {
                $attrs = $reflection->getAttributes();
            }
            
            foreach ($attrs as $attr) {
                $attributes[] = $attr->newInstance();
            }
        } catch (Exception $e) {
            // Method doesn't exist or other error
        }
        
        // Cache results
        self::$reflection_cache[$cache_key] = $attributes;
        
        return $attributes;
    }
    
    /**
     * Get all attributes of a specific type on a class
     * 
     * @param string $class_name The class name
     * @param string|null $attribute_class Optional specific attribute class
     * @return array Array of attribute instances
     */
    public static function get_class_attributes(string $class_name, ?string $attribute_class = null): array
    {
        $cache_key = "class_attrs_{$class_name}_" . ($attribute_class ?? 'all');
        
        // Check memory cache
        if (isset(self::$reflection_cache[$cache_key])) {
            return self::$reflection_cache[$cache_key];
        }
        
        $attributes = [];
        
        try {
            // Get class info from Manifest - trust it exists
            try {
                $class_info = Manifest::php_get_metadata_by_class($class_name);
                if (isset($class_info['file']) && file_exists(base_path($class_info['file']))) {
                    require_once base_path($class_info['file']);
                }
                $actual_class = $class_info['fqcn'] ?? $class_name;
            } catch (\RuntimeException $e) {
                // Try as FQCN
                $class_info = Manifest::php_get_metadata_by_fqcn($class_name);
                if (isset($class_info['file']) && file_exists(base_path($class_info['file']))) {
                    require_once base_path($class_info['file']);
                }
                $actual_class = $class_name;
            }
            
            // Trust that the class exists if Manifest has it
            $reflection = new ReflectionClass($actual_class);
                
            if ($attribute_class) {
                $attrs = $reflection->getAttributes($attribute_class, ReflectionAttribute::IS_INSTANCEOF);
            } else {
                $attrs = $reflection->getAttributes();
            }
            
            foreach ($attrs as $attr) {
                $attributes[] = $attr->newInstance();
            }
        } catch (Exception $e) {
            // Class doesn't exist or other error
        }
        
        // Cache results
        self::$reflection_cache[$cache_key] = $attributes;
        
        return $attributes;
    }
    
    /**
     * Check if a class has a specific method
     * 
     * @param string $class_name The class name
     * @param string $method_name The method name
     * @return bool
     */
    public static function has_method(string $class_name, string $method_name): bool
    {
        try {
            // Get class info from Manifest - trust it exists
            try {
                $class_info = Manifest::php_get_metadata_by_class($class_name);
                if (isset($class_info['file']) && file_exists(base_path($class_info['file']))) {
                    require_once base_path($class_info['file']);
                }
                $actual_class = $class_info['fqcn'] ?? $class_name;
            } catch (\RuntimeException $e) {
                // Try as FQCN
                $class_info = Manifest::php_get_metadata_by_fqcn($class_name);
                if (isset($class_info['file']) && file_exists(base_path($class_info['file']))) {
                    require_once base_path($class_info['file']);
                }
                $actual_class = $class_name;
            }
            
            // Trust that the class exists if Manifest has it
            return method_exists($actual_class, $method_name);
        } catch (Exception $e) {
            // Class doesn't exist
        }
        
        return false;
    }
    
    /**
     * Get the file path for a class
     * 
     * @param string $class_name The class name
     * @return string|null File path or null if not found
     */
    public static function get_class_file(string $class_name): ?string
    {
        // Check manifest - the ONLY source of truth for class files
        $class_info = Manifest::get_class($class_name);
        
        if ($class_info && isset($class_info['file'])) {
            return $class_info['file'];
        }
        
        // Class not in manifest - this is an error condition
        throw new \RuntimeException("Class {$class_name} not found in manifest. Ensure manifest is up to date.");
    }
    
    /**
     * Get all classes with a specific attribute
     * 
     * @param string $attribute_class The attribute class name
     * @return array Array of class names
     */
    public static function get_classes_with_attribute(string $attribute_class): array
    {
        $cache_key = "classes_with_attr_{$attribute_class}";
        
        // Check memory cache
        if (isset(self::$reflection_cache[$cache_key])) {
            return self::$reflection_cache[$cache_key];
        }
        
        // Check file cache
        $cached = Build_Manager::get(self::CACHE_PREFIX . $cache_key);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                self::$reflection_cache[$cache_key] = $decoded;
                return $decoded;
            }
        }
        
        $classes = [];
        
        // Get all PHP classes from manifest
        $manifest_data = Manifest::get_manifest();
        
        if (isset($manifest_data['php'])) {
            foreach ($manifest_data['php'] as $class_name => $info) {
                // Build full class name with namespace
                $full_class_name = $class_name;
                if (isset($info['namespace']) && !empty($info['namespace'])) {
                    $full_class_name = $info['namespace'] . '\\' . $class_name;
                }
                
                $attrs = self::get_class_attributes($full_class_name, $attribute_class);
                if (!empty($attrs)) {
                    $classes[] = $full_class_name;
                }
            }
        }
        
        // Cache results
        self::$reflection_cache[$cache_key] = $classes;
        Build_Manager::put(self::CACHE_PREFIX . $cache_key, json_encode($classes));
        
        return $classes;
    }
    
    /**
     * Get all methods in all classes with a specific attribute
     * 
     * @param string $attribute_class The attribute class name
     * @return array Array of ['class' => string, 'method' => string]
     */
    public static function get_methods_with_attribute(string $attribute_class): array
    {
        $cache_key = "methods_with_attr_{$attribute_class}";
        
        // Check memory cache
        if (isset(self::$reflection_cache[$cache_key])) {
            return self::$reflection_cache[$cache_key];
        }
        
        // Check file cache
        $cached = Build_Manager::get(self::CACHE_PREFIX . $cache_key);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                self::$reflection_cache[$cache_key] = $decoded;
                return $decoded;
            }
        }
        
        $methods = [];
        
        // Get all PHP classes from manifest
        $manifest_data = Manifest::get_manifest();
        
        if (isset($manifest_data['php'])) {
            foreach ($manifest_data['php'] as $class_name => $info) {
                // Build full class name with namespace
                $full_class_name = $class_name;
                if (isset($info['namespace']) && !empty($info['namespace'])) {
                    $full_class_name = $info['namespace'] . '\\' . $class_name;
                }
                
                $class_methods = self::get_static_methods($full_class_name, false);
                
                foreach ($class_methods as $method_name) {
                    $attrs = self::get_method_attributes($full_class_name, $method_name, $attribute_class);
                    if (!empty($attrs)) {
                        $methods[] = [
                            'class' => $full_class_name,
                            'method' => $method_name
                        ];
                    }
                }
            }
        }
        
        // Cache results
        self::$reflection_cache[$cache_key] = $methods;
        Build_Manager::put(self::CACHE_PREFIX . $cache_key, json_encode($methods));
        
        return $methods;
    }
    
    /**
     * Generate a URL for a route
     * 
     * @param string $target Format: "ClassName.method_name" or "ClassName::method_name"
     * @param array $params Parameters for route placeholders
     * @return string|null Generated URL or null if route not found
     */
    public static function route(string $target, array $params = []): ?string
    {
        // Parse target format
        $delimiter = strpos($target, '::') !== false ? '::' : '.';
        $parts = explode($delimiter, $target);
        
        if (count($parts) !== 2) {
            return null;
        }
        
        [$class_name, $method_name] = $parts;
        
        // Handle namespaced classes
        if (strpos($class_name, '\\') === false) {
            // Try to find the class in manifest
            $class_info = Manifest::get_class($class_name);
            
            if ($class_info && isset($class_info['namespace']) && !empty($class_info['namespace'])) {
                $class_name = $class_info['namespace'] . '\\' . $class_name;
            }
        }
        
        // Get route pattern from method
        $route_pattern = self::get_route_for_method($class_name, $method_name);
        
        if (!$route_pattern) {
            return null;
        }
        
        // Replace parameters in pattern
        $url = $route_pattern;
        
        foreach ($params as $key => $value) {
            $url = str_replace(':' . $key, $value, $url);
        }
        
        // Check for missing required parameters
        if (preg_match('/:(\w+)/', $url)) {
            // Still has unreplaced parameters
            return null;
        }
        
        return $url;
    }
    
    /**
     * Get the route pattern for a controller method
     * 
     * @param string $class_name The class name
     * @param string $method_name The method name
     * @return string|null Route pattern or null if not found
     */
    public static function get_route_for_method(string $class_name, string $method_name): ?string
    {
        // Get Route attributes from method
        $attributes = self::get_method_attributes($class_name, $method_name, Route::class);
        
        if (!empty($attributes)) {
            // Return the first route pattern found
            return $attributes[0]->pattern;
        }
        
        return null;
    }
    
    /**
     * Get metadata about a class (cached)
     * 
     * @param string $class_name The class name
     * @return array Class metadata from manifest
     */
    public static function get_class_info(string $class_name): array
    {
        $class_info = Manifest::get_class($class_name);
        
        if (!$class_info) {
            return [];
        }
        
        // Enhance with additional reflection data
        $info = $class_info;
        
        // Add public_static_methods if not present
        if (!isset($info['public_static_methods'])) {
            $info['public_static_methods'] = self::get_static_methods($class_name, false);
        }
        
        // Add parent class info from manifest data
        try {
            // Get parent from manifest data (already has 'extends' field)
            $metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_class($class_name);
            if (isset($metadata['extends'])) {
                $info['parent'] = $metadata['extends'];
            }

            // Still need ReflectionClass for interfaces and traits (not in manifest)
            $reflection = new ReflectionClass($class_name);
            $info['interfaces'] = $reflection->getInterfaceNames();
            $info['traits'] = $reflection->getTraitNames();
        } catch (Exception $e) {
            // Error getting class info
        }
        
        return $info;
    }
    
    /**
     * Invalidate reflection cache for a class
     * 
     * @param string $class_name The class name
     */
    public static function invalidate_cache(string $class_name): void
    {
        // Clear memory cache for this class
        foreach (self::$reflection_cache as $key => $value) {
            if (strpos($key, $class_name) !== false) {
                unset(self::$reflection_cache[$key]);
            }
        }
        
        // Clear file cache for this class using BuildManager
        // BuildManager handles the storage path internally
    }
    
    /**
     * Check if a class file has been modified since cache
     * 
     * @param string $class_name The class name
     * @return bool True if modified
     */
    private static function __is_class_modified(string $class_name): bool
    {
        $manifest_data = Manifest::get_manifest();
        
        // In development, always check for changes
        if (config('app.env') === 'local') {
            if (isset($manifest_data['php'][$class_name])) {
                $cached_mtime = $manifest_data['php'][$class_name]['mtime'] ?? 0;
                $file = $manifest_data['php'][$class_name]['file'] ?? null;
                
                if ($file && file_exists($file)) {
                    $current_mtime = filemtime($file);
                    return $current_mtime > $cached_mtime;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Clear all reflection caches
     */
    public static function clear_all_cache(): void
    {
        // Clear memory cache
        self::$reflection_cache = [];
        
        // Clear file cache using BuildManager
        // BuildManager handles the storage path internally
    }
}