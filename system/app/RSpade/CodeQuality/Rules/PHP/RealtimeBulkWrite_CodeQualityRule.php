<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\ModelHelper;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Realtime\Realtime_Touch_Registry;

/**
 * REALTIME-BULK-01 — flags bulk query-builder / DB::table writes against models with a
 * realtime surface ($realtime, a #[Realtime_Touch] relationship, or an overridden
 * realtime_touch()) OR a lifecycle-hook surface (an overridden after_create/update/delete).
 *
 * A MODEL-BUILDER bulk write (Model::where(...)->update()/->delete()) is AUTO-COVERED at
 * runtime — the RestrictedEloquentBuilder processes each affected record through its own
 * save()/delete(), so realtime frames AND the after_* hooks fire per record. So this rule is
 * ADVISORY (medium): its real prey is the paths the builder can NOT see —
 * DB::table('<table>')->update()/->delete() and raw SQL — which bypass the model layer and
 * silently fire NOTHING (no emission, no hook).
 *
 * Detected (AST):
 *   - ->update(...) / ->delete(...) reached through a Model::where()/query()/... builder
 *     chain whose root model has a realtime surface.
 *   - DB::table('<literal>')->...->update()/->delete() where the literal table maps to a
 *     realtime-surface model.
 *
 * Suppressed when:
 *   (a) the SAME statement chain calls ->raw_bulk() (the intentional escape hatch — do the
 *       raw statement, skip all per-record side effects),
 *   (b) the enclosing function/method body calls realtime_emit() (manual emission), or
 *   (c) the file carries @REALTIME-BULK-01-EXCEPTION - <rationale>.
 */
class RealtimeBulkWrite_CodeQualityRule extends CodeQualityRule_Abstract
{
    protected static $parser = null;

    /**
     * Builder-entry static methods: a bulk write's chain roots at one of these (returning a
     * query builder). Restricting to them avoids flagging INSTANCE chains — Model::find($id)
     * ->update(...) returns a hydrated model and goes through the auto-covered model layer,
     * not the bulk builder.
     */
    private const BUILDER_ROOT_METHODS = [
        'where', 'orWhere', 'whereIn', 'orWhereIn', 'whereNotIn', 'orWhereNotIn',
        'whereNull', 'whereNotNull', 'whereBetween', 'whereColumn', 'whereDate',
        'whereRaw', 'whereHas', 'whereDoesntHave', 'query', 'newQuery', 'newModelQuery',
        'withTrashed', 'onlyTrashed', 'orderBy', 'orderByDesc', 'limit', 'take', 'skip',
        'offset', 'select', 'groupBy', 'having', 'latest', 'oldest', 'when', 'has',
        'doesntHave',
    ];

    public function get_id(): string
    {
        return 'REALTIME-BULK-01';
    }

    public function get_name(): string
    {
        return 'Realtime / Lifecycle Bulk Write Coverage Check';
    }

