<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Codegen\Model_Codegen_Rewriter;
use App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver;
use App\RSpade\Core\Framework\Framework_Mutations;
use App\RSpade\Core\Manifest\Manifest;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;

/**
 * Regenerates model constants and docblocks based on $enums and the database schema.
 *
 * This is the single canonical model-codegen command. It scans every concrete model
 * extending Rsx_Model_Abstract and, for each one:
 *
 * 1. Validates the model's $enums (integer values, integer column type, no duplicate
 *    constants) - fails loud rather than emitting broken metadata.
 * 2. Emits a pre-class `_AUTO_GENERATED_ Database type hints` docblock with:
 *    - a typed `@property` line per base-table column (DATE/DATETIME -> string, matching
 *      the framework's string-time philosophy - model datetime attributes are ISO strings),
 *    - a `@property` line per Class-Table-Inheritance detail column, tagged `(detail: <table>)`,
 *    - BEM `@property-read string $col__label` / `$col__constant` accessor hints,
 *    - BEM typed `@method static array $col__enum()/__enum_select()/__enum_labels()/__enum_ids()`.
 * 3. Emits an `_AUTO_GENERATED_ Enum constants` block of `const` declarations after the
 *    class brace.
 *
 * The write is surgical: it goes through Model_Codegen_Rewriter, an AST-guided rewriter that
 * replaces ONLY the auto-generated regions and refuses (fails loud + skips the file) if any
 * hand-written byte would change. Framework-authored writes are labeled for the self-update
 * tamper ledger.
 */
class Constants_Regenerate_Command extends Command
{
    protected $signature = 'rsx:constants:regenerate';

    protected $description = 'Regenerate model constants and docblocks based on $enums';

    /**
     * Type mapping from database column types to PHP type hints. Keyed on both the DBAL
     * ABSTRACT type names Schema::getColumnType() actually returns here (`string`, `bigint`,
     * `datetime`, `text`, ...) and the raw MySQL type names, so it is correct regardless of
     * which form the schema builder reports.
     *
     * DATE / DATETIME / TIMESTAMP / TIME -> string: model datetime attributes are ISO
     * strings in this framework (Rsx_Date_Cast / Rsx_DateTime_Cast), never Carbon objects.
     */
    protected $type_map = [
        // Integer family.
        'bigint' => 'int',
        'int' => 'int',
        'integer' => 'int',
        'tinyint' => 'int',
        'smallint' => 'int',
        'mediumint' => 'int',
        'year' => 'int',
        // Floating point.
        'decimal' => 'float',
        'float' => 'float',
        'double' => 'float',
        'real' => 'float',
        // Boolean.
        'boolean' => 'bool',
        // Date/time - ISO strings in this framework, never Carbon.
        'date' => 'string',
        'datetime' => 'string',
        'datetimetz' => 'string',
        'timestamp' => 'string',
        'time' => 'string',
        // String family (`string` is the DBAL abstract type for VARCHAR/CHAR).
        'string' => 'string',
        'char' => 'string',
        'varchar' => 'string',
        'text' => 'string',
        'mediumtext' => 'string',
        'longtext' => 'string',
        'guid' => 'string',
        'blob' => 'string',
        'binary' => 'string',
        // JSON.
        'json' => 'array',
        'jsonb' => 'array',
    ];

