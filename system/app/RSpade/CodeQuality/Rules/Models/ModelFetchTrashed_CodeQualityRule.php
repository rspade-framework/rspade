<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-FETCH-TRASHED-01 - flags withTrashed() inside a model's fetch()/portal_fetch() body.
 *
 * The ORM fetch contract is that every record it serves is non-deleted. A scope-stripped
 * lookup in one of those bodies breaks the batch preload (which runs under the model's
 * default scopes) and hands client code a soft-deleted record it is entitled to assume it
 * will never see. A deleted record belongs on an explicit #[Ajax_Endpoint].
 *
 * Detection is AST-based over the ORIGINAL file: every method named fetch/portal_fetch on a
 * model class is walked for a withTrashed() call at any depth of its body (a closure inside
 * the body included - the record still leaves through the same return).
 *
 * Suppressed by @MODEL-FETCH-TRASHED-01-EXCEPTION on the call line, the line above it, or
 * anywhere in the file (the checker's own file-level handling).
 */
class ModelFetchTrashed_CodeQualityRule extends CodeQualityRule_Abstract
{
    protected static $parser = null;

    /**
     * The ORM fetch surfaces: the staff/API one and the portal one.
     */
    private const FETCH_METHODS = ['fetch', 'portal_fetch'];

    public function get_id(): string
    {
        return 'MODEL-FETCH-TRASHED-01';
    }

    public function get_name(): string
    {
        return 'Model Fetch Trashed Record Check';
    }

    public function get_description(): string
    {
        return 'Flags withTrashed() inside a model fetch()/portal_fetch() body - the ORM fetch serves non-deleted records only';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function is_called_during_manifest_scan(): bool
    {
        return false; // Only run during rsx:check.
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
        if (!str_ends_with($file_path, '.php')) {
            return;
        }

        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        // The fetch surfaces live on models; a fetch() elsewhere (a DataGrid, a service) is
        // a different method that happens to share the name.
        try {
            if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        // Parse the ORIGINAL file: the sanitized contents the checker passes has comments
        // stripped, and the exception markers are comments.
        $original = file_get_contents($file_path);
        if ($original === false) {
            return;
        }

        try {
            $ast = $this->get_parser()->parse($original);
        } catch (Error $error) {
            return; // Unparseable - the syntax linter reports it.
        }

        if (!$ast) {
            return;
        }

        $lines = explode("\n", $original);
        $node_finder = new NodeFinder();

        foreach ($node_finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
            $method_name = $method->name->name;
            if (!in_array($method_name, self::FETCH_METHODS, true)) {
                continue;
            }

            if ($method->stmts === null) {
                continue; // Abstract declaration - no body.
            }

            foreach ($this->find_trashed_calls($method->stmts, $node_finder) as $call) {
                $line = $call->getLine();

                if ($this->line_has_exception($lines, $line)) {
                    continue;
                }

                $this->add_violation(
                    $file_path,
                    $line,
                    $this->build_message($method_name),
                    trim($lines[$line - 1] ?? 'withTrashed()'),
                    $this->build_suggestion(),
                    'high'
                );
            }
        }
    }

    /**
     * Every withTrashed() call inside a method body, in source order.
     *
     * @return array<int, Node\Expr\MethodCall|Node\Expr\StaticCall>
     */
    private function find_trashed_calls(array $stmts, NodeFinder $node_finder): array
    {
        return $node_finder->find($stmts, function (Node $node) {
            if (!$node instanceof Node\Expr\MethodCall && !$node instanceof Node\Expr\StaticCall) {
                return false;
            }

            return $node->name instanceof Node\Identifier && $node->name->name === 'withTrashed';
        });
    }

    /**
     * Whether the violating line, or the line above it, carries the exception marker.
     */
    private function line_has_exception(array $lines, int $line): bool
    {
        $marker = '@' . $this->get_id() . '-EXCEPTION';

        foreach ([$line - 1, $line - 2] as $index) {
            if ($index >= 0 && isset($lines[$index]) && str_contains($lines[$index], $marker)) {
                return true;
            }
        }

        return false;
    }

    private function build_message(string $method_name): string
    {
        return "withTrashed() inside {$method_name}() is not allowed. The ORM fetch contract is that "
            . "every record served to the client is non-deleted:\n\n"
            . "  1. The batch endpoint preloads the requested ids in one IN-clause query under the\n"
            . "     model's DEFAULT scopes, so a scope-stripped lookup in this body always misses the\n"
            . "     preload and silently degrades every fetch of this model to an individual query.\n"
            . "  2. Client code is entitled to assume an ORM-fetched record is not soft-deleted.";
    }

    private function build_suggestion(): string
    {
        $lines = [];
        $lines[] = "Load the record under the model's default scopes:";
        $lines[] = '';
        $lines[] = '    $record = static::find($id);';
        $lines[] = '';
        $lines[] = 'To serve a deleted record, write an explicit #[Ajax_Endpoint] for that screen,';
        $lines[] = 'where the server controls authorization for that specific situation. The worked';
        $lines[] = 'example is Frontend_Clients_Controller::fetch_deleted (the deleted-client view):';
        $lines[] = 'it loads the record withTrashed(), requires trashed(), and answers with no record';
        $lines[] = 'for anything else - the screen asks it only after the ORM fetch reports not-found.';
        $lines[] = '';
        $lines[] = "Suppress with rationale: // @{$this->get_id()}-EXCEPTION - <why>";

        return implode("\n", $lines);
    }
}
