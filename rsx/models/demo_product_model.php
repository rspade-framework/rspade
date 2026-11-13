<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use Rsx\Lib\TextTypes\RichText\Rich_Text;

/**
 * Demo Product model for Ajax ORM demonstration
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: demo_products
 *
 * @property int $category_id enum: CATEGORY_ELECTRONICS, CATEGORY_CLOTHING, CATEGORY_BOOKS, CATEGORY_FOOD (also $category_id__label and the other $category_id__* properties)
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property \Rsx\Lib\TextTypes\RichText\Rich_Text $description text type: a value object, filtered on write
 * @property int $id
 * @property string $name
 * @property string $price decimal
 * @property int $status_id enum: STATUS_AVAILABLE, STATUS_OUT_OF_STOCK, STATUS_DISCONTINUED (also $status_id__label and the other $status_id__* properties)
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
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
    /**
     * Column declarations (see rsx:man model).
     *
     * `description` is authored in a WYSIWYG and holds HTML, so its text_type makes the value
     * carry its own encoding instead of every template, endpoint and export remembering it -
     * the same decision Project_Model makes, and states in full, for the same kind of column.
     */
    public static $columns = [
        'description' => ['text_type' => Rich_Text::class],
        'status_id' => ['enum' => [
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
        ]],
        'category_id' => ['enum' => [
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
        ]],
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
    #[Auth('can_view_data')]
    #[Ajax_Endpoint_Model_Fetch]
    public static function fetch($id)
    {
        // Fetch single record
        $model = static::find($id);

        return $model ?: false;
    }
}