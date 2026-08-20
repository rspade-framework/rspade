<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Demo Product model for Ajax ORM demonstration
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: demo_products
 *
 * @property int $id
 * @property string $name
 * @property string $description
 * @property float $price
 * @property int $status_id
 * @property int $category_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 * @property-read string $category_id__label
 * @property-read string $category_id__constant
 *
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 * @method static array category_id__enum() Get all enum definitions with full metadata
 * @method static array category_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array category_id__enum_labels() Get simple id => label map
 * @method static array category_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
#[Auth('is_logged_in')]
class Demo_Product_Model extends Rsx_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_AVAILABLE = 1;
    const STATUS_OUT_OF_STOCK = 2;
    const STATUS_DISCONTINUED = 3;
    const CATEGORY_ELECTRONICS = 1;
    const CATEGORY_CLOTHING = 2;
    const CATEGORY_BOOKS = 3;
    const CATEGORY_FOOD = 4;

    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'demo_products';

    /**
     * Enum field definitions
     *
     * @var array
     */
    public static $enums = [
        'status_id' => [
            1 => [
                'constant' => 'STATUS_AVAILABLE',
                'label' => 'Available',
                'order' => 1,
            ],
            2 => [
                'constant' => 'STATUS_OUT_OF_STOCK',
                'label' => 'Out of Stock',
                'order' => 2,
            ],
            3 => [
                'constant' => 'STATUS_DISCONTINUED',
                'label' => 'Discontinued',
                'order' => 3,
            ],
        ],
        'category_id' => [
            1 => [
                'constant' => 'CATEGORY_ELECTRONICS',
                'label' => 'Electronics',
                'order' => 1,
            ],
            2 => [
                'constant' => 'CATEGORY_CLOTHING',
                'label' => 'Clothing',
                'order' => 2,
            ],
            3 => [
                'constant' => 'CATEGORY_BOOKS',
                'label' => 'Books',
                'order' => 3,
            ],
            4 => [
                'constant' => 'CATEGORY_FOOD',
                'label' => 'Food & Beverage',
                'order' => 4,
            ],
        ],
    ];

    /**
     * No relationships for this demo model
     *
     * @var array
     */

    /**
     * Fetch a demo product by ID for Ajax ORM
     *
     * @param int $id Single ID
     * @return static|false Single model object or false if not found
     */
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        // Fetch single record
        $model = static::find($id);

        return $model ?: false;
    }
}