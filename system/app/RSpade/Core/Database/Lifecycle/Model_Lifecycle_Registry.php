<?php

namespace App\RSpade\Core\Database\Lifecycle;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Model_Lifecycle_Registry
 *
 * Per-process memoized "does this model DECLARE a lifecycle hook" gate — the lifecycle
 * analogue of Realtime_Touch_Registry::has_realtime_surface(). A model participates in the
 * lifecycle engine only when it OVERRIDES one of the three optional hooks
 * (after_create / after_update / after_delete) on Rsx_Model_Abstract. A plain model that
 * overrides none pays ZERO dispatch cost: the save()/delete() choke points consult
 * has_lifecycle_surface() (one cached boolean) and bail before touching the buffer.
 *
 * "Declares a hook" = the hook method's ReflectionMethod::getDeclaringClass() is not
 * Rsx_Model_Abstract (i.e. a subclass reimplemented it). Resolved once per class per
 * process and cached — the override map is static metadata that never changes at runtime.
 */
class Model_Lifecycle_Registry
{
    /**
     * The base class a hook's declaring class is compared against to decide whether a
     * subclass overrode it.
     */
    private const BASE_MODEL_CLASS = Rsx_Model_Abstract::class;

    /**
     * The three optional lifecycle hooks a model may override.
     */
    private const HOOK_METHODS = ['after_create', 'after_update', 'after_delete'];

    /**
     * Per-FQCN map of hook name => whether the class overrides it.
     *
     * @var array<string, array<string, bool>>
     */
    private static array $declares = [];

    /**
     * Per-FQCN "does this class override ANY hook" (drives the cheap common-path gate).
     *
     * @var array<string, bool>
     */
    private static array $has_surface = [];

    /**
     * Whether a model has ANY lifecycle surface: it overrides at least one of
     * after_create / after_update / after_delete. Plain models return false and pay no
     * dispatch cost.
     */
    public static function has_lifecycle_surface(string|object $model): bool
    {
        $fqcn = is_object($model) ? get_class($model) : $model;

        if (isset(self::$has_surface[$fqcn])) {
            return self::$has_surface[$fqcn];
        }

        $map = self::_resolve($fqcn);
        $surface = $map['after_create'] || $map['after_update'] || $map['after_delete'];
        self::$has_surface[$fqcn] = $surface;

        return $surface;
    }

    /**
     * Whether a model overrides a SPECIFIC hook. The save() choke point uses this to queue
     * only the event a model actually cares about (a model overriding only after_delete must
     * not queue a create/update entry). $hook is one of the HOOK_METHODS names.
     */
    public static function declares(string|object $model, string $hook): bool
    {
        $fqcn = is_object($model) ? get_class($model) : $model;

        return self::_resolve($fqcn)[$hook] ?? false;
    }

    /**
     * Resolve (and memoize) the override map for a class via reflection over each hook's
     * declaring class.
     *
     * @return array<string, bool>
     */
    private static function _resolve(string $fqcn): array
    {
        if (isset(self::$declares[$fqcn])) {
            return self::$declares[$fqcn];
        }

        $map = [];

        foreach (self::HOOK_METHODS as $method) {
            $declaring = (new \ReflectionMethod($fqcn, $method))->getDeclaringClass()->getName();
            $map[$method] = $declaring !== self::BASE_MODEL_CLASS;
        }

        self::$declares[$fqcn] = $map;

        return $map;
    }

    /**
     * Reset all memoized state. Framework-test seam (statics are process-global).
     */
    public static function _testing_reset(): void
    {
        self::$declares = [];
        self::$has_surface = [];
    }
}
