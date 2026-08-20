<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * SESSION-ID-01 - a null-ish or zero-ish TEST applied to a get_session_id() result.
 *
 * Session::get_session_id() and Portal_Session::get_session_id() are declared `: int`
 * and they CREATE a session when none exists. Their whole job is "give me the id of
 * the session, making one if I have to". They therefore never return null, and by the
 * time the value reaches the caller the session it names exists.
 *
 * So `$id = Session::get_session_id(); if ($id === null) { ... }` is dead code that
 * READS like a guard - the author believed they were asking "is there a session?"
 * while actually forcing one into existence and then testing an impossible branch.
 * That is the exact shape of the bug: the session row is created (a DB write for an
 * anonymous visitor, a Set-Cookie on a response that wanted none), and the branch that
 * was supposed to prevent it can never run.
 *
 * The question the author meant to ask is answered by has_session() (both facades),
 * which reports existence WITHOUT creating anything.
 *
 * Detected (AST, never a regex over source), against a direct call OR a local variable
 * assigned exactly once from that call inside the same function body:
 *   - === null / !== null / == null / != null
 *   - is_null(...), empty(...), isset(...)
 *   - === 0 / == 0 / !== 0 / != 0 / > 0 / >= 0 / < 0 / <= 0
 *   - ?? <expr>, ??= <expr>
 *   - a truthiness test: ?: / full ternary condition, !, if/elseif/while/do condition,
 *     an operand of && or ||
 *
 * NOT detected (deliberately): arithmetic, string interpolation, passing the value to
 * a query, comparing it to ANOTHER session id. Using the value is the point; only
 * testing it for absence is the defect.
 *
 * Suppressed when the file (or the offending line, or the line above it) carries
 * @SESSION-ID-01-EXCEPTION - <rationale>. The two Session facades are excluded: their
 * own internals are where the int is produced, so reasoning about 0/null there is the
 * implementation, not a misuse.
 *
 * FATAL at manifest build: a violation aborts the build via YoureDoingItWrongException
 * and poisons the manifest until the source is fixed.
 */
class SessionIdNullCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'SESSION-ID-01';

    /** The facades whose get_session_id() this rule guards (simple names). */
    private const TARGET_CLASSES = ['Session', 'Portal_Session'];

    private const TARGET_METHOD = 'get_session_id';

    /** Path suffixes whose own internals legitimately reason about the raw int. */
    private const EXCLUDED_PATH_SUFFIXES = [
        '/Core/Session/Session.php',
        '/Core/Portal/Portal_Session.php',
    ];

    /** @var mixed Shared php-parser instance. */
    protected static $parser = null;

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Session ID Existence Test Misuse';
    }

    public function get_description(): string
    {
        return 'Flags a null-ish or zero-ish test on a Session::get_session_id() / '
            . 'Portal_Session::get_session_id() result - the call always creates a session and '
            . 'never returns null, so the test is dead code; has_session() is the real question';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Runs during the manifest scan: testing a forced-session id for absence is a
     * FATAL manifest-time error, not advice. The mistake is invisible at runtime
     * (the dead branch simply never runs) so it must be caught at build time.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Per-file rule: everything it needs is in the file's own AST.
     */
    public function is_incremental(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $normalized = str_replace('\\', '/', $file_path);

        // The facades themselves produce the int; their internals are the implementation.
        foreach (self::EXCLUDED_PATH_SUFFIXES as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                return;
            }
        }

        // This rule (and any rule/fixture describing the pattern) is meta-code ABOUT the
        // pattern, not an instance of it.
        if (str_contains($normalized, '/CodeQuality/')) {
            return;
        }

        // Read the file from disk: the passed contents may be sanitized, and AST parsing
        // needs the real source.
        $original = @file_get_contents($file_path);
        if ($original === false) {
            return;
        }

        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        // Cheap pre-filter: no mention of the method name means nothing to parse.
        if (!str_contains($original, self::TARGET_METHOD)) {
            return;
        }

        // Whole-file exception. CodeQualityChecker applies this generically for
        // rsx:check, but the manifest-time driver does not - so honor it here too.
        if (str_contains($original, $marker)) {
            return;
        }

        try {
            $ast = $this->get_parser()->parse($original);
        } catch (Error $error) {
            return; // Unparseable - skip (a syntax error is reported by the linter).
        }

        if (!$ast) {
            return;
        }

        $lines = explode("\n", $original);
        $node_finder = new NodeFinder();

        // Reported nodes, keyed by object id: a nested closure is walked both on its own
        // and as part of its enclosing function, so the same test node can be reached twice.
        $reported = [];

        // Pass 1: direct tests on the call itself, anywhere in the file (including code
        // outside any function).
        $this->scan_nodes($ast, [], $node_finder, $file_path, $lines, $marker, $reported);

        // Pass 2: tests on a local variable assigned exactly once from the call, scoped to
        // the function body that assigned it.
        foreach ($node_finder->findInstanceOf($ast, Node\FunctionLike::class) as $function_like) {
            $body = $function_like->getStmts();
            if (empty($body)) {
                continue;
            }

            $tracked = $this->collect_tracked_variables($body, $node_finder);
            if (empty($tracked)) {
                continue;
            }

            $this->scan_nodes($body, $tracked, $node_finder, $file_path, $lines, $marker, $reported);
        }
    }

    /**
     * Find every test node in $nodes whose subject is a get_session_id() result - either
     * the call itself or one of the $tracked variable names - and record a violation.
     *
     * @param array<int,Node>    $nodes
     * @param array<string,true> $tracked  variable names holding the call result
     * @param array<int,true>    $reported dedup map, keyed by spl_object_id
     */
    private function scan_nodes(
        array $nodes,
        array $tracked,
        NodeFinder $node_finder,
        string $file_path,
        array $lines,
        string $marker,
        array &$reported
    ): void {
        foreach ($node_finder->find($nodes, fn(Node $node) => true) as $node) {
            $finding = $this->classify_test($node, $tracked);
            if ($finding === null) {
                continue;
            }

            $id = spl_object_id($node);
            if (isset($reported[$id])) {
                continue;
            }
            $reported[$id] = true;

            $line = $node->getStartLine();

            // Line-level exception: on the offending line or the one above it.
            $index = $line - 1;
            if ($index >= 0 && isset($lines[$index]) && str_contains($lines[$index], $marker)) {
                continue;
            }
            if ($index - 1 >= 0 && isset($lines[$index - 1]) && str_contains($lines[$index - 1], $marker)) {
                continue;
            }

            $facade = $finding['facade'];
            $snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

            $this->add_violation(
                $file_path,
                $line,
                sprintf(
                    "%s applied to the result of %s::%s(). That call CREATES a session when none "
                        . "exists and is declared `: int` - it never returns null, and the session it "
                        . "names always exists by the time you hold the value. This test is dead code, "
                        . "and reaching it has already forced a session row (and a Set-Cookie) into "
                        . "existence for a visitor who may not have needed one.",
                    $finding['description'],
                    $facade,
                    self::TARGET_METHOD
                ),
                $snippet,
                sprintf(
                    "If you meant \"is there a session?\", ask %s::has_session() - it reports existence "
                        . "WITHOUT creating one, and returns false for an anonymous visitor.\n"
                        . "If you meant \"give me the session, making one if needed\", the call already "
                        . "did that: drop the test and use the value.\n"
                        . "See: php artisan rsx:man session (LAZY INITIALIZATION).",
                    $facade
                ),
                'critical'
            );
        }
    }

    /**
     * Classify $node as a null-ish / zero-ish test on a get_session_id() result.
     *
     * @param array<string,true> $tracked
     * @return array{description:string,facade:string}|null
     */
    private function classify_test(Node $node, array $tracked): ?array
    {
        // --- comparisons against null or literal 0 -------------------------------
        if ($node instanceof Node\Expr\BinaryOp\Identical
            || $node instanceof Node\Expr\BinaryOp\NotIdentical
            || $node instanceof Node\Expr\BinaryOp\Equal
            || $node instanceof Node\Expr\BinaryOp\NotEqual
        ) {
            $facade = $this->comparison_subject($node->left, $node->right, $tracked, true);

            return $facade === null ? null : ['description' => 'A comparison against ' . $this->literal_label($node->left, $node->right), 'facade' => $facade];
        }

        if ($node instanceof Node\Expr\BinaryOp\Greater
            || $node instanceof Node\Expr\BinaryOp\GreaterOrEqual
            || $node instanceof Node\Expr\BinaryOp\Smaller
            || $node instanceof Node\Expr\BinaryOp\SmallerOrEqual
        ) {
            $facade = $this->comparison_subject($node->left, $node->right, $tracked, false);

            return $facade === null ? null : ['description' => 'A comparison against 0', 'facade' => $facade];
        }

        // --- is_null() ------------------------------------------------------------
        if ($node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && strcasecmp($node->name->toString(), 'is_null') === 0
            && isset($node->args[0])
            && $node->args[0] instanceof Node\Arg
        ) {
            $facade = $this->subject_facade($node->args[0]->value, $tracked);

            return $facade === null ? null : ['description' => 'is_null()', 'facade' => $facade];
        }

        // --- empty() / isset() ----------------------------------------------------
        if ($node instanceof Node\Expr\Empty_) {
            $facade = $this->subject_facade($node->expr, $tracked);

            return $facade === null ? null : ['description' => 'empty()', 'facade' => $facade];
        }

        if ($node instanceof Node\Expr\Isset_) {
            foreach ($node->vars as $var) {
                $facade = $this->subject_facade($var, $tracked);
                if ($facade !== null) {
                    return ['description' => 'isset()', 'facade' => $facade];
                }
            }

            return null;
        }

        // --- ?? and ??= -----------------------------------------------------------
        if ($node instanceof Node\Expr\BinaryOp\Coalesce) {
            $facade = $this->subject_facade($node->left, $tracked);

            return $facade === null ? null : ['description' => 'A ?? null-coalesce', 'facade' => $facade];
        }

        if ($node instanceof Node\Expr\AssignOp\Coalesce) {
            $facade = $this->subject_facade($node->var, $tracked);

            return $facade === null ? null : ['description' => 'A ??= null-coalescing assignment', 'facade' => $facade];
        }

        // --- truthiness tests -----------------------------------------------------
        if ($node instanceof Node\Expr\Ternary) {
            $facade = $this->subject_facade($node->cond, $tracked);

            return $facade === null
                ? null
                : ['description' => $node->if === null ? 'A ?: short ternary' : 'A ternary condition', 'facade' => $facade];
        }

        if ($node instanceof Node\Expr\BooleanNot) {
            $facade = $this->subject_facade($node->expr, $tracked);

            return $facade === null ? null : ['description' => 'A ! truthiness test', 'facade' => $facade];
        }

        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr
            || $node instanceof Node\Expr\BinaryOp\LogicalAnd
            || $node instanceof Node\Expr\BinaryOp\LogicalOr
        ) {
            $facade = $this->subject_facade($node->left, $tracked) ?? $this->subject_facade($node->right, $tracked);

            return $facade === null ? null : ['description' => 'A truthiness test inside a boolean expression', 'facade' => $facade];
        }

        if ($node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_
        ) {
            $facade = $this->subject_facade($node->cond, $tracked);

            return $facade === null ? null : ['description' => 'A truthiness test as a condition', 'facade' => $facade];
        }

        return null;
    }

    /**
     * For a two-sided comparison: return the facade name when one side is a
     * get_session_id() subject and the other is a null / 0 literal.
     *
     * @param array<string,true> $tracked
     * @param bool               $allow_null whether `null` counts as the literal side
     */
    private function comparison_subject(Node $left, Node $right, array $tracked, bool $allow_null): ?string
    {
        $facade = $this->subject_facade($left, $tracked);
        if ($facade !== null && $this->is_absence_literal($right, $allow_null)) {
            return $facade;
        }

        $facade = $this->subject_facade($right, $tracked);
        if ($facade !== null && $this->is_absence_literal($left, $allow_null)) {
            return $facade;
        }

        return null;
    }

    /**
     * Human label for whichever side of the comparison carried the literal.
     */
    private function literal_label(Node $left, Node $right): string
    {
        return ($this->is_null_literal($left) || $this->is_null_literal($right)) ? 'null' : '0';
    }

    /**
     * Whether $node is the `null` constant, or (when zero counts) the integer literal 0.
     */
    private function is_absence_literal(Node $node, bool $allow_null): bool
    {
        if ($allow_null && $this->is_null_literal($node)) {
            return true;
        }

        return $node instanceof Node\Scalar\Int_ && $node->value === 0;
    }

    private function is_null_literal(Node $node): bool
    {
        return $node instanceof Node\Expr\ConstFetch
            && $node->name instanceof Node\Name
            && strcasecmp($node->name->toString(), 'null') === 0;
    }

    /**
     * Return the facade simple name when $node IS a get_session_id() result: the static
     * call itself, or a variable tracked as holding it.
     *
     * @param array<string,true> $tracked variable name => facade simple name
     */
    private function subject_facade(Node $node, array $tracked): ?string
    {
        $facade = $this->target_call_facade($node);
        if ($facade !== null) {
            return $facade;
        }

        if ($node instanceof Node\Expr\Variable && is_string($node->name) && isset($tracked[$node->name])) {
            return $tracked[$node->name];
        }

        return null;
    }

    /**
     * Return the facade simple name when $node is Session::get_session_id() or
     * Portal_Session::get_session_id() (any namespace qualification, any arguments).
     */
    private function target_call_facade(Node $node): ?string
    {
        if (!$node instanceof Node\Expr\StaticCall) {
            return null;
        }
        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            return null;
        }
        if (strcasecmp($node->name->toString(), self::TARGET_METHOD) !== 0) {
            return null;
        }

        $parts = explode('\\', $node->class->toString());
        $simple = end($parts);

        foreach (self::TARGET_CLASSES as $target) {
            if (strcasecmp($simple, $target) === 0) {
                return $target;
            }
        }

        return null;
    }

    /**
     * Variables inside one function body that unambiguously hold a get_session_id()
     * result: bound EXACTLY ONCE in the body, by a plain assignment from the call.
     *
     * Single-assignment is the whole dataflow model, deliberately. A name written more
     * than once (reassigned, a parameter, a foreach target, a by-reference use) is not
     * provably the session id at the point of the test, so it is dropped rather than
     * guessed at - this rule is fatal and must not fire on a maybe.
     *
     * @param array<int,Node> $body
     * @return array<string,string> variable name => facade simple name
     */
    private function collect_tracked_variables(array $body, NodeFinder $node_finder): array
    {
        $binding_counts = [];
        $candidates = [];

        foreach ($node_finder->find($body, fn(Node $node) => true) as $node) {
            // Plain assignment - the only shape that can make a variable a candidate.
            if ($node instanceof Node\Expr\Assign) {
                foreach ($this->assigned_names($node->var) as $name) {
                    $binding_counts[$name] = ($binding_counts[$name] ?? 0) + 1;
                }

                if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                    $facade = $this->target_call_facade($node->expr);
                    if ($facade !== null) {
                        $candidates[$node->var->name] = $facade;
                    }
                }

                continue;
            }

            // Every other way a name can be (re)bound disqualifies it.
            if ($node instanceof Node\Expr\AssignRef) {
                foreach ($this->assigned_names($node->var) as $name) {
                    $binding_counts[$name] = ($binding_counts[$name] ?? 0) + 1;
                }
                continue;
            }

            if ($node instanceof Node\Expr\AssignOp) {
                foreach ($this->assigned_names($node->var) as $name) {
                    $binding_counts[$name] = ($binding_counts[$name] ?? 0) + 1;
                }
                continue;
            }

            if ($node instanceof Node\Param && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $binding_counts[$node->var->name] = ($binding_counts[$node->var->name] ?? 0) + 1;
                continue;
            }

            if ($node instanceof Node\Stmt\Foreach_) {
                foreach ([$node->keyVar, $node->valueVar] as $var) {
                    if ($var === null) {
                        continue;
                    }
                    foreach ($this->assigned_names($var) as $name) {
                        $binding_counts[$name] = ($binding_counts[$name] ?? 0) + 1;
                    }
                }
                continue;
            }

            if ($node instanceof Node\Stmt\Catch_ && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $binding_counts[$node->var->name] = ($binding_counts[$node->var->name] ?? 0) + 1;
                continue;
            }

            if ($node instanceof Node\StaticVar && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $binding_counts[$node->var->name] = ($binding_counts[$node->var->name] ?? 0) + 1;
                continue;
            }

            if ($node instanceof Node\Stmt\Global_) {
                foreach ($node->vars as $var) {
                    foreach ($this->assigned_names($var) as $name) {
                        $binding_counts[$name] = ($binding_counts[$name] ?? 0) + 1;
                    }
                }
                continue;
            }

            if ($node instanceof Node\ClosureUse && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                // A `use (&$x)` can rewrite the outer variable; `use ($x)` cannot, but the
                // distinction is not worth a false negative's risk of a false positive.
                if ($node->byRef) {
                    $binding_counts[$node->var->name] = ($binding_counts[$node->var->name] ?? 0) + 1;
                }
                continue;
            }
        }

        $tracked = [];
        foreach ($candidates as $name => $facade) {
            if (($binding_counts[$name] ?? 0) === 1) {
                $tracked[$name] = $facade;
            }
        }

        return $tracked;
    }

    /**
     * Variable names bound by an assignment target, including list()/[] destructuring.
     *
     * @return array<int,string>
     */
    private function assigned_names(Node $target): array
    {
        if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
            return [$target->name];
        }

        $names = [];

        if ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
            foreach ($target->items as $item) {
                if ($item === null) {
                    continue;
                }
                $names = array_merge($names, $this->assigned_names($item->value));
            }
        }

        return $names;
    }

    /**
     * Get or create the shared php-parser instance.
     */
    private function get_parser()
    {
        if (static::$parser === null) {
            static::$parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        return static::$parser;
    }
}
