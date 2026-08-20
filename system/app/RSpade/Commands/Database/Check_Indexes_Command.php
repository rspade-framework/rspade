<?php

namespace App\RSpade\Commands\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Manifest\Manifest;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

/**
 * Database Index Analysis and Recommendation System
 *
 * Analyzes RSX application code to detect required database indexes from:
 * 1. Model relationships (foreign key columns from #[Relationship] methods)
 * 2. Query patterns (AST analysis of Model::where() chains in /rsx/)
 *
 * Generates optimized index recommendations using covering index logic and
 * outputs a migration file for review and application.
 */
class Check_Indexes_Command extends Command
{
    protected $signature = 'rsx:db:check_indexes
        {--generate-migration : Create migration file with recommendations}
        {--table= : Analyze only specific table}
        {--format=text : Output format (text|json)}
        {--no-relationships : Skip relationship analysis}
        {--no-queries : Skip query pattern analysis}
        {--show-all : Show all recommendations even if quota exceeded}
        {--show-details : Show detailed analysis process}';

    protected $description = 'Analyze database index requirements from relationships and query patterns';

    /**
     * All collected index requirements before optimization
     *
     * Structure:
     * [
     *   'table_name' => [
     *     ['columns' => ['col1', 'col2'], 'source' => 'file:line', 'priority' => 'relationship|query', 'type' => 'hasMany|where'],
     *     ...
     *   ]
     * ]
     */
    protected $requirements = [];

    /**
     * Optimized index recommendations after deduplication
     *
     * Structure: same as $requirements but deduplicated
     */
    protected $recommendations = [];

    /**
     * Existing indexes per table
     *
     * Structure:
     * [
     *   'table_name' => [
     *     ['name' => 'idx_name', 'columns' => ['col1', 'col2'], 'unique' => false, 'auto_generated' => true],
     *     ...
     *   ]
     * ]
     */
    protected $existing_indexes = [];

    /**
     * Tables that have site_id column (siteable models)
     */
    protected $siteable_tables = [];

    /**
     * Maximum indexes allowed per table (MySQL InnoDB limit)
     */
    const MAX_INDEXES_PER_TABLE = 64;

    /**
     * Available quota for auto-generated indexes per table
     */
    const AUTO_INDEX_QUOTA = 32;

    public function handle()
    {
        $this->info('Database Index Analysis');
        $this->info(str_repeat('=', 50));
        $this->line('');

        // Phase 1: Collect index requirements
        $this->_collect_index_requirements();

        // Phase 2: Optimize requirements using covering index logic
        $this->_optimize_requirements();

        // Phase 3: Generate index diff (creates vs drops)
        $diff = $this->_generate_index_diff();

        // Phase 4: Output summary
        $this->_output_summary($diff);

        // Phase 5: Generate migration if requested
        if ($this->option('generate-migration')) {
            $this->_generate_migration($diff);
        }

        return 0;
    }

    /**
     * Phase 1: Collect all index requirements from relationships and query patterns
     */
    protected function _collect_index_requirements(): void
    {
        if (!$this->option('no-relationships')) {
            $this->_detect_relationship_requirements();
        }

        if (!$this->option('no-queries')) {
            $this->_detect_query_pattern_requirements();
        }

        if ($this->option('show-details')) {
            $this->line('Total requirements collected: ' . $this->_count_requirements());
        }
    }