    public function handle()
    {
        $this->info('Regenerating model constants...');

        // Master list of models from the manifest.
        $model_entries = Manifest::php_get_extending('Rsx_Model_Abstract');

        // Remove duplicates (in case a model is found multiple times).
        $unique_models = [];
        foreach ($model_entries as $model) {
            if (isset($model['fqcn'])) {
                $unique_models[$model['fqcn']] = $model;
            }
        }

        $processed_count = 0;
        $skipped = [];

        foreach ($unique_models as $model_metadata) {
            $file_rel = $model_metadata['file'] ?? '';
            $full_class_name = $model_metadata['fqcn'] ?? '';

            if (!$file_rel || !$full_class_name) {
                continue;
            }

            // Skip framework files (app/RSpade/) unless in framework developer mode. This
            // prevents end users from accidentally modifying framework source.
            if (str_starts_with($file_rel, 'app/RSpade/') && !config('rsx.code_quality.is_framework_developer', false)) {
                continue;
            }

            // Skip abstract classes.
            if (Manifest::php_is_abstract($full_class_name)) {
                continue;
            }

            $class_name = class_basename($full_class_name);
            $file_path = base_path($file_rel);

            // A model whose table is not in the current schema (a test-fixture model whose
            // tables exist only during a test run, or a model whose migration has not been
            // applied yet), or whose table lists no columns, has nothing to document - skip
            // it. The command runs post-migrate, so this must never fatal.
            $table = with(new $full_class_name())->getTable();
            if (!Schema::hasTable($table) || empty(Schema::getColumnListing($table))) {
                continue;
            }

            // Enum validation is a HARD gate: broken enum definitions must stop the run so
            // they are fixed before any metadata is emitted. Returns non-null exit code.
            $validation_exit = $this->validate_enums($full_class_name, $class_name);
            if ($validation_exit !== null) {
                return $validation_exit;
            }

            try {
                $updated = $this->regenerate_model($full_class_name, $class_name, $file_path);
                if ($updated) {
                    $processed_count++;
                    $this->line(" Updated: {$class_name}");
                }
            } catch (RuntimeException $e) {
                // A file the rewriter cannot rewrite cleanly is left untouched and reported.
                // A skipped file is recoverable; a silently rewritten one is not.
                $skipped[$file_rel] = $e->getMessage();
                $this->error("Skipped {$class_name}: " . $e->getMessage());
            }
        }

        $this->newLine();
        if ($processed_count > 0) {
            $this->info("[OK] Successfully updated {$processed_count} model(s) with constants and docblocks.");
        } else {
            $this->info('No models needed updating.');
        }

        if (!empty($skipped)) {
            $this->newLine();
            $this->error('The following files were SKIPPED (not written) and need attention:');
            foreach ($skipped as $file => $reason) {
                $this->line("  - {$file}");
                $this->line("      {$reason}");
            }
            return 1;
        }

        return 0;
    }

    /**
     * Validate the model's $enums before any metadata is emitted. Bad values / column types
     * are fatal (return an exit code, stopping the whole run); a duplicate constant throws
     * (the caller's per-file catch skips + reports). Returns null when the model is clean.
     */
    protected function validate_enums(string $full_class_name, string $class_name): ?int
    {
        $table = with(new $full_class_name())->getTable();

        $reflector = new ReflectionClass($full_class_name);
        $enums = $reflector->getStaticPropertyValue('enums', []);

        foreach ($enums as $column => $enum_values) {
            // Validate enum values are integers.
            foreach ($enum_values as $value => $props) {
                if (!is_int($value) && !ctype_digit((string)$value)) {
                    $this->error("Invalid enum value '{$value}' for column '{$column}' in model '{$full_class_name}'.");
                    $this->newLine();
                    $this->error('ENUM VALUES MUST BE INTEGERS.');
                    $this->newLine();
                    $this->line('The purpose of enum values is to store an INTEGER in the database that corresponds to a');
                    $this->line("string in the enum definition. The string label can then be changed in the enum 'label'");
                    $this->line('property without affecting the database value, so long as it continues to correspond to');
                    $this->line('the same numeric integer.');
                    $this->newLine();
                    $this->line('Example of a properly defined enum:');
                    $this->newLine();
                    $this->line("    protected static \$enums = [");
                    $this->line("        'status' => [");
                    $this->line("            1 => ['label' => 'Active', 'constant' => 'STATUS_ACTIVE'],");
                    $this->line("            2 => ['label' => 'Inactive', 'constant' => 'STATUS_INACTIVE'],");
                    $this->line("            3 => ['label' => 'Pending', 'constant' => 'STATUS_PENDING'],");
                    $this->line('        ],');
                    $this->line('    ];');
                    $this->newLine();
                    $this->line('For more information, run: php artisan rsx:man enums');
                    return 1;
                }
            }

            // Validate enum column is an integer type in the database.
            $column_type = DB::getSchemaBuilder()->getColumnType($table, $column);
            $valid_integer_types = ['integer', 'bigint', 'smallint', 'tinyint', 'mediumint'];

            // Special case: allow 'boolean' type (TINYINT(1)) ONLY if enum values are 0 and 1.
            $is_boolean_enum = false;
            if ($column_type === 'boolean') {
                $enum_keys = array_keys($enum_values);
                sort($enum_keys);
                if ($enum_keys === [0, 1]) {
                    $is_boolean_enum = true;
                }
            }

            if (!in_array($column_type, $valid_integer_types) && !$is_boolean_enum) {
                $this->error("Invalid column type '{$column_type}' for enum column '{$column}' in table '{$table}' (model '{$full_class_name}').");
                $this->newLine();
                $this->error('ENUM COLUMNS MUST BE INTEGER TYPES.');
                $this->newLine();

                if ($column_type === 'boolean') {
                    $this->line("TINYINT columns are reported as 'boolean' by Laravel because TINYINT is ONLY for true/false values.");
                    $this->line('For enum values with multiple options (1, 2, 3, etc.), you MUST use INT or BIGINT.');
                    $this->newLine();
                }

                $this->line('Enum values are stored as integers in the database. The column must be defined as an');
                $this->line('integer type (INT, BIGINT, SMALLINT, or MEDIUMINT), not VARCHAR, TINYINT, or other types.');
                $this->newLine();
                $this->line("Current column type: {$column_type}");
                $this->line('Required column types: ' . implode(', ', $valid_integer_types));
                $this->newLine();
                $this->line('To fix this issue:');
                $this->line('1. Create a migration to change the column type to INT or BIGINT');
                $this->line('2. Example migration:');
                $this->newLine();
                $this->line('    public function up()');
                $this->line('    {');
                $this->line("        Schema::table('{$table}', function (Blueprint \$table) {");
                $this->line("            \$table->integer('{$column}')->change();");
                $this->line('        });');
                $this->line('    }');
                $this->newLine();
                $this->line('For more information, run: php artisan rsx:man enums');
                return 1;
            }

            // Check for duplicate constants.
            $constants = [];
            foreach ($enum_values as $value => $props) {
                if (isset($props['constant'])) {
                    if (in_array($props['constant'], $constants)) {
                        throw new Exception("Duplicate constant '{$props['constant']}' found in '{$column}' for value '{$value}'");
                    }
                    $constants[] = $props['constant'];
                }
            }
        }

        return null;
    }

