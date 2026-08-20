<?php

namespace App\RSpade\Core\Database\TypeRefs;

use Illuminate\Database\Eloquent\Model;

/**
 * Type_Ref_Model - Maps class names to integer IDs for polymorphic relationships
 *
 * This model represents entries in the _type_refs table, which provides a
 * registry of class name to integer ID mappings used for efficient polymorphic
 * column storage.
 *
 * The table is managed automatically by Type_Ref_Registry - you should not
 * typically interact with this model directly.
 *
 * @property int $id
 * @property string $class_name
 * @property string|null $table_name
 * @property string $created_at
 * @property string $updated_at
 */
class Type_Ref_Model extends Model
{
    protected $table = '_type_refs';

    protected $guarded = [];

    public $timestamps = true;
}
