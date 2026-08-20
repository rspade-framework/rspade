<?php

namespace App\RSpade\Core\Kernels;

use InvalidArgumentException;
use App\RSpade\Core\Manifest\ManifestModule_Abstract;

/**
 * Kernel that manages manifest processing modules
 */
#[Instantiatable]
class ManifestKernel
{
    /**
     * Registered modules
     */
    protected array $modules = [];

    /**
     * Sorted modules cache
     */
    protected ?array $sorted_modules = null;

    /**
     * Register a module
     */
    public function register(string $module_class): void
    {
        if (!is_subclass_of($module_class, ManifestModule_Abstract::class)) {
            throw new InvalidArgumentException(
                "Module {$module_class} must extend " . ManifestModule_Abstract::class
            );
        }

        $this->modules[] = $module_class;
        $this->sorted_modules = null; // Clear cache
    }

    /**
     * Process a file through all applicable modules
     */
    public function process(string $file_path, array $metadata): array
    {
        $extension = $this->get_file_extension($file_path);

        foreach ($this->get_sorted_modules() as $module) {
            if (in_array($extension, $module->handles())) {
                $metadata = $module->process($file_path, $metadata);
            }
        }

        return $metadata;
    }

    /**
     * Get modules sorted by priority
     */
    public function get_sorted_modules(): array
    {
        if ($this->sorted_modules === null) {
            $instances = array_map(
                fn ($class) => app($class),
                $this->modules
            );

            usort($instances, fn ($a, $b) => $a->priority() <=> $b->priority());

            $this->sorted_modules = $instances;
        }

        return $this->sorted_modules;
    }

    /**
     * Get file extension, handling double extensions like blade.php
     */
    protected function get_file_extension(string $file_path): string
    {
        $filename = basename($file_path);

        // Check for double extensions
        if (preg_match('/\.blade\.php$/', $filename)) {
            return 'blade.php';
        }

        return pathinfo($filename, PATHINFO_EXTENSION);
    }

    /**
     * Clear all registered modules
     */
    public function clear(): void
    {
        $this->modules = [];
        $this->sorted_modules = null;
    }

    /**
     * Get count of registered modules
     */
    public function count(): int
    {
        return count($this->modules);
    }
}
