<?php

namespace App\RSpade\Core\Database;

use App\RSpade\Core\Bundle\BundleIntegration_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Database integration for RSX framework
 *
 * Handles generation of JavaScript stub files for ORM models,
 * enabling Ajax ORM functionality and relationship methods.
 */
class Database_BundleIntegration extends BundleIntegration_Abstract
{
    /**
     * Get the integration's unique identifier
     *
     * @return string Integration identifier
     */
    public static function get_name(): string
    {
        return 'database';
    }

    /**
     * Get file extensions handled by this integration
     *
     * Models are PHP files, but we don't need to register
     * extensions as the PHP files are already handled by the core.
     *
     * @return array Empty array as no special extensions needed
     */
    public static function get_file_extensions(): array
    {
        return [];
    }

    /**
     * Generate JavaScript stub files for ORM models
     *
     * These stubs enable IDE autocomplete and provide relationship methods
     * for models that extend Rsx_Model_Abstract.
     *
     * TODO: This function needs cleanup
     *
     * @param array &$manifest_data The complete manifest data (passed by reference)
     * @return void
     */
    public static function generate_manifest_stubs(array &$manifest_data): void
    {
        // Debug: Track when and why this is called
        // static $call_count = 0;
        // $call_count++;
        // console_debug("STUB_GEN", "Database stub generation call #{$call_count}");

        // // Show backtrace to understand the call flow
        // $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        // foreach ($backtrace as $idx => $frame) {
        //     if ($idx === 0) continue; // Skip this method itself
        //     $file = isset($frame['file']) ? basename($frame['file']) : 'unknown';
        //     $line = $frame['line'] ?? '?';
        //     $function = $frame['function'] ?? 'unknown';
        //     $class = isset($frame['class']) ? basename(str_replace('\\', '/', $frame['class'])) : '';
        //     console_debug("STUB_GEN", "  [{$idx}] {$class}::{$function}() at {$file}:{$line}");
        // }

        $stub_dir = storage_path('rsx-build/js-model-stubs');

        // Create directory if it doesn't exist
        if (!is_dir($stub_dir)) {
            mkdir($stub_dir, 0755, true);
        }

        // Track generated stub files for cleanup
        $generated_stubs = [];

        // Get all models from the manifest
        $model_entries = Manifest::php_get_extending('Rsx_Model_Abstract');
        console_debug('STUB_GEN', 'Found ' . count($model_entries) . ' models extending Rsx_Model_Abstract');

        foreach ($model_entries as $model_entry) {
            if (!isset($model_entry['fqcn'])) {
                continue;
            }

            $fqcn = $model_entry['fqcn'];
            $class_name = $model_entry['class'] ?? '';

            // Skip if it extends Rsx_System_Model_Abstract
            if (static::_php_is_subclass_of($fqcn, 'Rsx_System_Model_Abstract', $manifest_data)) {
                console_debug('STUB_GEN', "  Skipping {$class_name}: extends Rsx_System_Model_Abstract");
                continue;
            }

            // Load the class and its hierarchy
            Manifest::_load_class_hierarchy($fqcn, $manifest_data);

            // Verify class loaded
            if (!class_exists($fqcn)) {
                shouldnt_happen("Failed to load model class {$fqcn} after _load_class_hierarchy");
            }

            // Note: Abstract classes already filtered by php_get_extending()

            console_debug('STUB_GEN', "  Processing {$class_name} for stub generation...");

            // Get model metadata from manifest
            $file_path = $model_entry['file'] ?? '';
            $metadata = isset($manifest_data['data']['files'][$file_path]) ? $manifest_data['data']['files'][$file_path] : [];

            // Generate stub filename and paths
            // Always use Base_ prefix - concrete classes are handled at bundle compilation time
            $stub_class_name = 'Base_' . $class_name;
            $stub_filename = static::_sanitize_model_stub_filename($stub_class_name) . '.js';

            $stub_relative_path = 'storage/rsx-build/js-model-stubs/' . $stub_filename;
            $stub_full_path = rsx_project_file_path($stub_relative_path);

            // Check if stub needs regeneration
            $needs_regeneration = true;
            if (file_exists($stub_full_path)) {
                // Get mtime of source PHP file
                $source_mtime = $metadata['mtime'] ?? 0;
                $stub_mtime = filemtime($stub_full_path);

                // Only regenerate if source is newer than stub
                if ($stub_mtime >= $source_mtime) {
                    // Also check if the model metadata has changed
                    // by comparing a hash of enums, relationships, columns, and constants
                    $model_metadata = static::_get_model_metadata_for_hash($fqcn, $class_name, $manifest_data);

                    $model_metadata_hash = md5(json_encode($model_metadata));
                    $old_metadata_hash = $metadata['model_metadata_hash'] ?? '';

                    if ($model_metadata_hash === $old_metadata_hash) {
                        $needs_regeneration = false;
                    }

                    // Store the hash for future comparisons
                    $manifest_data['data']['files'][$file_path]['model_metadata_hash'] = $model_metadata_hash;
                }
            }

            if ($needs_regeneration) {
                // Generate stub content
                $stub_content = static::_generate_model_stub_content($fqcn, $class_name, $stub_class_name, $manifest_data);

                // Write stub file
                file_put_contents_safe($stub_full_path, $stub_content);

                // Store the metadata hash for future comparisons if not already done
                if (!isset($manifest_data['data']['files'][$file_path]['model_metadata_hash'])) {
                    $model_metadata = static::_get_model_metadata_for_hash($fqcn, $class_name, $manifest_data);
                    $manifest_data['data']['files'][$file_path]['model_metadata_hash'] = md5(json_encode($model_metadata));
                }
            }

            $generated_stubs[] = $stub_filename;

            // Add js_stub property to manifest data
            $metadata['js_stub'] = $stub_relative_path;

            // Write the updated metadata back to the manifest
            $manifest_data['data']['files'][$file_path]['js_stub'] = $stub_relative_path;

            // Debug: Verify the value was written
            // console_debug('STUB_GEN', "    Written js_stub for {$file_path}: {$stub_relative_path}");
            // if (!isset($manifest_data['data']['files'][$file_path]['js_stub'])) {
            //     console_debug('STUB_GEN', '    ERROR: js_stub not set after writing!');
            // }

            // Add the stub file itself to the manifest
            $stat = stat($stub_full_path);
            $manifest_data['data']['files'][$stub_relative_path] = [
                'file' => $stub_relative_path,
                'hash' => sha1_file($stub_full_path),
                'mtime' => $stat['mtime'],
                'size' => $stat['size'],
                'extension' => 'js',
                'class' => $stub_class_name,
                'is_model_stub' => true,  // Mark this as a generated model stub
                'source_model' => $file_path,  // Reference to the source model
            ];
        }

        // Clean up orphaned stub files
        $existing_stubs = glob($stub_dir . '/*.js');
        foreach ($existing_stubs as $existing_stub) {
            $filename = basename($existing_stub);
            if (!in_array($filename, $generated_stubs)) {
                // Remove from disk (check exists to avoid Windows errors)
                if (file_exists($existing_stub)) {
                    unlink($existing_stub);
                }

                // Remove from manifest
                $stub_relative_path = 'storage/rsx-build/js-model-stubs/' . $filename;
                if (isset($manifest_data['data']['files'][$stub_relative_path])) {
                    unset($manifest_data['data']['files'][$stub_relative_path]);
                }
            }
        }
    }

