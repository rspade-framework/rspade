<?php

namespace App\RSpade\Core\Database\TypeRefs;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\TypeRefs\Retired_Type_Ref;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Type_Ref_Registry - Manages polymorphic type reference mappings
 *
 * This registry provides a transparent mapping between class names (strings)
 * and integer IDs for efficient polymorphic column storage. It handles:
 *
 * - Loading the type refs map from database/cache
 * - Auto-creating new type ref entries when new classes are used
 * - Lookups in both directions (class → ID, ID → class)
 * - Registering the morph map with Laravel for relationship compatibility
 *
 * The registry uses a two-tier caching strategy:
 * 1. Redis cache (if available) - shared across requests
 * 2. PHP memory - loaded once per request
 *
 * Usage:
 *     $id = Type_Ref_Registry::class_to_id('Contact_Model');
 *     $class = Type_Ref_Registry::id_to_class($id);
 */
class Type_Ref_Registry
{
    /**
     * In-memory cache of the type refs map (class_name => id)
     * @var array|null
     */
    protected static $map_class_to_id = null;

    /**
     * In-memory cache of reverse map (id => class_name)
     * @var array|null
     */
    protected static $map_id_to_class = null;

    /**
     * Cache key for Redis storage (build-scoped - invalidates on manifest rebuild)
     */
    protected const CACHE_KEY = 'type_refs_map';

    /**
     * Per-request memo of "does this registered class still exist in the codebase":
     * class_name => bool. Every cast read consults it, so the manifest lookup behind
     * _resolve_fqcn() happens once per class per request, not once per row.
     * @var array<string, bool>
     */
    protected static array $map_class_resolves = [];

    /**
     * Get the integer ID for a class name
     *
     * If the class doesn't exist in the registry, it will be auto-created
     * after validating that it's a valid Rsx_Model subclass.
     *
     * A registered class that no longer exists in the codebase is REFUSED here, on the
     * cache-hit path as well as the auto-create path: a retired model must not be able to
     * accrue new references while its _type_refs row waits to be pruned.
     *
     * @param string $class_name Simple class name (e.g., "Contact_Model")
     * @return int The type ref ID
     * @throws RuntimeException If class doesn't exist or isn't a valid model
     */
    public static function class_to_id(string $class_name): int
    {
        static::_ensure_loaded();

        // Check if already in map
        if (isset(static::$map_class_to_id[$class_name])) {
            $id = static::$map_class_to_id[$class_name];

            if (!static::_class_resolves($class_name)) {
                throw new RuntimeException(
                    "Refusing to reference '{$class_name}': " . Retired_Type_Ref::message($id, $class_name)
                );
            }

            return $id;
        }

        // Auto-create new entry
        return static::_create_type_ref($class_name);
    }

    /**
     * Get the class name for an integer ID
     *
     * The registered class must still EXIST. Returning the name of a class that resolves to
     * nothing defers the failure to an arbitrary later frame (Rsx_Type_Ref_Cast::get() would
     * hand app code a phantom class name), so a retired class throws here instead.
     *
     * @param int $id The type ref ID
     * @return string The simple class name
     * @throws RuntimeException If ID doesn't exist in the registry, or its class is retired
     */
    public static function id_to_class(int $id): string
    {
        static::_ensure_loaded();

        if (!isset(static::$map_id_to_class[$id])) {
            throw new RuntimeException(
                "Type ref ID {$id} not found in registry. " .
                "The referenced model class may have been removed from the codebase."
            );
        }

        $class_name = static::$map_id_to_class[$id];

        if (!static::_class_resolves($class_name)) {
            throw new RuntimeException(Retired_Type_Ref::message($id, $class_name));
        }

        return $class_name;
    }

    /**
     * The registry id for a class name, WITHOUT auto-creating it and WITHOUT requiring the
     * class to still exist - null when the registry has never heard of it.
     *
     * This is the supported lookup for a CLEANUP MIGRATION: class_to_id() cannot serve one,
     * because it validates against the manifest and therefore throws for precisely the
     * retired classes a cleanup is about. Reading _type_refs directly is not the answer -
     * this is.
     *
     * @param string $class_name Simple class name
     * @return int|null
     */
    public static function find_id_by_class_name(string $class_name): ?int
    {
        static::_ensure_loaded();

        return static::$map_class_to_id[$class_name] ?? null;
    }

    /**
     * Check if a class name exists in the registry
     *
     * @param string $class_name
     * @return bool
     */
    public static function has_class(string $class_name): bool
    {
        static::_ensure_loaded();
        return isset(static::$map_class_to_id[$class_name]);
    }

    /**
     * Check if an ID exists in the registry
     *
     * @param int $id
     * @return bool
     */
    public static function has_id(int $id): bool
    {
        static::_ensure_loaded();
        return isset(static::$map_id_to_class[$id]);
    }

