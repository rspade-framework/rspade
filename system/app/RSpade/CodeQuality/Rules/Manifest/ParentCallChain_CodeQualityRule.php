<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * PHP-PARENT-CHAIN-01 - Default parent-call chaining for method overrides.
 *
 * Contract: any method that overrides a same-named method declared by an ANCESTOR
 * class visible in the manifest MUST call parent::<method>() somewhere in its body,
 * UNLESS the nearest ancestor that declares the method is abstract or marked
 * #[Replaceable]. Chaining is the default; #[Replaceable] is the explicit opt-out
 * (the inverse of `final`).
 *
 * Motivating incident: a booted() override that forgot parent::booted() silently
 * dropped the site global scope (cross-tenant read leak). PHP does not require
 * chaining a parent override - losing the chain fails OPEN and SILENT. This rule
 * makes chaining the enforced default.
 *
 * Scope covers EVERYTHING the child declares, including __construct and magic
 * methods (owner decision 1). Overrides that legitimately fully replace opt out
 * with #[Replaceable] on the parent method.
 *
 * Detection is AST-based (nikic/php-parser), never a regex over raw source, so a
 * comment or string literal mentioning parent::method() cannot spoof it. The
 * manifest is used ONLY for lineage (php_get_lineage) and ancestor file lookup
 * (php_find_class); per-method modifiers (abstract/attributes) are read
 * authoritatively from the rule's own AST parse of each file, because the manifest
 * method maps lack the abstract flag and omit protected methods.
 */
