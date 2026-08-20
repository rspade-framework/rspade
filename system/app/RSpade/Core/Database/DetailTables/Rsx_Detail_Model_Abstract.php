<?php

namespace App\RSpade\Core\Database\DetailTables;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Rsx_Detail_Model_Abstract - base class for a Class-Table Inheritance (CTI) detail model.
 *
 * A detail model holds the type-specific fields for one discriminator value of a base
 * model (see man detail_tables). It is a STANDARD model (default `id` PK) joined 1:1 to
 * the base by a UNIQUE foreign key ({parent}_id). It is reached only through the base
 * record's generated detail accessor (e.g. `$party->party_person`) - it has NO fetch()
 * and is never independently fetchable from JS; its authorization IS the parent's.
 *
 * A concrete detail model only declares its parent:
 *
 *   class Party_Person_Detail_Model extends Rsx_Detail_Model_Abstract
 *   {
 *       protected $table = 'party_person_details';
 *       protected static $parent_model = Party_Model::class;
 *   }
 *
 * The FK column ($parent_key) is derived from the parent model's basename
 * (Party_Model -> party_id); override $parent_key only for a non-conventional name.
 */
abstract class Rsx_Detail_Model_Abstract extends Rsx_Model_Abstract
{
    /**
     * The base model this detail extends. REQUIRED on concrete detail models.
     * @var string|null
     */
    protected static $parent_model = null;

    /**
     * FK column linking this detail to its base (defaults to derived name). Override
     * only when the column is not {parent_basename}_id.
     * @var string|null
     */
    protected static $parent_key = null;

    /**
     * The base model class this detail belongs to (fails loud if unset).
     */
    public static function parent_model(): string
    {
        if (!static::$parent_model) {
            shouldnt_happen(static::class . ' must declare protected static $parent_model = <Base_Model>::class;');
        }

        return static::$parent_model;
    }

    /**
     * The FK column name on this detail table that references the base's id.
     * Derived from the parent model basename (Party_Model -> party_id) unless overridden.
     */
    public static function parent_key(): string
    {
        if (static::$parent_key) {
            return static::$parent_key;
        }

        $base = class_basename(static::parent_model());
        if (str_ends_with($base, '_Model')) {
            $base = substr($base, 0, -strlen('_Model'));
        }

        return strtolower($base) . '_id';
    }

    /**
     * Load the detail row for a given base record id, or null if none exists yet.
     */
    public static function for_parent(int $parent_id): ?Rsx_Detail_Model_Abstract
    {
        return static::where(static::parent_key(), $parent_id)->first();
    }
}
