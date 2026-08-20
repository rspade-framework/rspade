<?php

namespace App\RSpade\Core\Database;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Database helper for accessing model and table metadata from the manifest
 */
class ModelHelper
{
    /**
     * Get all model class names in the system
     * 
     * @return array Array of model class names (simple names, not FQCNs)
     */
    public static function get_all_model_names(): array
    {
        $manifest = Manifest::get_full_manifest();
        
        if (!isset($manifest['data']['models'])) {
            return [];
        }
        
        return array_keys($manifest['data']['models']);
    }
    
    /**
     * Get all database table names used by models
     * 
     * @return array Array of table names
     */
    public static function get_all_table_names(): array
    {
        $manifest = Manifest::get_full_manifest();
        
        if (!isset($manifest['data']['models'])) {
            return [];
        }
        
        $tables = [];
        foreach ($manifest['data']['models'] as $model_data) {
            if (isset($model_data['table'])) {
                $tables[] = $model_data['table'];
            }
        }
        
        return array_unique($tables);
    }
    
    /**
     * Get full column data for a model by class name
     * 
     * @param string $model_name Simple class name (e.g., 'User', not 'App\Models\User')
     * @return array Column metadata array
     * @throws \RuntimeException if model not found
     */
    public static function get_columns_by_model(string $model_name): array
    {
        $manifest = Manifest::get_full_manifest();
        
        if (!isset($manifest['data']['models'][$model_name])) {
            throw new \RuntimeException("Model not found in manifest: {$model_name}");
        }
        
        return $manifest['data']['models'][$model_name]['columns'] ?? [];
    }
    
    /**
     * Get full column data for a database table
     * 
     * @param string $table_name Database table name
     * @return array Column metadata array
     * @throws \RuntimeException if table not found
     */
    public static function get_columns_by_table(string $table_name): array
    {
        $manifest = Manifest::get_full_manifest();
        
        if (!isset($manifest['data']['models'])) {
            throw new \RuntimeException("No models found in manifest");
        }
        
        foreach ($manifest['data']['models'] as $model_data) {
            if (isset($model_data['table']) && $model_data['table'] === $table_name) {
                return $model_data['columns'] ?? [];
            }
        }
        
        throw new \RuntimeException("Table not found in manifest: {$table_name}");
    }
    
    /**
     * Get model metadata by class name
     * 
     * @param string $model_name Simple class name
     * @return array Full model metadata including table, columns, fqcn, etc.
     * @throws \RuntimeException if model not found
     */
    public static function get_model_metadata(string $model_name): array
    {
        $manifest = Manifest::get_full_manifest();
        
        if (!isset($manifest['data']['models'][$model_name])) {
            throw new \RuntimeException("Model not found in manifest: {$model_name}");
        }
        
        return $manifest['data']['models'][$model_name];
    }
    
    /**
     * Get model name by table name
     * 
     * @param string $table_name Database table name
     * @return string Model class name
     * @throws \RuntimeException if table not found
     */
    public static function get_model_by_table(string $table_name): string
    {
        $manifest = Manifest::get_full_manifest();
        
        if (!isset($manifest['data']['models'])) {
            throw new \RuntimeException("No models found in manifest");
        }
        
        foreach ($manifest['data']['models'] as $model_name => $model_data) {
            if (isset($model_data['table']) && $model_data['table'] === $table_name) {
                return $model_name;
            }
        }
        
        throw new \RuntimeException("No model found for table: {$table_name}");
    }
    
    /**
     * Get table name by model name
     * 
     * @param string $model_name Simple class name
     * @return string Database table name
     * @throws \RuntimeException if model not found
     */
    public static function get_table_by_model(string $model_name): string
    {
        $manifest = Manifest::get_full_manifest();
        
        if (!isset($manifest['data']['models'][$model_name])) {
            throw new \RuntimeException("Model not found in manifest: {$model_name}");
        }
        
        if (!isset($manifest['data']['models'][$model_name]['table'])) {
            throw new \RuntimeException("Model has no table defined: {$model_name}");
        }
        
        return $manifest['data']['models'][$model_name]['table'];
    }
    
    /**
     * Check if a model exists in the manifest
     * 
     * @param string $model_name Simple class name
     * @return bool
     */
    public static function model_exists(string $model_name): bool
    {
        $manifest = Manifest::get_full_manifest();
        return isset($manifest['data']['models'][$model_name]);
    }
    
    /**
     * Check if a table is managed by a model
     * 
     * @param string $table_name Database table name
     * @return bool
     */
    public static function table_exists(string $table_name): bool
    {
        $manifest = Manifest::get_full_manifest();
        
        if (!isset($manifest['data']['models'])) {
            return false;
        }
        
        foreach ($manifest['data']['models'] as $model_data) {
            if (isset($model_data['table']) && $model_data['table'] === $table_name) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get column type for a specific column in a model
     * 
     * @param string $model_name Simple class name
     * @param string $column_name Column name
     * @return string Column type
     * @throws \RuntimeException if model or column not found
     */
    public static function get_column_type(string $model_name, string $column_name): string
    {
        $columns = static::get_columns_by_model($model_name);
        
        if (!isset($columns[$column_name])) {
            throw new \RuntimeException("Column '{$column_name}' not found in model '{$model_name}'");
        }
        
        return $columns[$column_name]['type'] ?? 'unknown';
    }
    
    /**
     * Check if a column is nullable
     * 
     * @param string $model_name Simple class name
     * @param string $column_name Column name
     * @return bool
     * @throws \RuntimeException if model or column not found
     */
    public static function is_column_nullable(string $model_name, string $column_name): bool
    {
        $columns = static::get_columns_by_model($model_name);
        
        if (!isset($columns[$column_name])) {
            throw new \RuntimeException("Column '{$column_name}' not found in model '{$model_name}'");
        }
        
        return $columns[$column_name]['nullable'] ?? false;
    }
}