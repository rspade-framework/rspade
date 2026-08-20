<?php

namespace App\RSpade\CodeQuality\Support;

#[Instantiatable]
class CacheManager
{
    private string $cache_dir;
    private array $memory_cache = [];
    
    public function __construct(string $cache_dir = null)
    {
        if ($cache_dir === null) {
            // Try to use Laravel's storage_path if available
            if (function_exists('storage_path')) {
                $this->cache_dir = storage_path('rsx-tmp/cache/code-quality');
            } else {
                // Fall back to a default location
                $this->cache_dir = '/var/www/html/storage/rsx-tmp/cache/code-quality';
            }
        } else {
            $this->cache_dir = $cache_dir;
        }
        
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
    }
    
    public function get_sanitized_file(string $file_path): ?array
    {
        // Check memory cache first
        if (isset($this->memory_cache[$file_path])) {
            return $this->memory_cache[$file_path];
        }
        
        $cache_key = $this->get_cache_key($file_path);
        $cache_file = $this->cache_dir . '/' . $cache_key . '.json';
        
        if (!file_exists($cache_file)) {
            return null;
        }
        
        $file_mtime = filemtime($file_path);
        $cache_mtime = filemtime($cache_file);
        
        // Cache is stale
        if ($file_mtime > $cache_mtime) {
            unlink($cache_file);
            return null;
        }
        
        $data = json_decode(file_get_contents($cache_file), true);
        $this->memory_cache[$file_path] = $data;
        
        return $data;
    }
    
    public function set_sanitized_file(string $file_path, array $data): void
    {
        $this->memory_cache[$file_path] = $data;
        
        $cache_key = $this->get_cache_key($file_path);
        $cache_file = $this->cache_dir . '/' . $cache_key . '.json';
        
        file_put_contents_safe($cache_file, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    public function clear(): void
    {
        $this->memory_cache = [];
        
        $files = glob($this->cache_dir . '/*.json');
        foreach ($files as $file) {
            unlink($file);
        }
    }
    
    public function clear_file(string $file_path): void
    {
        unset($this->memory_cache[$file_path]);
        
        $cache_key = $this->get_cache_key($file_path);
        $cache_file = $this->cache_dir . '/' . $cache_key . '.json';
        
        if (file_exists($cache_file)) {
            unlink($cache_file);
        }
    }
    
    private function get_cache_key(string $file_path): string
    {
        // Create a unique cache key based on file path
        // Use hash to avoid filesystem issues with long paths
        return md5($file_path) . '_' . basename($file_path, '.php');
    }
}