    public function get_description(): string
    {
        return 'Flags bulk query-builder / DB::table update()/delete() writes against models with a realtime or lifecycle-hook surface so their per-record change notifications and after_* hooks fire (or consciously opt out via raw_bulk())';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function is_called_during_manifest_scan(): bool
    {
        return false; // Only run during rsx:check.
    }

    public function get_default_severity(): string
    {
        return 'medium';
    }

    protected function get_parser()
    {
        if (static::$parser === null) {
            static::$parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        return static::$parser;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only application/framework PHP under rsx/ or app/.
        $normalized = str_replace('\\', '/', $file_path);
        if (!str_contains($normalized, '/rsx/') && !str_contains($normalized, '/app/')) {
            return;
        }

        // Framework internals + generated/vendor code are never application call sites.
        if (str_contains($normalized, '/vendor/')
            || str_contains($normalized, '/node_modules/')
            || str_contains($normalized, '/database/migrations/')
            || str_contains($normalized, '/CodeQuality/')
            || str_contains($normalized, '/tests/')
        ) {
            return;
        }

        // Parse the ORIGINAL file (not the sanitized contents): DB::table('<literal>')
        // detection needs the string literals intact.
        $original = file_get_contents($file_path);

        // (c) file-level exception.
        if (str_contains($original, '@' . $this->get_id() . '-EXCEPTION')) {
            return;
        }

        try {
            $ast = $this->get_parser()->parse($original);
        } catch (Error $error) {
            return; // Unparseable — skip.
        }

        if (!$ast) {
            return;
        }

        $node_finder = new NodeFinder();
        $use_statements = $this->collect_use_statements($ast, $node_finder);

        // (b) line ranges of functions whose body calls realtime_emit() — suppresses every
        //     bulk write inside them (the developer emits explicitly there).
        $emit_ranges = $this->collect_emit_covered_ranges($ast, $node_finder);

        $calls = $node_finder->findInstanceOf($ast, Node\Expr\MethodCall::class);
        foreach ($calls as $call) {
            $this->check_write_call($call, $use_statements, $emit_ranges, $file_path);
        }
    }

    /**
     * Inspect one ->update()/->delete() call: resolve its root, and if it is a realtime-surface
     * bulk write with no per-chain/per-method suppression, add a violation.
     */
    private function check_write_call(Node\Expr\MethodCall $call, array $use_statements, array $emit_ranges, string $file_path): void
    {
        if (!$call->name instanceof Node\Identifier) {
            return;
        }

        $method = $call->name->name;
        if ($method !== 'update' && $method !== 'delete') {
            return;
        }

        // Walk the receiver chain to its root, watching for the ->raw_bulk() escape hatch
        // anywhere in the chain (suppression (a)).
        $node = $call->var;
        while ($node instanceof Node\Expr\MethodCall) {
            if ($node->name instanceof Node\Identifier && $node->name->name === 'raw_bulk') {
                return;
            }
            $node = $node->var;
        }

        // The chain must root at a StaticCall (Model::where(...) / DB::table(...)). A variable
        // or instance root is skipped silently (unresolvable, or an auto-covered model write).
        if (!$node instanceof Node\Expr\StaticCall) {
            return;
        }

        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            return;
        }

        // (b) enclosing function emits explicitly.
        if ($this->line_in_ranges($call->getLine(), $emit_ranges)) {
            return;
        }

        $root_class = $node->class->toString();
        $root_method = $node->name->name;
        $simple_class = $this->simple_name($root_class);

        // DB::table('<literal>')->...->update()/->delete()
        if ($simple_class === 'DB' && $root_method === 'table') {
            $this->check_db_table_root($node, $call, $method, $file_path);

            return;
        }

        // Model::<builder>(...)->...->update()/->delete()
        if (!in_array($root_method, self::BUILDER_ROOT_METHODS, true)) {
            return; // Instance-returning static (find/first/...) — auto-covered, not a sweep.
        }

        $fqcn = $this->resolve_realtime_model_fqcn($simple_class, $root_class, $use_statements);
        if ($fqcn === null) {
            return;
        }

        $this->add_violation(
            $file_path,
            $call->getLine(),
            "Bulk {$method}() on '{$simple_class}' (a model with a realtime surface) via a query-builder chain.",
            "{$simple_class}::{$root_method}(...)->{$method}(...)",
            $this->build_model_suggestion($simple_class, $method),
            'medium'
        );
    }

    /**
     * DB::table('<literal>') root: map the table to a model and flag when that model has a
     * realtime surface (DB::table bypasses the model layer entirely — it emits nothing).
     */
    private function check_db_table_root(Node\Expr\StaticCall $root, Node\Expr\MethodCall $call, string $method, string $file_path): void
    {
        $args = $root->args;
        if (empty($args) || !$args[0] instanceof Node\Arg) {
            return;
        }

        $value = $args[0]->value;
        if (!$value instanceof Node\Scalar\String_) {
            return; // Dynamic table name — cannot resolve statically.
        }

        $table = $value->value;

        try {
            $model_simple = ModelHelper::get_model_by_table($table);
        } catch (\Throwable $e) {
            return; // No model for this table — nothing to protect.
        }

        $fqcn = $this->model_fqcn_if_realtime($model_simple);
        if ($fqcn === null) {
            return;
        }

        $this->add_violation(
            $file_path,
            $call->getLine(),
            "Bulk {$method}() on DB::table('{$table}') - that table maps to '{$model_simple}', a model with a realtime surface. DB::table bypasses the model layer, so no change notification is emitted.",
            "DB::table('{$table}')->...->{$method}(...)",
            $this->build_db_table_suggestion($model_simple, $method),
            'medium'
        );
    }

    /**
     * Resolve a builder root class (simple or partially-qualified) to a realtime-surface model
     * FQCN, or null if it is not a model, has no realtime surface, or cannot be resolved.
     */
    private function resolve_realtime_model_fqcn(string $simple_class, string $root_class, array $use_statements): ?string
    {
        // A qualified reference (or a use-aliased one) already names the class.
        $qualified = ltrim($root_class, '\\');
        if (str_contains($qualified, '\\')) {
            return $this->fqcn_if_realtime($qualified, $simple_class);
        }
        if (isset($use_statements[$simple_class])) {
            return $this->fqcn_if_realtime($use_statements[$simple_class], $simple_class);
        }

        return $this->model_fqcn_if_realtime($simple_class);
    }

    /**
     * Resolve a simple model name to its FQCN (via the manifest) and return it only if the
     * model has a realtime surface.
     */
    private function model_fqcn_if_realtime(string $simple_class): ?string
    {
        try {
            if (!Manifest::is_php_model_class($simple_class)) {
                return null;
            }
            $metadata = Manifest::php_get_metadata_by_class($simple_class);
        } catch (\Throwable $e) {
            return null;
        }

        $fqcn = $metadata['fqcn'] ?? null;
        if (!$fqcn) {
            return null;
        }

        return $this->fqcn_if_realtime($fqcn, $simple_class);
    }

    /**
     * Return the FQCN only if it names a model with a realtime surface. Guarded so a
     * non-model / unloadable class resolves to null (skip silently).
     */
    private function fqcn_if_realtime(string $fqcn, string $simple_class): ?string
    {
        try {
            if (!Manifest::is_php_model_class($simple_class)) {
                return null;
            }
            if (!Realtime_Touch_Registry::has_realtime_surface($fqcn)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $fqcn;
    }

    /**
     * Function/method line ranges whose body calls realtime_emit() (suppression (b)).
     *
     * @return array<int, array{start: int, end: int}>
     */
    private function collect_emit_covered_ranges($ast, NodeFinder $node_finder): array
    {
        $ranges = [];

        $functions = array_merge(
            $node_finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class),
            $node_finder->findInstanceOf($ast, Node\Stmt\Function_::class),
            $node_finder->findInstanceOf($ast, Node\Expr\Closure::class),
            $node_finder->findInstanceOf($ast, Node\Expr\ArrowFunction::class)
        );

        foreach ($functions as $function) {
            $emit_calls = $node_finder->find($function, function (Node $node) {
                return $node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->name === 'realtime_emit';
            });

            if (!empty($emit_calls)) {
                $ranges[] = ['start' => $function->getStartLine(), 'end' => $function->getEndLine()];
            }
        }

        return $ranges;
    }

    /**
     * Whether a line falls inside any emit-covered function range.
     *
     * @param array<int, array{start: int, end: int}> $ranges
     */
    private function line_in_ranges(int $line, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($line >= $range['start'] && $line <= $range['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collect `use` statements (short name => FQCN).
     */
    private function collect_use_statements($ast, NodeFinder $node_finder): array
    {
        $use_statements = [];
        foreach ($node_finder->findInstanceOf($ast, Node\Stmt\Use_::class) as $use) {
            foreach ($use->uses as $use_item) {
                $full = $use_item->name->toString();
                $short = $use_item->alias ? $use_item->alias->name : $use_item->name->getLast();
                $use_statements[$short] = $full;
            }
        }

        return $use_statements;
    }

    private function simple_name(string $class_name): string
    {
        $class_name = ltrim($class_name, '\\');
        $parts = explode('\\', $class_name);

        return end($parts);
    }

    private function build_model_suggestion(string $simple_class, string $method): string
    {
        $lines = [];
        $lines[] = "This bulk {$method}() on '{$simple_class}' is AUTO-COVERED at runtime - the";
        $lines[] = "model builder processes each affected record through its own {$method}(), so the";
        $lines[] = "per-record realtime frames and after_* hooks fire. The rule is advisory:";
        $lines[] = "acknowledge the sweep and pick one of:";
        $lines[] = "";
        $lines[] = "  - Keep it (recommended): a normal-sized sweep needs no change. It is O(N)";
        $lines[] = "    inline (one save()/delete() per row); for a very large maintenance sweep";
        $lines[] = "    where that cost or those side effects are unwanted, be explicit below.";
        $lines[] = "  - Opt out of side effects: ->raw_bulk()->{$method}(...) does the single raw";
        $lines[] = "    statement and fires no realtime frame or hook.";
        $lines[] = "  - Emit explicitly: call \$record->realtime_emit() in this method for records";
        $lines[] = "    written outside the model layer.";
        $lines[] = "  - Suppress with rationale: // @REALTIME-BULK-01-EXCEPTION - <why>";

        return implode("\n", $lines);
    }

    private function build_db_table_suggestion(string $model_simple, string $method): string
    {
        $lines = [];
        $lines[] = "DB::table() never touches the model layer, so this bulk {$method}() fires NO";
        $lines[] = "realtime change notification AND no after_* lifecycle hook for '{$model_simple}' -";
        $lines[] = "subscribers will not refresh and any post-write hook is skipped. Choose one:";
        $lines[] = "";
        $lines[] = "  - Preferred: rewrite as a model-builder write ({$model_simple}::where(...)";
        $lines[] = "    ->{$method}(...)), which is auto-covered (per-record emission + hooks).";
        $lines[] = "  - Raw path required: after the write, emit for the affected records via";
        $lines[] = "    \$record->realtime_emit() (or Realtime::publish() for a manual notification).";
        $lines[] = "  - Intentionally silent: // @REALTIME-BULK-01-EXCEPTION - <why> (e.g. a";
        $lines[] = "    framework-internal infra table nobody subscribes to).";

        return implode("\n", $lines);
    }
}
