<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * DB-UNBOUNDED-01 - flags a whole-set read (->get() / ->pluck()) against a model that
 * DECLARES public static $unbounded = true, in FRAMEWORK code.
 *
 * An $unbounded model is one whose row count grows with customer activity rather than with
 * the codebase: sessions, queues, uploads, the API request log. Nothing about such a query
 * looks wrong, and it works perfectly until the table grows. The framework's own worked
 * example: Session::get_sessions_for_user() ran an unbounded ->get() and the caller sliced
 * to 20, but one login user had accumulated 62,617 rows - enough to exhaust a 128MB process
 * and return a blank 500.
 *
 * SCOPE: framework code only (system/app/), because that is the code we ship to everyone
 * and hold to the higher bar. Application code is covered by the runtime row-count tripwire
 * and by the prelaunch checklist's unbounded-query audit - not by this rule, which would
 * otherwise fire on perfectly reasonable app queries.
 *
 * This is ADVISORY. The declaration is NOT a claim that every query on the model is huge; a
 * small, well-narrowed read is still fine. It is a prompt to state what bounds this one.
 *
 * Detected (AST):
 *   - ->get() / ->pluck() reached through a Model::where()/query()/... builder chain whose
 *     root model declares $unbounded.
 *
 * Suppressed when:
 *   (a) the chain carries a bound or an iteration strategy - ->limit(), ->take(),
 *       ->result_set(), ->lazyById(), ->lazy(), ->chunkById(), ->chunk(), ->cursor(),
 *       ->paginate(), ->simplePaginate(), ->first(), ->raw_bulk(),
 *   (b) the file carries @DB-UNBOUNDED-01-EXCEPTION - <rationale>.
 */
class UnboundedResultSet_CodeQualityRule extends CodeQualityRule_Abstract
{
    protected static $parser = null;

    /**
     * Builder-entry static methods: a whole-set read roots at one of these. Restricting to
     * them avoids flagging instance chains, which are not builder reads.
     */
    private const BUILDER_ROOT_METHODS = [
        'where', 'orWhere', 'whereIn', 'orWhereIn', 'whereNotIn', 'orWhereNotIn',
        'whereNull', 'whereNotNull', 'whereBetween', 'whereColumn', 'whereDate',
        'whereRaw', 'whereHas', 'whereDoesntHave', 'query', 'newQuery', 'newModelQuery',
        'withTrashed', 'onlyTrashed', 'orderBy', 'orderByDesc', 'select', 'groupBy',
        'having', 'latest', 'oldest', 'when', 'has', 'doesntHave',
    ];

    /**
     * Anything in the chain that already bounds the read or turns it into an iteration.
     * Note ->limit()/->take() are here: a true "N most recent" IS a bounded read, and the
     * rule's prey is the read with NO ceiling at all.
     */
    private const BOUNDING_METHODS = [
        'limit', 'take', 'result_set', 'lazyById', 'lazyByIdDesc', 'lazy', 'chunkById',
        'chunkByIdDesc', 'chunk', 'chunkMap', 'cursor', 'paginate', 'simplePaginate',
        'cursorPaginate', 'first', 'firstOrFail', 'find', 'value', 'raw_bulk', 'each',
        'eachById',
    ];

    /** The whole-set reads this rule cares about. */
    private const WHOLE_SET_READS = ['get', 'pluck'];

    public function get_id(): string
    {
        return 'DB-UNBOUNDED-01';
    }

    public function get_name(): string
    {
        return 'Unbounded Result Set Read';
    }

    public function get_description(): string
    {
        return 'Flags whole-set ->get()/->pluck() reads against models declared $unbounded, so framework code either bounds the read or iterates it with ->result_set() instead of assuming the table is small';
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
        $normalized = str_replace('\\', '/', $file_path);

        // FRAMEWORK code only. Application code answers to the runtime tripwire and the
        // prelaunch audit; linting it here would flag ordinary app queries.
        if (!str_contains($normalized, '/app/RSpade/')) {
            return;
        }

        // Generated / vendored / test code is never a framework call site. Models
        // themselves are skipped too: a model's own methods are where a deliberately
        // whole-set accessor legitimately lives (and they carry their own reasoning).
        if (str_contains($normalized, '/vendor/')
            || str_contains($normalized, '/node_modules/')
            || str_contains($normalized, '/database/migrations/')
            || str_contains($normalized, '/CodeQuality/')
            || str_contains($normalized, '/tests/')
        ) {
            return;
        }

        $original = file_get_contents($file_path);

        // (b) file-level exception.
        if (str_contains($original, '@' . $this->get_id() . '-EXCEPTION')) {
            return;
        }

        try {
            $ast = $this->get_parser()->parse($original);
        } catch (Error $error) {
            return; // Unparseable - skip.
        }

        if (!$ast) {
            return;
        }

        $node_finder = new NodeFinder();
        $use_statements = $this->collect_use_statements($ast, $node_finder);

        foreach ($node_finder->findInstanceOf($ast, Node\Expr\MethodCall::class) as $call) {
            $this->check_read_call($call, $use_statements, $file_path);
        }
    }