    /**
     * Get the full type refs map (class_name => id)
     *
     * @return array
     */
    public static function get_map(): array
    {
        static::_ensure_loaded();
        return static::$map_class_to_id;
    }

    /**
     * Register all type refs with Laravel's morph map
     *
     * Called during framework boot. Every type ref is registered under TWO aliases
     * pointing at the same FQCN:
     *
     *   'Contact_Model' => Rsx\Models\Contact_Model::class   (the simple class name)
     *   '4'             => Rsx\Models\Contact_Model::class   (the type-ref integer id)
     *
     * The integer alias is what makes STOCK Eloquent polymorphism work over a BIGINT
     * type-ref column. Laravel's morphTo() reads the RAW attribute (bypassing the cast,
     * so it sees the integer) and resolves it through Relation::getMorphedModel() /
     * Model::getActualClassNameForMorph(), which are plain morph-map lookups. Registering
     * the integer id AS an alias therefore turns the raw int into the right class with no
     * framework workaround at all. PHP coerces a numeric-string array key to an int, so the
     * entry is keyed by int 4 and matches both a `4` and a `"4"` raw attribute.
     *
     * ORDER IS LOAD-BEARING. Model::getMorphClass() answers with
     * array_search(static::class, $morphMap, true) - the FIRST key that maps to the class.
     * That value is what a WRITE puts in the type column (morphTo()->associate(),
     * morphMany()'s constraint, whereMorphedTo()). It must be the class-name alias, which
     * the Rsx_Type_Ref_Cast then converts to the integer on the way to the database. If the
     * integer alias came first, getMorphClass() would answer "4" and the cast would try to
     * register a model class literally named "4". So every class-name alias is inserted
     * BEFORE any integer alias, here and in _create_type_ref().
     */
    public static function register_morph_map(): void
    {
        static::_ensure_loaded();

        // Resolve once: not every registered class name still exists in the codebase
        // (a renamed/removed model leaves its _type_refs row behind). A class that no
        // longer resolves is NOT dropped - it is registered as a POISON alias whose
        // instantiation throws the full story at the point of use (Retired_Type_Ref).
        $resolved = [];
        $retired = [];
        foreach (static::$map_class_to_id as $class_name => $id) {
            $fqcn = static::_resolve_fqcn($class_name);
            if ($fqcn) {
                $resolved[$class_name] = ['id' => $id, 'fqcn' => $fqcn];
            } else {
                $retired[$class_name] = [
                    'id' => $id,
                    'fqcn' => Retired_Type_Ref::poison_class_for($id, $class_name),
                ];
            }
        }

        if (!empty($retired)) {
            $pairs = [];
            foreach ($retired as $class_name => $entry) {
                $pairs[] = $entry['id'] . ' => ' . $class_name;
            }
            Log::warning(
                '[TYPE-REF] ' . count($retired) . ' registered type ref(s) name a model class that no longer'
                . ' exists: ' . implode(', ', $pairs) . '. Any record still referencing one throws at the'
                . ' point of use. Run "php artisan rsx:health" for the referencing table/column counts,'
                . ' then "php artisan rsx:type_refs:prune".'
            );
        }

        if (empty($resolved) && empty($retired)) {
            return;
        }

        $morph_map = [];

        $all = $resolved + $retired;

        // Class-name aliases FIRST (see ORDER IS LOAD-BEARING above).
        foreach ($all as $class_name => $entry) {
            $morph_map[$class_name] = $entry['fqcn'];
        }

        // Integer-id aliases second.
        foreach ($all as $entry) {
            $morph_map[(string) $entry['id']] = $entry['fqcn'];
        }

        Relation::morphMap($morph_map);
    }

    /**
     * Clear all caches and reload from database
     *
     * Call this after manually modifying the _type_refs table
     */
    public static function refresh(): void
    {
        // Clear Redis cache
        RsxCache::delete(static::CACHE_KEY);
        static::$map_class_to_id = null;
        static::$map_id_to_class = null;
        static::$map_class_resolves = [];
        static::_ensure_loaded();
    }

    /**
     * Drop every cached view of the registry - memory AND the Redis entry - WITHOUT
     * reloading. The next lookup reads the database.
     *
     * The test seam: a test that inserts a _type_refs row inside its per-test transaction
     * must not leave that (rolled-back) row cached in Redis for the rest of the suite.
     */
    public static function _reset_cached_state(): void
    {
        RsxCache::delete(static::CACHE_KEY);
        static::$map_class_to_id = null;
        static::$map_id_to_class = null;
        static::$map_class_resolves = [];
    }

    /**
     * Does a class name still exist in the codebase? The public view of the resolution
     * seam every registry read uses - the integrity audit (Type_Ref_Audit) asks with it,
     * so "resolvable" means exactly the same thing there as it does here.
     */
    public static function class_resolves(string $class_name): bool
    {
        return static::_class_resolves($class_name);
    }