    /**
     * Check if a class is a subclass of another class using manifest data
     *
     * IMPORTANT: This is NOT redundant with Manifest::php_is_subclass_of().
     * This method operates on the raw manifest data array during the manifest
     * build process, before the Manifest class has fully initialized its static
     * data structures. It's called during Phase 5 (stub generation) when the
     * manifest is being built, not when it's being queried.
     *
     * @param string $class_name The FQCN of the class to check
     * @param string $parent_class The simple name of the parent class
     * @param array $manifest_data The raw manifest data array being built
     * @return bool True if class_name extends parent_class
     */
    private static function _php_is_subclass_of(string $class_name, string $parent_class, array $manifest_data): bool
    {
        foreach ($manifest_data['data']['files'] as $file_path => $metadata) {
            if (isset($metadata['fqcn']) && $metadata['fqcn'] === $class_name) {
                $extends = $metadata['extends'] ?? '';
                if ($extends === $parent_class) {
                    return true;
                }
                // Recursively check parent
                if ($extends) {
                    return static::_php_is_subclass_of($extends, $parent_class, $manifest_data);
                }
            }
        }

        return false;
    }

    /**
     * Get model metadata for hash comparison (detects when stubs need regeneration)
     *
     * @param string $fqcn Fully qualified class name
     * @param string $class_name Simple class name
     * @param array $manifest_data The manifest data array
     * @return array Metadata array for hashing
     */
    private static function _get_model_metadata_for_hash(string $fqcn, string $class_name, array $manifest_data): array
    {
        $model_metadata = [];

        // Get relationships
        $model_metadata['rel'] = $fqcn::get_relationships();

        // Get enums
        if (property_exists($fqcn, 'enums')) {
            $model_metadata['enums'] = $fqcn::$enums ?? [];
        }

        // Realtime emission flag — flipping $realtime must regenerate the stub so the
        // baked-in `static __REALTIME` line appears/disappears (see _generate_model_stub_content).
        $model_metadata['realtime'] = property_exists($fqcn, 'realtime') && $fqcn::$realtime === true;

        // Get columns from models metadata if available
        if (isset($manifest_data['data']['models'][$class_name]['columns'])) {
            $model_metadata['columns'] = $manifest_data['data']['models'][$class_name]['columns'];
        }

        // Get public constants defined directly on this class
        $reflection = new \ReflectionClass($fqcn);
        $constants = [];
        foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $const) {
            if ($const->getDeclaringClass()->getName() === $fqcn) {
                $constants[$const->getName()] = $const->getValue();
            }
        }
        if (!empty($constants)) {
            $model_metadata['constants'] = $constants;
        }

