<?php

namespace App\RSpade\Core\Database\Models;

use App\Database\RestrictedEloquentBuilder;
use App\Exceptions\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Model_Abstract;
use App\RSpade\Core\Database\Lifecycle\Model_Lifecycle_Emissions;
use App\RSpade\Core\Database\Lifecycle\Model_Lifecycle_Registry;
use App\RSpade\Core\Database\Models\Rsx_Actor_Model_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Site_Actor_Model_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Realtime\Realtime_Touch_Registry;

/**
 * Abstract base model for all RSpade models
 *
 * This class:
 * - Prohibits mass assignment
 * - Provides enum functionality with magic properties and methods
 * - Prevents eager loading
 * - Serves as the base for all RSpade models
 * - Provides server-side query caching with modification tracking
 *
 * All models in RSpade must extend this class and use explicit field assignment:
 *
 * $model = new Model();
 * $model->field1 = $value1;
 * $model->field2 = $value2;
 * $model->save();
 *
 * Enum System:
 * Define enums using public static $enums property:
 * public static $enums = [
 *     'status_id' => [
 *         1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active'],
 *         2 => ['constant' => 'STATUS_INACTIVE', 'label' => 'Inactive']
 *     ]
 * ];
 *
 * This provides magic properties and methods (BEM-style double underscore):
 * - $model->status__label - Get label for current enum value
 * - $model->status__constant - Get constant name for current value
 * - $model->status__badge - Get any custom property for current value
 *
 * Static methods (available in both PHP and JavaScript):
 * - Model::status__enum() - Get all enum definitions with full metadata
 * - Model::status__enum_select() - Get selectable items as [{value, label}] array for dropdowns
 * - Model::status__enum_labels() - Get simple id => label lookup map
 * - Model::status__enum_ids() - Get array of all valid enum IDs
 */
#[Monoprogenic]
#[Instantiatable]
abstract class Rsx_Model_Abstract extends Model
{
    /**
     * Per-instance cache for the active Class-Table Inheritance detail row.
     * @var bool
     */
    private $_active_detail_loaded = false;

    /**
     * The loaded persisted active detail model, or null when none exists / type has no detail.
     * @var Rsx_Detail_Model_Abstract|null
     */
    private $_active_detail = null;

    /**
     * A vivified (new, unsaved, FK pre-set) detail instance, cached so repeated accessor
     * reads return the same object to fill and save.
     * @var Rsx_Detail_Model_Abstract|null
     */
    private $_vivified_detail = null;

    /**
     * Realtime model-change emission opt-in. When true, a successful save()/delete()
     * publishes a notification-only Model_Changed_Topic message ({model, id}) on
     * transaction commit, so subscribed clients refetch. Off by default.
     * @var bool
     */
    public static $realtime = false;

    /**
     * When true, this model's writes do NOT kick the realtime emitter engine. Set on
     * per-request / high-volume infrastructure models (session persistence, mail/SMS
     * queues) whose writes are not interesting to any UI subscriber — otherwise every
     * such write would needlessly schedule an emitter recompute. Off by default (a
     * normal domain write SHOULD kick emitters).
     * @var bool
     */
    public static $realtime_silent = false;

    /**
     * Enum definitions for this model
     *
     * Example:
     * public static $enums = [
     *     'role_id' => [
     *         1 => ['constant' => 'ROLE_ADMIN', 'label' => 'Administrator'],
     *         2 => ['constant' => 'ROLE_USER', 'label' => 'User']
     *     ],
     *     'status_id' => [
     *         1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active'],
     *         2 => ['constant' => 'STATUS_INACTIVE', 'label' => 'Inactive']
     *     ]
     * ];
     *
     * @var array
     */
    // Note: $enums is declared later with full documentation

    /**
     * Private helper to resolve enum magic properties and methods
     *
     * Handles (these are mirrored in JavaScript stubs, BEM-style double underscore):
     * - field__enum() - Returns all enum definitions with full metadata
     * - field__enum_select() - Returns [{value, label}] array for dropdowns
     * - field__enum_labels() - Returns simple id => label map
     * - field__enum_ids() - Returns array of all valid enum IDs
     *
     * @param string $key The property/method being accessed
     * @param mixed $value Optional value for filtering selectable items
     * @return mixed|null
     */
    private static function _get_static_magic($key, $value = null)
    {
        $class = get_called_class();

        if (!empty($class::$enums)) {
            foreach ($class::$enums as $column => $enum_config) {
                // Create a copy to avoid modifying the static property
                $sorted_config = $enum_config;

                // Sort by 'order' property first, then by key
                uksort($sorted_config, function ($keyA, $keyB) use ($sorted_config) {
                    $orderA = isset($sorted_config[$keyA]['order']) ? $sorted_config[$keyA]['order'] : 0;
                    $orderB = isset($sorted_config[$keyB]['order']) ? $sorted_config[$keyB]['order'] : 0;

                    // First compare by order
                    if ($orderA !== $orderB) {
                        return $orderA - $orderB;
                    }

                    // If order is same, compare by key (use spaceship operator for string comparison)
                    return $keyA <=> $keyB;
                });

                // field__enum() - All enum definitions with full metadata
                if ($key == $column . '__enum') {
                    return $sorted_config;
                }

                // field__enum_select() - Selectable items for dropdowns (respects selectable: false)
                // Returns array of ['value' => id, 'label' => label] for consistent ordering
                if ($key == $column . '__enum_select') {
                    $return = [];

                    foreach ($sorted_config as $k => $v) {
                        // Filter based on 'selectable' property
                        $selectable = isset($v['selectable']) ? $v['selectable'] : true;

                        if (($selectable || ($k == $value && $value !== null))) {
                            $return[] = ['value' => $k, 'label' => $v['label']];
                        }
                    }

                    return $return;
                }

                // field__enum_labels() - Simple id => label map (all items, ignores selectable)
                if ($key == $column . '__enum_labels') {
                    $return = [];
                    foreach ($sorted_config as $k => $v) {
                        if (isset($v['label'])) {
                            $return[$k] = $v['label'];
                        }
                    }
                    return $return;
                }

                // field__enum_ids() - Array of all valid enum IDs
                if ($key == $column . '__enum_ids') {
                    return array_keys($sorted_config);
                }
            }
        }

        return null;
    }

    /**
     * Magic getter for enum properties
     *
     * Uses BEM-style double underscore to separate field from property:
     * - field__label - Label for current enum value
     * - field__constant - Constant name for current value
     * - field__badge - Any custom property for current value
     * - field__enum(), field__enum_select(), etc. - Via _get_static_magic
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        // Check for enum lookup functions: __enum, __enum_select, __enum_ids
        $static_call = self::_get_static_magic($key);
        if ($static_call !== null) {
            return $static_call;
        }

        // Look up enum properties related to current column value (BEM-style: field__property)
        if (!empty(static::$enums)) {
            foreach (static::$enums as $column => $enum_config) {
                // Look for specific enum property (e.g., field__label, field__constant)
                foreach ($enum_config as $enum_val => $enum_properties) {
                    foreach ($enum_properties as $prop_name => $prop_value) {
                        if ($key == $column . '__' . $prop_name && $this->$column == $enum_val) {
                            return $prop_value;
                        }
                    }
                }
            }
        }

        // Class-Table Inheritance: detail accessor (e.g. $record->party_person).
        // Returns the embedded/loaded detail for the active type, or throws on wrong-type.
        if (static::has_detail_tables() && static::__is_detail_accessor($key)) {
            return $this->__get_detail_for_accessor($key);
        }

        return parent::__get($key);
    }

    /**
     * Magic isset for enum properties
     *
     * Required because PHP's ?? operator calls __isset() before __get().
     * Without this, Eloquent's __isset() returns false for enum properties,
     * causing ?? to use the default value without ever calling __get().
     *
     * @param string $key
     * @return bool
     */
    public function __isset($key)
    {
        // Check for enum magic properties (BEM-style: field__property)
        if (!empty(static::$enums)) {
            foreach (static::$enums as $column => $enum_config) {
                // field__label, field__constant, field__* (any custom enum property)
                foreach ($enum_config as $enum_val => $enum_properties) {
                    foreach ($enum_properties as $prop_name => $prop_value) {
                        if ($key == $column . '__' . $prop_name) {
                            // Property exists if current column value matches this enum value
                            if ($this->$column == $enum_val) {
                                return true;
                            }
                        }
                    }
                }
            }
        }

        // A detail accessor is "set" only when it matches this record's active type
        // (so `$record->party_company ?? null` yields null rather than throwing wrong-type).
        if (static::has_detail_tables() && static::__is_detail_accessor($key)) {
            return $key === $this->__active_detail_accessor();
        }

        return parent::__isset($key);
    }

    /**
     * Magic static method handler for enum methods
     *
     * Provides static access to (mirrored in JavaScript stubs, BEM-style):
     * - Model::field__enum() - All enum definitions with full metadata
     * - Model::field__enum_select() - [{value, label}] array for dropdowns
     * - Model::field__enum_labels() - Simple id => label map
     * - Model::field__enum_ids() - Array of all valid enum IDs
     *
     * @param string $key
     * @param array $args
     * @return mixed
     */
    public static function __callStatic($key, $args)
    {
        $class = get_called_class();

        $result = $class::_get_static_magic($key, isset($args[0]) ? $args[0] : null);

        if ($result !== null) {
            return $result;
        }

        return parent::__callStatic($key, $args);
    }