    /**
     * Does a REGISTERED class name still exist in the codebase? Memoized per request -
     * this is on every cast read.
     */
    protected static function _class_resolves(string $class_name): bool
    {
        if (!isset(static::$map_class_resolves[$class_name])) {
            static::$map_class_resolves[$class_name] = static::_resolve_fqcn($class_name) !== null;
        }

        return static::$map_class_resolves[$class_name];
    }

    /**
     * Ensure the type refs map is loaded into memory
     */
    protected static function _ensure_loaded(): void
    {
        if (static::$map_class_to_id !== null) {
            return;
        }

        // Check if _type_refs table exists (might not during initial migration)
        if (!Schema::hasTable('_type_refs')) {
            static::$map_class_to_id = [];
            static::$map_id_to_class = [];
            return;
        }

        // Try to load from Redis cache first (build-scoped)
        $cached = RsxCache::get(static::CACHE_KEY);
        if ($cached !== null && is_array($cached)) {
            static::$map_class_to_id = $cached;
            static::$map_id_to_class = array_flip($cached);
            return;
        }

        // Load from database
        static::_load_from_database();
    }

    /**
     * Load the type refs map from the database
     */
    protected static function _load_from_database(): void
    {
        $rows = DB::table('_type_refs')->get(['id', 'class_name']);

        static::$map_class_to_id = [];
        static::$map_id_to_class = [];

        foreach ($rows as $row) {
            static::$map_class_to_id[$row->class_name] = (int) $row->id;
            static::$map_id_to_class[(int) $row->id] = $row->class_name;
        }

        // Cache the map (build-scoped)
        RsxCache::set(static::CACHE_KEY, static::$map_class_to_id, RsxCache::HOUR);
    }

    /**
     * Create a new type ref entry for a class
     *
     * @param string $class_name
     * @return int The new ID
     * @throws RuntimeException If class is invalid
     */
    protected static function _create_type_ref(string $class_name): int
    {
        // Validate that the class exists and is a valid model
        static::_validate_class($class_name);

        // Get the table name for the model
        $table_name = static::_get_table_name($class_name);

        // Insert with duplicate key handling (race condition protection)
        // Use INSERT IGNORE to handle concurrent requests
        DB::statement(
            "INSERT IGNORE INTO _type_refs (class_name, table_name, created_at, updated_at) VALUES (?, ?, NOW(3), NOW(3))",
            [$class_name, $table_name]
        );

        // Fetch the ID (whether we just inserted or it already existed)
        $row = DB::table('_type_refs')->where('class_name', $class_name)->first(['id']);

        if (!$row) {
            throw new RuntimeException("Failed to create type ref for class: {$class_name}");
        }

        $id = (int) $row->id;

        // Update in-memory cache
        static::$map_class_to_id[$class_name] = $id;
        static::$map_id_to_class[$id] = $class_name;

        // Update Redis cache
        RsxCache::set(static::CACHE_KEY, static::$map_class_to_id, RsxCache::HOUR);

        // Update Laravel morph map - both aliases, class name first (see register_morph_map).
        $fqcn = static::_resolve_fqcn($class_name);
        if ($fqcn) {
            Relation::morphMap([$class_name => $fqcn, (string) $id => $fqcn]);
        }

        return $id;
    }

    /**
     * Validate that a class name is a valid Rsx model
     *
     * @param string $class_name
     * @throws RuntimeException If invalid
     */
    protected static function _validate_class(string $class_name): void
    {
        // Resolve the fully qualified class name
        $fqcn = static::_resolve_fqcn($class_name);

        if (!$fqcn) {
            throw new RuntimeException(
                "Cannot create type ref for '{$class_name}': Class not found in manifest. " .
                "Ensure the class exists and extends Rsx_Model_Abstract."
            );
        }

        // Check if it extends Rsx_Model_Abstract
        if (!is_subclass_of($fqcn, Rsx_Model_Abstract::class)) {
            throw new RuntimeException(
                "Cannot create type ref for '{$class_name}': Class must extend Rsx_Model_Abstract. " .
                "Only model classes can be used in polymorphic type references."
            );
        }
    }

    /**
     * Get the table name for a model class
     *
     * @param string $class_name
     * @return string|null
     */
    protected static function _get_table_name(string $class_name): ?string
    {
        $fqcn = static::_resolve_fqcn($class_name);
        if (!$fqcn) {
            return null;
        }

        try {
            // Create a reflection to get the table name without instantiating
            $instance = new $fqcn();
            return $instance->getTable();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve a simple class name to its fully qualified class name
     *
     * @param string $class_name Simple class name (e.g., "Contact_Model")
     * @return string|null FQCN or null if not found in manifest
     */
    protected static function _resolve_fqcn(string $class_name): ?string
    {
        try {
            $metadata = Manifest::php_get_metadata_by_class($class_name);
            return $metadata['fqcn'] ?? null;
        } catch (\RuntimeException $e) {
            // Class not found in manifest
            return null;
        }
    }
}
