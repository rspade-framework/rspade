<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * ABSTRACT-ATTR-01 - registry attributes may not be declared on an abstract class.
 *
 * A registry attribute is one whose whole purpose is to enrol the DECLARING class into a
 * runtime registry: a route table, the task scheduler, the event handler index, the health
 * check inventory, the auth surface index. Every one of those registries is built from the
 * manifest, which indexes attributes per FILE and never inherits them - so an attribute
 * placed on an abstract class produces one of exactly two wrong outcomes:
 *
 *   - the registration is invisible to every subclass (the attribute was meant to apply to
 *     them and silently applies to nobody), or
 *   - the registry gains a PHANTOM entry keyed to the abstract class itself - a schedule, a
 *     route, an event handler that runs AS the abstract. PHP permits the static call, and it
 *     is wrong the moment the body uses static:: .
 *
 * Neither failure raises anything at runtime, which is why this is fatal at build time.
 *
 * NOT every attribute belongs here. The forbidden set is the registry-registering ones. An
 * attribute whose meaning is genuinely ABOUT the declaring class, or that is deliberately
 * consumed through a lineage walk, is legitimate on an abstract and is not listed:
 * #[Relationship] (unioned across the lineage by get_relationships()), #[Auth_Check] (the
 * check registry does an explicit lineage union), #[Replaceable], #[Instantiatable],
 * #[Monoprogenic], #[Sealed].
 *
 * Detection is a pure compiled-manifest walk - the abstract flag and the attribute names
 * are already indexed. No AST parsing is needed or done.
 *
 * Honors @ABSTRACT-ATTR-01-EXCEPTION at file level (via CodeQualityChecker) and, with
 * precision, on or immediately above the offending declaration.
 *
 * See: docs.dev/audits/manifest_inheritance_blindness_audit_2026_08_13.md (2.3, 5.1, 5.2)
 */