    /**
     * Override toArray to include enum properties and model identifier
     *
     * Adds all enum properties and __MODEL identifier for client-side ORM instantiation
     * Excludes fields listed in $neverExport property for security
     *
     * @return array
     */
    public function toArray()
    {
        try {
            $array = parent::toArray();
        } catch (LogicException $e) {
            // Check if this is the relationship instance error
            if (str_contains($e->getMessage(), 'must return a relationship instance')) {
                // Extract the method name from the error message
                $message = $e->getMessage();
                preg_match('/::([\w]+) must return a relationship instance/', $message, $matches);
                $method_name = $matches[1] ?? 'unknown';

                throw new RuntimeException(
                    'Error in ' . static::class . "::toArray(): {$message}\n\n" .
                    "This error typically occurs when:\n" .
                    "1. A method name conflicts with a database column name\n" .
                    "2. An enum is defined for a non-existent column that matches a method name\n" .
                    "3. A method is not marked with #[Relationship] but Laravel is auto-detecting it as a relationship\n\n" .
                    "To diagnose:\n" .
                    "- Run 'php artisan rsx:check' to check for column/method conflicts\n" .
                    "- Verify '{$method_name}' is either a column OR a method, not both\n" .
                    "- If '{$method_name}' is a relationship, add #[Relationship] attribute to the method\n" .
                    "- If '{$method_name}' is not a relationship, ensure it's not being auto-detected",
                    0,
                    $e
                );
            }

            // Re-throw other exceptions
            throw $e;
        }

        // Remove fields that should never be exported to JavaScript
        if (property_exists($this, 'neverExport') && is_array($this->neverExport)) {
            foreach ($this->neverExport as $field) {
                unset($array[$field]);
            }
        }

        // Add enum field extra data - ALL properties (BEM-style: field__property)
        foreach (static::$enums as $column => $definitions) {
            if (isset($this->$column) && isset($definitions[$this->$column])) {
                foreach ($definitions[$this->$column] as $prop => $value) {
                    // Add all enum properties to the export
                    $array[$column . '__' . $prop] = $value;
                }
            }
        }

        // Class-Table Inheritance: eager-embed the active PERSISTED detail so the client
        // resolves `await record.party_person()` from the same payload (no extra fetch). It
        // rides under a single "__details" blob (not the accessor key) so it never collides
        // with the generated accessor method of the same name on the JS model.
        if (static::has_detail_tables()) {
            $accessor = $this->__active_detail_accessor();
            if ($accessor !== null) {
                $detail = $this->active_detail();
                if ($detail !== null) {
                    $array['__details'][$accessor] = $detail->toArray();
                }
            }
        }

        // __MODEL SYSTEM: Enables automatic ORM instantiation when fetching from PHP models.
        // PHP models add "__MODEL": "ClassName" to JSON, JavaScript uses it to create proper instances.
        // This provides typed model objects instead of plain JSON, with methods and type checking.

        // Here we inject the model's class name into the JSON output. When JavaScript receives
        // this data, it will see __MODEL and know to instantiate the matching JS model class
        // instead of leaving it as a plain object. This enables ORM features on the client side.
        $array['__MODEL'] = class_basename(static::class);

        return $array;
    }

    /**
     * Override isRelation to only consider methods with #[Relationship] attribute as relationships
     *
     * This prevents Laravel from auto-detecting non-relationship methods
     * like is_active() as relationships
     *
     * @param string $key
     * @return bool
     */
    public function isRelation($key)
    {
        // If this has an attribute mutator, it's not a relationship
        if ($this->hasAttributeMutator($key)) {
            return false;
        }

        // Only consider it a relationship if it's in the relationships list
        $relationships = static::get_relationships();

        return in_array($key, $relationships);
    }

    /**
     * Override getArrayableRelations to only include relationships defined via #[Relationship]
     *
     * This prevents Laravel from auto-detecting non-relationship methods
     * like is_active() as relationships
     *
     * @return array
     */
    protected function getArrayableRelations()
    {
        // Get relationships from annotations
        $relationships = static::get_relationships();

        // Only include relationships that are:
        // 1. Listed in the relationships
        // 2. Actually loaded on this model instance
        $arrayable = [];
        foreach ($relationships as $relation_name) {
            // Check if this relationship is loaded
            if (array_key_exists($relation_name, $this->relations)) {
                $arrayable[$relation_name] = $this->relations[$relation_name];
            }
        }

        return $arrayable;
    }

    /**
     * Mass assignment is explicitly disabled in RSpade
     * This empty array is required by Laravel's Model class
     * @var array
     */
    protected $fillable = [];

    /**
     * Guarded is set to all fields by default
     * @var array
     */
    protected $guarded = ['*'];

    /**
     * Columns that should never be exported to JavaScript
     * Concrete classes should define this array with sensitive column names
     * @var array
     */
    protected $neverExport = [];

    /**
     * Enumeration definitions for fields ending in _id
     * Define this array with enum values for lookup fields in the format:
     * 'field_id' => [
     *     1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active'],
     *     2 => ['constant' => 'STATUS_INACTIVE', 'label' => 'Inactive'],
     * ]
     * This enables automatic constant generation and JavaScript exports
     * @var array
     */
    public static $enums;

    /**
     * Class-Table Inheritance (CTI) detail-table map (see man detail_tables).
     *
     * Declares, per discriminator value, the detail model holding that type's
     * type-specific fields. The single source of truth for "what detail a type has":
     *
     * public static $detail_tables = [
     *     'type_id' => [                                   // discriminator column
     *         self::TYPE_PERSON  => Party_Person_Detail_Model::class,
     *         self::TYPE_COMPANY => Party_Company_Detail_Model::class,
     *         // a value absent from the map => that type has no detail row
     *     ],
     * ];
     *
     * Detail accessors (e.g. $record->party_person) are generated from this map; the
     * detail is eager-embedded on read and throws on wrong-type access. Null when the
     * model has no detail tables.
     *
     * @var array|null
     */
    public static $detail_tables = null;

    /**
     * Polymorphic type reference columns
     *
     * Columns listed here will automatically be cast using Rsx_Type_Ref_Cast,
     * which transparently converts between class name strings (PHP) and
     * integer IDs (database).
     *
     * A polymorphic reference is ALWAYS the pair {relation}_type + {relation}_id, both
     * BIGINT. Declaring the _type column here is the whole setup - stock Eloquent morph
     * relations (morphTo/morphMany/morphOne/whereMorphedTo/whereHasMorph) then work over
     * it unchanged, because Type_Ref_Registry::register_morph_map() registers each
     * type-ref integer id as a morph-map alias alongside the class name.
     *
     * Example:
     *   protected static $type_ref_columns = ['fileable_type'];
     *
     * With this:
     *   $attachment->fileable_type = 'Contact_Model';  // Stored as integer
     *   echo $attachment->fileable_type;               // Returns 'Contact_Model'
     *
     *   #[Relationship]
     *   public function fileable() { return $this->morphTo('fileable', 'fileable_type', 'fileable_id'); }
     *   $attachment->fileable;                         // The Contact_Model row
     *
     * The base declares the AUDIT type columns: the two authorship ones, which every table
     * carries, plus the deletion one, which only the soft-deleting tables carry (declaring a
     * cast for a column a table does not have is inert - Eloquent only casts attributes that
     * exist). A subclass that declares its own array does NOT lose them: the property is read
     * through _type_ref_columns(), which unions the base's declaration with the subclass's
     * (see that method).
     *
     * @var array
     */
    protected static $type_ref_columns = ['created_by_type', 'updated_by_type', 'deleted_by_type'];

    /**
     * The full type-ref column list for this model: the framework audit authorship columns
     * (declared on the base, present on every table) UNIONed with whatever the concrete model
     * declares for itself.
     *
     * The union is the whole point. PHP static properties are per-declaring-class: a model
     * writing `protected static $type_ref_columns = ['fileable_type'];` creates its OWN
     * property, and `static::$type_ref_columns` on that model answers ONLY 'fileable_type' -
     * the audit columns would silently lose their cast. `self::$type_ref_columns` inside this
     * method always resolves to the base's declaration, so merging the two yields both.
     *
     * Every consumer of the declaration (getCasts(), the query builder's WHERE conversion)
     * must go through this method rather than reading the property.
     *
     * @return array<int, string>
     */
    public static function _type_ref_columns(): array
    {
        return array_values(array_unique(array_merge(self::$type_ref_columns, static::$type_ref_columns)));
    }

    /**
     * Get the casts array with automatic type casting based on database schema
     *
     * This method automatically adds type casts by consulting the manifest's
     * database metadata. This eliminates the need to manually define $casts
     * for standard framework conventions.
     *
     * Auto-detected casts:
     * - TINYINT(1) columns → 'boolean'
     * - DATETIME/TIMESTAMP columns → Rsx_DateTime_Cast (returns ISO 8601 UTC strings)
     * - DATE columns → Rsx_Date_Cast (returns YYYY-MM-DD strings)
     * - TIME columns → 'time' (string)
     *
     * IMPORTANT: Date and datetime columns return STRINGS, not Carbon objects.
     * This prevents timezone bugs and keeps PHP/JS in sync with identical formats.
     *
     * Users can still override by defining their own $casts property.
     *
     * @return array
     */
    public function getCasts(): array
    {
        // Start with parent casts (includes $casts property + timestamps)
        $casts = parent::getCasts();

        // Get table name
        $table_name = $this->getTable();

        // Auto-detect boolean columns (TINYINT(1))
        $boolean_columns = \App\RSpade\Core\Manifest\Manifest::db_get_columns_by_type($table_name, 'boolean');
        foreach ($boolean_columns as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = 'boolean';
            }
        }

