<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Actor_Model_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Site_Actor_Model_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * ACTOR-01 - the authorship stamp's targets must be actor models, and actors must
 * still soft-delete.
 *
 * Two checks, both about the same invariant: an audit column pointing at an actor must
 * always be resolvable to a name.
 *
 * CHECK 1 - stamp targets are actors. Rsx_Model_Abstract::AUDIT_ACTOR_MODELS is the
 * complete set of class names save() will ever write into a created_by_type /
 * updated_by_type column. Each must extend the actor layer (Rsx_Actor_Model_Abstract or
 * Rsx_Site_Actor_Model_Abstract), which is what guarantees get_printed_name() and
 * get_view_profile_url() exist for every stamp the framework produces. The check is not
 * theatre in the monorepo, where the framework's own three obviously conform: it exists
 * for DOWNSTREAM apps, which replace framework models wholesale through the
 * class-override pattern. An override that extends the plain model abstract compiles,
 * runs, saves records - and every authorship display in the app dies on a missing
 * method, at read time, far from the change.
 *
 * CHECK 2 - the soft-delete mandate survives. Every concrete descendant of the actor
 * layer must still resolve the SoftDeletes trait. The abstracts install it, and
 * $actor_soft_deletes is #[Sealed] so it cannot be redeclared (SEALED-01), but neither
 * of those stops a subclass from being written against a table with no deleted_at or a
 * lineage that lost the trait. Hard-deleting an actor leaves every audit column that
 * ever named it dangling, which is unrecoverable - so it is checked directly.
 *
 * A registry of "all actor models" is deliberately NOT built. The framework knows
 * exactly which classes it stamps, and it knows the actor abstracts; that is enough to
 * validate both ends without asking applications to enroll anything.
 *
 * See: php artisan rsx:man actors
 */
class ActorModel_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'ACTOR-01';

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Actor Model Contract';
    }

    public function get_description(): string
    {
        return 'Authorship stamp targets must extend the actor layer, and actor models must soft-delete';
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
     * Runs during the manifest scan: both failure modes are invisible until an
     * authorship display runs, long after the change that caused them.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Cross-file rule: validates a fixed set of classes plus the actor layer's
     * descendants, not the file it was handed.
     */
    public function is_incremental(): bool
    {
        return false;
    }

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

        $this->check_stamp_targets_are_actors();
        $this->check_actors_soft_delete($files);
    }

    /**
     * CHECK 1: every class the audit stamp can name extends the actor layer.
     */
    private function check_stamp_targets_are_actors(): void
    {
        foreach (Rsx_Model_Abstract::AUDIT_ACTOR_MODELS as $cell => $class_name) {
            try {
                $rel_path = Manifest::php_find_class($class_name);
            } catch (\Throwable $e) {
                // The stamp names a class the manifest cannot find at all. That is a
                // broken install rather than an authorship problem, and the model
                // layer will say so loudly the first time it resolves one.
                continue;
            }

            if ($this->extends_actor_layer($class_name)) {
                continue;
            }

            $abs_file = base_path($rel_path);
            $line = $this->find_class_line($rel_path, $class_name);
            $contents = @file_get_contents($abs_file);
            $lines = $contents === false ? [] : explode("\n", $contents);
            $snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

            $this->add_violation(
                $abs_file,
                $line,
                "{$class_name} is an authorship stamp target (Rsx_Model_Abstract::AUDIT_ACTOR_MODELS"
                . "['{$cell}']) but does not extend the actor layer.\n\n"
                . "Every save() in the application can write this class name into a created_by_type "
                . "or updated_by_type column. Anything that later displays that authorship calls "
                . "get_printed_name() / get_view_profile_url() on it - methods only the actor layer "
                . "guarantees. Without them the failure surfaces at READ time, on some unrelated "
                . "screen, with no clue pointing back here.",
                $snippet,
                "Extend Rsx_Site_Actor_Model_Abstract if this model's table is site-scoped "
                . "(it carries site_id), or Rsx_Actor_Model_Abstract if it is not, and implement "
                . "get_printed_name() and get_view_profile_url().\n"
                . "See: php artisan rsx:man actors",
                'critical'
            );
        }
    }

    /**
     * CHECK 2: every concrete descendant of the actor layer still resolves SoftDeletes.
     */
    private function check_actors_soft_delete(array $files): void
    {
        foreach ($files as $rel_path => $file_metadata) {
            if (($file_metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            $fqcn = $file_metadata['fqcn'] ?? null;
            $class_name = $file_metadata['class'] ?? null;
            if (!$fqcn || !$class_name || !class_exists($fqcn)) {
                continue;
            }

            // The two halves of the actor layer, tested by class rather than by the
            // shared interface: the manifest indexes classes, and these are the two
            // names a downstream model actually writes after `extends`.
            if (!is_subclass_of($fqcn, Rsx_Actor_Model_Abstract::class)
                && !is_subclass_of($fqcn, Rsx_Site_Actor_Model_Abstract::class)) {
                continue;
            }

            if (Manifest::php_is_abstract($class_name)) {
                continue;
            }

            if (in_array(SoftDeletes::class, class_uses_recursive($fqcn), true)) {
                continue;
            }

            $abs_file = base_path($rel_path);
            $line = $this->find_class_line($rel_path, $class_name);
            $contents = @file_get_contents($abs_file);
            $lines = $contents === false ? [] : explode("\n", $contents);
            $snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

            $this->add_violation(
                $abs_file,
                $line,
                "{$class_name} is an actor model but does not resolve the SoftDeletes trait.\n\n"
                . "Soft deletes are mandatory for actors. An actor that can be hard-deleted leaves "
                . "every created_by / updated_by column that ever named it pointing at nothing, and "
                . "no amount of later code can recover the name - the row is gone.",
                $snippet,
                "Actor models inherit `use SoftDeletes` from Rsx_Actor_Model_Abstract / "
                . "Rsx_Site_Actor_Model_Abstract; getting this violation means the lineage was "
                . "broken or the trait was countermanded. Restore it, and make sure the table has a "
                . "deleted_at column.\n"
                . "See: php artisan rsx:man actors",
                'critical'
            );
        }
    }

    /**
     * Whether a class extends either half of the actor layer.
     */
    private function extends_actor_layer(string $class_name): bool
    {
        return Manifest::php_is_subclass_of($class_name, 'Rsx_Actor_Model_Abstract')
            || Manifest::php_is_subclass_of($class_name, 'Rsx_Site_Actor_Model_Abstract');
    }

    /**
     * The line a class is declared on, for violation reporting.
     */
    private function find_class_line(string $rel_path, string $class_name): int
    {
        $absolute_path = base_path($rel_path);
        if (!is_file($absolute_path)) {
            return 1;
        }

        $contents = @file_get_contents($absolute_path);
        if ($contents === false) {
            return 1;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            if (preg_match('/^\s*(?:abstract\s+|final\s+)*class\s+' . preg_quote($class_name, '/') . '\b/', $line)) {
                return $index + 1;
            }
        }

        return 1;
    }
}
