<?php

namespace App\RSpade\Core\Realtime;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Realtime_Touch_Registry
 *
 * Per-process memoized metadata for the #[Realtime_Touch] attribute — a bare marker
 * placed on a child model's belongsTo relationship method (e.g. Contact_Model::client()).
 * Any write to that child (save/delete via the model layer OR a covered bulk builder
 * write) queues a change emission for the related PARENT, regardless of the child's own
 * $realtime flag.
 *
 * The attribute carries NO arguments (no FK/parent duplication): the FK column and parent
 * class are resolved ONCE per class per process by runtime reflection over the annotated
 * relationship method — a blank model instance, the belongsTo introspected for its foreign
 * key and related class (the Check_Indexes_Command precedent). A method carrying the
 * attribute that does NOT return a belongsTo is a programming error and fails loud.
 *
 * All lookups are keyed by FQCN and memoized. Metadata is static/reflective (attributes,
 * relationships, traits) so a single resolution per class holds for the whole process.
 */
class Realtime_Touch_Registry
{
    /**
     * The base model class name a method's declaring class is compared against to decide
     * whether realtime_touch() was overridden.
     */
    private const BASE_MODEL_CLASS = Rsx_Model_Abstract::class;

    /**
     * Resolved touch metadata per child FQCN.
     *
     * @var array<string, array<int, array{method: string, fk_column: string, parent_class: string, parent_fqcn: string, parent_has_onward: bool, parent_soft_deletes: bool}>>
     */
    private static array $touch_metadata = [];

    /**
     * Memoized "does this class have >=1 #[Realtime_Touch] method" (attribute presence only,
     * no relationship reflection — the cheap check that keeps parent_has_onward acyclic).
     *
     * @var array<string, bool>
     */
    private static array $has_attributed_touch = [];

    /**
     * Memoized "does this class override realtime_touch()".
     *
     * @var array<string, bool>
     */
    private static array $overrides_touch_method = [];

    /**
     * Memoized "does this class opt into its own change emission ($realtime = true)".
     *
     * @var array<string, bool>
     */
    private static array $is_own_realtime = [];

    /**
     * Memoized "does this class have any realtime surface at all".
     *
     * @var array<string, bool>
     */
    private static array $has_realtime_surface = [];

    /**
     * The #[Realtime_Touch] entries for a child model, resolved once per class.
     *
     * @return array<int, array{method: string, fk_column: string, parent_class: string, parent_fqcn: string, parent_has_onward: bool, parent_soft_deletes: bool}>
     */
    public static function touch_metadata(string $fqcn): array
    {
        if (isset(self::$touch_metadata[$fqcn])) {
            return self::$touch_metadata[$fqcn];
        }

        $entries = [];

        foreach (self::_attributed_touch_methods($fqcn) as $method) {
            $rel = (new $fqcn())->$method();

            if (!$rel instanceof BelongsTo) {
                shouldnt_happen(
                    '#[Realtime_Touch] on ' . $fqcn . '::' . $method . '() requires a belongsTo relationship '
                    . '(the attribute is belongsTo-only). For a polymorphic or conditional parent, override '
                    . 'realtime_touch() instead. Got: ' . get_debug_type($rel)
                );
            }

            $parent_fqcn = get_class($rel->getRelated());

            $entries[] = [
                'method' => $method,
                'fk_column' => $rel->getForeignKeyName(),
                'parent_class' => class_basename($parent_fqcn),
                'parent_fqcn' => $parent_fqcn,
                'parent_has_onward' => self::_has_attributed_touch($parent_fqcn) || self::_overrides_touch_method($parent_fqcn),
                'parent_soft_deletes' => self::_uses_soft_deletes($parent_fqcn),
            ];
        }

        self::$touch_metadata[$fqcn] = $entries;

        return $entries;
    }

