<?php

namespace App\RSpade\Core\Manifest\Modules;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module for extracting database metadata for Rsx_Model_Abstract classes
 * This runs after the primary manifest is built to add model metadata
 */
class Model_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * Process the manifest and add model database metadata
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function process(array &$manifest_data): void
    {
        // Initialize models key if it doesn't exist
        if (!isset($manifest_data['data']['models'])) {
            $manifest_data['data']['models'] = [];
        }

        // All PHP files should already be loaded in Phase 3 of manifest processing
        // Get all classes extending Rsx_Model_Abstract
        $model_entries = Manifest::php_get_extending('Rsx_Model_Abstract');

        foreach ($model_entries as $model_entry) {
            if (!isset($model_entry['fqcn'])) {
                continue;
            }

            $fqcn = $model_entry['fqcn'];
            $class_name = $model_entry['class'] ?? '';

            $cachekey = 'Model_ManifestSupport_' . $model_entry['file'] . '__' . $model_entry['hash'];
            $cache = RsxCache::get_persistent($cachekey);

            if (!empty($cache)) {
                $manifest_data['data']['models'][$class_name] = $cache;
            }

            include_once($model_entry['file']);

            // Check if class is abstract
            if (\App\RSpade\Core\Manifest\Manifest::php_is_abstract($fqcn)) {
                // Skip abstract classes
                continue;
            }

            // Instantiate the model to get table name
            $model_instance = new $fqcn();
            $table_name = $model_instance->getTable();

            // Check if table exists
            if (!Schema::hasTable($table_name)) {
                // This is acceptable, maybe migrations didnt run or migrations are broken.  Just skip adding
                // the database metadata to the manifest.
                continue;
            }

            // Get column information
            $columns = [];

            // Use SHOW COLUMNS to get column information
            $column_results = DB::select("SHOW COLUMNS FROM `{$table_name}`");

            foreach ($column_results as $column) {
                $columns[$column->Field] = [
                    'type' => static::__parse_column_type($column->Type),
                    'max_length' => static::__parse_varchar_length($column->Type),
                    'nullable' => ($column->Null === 'YES'),
                    'key' => $column->Key,
                    'default' => $column->Default,
                    'extra' => $column->Extra,
                    'source_table' => $table_name,
                ];
            }

            // Class-Table Inheritance: merge detail-table columns into the base model's column
            // map so field_length(), JS stubs, and model codegen span base + detail as one
            // logical model. Columns are re-introspected live each build, so a detail-table
            // schema change is picked up here without touching the base model file.
            static::__merge_detail_columns($fqcn, $class_name, $table_name, $columns);

            // Look up the file's metadata to get public_static_methods extracted during reflection
            $file_path = $model_entry['file'] ?? '';
            $public_static_methods = [];

            // Find the public_static_methods data from the files array
            if ($file_path && isset($manifest_data['data']['files'][$file_path])) {
                $file_metadata = $manifest_data['data']['files'][$file_path];
                $public_static_methods = $file_metadata['public_static_methods'] ?? [];
            }

            // Store model metadata with database info AND preserved public_static_methods
            $full_data = [
                'fqcn' => $fqcn,
                'file' => $file_path,
                'table' => $table_name,
                'columns' => $columns,
                'public_static_methods' => $public_static_methods,  // Include the public static methods
                'class' => $class_name,  // Ajax_Endpoint_Controller expects this
            ];

            $manifest_data['data']['models'][$class_name] = $full_data;

            RsxCache::set_persistent($cachekey, $full_data);
        }
    }

    /**
     * Merge a base model's detail-table columns into its column map (by reference), tagging
     * each with its source table. Shared structural/audit columns (id, the parent FK, the
     * created/updated/deleted columns) are skipped. A genuine same-name collision between a
     * base column and a detail column is a smell - it emits a build warning and the base
     * column wins.
     *
     * @param string $fqcn       The base model FQCN (already loaded)
     * @param string $class_name The base model simple name (for messages)
     * @param string $base_table The base table name
     * @param array  $columns    The base column map, merged in place
     */
    private static function __merge_detail_columns(string $fqcn, string $class_name, string $base_table, array &$columns): void
    {
        if (!$fqcn::has_detail_tables()) {
            return;
        }

        foreach (\App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::detail_classes($fqcn::$detail_tables) as $detail_class) {
            if (!class_exists($detail_class)) {
                continue;
            }

            $detail_table = (new $detail_class())->getTable();
            if (!Schema::hasTable($detail_table)) {
                continue;
            }

            $structural = [
                'id', $detail_class::parent_key(),
                'created_at', 'updated_at', 'deleted_at',
                'created_by_id', 'created_by_type', 'updated_by_id', 'updated_by_type',
                'deleted_by_id', 'deleted_by_type',
            ];

            foreach (DB::select("SHOW COLUMNS FROM `{$detail_table}`") as $column) {
                if (in_array($column->Field, $structural, true)) {
                    continue;
                }

                if (isset($columns[$column->Field])) {
                    error_log(
                        "[detail_tables] WARNING: column '{$column->Field}' exists on both base table " .
                        "'{$base_table}' and detail table '{$detail_table}' for model {$class_name} - " .
                        'same-name base/detail columns are usually unintended.'
                    );
                    continue;
                }

                $columns[$column->Field] = [
                    'type' => static::__parse_column_type($column->Type),
                    'max_length' => static::__parse_varchar_length($column->Type),
                    'nullable' => ($column->Null === 'YES'),
                    'key' => $column->Key,
                    'default' => $column->Default,
                    'extra' => $column->Extra,
                    'source_table' => $detail_table,
                ];
            }
        }
    }

    /**
     * Parse MySQL column type to a simpler format
     *
     * CRITICAL: TINYINT(1) is treated as boolean (common MySQL boolean convention)
     * All other TINYINT sizes are treated as integers
     *
     * @param string $type MySQL column type (e.g., "varchar(255)", "tinyint(1)")
     * @return string Simplified type
     */
    protected static function __parse_column_type(string $type): string
    {
        // Special case: tinyint(1) is boolean
        if (preg_match('/^tinyint\(1\)/i', $type)) {
            return 'boolean';
        }

        // Extract base type without parameters
        if (preg_match('/^([a-z]+)/', $type, $matches)) {
            $base_type = $matches[1];

            // Map MySQL types to simplified types
            $type_map = [
                'int' => 'integer',
                'bigint' => 'integer',
                'tinyint' => 'integer',  // tinyint(1) handled above, others are integers
                'smallint' => 'integer',
                'mediumint' => 'integer',
                'varchar' => 'string',
                'char' => 'string',
                'text' => 'text',
                'mediumtext' => 'text',
                'longtext' => 'text',
                'datetime' => 'datetime',
                'timestamp' => 'datetime',
                'date' => 'date',
                'time' => 'time',
                'decimal' => 'decimal',
                'float' => 'float',
                'double' => 'double',
                'boolean' => 'boolean',
                'json' => 'json',
                'enum' => 'enum',
            ];

            return $type_map[$base_type] ?? $base_type;
        }

        return $type;
    }

    /**
     * Extract varchar length from MySQL column type
     *
     * Returns the max length for varchar/char fields, null for all other types.
     *
     * @param string $type MySQL column type (e.g., "varchar(255)", "char(10)", "text")
     * @return int|null Max length for varchar/char, null otherwise
     */
    protected static function __parse_varchar_length(string $type): ?int
    {
        // Match varchar(N) or char(N)
        if (preg_match('/^(?:varchar|char)\((\d+)\)/i', $type, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Model Database Metadata';
    }
}