class ParentCallChain_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'PHP-PARENT-CHAIN-01';

    /** @var mixed Shared php-parser instance. */
    protected static $parser = null;

    /** @var array Parsed-AST cache keyed by absolute file path (value: Node[]|false). */
    protected static $ast_cache = [];

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Parent Call Chaining Enforcement';
    }

    public function get_description(): string
    {
        return 'Requires a method override to call parent::<method>() unless the nearest declaring '
            . 'ancestor method is abstract or marked #[Replaceable]';
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
     * Runs during the manifest scan: a missing parent-call is a FATAL
     * manifest-time error (the end state per the design).
     *
     * A violation aborts the manifest build via YoureDoingItWrongException and
     * sets the manifest_is_bad poison flag, forcing a full rebuild + re-fire on
     * every subsequent load until the offending override is fixed (or the parent
     * method is marked #[Replaceable]).
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Cross-file rule: needs full manifest context (lineage + ancestor files).
     */
    public function is_incremental(): bool
    {
        return false;
    }

    /**
     * Runs once per build. Scopes validated classes to the files that changed in
     * this scan (falling back to the full manifest when nothing is flagged changed,
     * i.e. a full rebuild), and uses the manifest for lineage / ancestor lookups.
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;
        if ($already_checked) {
            return;
        }
        $already_checked = true;

        $files = Manifest::get_all();
        if (empty($files)) {
            return;
        }

        // Scope to changed files; empty => full rebuild => validate everything.
        $changed = Manifest::get_changed_files();
        $changed_lookup = null;
        if (!empty($changed)) {
            $changed_lookup = [];
            foreach ($changed as $rel) {
                $changed_lookup[str_replace('\\', '/', $rel)] = true;
            }
        }

        foreach ($files as $rel_path => $file_metadata) {
            // Only PHP class files carry an override obligation.
            if (($file_metadata['extension'] ?? '') !== 'php') {
                continue;
            }
            if (empty($file_metadata['class'])) {
                continue;
            }

            if ($changed_lookup !== null) {
                $normalized_rel = str_replace('\\', '/', $rel_path);
                if (!isset($changed_lookup[$normalized_rel])) {
                    continue;
                }
            }

            $class_name = $file_metadata['class'];
            $abs_file = base_path($rel_path);

            // Build the nearest -> root ancestry (class name + absolute file path)
            // from the manifest. Vendor ancestors are naturally absent: the
            // manifest never scans vendor/, so php_get_lineage terminates at the
            // last framework/app ancestor and php_find_class throws for the rest -
            // an override of a vendor method is therefore never flagged.
            $ancestry = [];
            foreach (Manifest::php_get_lineage($class_name) as $ancestor_name) {
                try {
                    $ancestor_rel = Manifest::php_find_class($ancestor_name);
                } catch (\Throwable $e) {
                    // Not in the manifest (vendor or unresolved) - stop climbing.
                    break;
                }
                $ancestry[] = [
                    'class' => $ancestor_name,
                    'file' => base_path($ancestor_rel),
                ];
            }

            $this->evaluate_class_methods($abs_file, $class_name, $ancestry);
        }
    }

    /**
     * Core validation for one child class against an explicit nearest -> root
     * ancestry (each entry: ['class' => simpleName, 'file' => absolutePath]).
     *
     * This is the testable seam: production builds the ancestry from the manifest;
     * tests build it from synthetic fixture files so lineage resolution, nearest-
     * declarer anchoring, abstract/#[Replaceable] exemption, and parent-call
     * detection are all exercised over real AST without touching the manifest.
     */
    private function evaluate_class_methods(string $child_file, string $child_class, array $ancestry): void
    {
        $child_node = $this->find_class_node($child_file, $child_class);
        if ($child_node === null) {
            // Cannot locate/parse the child class - nothing to enumerate.
            return;
        }

        $contents = @file_get_contents($child_file);
        $lines = $contents === false ? [] : explode("\n", $contents);
        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        foreach ($child_node->getMethods() as $child_method) {
            // Abstract / bodiless declarations cannot call parent - not an override
            // in the calling sense.
            if ($child_method->stmts === null) {
                continue;
            }

            $method_name = $child_method->name->toString();

            // Find the nearest ancestor that DECLARES this method.
            $declarer = $this->find_nearest_declarer($ancestry, $method_name);
            if ($declarer === null) {
                // Fresh method, or overrides a vendor method the manifest cannot
                // see - no chaining obligation.
                continue;
            }

            // Exempt: the nearest declarer is abstract (cannot call it).
            if ($declarer['method']->isAbstract()) {
                continue;
            }

            // Exempt: the nearest declarer opted out via #[Replaceable].
            if ($this->method_is_replaceable($declarer['method'])) {
                continue;
            }

            // Obligation stands: the child body must call parent::<method>().
            if ($this->method_body_calls_parent($child_method, $method_name)) {
                continue;
            }

            // Documented-unsupported edge syntaxes (callable-array,
            // call_user_func('parent::x'), explicit parent-classname) escape via
            // @PHP-PARENT-CHAIN-01-EXCEPTION.
            if ($this->method_has_exception($child_method, $lines, $marker)) {
                continue;
            }

            $line = $child_method->getStartLine();
            $ancestor_class = $declarer['class'];
            $code_snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

            $message = "{$child_class}::{$method_name}() overrides {$ancestor_class}::{$method_name}() "
                . "but does not call parent::{$method_name}().";

            $suggestion = "Call parent::{$method_name}(...) somewhere in {$child_class}::{$method_name}(), "
                . "OR mark {$ancestor_class}::{$method_name}() #[Replaceable] if overriders are meant to "
                . "fully replace it (no parent call).";

            $this->add_violation(
                $child_file,
                $line,
                $message,
                $code_snippet,
                $suggestion,
                'critical'
            );
        }
    }

    /**
     * Walk the nearest -> root ancestry and return the first ancestor that declares
     * $method_name, as ['class' => simpleName, 'method' => ClassMethod]. Returns
     * null when no ancestry class declares it.
     *
     * An unparseable/unlocatable ancestor file is skipped (we cannot confirm a
     * declaration there); climbing continues to the next ancestor.
     */
    private function find_nearest_declarer(array $ancestry, string $method_name): ?array
    {
        foreach ($ancestry as $ancestor) {
            $ancestor_node = $this->find_class_node($ancestor['file'], $ancestor['class']);
            if ($ancestor_node === null) {
                continue;
            }

            $method = $ancestor_node->getMethod($method_name);
            if ($method !== null) {
                return ['class' => $ancestor['class'], 'method' => $method];
            }
        }

        return null;
    }

    /**
     * Structurally determine whether $method's body calls parent::<method_name>().
     *
     * Recurses into closures/arrow-fns (NodeFinder walks the whole subtree). Keeps
     * same-name equality (owner decision 6): parent::otherMethod() does NOT satisfy.
     * A dynamic-name call parent::{$x}() COUNTS as satisfied - the parent IS
     * invoked and the name is unprovable, so failing open is correct.
     */
    private function method_body_calls_parent(Node\Stmt\ClassMethod $method, string $method_name): bool
    {
        if ($method->stmts === null) {
            return false;
        }

        $node_finder = new NodeFinder();
        $static_calls = $node_finder->findInstanceOf($method->stmts, Node\Expr\StaticCall::class);

        foreach ($static_calls as $call) {
            if (!($call->class instanceof Node\Name)) {
                continue;
            }
            if (strcasecmp($call->class->toString(), 'parent') !== 0) {
                continue;
            }

            if ($call->name instanceof Node\Identifier) {
                if (strcasecmp($call->name->toString(), $method_name) === 0) {
                    return true;
                }
                // parent::someOtherMethod() - not this method's chain.
                continue;
            }

            // parent::{$dynamic}() - the parent is invoked; count as satisfied.
            return true;
        }

        return false;
    }

    /**
     * Whether a method node carries the #[Replaceable] marker attribute. Read by
     * name from the AST attribute groups (the attribute class is never defined -
     * framework marker-attribute convention).
     */
    private function method_is_replaceable(Node\Stmt\ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $parts = explode('\\', $attr->name->toString());
                $simple = end($parts);
                if (strcasecmp($simple, 'Replaceable') === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Per-method exception detection for @PHP-PARENT-CHAIN-01-EXCEPTION. Recognizes
     * the marker in the method's attached comments/docblock, on the method's
     * declaration line, or on the line immediately preceding it. (Whole-file
     * exceptions are handled generically by CodeQualityChecker before the rule
     * ever runs.)
     */
    private function method_has_exception(Node\Stmt\ClassMethod $method, array $lines, string $marker): bool
    {
        foreach ($method->getComments() as $comment) {
            if (str_contains($comment->getText(), $marker)) {
                return true;
            }
        }

        $start_line = $method->getStartLine();
        $index = $start_line - 1;

        if ($index >= 0 && isset($lines[$index]) && str_contains($lines[$index], $marker)) {
            return true;
        }
        if ($index - 1 >= 0 && isset($lines[$index - 1]) && str_contains($lines[$index - 1], $marker)) {
            return true;
        }

        return false;
    }

    /**
     * Locate the ClassLike node named $class_name inside $abs_file. Prefers a
     * case-insensitive name match; parses (and caches) the file via nikic/php-parser.
     * Returns null when the file is missing/unparseable or the class is absent
     * (a missing/unparseable ancestor simply is not treated as a declarer).
     */
    private function find_class_node(string $abs_file, string $class_name): ?Node\Stmt\ClassLike
    {
        $ast = $this->parse_file($abs_file);
        if ($ast === null) {
            return null;
        }

        $node_finder = new NodeFinder();
        $class_likes = $node_finder->findInstanceOf($ast, Node\Stmt\ClassLike::class);

        foreach ($class_likes as $class_like) {
            if ($class_like->name !== null
                && strcasecmp($class_like->name->toString(), $class_name) === 0) {
                return $class_like;
            }
        }

        return null;
    }

    /**
     * Parse a file into an AST (Node[]), cached per absolute path. Returns null on
     * a missing file or a parse error (fail-closed: an unverifiable file yields no
     * usable declarations rather than a guessed one).
     */
    private function parse_file(string $abs_file): ?array
    {
        $key = $abs_file;

        if (array_key_exists($key, static::$ast_cache)) {
            $cached = static::$ast_cache[$key];

            return $cached === false ? null : $cached;
        }

        if (!is_file($abs_file)) {
            static::$ast_cache[$key] = false;

            return null;
        }

        $code = @file_get_contents($abs_file);
        if ($code === false) {
            static::$ast_cache[$key] = false;

            return null;
        }

        try {
            $ast = $this->get_parser()->parse($code);
        } catch (Error $error) {
            static::$ast_cache[$key] = false;

            return null;
        }

        if (!$ast) {
            static::$ast_cache[$key] = false;

            return null;
        }

        static::$ast_cache[$key] = $ast;

        return $ast;
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