        // Auto-detect datetime columns (DATETIME, TIMESTAMP)
        // Returns ISO 8601 UTC strings, NOT Carbon objects
        $datetime_columns = \App\RSpade\Core\Manifest\Manifest::db_get_columns_by_type($table_name, 'datetime');
        foreach ($datetime_columns as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = \App\RSpade\Core\Time\Rsx_DateTime_Cast::class;
            }
        }

        // Auto-detect date columns (DATE)
        // Returns YYYY-MM-DD strings, NOT Carbon objects
        $date_columns = \App\RSpade\Core\Manifest\Manifest::db_get_columns_by_type($table_name, 'date');
        foreach ($date_columns as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = \App\RSpade\Core\Time\Rsx_Date_Cast::class;
            }
        }

        // Auto-detect time columns (TIME)
        // Laravel's 'time' cast returns strings, which is what we want
        $time_columns = \App\RSpade\Core\Manifest\Manifest::db_get_columns_by_type($table_name, 'time');
        foreach ($time_columns as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = 'string';
            }
        }

        // Apply type ref casts for polymorphic type columns
        // These columns store integer IDs but expose class name strings
        foreach (static::_type_ref_columns() as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = \App\RSpade\Core\Database\TypeRefs\Rsx_Type_Ref_Cast::class;
            }
        }

        return $casts;
    }

    /**
     * Override the fill method to prevent mass assignment
     *
     * Exception: Allow timestamp-only updates from Laravel's query builder
     *
     * @param array $attributes
     * @return $this
     * @throws MassAssignmentException
     */
    public function fill(array $attributes)
    {
        if (empty($attributes)) {
            return $this;
        }

        // Allow if ONLY timestamps are being set (Laravel's query builder does this)
        $timestampColumns = array_filter([
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ]);

        $nonTimestampAttributes = array_diff(array_keys($attributes), $timestampColumns);

        // If only timestamps are being set, allow it (Laravel internals)
        if (empty($nonTimestampAttributes)) {
            return parent::fill($attributes);
        }

        // Otherwise, block mass assignment
        throw new MassAssignmentException(
            get_class($this),
            array_keys($attributes)
        );
    }

    /**
     * Override the forceFill method to prevent mass assignment
     *
     * Exception: Allow timestamp-only updates from Laravel's query builder
     *
     * @param array $attributes
     * @return $this
     * @throws MassAssignmentException
     */
    public function forceFill(array $attributes)
    {
        if (empty($attributes)) {
            return $this;
        }

        // Allow if ONLY timestamps are being set (Laravel's query builder does this)
        $timestampColumns = array_filter([
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ]);

        $nonTimestampAttributes = array_diff(array_keys($attributes), $timestampColumns);

        // If only timestamps are being set, allow it (Laravel internals)
        if (empty($nonTimestampAttributes)) {
            return parent::forceFill($attributes);
        }

        // Otherwise, block mass assignment
        throw new MassAssignmentException(
            get_class($this),
            array_keys($attributes),
            true
        );
    }

    /**
     * Override the create method to prevent mass assignment
     *
     * @param array $attributes
     * @return static
     * @throws MassAssignmentException
     */
    public static function create(array $attributes = [])
    {
        if (!empty($attributes)) {
            throw new MassAssignmentException(
                static::class,
                array_keys($attributes),
                false,
                true,
                'create'
            );
        }

        return parent::create($attributes);
    }

    /**
     * Override the firstOrCreate method to prevent mass assignment
     *
     * @param array $attributes
     * @param array $values
     * @return static
     * @throws MassAssignmentException
     */
    public static function firstOrCreate(array $attributes = [], array $values = [])
    {
        if (!empty($attributes) || !empty($values)) {
            throw new MassAssignmentException(
                static::class,
                array_merge(array_keys($attributes), array_keys($values)),
                false,
                true,
                'firstOrCreate'
            );
        }

        return parent::firstOrCreate($attributes, $values);
    }

    /**
     * Override the firstOrNew method to prevent mass assignment
     *
     * @param array $attributes
     * @param array $values
     * @return static
     * @throws MassAssignmentException
     */
    public static function firstOrNew(array $attributes = [], array $values = [])
    {
        if (!empty($attributes) || !empty($values)) {
            throw new MassAssignmentException(
                static::class,
                array_merge(array_keys($attributes), array_keys($values)),
                false,
                true,
                'firstOrNew'
            );
        }

        return parent::firstOrNew($attributes, $values);
    }

    /**
     * Override the updateOrCreate method to prevent mass assignment
     *
     * @param array $attributes
     * @param array $values
     * @return static
     * @throws MassAssignmentException
     */
    public static function updateOrCreate(array $attributes = [], array $values = [])
    {
        if (!empty($attributes) || !empty($values)) {
            throw new MassAssignmentException(
                static::class,
                array_merge(array_keys($attributes), array_keys($values)),
                false,
                true,
                'updateOrCreate'
            );
        }

        return parent::updateOrCreate($attributes, $values);
    }

    /**
     * Override the update method to prevent mass assignment
     *
     * @param array $attributes
     * @param array $options
     * @return bool
     * @throws MassAssignmentException
     */
    public function update(array $attributes = [], array $options = [])
    {
        if (!empty($attributes)) {
            throw new MassAssignmentException(
                get_class($this),
                array_keys($attributes),
                false,
                false,
                'update'
            );
        }

        return parent::update($attributes, $options);
    }

    /**
     * Allow the constructor to work normally for framework internals
     * but log a warning if attributes are passed
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        if (!empty($attributes) && app()->environment() !== 'testing') {
            // Log warning but don't throw exception in constructor
            // Some Laravel internals need this to work
            logger()->warning('Mass assignment attempted in constructor for ' . static::class, [
                'attributes' => array_keys($attributes),
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ]);
        }

        parent::__construct($attributes);
    }

    /**
     * Helper method to create a model with explicit field assignment
     * This is the recommended way to create models in RSpade
     *
     * @return static
     */
    public static function make_new()
    {
        return new static();
    }

    /**
     * Get a helpful example of how to properly create/update this model
     *
     * @return string
     */
    public function get_mass_assignment_example()
    {
        $class = get_class($this);
        $short_class = class_basename($class);
        $var_name = '$' . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short_class));

        return <<<EXAMPLE
// Creating a new {$short_class}:
{$var_name} = new {$short_class}();
{$var_name}->field1 = \$request->input('field1');
{$var_name}->field2 = \$request->input('field2');
{$var_name}->save();