class AbstractRegistryAttribute_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'ABSTRACT-ATTR-01';

    /**
     * The attributes that register their declaring class into an inheritance-blind runtime
     * registry. Single source of truth for this rule.
     */
    private const FORBIDDEN_ON_ABSTRACT = [
        'Auth',
        'Route',
        'SPA',
        'Portal_Route',
        'Ajax_Endpoint',
        'Ajax_Endpoint_Model_Fetch',
        'Api_Endpoint',
        'Task',
        'Schedule',
        'Exclusive',
        'Debounce',
        'Emitter',
        'OnEvent',
        'Health_Check',
        'Realtime_Touch',
        'FPC',
    ];

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Registry Attribute On Abstract Class';
    }

    public function get_description(): string
    {
        return 'Attributes that register a class into a runtime registry may not be declared on an abstract class';
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
     * Runs during the manifest scan: both outcomes (dropped registration, phantom entry)
     * are silent at runtime.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Cross-file rule: walks the manifest rather than the file it was handed.
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
            if (empty($file_metadata['abstract'])) {
                continue;
            }

            if ($changed_lookup !== null) {
                $normalized_rel = str_replace('\\', '/', $rel_path);
                if (!isset($changed_lookup[$normalized_rel])) {
                    continue;
                }
            }

            $this->evaluate_class_attributes(
                base_path($rel_path),
                $file_metadata['class'],
                $file_metadata['attributes'] ?? [],
                $this->merge_method_maps($file_metadata)
            );
        }
    }

    /**
     * Core validation for one abstract class: its class-level attributes plus a merged map
     * of its methods (name => ['line' => int, 'attributes' => array]).
     *
     * This is the testable seam: production reads both from the manifest entry; tests hand
     * in manifest-shaped data over a real fixture file, so line/snippet/exception handling is
     * exercised without the fixture having to be indexed.
     *
     * @param array<string, mixed> $class_attributes attribute name => arguments
     * @param array<string, array>  $methods         method name => ['line' => int, 'attributes' => array]
     */
    public function evaluate_class_attributes(
        string $file,
        string $class_name,
        array $class_attributes,
        array $methods
    ): void {
        $contents = @file_get_contents($file);
        $lines = $contents === false ? [] : explode("\n", $contents);

        $class_line = $this->find_class_line($lines, $class_name);

        foreach (self::FORBIDDEN_ON_ABSTRACT as $attribute_name) {
            if (!isset($class_attributes[$attribute_name])) {
                continue;
            }
            if ($this->has_exception_marker($lines, $class_line)) {
                continue;
            }

            $this->report($file, $class_line, $lines, $class_name, $attribute_name, null);
        }

        foreach ($methods as $method_name => $method_data) {
            $line = (int) ($method_data['line'] ?? 0);

            foreach (self::FORBIDDEN_ON_ABSTRACT as $attribute_name) {
                if (!isset($method_data['attributes'][$attribute_name])) {
                    continue;
                }
                if ($this->has_exception_marker($lines, $line)) {
                    continue;
                }

                $this->report($file, $line > 0 ? $line : $class_line, $lines, $class_name, $attribute_name, $method_name);
            }
        }
    }

    /**
     * Emit one violation for a forbidden attribute found on $class_name (on $method_name
     * when the attribute sits on a method, on the class itself when null).
     */
    private function report(
        string $file,
        int $line,
        array $lines,
        string $class_name,
        string $attribute_name,
        ?string $method_name
    ): void {
        $subject = $method_name === null
            ? "abstract class {$class_name}"
            : "{$class_name}::{$method_name}(), on an abstract class";

        $code_snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

        $message = "#[{$attribute_name}] is declared on {$subject}.\n\n"
            . "This attribute registers its DECLARING class into a runtime registry built from the "
            . "manifest, and the manifest indexes attributes per file - it never inherits them. On an "
            . "abstract class the registration therefore either reaches no subclass at all, or creates "
            . "a phantom entry keyed to the abstract itself that executes AS the abstract. Both are "
            . "silent at runtime.";

        $suggestion = "Move #[{$attribute_name}] onto the concrete class (or classes) it is meant to "
            . "register. Shared implementation can stay on the abstract - it is the ATTRIBUTE that must "
            . "sit where the registration belongs.\n"
            . "Attributes consumed through a lineage walk (#[Relationship], #[Auth_Check]) and "
            . "class-descriptive markers (#[Replaceable], #[Instantiatable], #[Monoprogenic], #[Sealed]) "
            . "are unaffected by this rule.";

        $this->add_violation(
            $file,
            $line > 0 ? $line : 1,
            $message,
            $code_snippet,
            $suggestion,
            'critical'
        );
    }

    /**
     * One method map from the entry's two indexes. public_static_methods is a
     * PUBLIC-OR-STATIC union (it carries public instance methods too), so the two maps
     * overlap; merging by name keeps each method's attributes reported once.
     *
     * @return array<string, array>
     */
    private function merge_method_maps(array $file_metadata): array
    {
        $methods = [];

        foreach (['public_instance_methods', 'public_static_methods'] as $key) {
            foreach ($file_metadata[$key] ?? [] as $method_name => $method_data) {
                if (!isset($methods[$method_name])) {
                    $methods[$method_name] = $method_data;

                    continue;
                }

                // Same method seen in both maps: keep the attribute set that is populated.
                if (empty($methods[$method_name]['attributes']) && !empty($method_data['attributes'])) {
                    $methods[$method_name] = $method_data;
                }
            }
        }

        return $methods;
    }

    /**
     * The line $class_name is declared on. Tokenized, never pattern-matched: a class name
     * in a comment or a string must not be mistaken for the declaration.
     */
    private function find_class_line(array $lines, string $class_name): int
    {
        $tokens = @token_get_all(implode("\n", $lines));
        if (empty($tokens)) {
            return 1;
        }

        $expecting_name = false;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_CLASS) {
                $expecting_name = true;

                continue;
            }

            if (!$expecting_name) {
                continue;
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            if ($token[0] === T_STRING && $token[1] === $class_name) {
                return $token[2];
            }

            $expecting_name = false;
        }

        return 1;
    }

    /**
     * Per-declaration exception detection for @ABSTRACT-ATTR-01-EXCEPTION: on the
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