    /**
     * Build the fresh docblock + enum-constants block for a model and write it through the
     * fence-safe rewriter. Returns true when the file changed.
     */
    protected function regenerate_model(string $full_class_name, string $class_name, string $file_path): bool
    {
        [$doc_block, $constants_block] = $this->build_metadata($full_class_name);

        return $this->write_model_file($file_path, $class_name, $doc_block, $constants_block);
    }

    /**
     * Build the pre-class docblock and post-brace enum-constants block for a model from its
     * database schema (base + CTI detail columns) and $enums. Returns [$doc_block,
     * $constants_block] ($constants_block is '' for enum-less models). Pure computation - no
     * file writes - so it is directly assertable.
     *
     * @return array{0:string,1:string}
     */
    public function build_metadata(string $full_class_name): array
    {
        $model = new $full_class_name();
        $table = $model->getTable();

        $reflector = new ReflectionClass($full_class_name);
        $enums = $reflector->getStaticPropertyValue('enums', []);

        // ---- @property lines for every base-table column ----
        $properties = [];
        foreach (Schema::getColumnListing($table) as $column) {
            $php_type = $this->map_database_type_to_php(DB::getSchemaBuilder()->getColumnType($table, $column));
            $properties[] = " * @property {$php_type} \${$column}";
        }

        // ---- Class-Table Inheritance: document detail-table columns as part of the base
        // model, each tagged with the detail table it lives in. ----
        if ($full_class_name::has_detail_tables()) {
            foreach (Detail_Tables_Resolver::detail_classes($full_class_name::$detail_tables) as $detail_class) {
                if (!class_exists($detail_class)) {
                    continue;
                }
                $detail_table = (new $detail_class())->getTable();
                if (!Schema::hasTable($detail_table)) {
                    continue;
                }
                // Structural columns belong to the detail table's own machinery, not the
                // model's field surface.
                $structural = [
                    'id', $detail_class::parent_key(),
                    'created_at', 'updated_at', 'deleted_at',
                    'created_by_id', 'created_by_type', 'updated_by_id', 'updated_by_type',
                    'deleted_by_id', 'deleted_by_type',
                ];
                foreach (Schema::getColumnListing($detail_table) as $column) {
                    if (in_array($column, $structural, true)) {
                        continue;
                    }
                    $php_type = $this->map_database_type_to_php(DB::getSchemaBuilder()->getColumnType($detail_table, $column));
                    $properties[] = " * @property {$php_type} \${$column} (detail: {$detail_table})";
                }
            }
        }

        // ---- BEM enum accessor properties + typed methods ----
        $enum_properties = [];
        $enum_methods = [];
        foreach ($enums as $column_name => $enum_definitions) {
            $enum_properties[] = " * @property-read string \${$column_name}__label";
            $enum_properties[] = " * @property-read string \${$column_name}__constant";

            $enum_methods[] = " * @method static array {$column_name}__enum() Get all enum definitions with full metadata";
            $enum_methods[] = " * @method static array {$column_name}__enum_select() Get [{value, label}] array for dropdowns";
            $enum_methods[] = " * @method static array {$column_name}__enum_labels() Get simple id => label map";
            $enum_methods[] = " * @method static array {$column_name}__enum_ids() Get array of all valid enum IDs";
        }

        // ---- enum constants ----
        $enum_constants = [];
        foreach ($enums as $column_name => $enum_definitions) {
            foreach ($enum_definitions as $value => $definition) {
                if (isset($definition['constant'])) {
                    $enum_constants[] = "    const {$definition['constant']} = {$value};";
                }
            }
        }

        // ---- assemble the pre-class docblock ----
        $doc_block = "/**\n";
        $doc_block .= " * _AUTO_GENERATED_ Database type hints - do not edit manually\n";
        $doc_block .= " * Table: {$table}\n";
        $doc_block .= " *\n";

        if (!empty($properties)) {
            $doc_block .= implode("\n", $properties) . "\n";
        }

        if (!empty($enum_properties)) {
            $doc_block .= " *\n";
            $doc_block .= implode("\n", $enum_properties) . "\n";
        }

        if (!empty($enum_methods)) {
            $doc_block .= " *\n";
            $doc_block .= implode("\n", $enum_methods) . "\n";
        }

        $doc_block .= " *\n";
        $doc_block .= " * @mixin \\Eloquent\n";
        $doc_block .= " */";

        // ---- assemble the post-brace enum-constants block (empty for enum-less models) ----
        $constants_block = '';
        if (!empty($enum_constants)) {
            $constants_block = "    /**\n";
            $constants_block .= "     * _AUTO_GENERATED_ Enum constants\n";
            $constants_block .= "     */\n";
            $constants_block .= implode("\n", $enum_constants) . "\n";
        }

        return [$doc_block, $constants_block];
    }