    /**
     * Inspect one ->get()/->pluck(): resolve its root model and flag when that model is
     * declared $unbounded and the chain carries no bound.
     */
    private function check_read_call(Node\Expr\MethodCall $call, array $use_statements, string $file_path): void
    {
        if (!$call->name instanceof Node\Identifier) {
            return;
        }

        $method = $call->name->name;
        if (!in_array($method, self::WHOLE_SET_READS, true)) {
            return;
        }

        // Walk the receiver chain to its root, watching for any bounding method
        // (suppression (a)).
        $node = $call->var;
        while ($node instanceof Node\Expr\MethodCall) {
            if ($node->name instanceof Node\Identifier
                && in_array($node->name->name, self::BOUNDING_METHODS, true)) {
                return;
            }
            $node = $node->var;
        }

        // Must root at a StaticCall (Model::where(...)). A variable or instance root is
        // unresolvable here and skipped silently.
        if (!$node instanceof Node\Expr\StaticCall) {
            return;
        }

        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            return;
        }

        $root_method = $node->name->name;
        if (!in_array($root_method, self::BUILDER_ROOT_METHODS, true)
            && !in_array($root_method, self::BOUNDING_METHODS, true)) {
            return;
        }

        // A bounding method used as the ROOT (Model::limit(10)->get()) is bounded too.
        if (in_array($root_method, self::BOUNDING_METHODS, true)) {
            return;
        }

        $root_class = $node->class->toString();
        $simple_class = $this->simple_class_name($root_class);

        if (!$this->is_unbounded_model($simple_class, $root_class, $use_statements)) {
            return;
        }

        $this->add_violation(
            $file_path,
            $call->getLine(),
            "Whole-set ->{$method}() on '{$simple_class}', a model declared \$unbounded - its row count grows with customer activity, so this read has no ceiling.",
            "{$simple_class}::{$root_method}(...)->{$method}(...)",
            $this->build_suggestion($simple_class, $method),
            'medium'
        );
    }

    /**
     * Whether a builder root resolves to a model declaring $unbounded.
     */
    private function is_unbounded_model(string $simple_class, string $root_class, array $use_statements): bool
    {
        $qualified = ltrim($root_class, '\\');

        if (str_contains($qualified, '\\')) {
            return $this->declares_unbounded($qualified, $simple_class);
        }

        if (isset($use_statements[$simple_class])) {
            return $this->declares_unbounded($use_statements[$simple_class], $simple_class);
        }

        try {
            if (!Manifest::is_php_model_class($simple_class)) {
                return false;
            }
            $metadata = Manifest::php_get_metadata_by_class($simple_class);
        } catch (\Throwable $e) {
            return false;
        }

        $fqcn = $metadata['fqcn'] ?? null;

        return $fqcn ? $this->declares_unbounded($fqcn, $simple_class) : false;
    }

    /**
     * Read the model's $unbounded declaration. Guarded so a non-model / unloadable class
     * resolves to false (skip silently).
     */
    private function declares_unbounded(string $fqcn, string $simple_class): bool
    {
        try {
            if (!Manifest::is_php_model_class($simple_class)) {
                return false;
            }
            if (!class_exists($fqcn)) {
                return false;
            }
            if (!property_exists($fqcn, 'unbounded')) {
                return false;
            }

            return (bool) $fqcn::$unbounded;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param string $class_reference
     * @return string
     */
    private function simple_class_name(string $class_reference): string
    {
        $parts = explode('\\', ltrim($class_reference, '\\'));

        return end($parts);
    }

    private function build_suggestion(string $simple_class, string $method): string
    {
        return "'{$simple_class}' declares \$unbounded = true: its row count grows with customer\n"
            . "activity (sessions, uploads, queue rows, log rows), not with the codebase. A bare\n"
            . "->{$method}() therefore has no ceiling - it works until the table grows.\n\n"
            . "Pick the shape that matches what this code actually needs:\n\n"
            . "1. ITERATE THE WHOLE SET (the usual answer)\n"
            . "   {$simple_class}::where(...)->result_set()   // foreach it - keyset-paged, whole set,\n"
            . "                                               // one page resident at a time\n\n"
            . "2. ASK FOR N (only when N really is what the caller wants)\n"
            . "   {$simple_class}::where(...)->orderByDesc('created_at')->limit(20)->get()\n"
            . "   Never a cap added 'for safety' to a call that promised everything - that is\n"
            . "   silent data loss, and the caller cannot detect it.\n\n"
            . "3. RETURN THE SET, DO NOT CONSUME IT\n"
            . "   If this is a 'give me these records' API, return ->result_set() and let the\n"
            . "   caller foreach it.\n\n"
            . "4. IT IS GENUINELY BOUNDED HERE\n"
            . "   Narrowed to one parent record, or capped elsewhere? Say so in a comment at the\n"
            . "   call site, then add @DB-UNBOUNDED-01-EXCEPTION - <rationale> to the file.\n\n"
            . "See: the 'Do The Whole Job' section of CLAUDE.md, and\n"
            . "docs.dev/audits/framework_internal_audit_checklist.md.";
    }

    /**
     * @return array<string, string> short name => FQCN
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
}