    /**
     * Detect index requirements from model relationships (#[Relationship] methods)
     * Priority: HIGHEST - Always included, never cut
     */
    protected function _detect_relationship_requirements(): void
    {
        if ($this->option('show-details')) {
            $this->info('Analyzing model relationships...');
        }

        $model_metadata = Manifest::php_get_extending('Rsx_Model_Abstract');
        $relationship_count = 0;

        foreach ($model_metadata as $metadata) {
            $model_fqcn = $metadata['fqcn'];

            // Relationship names come from the model API, not the raw manifest entry: the
            // manifest indexes methods per file, so an inherited relation (the framework
            // audit relations created_by / updated_by / deleted_by, or any relation an
            // application declares on an intermediate abstract) is absent from this class's
            // own method map. get_relationships() unions the lineage.
            foreach ($model_fqcn::get_relationships() as $method_name) {
                // Instantiate the relationship to introspect it
                try {
                    $model_instance = new $model_fqcn();
                    $relation = $model_instance->$method_name();

                    if (!$relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        continue;
                    }

                    $this->_process_relationship($model_fqcn, $method_name, $relation, $metadata['file']);
                    $relationship_count++;
                } catch (\Throwable $e) {
                    // Skip relationships that can't be introspected
                    continue;
                }
            }
        }

        if ($this->option('show-details')) {
            $this->line("Found {$relationship_count} relationships");
        }
    }

    /**
     * Process a single relationship and add index requirements
     */
    protected function _process_relationship(
        string $source_model_fqcn,
        string $method_name,
        \Illuminate\Database\Eloquent\Relations\Relation $relation,
        string $source_file
    ): void {
        $relationship_type = class_basename(get_class($relation));

        // BelongsTo and MorphTo resolve through the TARGET table's primary key, so they
        // impose no index requirement of their own. This is the branch the inherited audit
        // relations (created_by / updated_by / deleted_by, all morphTo) land in: indexing
        // created_by_id would only serve a "records written by X" query, which no declared
        // relation asks for.
        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo ||
            $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo) {
            return;
        }

