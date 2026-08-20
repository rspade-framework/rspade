<?php

namespace App\RSpade\Core\Manifest;



/**
 * Abstract base class for modules that process files during manifest scanning
 *
 * Manifest modules are responsible for extracting metadata from specific file types
 * during the manifest building process. Each module handles certain file extensions
 * and can extract relevant information like classes, methods, attributes, etc.
 */
#[Instantiatable]
abstract class ManifestModule_Abstract
{
    /**
     * Get file extensions this module handles
     *
     * Return an array of file extensions (without dots) that this module
     * should process. For example: ['php', 'blade.php']
     *
     * @return array File extensions this module processes
     */
    abstract public function handles(): array;

    /**
     * Get processing priority (lower = earlier)
     *
     * Modules with lower priority numbers are processed first.
     * This allows certain modules to take precedence over others.
     * For example, Blade_ManifestModule might have higher priority than Php_ManifestModule
     * to handle .blade.php files before the generic PHP handler.
     *
     * @return int Priority value (typically 0-100)
     */
    abstract public function priority(): int;

    /**
     * Process a file and extract metadata
     *
     * This method receives a file path and existing metadata, processes
     * the file to extract additional information, and returns the updated
     * metadata array.
     *
     * @param string $file_path Full path to the file to process
     * @param array $metadata Existing metadata (size, mtime, etc)
     * @return array Updated metadata with extracted information
     */
    abstract public function process(string $file_path, array $metadata): array;

    /**
     * Helper method to check if a file should be processed
     *
     * @param string $file_path Path to check
     * @return bool True if this module should process the file
     */
    protected function should_process(string $file_path): bool
    {
        $extensions = $this->handles();
        foreach ($extensions as $ext) {
            if (str_ends_with($file_path, '.' . $ext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Helper method to get file contents safely
     *
     * @param string $file_path Path to file
     * @return string|null File contents or null if not readable
     */
    protected function get_file_contents(string $file_path): ?string
    {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return null;
        }

        return file_get_contents($file_path);
    }
}