        return $model_metadata;
    }

    /**
     * Sanitize model name for use as filename
     */
    private static function _sanitize_model_stub_filename(string $model_name): string
    {
        // Replace underscores with hyphens and lowercase
        // e.g., User_Model becomes user-model
        return strtolower(str_replace('_', '-', $model_name));
    }

    /**
     * Generate JavaScript stub content for a model
     */
    private static function _generate_model_stub_content(string $fqcn, string $class_name, string $stub_class_name, array $manifest_data): string
    {
        // Ensure class is loaded before introspection
        // (should already be loaded but double-check)
        if (!class_exists($fqcn)) {
            shouldnt_happen("Class {$fqcn} not loaded for stub generation");
        }

        // Get model instance to introspect
        $model = new $fqcn();

        // Get relationships that are Ajax-fetchable
        // Only include relationships with BOTH #[Relationship] AND #[Ajax_Endpoint_Model_Fetch]
        $all_relationships = $fqcn::get_relationships();
        $model_metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_fqcn($fqcn);
        $fetchable_relationships = [];

        foreach ($all_relationships as $rel_name) {
            $method_data = $model_metadata['public_instance_methods'][$rel_name] ?? [];
            if (isset($method_data['attributes']['Ajax_Endpoint_Model_Fetch'])) {
                $fetchable_relationships[] = $rel_name;
            }
        }
        $relationships = $fetchable_relationships;

        // Get enums
        $enums = $fqcn::$enums ?? [];

        // Get columns from models metadata if available
        $columns = [];
        if (isset($manifest_data['data']['models'][$class_name]['columns'])) {
            $columns = $manifest_data['data']['models'][$class_name]['columns'];
        }

        // Determine the base class to extend
        // User can configure a custom base class that sits between stubs and Rsx_Js_Model
        $js_model_base_class = config('rsx.js_model_base_class');
        $extends_class = $js_model_base_class ?: 'Rsx_Js_Model';

        // Collect enum constant names to avoid duplicating them
        $enum_constant_names = [];
        foreach ($enums as $column => $enum_values) {
            foreach ($enum_values as $value => $props) {
                if (!empty($props['constant'])) {
                    $enum_constant_names[] = $props['constant'];
                }
            }
        }

        // Get all public constants defined directly on this model class (not inherited)
        $reflection = new \ReflectionClass($fqcn);
        $non_enum_constants = [];
        foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $const) {
            // Only include constants defined directly on this class
            if ($const->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }
            $const_name = $const->getName();
            // Skip constants already generated from enums
            if (in_array($const_name, $enum_constant_names)) {
                continue;
            }
            $non_enum_constants[$const_name] = $const->getValue();
        }

        // Start building the stub content
        $content = "/**\n";
        $content .= " * Auto-generated JavaScript stub for {$class_name}\n";
        $content .= " * DO NOT EDIT - This file is automatically regenerated\n";
        $content .= " * @Instantiatable\n";
        $content .= " */\n";

        $content .= "class {$stub_class_name} extends {$extends_class} {\n";

        // Add static __MODEL property for PHP model name resolution
        $content .= "    static __MODEL = '{$class_name}';\n";

        // Realtime emission opt-in (mirrors PHP `public static $realtime`). Baked ONLY
        // when true so the JS surface (this.subscribe(Model_Class, id, cb) / watch_changes())
        // can fail loud when a model has not opted into Model_Changed_Topic emission. Absent
        // line == not realtime, keeping non-realtime stubs clean.
        if (property_exists($fqcn, 'realtime') && $fqcn::$realtime === true) {
            $content .= "    static __REALTIME = true;\n";
        }

        $content .= "\n";

        // Generate non-enum constants first (static properties)
        if (!empty($non_enum_constants)) {
            $content .= "    // Non-enum constants\n";
            foreach ($non_enum_constants as $const_name => $const_value) {
                $value_json = json_encode($const_value);
                $content .= "    static {$const_name} = {$value_json};\n";
            }
            $content .= "\n";
        }

        // Generate enum constants and methods
        foreach ($enums as $column => $enum_values) {
            // Sort enum values by order property first, then by key
            uksort($enum_values, function ($keyA, $keyB) use ($enum_values) {
                $orderA = isset($enum_values[$keyA]['order']) ? $enum_values[$keyA]['order'] : 0;
                $orderB = isset($enum_values[$keyB]['order']) ? $enum_values[$keyB]['order'] : 0;

                // First compare by order
                if ($orderA !== $orderB) {
                    return $orderA - $orderB;
                }

                // If order is same, compare by key (use spaceship operator for string comparison)
                return $keyA <=> $keyB;
            });

            // Generate constants
            foreach ($enum_values as $value => $props) {
                if (!empty($props['constant'])) {
                    $value_json = json_encode($value);
                    $content .= "    static {$props['constant']} = {$value_json};\n";
                }
            }
            if (!empty($enum_values)) {
                $content .= "\n";
            }

            // Generate enum getter with Proxy for maintaining order (BEM-style: field__enum)
            $content .= "    /**\n";
            $content .= "     * Get enum metadata for {$column}.\n";
            $content .= "     * @param {number} [enum_value] - If provided, returns metadata for that ID (or null + console.error if invalid)\n";
            $content .= "     * @returns {Object} All enum definitions keyed by ID, or single enum's metadata if enum_value provided\n";
            $content .= "     * @example\n";
            $content .= "     * // Get all: Model.{$column}__enum()\n";
            $content .= "     * // Get one: Model.{$column}__enum(Model.CONSTANT_NAME).property\n";
            $content .= "     */\n";
            $content .= "    static __{$column}__enum = null;\n";
            $content .= "    static {$column}__enum(enum_value) {\n";
            $content .= "        if (!this.__{$column}__enum) {\n";
            $content .= "            const data = {};\n";
            $content .= "            const order = [];\n";

            // Generate the sorted entries
            foreach ($enum_values as $value => $props) {
                $value_json = json_encode($value);
                $props_json = json_encode($props, JSON_UNESCAPED_SLASHES);
                $content .= "            data[{$value_json}] = {$props_json};\n";
                $content .= "            order.push({$value_json});\n";
            }

            $content .= "            // Cache Proxy that maintains sort order for enumeration\n";
            $content .= "            this.__{$column}__enum = new Proxy(data, {\n";
            $content .= "                ownKeys() {\n";
            $content .= "                    return order.map(String);\n";
            $content .= "                },\n";
            $content .= "                getOwnPropertyDescriptor(target, prop) {\n";
            $content .= "                    if (prop in target) {\n";
            $content .= "                        return {\n";
            $content .= "                            enumerable: true,\n";
            $content .= "                            configurable: true,\n";
            $content .= "                            value: target[prop]\n";
            $content .= "                        };\n";
            $content .= "                    }\n";
            $content .= "                }\n";
            $content .= "            });\n";
            $content .= "        }\n";
            $content .= "        if (enum_value !== undefined) {\n";
            $content .= "            const result = this.__{$column}__enum[enum_value];\n";
            $content .= "            if (!result) {\n";
            $content .= "                console.error(`Invalid enum value '\${enum_value}' for {$column}`);\n";
            $content .= "                return null;\n";
            $content .= "            }\n";
            $content .= "            return result;\n";
            $content .= "        }\n";
            $content .= "        return this.__{$column}__enum;\n";
            $content .= "    }\n\n";

            // Generate enum_select() - Selectable items for dropdowns (respects selectable: false)
            // Returns [{value, label}] array — order baked into array index, no Proxy needed
            $content .= "    /**\n";
            $content .= "     * Get selectable options for {$column} dropdowns (excludes selectable:false items).\n";
            $content .= "     * @returns {Array<{value: number, label: string}>} Options sorted by 'order' property\n";
            $content .= "     */\n";
            $content .= "    static {$column}__enum_select() {\n";
            $content .= "        const fullData = this.{$column}__enum();\n";
            $content .= "        const result = [];\n";
            $content .= "        for (const key of Object.keys(fullData)) {\n";
            $content .= "            const item = fullData[key];\n";
            $content .= "            if (item.selectable !== false && item.label) {\n";
            $content .= "                result.push({value: parseInt(key), label: item.label});\n";
            $content .= "            }\n";
            $content .= "        }\n";
            $content .= "        return result;\n";
            $content .= "    }\n\n";

            // Generate enum_labels() - Simple id => label map (all items, ignores selectable)
            $content .= "    /**\n";
            $content .= "     * Get all {$column} labels (includes non-selectable items).\n";
            $content .= "     * @returns {Object} {id: label} pairs for all enum values\n";
            $content .= "     */\n";
            $content .= "    static {$column}__enum_labels() {\n";
            $content .= "        const values = {};\n";
            foreach ($enum_values as $value => $props) {
                if (isset($props['label'])) {
                    $value_json = json_encode($value);
                    $label = addslashes($props['label']);
                    $content .= "        values[{$value_json}] = '{$label}';\n";
                }
            }
            $content .= "        return values;\n";
            $content .= "    }\n\n";

            // Generate enum_ids() - Array of all valid enum IDs
            $content .= "    /**\n";
            $content .= "     * Get all valid {$column} IDs.\n";
            $content .= "     * @returns {number[]} Array of all enum IDs\n";
            $content .= "     */\n";
            $content .= "    static {$column}__enum_ids() {\n";
            $content .= "        return [";
            $ids = array_keys($enum_values);
            $content .= implode(', ', array_map('json_encode', $ids));
            $content .= "];\n";
            $content .= "    }\n\n";
        }

        // Generate static get_relationships() method
        $relationships_json = json_encode(array_values($relationships));
        $content .= "    /**\n";
        $content .= "     * Get list of relationship names available on this model\n";
        $content .= "     * @returns {Array} Array of relationship method names\n";
        $content .= "     */\n";
        $content .= "    static get_relationships() {\n";
        $content .= "        return {$relationships_json};\n";
        $content .= "    }\n\n";

        // Generate relationship methods
        foreach ($relationships as $relationship) {
            $content .= "    /**\n";
            $content .= "     * Fetch {$relationship} relationship\n";
            $content .= "     * @returns {Promise} Related model instance(s), null, or empty array\n";
            $content .= "     */\n";
            $content .= "    async {$relationship}() {\n";
            $content .= "        if (!this.id) {\n";
            $content .= "            shouldnt_happen('Cannot fetch relationship without id property');\n";
            $content .= "        }\n";
            $content .= "        return await Orm_Controller.fetch_relationship({\n";
            $content .= "            model: '{$class_name}',\n";
            $content .= "            id: this.id,\n";
            $content .= "            relationship: '{$relationship}'\n";
            $content .= "        });\n";
            $content .= "    }\n\n";
        }

        // Generate Class-Table Inheritance detail accessors (embedded-resolving, NOT a network
        // relationship). Bakes the discriminator + value->accessor map so the accessor can
        // throw wrong-type locally and read the embedded detail from __details.
        if ($fqcn::has_detail_tables()) {
            $discriminator = $fqcn::detail_discriminator_column();
            $value_to_accessor = \App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::value_to_accessor($fqcn::$detail_tables);

            $content .= "    // Class-Table Inheritance detail tables (see man detail_tables)\n";
            $content .= "    static __detail_discriminator = " . json_encode($discriminator) . ";\n";
            $content .= "    static __detail_value_to_accessor = " . json_encode($value_to_accessor, JSON_FORCE_OBJECT) . ";\n\n";

            foreach (array_keys($fqcn::detail_accessors()) as $accessor) {
                $content .= "    /**\n";
                $content .= "     * CTI detail accessor (embedded; resolves with no network call; throws on wrong-type).\n";
                $content .= "     * @returns {Promise<Object|null>} The detail model for this record's type, or null\n";
                $content .= "     */\n";
                $content .= "    async {$accessor}() {\n";
                $content .= "        return this.__resolve_detail(" . json_encode($accessor) . ");\n";
                $content .= "    }\n\n";
            }
        }

        // Generate field_length() method for varchar max lengths
        $varchar_lengths = [];
        foreach ($columns as $col_name => $col_data) {
            if (isset($col_data['max_length']) && $col_data['max_length'] !== null) {
                $varchar_lengths[$col_name] = $col_data['max_length'];
            }
        }

        $content .= "    /**\n";
        $content .= "     * Get max length for a varchar/char column.\n";
        $content .= "     * @param {string} column - Column name\n";
        $content .= "     * @returns {number|null} Max length for varchar/char columns, null for other types\n";
        $content .= "     */\n";
        $content .= "    static field_length(column) {\n";
        $content .= "        const lengths = " . json_encode($varchar_lengths, JSON_FORCE_OBJECT) . ";\n";
        $content .= "        return lengths[column] ?? null;\n";
        $content .= "    }\n\n";

        $content .= "}\n";

        return $content;
    }
}
