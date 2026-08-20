<?php

namespace App\RSpade\Core\Database\TypeRefs;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;

/**
 * Rsx_Type_Ref_Cast - Eloquent cast for polymorphic type reference columns
 *
 * This cast provides transparent conversion between class name strings (PHP)
 * and integer IDs (database) for polymorphic type columns.
 *
 * When reading from database:
 *   - Integer ID is converted to class name string
 *   - Null values remain null
 *   - Invalid IDs throw an exception
 *
 * When writing to database:
 *   - Class name string is converted to integer ID
 *   - New classes are auto-registered in _type_refs
 *   - Null values remain null
 *   - Invalid class names throw an exception
 *
 * Usage in model:
 *   protected static $type_ref_columns = ['fileable_type'];
 *
 * The cast is automatically applied by Rsx_Model_Abstract based on the
 * $type_ref_columns declaration.
 */
#[Instantiatable]
class Rsx_Type_Ref_Cast implements CastsAttributes
{
    /**
     * Transform the attribute from the underlying model values.
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array $attributes
     * @return string|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Convert integer ID to class name
        return Type_Ref_Registry::id_to_class((int) $value);
    }

    /**
     * Transform the attribute to its underlying model values.
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array $attributes
     * @return int|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Convert class name to integer ID (auto-creates if needed)
        return Type_Ref_Registry::class_to_id((string) $value);
    }
}
