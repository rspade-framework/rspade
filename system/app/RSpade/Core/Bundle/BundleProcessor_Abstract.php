<?php

namespace App\RSpade\Core\Bundle;

/**
 * BundleProcessor_Abstract - Base class for file transformation processors
 *
 * Processors handle compilation and transformation of specific file types
 * during bundle building. Examples include:
 * - JQHTML templates → JavaScript
 * - SCSS/SASS → CSS
 * - TypeScript → JavaScript
 * - ES6+ → ES5 (transpilation)
 *
 * SEPARATION OF CONCERNS:
 * - Processors focus solely on file transformation
 * - Processors modify bundle file arrays by reference
 * - Bundles orchestrate the overall compilation
 *
 * STATIC DESIGN:
 * All methods are static - processors are stateless transformation engines
 *
 * BY-REFERENCE PROCESSING:
 * The process_batch() method receives the bundle files array by reference
 * and modifies it directly by appending compiled output files
 * 
 * EXAMPLE:
 * class Jqhtml_BundleProcessor extends BundleProcessor_Abstract {
 *     public static function process_batch(array &$bundle_files): void {
 *         foreach ($bundle_files as $path) {
 *             if (pathinfo($path, PATHINFO_EXTENSION) === 'jqhtml') {
 *                 $compiled = static::compile_template($path);
 *                 $temp_file = static::_write_temp_file($compiled['js_code'], 'js');
 *                 $bundle_files[] = $temp_file;
 *             }
 *         }
 *     }
 * }
 */
abstract class BundleProcessor_Abstract
{
    /**
     * Get the processor's unique identifier
     * 
     * @return string Processor name (e.g., 'jqhtml', 'less', 'typescript')
     */
    abstract public static function get_name(): string;
    
    /**
     * Get file extensions this processor handles
     * 
     * @return array Extensions without dots (e.g., ['jqhtml', 'jqtpl'])
     */
    abstract public static function get_extensions(): array;
    
    /**
     * Process multiple files in batch
     *
     * Examines all files in the bundle and compiles those matching this processor's extensions.
     * Compiled output files are appended to the bundle_files array.
     * Uses file modification time caching - skips compilation if temp file is newer than source.
     *
     * @param array &$bundle_files Array of file paths (modified by reference)
     * @return void
     */
    abstract public static function process_batch(array &$bundle_files): void;
    
    /**
     * Pre-processing hook (default: no-op)
     * 
     * Called before any files are processed. Can be used to initialize
     * resources, validate configuration, or prepare the processing environment.
     * 
     * @param array $all_files All files that will be processed
     * @param array $options Processing options
     */
    #[Replaceable]
    public static function before_processing(array $all_files, array $options = []): void
    {
        // Override in subclasses if needed
    }
    
    /**
     * Post-processing hook (default: no additional files)
     * 
     * Called after all files are processed. Can be used to generate
     * additional output, clean up resources, or perform final transformations.
     * 
     * @param array $processed_files Processed file results
     * @param array $options Processing options
     * @return array Additional files to include in bundle
     */
    #[Replaceable]
    public static function after_processing(array $processed_files, array $options = []): array
    {
        return [];
    }
    
    /**
     * Get processor configuration
     * 
     * @return array Processor configuration from config/rsx.php
     */
    public static function get_config(): array
    {
        $name = static::get_name();
        return config("rsx.processors.{$name}", []);
    }
    
    /**
     * Validate processor configuration (default: no validation)
     * 
     * @throws \RuntimeException If configuration is invalid
     */
    #[Replaceable]
    public static function validate(): void
    {
        // Override in subclasses to add validation
    }
    
    /**
     * Get processor priority (default: 500)
     * 
     * Processors with lower priority run first.
     * This allows dependencies between processors.
     * 
     * @return int Priority (0-1000, default 500)
     */
    #[Replaceable]
    public static function get_priority(): int
    {
        $config = static::get_config();
        return $config['priority'] ?? 500;
    }
    
    /**
     * Get metadata for manifest integration (default: basic metadata)
     * 
     * Return metadata that should be added to the manifest
     * for files processed by this processor.
     * 
     * @param string $file_path File being processed
     * @param array $result Processing result
     * @return array Metadata for manifest
     */
    public static function get_manifest_metadata(string $file_path, array $result): array
    {
        return [
            'processor' => static::get_name(),
            'processed_at' => time(),
            'type' => $result['type'] ?? 'unknown'
        ];
    }
    
    /**
     * Helper: Read file contents safely
     */
    protected static function _read_file(string $path): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        return file_get_contents($path);
    }
    
    /**
     * Helper: Write to temp file
     */
    protected static function _write_temp_file(string $content, string $extension = 'tmp'): string
    {
        $temp_dir = storage_path('rsx-tmp');
        if (!is_dir($temp_dir)) {
            mkdir($temp_dir, 0755, true);
        }
        
        $temp_file = $temp_dir . '/' . uniqid('processor_') . '.' . $extension;
        file_put_contents_safe($temp_file, $content);
        return $temp_file;
    }
    
    /**
     * Helper: Get cache key for processed file
     */
    protected static function _get_cache_key(string $file_path): string
    {
        $content = file_get_contents($file_path);
        $mtime = filemtime($file_path);
        return md5($file_path . ':' . $mtime . ':' . $content);
    }
}