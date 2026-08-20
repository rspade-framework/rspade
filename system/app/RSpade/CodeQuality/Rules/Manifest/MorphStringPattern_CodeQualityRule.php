<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * POLY-01 - the Laravel string-morph pattern.
 *
 * RSpade stores a polymorphic discriminator as a BIGINT type-ref id, never as a
 * VARCHAR class name. The shape is ALWAYS a pair:
 *
 *     {relation}_type   BIGINT   - declared in the owning model's $type_ref_columns
 *     {relation}_id     BIGINT
 *
 * With the _type column declared, STOCK Eloquent morph relations work unchanged:
 * Type_Ref_Registry::register_morph_map() registers each type-ref integer id as a
 * morph-map alias next to the class name, so morphTo() resolving the RAW integer
 * attribute lands on the right class, and Rsx_Type_Ref_Cast converts the class-name
 * string a write produces back into the integer. Nothing about the relation is
 * special - only the declaration is.
 *
 * WITHOUT the declaration you have Laravel's stock string morph: a VARCHAR column
 * repeating a class name on every row, outside the type-ref registry, invisible to
 * the query-builder conversion and to joinMorph(). It half-works, which is the
 * problem - it reads fine and silently matches nothing the moment anything else in
 * the framework treats the column as a type ref.
 *
 * Detected (AST, never a regex over source):
 *   1. morphTo()      - the _type column lives on the DECLARING model. Flagged when
 *                       that model's $type_ref_columns does not declare it.
 *   2. morphOne() / morphMany()
 *                     - the _type column lives on the RELATED model (argument 1).
 *                       Flagged when THAT model does not declare it.
 *   3. morphToMany() / morphedByMany()
 *                     - the _type column lives on a PIVOT TABLE that no model owns,
 *                       so no $type_ref_columns declaration can ever apply to it.
 *                       Always flagged: model the join table as a real model with a
 *                       {relation}_type / {relation}_id pair.
 *   4. A morph type column whose name does not end in `_type` (the pair standard).
 *
 * NOT detected (deliberately): a morph verb whose owning model cannot be resolved
 * statically (a variable class name, a dynamic relation name). This rule is FATAL,
 * so an unprovable case is skipped rather than guessed at.
 *
 * NOT covered: migrations. The manifest does not scan migration directories, and a
 * migration's column definitions live inside raw SQL strings - so a `VARCHAR *_type`
 * column is caught HERE, at the model that relates over it, not at the migration
 * that created it. (`type_id`-style enum columns are untouched: this rule keys on the
 * `_type` suffix, never on `type_id`.)
 *
 * Suppressed by @POLY-01-EXCEPTION on the offending method (docblock, declaration
 * line, or the line above) or anywhere in the file - for a genuinely external,
 * non-RSpade table whose VARCHAR discriminator is not ours to change.
 *
 * FATAL at manifest build: a violation aborts the build via YoureDoingItWrongException
 * and poisons the manifest until the source is fixed.
 */
