<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * RELATIONSHIP-OVERRIDE-01 - an override of an ancestor's #[Relationship] method must
 * itself carry #[Relationship].
 *
 * Rsx_Model_Abstract::get_relationships() unions the #[Relationship] methods of a model
 * and of every manifest-visible ancestor, because the manifest indexes methods per FILE.
 * A subclass that redeclares such a method WITHOUT the attribute does not remove the name
 * from that union - the ancestor still declares it - so the model reports a relationship
 * whose live implementation was never marked as one. The attribute is what the ORM stub
 * generator, the index analyzer and isRelation() all read; splitting the declaration from
 * the implementation makes every one of them describe a method that no longer exists in
 * the form they expect.
 *
 * Redeclaring the attribute costs one line and keeps the model's own file honest about
 * what it is: a reader of the subclass should not have to climb the lineage to learn that
 * a method is a relationship.
 *
 * Detection is a pure compiled-manifest walk - method attributes are already indexed, and
 * the lineage is the entries' extends_fqcn chain. No AST parsing is needed or done.
 *
 * Honors @RELATIONSHIP-OVERRIDE-01-EXCEPTION at file level (via CodeQualityChecker) and,
 * with precision, on or immediately above the offending method declaration.
 */
class RelationshipOverride_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'RELATIONSHIP-OVERRIDE-01';

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Relationship Override Redeclaration';
    }

    public function get_description(): string
    {
        return 'An override of an ancestor #[Relationship] method must redeclare #[Relationship]';
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
     * Runs during the manifest scan: the override compiles and runs, and the mismatch only
     * surfaces as a relationship that behaves oddly in generated code - build time is the
     * only place it is visible.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Cross-file rule: needs full manifest context (ancestor entries).
     */
    public function is_incremental(): bool
    {
        return false;
    }

    /**
     * Runs once per build. Scopes validated classes to the files that changed in this scan
     * (empty changed-set => full rebuild => validate everything).
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

        $by_fqcn = [];
        foreach ($files as $file_metadata) {
            if (isset($file_metadata['fqcn'])) {
                $by_fqcn[$file_metadata['fqcn']] = $file_metadata;
            }
        }

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
            if (empty($file_metadata['class']) || empty($file_metadata['fqcn'])) {
                continue;
            }
            if (empty($file_metadata['public_instance_methods'])) {
                continue;
            }

            if ($changed_lookup !== null) {
                $normalized_rel = str_replace('\\', '/', $rel_path);
                if (!isset($changed_lookup[$normalized_rel])) {
                    continue;
                }
            }

            $ancestor_relationships = $this->collect_ancestor_relationships($file_metadata, $by_fqcn);
            if (empty($ancestor_relationships)) {
                continue;
            }

            $this->evaluate_class_methods(
                base_path($rel_path),
                $file_metadata['class'],
                $file_metadata['public_instance_methods'],
                $ancestor_relationships
            );
        }
    }

    /**
     * Core validation for one class against an explicit map of ancestor-declared
     * relationship methods (method name => declaring class name).
     *
     * This is the testable seam: production builds the map by climbing extends_fqcn through
     * the manifest; tests build it directly, so the flag/clean/exception paths are exercised
     * without needing fixture classes in the manifest.
     *
     * @param array<string, array>  $own_methods            manifest-shaped public_instance_methods map
     * @param array<string, string> $ancestor_relationships method name => declaring class name
     */
    public function evaluate_class_methods(
        string $file,
        string $class_name,
        array $own_methods,
        array $ancestor_relationships
    ): void {
        $contents = @file_get_contents($file);
        $lines = $contents === false ? [] : explode("\n", $contents);

        foreach ($own_methods as $method_name => $method_data) {
            if (!isset($ancestor_relationships[$method_name])) {
                continue;
            }
            if (isset($method_data['attributes']['Relationship'])) {
                continue;
            }

            $line = (int) ($method_data['line'] ?? 0);
            if ($this->has_exception_marker($lines, $line)) {
                continue;
            }

            $declaring_class = $ancestor_relationships[$method_name];
            $code_snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

            $message = "{$class_name}::{$method_name}() overrides a #[Relationship] method declared by "
                . "{$declaring_class}, but does not redeclare the attribute.\n\n"
                . "get_relationships() unions the lineage, so {$method_name} is still reported as a "
                . "relationship of {$class_name} - by the ORM stub generator, the index analyzer and "
                . "isRelation() alike. The declaration and the implementation would live in different "
                . "classes, and only the ancestor's file would say what this method is.";

            $suggestion = "Add #[Relationship] to {$class_name}::{$method_name}().\n"
                . "If the override is deliberately NOT a relationship any more, it must not carry the "
                . "ancestor's name: rename it, or stop declaring the relationship on {$declaring_class}.";

            $this->add_violation(
                $file,
                $line > 0 ? $line : 1,
                $message,
                $code_snippet,
                $suggestion,
                'critical'
            );
        }
    }

    /**
     * Every #[Relationship] method declared by a manifest-visible ancestor, as
     * name => nearest declaring class. Climbing stops at the first ancestor with no
     * manifest entry (vendor classes are never scanned).
     *
     * @param array<string, array> $by_fqcn manifest entries keyed by FQCN
     * @return array<string, string>
     */
    private function collect_ancestor_relationships(array $file_metadata, array $by_fqcn): array
    {
        $relationships = [];
        $seen = [$file_metadata['fqcn'] => true];
        $parent_fqcn = $file_metadata['extends_fqcn'] ?? null;

        while ($parent_fqcn !== null && !isset($seen[$parent_fqcn]) && isset($by_fqcn[$parent_fqcn])) {
            $seen[$parent_fqcn] = true;
            $entry = $by_fqcn[$parent_fqcn];

            foreach ($entry['public_instance_methods'] ?? [] as $method_name => $method_data) {
                if (!isset($method_data['attributes']['Relationship'])) {
                    continue;
                }
                if (isset($relationships[$method_name])) {
                    continue;
                }
                $relationships[$method_name] = $entry['class'] ?? $parent_fqcn;
            }

            $parent_fqcn = $entry['extends_fqcn'] ?? null;
        }

        return $relationships;
    }

    /**
     * Per-method exception detection for @RELATIONSHIP-OVERRIDE-01-EXCEPTION: on the
     * declaration line, or anywhere in the contiguous comment/attribute block directly above
     * it. (Whole-file exceptions are handled generically by CodeQualityChecker before the
     * rule runs under rsx:check; this covers the manifest-time driver, which does not.)
     */
    private function has_exception_marker(array $lines, int $line): bool
    {
        if ($line <= 0) {
            return false;
        }

        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        if (isset($lines[$line - 1]) && str_contains($lines[$line - 1], $marker)) {
            return true;
        }

        for ($index = $line - 2; $index >= 0; $index--) {
            $text = trim($lines[$index] ?? '');
            if ($text === '') {
                break;
            }
            if (!str_starts_with($text, '*')
                && !str_starts_with($text, '/*')
                && !str_starts_with($text, '//')
                && !str_starts_with($text, '#[')) {
                break;
            }
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }
}
