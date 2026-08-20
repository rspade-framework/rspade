<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * SEALED-01 - #[Sealed] properties may not be redeclared by a subclass.
 *
 * #[Sealed] is a marker-only attribute (NEVER define the attribute class - this rule
 * reads it by name from the AST, per framework convention). Placed on a PROPERTY, it
 * declares that property the sole property of the class that declared it: no descendant
 * may redeclare it, at any depth.
 *
 * WHY PROPERTIES ONLY. Methods already have `final`, which PHP itself enforces at
 * compile time - a second mechanism there would be a dual implementation. PHP has no
 * `final` for properties, so a subclass can silently redeclare one and change a value
 * the parent's own logic depends on. That is the gap #[Sealed] fills, and it is the
 * only gap it fills; putting it anywhere else is a misuse.
 *
 * The motivating case is Rsx_Actor_Model_Abstract::$actor_soft_deletes - the declared
 * soft-delete mandate for identity models. Redeclaring it is exactly the move someone
 * makes when trying to opt an actor out of soft deletes, so it must fail LOUD at build
 * time rather than appear to work.
 *
 * A REDECLARATION is what is flagged - not reading, not writing. `static::$prop = x`
 * anywhere is untouched; `public static $prop = x;` in a subclass body is the
 * violation. Redeclaring with the SAME value is still a violation: the point is that
 * the declaration lives in exactly one place.
 *
 * Detection is AST-based (nikic/php-parser), never a regex over raw source, so a
 * comment or string mentioning the property cannot spoof it, and lineage comes from
 * the manifest (php_get_lineage). Vendor ancestors are naturally out of scope - the
 * manifest never scans vendor/.
 *
 * Honors @SEALED-01-EXCEPTION at file level (via CodeQualityChecker) and, with
 * precision, on or immediately above the offending property declaration.
 */