    /**
     * Fence-safe rewrite: the rewriter replaces ONLY the auto-generated docblock and
     * enum-constants block (locating them structurally via php-parser), leaving every other
     * byte - including hand-written declarations between the class brace and the first fence -
     * verbatim. An embedded self-check refuses the write if any hand-written byte would change.
     * A structural surprise (class not found, duplicate/unbalanced markers, parse error) throws;
     * the caller skips the file and reports. Returns true when the file changed.
     */
    protected function write_model_file(string $file_path, string $class_name, string $doc_block, string $constants_block): bool
    {
        $content = file_get_contents($file_path);

        $new_content = Model_Codegen_Rewriter::rewrite($content, $class_name, $doc_block, $constants_block);

        if ($new_content === $content) {
            return false;
        }

        // Label the mutation-marker record for this write precisely (the generic
        // file_put_contents_safe hook consumes this one-shot hint). Only framework-core
        // model files under app/RSpade fall in an owned zone; app model files resolve
        // outside it and the recorder no-ops.
        Framework_Mutations::$next_mechanism = 'model_codegen';
        file_put_contents_safe($file_path, $new_content);

        return true;
    }

    protected function map_database_type_to_php(string $db_type): string
    {
        $db_type = strtolower($db_type);

        // Handle types with parentheses (e.g., "varchar(255)").
        if (preg_match('/^(\w+)/', $db_type, $matches)) {
            $db_type = $matches[1];
        }

        return $this->type_map[$db_type] ?? 'mixed';
    }
}