// Updating an existing {$short_class}:
{$var_name} = {$short_class}::find(\$id);
{$var_name}->field1 = \$request->input('field1');
{$var_name}->field2 = \$request->input('field2');
{$var_name}->save();
EXAMPLE;
    }

    /**
     * ==========================================
     * EAGER LOADING PREVENTION
     * ==========================================
     * All forms of eager loading are explicitly disabled in RSpade.
     * Use explicit queries for each relationship instead.
     */

    /**
     * Override the with() method to prevent eager loading
     *
     * @param array|string $relations
     * @return \Illuminate\Database\Eloquent\Builder
     * @throws RuntimeException
     */
    public static function with($relations)
    {
        throw new RuntimeException(
            'Eager loading is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to eager load: ' . (is_array($relations) ? implode(', ', $relations) : $relations)
        );
    }

    /**
     * Override the load() method to prevent lazy eager loading
     *
     * @param array|string $relations
     * @return $this
     * @throws RuntimeException
     */
    public function load($relations)
    {
        throw new RuntimeException(
            'Lazy eager loading is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to lazy eager load: ' . (is_array($relations) ? implode(', ', $relations) : $relations)
        );
    }

    /**
     * Override the loadMissing() method to prevent conditional eager loading
     *
     * @param array|string $relations
     * @return $this
     * @throws RuntimeException
     */
    public function loadMissing($relations)
    {
        throw new RuntimeException(
            'Conditional eager loading is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to conditionally eager load: ' . (is_array($relations) ? implode(', ', $relations) : $relations)
        );
    }

    /**
     * Override the loadCount() method to prevent eager loading counts
     *
     * @param array|string $relations
     * @return $this
     * @throws RuntimeException
     */
    public function loadCount($relations)
    {
        throw new RuntimeException(
            'Eager loading counts is not allowed in the RSpade framework. ' .
            'Use explicit count queries instead. ' .
            'Attempted to eager load counts for: ' . (is_array($relations) ? implode(', ', $relations) : $relations)
        );
    }

    /**
     * Override the loadMorph() method to prevent morphed eager loading
     *
     * @param string $relation
     * @param array $relations
     * @return $this
     * @throws RuntimeException
     */
    public function loadMorph($relation, $relations)
    {
        throw new RuntimeException(
            'Morphed eager loading is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to eager load morph relation: ' . $relation
        );
    }

    /**
     * Override the loadAggregate() method to prevent aggregate eager loading
     *
     * @param array|string $relations
     * @param string $column
     * @param string $function
     * @return $this
     * @throws RuntimeException
     */
    public function loadAggregate($relations, $column, $function = null)
    {
        throw new RuntimeException(
            'Aggregate eager loading is not allowed in the RSpade framework. ' .
            'Use explicit aggregate queries instead. ' .
            'Attempted to eager load aggregates for: ' . (is_array($relations) ? implode(', ', $relations) : $relations)
        );
    }

    /**
     * Override the loadMax() method
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws RuntimeException
     */
    public function loadMax($relations, $column)
    {
        throw new RuntimeException(
            'Max eager loading is not allowed in the RSpade framework. ' .
            'Use explicit max queries instead.'
        );
    }

    /**
     * Override the loadMin() method
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws RuntimeException
     */
    public function loadMin($relations, $column)
    {
        throw new RuntimeException(
            'Min eager loading is not allowed in the RSpade framework. ' .
            'Use explicit min queries instead.'
        );
    }

    /**
     * Override the loadSum() method
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws RuntimeException
     */
    public function loadSum($relations, $column)
    {
        throw new RuntimeException(
            'Sum eager loading is not allowed in the RSpade framework. ' .
            'Use explicit sum queries instead.'
        );
    }

    /**
     * Override the loadAvg() method
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws RuntimeException
     */
    public function loadAvg($relations, $column)
    {
        throw new RuntimeException(
            'Avg eager loading is not allowed in the RSpade framework. ' .
            'Use explicit avg queries instead.'
        );
    }

    /**
     * Override the loadExists() method
     *
     * @param array|string $relations
     * @return $this
     * @throws RuntimeException
     */
    public function loadExists($relations)
    {
        throw new RuntimeException(
            'Exists eager loading is not allowed in the RSpade framework. ' .
            'Use explicit exists queries instead.'
        );
    }

    /**
     * ==========================================
     * QUERY BUILDER CUSTOMIZATION
     * ==========================================
     */

    /**
     * Create a new Eloquent query builder that blocks eager loading
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newEloquentBuilder($query)
    {
        return new RestrictedEloquentBuilder($query);
    }

    /**
     * Override newCollection to prevent eager loading via collections
     * Collections can also trigger eager loading through methods like load()
     *
     * @param array $models
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function newCollection(array $models = [])
    {
        return new \App\Database\RestrictedEloquentCollection($models);
    }

    /**
     * ==========================================
     * AJAX ORM INTERFACE
     * ==========================================
     */

    /**
     * Fetch model record(s) by ID for Ajax ORM
     *
     * This method MUST be overridden in child classes that want to enable Ajax fetching.
     * Child classes must:
     * 1. Add the #[Ajax_Endpoint_Model_Fetch] attribute to their fetch() implementation
     * 2. Check authorization (e.g., can current user view this record?)
     * 3. Filter sensitive data (e.g., remove password fields)
     *
     * @param int|array $id Single ID or array of IDs
     * @throws RuntimeException Always - this method must be implemented by child classes
     */
    #[Replaceable]
    public static function fetch($id)
    {
        throw new RuntimeException(
            "Cannot call fetch() on abstract Rsx_Model_Abstract class directly.\n\n" .
            'To enable Ajax fetching for ' . static::class . ", you must:\n" .
            "1. Override the fetch() method in your model class\n" .
            "2. Add the #[Ajax_Endpoint_Model_Fetch] attribute to your fetch() method\n" .
            "3. Implement authorization checks (can current user view these records?)\n" .
            "4. Filter sensitive data before returning (e.g., unset password fields)\n\n" .
            "Example implementation:\n\n" .
            "#[Ajax_Endpoint_Model_Fetch]\n" .
            "public static function fetch(\$id)\n" .
            "{\n" .
            "    // Check authorization\n" .
            "    if (!Session::is_logged_in()) {\n" .
            "        return false;\n" .
            "    }\n\n" .
            "    // Fetch single record\n" .
            "    \$model = static::find(\$id);\n" .
            "    return \$model ?: false;\n" .
            '}'
        );
    }

    /**
     * Get table name statically without instantiating the model
     * Cached to avoid repeated instantiation
     *
     * @return string The table name for this model
     */
    public static function get_table_static()
    {
        static $tableToModelMap = [];
        $className = get_called_class();

        if (empty($tableToModelMap[$className])) {
            $instance = new $className();
            $tableToModelMap[$className] = $instance->getTable();
        }

        return $tableToModelMap[$className];
    }

    /**
     * Get column listing for this model's table
     * Uses Laravel's Schema facade to get column information
     *
     * @return array Array of column names
     */
    public static function getColumns()
    {
        $tableName = self::get_table_static();

        return Schema::getColumnListing($tableName);
    }

    /**
     * Check if a column exists in this model's table
     *
     * @param string $column Column name to check
     * @return bool True if column exists
     */
    public static function hasColumn($column)
    {
        $columns = self::getColumns();

        return in_array($column, $columns);
    }

    /**
     * Re-entrancy depth of a SINGLE model write (this class's own save()/delete()). A single
     * write already routes its DB statement through RestrictedEloquentBuilder::update()/delete()
     * (Eloquent's performUpdate / performDeleteOnModel run through the model's builder), so the
     * builder must be able to tell "this update()/delete() IS a model's own single-row write"
     * (do the raw statement — the model emits realtime + hooks itself) from "this is a genuine
     * user bulk statement" (fetch-then-iterate). The model bumps this counter strictly around
     * its parent::save()/parent::delete() DB write; the builder reads it (> 0 => internal).
     *
     * A COUNTER, not a boolean: an Eloquent saving/updating/updated observer may itself write
     * another model inside parent::save(); that nested single write must not clear the outer
     * write's guard, so the flag is active while depth > 0.
     *
     * @var int
     */
    public static int $_single_model_write_depth = 0;

    /**
     * Whether the current call stack is inside a single model's own save()/delete() DB write
     * (so a builder update()/delete() reached now is that write's raw single-row statement, not
     * a user bulk op). The single choke point the builder consults for its re-entrancy guard.
     */
    public static function _is_inside_single_model_write(): bool
    {
        return self::$_single_model_write_depth > 0;
    }

    /**
     * Override save to fire realtime emission on a committed write
     */
    public function save(array $options = [])
    {
        // CTI: the discriminator is immutable once set (it selects the detail table; changing
        // it would orphan the existing detail). Reject before the write.
        $this->__assert_detail_discriminator_immutable();

        // Snapshot existence BEFORE the write: an INSERT is a not-yet-existing record that
        // exists after a successful save. wasRecentlyCreated is unreliable here — it stays
        // true for the instance's lifetime, so reusing one instance for a later update would
        // misclassify the update as a create.
        $was_existing = $this->exists;

        // Stamp authorship BEFORE the write so the columns ride the same INSERT/UPDATE.
        $this->__apply_audit_stamp($was_existing);

        // Guard the DB write: any RestrictedEloquentBuilder::update() reached from inside
        // parent::save() (Eloquent's performUpdate) is THIS record's own single-row write and
        // must run raw — never re-enter the user-bulk fetch-then-iterate path (that would
        // recurse). Decremented in finally so an exception cannot leave the guard stuck on.
        self::$_single_model_write_depth++;
        try {
            $result = parent::save($options);
        } finally {
            self::$_single_model_write_depth--;
        }

        if ($result) {
            $this->_dispatch_write_effects_on_save($was_existing);
        }

        return $result;
    }

    /**
     * Re-entrancy guard for the audit stamp. Resolving the actor reads the session facades,
     * and reading a session can itself write the _sessions row - a nested save() that must
     * not try to resolve the actor again (infinite recursion). While this is true the stamp
     * is skipped entirely.
     *
     * @var bool
     */
    private static bool $_audit_stamp_resolving = false;

    /**
     * Per-process memo of "does this table carry audit column set X", keyed by
     * "{table}|{set}". Only ever grows, and only for tables actually written to.
     *
     * The two sets are memoized SEPARATELY because a table can carry one without the other:
     * every table has the authorship pairs, only a soft-deleting table has the deletion pair.
     *
     * @var array<string, bool>
     */
    private static array $_audit_columns_present = [];

    /**
     * The framework audit authorship columns. Present on every table (the schema-hygiene
     * command puts them there), NULL until an identified actor writes the record.
     */
    private const AUDIT_COLUMNS = ['created_by_id', 'created_by_type', 'updated_by_id', 'updated_by_type'];

    /**
     * The framework audit DELETION columns. Present only on tables that soft-delete: a hard
     * delete removes the row, so there is nothing to stamp and no column worth carrying. The
     * schema-hygiene command puts them on every table that has a deleted_at.
     */
    private const AUDIT_DELETED_COLUMNS = ['deleted_by_id', 'deleted_by_type'];

    /**
     * The ONLY class names the audit stamp will ever write into a *_by_type column,
     * keyed by the cell of the stamp matrix they serve. This is the single declaration
     * of that set: __resolve_audit_actor() reads it rather than repeating the literals.
     *
     * Public because the ACTOR-01 code-quality rule reads it to verify, at manifest
     * build time, that each named class still extends the actor layer - including after
     * a downstream app replaces one through the class-override pattern. A stamp target
     * that is not an actor would produce audit columns nothing can resolve a name for.
     *
     * @var array<string, string>
     */
    public const AUDIT_ACTOR_MODELS = [
        'staff_site' => 'User_Model',
        'portal' => 'Portal_User_Model',
        'cross_site' => 'Login_User_Model',
    ];

    /**
     * Per-process memo of get_relationships(), keyed by FQCN. isRelation() is on Eloquent's
     * attribute-miss path, so the manifest lookup behind it is worth doing once per class.
     *
     * @var array<string, array<int, string>>
     */
    private static array $_relationships_cache = [];

    /**
     * Stamp WHO is writing this record.
     *
     * INSERT stamps both pairs; UPDATE stamps only the updated_by pair, and only when the
     * write is going to happen anyway (an update with nothing dirty stays a no-op - stamping
     * it would manufacture a write). An explicitly-assigned value always wins: application
     * code that sets its own authorship is never overwritten.
     *
     * THE STAMP MATRIX. "Sites match" means the record is site-scoped AND its site_id equals
     * the actor's session site AND that site is not 0 (0 is "no site selected", not a site):
     *
     *   | actor                                  | sites match | stamp                    |
     *   |----------------------------------------|-------------|--------------------------|
     *   | portal user (portal request)           | yes         | Portal_User_Model        |
     *   | portal user (portal request)           | no          | NOTHING (see below)      |
     *   | staff (web, CLI, API, impersonating)   | yes         | User_Model               |
     *   | staff                                  | no          | Login_User_Model         |
     *   | nobody signed in                       | -           | NOTHING                  |
     *
     * The portal "no" cell is deliberately empty rather than a Login_User_Model stamp: a
     * portal account has no cross-site identity at all, so there is nothing truthful to
     * write. A NULL pair reads as "unattributed", which is the honest answer; inventing an
     * identity would be a lie in an audit column.
     *
     * Impersonation is intentionally invisible here - the stamp records the EFFECTIVE actor
     * (who the write is attributed to), which is what an audit column asks. The impersonator
     * is recorded on the session.
     *
     * A table without the columns is skipped, which is what makes this safe DURING a
     * migration run: a data-seed migration whose position in history predates the audit-pair
     * migration writes through live models against a schema that has not been converged yet.
     *
     * THE DELETION PAIR IS ALSO HANDLED HERE, but only for the saves that move deleted_at:
     * a record whose deleted_at goes null -> set is stamped with its deleter, and one whose
     * deleted_at goes set -> null (a restore) has the pair CLEARED. The soft delete performed
     * by SoftDeletes::delete() does NOT come through save() at all - Laravel's runSoftDelete()
     * writes its own two-column UPDATE and ignores dirty attributes - so that path is stamped
     * at the write choke point instead (see _merge_soft_delete_stamp).
     *
     * @param bool $was_existing Whether the record existed before this save (false = INSERT)
     */
    private function __apply_audit_stamp(bool $was_existing): void
    {
        if (self::$_audit_stamp_resolving) {
            return;
        }

        $has_authorship = $this->__table_has_audit_columns();
        $has_deletion = $this->__table_has_deleted_audit_columns();

        if (!$has_authorship && !$has_deletion) {
            return;
        }

        // An UPDATE that changes nothing must remain a no-op.
        if ($was_existing && !$this->isDirty()) {
            return;
        }

        $attributes = $this->getAttributes();

        $deleted_by_is_explicit = $was_existing
            ? ($this->isDirty('deleted_by_id') || $this->isDirty('deleted_by_type'))
            : (array_key_exists('deleted_by_id', $attributes) || array_key_exists('deleted_by_type', $attributes));

        $deleted_at_column = $this->__deleted_at_column();

        // RESTORE. Clearing the pair is not an attribution, so it happens whether or not there
        // is an actor to name - a restored record is not deleted BY anybody, exactly as its
        // deleted_at is no longer set.
        if ($has_deletion
            && !$deleted_by_is_explicit
            && $this->isDirty($deleted_at_column)
            && $this->{$deleted_at_column} === null) {
            $this->deleted_by_type = null;
            $this->deleted_by_id = null;
        }

        self::$_audit_stamp_resolving = true;
        try {
            $actor = $this->__resolve_audit_actor();
        } finally {
            self::$_audit_stamp_resolving = false;
        }

        if ($actor === null) {
            return;
        }

        // A pair is treated as ONE unit: if either half was assigned by hand, the whole pair
        // is the application's to own (a half-set morph pair fails loud on read, so it must
        // never be the framework mixing its stamp into a hand-written one).
        if ($has_authorship) {
            if (!$was_existing
                && !array_key_exists('created_by_id', $attributes)
                && !array_key_exists('created_by_type', $attributes)) {
                $this->created_by_type = $actor['type'];
                $this->created_by_id = $actor['id'];
            }

            $updated_by_is_explicit = $was_existing
                ? ($this->isDirty('updated_by_id') || $this->isDirty('updated_by_type'))
                : (array_key_exists('updated_by_id', $attributes) || array_key_exists('updated_by_type', $attributes));

            if (!$updated_by_is_explicit) {
                $this->updated_by_type = $actor['type'];
                $this->updated_by_id = $actor['id'];
            }
        }

        // SOFT DELETE performed by hand (deleted_at assigned, then save()) - the shape
        // File_Attachment_Model::force_destroy() uses. Only a transition INTO the deleted
        // state stamps; re-saving an already-deleted record does not rewrite its deleter.
        if ($has_deletion
            && !$deleted_by_is_explicit
            && $this->isDirty($deleted_at_column)
            && $this->{$deleted_at_column} !== null) {
            $this->deleted_by_type = $actor['type'];
            $this->deleted_by_id = $actor['id'];
        }
    }

    /**
     * The soft-delete timestamp column. Always `deleted_at` by framework schema convention;
     * resolved through the trait when the model has it so the two stamp paths key on exactly
     * the same column Laravel does.
     *
     * @return string
     */
    private function __deleted_at_column(): string
    {
        return method_exists($this, 'getDeletedAtColumn') ? $this->getDeletedAtColumn() : 'deleted_at';
    }

    /**
     * Merge the deletion stamp into a soft delete's UPDATE values, so deleted_by_id/_type ride
     * the SAME statement that sets deleted_at.
     *
     * WHY THIS IS NOT IN save(). A SoftDeletes delete never reaches save(): Laravel's
     * runSoftDelete() builds its own two-column array ([deleted_at, updated_at]), calls
     * $query->update() with it, and DISCARDS every dirty attribute on the model. Setting the
     * pair before parent::delete() would therefore write nothing. The model's own builder is
     * where that UPDATE actually lands (RestrictedEloquentBuilder::update(), under the
     * single-model-write guard), so that is where the pair is merged in - one statement, no
     * follow-up write against a row that is already soft-deleted.
     *
     * Framework-internal ('_' prefix); the builder is its only caller.
     *
     * @param array<string, mixed> $values The columns the soft delete is about to write.
     * @return array<string, mixed> The same array, plus the deletion pair when one applies.
     */
    public function _merge_soft_delete_stamp(array $values): array
    {
        if (self::$_audit_stamp_resolving) {
            return $values;
        }

        // Not a soft-deleting model at all (getDeletedAtColumn() comes from the trait).
        if (!method_exists($this, 'getDeletedAtColumn')) {
            return $values;
        }

        $deleted_at_column = $this->__deleted_at_column();

        // Not a soft delete: an ordinary performUpdate whose dirty set happens to arrive here,
        // or a restore (deleted_at -> null), which rides save() and is stamped there.
        if (!array_key_exists($deleted_at_column, $values) || $values[$deleted_at_column] === null) {
            return $values;
        }

        // Already carried by the save() path (force_destroy's hand-assigned deleted_at).
        if (array_key_exists('deleted_by_id', $values) || array_key_exists('deleted_by_type', $values)) {
            return $values;
        }

        if (!$this->__table_has_deleted_audit_columns()) {
            return $values;
        }

        // EXPLICIT WINS - and here it also repairs a Laravel behavior: a caller that assigned
        // the pair before calling delete() would otherwise have those attributes silently
        // dropped by runSoftDelete(). Carrying them into the statement is what honors them.
        if (!$this->isDirty('deleted_by_id') && !$this->isDirty('deleted_by_type')) {
            self::$_audit_stamp_resolving = true;
            try {
                $actor = $this->__resolve_audit_actor();
            } finally {
                self::$_audit_stamp_resolving = false;
            }

            if ($actor === null) {
                return $values;
            }

            // Assign through the model so the type-ref cast converts the class name to its
            // registry id (and registers it) - and so the in-memory record matches the row.
            $this->deleted_by_type = $actor['type'];
            $this->deleted_by_id = $actor['id'];
        }

        // Raw attributes: the statement takes stored values, not cast-facing class names.
        $raw = $this->getAttributes();
        $values['deleted_by_id'] = $raw['deleted_by_id'] ?? null;
        $values['deleted_by_type'] = $raw['deleted_by_type'] ?? null;

        return $values;
    }

    /**
     * Whether this model's table carries the audit authorship pair.
     *
     * Two tiers, both cheap. The manifest's model metadata is consulted first and is free -
     * in steady state it answers every call with no query at all. It is only ever trusted
     * when AFFIRMATIVE; anything else (a table the manifest has not indexed, or a manifest
     * built before the columns existed) falls through to one authoritative column listing,
     * memoized for the life of the process. The reverse mistake - trusting a manifest that
     * claims columns the database does not have - would produce an unknown-column INSERT,
     * so it is never made.
     *
     * @return bool
     */
    private function __table_has_audit_columns(): bool
    {
        return $this->__table_has_column_set('authorship', self::AUDIT_COLUMNS);
    }

    /**
     * Whether this model's table carries the audit DELETION pair.
     *
     * Asked SEPARATELY from the authorship pairs and memoized under its own key, because the
     * answers genuinely differ per table: every table has created_by/updated_by, only a table
     * with deleted_at has deleted_by. Folding the two questions into one check would turn off
     * the authorship stamp on every hard-deleting table in the schema.
     *
     * @return bool
     */
    private function __table_has_deleted_audit_columns(): bool
    {
        return $this->__table_has_column_set('deletion', self::AUDIT_DELETED_COLUMNS);
    }

    /**
     * Whether this model's table carries every column of one named audit set. See
     * __table_has_audit_columns() for the two-tier resolution this implements.
     *
     * @param string $set_name Memo key discriminator ('authorship' / 'deletion')
     * @param array<int, string> $columns
     * @return bool
     */
    private function __table_has_column_set(string $set_name, array $columns): bool
    {
        $table = $this->getTable();
        $memo_key = $table . '|' . $set_name;

        if (isset(self::$_audit_columns_present[$memo_key])) {
            return self::$_audit_columns_present[$memo_key];
        }

        $manifest_columns = \App\RSpade\Core\Manifest\Manifest::db_get_table_columns($table);
        foreach ($columns as $column) {
            if (!isset($manifest_columns[$column])) {
                $manifest_columns = null;
                break;
            }
        }

        if ($manifest_columns !== null) {
            self::$_audit_columns_present[$memo_key] = true;

            return true;
        }

        $live_columns = Schema::getColumnListing($table);
        $present = true;
        foreach ($columns as $column) {
            if (!in_array($column, $live_columns, true)) {
                $present = false;
                break;
            }
        }

        self::$_audit_columns_present[$memo_key] = $present;

        return $present;
    }

    /**
     * Resolve the actor to stamp on this record, per the matrix documented on
     * __apply_audit_stamp(). Returns ['type' => simple class name, 'id' => int] or null when
     * there is nobody truthful to name.
     *
     * @return array{type: string, id: int}|null
     */
    private function __resolve_audit_actor(): ?array
    {
        // Portal realm. A portal request is a wholly separate identity space - never fall
        // through to the staff facade, which would attribute a client's write to whatever
        // staff cookie happens to be in the same browser.
        if (\App\RSpade\Core\Portal\Rsx_Portal::is_portal_request()) {
            if (!\App\RSpade\Core\Portal\Portal_Session::is_logged_in()) {
                return null;
            }

            $portal_user_id = \App\RSpade\Core\Portal\Portal_Session::get_portal_user_id();
            if (empty($portal_user_id)) {
                return null;
            }

            if (!$this->__record_site_matches(\App\RSpade\Core\Portal\Portal_Session::get_site_id())) {
                return null;
            }

            return ['type' => self::AUDIT_ACTOR_MODELS['portal'], 'id' => (int) $portal_user_id];
        }

        $login_user_id = \App\RSpade\Core\Session\Session::get_login_user_id();
        if (empty($login_user_id)) {
            return null;
        }

        if ($this->__record_site_matches(\App\RSpade\Core\Session\Session::get_site_id())) {
            $user_id = \App\RSpade\Core\Session\Session::get_user_id();
            if (!empty($user_id)) {
                return ['type' => self::AUDIT_ACTOR_MODELS['staff_site'], 'id' => (int) $user_id];
            }
        }

        return ['type' => self::AUDIT_ACTOR_MODELS['cross_site'], 'id' => (int) $login_user_id];
    }

    /**
     * Whether this record belongs to the actor's site, which is what selects the site-scoped
     * actor model over the cross-site one.
     *
     * A record only HAS a site when its model is site-scoped. For a new site-scoped record
     * the site_id attribute has not been forced from the session yet (that happens in the
     * `creating` hook, inside parent::save()), so the session's site is the answer - which is
     * exactly what that hook is about to write. Site 0 means "no site selected" and never
     * matches.
     *
     * @param int $actor_site_id
     * @return bool
     */
    private function __record_site_matches(int $actor_site_id): bool
    {
        if (!($this instanceof Rsx_Site_Model_Abstract)) {
            return false;
        }

        if ($actor_site_id === 0) {
            return false;
        }

        $record_site_id = $this->site_id;
        if ($record_site_id === null) {
            $record_site_id = $actor_site_id;
        }

        return (int) $record_site_id === $actor_site_id;
    }

    /**
     * The actor that created this record: a User_Model, Portal_User_Model or
     * Login_User_Model, or null when the record is unattributed. Resolved through the
     * created_by_type / created_by_id polymorphic pair.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    #[Relationship]
    public function created_by()
    {
        return $this->morphTo('created_by', 'created_by_type', 'created_by_id');
    }

    /**
     * The actor that last updated this record. See created_by().
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    #[Relationship]
    public function updated_by()
    {
        return $this->morphTo('updated_by', 'updated_by_type', 'updated_by_id');
    }

    /**
     * The actor that soft-deleted this record, or null when the record is live, was deleted
     * by nobody identifiable, or lives on a table that does not soft-delete at all (the pair
     * only exists where deleted_at does). See created_by().
     *
     * A restore clears the pair, so this always answers for the CURRENT deletion.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    #[Relationship]
    public function deleted_by()
    {
        return $this->morphTo('deleted_by', 'deleted_by_type', 'deleted_by_id');
    }

    /**
     * Per-request memo for resolved authorship display, keyed by VIEWER identity +
     * actor. get_view_profile_url() is explicitly forbidden from memoizing across
     * realms, so the viewer tuple is part of the key rather than assumed constant -
     * a CLI process, a test using __acting_as_user(), or a queued task that changes
     * identity mid-process gets a fresh answer.
     *
     * @var array<string, array{name: string, url: ?string}|null>
     */
    private static array $_author_display_cache = [];

    /**
     * Who created this record, ready to display: ['name' => string, 'url' => ?string],
     * or null when the record is unattributed.
     *
     * This is the server-side half of the <Record_Author> widget. Call it from a
     * controller endpoint or a model fetch() and hand the result to the component:
     *
     *     $data['created_by_author'] = $record->get_created_by_author();
     *     // template: <Record_Author $author=this.data.record.created_by_author />
     *
     * WHY IT IS EXPLICIT AND NOT AUTOMATIC IN toArray(). Resolving an author costs a
     * query and discloses a name, so doing it on every serialization in the framework
     * would tax and expose paths that never display authorship. Endpoints that show it
     * ask for it. There is deliberately no Ajax endpoint for "who wrote record X"
     * either: that would be a new identity-lookup surface over arbitrary records,
     * gated by nothing the record's own fetch() already decided.
     *
     * The N+1 a list page would otherwise pay is absorbed by the per-request memo - a
     * datagrid of 200 rows written by 3 people costs 3 lookups.
     *
     * TRASHED ACTORS RESOLVE. Actors soft-delete precisely so historical authorship
     * keeps reading; the lookup includes trashed rows and the name comes back
     * unchanged (no "(deleted)" marker - that is the actor contract, and a screen that
     * wants to mark it reads trashed() itself).
     *
     * @return array{name: string, url: ?string}|null
     */
    public function get_created_by_author(): ?array
    {
        return $this->__resolve_author_display('created_by');
    }

    /**
     * Who last updated this record, ready to display. See get_created_by_author().
     *
     * @return array{name: string, url: ?string}|null
     */
    public function get_updated_by_author(): ?array
    {
        return $this->__resolve_author_display('updated_by');
    }

    /**
     * Who soft-deleted this record, ready to display - the datum a "recently deleted" /
     * recovery screen exists to show. Null on a live record. See get_created_by_author().
     *
     * @return array{name: string, url: ?string}|null
     */
    public function get_deleted_by_author(): ?array
    {
        return $this->__resolve_author_display('deleted_by');
    }

    /**
     * Resolve one authorship relation to its display pair.
     *
     * A half-set pair (id without type, or type without id) resolves to null rather
     * than throwing: the stamp writes both halves together, so a half-set pair is
     * hand-written data the framework has nothing truthful to say about.
     *
     * @param string $relation 'created_by', 'updated_by' or 'deleted_by'
     * @return array{name: string, url: ?string}|null
     */
    private function __resolve_author_display(string $relation): ?array
    {
        $type = $this->{$relation . '_type'};
        $id = $this->{$relation . '_id'};

        if (empty($type) || empty($id)) {
            return null;
        }

        $cache_key = implode('|', [
            \App\RSpade\Core\Portal\Rsx_Portal::is_portal_request() ? 'portal' : 'staff',
            (int) \App\RSpade\Core\Session\Session::get_user_id(),
            (int) \App\RSpade\Core\Session\Session::get_login_user_id(),
            (int) \App\RSpade\Core\Portal\Portal_Session::get_portal_user_id(),
            (string) $type,
            (int) $id,
        ]);

        if (array_key_exists($cache_key, self::$_author_display_cache)) {
            return self::$_author_display_cache[$cache_key];
        }

        // withTrashed: a deleted actor must still print. The site global scope is
        // deliberately NOT bypassed - the stamp matrix only writes a site-scoped actor
        // when its site matches the record's, so an in-scope reader of the record can
        // always see its author.
        $actor = $this->{$relation}()->withTrashed()->first();

        // Either half of the actor layer answers; there is no shared interface to test
        // against (the manifest indexes classes, not interfaces).
        $display = null;
        if ($actor instanceof Rsx_Actor_Model_Abstract || $actor instanceof Rsx_Site_Actor_Model_Abstract) {
            $display = [
                'name' => $actor->get_printed_name(),
                'url' => $actor->get_view_profile_url(),
            ];
        }

        self::$_author_display_cache[$cache_key] = $display;

        return $display;
    }

    /**
     * Override delete to fire realtime emission on a committed write.
     *
     * A SOFT delete is also stamped with its deleter (deleted_by_id/_type), merged into the
     * same UPDATE by _merge_soft_delete_stamp() at the builder. A HARD delete is not stamped
     * and cannot be: the row it would be written on ceases to exist.
     */
    public function delete()
    {
        // Guard the DB write: a hard delete reaches RestrictedEloquentBuilder::delete() and a
        // SoftDeletes delete reaches builder::update([deleted_at]) — both from inside
        // parent::delete(). Under the guard those run raw (no re-iterate), and the model fires
        // its own realtime + after_delete once below.
        self::$_single_model_write_depth++;
        try {
            $result = parent::delete();
        } finally {
            self::$_single_model_write_depth--;
        }

        if ($result) {
            // The deletion pair was written by the soft delete's own UPDATE, which syncs only
            // the columns IT knows about - so mark ours clean too, or the next save() would
            // re-write values already in the row.
            $attributes = $this->getAttributes();
            foreach (self::AUDIT_DELETED_COLUMNS as $column) {
                if (array_key_exists($column, $attributes)) {
                    $this->syncOriginalAttribute($column);
                }
            }

            $this->_dispatch_write_effects_on_delete();
        }

        return $result;
    }

    /**
     * The single post-write dispatch for a committed save(). Realtime emission is now one
     * EFFECT under the lifecycle dispatch (a consumer of the same write signal), not a
     * parallel model path: the realtime effect runs first (preserving frame ordering), then
     * the app after_create/after_update hooks. Both are individually gated and no-op for a
     * model that opts into neither.
     */
    private function _dispatch_write_effects_on_save(bool $was_existing): void
    {
        $this->_realtime_emit_on_write();
        $this->_lifecycle_dispatch_on_save($was_existing);
    }

    /**
     * The single post-write dispatch for a committed delete() — realtime effect then the
     * after_delete hook (see _dispatch_write_effects_on_save).
     */
    private function _dispatch_write_effects_on_delete(): void
    {
        $this->_realtime_emit_on_write();
        $this->_lifecycle_dispatch_on_delete();
    }

    /**
     * Lifecycle hook: fired after this record is first INSERTED, once the write has
     * COMMITTED (single-write path). Override on a model to run a post-create side effect;
     * base default is a no-op. Runs synchronously in the caller's post-commit context and is
     * DISCARDED if the surrounding transaction rolls back.
     *
     * Marked #[Replaceable] — this is a template hook, so an overriding model REPLACES it and
     * must NOT call parent::after_create() (the parent-call-chain rule is suppressed for
     * replaceable methods).
     */
    #[Replaceable]
    public function after_create(): void
    {
    }

    /**
     * Lifecycle hook: fired after this record is UPDATED, once the write has COMMITTED
     * (single-write path). Receives the changed column names (the keys of the write's dirty
     * set). Only fires when the update actually changed columns. Override on a model; base
     * default is a no-op. Runs synchronously in the caller's post-commit context and is
     * DISCARDED if the surrounding transaction rolls back.
     *
     * Marked #[Replaceable] — an overriding model REPLACES it and must NOT call
     * parent::after_update().
     *
     * @param array<int, string> $changed_fields Names of the columns changed by this write.
     */
    #[Replaceable]
    public function after_update(array $changed_fields): void
    {
    }

    /**
     * Lifecycle hook: fired after this record is DELETED, once the write has COMMITTED
     * (single-write path). Override on a model to run post-delete cleanup (e.g. blob
     * removal); base default is a no-op. Runs synchronously in the caller's post-commit
     * context and is DISCARDED if the surrounding transaction rolls back.
     *
     * Marked #[Replaceable] — an overriding model REPLACES it and must NOT call
     * parent::after_delete().
     */
    #[Replaceable]
    public function after_delete(): void
    {
    }

    /**
     * Queue the lifecycle hook for a committed save() (create vs update). No-op for a model
     * that declares no hook (the common case — one cached boolean). CREATE vs UPDATE is
     * distinguished by the pre-write existence snapshot ($was_existing false = INSERT). An
     * update with an empty changed set performed no DB write (Laravel skips the query when
     * not dirty), so it queues nothing.
     */
    private function _lifecycle_dispatch_on_save(bool $was_existing): void
    {
        if (!Model_Lifecycle_Registry::has_lifecycle_surface($this)) {
            return;
        }

        if (!$was_existing) {
            if (Model_Lifecycle_Registry::declares($this, 'after_create')) {
                Model_Lifecycle_Emissions::queue($this, Model_Lifecycle_Emissions::EVENT_CREATE);
            }

            return;
        }

        $changed = array_keys($this->getChanges());

        if (!empty($changed) && Model_Lifecycle_Registry::declares($this, 'after_update')) {
            Model_Lifecycle_Emissions::queue($this, Model_Lifecycle_Emissions::EVENT_UPDATE, $changed);
        }
    }

    /**
     * Queue the lifecycle hook for a committed delete(). No-op unless the model declares
     * after_delete().
     */
    private function _lifecycle_dispatch_on_delete(): void
    {
        if (!Model_Lifecycle_Registry::has_lifecycle_surface($this)) {
            return;
        }

        if (Model_Lifecycle_Registry::declares($this, 'after_delete')) {
            Model_Lifecycle_Emissions::queue($this, Model_Lifecycle_Emissions::EVENT_DELETE);
        }
    }

    /**
     * Parent model instances whose result sets depend on THIS record — a change to this
     * record should also notify subscribers of those parents. The touch cascade queues an
     * emission for each returned parent (and their parents, cycle-guarded) WITHOUT writing
     * any parent row. Override on a child model; base default is no dependencies.
     *
     * Example: Contact_Model::realtime_touch() returns [$this->client()] so editing a
     * contact live-refreshes the client's contacts result set.
     *
     * @return Rsx_Model_Abstract[]
     */
    #[Replaceable]
    public function realtime_touch(): array
    {
        return [];
    }

    /**
     * Manually queue a realtime change emission for THIS record — the explicit companion
     * to the automatic save()/delete() hook. Use it after a write that bypasses the model
     * layer (a query-builder / bulk / raw SQL update, an import, a derived-value change):
     * those never fire the auto hook, so subscribers would otherwise never be notified.
     *
     * Calling this IS the intent, so it works regardless of static::$realtime — but it is
     * still a no-op when realtime is disabled (safe to leave in code unconditionally). It
     * requires a persisted record; a manual emit on an unsaved row is a programming error
     * and fails loud. It runs the SAME path as auto-emission (touch cascade included) and
     * is deduped to at most one emission per (model, id) per request (see
     * Realtime_Emissions::request_emit). It never emits uncommitted state — inside a
     * transaction the emission still flushes on commit and is discarded on rollback.
     */
    public function realtime_emit(): void
    {
        if (!Realtime::is_enabled()) {
            return;
        }

        if (empty($this->id)) {
            shouldnt_happen('realtime_emit() requires a persisted record (empty id) — a manual emit on an unsaved row is a bug.');
        }

        Realtime_Emissions::request_emit($this);
    }

    /**
     * Realtime hook fired from the save()/delete() success path (the single model write
     * choke point). Query-builder writes (DB::table, Model::where()->update()) bypass the
     * model layer by design and do not emit. No-op unless realtime is enabled.
     */
    private function _realtime_emit_on_write(): void
    {
        if (!Realtime::is_enabled()) {
            return;
        }

        // Model-change emission: only opt-in models publish their own change (plus the
        // realtime_touch() method cascade, walked inside queue_model_change()).
        if (static::$realtime) {
            Realtime_Emissions::queue_model_change($this);
        } elseif (Realtime_Touch_Registry::overrides_realtime_touch(static::class)) {
            // Method-surface touch-only model: walk the realtime_touch() cascade without
            // publishing the model's own frame, mirroring #[Realtime_Touch] semantics.
            Realtime_Emissions::queue_touch_cascade($this);
        }

        // #[Realtime_Touch] parent emissions: fired regardless of this model's own $realtime
        // flag — a touch-only child (not itself watched) must still notify its parents. No-op
        // when the model declares no attributed relationships.
        Realtime_Emissions::queue_attributed_touches($this);

        // Emitter dirty-kick: any non-silent write schedules a (gated) emitter recompute.
        if (!static::$realtime_silent) {
            Realtime_Emissions::mark_emitters_dirty();
        }
    }

    /**
     * Get relationship method names for this model
     *
     * Returns every method marked with the #[Relationship] attribute on this class AND on
     * each of its manifest-visible ancestors. The manifest indexes methods per FILE, so a
     * concrete model's own entry never lists an inherited relation - the framework audit
     * relations (created_by / updated_by / deleted_by) are declared on Rsx_Model_Abstract
     * and reach every model through this walk.
     *
     * @return array Array of relationship method names
     */
    public static function get_relationships(): array
    {
        $called_class = get_called_class();

        if (isset(self::$_relationships_cache[$called_class])) {
            return self::$_relationships_cache[$called_class];
        }

        $manifest_data = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_fqcn($called_class);

        if (!$manifest_data) {
            throw new RuntimeException("Class {$called_class} not found in manifest");
        }

        // Climb extends_fqcn, collecting each class's OWN attributed methods. The walk ends
        // at the last manifest-visible ancestor: Eloquent's Model has no manifest entry, which
        // is the natural terminator. An override redeclares the name (RELATIONSHIP-OVERRIDE-01
        // requires it to carry the attribute too), so the union is de-duplicated by name.
        $relationships = [];
        $entry = $manifest_data;
        $seen_classes = [$called_class => true];

        while ($entry !== null) {
            foreach ($entry['public_instance_methods'] ?? [] as $method_name => $method_data) {
                if (isset($method_data['attributes']['Relationship'])) {
                    $relationships[] = $method_name;
                }
            }

            $parent_fqcn = $entry['extends_fqcn'] ?? null;
            if ($parent_fqcn === null || isset($seen_classes[$parent_fqcn])) {
                break;
            }

            $seen_classes[$parent_fqcn] = true;
            $entry = self::__manifest_entry_for_fqcn($parent_fqcn);
        }

        $relationships = array_values(array_unique($relationships));
        self::$_relationships_cache[$called_class] = $relationships;

        return $relationships;
    }

    /**
     * The manifest entry declaring $fqcn, or null when the class is not indexed.
     *
     * Null is an ordinary answer, not an error: the manifest never scans vendor/, so a
     * lineage walk reaches an unindexed ancestor (Eloquent's Model) at its root. The
     * php_classes index is keyed by SIMPLE name, so the resolved entry is confirmed to be
     * this exact class before it is used.
     */
    private static function __manifest_entry_for_fqcn(string $fqcn): ?array
    {
        $simple_name = \App\RSpade\Core\Manifest\Manifest::_normalize_class_name($fqcn);

        try {
            $file = \App\RSpade\Core\Manifest\Manifest::php_find_class($simple_name);
        } catch (RuntimeException $e) {
            return null;
        }

        $entry = \App\RSpade\Core\Manifest\Manifest::get_file($file);

        return (($entry['fqcn'] ?? null) === $fqcn) ? $entry : null;
    }

    /**
     * Whether this model declares Class-Table Inheritance detail tables.
     */
    public static function has_detail_tables(): bool
    {
        return !empty(static::$detail_tables);
    }

    /**
     * The discriminator column that selects which detail table applies, or null.
     */
    public static function detail_discriminator_column(): ?string
    {
        return \App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::discriminator_column(static::$detail_tables);
    }

    /**
     * The detail model class for a discriminator value, or null when that value has no detail.
     */
    public static function detail_class_for($value): ?string
    {
        return \App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::class_for_value(static::$detail_tables, $value);
    }

    /**
     * [accessor_name => detail_class] for every detail this model has (deduped).
     */
    public static function detail_accessors(): array
    {
        return \App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::accessors(static::$detail_tables);
    }

    /**
     * Whether $key is one of this model's detail accessor names (for any type).
     */
    private static function __is_detail_accessor(string $key): bool
    {
        return array_key_exists($key, static::detail_accessors());
    }

    /**
     * The detail accessor name for THIS record's current discriminator value, or null
     * when the record's type has no detail (or it is untyped).
     */
    private function __active_detail_accessor(): ?string
    {
        $column = static::detail_discriminator_column();
        if ($column === null) {
            return null;
        }

        $class = static::detail_class_for($this->{$column});
        if ($class === null) {
            return null;
        }

        return \App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::accessor_name($class);
    }

    /**
     * Load (and cache) the PERSISTED detail row for this record's active type, or null when
     * none exists yet / the type has no detail / the record is unsaved. Used by the embed and
     * preload paths (serialization only ever exposes persisted details).
     */
    public function active_detail(): ?Rsx_Detail_Model_Abstract
    {
        if (!static::has_detail_tables() || !$this->id) {
            return null;
        }

        if ($this->_active_detail_loaded) {
            return $this->_active_detail;
        }

        $class = static::detail_class_for($this->{static::detail_discriminator_column()});
        $this->_active_detail = $class !== null ? $class::for_parent($this->id) : null;
        $this->_active_detail_loaded = true;

        return $this->_active_detail;
    }

    /**
     * Resolve a detail accessor by name (e.g. $record->party_company). Wrong-type access
     * (the accessor does not match this record's discriminator) throws - fail loud, local.
     * For the active type, returns the persisted detail, or a vivified new instance with the
     * parent FK pre-set (plain construction, parent FK populated), cached so the caller can fill
     * and save it. The vivified blank is never serialized (toArray embeds persisted only).
     */
    private function __get_detail_for_accessor(string $accessor): Rsx_Detail_Model_Abstract
    {
        $active = $this->__active_detail_accessor();

        if ($accessor !== $active) {
            $column = static::detail_discriminator_column();
            $value = $this->{$column};
            $active_text = $active === null ? "no detail (type {$column}={$value} has no detail table)" : "'{$active}'";

            throw new RuntimeException(
                static::class . " #{$this->id}: detail accessor '{$accessor}' is not valid for {$column}={$value} - " .
                "this record's detail is {$active_text}. Check the type before accessing type-specific fields."
            );
        }

        // Return the same vivified instance on repeated access (covers fill-then-save).
        if ($this->_vivified_detail !== null) {
            return $this->_vivified_detail;
        }

        $persisted = $this->active_detail();
        if ($persisted !== null) {
            return $persisted;
        }

        // No persisted row: vivify a new, unsaved detail with the parent FK already set.
        $class = static::detail_class_for($this->{static::detail_discriminator_column()});
        $detail = new $class();
        $detail->{$class::parent_key()} = $this->id;
        $this->_vivified_detail = $detail;

        return $detail;
    }

    /**
     * Batch-preload active details for a set of base records (avoids N+1 when serializing a
     * list). Groups by detail table, loads each with one WHERE {parent}_id IN (...) query,
     * and populates each record's detail cache. Eloquent eager loading is disabled
     * framework-wide, so datagrids/lists call this explicitly.
     *
     * @param iterable $records Base model instances (all of this class)
     */
    public static function preload_details(iterable $records): void
    {
        if (!static::has_detail_tables()) {
            return;
        }

        $column = static::detail_discriminator_column();

        // Bucket record ids by the detail class their type selects.
        $ids_by_class = [];
        $records_by_id = [];
        foreach ($records as $record) {
            if (!$record->id) {
                continue;
            }
            $records_by_id[$record->id] = $record;
            $class = static::detail_class_for($record->{$column});
            if ($class !== null) {
                $ids_by_class[$class][] = $record->id;
            } else {
                // Absent-detail type: cache "no detail" so toArray/accessor never query.
                $record->_active_detail = null;
                $record->_active_detail_loaded = true;
            }
        }

        foreach ($ids_by_class as $class => $ids) {
            $parent_key = $class::parent_key();
            $details = $class::whereIn($parent_key, $ids)->get();
            foreach ($details as $detail) {
                $parent_id = $detail->{$parent_key};
                if (isset($records_by_id[$parent_id])) {
                    $records_by_id[$parent_id]->_active_detail = $detail;
                    $records_by_id[$parent_id]->_active_detail_loaded = true;
                }
            }
        }
    }

    /**
     * CTI: the discriminator column is immutable once a record exists. Changing it would
     * leave the previously-selected detail row orphaned, so we reject the change (fail loud).
     */
    private function __assert_detail_discriminator_immutable(): void
    {
        if (!$this->exists || !static::has_detail_tables()) {
            return;
        }

        $column = static::detail_discriminator_column();
        if ($this->isDirty($column)) {
            throw new RuntimeException(
                static::class . " #{$this->id}: the detail discriminator '{$column}' is immutable " .
                '(it selects the detail table). Changing it from ' . $this->getOriginal($column) .
                " to {$this->{$column}} would orphan the existing detail row."
            );
        }
    }

    /**
     * Get a single attachment for this model by category
     *
     * Returns the first attachment with the specified category.
     * Use this for single-file attachments (e.g., profile_photo, logo).
     *
     * @param string $category Category identifier (e.g., 'profile_photo')
     * @return \App\RSpade\Core\Files\File_Attachment_Model|null
     */
    public function get_attachment(string $category)
    {
        // Use simple class name (type_ref system stores simple names, not FQCNs)
        return \App\RSpade\Core\Files\File_Attachment_Model::where('fileable_type', class_basename($this))
            ->where('fileable_id', $this->id)
            ->where('fileable_category', $category)
            ->first();
    }

    /**
     * Get all attachments for this model by category.
     *
     * Returns EVERY attachment in the category - foreach it, count() it, map it. The set
     * is fetched a page at a time (Rsx_Result_Set) because one record's attachment count
     * has no ceiling: a bulk import can hang thousands off a single row.
     *
     * @param string $category Category identifier (e.g., 'documents')
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    public function get_attachments(string $category)
    {
        // Use simple class name (type_ref system stores simple names, not FQCNs)
        return \App\RSpade\Core\Files\File_Attachment_Model::where('fileable_type', class_basename($this))
            ->where('fileable_id', $this->id)
            ->where('fileable_category', $category)
            ->result_set();
    }

    /**
     * Get this model's attachments in a category that are currently in the RETENTION
     * WINDOW (soft-deleted but not yet destroyed) - the "Recently Deleted" recovery set
     * for that category. Excludes permanently-destroyed rows. Restore one with
     * $attachment->undelete().
     *
     * @param string $category Category identifier (e.g., 'documents')
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    public function get_deleted_attachments(string $category)
    {
        // Use simple class name (type_ref system stores simple names, not FQCNs)
        //
        // NOTE the orderByDesc is dropped by iteration: Rsx_Result_Set walks by primary
        // key. Recovery lists are small enough to sort in the view, and the whole-set
        // guarantee matters more here than arrival order.
        return \App\RSpade\Core\Files\File_Attachment_Model::withTrashed()
            ->whereNotNull('deleted_at')
            ->whereNull('destroyed_at')
            ->where('fileable_type', class_basename($this))
            ->where('fileable_id', $this->id)
            ->where('fileable_category', $category)
            ->result_set();
    }
}