    /**
     * Whether a model has ANY realtime surface: its own change opt-in ($realtime = true),
     * a #[Realtime_Touch]-attributed relationship, or an overridden realtime_touch(). Drives
     * the bulk-builder interception gate.
     */
    public static function has_realtime_surface(string $fqcn): bool
    {
        if (isset(self::$has_realtime_surface[$fqcn])) {
            return self::$has_realtime_surface[$fqcn];
        }

        $surface = self::is_own_realtime($fqcn)
            || self::_has_attributed_touch($fqcn)
            || self::_overrides_touch_method($fqcn);

        self::$has_realtime_surface[$fqcn] = $surface;

        return $surface;
    }

    /**
     * Whether the model opts into its own change emission ($realtime = true).
     */
    public static function is_own_realtime(string $fqcn): bool
    {
        if (isset(self::$is_own_realtime[$fqcn])) {
            return self::$is_own_realtime[$fqcn];
        }

        $value = (bool) (new \ReflectionClass($fqcn))->getStaticPropertyValue('realtime', false);
        self::$is_own_realtime[$fqcn] = $value;

        return $value;
    }

    /**
     * Whether the model overrides realtime_touch() (the escape hatch for polymorphic /
     * conditional parents). A bulk write on such a model must HYDRATE affected rows to run
     * that method with a real instance (a light column select cannot).
     */
    public static function overrides_realtime_touch(string $fqcn): bool
    {
        return self::_overrides_touch_method($fqcn);
    }

    /**
     * Reset all memoized state. Framework-test seam (statics are process-global).
     */
    public static function _testing_reset(): void
    {
        self::$touch_metadata = [];
        self::$has_attributed_touch = [];
        self::$overrides_touch_method = [];
        self::$is_own_realtime = [];
        self::$has_realtime_surface = [];
    }

    /**
     * The instance-method names on a class carrying the #[Realtime_Touch] attribute, read
     * from the manifest attribute index (simple-name keyed, mirroring the #[Emitter] scan).
     *
     * @return array<int, string>
     */
    private static function _attributed_touch_methods(string $fqcn): array
    {
        $metadata = Manifest::php_get_metadata_by_fqcn($fqcn);
        if (!$metadata || empty($metadata['public_instance_methods'])) {
            return [];
        }

        $methods = [];

        foreach ($metadata['public_instance_methods'] as $method_name => $method_data) {
            foreach ($method_data['attributes'] ?? [] as $attr_name => $attr_instances) {
                if ($attr_name === 'Realtime_Touch' || str_ends_with($attr_name, '\\Realtime_Touch')) {
                    $methods[] = $method_name;
                    break;
                }
            }
        }

        return $methods;
    }

    /**
     * Cheap presence check (attribute only, no relationship reflection) so parent_has_onward
     * resolves without recursing into a parent's full touch_metadata — a #[Realtime_Touch]
     * cycle (A touches B, B touches A) would otherwise loop the resolver.
     */
    private static function _has_attributed_touch(string $fqcn): bool
    {
        if (isset(self::$has_attributed_touch[$fqcn])) {
            return self::$has_attributed_touch[$fqcn];
        }

        $value = !empty(self::_attributed_touch_methods($fqcn));
        self::$has_attributed_touch[$fqcn] = $value;

        return $value;
    }

    /**
     * Whether $fqcn declares its own realtime_touch() (i.e. the declaring class is not the
     * base model).
     */
    private static function _overrides_touch_method(string $fqcn): bool
    {
        if (isset(self::$overrides_touch_method[$fqcn])) {
            return self::$overrides_touch_method[$fqcn];
        }

        $declaring = (new \ReflectionMethod($fqcn, 'realtime_touch'))->getDeclaringClass()->getName();
        $value = $declaring !== self::BASE_MODEL_CLASS;
        self::$overrides_touch_method[$fqcn] = $value;

        return $value;
    }

    /**
     * Whether the model uses the SoftDeletes trait (so a hydrating parent lookup must
     * withTrashed(), matching the Phase 1 touch semantics).
     */
    private static function _uses_soft_deletes(string $fqcn): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($fqcn), true);
    }
}
