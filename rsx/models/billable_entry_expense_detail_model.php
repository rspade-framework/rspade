<?php

namespace Rsx\Models;

use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Model_Abstract;
use Rsx\Models\Billable_Entry_Model;

/**
 * Billable_Entry_Expense_Detail_Model - the fields only an EXPENSE entry has (rsx:man
 * detail_tables).
 *
 * Reached only through $entry->billable_entry_expense; never independently fetchable - its
 * authorization is the entry's, and it rides the entry's fetch() payload under __details.
 *
 * `cost` is money and is the entry's amount. The receipt is NOT attached here: it is a file
 * attachment on the base entry (Billable_Entry_Model::RECEIPT_CATEGORY), where the site scope
 * and the entry's own lifecycle apply to it.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: billable_entry_expense_details
 *
 * @property int $billable_entry_id
 * @property \App\RSpade\Core\Database\Money\Rsx_Money $cost money: a value object, two decimal places, never a float
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property int $id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
 * @property string $vendor
 *
 * @mixin \Eloquent
 */
class Billable_Entry_Expense_Detail_Model extends Rsx_Detail_Model_Abstract
{
    protected $table = 'billable_entry_expense_details';
    protected $fillable = [];

    protected static $parent_model = Billable_Entry_Model::class;

    public static $columns = [
        'cost' => ['money' => true],
    ];

    /**
     * The entry this detail belongs to. #[Realtime_Touch]: a detail-only save notifies the
     * entry's subscribers, so its view refreshes.
     */
    #[Relationship]
    #[Realtime_Touch]
    public function billable_entry()
    {
        return $this->belongsTo(Billable_Entry_Model::class, 'billable_entry_id');
    }
}