class MorphStringPattern_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'POLY-01';

    /** Morph verbs whose _type column lives on the RELATED model (argument 1). */
    private const RELATED_SIDE_VERBS = ['morphOne', 'morphMany'];

    /** Morph verbs whose _type column lives on a pivot table no model owns. */
    private const PIVOT_VERBS = ['morphToMany', 'morphedByMany'];

    /** @var mixed Shared php-parser instance. */
    protected static $parser = null;

    /**
     * Parsed-AST cache keyed by absolute file path (value: Node[]|false), and resolved
     * $type_ref_columns keyed by simple class name. Per-INSTANCE, not static: the rule is
     * instantiated once per run, so the caching is just as effective, and a stale entry can
     * never survive into a second run (which would let a fixture class name from one test
     * answer for a different class in the next).
     *
     * @var array
     */
    private $ast_cache = [];

    /** @var array */
    private $type_ref_cache = [];

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Polymorphic Type-Ref Declaration';
    }

    public function get_description(): string
    {
        return 'Flags a Laravel string-morph relationship: a morphTo/morphOne/morphMany whose '
            . '*_type discriminator column is not declared in the owning model\'s $type_ref_columns, '
            . 'and pivot-table morphs which no type-ref declaration can cover';
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
     * Runs during the manifest scan: an undeclared morph column is fatal, not advice.
     * The failure mode is silent (the relation reads plausibly and matches nothing
     * once the column is treated as a type ref), so it must be caught at build time.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Cross-file rule: morphOne/morphMany are validated against the RELATED model's
     * declaration, which lives in another file.
     */
    public function is_incremental(): bool
    {
        return false;
    }

    /**
     * Runs once per build. Scopes the files it validates to those that changed in this
     * scan (falling back to the whole manifest on a full rebuild), and uses the manifest
     * to locate related models and climb lineage for an inherited declaration.
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

            $normalized_rel = str_replace('\\', '/', $rel_path);

            // Meta-code ABOUT the pattern (this rule, its fixtures) is not an instance of it.
            if (str_contains($normalized_rel, '/CodeQuality/')) {
                continue;
            }

            if ($changed_lookup !== null && !isset($changed_lookup[$normalized_rel])) {
                continue;
            }

            $this->evaluate_file(base_path($rel_path), $file_metadata['class']);
        }
    }

    /**
     * Validate every morph call inside one class file.
     *
     * This is the testable seam: production passes manifest-resolved paths, tests pass
     * synthetic fixture files, so argument parsing, owner resolution, lineage climbing
     * and the exception marker are all exercised over real AST.
     */
    public function evaluate_file(string $abs_file, string $class_name): void
    {
        $class_node = $this->find_class_node($abs_file, $class_name);
        if ($class_node === null) {
            return;
        }

        $contents = @file_get_contents($abs_file);
        if ($contents === false) {
            return;
        }

        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        // Whole-file exception. CodeQualityChecker applies this generically for rsx:check,
        // but the manifest-time driver does not - so honor it here too.
        if (str_contains($contents, $marker)) {
            return;
        }

        $lines = explode("\n", $contents);
        $node_finder = new NodeFinder();

        foreach ($class_node->getMethods() as $method) {
            if ($method->stmts === null) {
                continue;
            }

            $calls = $node_finder->findInstanceOf($method->stmts, Node\Expr\MethodCall::class);
            foreach ($calls as $call) {
                if (!$call->name instanceof Node\Identifier) {
                    continue;
                }

                $verb = $call->name->toString();
                $finding = $this->classify_call($verb, $call, $class_node, $class_name, $method);
                if ($finding === null) {
                    continue;
                }

                if ($this->method_has_exception($method, $lines, $marker)) {
                    continue;
                }

                $line = $call->getStartLine();
                $snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

                $this->add_violation(
                    $abs_file,
                    $line,
                    $finding['message'],
                    $snippet,
                    $finding['suggestion'],
                    'critical'
                );
            }
        }
    }

    /**
     * Classify one morph call. Returns null when the call is fine, is not a morph verb,
     * or cannot be resolved statically.
     *
     * @return array{message:string,suggestion:string}|null
     */
    private function classify_call(
        string $verb,
        Node\Expr\MethodCall $call,
        Node\Stmt\ClassLike $class_node,
        string $class_name,
        Node\Stmt\ClassMethod $method
    ): ?array {
        // --- pivot morphs: no model owns the pivot table's _type column ------------
        if (in_array($verb, self::PIVOT_VERBS, true)) {
            $name = $this->literal_arg($call, 1) ?? '<relation>';

            return [
                'message' => sprintf(
                    "%s::%s() uses %s('%s'). The '%s_type' discriminator lives on a PIVOT TABLE, "
                        . "which no model owns - so no \$type_ref_columns declaration can ever apply to it "
                        . "and the column is a stock Laravel VARCHAR class-name morph.",
                    $class_name,
                    $method->name->toString(),
                    $verb,
                    $name,
                    $name
                ),
                'suggestion' => $this->conversion_recipe(
                    "Model the join table as a real RSpade model with a {$name}_type BIGINT / "
                        . "{$name}_id BIGINT pair declared in its \$type_ref_columns, and relate to it "
                        . "with morphMany()/belongsTo() instead of a pivot morph."
                ),
            ];
        }

        $is_related_side = in_array($verb, self::RELATED_SIDE_VERBS, true);
        if (!$is_related_side && $verb !== 'morphTo') {
            return null;
        }

        // Resolve the model that OWNS the _type column, and the column name.
        if ($is_related_side) {
            // morphOne($related, $name, $type = null, ...) - column on $related's table.
            $owner = $this->class_name_arg($call, 0);
            $relation_name = $this->literal_arg($call, 1);
            $explicit_type = $this->literal_arg($call, 2);

            if ($owner === null || ($relation_name === null && $explicit_type === null)) {
                return null; // Not statically resolvable - never guess on a fatal rule.
            }

            // HasRelationships::getMorphs() - no snake_case pass on this side.
            $type_column = $explicit_type ?? ($relation_name . '_type');
        } else {
            // morphTo($name = null, $type = null, ...) - column on THIS model's table.
            // The receiver must be $this: any other receiver names a model we cannot
            // attribute the declaration to.
            if (!($call->var instanceof Node\Expr\Variable) || $call->var->name !== 'this') {
                return null;
            }

            $owner = $class_name;
            $relation_name = $this->literal_arg($call, 0) ?? $method->name->toString();
            $explicit_type = $this->literal_arg($call, 1);

            // HasRelationships::morphTo() snake_cases the relation name before getMorphs().
            $type_column = $explicit_type ?? ($this->snake($relation_name) . '_type');
        }

        // --- the pair-naming standard --------------------------------------------
        if (!str_ends_with($type_column, '_type')) {
            return [
                'message' => sprintf(
                    "%s::%s() declares a polymorphic reference on column '%s'. A polymorphic "
                        . "reference is ALWAYS the pair {relation}_type + {relation}_id.",
                    $class_name,
                    $method->name->toString(),
                    $type_column
                ),
                'suggestion' => "Rename the columns to {relation}_type / {relation}_id (both BIGINT) "
                    . "and declare the _type column in the owning model's \$type_ref_columns.\n"
                    . "See: php artisan rsx:man polymorphic.",
            ];
        }

        // --- the declaration ------------------------------------------------------
        $declared = $this->type_ref_columns_for($owner, $owner === $class_name ? $class_node : null);
        if ($declared === null) {
            return null; // Owning model not resolvable in the manifest - cannot judge.
        }

        if (in_array($type_column, $declared, true)) {
            return null;
        }

        return [
            'message' => sprintf(
                "%s::%s() relates polymorphically over '%s.%s', but %s does not declare "
                    . "'%s' in \$type_ref_columns. That is Laravel's string-morph pattern: a VARCHAR "
                    . "column repeating a class name on every row, outside the type-ref registry and "
                    . "invisible to the query-builder conversion.",
                $class_name,
                $method->name->toString(),
                $owner,
                $type_column,
                $owner,
                $type_column
            ),
            'suggestion' => $this->conversion_recipe(
                "Declare it on {$owner}:\n"
                    . "    protected static \$type_ref_columns = ['{$type_column}'];"
            ),
        ];
    }

    /**
     * The shared remediation body: what the correct shape is, and how to convert an
     * existing VARCHAR column (and its data) to it.
     */
    private function conversion_recipe(string $head): string
    {
        return $head . "\n"
            . "\n"
            . "Both columns are BIGINT. Converting an existing VARCHAR column, in a migration:\n"
            . "    UPDATE my_table t JOIN _type_refs r ON r.class_name = t.thing_type\n"
            . "        SET t.thing_type = r.id;\n"
            . "    ALTER TABLE my_table MODIFY thing_type BIGINT NULL;\n"
            . "Every class the column names must have a _type_refs row before that UPDATE - ids are\n"
            . "auto-registered on first use by Type_Ref_Registry::class_to_id('My_Model'), so call it\n"
            . "once per distinct class in the migration (or write the row) before mapping.\n"
            . "Rows whose class no longer exists have no id to map to: decide explicitly (NULL them,\n"
            . "or delete them) rather than leaving a value the registry cannot resolve.\n"
            . "\n"
            . "With the declaration in place, stock Eloquent morph relations work unchanged.\n"
            . "See: php artisan rsx:man polymorphic.";
    }

    /**
     * The $type_ref_columns list for a simple class name, climbing lineage until a class
     * DECLARES the property (an inherited empty declaration from Rsx_Model_Abstract is a
     * legitimate answer: "this model has no type-ref columns").
     *
     * Returns null when the class cannot be located/parsed at all - the rule then declines
     * to judge rather than assuming an empty list.
     *
     * @return array<int,string>|null
     */
    private function type_ref_columns_for(string $class_name, ?Node\Stmt\ClassLike $known_node): ?array
    {
        if (array_key_exists($class_name, $this->type_ref_cache)) {
            return $this->type_ref_cache[$class_name];
        }

        $result = null;

        if ($known_node !== null) {
            $result = $this->read_type_ref_property($known_node);
        }

        if ($result === null) {
            foreach ($this->lineage_with_self($class_name) as $ancestor) {
                $node = $this->find_class_node($ancestor['file'], $ancestor['class']);
                if ($node === null) {
                    continue;
                }

                $declared = $this->read_type_ref_property($node);
                if ($declared !== null) {
                    $result = $declared;
                    break;
                }

                // Located, but does not declare the property - keep climbing.
            }
        }

        $this->type_ref_cache[$class_name] = $result;

        return $result;
    }

    /**
     * [{class, file}] for $class_name itself followed by its manifest-visible ancestry.
     *
     * @return array<int,array{class:string,file:string}>
     */
    private function lineage_with_self(string $class_name): array
    {
        $chain = [];

        try {
            $chain[] = ['class' => $class_name, 'file' => base_path(Manifest::php_find_class($class_name))];
        } catch (\Throwable $e) {
            return [];
        }

        foreach (Manifest::php_get_lineage($class_name) as $ancestor_name) {
            try {
                $chain[] = ['class' => $ancestor_name, 'file' => base_path(Manifest::php_find_class($ancestor_name))];
            } catch (\Throwable $e) {
                break;
            }
        }

        return $chain;
    }

    /**
     * The literal string values of a class's own $type_ref_columns declaration, or null
     * when the class does not declare the property.
     *
     * @return array<int,string>|null
     */
    private function read_type_ref_property(Node\Stmt\ClassLike $class_node): ?array
    {
        foreach ($class_node->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() !== 'type_ref_columns') {
                    continue;
                }

                $columns = [];
                if ($prop->default instanceof Node\Expr\Array_) {
                    foreach ($prop->default->items as $item) {
                        if ($item !== null && $item->value instanceof Node\Scalar\String_) {
                            $columns[] = $item->value->value;
                        }
                    }
                }

                return $columns;
            }
        }

        return null;
    }

    /**
     * The literal string value of argument $index, or null when it is absent or not a
     * plain string literal.
     */
    private function literal_arg(Node\Expr\MethodCall $call, int $index): ?string
    {
        $arg = $call->args[$index] ?? null;
        if (!$arg instanceof Node\Arg || $arg->name !== null) {
            return null; // Positional args only; a named arg is not resolvable here.
        }

        return $arg->value instanceof Node\Scalar\String_ ? $arg->value->value : null;
    }

    /**
     * The SIMPLE class name named by argument $index, accepting both Foo_Model::class and
     * the 'Foo_Model' string form. Null when it is neither.
     */
    private function class_name_arg(Node\Expr\MethodCall $call, int $index): ?string
    {
        $arg = $call->args[$index] ?? null;
        if (!$arg instanceof Node\Arg || $arg->name !== null) {
            return null;
        }

        $value = $arg->value;

        if ($value instanceof Node\Expr\ClassConstFetch
            && $value->class instanceof Node\Name
            && $value->name instanceof Node\Identifier
            && strcasecmp($value->name->toString(), 'class') === 0
        ) {
            $parts = explode('\\', $value->class->toString());

            return end($parts);
        }

        if ($value instanceof Node\Scalar\String_) {
            $parts = explode('\\', $value->value);

            return end($parts);
        }

        return null;
    }

    /**
     * The exact transform HasRelationships::morphTo() applies to the relation name before
     * deriving the column names - the same Str::snake() call, so the rule can never disagree
     * with Eloquent about which column a relation reads.
     */
    private function snake(string $value): string
    {
        return \Illuminate\Support\Str::snake($value);
    }

    /**
     * Per-method exception detection for @POLY-01-EXCEPTION: in the method's docblock,
     * on its declaration line, or on the line immediately above it.
     */
    private function method_has_exception(Node\Stmt\ClassMethod $method, array $lines, string $marker): bool
    {
        foreach ($method->getComments() as $comment) {
            if (str_contains($comment->getText(), $marker)) {
                return true;
            }
        }

        $index = $method->getStartLine() - 1;

        if ($index >= 0 && isset($lines[$index]) && str_contains($lines[$index], $marker)) {
            return true;
        }
        if ($index - 1 >= 0 && isset($lines[$index - 1]) && str_contains($lines[$index - 1], $marker)) {
            return true;
        }

        return false;
    }

    /**
     * Locate the ClassLike node named $class_name inside $abs_file, or null when the file
     * is missing/unparseable or the class is absent.
     */
    private function find_class_node(string $abs_file, string $class_name): ?Node\Stmt\ClassLike
    {
        $ast = $this->parse_file($abs_file);
        if ($ast === null) {
            return null;
        }

        $node_finder = new NodeFinder();

        foreach ($node_finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class_like) {
            if ($class_like->name !== null && strcasecmp($class_like->name->toString(), $class_name) === 0) {
                return $class_like;
            }
        }

        return null;
    }

    /**
     * Parse a file into an AST (Node[]), cached per absolute path. Null on a missing file
     * or a parse error (a syntax error is reported by the linter, not by this rule).
     */
    private function parse_file(string $abs_file): ?array
    {
        if (array_key_exists($abs_file, $this->ast_cache)) {
            $cached = $this->ast_cache[$abs_file];

            return $cached === false ? null : $cached;
        }

        if (!is_file($abs_file)) {
            $this->ast_cache[$abs_file] = false;

            return null;
        }

        $code = @file_get_contents($abs_file);
        if ($code === false) {
            $this->ast_cache[$abs_file] = false;

            return null;
        }

        try {
            $ast = $this->get_parser()->parse($code);
        } catch (Error $error) {
            $this->ast_cache[$abs_file] = false;

            return null;
        }

        if (!$ast) {
            $this->ast_cache[$abs_file] = false;

            return null;
        }

        $this->ast_cache[$abs_file] = $ast;

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