        // For HasMany, HasOne, MorphMany, MorphOne - check related table needs index
        $target_table = null;
        $target_columns = [];

        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasMany ||
            $relation instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
            $target_table = $relation->getRelated()->getTable();
            $target_columns = [$relation->getForeignKeyName()];
        }
        elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphMany ||
                  $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphOne) {
            $target_table = $relation->getRelated()->getTable();
            $morph_name = $relation->getMorphType();
            $morph_name = str_replace('_type', '', $morph_name);
            $target_columns = [$morph_name . '_id'];
        }
        elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            // Pivot table needs indexes on both foreign keys
            $this->_process_belongs_to_many($source_model_fqcn, $method_name, $relation, $source_file);
            return;
        }

        if (!$target_table || empty($target_columns)) {
            return;
        }

        $this->_add_requirement($target_table, $target_columns, $source_file, 'relationship', $relationship_type);
    }

    /**
     * Process BelongsToMany relationships (pivot tables)
     */
    protected function _process_belongs_to_many(
        string $source_model_fqcn,
        string $method_name,
        \Illuminate\Database\Eloquent\Relations\BelongsToMany $relation,
        string $source_file
    ): void {
        $pivot_table = $relation->getTable();
        $foreign_pivot_key = $relation->getForeignPivotKeyName();
        $related_pivot_key = $relation->getRelatedPivotKeyName();

        $this->_add_requirement($pivot_table, [$foreign_pivot_key], $source_file, 'relationship', 'BelongsToMany');
        $this->_add_requirement($pivot_table, [$related_pivot_key], $source_file, 'relationship', 'BelongsToMany');
    }

    /**
     * Detect index requirements from query patterns (Model::where() chains)
     * Priority: MEDIUM - Subject to quota limits
     */
    protected function _detect_query_pattern_requirements(): void
    {
        if ($this->option('show-details')) {
            $this->info('Analyzing query patterns...');
        }

        // Get all PHP files from manifest that:
        // 1. Define a class
        // 2. Are in the manifest
        // 3. Have base path starting with ./rsx
        $all_files = Manifest::get_all();
        $php_files_to_scan = [];

        foreach ($all_files as $file => $metadata) {
            // Must be PHP file
            if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
                continue;
            }

            // Must define a class
            if (!isset($metadata['class'])) {
                continue;
            }

            // Must be in ./rsx
            if (!str_starts_with($file, 'rsx/')) {
                continue;
            }

            $php_files_to_scan[] = [
                'file' => $file,
                'class' => $metadata['class'],
            ];
        }

        if ($this->option('show-details')) {
            $this->line('Scanning ' . count($php_files_to_scan) . ' PHP files for query patterns');
        }

        // Parse each file with AST to find Model::where() chains
        $parser = (new ParserFactory())->createForHostVersion();
        $patterns_found = 0;

        foreach ($php_files_to_scan as $file_info) {
            $file_path = base_path($file_info['file']);
            $code = file_get_contents($file_path);

            try {
                $ast = $parser->parse($code);

                // Traverse AST to find where() chains
                $visitor = new class($this, $file_info['file']) extends NodeVisitorAbstract {
                    private $command;
                    private $source_file;

                    public function __construct($command, $source_file)
                    {
                        $this->command = $command;
                        $this->source_file = $source_file;
                    }

                    public function enterNode(Node $node)
                    {
                        // Look for method calls
                        if (!$node instanceof Node\Expr\MethodCall &&
                            !$node instanceof Node\Expr\StaticCall) {
                            return null;
                        }

                        // Extract where() chain
                        $chain = $this->extractWhereChain($node);

                        if (!empty($chain['columns']) && $chain['model_class']) {
                            $this->command->_process_query_pattern(
                                $chain['model_class'],
                                $chain['columns'],
                                $this->source_file . ':' . $node->getStartLine()
                            );
                        }

                        return null;
                    }

                    private function extractWhereChain($node): array
                    {
                        $columns = [];
                        $model_class = null;
                        $current = $node;

                        // Walk backwards through method chain
                        while ($current) {
                            // Check if this is a where() call
                            if ($current instanceof Node\Expr\MethodCall ||
                                $current instanceof Node\Expr\StaticCall) {

                                $method_name = $current->name instanceof Node\Identifier
                                    ? $current->name->toString()
                                    : null;

                                // If it's where(), extract column name
                                if ($method_name === 'where' && isset($current->args[0])) {
                                    $first_arg = $current->args[0]->value;

                                    // Only process string literals (not variables)
                                    if ($first_arg instanceof Node\Scalar\String_) {
                                        // Add to beginning since we're walking backwards
                                        array_unshift($columns, $first_arg->value);
                                    } else {
                                        // Variable column name - stop processing this chain
                                        return ['columns' => [], 'model_class' => null];
                                    }
                                }

                                // Check if we've reached the Model class static call
                                if ($current instanceof Node\Expr\StaticCall) {
                                    $class = $current->class;

                                    if ($class instanceof Node\Name) {
                                        $class_name = $class->toString();

                                        // Check if it ends with _Model
                                        if (str_ends_with($class_name, '_Model')) {
                                            $model_class = $class_name;
                                        }
                                    }
                                }

                                // Move to next in chain
                                if ($current instanceof Node\Expr\MethodCall) {
                                    $current = $current->var;
                                } elseif ($current instanceof Node\Expr\StaticCall) {
                                    // Reached the static call, stop
                                    break;
                                }
                            } else {
                                break;
                            }
                        }

                        // Only return if we have at least one column and found a model
                        if (empty($columns) || !$model_class) {
                            return ['columns' => [], 'model_class' => null];
                        }

                        return [
                            'columns' => $columns,
                            'model_class' => $model_class,
                        ];
                    }
                };

                $traverser = new NodeTraverser();
                $traverser->addVisitor($visitor);
                $traverser->traverse($ast);

            } catch (\Throwable $e) {
                // Skip files that fail to parse
                continue;
            }
        }

        if ($this->option('show-details')) {
            $this->line('Query pattern analysis complete');
        }
    }

    /**
     * Process a detected query pattern and add index requirement
     */
    public function _process_query_pattern(string $model_class, array $columns, string $source): void
    {
        // Resolve model class to table name
        try {
            $table = $model_class::get_table_static();
        } catch (\Throwable $e) {
            // Model class doesn't exist or can't get table
            return;
        }

        $this->_add_requirement($table, $columns, $source, 'query', 'where');
    }

    /**
     * Add an index requirement to the collection
     */
    protected function _add_requirement(
        string $table,
        array $columns,
        string $source,
        string $priority,
        string $type
    ): void {
        // Filter out empty columns
        $columns = array_filter($columns);

        if (empty($columns)) {
            return;
        }

        if (!isset($this->requirements[$table])) {
            $this->requirements[$table] = [];
        }

        $this->requirements[$table][] = [
            'columns' => array_values($columns),
            'source' => $source,
            'priority' => $priority,
            'type' => $type,
        ];
    }

    /**
     * Phase 2: Optimize requirements using covering index logic
     */
    protected function _optimize_requirements(): void
    {
        if ($this->option('show-details')) {
            $this->info('Optimizing index requirements...');
        }

        foreach ($this->requirements as $table => $requirements) {
            $optimized = $this->_apply_covering_index_logic($requirements);
            $optimized = $this->_apply_quota_limits($table, $optimized);

            $this->recommendations[$table] = $optimized;
        }
    }

    /**
     * Apply covering index deduplication logic
     *
     * Algorithm:
     * - Index (a,b,c) can satisfy queries on (a) or (a,b) but NOT (b) or (c) alone
     * - When single-column requirement conflicts with multi-column requirement,
     *   put single-column FIRST to enable left-prefix usage
     * - Example: Requirements (status) + (user_id, status) -> Create (status, user_id)
     */
    protected function _apply_covering_index_logic(array $requirements): array
    {
        // Group by column signature
        $groups = [];
        foreach ($requirements as $req) {
            $sig = implode(',', $req['columns']);
            if (!isset($groups[$sig])) {
                $groups[$sig] = [];
            }
            $groups[$sig][] = $req;
        }

        // Try to find covering relationships and optimize
        $optimized = [];
        $covered = [];

        // Sort by column count (descending) to check longer indexes first
        uasort($groups, function($a, $b) {
            return count($b[0]['columns']) - count($a[0]['columns']);
        });

        foreach ($groups as $sig => $reqs) {
            if (in_array($sig, $covered)) {
                continue;
            }

            $cols = $reqs[0]['columns'];

            // Check if this is covered by any existing optimized index
            $is_covered = false;
            foreach ($optimized as $opt) {
                if ($this->_is_covered_by($cols, $opt['columns'])) {
                    $is_covered = true;
                    break;
                }
            }

            if (!$is_covered) {
                $optimized[] = $reqs[0]; // Keep first occurrence
            } else {
                $covered[] = $sig;
            }
        }

        return $optimized;
    }

    /**
     * Check if needed columns are covered by existing index via left-prefix matching
     *
     * Example: (a,b) is covered by (a,b,c)
     * Example: (b) is NOT covered by (a,b)
     */
    protected function _is_covered_by(array $needed, array $existing): bool
    {
        if (count($needed) > count($existing)) {
            return false;
        }

        for ($i = 0; $i < count($needed); $i++) {
            if ($needed[$i] !== $existing[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply quota limits and prioritization
     *
     * Priority tiers:
     * 1. Relationship indexes (foreign keys) - Never cut
     * 2. Multi-column query indexes (2+ columns) - Cut last
     * 3. Single-column query indexes - Cut first
     */
    protected function _apply_quota_limits(string $table, array $recommendations): array
    {
        // Get existing user-defined indexes (non-auto-generated)
        $existing = $this->_get_table_indexes($table);
        $user_indexes = array_filter($existing, function($idx) {
            return !$this->_is_auto_generated_index($idx['name']);
        });

        $available_quota = min(
            self::AUTO_INDEX_QUOTA,
            self::MAX_INDEXES_PER_TABLE - count($user_indexes)
        );

        if (count($recommendations) <= $available_quota) {
            return $recommendations;
        }

        // Need to cut some indexes - prioritize by tier
        $relationship_indexes = array_filter($recommendations, function($r) {
            return $r['priority'] === 'relationship';
        });

        $multi_col_query_indexes = array_filter($recommendations, function($r) {
            return $r['priority'] === 'query' && count($r['columns']) >= 2;
        });

        $single_col_query_indexes = array_filter($recommendations, function($r) {
            return $r['priority'] === 'query' && count($r['columns']) === 1;
        });

        // Always include all relationship indexes
        $result = array_values($relationship_indexes);
        $remaining = $available_quota - count($result);

        // Add as many multi-column indexes as fit
        foreach ($multi_col_query_indexes as $idx) {
            if ($remaining > 0) {
                $result[] = $idx;
                $remaining--;
            }
        }

        // Add single-column indexes if space remains
        foreach ($single_col_query_indexes as $idx) {
            if ($remaining > 0) {
                $result[] = $idx;
                $remaining--;
            }
        }

        return $result;
    }

    /**
     * Phase 3: Generate index diff (creates and drops)
     */
    protected function _generate_index_diff(): array
    {
        $diff = [
            'creates' => [],
            'drops' => [],
            'warnings' => [],
        ];

        foreach ($this->recommendations as $table => $recommendations) {
            $existing = $this->_get_table_indexes($table);

            // Find indexes to drop (auto-generated but not in recommendations)
            foreach ($existing as $idx) {
                if ($this->_is_auto_generated_index($idx['name'])) {
                    $found = false;
                    foreach ($recommendations as $rec) {
                        if ($this->_indexes_match($idx['columns'], $rec['columns'])) {
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $diff['drops'][$table][] = $idx;
                    }
                }
            }

            // Find indexes to create (recommended but not existing)
            foreach ($recommendations as $rec) {
                $found = false;
                foreach ($existing as $idx) {
                    if ($this->_indexes_match($idx['columns'], $rec['columns'])) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $diff['creates'][$table][] = $rec;
                }
            }
        }

        return $diff;
    }

    /**
     * Check if an index name is auto-generated
     */
    protected function _is_auto_generated_index(string $name): bool
    {
        return strpos($name, 'idx_auto_') === 0;
    }

    /**
     * Check if two index column arrays match
     */
    protected function _indexes_match(array $cols1, array $cols2): bool
    {
        return $cols1 === $cols2;
    }

    /**
     * Phase 4: Output summary
     */
    protected function _output_summary(array $diff): void
    {
        $total_creates = 0;
        $total_drops = 0;

        foreach ($diff['creates'] as $table => $creates) {
            $total_creates += count($creates);
        }

        foreach ($diff['drops'] as $table => $drops) {
            $total_drops += count($drops);
        }

        if ($total_creates === 0 && $total_drops === 0) {
            $this->info('[OK] All indexes are up to date');
            $this->line('');
            $this->_output_limitations_warning();
            return;
        }

        $this->info("Found {$total_creates} indexes to create and {$total_drops} to drop");
        $this->line('');

        // Show details per table
        foreach ($diff['creates'] as $table => $creates) {
            $this->line("Table: {$table}");
            foreach ($creates as $create) {
                $cols = implode(', ', $create['columns']);
                $this->line("  + Create index on ({$cols}) - {$create['source']}");
            }
        }

        foreach ($diff['drops'] as $table => $drops) {
            $this->line("Table: {$table}");
            foreach ($drops as $drop) {
                $cols = implode(', ', $drop['columns']);
                $this->line("  - Drop index {$drop['name']} on ({$cols})");
            }
        }

        $this->line('');

        if (!$this->option('generate-migration')) {
            $this->line('To generate migration:');
            $this->line('  php artisan rsx:db:check_indexes --generate-migration');
        }

        $this->line('');
        $this->_output_limitations_warning();
    }

    /**
     * Output limitations warning (always shown)
     */
    protected function _output_limitations_warning(): void
    {
        $this->warn('[WARNING]  LIMITATIONS');
        $this->line('');
        $this->line('This analysis cannot detect:');
        $this->line('  [ERROR] Dynamic column names: where($variable, ...)');
        $this->line('  [ERROR] Join conditions (separate relationship analysis)');
        $this->line('  [ERROR] Complex WHERE clauses (whereRaw, whereIn, etc.)');
        $this->line('  [ERROR] Runtime-determined query patterns');
        $this->line('');
        $this->line('Always benchmark critical queries and add custom indexes as needed.');
        $this->line('Use EXPLAIN to verify query performance in production.');
    }

    /**
     * Phase 5: Generate migration file
     */
    protected function _generate_migration(array $diff): void
    {
        $timestamp = date('Y_m_d_His');
        $filename = database_path("migrations/{$timestamp}_auto_index_recommendations.php");

        $content = $this->_build_migration_content($diff);

        file_put_contents_safe($filename, $content);

        $this->info("Migration generated: {$filename}");
        $this->line('');
        $this->line('Review the migration and run:');
        $this->line('  php artisan migrate');
    }

    /**
     * Build migration file content
     */
    protected function _build_migration_content(array $diff): string
    {
        $statements = [];

        // Group by table and generate statements
        foreach ($diff['drops'] as $table => $drops) {
            $statements[] = "        // Table: {$table}";
            $statements[] = "        // ============================================";
            $statements[] = "";
            $statements[] = "        // DROP: Obsolete auto-generated indexes";
            $statements[] = "";

            foreach ($drops as $drop) {
                $statements[] = "        DB::statement('DROP INDEX IF EXISTS {$drop['name']} ON {$table}');";
            }

            $statements[] = "";
        }

        foreach ($diff['creates'] as $table => $creates) {
            if (!isset($diff['drops'][$table])) {
                $statements[] = "        // Table: {$table}";
                $statements[] = "        // ============================================";
                $statements[] = "";
            }

            $statements[] = "        // CREATE: New recommended indexes";
            $statements[] = "";

            foreach ($creates as $create) {
                $index_name = 'idx_auto_' . implode('_', $create['columns']);
                $cols_str = implode(', ', $create['columns']);

                $statements[] = "        // Source: {$create['source']}";
                $statements[] = "        // Priority: {$create['priority']} ({$create['type']})";
                $statements[] = "        DB::statement('";
                $statements[] = "            CREATE INDEX {$index_name}";
                $statements[] = "            ON {$table} ({$cols_str})";
                $statements[] = "        ');";
                $statements[] = "";
            }
        }

        $content = implode("\n", $statements);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
{$content}
    }
};
PHP;
    }

    /**
     * Get existing indexes for a table
     */
    protected function _get_table_indexes(string $table): array
    {
        // Check if we already cached this table's indexes
        if (isset($this->existing_indexes[$table])) {
            return $this->existing_indexes[$table];
        }

        try {
            $results = DB::select("SHOW INDEX FROM `{$table}`");
        } catch (\Exception $e) {
            // Table doesn't exist
            return [];
        }

        $indexes = [];
        foreach ($results as $row) {
            $index_name = $row->Key_name;

            if (!isset($indexes[$index_name])) {
                $indexes[$index_name] = [
                    'name' => $index_name,
                    'unique' => $row->Non_unique == 0,
                    'columns' => [],
                ];
            }

            // Add column in correct order
            $indexes[$index_name]['columns'][$row->Seq_in_index - 1] = $row->Column_name;
        }

        // Sort columns by their sequence
        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }

        $this->existing_indexes[$table] = array_values($indexes);
        return $this->existing_indexes[$table];
    }

    /**
     * Count total requirements collected
     */
    protected function _count_requirements(): int
    {
        $count = 0;
        foreach ($this->requirements as $table => $reqs) {
            $count += count($reqs);
        }
        return $count;
    }
}
