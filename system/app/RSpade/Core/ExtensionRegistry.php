<?php

namespace App\RSpade\Core;

/**
 * ExtensionRegistry - Central registry for file extensions and their handlers
 * 
 * This class provides a plugin point for integrations to register new file types
 * that should be processed by the Manifest and Bundle systems. It follows the
 * static design pattern as per framework philosophy.
 * 
 * USAGE:
 * ExtensionRegistry::register_extension('jqhtml', 'JqhtmlHandler', 100);
 * ExtensionRegistry::register_handler('jqhtml', function($path, &$data) { ... });
 * 
 * The registry maintains:
 * - File extensions that should be discovered
 * - Handler callbacks for processing each extension
 * - Priority ordering for handler execution
 */
class ExtensionRegistry
{
    /**
     * Registered extensions and their metadata
     * Format: ['extension' => ['handler_class' => string, 'priority' => int]]
     */
    protected static array $extensions = [];
    
    /**
     * Processing callbacks for each extension
     * Format: ['extension' => callable]
     */
    protected static array $handlers = [];
    
    /**
     * Core extensions that are always processed
     * These are built into the framework and cannot be unregistered
     */
    protected const CORE_EXTENSIONS = [
        'php' => ['priority' => 100],
        'php.upstream' => ['priority' => 101],  // Framework files renamed during class override
        'js' => ['priority' => 200],
        'jsx' => ['priority' => 200],
        'ts' => ['priority' => 200],
        'tsx' => ['priority' => 200],
        'blade.php' => ['priority' => 300],
        'phtml' => ['priority' => 300],
        'scss' => ['priority' => 400],
        'less' => ['priority' => 400],
        'css' => ['priority' => 500],
    ];
    
    /**
     * Register a file extension for processing
     * 
     * @param string $extension File extension without dot (e.g. 'jqhtml')
     * @param string|null $handler_class Optional handler class name
     * @param int $priority Processing priority (lower = earlier)
     */
    public static function register_extension(string $extension, ?string $handler_class = null, int $priority = 1000): void
    {
        // Don't allow overriding core extensions
        if (isset(static::CORE_EXTENSIONS[$extension])) {
            throw new \RuntimeException("Cannot override core extension: {$extension}");
        }
        
        static::$extensions[$extension] = [
            'handler_class' => $handler_class,
            'priority' => $priority
        ];
    }
    
    /**
     * Register a processing handler for an extension
     * 
     * The handler receives the file path and metadata array by reference
     * and should modify the metadata array with extracted information.
     * 
     * @param string $extension File extension
     * @param callable $handler Processing callback
     */
    public static function register_handler(string $extension, callable $handler): void
    {
        static::$handlers[$extension] = $handler;
    }
    
    /**
     * Get all registered extensions (core + custom)
     * 
     * @return array List of extensions
     */
    public static function get_all_extensions(): array
    {
        $all = array_keys(static::CORE_EXTENSIONS);
        $custom = array_keys(static::$extensions);
        
        return array_unique(array_merge($all, $custom));
    }
    
    /**
     * Check if an extension is registered
     * 
     * @param string $extension Extension to check
     * @return bool
     */
    public static function is_registered(string $extension): bool
    {
        return isset(static::CORE_EXTENSIONS[$extension]) || 
               isset(static::$extensions[$extension]);
    }
    
    /**
     * Get handler for an extension
     * 
     * @param string $extension Extension
     * @return callable|null Handler callback or null
     */
    public static function get_handler(string $extension): ?callable
    {
        return static::$handlers[$extension] ?? null;
    }
    
    /**
     * Process a file through its registered handler
     * 
     * @param string $extension File extension
     * @param string $file_path Path to file
     * @param array $metadata Metadata array (modified by reference)
     * @return bool True if processed, false if no handler
     */
    public static function process_file(string $extension, string $file_path, array &$metadata): bool
    {
        $handler = static::get_handler($extension);
        
        if ($handler === null) {
            return false;
        }
        
        $handler($file_path, $metadata);
        return true;
    }
    
    /**
     * Get extensions sorted by priority
     * 
     * @return array Extensions in priority order
     */
    public static function get_sorted_extensions(): array
    {
        // Combine core and custom extensions
        $all = static::CORE_EXTENSIONS;
        foreach (static::$extensions as $ext => $data) {
            $all[$ext] = ['priority' => $data['priority']];
        }
        
        // Sort by priority
        uasort($all, fn($a, $b) => $a['priority'] <=> $b['priority']);
        
        return array_keys($all);
    }
    
    /**
     * Clear all custom registrations (for testing)
     */
    public static function clear_custom(): void
    {
        static::$extensions = [];
        static::$handlers = [];
    }
    
    /**
     * Unregister a custom extension
     * 
     * @param string $extension Extension to remove
     * @return bool True if removed, false if not found or core
     */
    public static function unregister(string $extension): bool
    {
        if (isset(static::CORE_EXTENSIONS[$extension])) {
            return false; // Cannot unregister core
        }
        
        if (isset(static::$extensions[$extension])) {
            unset(static::$extensions[$extension]);
            unset(static::$handlers[$extension]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get metadata for an extension
     * 
     * @param string $extension Extension
     * @return array|null Metadata or null if not found
     */
    public static function get_extension_metadata(string $extension): ?array
    {
        if (isset(static::CORE_EXTENSIONS[$extension])) {
            return static::CORE_EXTENSIONS[$extension];
        }
        
        return static::$extensions[$extension] ?? null;
    }
}