class SealedProperty_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'SEALED-01';

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
        return 'Sealed Property Redeclaration';
    }

    public function get_description(): string
    {
        return 'A subclass may not redeclare a property an ancestor marked #[Sealed]';
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
     * Runs during the manifest scan: a sealed-property redeclaration is a FATAL
     * manifest-time error. The redeclared property compiles and runs fine - it just
     * quietly detaches the subclass from a declaration the base class's contract
     * rests on - so build time is the only place it can be caught.
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
     * Runs once per build. Scopes validated classes to the files that changed in this
     * scan (falling back to the full manifest when nothing is flagged changed, i.e. a
     * full rebuild), and uses the manifest for lineage / ancestor lookups.
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

            // Nearest -> root ancestry (class name + absolute file path) from the
            // manifest. Climbing stops at the last manifest-visible ancestor.
            $ancestry = [];
            foreach (Manifest::php_get_lineage($class_name) as $ancestor_name) {
                try {
                    $ancestor_rel = Manifest::php_find_class($ancestor_name);
                } catch (\Throwable $e) {
                    break;
                }
                $ancestry[] = [
                    'class' => $ancestor_name,
                    'file' => base_path($ancestor_rel),
                ];
            }

            if (empty($ancestry)) {
                continue;
            }

            $this->evaluate_class_properties($abs_file, $class_name, $ancestry);
        }
    }

    /**
     * Core validation for one child class against an explicit nearest -> root ancestry
     * (each entry: ['class' => simpleName, 'file' => absolutePath]).
     *
     * This is the testable seam: production builds the ancestry from the manifest;
     * tests build it from synthetic fixture files, so seal discovery, depth-N
     * inheritance and exception markers are all exercised over real AST without
     * touching the manifest.
     */
    public function evaluate_class_properties(string $child_file, string $child_class, array $ancestry): void
    {
        $child_node = $this->find_class_node($child_file, $child_class);
        if ($child_node === null) {
            return;
        }

        $child_properties = $this->collect_properties($child_node);
        if (empty($child_properties)) {
            return;
        }

        // Sealed name => declaring class, collected across the WHOLE ancestry: a seal
        // binds every descendant, not just direct children.
        $sealed = [];
        foreach ($ancestry as $ancestor) {
            $ancestor_node = $this->find_class_node($ancestor['file'], $ancestor['class']);
            if ($ancestor_node === null) {
                continue;
            }

            foreach ($this->collect_properties($ancestor_node) as $name => $property) {
                if (isset($sealed[$name])) {
                    continue;
                }
                if ($this->property_is_sealed($property['node'])) {
                    $sealed[$name] = $ancestor['class'];
                }
            }
        }

        if (empty($sealed)) {
            return;
        }

        $contents = @file_get_contents($child_file);
        $lines = $contents === false ? [] : explode("\n", $contents);
        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        foreach ($child_properties as $name => $property) {
            if (!isset($sealed[$name])) {
                continue;
            }

            if ($this->property_has_exception($property['node'], $lines, $marker)) {
                continue;
            }

            $sealing_class = $sealed[$name];
            $line = $property['line'];
            $code_snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

            $message = "{$child_class} redeclares \${$name}, which {$sealing_class} marked #[Sealed].\n\n"
                . "A sealed property has exactly one declaration site. Redeclaring it detaches this "
                . "class from the contract {$sealing_class} declares through it - silently, because "
                . "the redeclaration compiles and runs.";

            $suggestion = "Delete the \${$name} declaration from {$child_class} and inherit it.\n"
                . "If this class genuinely needs a different value, that is a signal the base class's "
                . "contract does not fit it - change the contract on {$sealing_class} (or stop "
                . "extending it), rather than re-opening a sealed declaration.";

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
     * Every property DECLARED in a class node, as name => ['node' => Property, 'line'].
     *
     * A single `public $a, $b;` statement declares two properties from one node; both
     * are indexed, and both point at the same statement's line.
     *
     * @return array<string, array{node: Node\Stmt\Property, line: int}>
     */
    private function collect_properties(Node\Stmt\ClassLike $class_node): array
    {
        $properties = [];

        foreach ($class_node->stmts as $stmt) {
            if (!($stmt instanceof Node\Stmt\Property)) {
                continue;
            }

            foreach ($stmt->props as $prop) {
                $properties[$prop->name->toString()] = [
                    'node' => $stmt,
                    'line' => $stmt->getStartLine(),
                ];
            }
        }

        return $properties;
    }

    /**
     * Whether a property statement carries the #[Sealed] marker attribute. Read by
     * name from the AST attribute groups (the attribute class is never defined -
     * framework marker-attribute convention).
     */
    private function property_is_sealed(Node\Stmt\Property $property): bool
    {
        foreach ($property->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $parts = explode('\\', $attr->name->toString());
                $simple = end($parts);
                if (strcasecmp($simple, 'Sealed') === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Per-property exception detection for @SEALED-01-EXCEPTION: in the property's
     * attached comments/docblock, on its declaration line, or on the line immediately
     * before it. (Whole-file exceptions are handled generically by CodeQualityChecker
     * before the rule runs under rsx:check; this covers the manifest-time driver,
     * which does not.)
     */
    private function property_has_exception(Node\Stmt\Property $property, array $lines, string $marker): bool
    {
        foreach ($property->getComments() as $comment) {
            if (str_contains($comment->getText(), $marker)) {
                return true;
            }
        }

        $start_line = $property->getStartLine();
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
     * Locate the ClassLike node named $class_name inside $abs_file. Returns null when
     * the file is missing/unparseable or the class is absent (an unverifiable ancestor
     * simply contributes no seals).
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
     * Parse a file into an AST (Node[]), cached per absolute path. Returns null on a
     * missing file or a parse error.
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

        if ($ast === null) {
            static::$ast_cache[$key] = false;

            return null;
        }

        static::$ast_cache[$key] = $ast;

        return $ast;
    }

    /**
     * The shared php-parser instance.
     */
    private function get_parser()
    {
        if (static::$parser === null) {
            static::$parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        return static::$parser;
    }
}
